"""Seven verified daily data points, monthly data, and separate weekly software."""
from datetime import datetime, timezone
import os
from pathlib import Path
import re
import zipfile


def commit_received(agent,temp,destination,digest,scope,stamp,refresh_month=False,data_destination=None):
    destination=Path(destination);folder=destination if scope=='software' else destination/'Daily'
    folder.mkdir(parents=True,exist_ok=True)
    prefix='TRANSTRADE_SOFTWARE_' if scope=='software' else 'TRANSTRADE_DATA_'
    target=folder/(prefix+stamp.strftime('%Y-%m-%d_%H%M')+'_'+digest[:12]+'.zip')
    manifest=agent.validate_zip(temp)
    if manifest.get('scope')!=scope:raise ValueError('The server returned a different backup type. Previous copies were preserved.')
    os.replace(temp,target)
    agent.write_json(destination/'latest-verified.json',{'name':target.name,'sha256':digest,'scope':scope,'createdAt':manifest.get('createdAt'),'softwareRevision':manifest.get('softwareRevision')})
    if scope=='software':
        # Retain compatible application versions for every surviving data point.
        # An unreadable data manifest holds software copies rather than guessing.
        required=set();hold=False
        data_root=Path(data_destination) if data_destination else destination.parent/'SystemBackups'
        for tier in ('Daily','Monthly'):
            for data in (data_root/tier).glob('TRANSTRADE_DATA_*.zip'):
                try:
                    revision=agent.validate_zip(data).get('softwareRevision')
                    if not revision:hold=True
                    else:required.add(revision)
                except (OSError,ValueError,zipfile.BadZipFile):hold=True
        if not hold:prune(agent,folder,prefix,target,digest,2,required_revisions=required)
    else:
        month=destination/'Monthly';month.mkdir(exist_ok=True)
        current=list(month.glob(prefix+stamp.strftime('%Y-%m')+'_*.zip'))
        # Match the existing first-weekday/catch-up policy; manual first setup may seed now.
        if refresh_month or (stamp.weekday()<5 and not current):
            monthly=month/(prefix+stamp.strftime('%Y-%m_%H%M')+'_'+digest[:12]+'.zip');agent.copy_verified(target,monthly,digest)
            agent.write_json(month/'latest-verified.json',{'name':monthly.name,'sha256':digest,'scope':scope})
            prune(agent,month,prefix,monthly,digest,12,monthly=True)
        prune(agent,folder,prefix,target,digest,7)
    return target


def prune(agent,folder,prefix,survivor,digest,count,monthly=False,required_revisions=()):
    """Delete only identified, intact archives after rechecking a verified survivor."""
    proof=agent.backup_identity(survivor);groups={};known=[];versions={}
    for p in folder.glob(prefix+'*.zip'):
        try:
            if p.is_symlink() or not p.is_file():continue
            # Monthly and daily filenames are distinct, and unknown files are held.
            pattern=re.escape(prefix)+(r'(\d{4}-\d{2})_\d{4}_([a-f0-9]{12})\.zip' if monthly else r'(\d{4}-\d{2}-\d{2})_\d{4}_([a-f0-9]{12})\.zip')
            match=re.fullmatch(pattern,p.name)
            if not match:continue
            m=agent.validate_zip(p)
            if m.get('scope')!=('software' if prefix=='TRANSTRADE_SOFTWARE_' else 'data'):continue
            created=datetime.fromisoformat(m['createdAt'].replace('Z','+00:00'))
            if created.tzinfo is None or created.timestamp()>datetime.now(timezone.utc).timestamp()+300:continue
            actual=agent.sha256_file(p)
            if actual[:12]!=match[2]:continue
            with zipfile.ZipFile(p) as z:
                if z.testzip() is not None:continue
            identity=agent.backup_identity(p);known.append((p,identity,match[1],created));groups.setdefault(match[1],[]).append((created,p))
            revision=m.get('softwareRevision')
            if revision in required_revisions:versions.setdefault(revision,[]).append((created,p))
        except (OSError,ValueError,KeyError,zipfile.BadZipFile):continue
    keep=set()
    for day in sorted(groups,reverse=True)[:count]:keep.add(max(groups[day],key=lambda x:(x[0],x[1]==survivor,x[1].name))[1])
    keep.add(survivor)
    for copies in versions.values():keep.add(max(copies,key=lambda x:(x[0],x[1].name))[1])
    for p,identity,day,created in known:
        if p in keep:continue
        if agent.backup_identity(survivor)!=proof or agent.sha256_file(survivor)!=digest:raise RuntimeError('Verified replacement changed; cleanup stopped.')
        if agent.backup_identity(p)!=identity:continue
        p.unlink()
