"""Exercise actual JV mutations with isolated books and the real shared ID allocator."""
import concurrent.futures
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

repo=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-jv-flow-') as folder:
    root=Path(folder)
    (root/'api').mkdir();(root/'accounts').mkdir();(root/'data').mkdir()
    for name in ['journal_vouchers.php','jv_decisions_core.php','accounts_reference.php']:
        shutil.copy2(repo/'api'/name,root/'api'/name)
    shutil.copy2(repo/'master_store.php',root/'master_store.php')
    shutil.copy2(repo/'accounts/accounting_master_v1.json',root/'accounts/accounting_master_v1.json')
    (root/'auth_store.php').write_text('''<?php
const TT_DATA_DIR=__DIR__.'/data';
if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}}
require __DIR__.'/master_store.php';
function tt_require_login(){return ['id'=>(int)($_SERVER['HTTP_X_QA_USER']??1),'role'=>$_SERVER['HTTP_X_QA_ROLE']??'Super Admin','username'=>'Fixture','full_name'=>'Fixture'];}
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_module_action($u,$m,$icon,$action){if($action==='View')return ($_SERVER['HTTP_X_QA_NO_VIEW']??'')!=='1';if($action==='Approve')return in_array($u['role'],['Super Admin','Director','Reviewer'])&&($_SERVER['HTTP_X_QA_NO_APPROVE']??'')!=='1';return $u['role']!=='Viewer';}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_ensure_data_dir(){}
function tt_verify_csrf($value){return $value==='fixture';}
function tt_release_read_session(){}
function tt_mutate_store($callback){$h=fopen(TT_DATA_DIR.'/sequence.json','c+');flock($h,LOCK_EX);$raw=stream_get_contents($h);$s=$raw?json_decode($raw,true):[];$callback($s);rewind($h);ftruncate($h,0);fwrite($h,json_encode($s));fflush($h);flock($h,LOCK_UN);fclose($h);}
''')
    (root/'api/opening_balance_core.php').write_text('''<?php
const JOB_CLEARING='3400';
function job_banks($e){return [];}
function job_targets($e){return [];}
function job_payload($s,$e,$u){return ['allowed'=>false];}
''')
    (root/'api/accounts_subaccounts_core.php').write_text('<?php function sac_accounts($s,$e){return [];}')
    (root/'other_post.php').write_text('''<?php
require __DIR__.'/auth_store.php';$h=fopen(TT_DATA_DIR.'/accounts.json','r+');flock($h,LOCK_EX);$s=json_decode(stream_get_contents($h),true);$id=tt_next_post_id($s['journals'],'Mill','Arrival','2026-10-15');$s['journals'][$id]=['id'=>$id,'sourceType'=>'OTHER','entity'=>'TTI','date'=>'2026-10-15','status'=>'Posted'];rewind($h);ftruncate($h,0);fwrite($h,json_encode($s));fflush($h);flock($h,LOCK_UN);fclose($h);echo json_encode(['id'=>$id]);
''')
    historical={'id':'JVD-2026-000004','entity':'TTI','date':'2026-05-17','status':'Posted','journalId':'POST-2026-00039','createdByUserId':2}
    initial={'revision':0,'journals':{'POST-2026-00040':{'id':'POST-2026-00040','sourceType':'EXPENSE'},'POST-2026-00039':{'id':'POST-2026-00039','sourceType':'JV','meta':{'jvDraftId':historical['id']},'lines':[{'debit':5,'credit':5}]}},'jvDrafts':{historical['id']:historical}}
    data_file=root/'data/accounts.json';data_file.write_text(json.dumps(initial))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env={**os.environ,'PHP_CLI_SERVER_WORKERS':'2'}
    server_log=open(root/'server.log','w')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=server_log,env=env,start_new_session=True)
    def request(body=None,user=1,role='Super Admin',query='',extra=None,path='/api/journal_vouchers.php?entity=TTI'):
        headers={'Content-Type':'application/json','X-QA-User':str(user),'X-QA-Role':role,**(extra or {})}
        req=urllib.request.Request(f'http://127.0.0.1:{port}'+path+query,data=json.dumps({'csrf':'fixture','entity':'TTI',**body}).encode() if body else None,headers=headers)
        try:
            with urllib.request.urlopen(req,timeout=20) as response:return response.status,json.load(response)
        except urllib.error.HTTPError as error:return error.code,json.load(error)
    def submit(key,amount=25):
        return {'action':'submit_jv','requestKey':key,'date':'2026-10-15','narration':'Fixture journal','lines':[{'account':'6900','subledger':'Office','debit':amount,'credit':0},{'account':'1120','debit':0,'credit':amount}]}
    try:
        for attempt in range(100):
            try:
                if request()[0]==200:break
            except (OSError,ValueError):time.sleep(.05)
        else:raise RuntimeError('Fixture PHP server did not start')
        before=data_file.read_bytes();payload=request()[1];assert payload['canPostDirectly'] is True
        assert payload['jvs'][0]['publicPostId']=='POST-2026-000004-JV';assert data_file.read_bytes()==before,'Read-only historical labels do not rewrite or repost books'
        status,posted=request(submit('one'));assert status==200 and posted['result']['status']=='Posted',posted
        assert posted['result']['journalId']=='POST-2026-00041',posted
        count=len(json.loads(data_file.read_text())['journals']);again=request(submit('one'))[1]
        assert again['replayed'] is True and again['result']==posted['result'];assert len(json.loads(data_file.read_text())['journals'])==count
        assert request(submit('one',30))[0]==409,'Changed payload cannot reuse a committed key'
        assert request(path='/other_post.php')[1]['id']=='POST-2026-00042'
        pending=request(submit('staff'),user=2,role='Accountant')[1]['result'];assert pending['status']=='Pending Approval'
        assert request({'action':'approve_jv','id':pending['jvId']},user=2,role='Reviewer')[0]==403,'A preparer cannot self-approve'
        approved=request({'action':'approve_jv','id':pending['jvId']})[1]['result'];assert approved['journalId']=='POST-2026-00043'
        staff_feed=request(user=2,role='Accountant',query='&notices=1&after=0')[1];assert len(staff_feed['notices'])==1 and staff_feed['notices'][0]['publicPostId']=='POST-2026-00043',staff_feed
        assert request(user=3,role='Accountant',query='&notices=1&after=0')[1]['notices']==[],'Decisions are private to their preparer/submitter'
        assert request(user=2,role='Accountant',query='&notices=1&after=0',extra={'X-QA-No-View':'1'})[0]==403
        pending2=request(submit('rejected'),user=2,role='Accountant')[1]['result'];reason='Amount does not match the original invoice'
        rejected=request({'action':'reject_jv','id':pending2['jvId'],'reason':reason})[1]['result'];assert rejected['status']=='Rejected'
        feed=request(user=2,role='Accountant',query='&notices=1&after='+str(staff_feed['cursor']))[1];assert len(feed['notices'])==1 and feed['notices'][0]['reason']==reason
        pending_feed=request(user=2,role='Accountant',query='&notices=1&pending=1')[1]
        assert len(pending_feed['notices'])==2,'A fresh session must retain undecided-by-user notifications'
        before_books=json.loads(data_file.read_text())['journals'];nid=staff_feed['notices'][0]['id']
        assert request({'action':'dismiss_jv_notice','noticeId':nid},user=3,role='Accountant')[0]==422,'Another user cannot dismiss the preparer notice'
        assert request({'action':'dismiss_jv_notice','noticeId':nid},user=2,role='Viewer')[1]['result']['dismissed'],'View-only recipient can close their own message'
        assert request({'action':'dismiss_jv_notice','noticeId':nid},user=2,role='Viewer')[0]==200,'Dismiss retries are safe'
        assert json.loads(data_file.read_text())['journals']==before_books,'Dismissing changes no financial journal'
        remaining=request(user=2,role='Accountant',query='&notices=1&pending=1')[1]['notices'];assert len(remaining)==1 and remaining[0]['status']=='Rejected'
        assert request(submit('viewer'),user=3,role='Viewer')[0]==403
        no_approve=request(submit('limited-admin'),extra={'X-QA-No-Approve':'1'})[1]['result'];assert no_approve['status']=='Pending Approval','Direct post still requires approval authority'
        unbalanced=submit('unbalanced');unbalanced['lines'][1]['credit']=1;before=data_file.read_bytes();assert request(unbalanced)[0]==422;assert data_file.read_bytes()==before
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            results=list(pool.map(lambda n:request(submit('concurrent-'+str(n))),range(4)))
        assert all(status==200 for status,_ in results),results
        ids=sorted(body['result']['journalId'] for _,body in results);assert ids==[f'POST-2026-{n:05}' for n in range(44,48)],ids
        saved=json.loads(data_file.read_text());assert saved['journals']['POST-2026-00039']==initial['journals']['POST-2026-00039'];assert saved['journals']['POST-2026-00041']['meta']['sharedPostSequence'] is True;assert saved['journals']['POST-2026-00041']['reference']=='POST-2026-00041'
        current=request()[1];assert next(r for r in current['jvs'] if r.get('journalId')=='POST-2026-00041')['publicPostId']=='POST-2026-00041'
        director=request(submit('director'),user=4,role='Director')[1];assert director['canPostDirectly'] and director['result']['status']=='Posted','Director with explicit JV approval permission posts directly'
        limited=request(submit('limited-director'),user=4,role='Director',extra={'X-QA-No-Approve':'1'})[1];assert not limited['canPostDirectly'] and limited['result']['status']=='Pending Approval'
        approved_own=request({'action':'approve_jv','id':limited['result']['jvId']},user=4,role='Director')[1];assert approved_own['result']['status']=='Posted','Existing own pending JV can be completed after approval authority is granted'
        print('PASS direct Super Admin post, shared +1 IDs across modules/concurrent JVs, retry protection, preparer approval, private notices/rejection and unchanged historical books')
    except Exception:
        server_log.flush();print((root/'server.log').read_text()[-5000:]);raise
    finally:
        import signal
        os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);server_log.close()
