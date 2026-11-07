import hashlib,io,os,pathlib,subprocess,tarfile,tempfile
SCRIPT=pathlib.Path(__file__).with_name('code_backup_retirement.sh').resolve()
with tempfile.TemporaryDirectory(prefix='tti-code-retirement-') as temporary:
 root=pathlib.Path(temporary);target=root/'public_html';target.mkdir();backups=root/'.transtrade-deploy/backups';backups.mkdir(parents=True)
 private=root/'transtrade_private/backups';private.mkdir(parents=True);(private/'business-snapshot.zip').write_bytes(b'preserve business recovery')
 (root/'transtrade_private/accounts.json').write_bytes(b'preserve original books')
 code=['.htaccess','auth_store.php','session_store.php','login.php','module.php','exports/app.js']
 for name in code:p=target/name;p.parent.mkdir(exist_ok=True,parents=True);p.write_text('original code '+name)
 (target/'data').mkdir();(target/'data/live.json').write_text('preserve public runtime')
 buffer=io.BytesIO()
 with tarfile.open(fileobj=buffer,mode='w:gz') as archive:
  for name in code:
   body=('original code '+name).encode();info=tarfile.TarInfo('./'+name);info.size=len(body);archive.addfile(info,io.BytesIO(body))
 original=buffer.getvalue();rows=[]
 for i in range(499):
  stamp=f'20260914T{i//3600:02d}{i//60%60:02d}{i%60:02d}Z' if i<496 else f'20261019T1256{48+i-496:02d}Z'
  name='before-'+format(i,'040x')+'-'+stamp+'.tgz';(backups/name).write_bytes(original)
  rows.append(name+'|'+hashlib.sha256(original).hexdigest()+'|'+str(len(original)))
 definitions="BR_BOOTSTRAP=("+' '.join("'"+r+"'" for r in rows[:20])+")\nBR_BASELINES=("+' '.join("'"+r+"'" for r in rows[-3:])+")\n"
 def run(mode,sha='a'*40,override='',success=True):
  shell="source '"+str(SCRIPT)+"'\n"+definitions+override+'\nbr_main "$1" "$2" "$3"'
  r=subprocess.run(['bash','-c',shell,'fixture',mode,str(target),sha],env={**os.environ,'TT_CODE_BACKUP_FIXTURE':'1'},capture_output=True,text=True)
  if success:assert r.returncode==0,(r.stdout,r.stderr)
  else:assert r.returncode!=0
  return r
 bad=backups/rows[4].split('|')[0];bad.write_bytes(b'changed backup')
 run('bootstrap',success=False);assert len(list(backups.glob('before-*.tgz')))==499,'Changed receipt must stop before first removal'
 bad.write_bytes(original)
 run('bootstrap',override='br_snapshot(){ return 1; }',success=False)
 assert len(list(backups.glob('before-*.tgz')))==479,'Only exact bootstrap set may be removed before a fresh baseline'
 assert all((backups/r.split('|')[0]).exists() for r in rows[-3:]),'Retained verified baselines survive failed fresh snapshot'
 run('bootstrap');assert (backups/('verified-live-'+'a'*40+'-before.tgz')).is_file()
 (target/'auth_store.php').write_text('repaired auth');(target/'session_store.php').write_text('repaired session')
 (backups/'manual-keep.txt').write_text('preserve unknown');(backups/('before-'+'f'*40+'-20261019T125650Z.tgz')).symlink_to(private/'business-snapshot.zip')
 result=run('finalize');assert all(not (backups/r.split('|')[0]).exists() for r in rows)
 current=backups/('verified-live-'+'a'*40+'.tgz');assert current.is_file() and not (backups/('verified-live-'+'a'*40+'-before.tgz')).exists()
 with tarfile.open(current) as archive:assert archive.extractfile('./auth_store.php').read()==b'repaired auth'
 assert (private/'business-snapshot.zip').read_bytes()==b'preserve business recovery'
 assert (root/'transtrade_private/accounts.json').read_bytes()==b'preserve original books'
 assert (target/'data/live.json').read_text()=='preserve public runtime'
 assert (backups/'manual-keep.txt').read_text()=='preserve unknown'
 run('bootstrap');run('finalize');assert current.is_file(),'Acknowledged retry remains safe'
 new=backups/('before-'+'b'*40+'-20261020T000000Z.tgz');new.write_bytes(current.read_bytes())
 run('rotate','b'*40);assert new.is_file() and not current.exists(),'Future deployment keeps only its verified rollback'
 invalid=backups/('before-'+'c'*40+'-20261020T000001Z.tgz');invalid.write_bytes(b'incomplete archive')
 run('rotate','c'*40,success=False);assert new.is_file(),'Bad new rollback cannot retire the previous valid copy'
 print('PASS authorised499 retirement: exact20 bootstrap/hash hold, failed snapshot preserves479/latest3, fresh live code/config verified, all499 retired, one current archive, idempotent retry, future one-copy rotation, bad-new-backup refusal and preserved books/runtime/business backups/unknowns/symlinks')
