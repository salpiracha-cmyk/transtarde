import hashlib,io,json,os,pathlib,subprocess,tarfile,tempfile
script=pathlib.Path(__file__).with_name('login_rollback_inventory.sh').resolve()
with tempfile.TemporaryDirectory(prefix='tti-rollback-shell-') as temp:
 root=pathlib.Path(temp);target=root/'public_html';target.mkdir();backups=root/'.transtrade-deploy/backups';backups.mkdir(parents=True)
 for i in range(52):
  path=backups/('before-'+format(i,'040x')+'-20261019T120000Z.tgz')
  with tarfile.open(path,'w:gz') as archive:
   for name in ['.htaccess','auth_store.php','login.php','module.php','exports/app.js']:
    info=tarfile.TarInfo('./'+name);info.size=4;archive.addfile(info,io.BytesIO(b'code'))
  age=1000+(i*37%52);os.utime(path,(age,age))
 (backups/'unknown.txt').write_text('preserve');(backups/'symlink.tgz').symlink_to(backups/'unknown.txt')
 before={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in backups.iterdir()}
 def run():return json.loads(subprocess.check_output(['bash',str(script),str(target)],env={**os.environ,'TT_LOGIN_INVENTORY_FIXTURE':'1'}))
 d=run();assert d['proposalVerified'] and d['proposedOldestCount']==20 and d['retainedKnownCodeBackups']==32 and d['unknownEntriesRetained']==2
 expected=sorted([p for p in backups.iterdir() if p.name.startswith('before-')],key=lambda p:(p.stat().st_mtime,p.name))
 assert [r['name'] for r in d['candidates']]==[p.name for p in expected[:20]]
 assert d['filesDeleted']==0 and len(d['verifiedLatestBaselines'])==3
 assert before=={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in backups.iterdir()}
 first=backups/d['candidates'][0]['name']
 with tarfile.open(first,'w:gz') as a:
  info=tarfile.TarInfo('./data/accounts.json');info.size=1;a.addfile(info,io.BytesIO(b'x'))
 os.utime(first,(1000,1000));assert not run()['proposalVerified']
 print('PASS shell inventory: exact oldest20, retained newest baselines, tar integrity/source markers, private-data refusal, unknown/symlink retention and zero file mutations')
