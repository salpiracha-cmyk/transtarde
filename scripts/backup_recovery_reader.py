"""Offline verified recovery into a NEW folder. Never executes recovered code."""
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import tempfile
import unicodedata
import zipfile
import zlib
from scripts.recovery_layout import organize
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

MAX_ENTRY = 512 * 1024 * 1024
MAX_TOTAL = 64 * 1024**3
SKIP_ACTIVATION = {'admin-recovery.hash', 'office-backup-token.sha256', 'office-backup-policy.json',
                   'backup-password.key', 'backup-password.json', 'office-setup.json', 'office-pairing.json'}


def safe_name(name):
    if not isinstance(name, str) or not name or '\\' in name or ':' in name or any(ord(c)<32 for c in name):
        raise ValueError('Unsafe recovery path.')
    p = PurePosixPath(name)
    if p.is_absolute() or str(p) != name or any(x in ('', '.', '..') for x in p.parts):
        raise ValueError('Unsafe recovery path.')
    for part in p.parts:
        if part.endswith((' ', '.')) or re.fullmatch(r'(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\..*)?', part, re.I):
            raise ValueError('Unsafe Windows recovery filename.')
    return p


def decoded(value, size=None):
    result = base64.b64decode(value, validate=True)
    if size is not None and len(result) != size: raise ValueError('Invalid recovery encryption metadata.')
    return result


def read_manifest(archive):
    infos = archive.infolist()
    if len(infos)>200000 or len({x.filename for x in infos}) != len(infos): raise ValueError('Invalid recovery ZIP inventory.')
    info = archive.getinfo('backup-manifest.json')
    if info.file_size>64*1024*1024: raise ValueError('Recovery manifest is too large.')
    manifest = json.loads(archive.read(info))
    if manifest.get('application')!='Transtrade' or manifest.get('type')!='full_recovery' or manifest.get('schema') not in (1,2,3):
        raise ValueError('Choose a Complete Transtrade Backup, not a Business Data ZIP.')
    enc = manifest.get('encryption', {})
    if enc.get('algorithm')!='AES-256-GCM' or enc.get('kdf')!='PBKDF2-HMAC-SHA256': raise ValueError('Unsupported recovery encryption.')
    iterations = enc.get('iterations')
    if not isinstance(iterations,int) or not 10000<=iterations<=2000000: raise ValueError('Invalid recovery key settings.')
    entries = manifest.get('encryptedEntries')
    if not isinstance(entries,dict) or 'System_Recovery/private/auth.json' not in entries: raise ValueError('Recovery data is missing.')
    names=set(); total=0
    for name, meta in entries.items():
        safe_name(name); folded=unicodedata.normalize('NFC',name).casefold()
        if folded in names: raise ValueError('Conflicting Windows recovery paths.')
        names.add(folded)
        size=meta.get('size', -1)
        if not isinstance(size,int) or not 0<=size<=MAX_TOTAL: raise ValueError('Invalid recovery file size.')
        total+=size
        if total>MAX_TOTAL: raise ValueError('Recovery contents exceed the supported size.')
    return manifest, entries, total


def write_entry(archive, meta, key, output):
    chunks=meta.get('chunks', [meta]); digest=hashlib.sha256(); size=0
    if not isinstance(chunks,list) or not chunks: raise ValueError('Invalid recovery chunks.')
    for chunk in chunks:
        stored=chunk.get('stored', '')
        if not re.fullmatch(r'Encrypted/[0-9]{6,}\.bin', stored): raise ValueError('Invalid encrypted recovery entry.')
        length=chunk.get('size', -1)
        if not isinstance(length,int) or not 0<=length<=MAX_ENTRY: raise ValueError('Recovery entry is too large.')
        info=archive.getinfo(stored)
        if info.file_size>MAX_ENTRY+32 or info.compress_size>MAX_ENTRY+32: raise ValueError('Encrypted entry is too large.')
        compression=chunk.get('compression','')
        if compression not in ('','deflate'): raise ValueError('Unsupported recovery compression.')
        aad=b'TRANSTRADE-BACKUP-DEFLATE' if compression else b'TRANSTRADE-BACKUP'
        try: plain=AESGCM(key).decrypt(decoded(chunk['nonce'],12),archive.read(info)+decoded(chunk['tag'],16),aad)
        except Exception: raise ValueError('Incorrect backup password, or the backup is damaged.') from None
        if compression:
            decoder=zlib.decompressobj(-15)
            plain=decoder.decompress(plain,length+1)
            if not decoder.eof or decoder.unused_data or decoder.unconsumed_tail: raise ValueError('Invalid compressed recovery data.')
        if len(plain)!=length or hashlib.sha256(plain).hexdigest()!=chunk.get('sha256'):
            raise ValueError('Recovery file integrity check failed.')
        output.write(plain); digest.update(plain); size+=len(plain)
    if size!=meta.get('size') or digest.hexdigest()!=meta.get('sha256'): raise ValueError('Complete recovery file integrity check failed.')


def recover_copy(backup, password, parent, progress=lambda text: None, companion=None):
    backup=Path(backup); parent=Path(parent)
    if backup.is_symlink() or not backup.is_file(): raise ValueError('Choose a regular backup ZIP file.')
    if len(password.encode('utf-8'))<12: raise ValueError('Enter the password used when this backup was created.')
    if parent.is_symlink(): raise ValueError('Choose a regular recovery folder.')
    parent.mkdir(parents=True,exist_ok=True)
    before=backup.stat(); source_hash=hashlib.sha256()
    with backup.open('rb') as stream:
        for block in iter(lambda:stream.read(1024*1024),b''):source_hash.update(block)
    stage=None
    try:
        with zipfile.ZipFile(backup) as archive:
            manifest, entries, total=read_manifest(archive)
            if shutil.disk_usage(parent).free<total+64*1024*1024: raise ValueError('Not enough free space for the recovered files.')
            stage=Path(tempfile.mkdtemp(prefix='.recovering-',dir=parent))
            if os.name=='nt':
                import subprocess
                identity=os.environ.get('USERDOMAIN','')+'\\'+os.environ.get('USERNAME','')
                result=subprocess.run(['icacls',str(stage),'/inheritance:r','/grant:r',identity+':(OI)(CI)F'],capture_output=True,creationflags=subprocess.CREATE_NO_WINDOW)
                if result.returncode: raise RuntimeError('Could not protect the recovery folder.')
            else:stage.chmod(stat.S_IRWXU)
            enc=manifest['encryption'];key=hashlib.pbkdf2_hmac('sha256',password.encode('utf-8'),decoded(enc['salt'],16),enc['iterations'],32)
            for i,(name,meta) in enumerate(entries.items(),1):
                # Credentials are verified too, but never placed in an active recovery tree.
                skip=name.startswith('System_Recovery/private/') and name.removeprefix('System_Recovery/private/') in SKIP_ACTIVATION
                target=stage.joinpath(*safe_name(name).parts)
                if skip:
                    with tempfile.TemporaryFile() as stream:write_entry(archive,meta,key,stream)
                else:
                    target.parent.mkdir(parents=True,exist_ok=True)
                    with target.open('xb') as stream:write_entry(archive,meta,key,stream)
                if i%25==0 or i==len(entries):progress(f'Verified {i:,} of {len(entries):,} recovered files.')
            after=backup.stat()
            if (after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns)!=(before.st_dev,before.st_ino,before.st_size,before.st_mtime_ns): raise ValueError('The source backup changed during recovery.')
            organize(stage,manifest,progress)
            companion_hash=None
            if companion is not None:
                if manifest.get('scope')!='data':raise ValueError('Add a software ZIP only when recovering a separate data backup.')
                with zipfile.ZipFile(companion) as companion_zip:
                    companion_manifest,_,_=read_manifest(companion_zip)
                    if companion_manifest.get('scope')!='software':raise ValueError('Choose a Software and Setup backup as the companion.')
                    revision=manifest.get('softwareRevision')
                    if not revision or revision!=companion_manifest.get('softwareRevision'):
                        raise ValueError('This software ZIP does not match the data snapshot version. Use its matching software backup; for current data, refresh both through Backup Now.')
                extra_parent=stage/'.software-check'
                extra=recover_copy(companion,password,extra_parent,progress)
                code=extra/'Complete Website Restore/public_html'
                if not code.is_dir():raise ValueError('The software archive contains no website application.')
                shutil.copytree(code,stage/'Complete Website Restore/public_html')
                companion_hash=json.loads((extra/'RECOVERY_RESULT.json').read_text())['sourceSha256']
                shutil.rmtree(extra_parent)
                missing=stage/'Complete Website Restore/SOFTWARE COPY REQUIRED.txt'
                if missing.exists():missing.unlink()
            receipt={'result':'verified_recovery_copy','sourceSha256':source_hash.hexdigest(),'scope':manifest.get('scope','full'),'backupCreatedAt':manifest.get('createdAt'),'verifiedEntries':len(entries),'liveWebsiteChanged':False,'originalBackupChanged':False}
            receipt['layout']='five-folders-v1'
            receipt['softwareRevision']=manifest.get('softwareRevision')
            if companion_hash:receipt['softwareSourceSha256']=companion_hash
            (stage/'RECOVERY_RESULT.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8')
            from datetime import datetime
            after=backup.stat()
            if (after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns)!=(before.st_dev,before.st_ino,before.st_size,before.st_mtime_ns):raise ValueError('The source backup changed during recovery.')
            final=parent/('Recovery_'+datetime.now().strftime('%Y-%m-%d_%H%M%S')+'_'+os.urandom(3).hex())
            stage.rename(final);stage=None;return final
    finally:
        if stage is not None:shutil.rmtree(stage)
