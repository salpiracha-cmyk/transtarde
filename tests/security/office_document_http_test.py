"""Real authenticated enqueue, token delivery and unsafe legacy-job hold."""
from office_document_fixtures import *
import hashlib, http.cookiejar, socket, shutil, time, urllib.request, urllib.error, urllib.parse
with tempfile.TemporaryDirectory(prefix='office-document-http-') as tmp:
 root=pathlib.Path(tmp);app=root/'app';app.mkdir()
 for f in ROOT.glob('*.php'):shutil.copy(f,app/f.name)
 shutil.copytree(ROOT/'api',app/'api');(app/'accounts').mkdir()
 for f in (ROOT/'accounts').glob('*.json'):shutil.copy(f,app/'accounts'/f.name)
 private=root/'transtrade_private';private.mkdir()
 (private/'auth.json').write_text(json.dumps({'users':[{'id':1,'username':'owner','role':'Super Admin','active':True,'session_version':1}],'masters':{},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
 original={'contracts':[{'id':'C','ref':'TTI/FIX/01','seller':'TTI'}],'shipments':[{'id':'L','contractRef':'TTI/FIX/01','lotId':'L01','kind':'lot'}]}
 (private/'operations.json').write_text(json.dumps({'revision':1,'values':{'transtrade_export_v3_operational':json.dumps(original)},'meta':{}}));(private/'accounts.json').write_text('{"revision":1,"journals":{}}')
 token='a'*64;(private/'office-backup-token.sha256').write_text(hashlib.sha256(token.encode()).hexdigest())
 (app/'session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo '{}';")
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-d','upload_max_filesize=8M','-d','post_max_size=16M','-S',f'127.0.0.1:{port}','-t',str(app)],stdout=log,stderr=log)
 opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
 def req(path,data=None,headers=None):
  try:
   with opener.open(urllib.request.Request(base+path,data=data,headers=headers or {}),timeout=20) as r:status,body=r.status,r.read()
  except urllib.error.HTTPError as r:status,body=r.code,r.read()
  try:return status,json.loads(body)
  except (json.JSONDecodeError,UnicodeDecodeError):return status,body
 def upload(items,parts=None):
  boundary='fixture-office-boundary';manifest={'type':'shipment_archive','customer':'Fixture','contract':'TTI/FIX/01','lot':'L01','folderParts':parts or ['Fixture','TTI-FIX-01','L01'],'files':[{'name':name,'storedName':'attacker.cmd','folder':''} for name,data in items]}
  chunks=[]
  for name,value in [('action','enqueue-shipment'),('csrf','fixture'),('manifest',json.dumps(manifest))]:chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+value+'\r\n').encode())
  for name,data in items:chunks.extend([('--'+boundary+'\r\nContent-Disposition: form-data; name="files[]"; filename="upload"\r\nContent-Type: application/octet-stream\r\n\r\n').encode(),data,b'\r\n'])
  chunks.append(('--'+boundary+'--\r\n').encode());return req('/api/office_agent.php',b''.join(chunks),{'Content-Type':'multipart/form-data; boundary='+boundary})
 try:
  for _ in range(60):
   try:req('/session.php');break
   except urllib.error.URLError:time.sleep(.05)
  headers={'X-Forwarded-Proto':'https','X-TT-Backup-Token':token};before=tuple((private/n).read_bytes() for n in ['accounts.json','operations.json'])
  items=[('Invoice.pdf',PDF),('Bank letter.docx',DOCX),('BAGS.zip',BAGS),('Mark.webp',WEBP)]
  code,result=upload(items);assert code==200,(code,result,(root/'php.log').read_text());jobid=result['job']['id'];jobfile=private/'office_agent_jobs'/jobid/'job.json';job=json.loads(jobfile.read_text())
  assert all(f['storedName'].startswith('file-') and not f['storedName'].endswith('.cmd') for f in job['files'])
  code,result=req('/api/office_agent.php?action=poll',headers=headers);assert code==200 and result['jobs'][0]['id']==jobid,(code,result)
  for row,(_,expected) in zip(job['files'],items):assert req('/api/office_agent.php?action=file&id='+jobid+'&file='+urllib.parse.quote(row['storedName']),headers=headers)==(200,expected)
  original_files={str(p.relative_to(private)):p.read_bytes() for p in (private/'office_agent_jobs'/jobid).rglob('*') if p.is_file()}
  for bad in [[('launch.cmd',b'@echo off')],[('Invoice.pdf',b'not a PDF')],[('same.pdf',PDF),('SAME.PDF',PDF)],[('BAGS.zip',package([('bad.exe',b'exe')]))]]:
   code,result=upload(bad);assert code==422,(code,result)
  assert upload([('Invoice.pdf',PDF)],['Only one'])[0]==422
  assert all((private/p).read_bytes()==value for p,value in original_files.items())
  # Invalid old packages are held even if they predate enqueue validation.
  legacy=private/'office_agent_jobs'/('c'*32);(legacy/'files').mkdir(parents=True);(legacy/'files/run.cmd').write_bytes(b'@echo off')
  old={**job,'id':'c'*32,'files':[{'name':'run.cmd','storedName':'run.cmd','folder':'','size':9,'sha256':hashlib.sha256(b'@echo off').hexdigest()}]};(legacy/'job.json').write_text(json.dumps(old));oldbytes=(legacy/'job.json').read_bytes()
  code,result=req('/api/office_agent.php?action=poll',headers=headers);assert code==200 and 'c'*32 not in [j['id'] for j in result['jobs']]
  assert req('/api/office_agent.php?action=file&id='+'c'*32+'&file=run.cmd',headers=headers)[0]==409
  early=private/'office_agent_jobs'/('0'*31+'1');(early/'files').mkdir(parents=True);(early/'files/fake.pdf').write_bytes(b'bad')
  earlyjob={**job,'id':'0'*31+'1','files':[{'name':'fake.pdf','storedName':'fake.pdf','folder':'','size':268435456,'sha256':'0'*64}]};(early/'job.json').write_text(json.dumps(earlyjob))
  delivered=[]
  for _ in range(3):
   code,result=req('/api/office_agent.php?action=poll',headers=headers);assert code==200;delivered.extend(j['id'] for j in result['jobs'])
  assert jobid in delivered and earlyjob['id'] not in delivered,'Unsafe legacy package must not permanently starve valid delivery'
  assert (legacy/'job.json').read_bytes()==oldbytes and (legacy/'files/run.cmd').read_bytes()==b'@echo off'
  receipt=[{k:v for k,v in f.items() if k!='storedName'} for f in job['files']]
  for r in receipt:r['untrustedExtra']='x'*100000
  code,result=req('/api/office_agent.php?action=ack',json.dumps({'id':jobid,'status':'COMPLETE','files':receipt}).encode(),{'Content-Type':'application/json',**headers});assert code==200,(code,result)
  assert jobfile.stat().st_size<20000 and 'untrustedExtra' not in jobfile.read_text(),'Receipt metadata must not bypass storage quota'
  assert tuple((private/n).read_bytes() for n in ['accounts.json','operations.json'])==before
  print('PASS actual-auth Office upload/delivery: valid document formats, server storage names, hostile inputs/collisions denied, unsafe legacy queue held and original stores preserved')
 finally:server.terminate();server.wait(timeout=10);log.close()
