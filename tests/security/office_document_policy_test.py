"""Hostile Office document/container and finite admission regressions."""
from office_document_fixtures import *
with tempfile.TemporaryDirectory(prefix='office-document-policy-') as temp:
 root=pathlib.Path(temp);driver=root/'check.php';driver.write_text('''<?php require $argv[1];try{if($argv[2]==='validate')office_document_validate($argv[3],$argv[4]);elseif($argv[2]==='admit'){ $h=fopen($argv[3].'/.admission.lock','c+b');flock($h,LOCK_EX);office_jobs_admit($argv[3],(int)$argv[4],1);if(isset($argv[5])){$p=$argv[3].'/'.bin2hex(random_bytes(16));mkdir($p);$f=fopen($p.'/reserved','wb');ftruncate($f,(int)$argv[4]);fclose($f);}flock($h,LOCK_UN);fclose($h);}echo 'OK';}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(2);}''')
 def run(action,*args):return subprocess.run(['php',str(driver),str(ROOT/'api/office_document_policy.php'),action,*map(str,args)],capture_output=True,text=True)
 def check(name,data,valid=True):
  path=root/'input';path.write_bytes(data);r=run('validate',path,name);assert (r.returncode==0)==valid,(name,valid,r.stdout,r.stderr)
 for name,data in [('Signed Sales Contract - '+'x'*120+'.pdf',PDF),('Ü'*120+'.pdf',PDF),('Invoice.pdf',PDF),('Picture.png',PNG),('Picture.webp',WEBP),('Bank letter.docx',DOCX),('BAGS.zip',BAGS)]:check(name,data)
 for name,data in [('launch.cmd',b'@echo off'),('PDF.lnk',PDF),('Invoice.pdf',b'<html>not PDF</html>'),('Fake.pdf',b'%PDF-1.7\narbitrary payload\n%%EOF'),('Picture.png',b'bad'),('../Invoice.pdf',PDF),('CON.pdf',PDF),('Invoice.pdf.',PDF),('bad\\Invoice.pdf',PDF),('COM¹.pdf',PDF),('LONGDO~1.PDF',PDF),('Fake.docx',b'PK-covering-letter')]:check(name,data,False)
 for items in [[('run.cmd',b'@echo off')],[('../Invoice.pdf',PDF)],[('a.pdf',PDF),('A.PDF',PDF)],[('inner.zip',BAGS)],[('a.pdf',b'bad')]]:check('BAGS.zip',package(items),False)
 check('BAGS.zip',package([('a.pdf',PDF)],zipfile.ZIP_DEFLATED),False)
 damaged=bytearray(BAGS);damaged[damaged.index(PDF)+5]^=1;check('BAGS.zip',damaged,False)
 symlink=io.BytesIO()
 with zipfile.ZipFile(symlink,'w') as z:
  info=zipfile.ZipInfo('link.pdf');info.create_system=3;info.external_attr=0o120777<<16;z.writestr(info,PDF)
 check('BAGS.zip',symlink.getvalue(),False)
 check('Bank.docx',package(entries+[('word/vbaProject.bin',b'macro')]),False)
 evil=[(name,xml.replace(b'Fixture covering letter',b'<w:fldSimple w:instr="DDEAUTO evil"/>')) for name,xml in entries];check('Bank.docx',package(evil),False)
 check('Bank.docx',package([(name,xml.replace(b'Target="styles.xml"',b'TargetMode="External" Target="https://evil.test"')) for name,xml in entries]),False)
 # All statuses and orphan files count; admission never deletes prior archives.
 jobs=root/'jobs';jobs.mkdir();orphan=jobs/'orphan';orphan.write_text('retain me');before=orphan.read_bytes()
 for size in [0,268435457]:assert run('admit',jobs,size).returncode!=0
 assert run('admit',jobs,1024).returncode==0 and orphan.read_bytes()==before
 for i in range(1000):(jobs/f'{i:032x}').mkdir()
 assert run('admit',jobs,1).returncode!=0 and orphan.read_bytes()==before
 for p in jobs.iterdir():
  if p.is_dir():p.rmdir()
 with orphan.open('wb') as f:f.truncate(1073741824)
 assert run('admit',jobs,1).returncode!=0 and orphan.stat().st_size==1073741824
 orphan.unlink()
 # Old payloads named like the admission lock still count when nested in jobs.
 legacy=jobs/'legacy';legacy.mkdir();hidden=legacy/'.admission.lock'
 with hidden.open('wb') as f:f.truncate(1073741824)
 assert run('admit',jobs,1).returncode!=0 and hidden.stat().st_size==1073741824
 hidden.unlink();legacy.rmdir()
 # Two whole-job admissions serialize; only one may consume the final available headroom.
 with (jobs/'existing').open('wb') as f:f.truncate(1073741824-1500000)
 cmd=['php',str(driver),str(ROOT/'api/office_document_policy.php'),'admit',str(jobs),'100000','commit']
 a=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE);b=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE);a.communicate();b.communicate();assert sorted([a.returncode,b.returncode])==[0,2]
 assert (jobs/'existing').stat().st_size==1073741824-1500000
 print('PASS genuine generated Word/BAGS/PDF/images, hostile names/signatures/ZIP/XML, preserved archives, finite quotas and concurrent admission')
