"""Exercise original HTTP endpoints in a disposable local-only copy; never writes to hosting."""
from __future__ import annotations
import json, os, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.parse, http.cookiejar
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2]
checks: list[dict] = []
def check(ok: bool, name: str, actual=None):
    checks.append({'name': name, 'passed': bool(ok), 'actual': actual})
    if not ok:
        raise AssertionError(f'{name}: {actual}')

SEED = r'''<?php
$root=__DIR__;if(!str_starts_with(basename($root),'tti-stock-http-'))throw new RuntimeException('Unsafe fixture root');
require $root.'/repo/auth_store.php';$pw=bin2hex(random_bytes(20));$users=[];$rw=['View','Create','Edit'];
foreach(['qaowner'=>['Super Admin',['Mill'=>'all','Accounts'=>'all','Directors'=>'all','Exports'=>'all']],
'qamill'=>['Mill Operator',['Mill'=>['stock'=>$rw,'production'=>$rw,'export'=>$rw,'arrival'=>$rw,'queue'=>$rw,'reports'=>['View']]]],
'qaaccounts'=>['Accounts Operator',['Accounts'=>['reports'=>['View'],'entity-tti'=>['View']]]],
'qadirector'=>['Director',['Directors'=>['reports'=>['View']]]],
'qaexport'=>['Export Operator',['Exports'=>'all']],
'qaview'=>['Mill Viewer',['Mill'=>['stock'=>['View'],'production'=>['View']]]]] as $name=>[$role,$p])
$users[]=['id'=>1000+count($users),'username'=>$name,'full_name'=>$name,'role'=>$role,'permissions'=>$p,'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'master_access'=>false];
$masters=tt_default_masters();$masters['business_parties'][]=['id'=>'QA-BROKER','values'=>['QA Broker','QA','Broker','','','','','','','','Active','','{"buying":[],"selling":[]}']];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>$users,'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'master_options'=>tt_default_master_options(),'audit'=>[]]));
file_put_contents($root.'/credentials.json',json_encode(['password'=>$pw,'users'=>array_column($users,'username')]));
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>0,'values'=>['tt32processingrecon'=>'[{"id":"private-legacy","narration":"MANAGEMENT_PRIVATE_SENTINEL","rate":987654321}]'],'meta'=>[]]));
'''

def run() -> None:
    root=Path(tempfile.mkdtemp(prefix='tti-stock-http-'));server=None;log=None
    output=os.environ.get('TT_QA_OUTPUT');cleanup=False
    if output:Path(output).mkdir(parents=True,exist_ok=True)
    mysql=os.environ.get('TT_QA_MYSQL')=='1'
    if mysql:
        assert os.environ.get('TT_DB_HOST')=='127.0.0.1' and re.fullmatch(r'transtrade_qa_[a-z0-9_]+',os.environ.get('TT_DB_NAME','')), 'Only a named local disposable QA database is allowed'
    db_code="$p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);"
    def db_php(code):
        return subprocess.run(['php','-r',db_code+code],capture_output=True,text=True,check=True).stdout
    try:
        app=root/'repo';shutil.copytree(SOURCE,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','playwright-report','test-results'))
        sessions=root/'sessions';sessions.mkdir();(root/'seed.php').write_text(SEED)
        subprocess.run(['php','-d',f'session.save_path={sessions}',str(root/'seed.php')],check=True,capture_output=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        log=open(root/'server.log','w+');env={**os.environ}
        for key in list(env):
            if not mysql and key.startswith('TT_DB_'):env.pop(key)
        server=subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(app)],stdout=log,stderr=log,env=env)
        time.sleep(.35);cred=json.loads((root/'credentials.json').read_text());clients={};tokens={}
        def request(user,path,body=None):
            req=urllib.request.Request(base+path,data=None if body is None else json.dumps({'csrf':tokens[user],**body}).encode(),headers={'Content-Type':'application/json'} if body is not None else {})
            try:r=clients[user].open(req,timeout=20);status=r.status;data=r.read()
            except urllib.error.HTTPError as e:status=e.code;data=e.read()
            try:result=json.loads(data)
            except (json.JSONDecodeError,UnicodeDecodeError):result=data.decode(errors='replace')
            return status,result
        for user in cred['users']:
            clients[user]=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            html=clients[user].open(base+'/login.php').read().decode();token=re.search(r'name="csrf" value="([^"]+)"',html).group(1);tokens[user]=token
            req=urllib.request.Request(base+'/login.php',data=urllib.parse.urlencode({'csrf':token,'username':user,'password':cred['password']}).encode())
            clients[user].open(req,timeout=20).read()
        def get(user='qamill'):
            c,d=request(user,'/api/operations.mysql.php');check(c==200,'Shared read authorized: '+user,c);return d
        def post(key,rows,user='qamill',version=None,expected=200):
            view=get(user);ver=(view.get('meta') or {}).get(key,{}).get('version',0) if version is None else version
            c,d=request(user,'/api/operations.mysql.php',{'key':key,'value':json.dumps(rows),'baseVersion':ver,'sourceModule':'Mill'})
            check(c==expected,'HTTP write '+key+' by '+user,(c,d.get('error') if isinstance(d,dict) else d[:80]));return d
        def private():
            if mysql:return json.loads(db_php("$v=[];foreach($p->query('SELECT storage_key,payload FROM tt_operation_records')->fetchAll(PDO::FETCH_ASSOC) as $r)$v[$r['storage_key']]=$r['payload'];echo json_encode($v);"))
            return json.loads((root/'transtrade_private/operations.json').read_text())['values']
        view=get()
        if mysql:
            db_php("$p->prepare('INSERT INTO tt_operation_records(storage_key,payload,version,updated_at,updated_by,updated_by_module) VALUES(?,?,1,UTC_TIMESTAMP(),?,?)')->execute(['tt32processingrecon','[{\"narration\":\"MANAGEMENT_PRIVATE_SENTINEL\"}]','QA fixture','System']);")
            view=get()
        check('MANAGEMENT_PRIVATE_SENTINEL' not in json.dumps(view),'Mill receives no private financial snapshot')
        check(set(view['restrictedKeys'])=={'tt34ghati','tt34nilqueue','tt32processingrecon'},'Server instructs removal of older private cache keys')
        c,d=request('qamill','/api/operations.php');check(c==200 and 'MANAGEMENT_PRIVATE_SENTINEL' not in json.dumps(d),'Legacy read cannot reveal private records')
        for user in ['qamill','qaexport','qaview']:
            c,d=request(user,'/stock-reconciliation.php?entity=TTI');check(c==403,'Financial report denied to '+user,c)
        for user,entity,expected in [('qaaccounts','TTI',200),('qaaccounts','BRM',403),('qadirector','BRM',200)]:
            c,d=request(user,'/stock-reconciliation.php?entity='+entity);check(c==expected,'Financial report company scope '+user+' '+entity,c)
        post('tt34ghati',[],expected=403)
        post('tt30prod',[],user='qaview',expected=403)
        c,d=request('qamill','/api/operations.php',{'key':'tt34nilqueue','value':'[]'});check(c==409,'Legacy write cannot bypass server-owned ledger')
        scope={'entity':'TTI','millId':2,'millName':'QA Second Mill'};stock='IRRI-6 White Rice — ASAS'
        post('tt30slips',[{**scope,'id':1,'baseVariety':'IRRI-6','riceType':'White','productStage':'RAW','payableWeight':100000}])
        ship={**scope,'id':2,'baseVariety':'IRRI-6','riceType':'White','brand':'ASAS','containers':[{'id':3,'container':'TEST1234567','weight':25000,'bags':500}]}
        post('tt30ship',[ship]);saved=json.loads(get()['values']['tt30ship'])[0]['containers'][0]
        shift=saved['productionShift'];date=saved['productionShiftDate']
        # The 18:00-20:00 gap has no current processing shift; do not invent completion for it.
        if not shift:
            check(not json.loads(private().get('tt34ghati','[]')),'Between shifts remains pending without guessing a financial result')
            # Continue with a persisted historical daytime loading fixture (test store only).
            fixture=json.loads(private()['tt30ship']);fixture[0]['containers'][0]['productionShift']='Day';payload=json.dumps(fixture)
            if mysql:
                db_php("$p->prepare('UPDATE tt_operation_records SET payload=? WHERE storage_key=\"tt30ship\"')->execute(["+json.dumps(payload)+"]);")
            else:
                fp=root/'transtrade_private/operations.json';state=json.loads(fp.read_text());state['values']['tt30ship']=payload;fp.write_text(json.dumps(state))
            shift='Day'
        crows=[{**scope,'id':'PC-HTTP','stockName':stock,'physicalKg':0,'snapshotKg':999999,'sourceShipmentId':'2'}]
        post('tt39physicalconfirmations',crows);pv=private();check(not json.loads(pv.get('tt34ghati','[]')),'Actual loading pending production creates no gain')
        pr={**scope,'id':10,'date':date,'shift':shift,'baseVariety':'IRRI-6','riceType':'White','inputStage':'RAW','shiftEntriesComplete':False,'rows':[{'product':'Ready Rice — ASAS','bags':200,'bagWeight':50}]}
        post('tt30prod',[pr]);check(not json.loads(private().get('tt34ghati','[]')),'Partial shift entry does not finalize gain')
        # Final current-shift entry contains no further ASAS: only 15,000kg remains unexplained.
        rows=json.loads(get()['values']['tt30prod']);rows.append({**pr,'id':11,'shiftEntriesComplete':True,'rows':[{'product':'Ready Rice — OTHER','bags':20,'bagWeight':50}]})
        post('tt30prod',rows);pv=private();events=json.loads(pv['tt34ghati']);check(len(events)==1 and events[0]['kg']==15000 and events[0]['kind']=='gain','Server applies only unexplained remainder once',events)
        adjs=json.loads(pv['tt32stockadj']);check(len(adjs)==1 and adjs[0]['millName']=='QA Second Mill','Correction remains at physical source mill')
        for endpoint in ['/api/operations.mysql.php','/api/operations.php']:
            c,d=request('qamill',endpoint);check('tt34ghati' not in d['values'] and 'tt34nilqueue' not in d['values'],'No management stores through '+endpoint)
        c,d=request('qaaccounts','/stock-reconciliation.php?entity=TTI');check(c==200 and '15,000.000' in d and 'Last loading excess' in d,'Accounts sees real server-derived gain')
        before=len(events);post('tt39physicalconfirmations',crows);check(len(json.loads(private()['tt34ghati']))==before,'Physical confirmation replay does not duplicate gain')
        post('tt39physicalconfirmations',crows,version=0,expected=409);check(len(json.loads(private()['tt34ghati']))==before,'Stale retry does not duplicate gain')
        rows=json.loads(get()['values']['tt30prod']);rows.append({**pr,'id':12,'shiftEntriesComplete':False,'rows':[]})
        post('tt30prod',rows);pv=private();fixed=json.loads(pv['tt34nilqueue']);check(fixed[0].get('productionId')==12 and fixed[0]['noStockPost'],'First subsequent report receives private no-stock row')
        view=get();check(not any(r.get('systemFixed') for p in json.loads(view['values']['tt30prod']) for r in p['rows']),'Mill production contains no fixed management row')
        counts=(len(json.loads(pv['tt34ghati'])),len(json.loads(pv['tt32stockadj'])))
        post('tt30prod',json.loads(view['values']['tt30prod']));pv=private();check(counts==(len(json.loads(pv['tt34ghati'])),len(json.loads(pv['tt32stockadj']))),'Report replay does not adjust stock again')
        check(not (root/'transtrade_private/accounts.json').exists(),'Reconciliation creates no duplicate purchase/accounting journal')
        if os.environ.get('TT_QA_BROWSER')=='1':
            browser_checks(base,clients,request,get,post,private,scope,output)
        print(f'PASS {"MySQL" if mysql else "file-backed"} inventory HTTP: {len(checks)} assertions')
    finally:
        if server:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill();server.wait()
        if log:log.close()
        if output:
            target=Path(output);target.mkdir(parents=True,exist_ok=True)
            (target/'inventory-http-results.json').write_text(json.dumps(checks,indent=2))
            if (root/'server.log').exists():shutil.copy(root/'server.log',target/'inventory-http-server.log')
        assert root.name.startswith('tti-stock-http-')
        shutil.rmtree(root);cleanup=not root.exists()
        if mysql:db_php("$p->exec('DROP DATABASE `'.getenv('TT_DB_NAME').'`');")
        if output:(Path(output)/'inventory-http-cleanup.json').write_text(json.dumps({'temporary_directory_removed':cleanup,'production_access':False}))
        check(cleanup,'Synthetic transactions and sessions removed')


def browser_checks(base,clients,request,get,post,private,scope,output):
    from playwright.sync_api import sync_playwright
    post('tt30mills',[{'id':1,'name':'TTI Rice Mills','type':'Own Mill'},{'id':2,'name':'QA Second Mill','type':'Own Mill'}],user='qaowner')
    # A separate fresh brand deliberately has no matching production yet.
    shipments=json.loads(get()['values']['tt30ship']);shipments.append({'id':301,'entity':'TTI','millId':1,'mill':'TTI Rice Mills','millName':'TTI Rice Mills','baseVariety':'IRRI-6','riceType':'White','brand':'QA FRESH','containers':[],'audit':[],'status':'Loading','ref':'QA-LOT','contractRef':'QA-CONTRACT','totalContainers':1,'bagsPerContainer':500,'bagSize':50,'emptyRequired':0})
    post('tt30ship',shipments,user='qaowner')
    with sync_playwright() as pw:
        browser=pw.chromium.launch(headless=True)
        try:
            context=browser.new_context(viewport={'width':1440,'height':1100})
            jar=next(h.cookiejar for h in clients['qamill'].handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
            context.add_cookies([{'name':c.name,'value':c.value,'url':base}for c in jar]);page=context.new_page();errors=[];dialogs=[]
            page.on('pageerror',lambda error:errors.append(error.stack))
            page.on('dialog',lambda dialog:(dialogs.append(dialog.message),dialog.accept()))
            page.goto(base+'/module_release.php?id=milling',wait_until='domcontentloaded')
            page.wait_for_function("typeof chooseMill==='function' && !!window.TT_SHARED_SYNC",timeout=20000)
            page.evaluate("chooseMill(1);openPanel('queue')")
            page.locator('#qVehicle').fill('QA-901');page.locator('#qBags').fill('100');page.locator('#qWeight').fill('5000');page.locator('#qBroker').fill('QA Broker');page.locator('#qBroken').fill('10')
            page.locator('#queueSaveBtn').click();page.evaluate('window.TT_SHARED_SYNC.saveNow()')
            saved=json.loads(get()['values'].get('tt30queue','[]'))
            check(any(r.get('vehicle')=='QA-901' for r in saved),'Fresh Mill arrival saves without pressing Clear first',dialogs)
            check(page.locator('#ghatiStatementBody').count()==0,'Mill browser contains no Ghati report')
            check(page.evaluate("['tt34ghati','tt34nilqueue','tt32processingrecon'].every(k=>localStorage.getItem(k)===null)"),'Mill browser cache contains no private reconciliation stores')
            page.evaluate("openPanel('export');selectShipment(301)")
            number=page.evaluate("'TGHU654321-'+containerCheckDigit('TGHU654321')")
            main=page.locator('#inlineShipment_301 .tt-container-main')
            if main.count():
                main.fill(number[:10]);page.locator('#inlineShipment_301 .tt-container-check').fill(number[-1])
            else:page.locator('#contNo').fill(number)
            for key,value in [('contTruck','QA-902'),('contWeight','25000'),('contBags','500'),('contSeal','QA-SEAL')]:page.locator('#'+key).fill(value)
            page.locator('#saveContainerBtn').click();page.evaluate('window.TT_SHARED_SYNC.saveNow()')
            saved=next(r for r in json.loads(get()['values']['tt30ship']) if r['id']==301)
            check(len(saved['containers'])==1 and saved['containers'][0]['weight']==25000,'Actual browser loading is allowed with no production stock',dialogs)
            check(not any(r.get('millId')==1 for r in json.loads(private()['tt30prod'])),'Browser loading did not fabricate production at own mill')
            page.evaluate('openCompletionBox(301)');page.locator('#completionRemainingBags').fill('0');page.locator('#completionBagSize').fill('50')
            page.locator('#completionBox button.ok').click()
            page.wait_for_function("JSON.parse(localStorage.getItem('tt30ship')||'[]').some(r=>r.id===301&&r.status==='Completed')",timeout=15000)
            page.evaluate('window.TT_SHARED_SYNC.saveNow()')
            confirmations=json.loads(private()['tt39physicalconfirmations']);c=next(r for r in confirmations if r.get('sourceShipmentId')=='301')
            check(c['status']=='Awaiting current-shift production','Physical completion waits for the loading shift instead of forcing a Mill gain/loss')
            check(not page.locator('body').inner_text().lower().find('ghati')>=0,'No Ghati wording in operational page')
            if output:page.screenshot(path=str(Path(output)/'mill-physical-workflow.png'),full_page=True)
            page.evaluate("openPanel('production')")
            check(page.locator('#prodShiftComplete').is_visible(),'Mill only confirms that all shift lots have been entered')
            # Direct management URL remains blocked even from the authenticated Mill browser.
            r=page.request.get(base+'/stock-reconciliation.php?entity=TTI');check(r.status==403,'Mill browser cannot open management report by URL')
            owner_context=browser.new_context();jar=next(h.cookiejar for h in clients['qaowner'].handlers if isinstance(h,urllib.request.HTTPCookieProcessor));owner_context.add_cookies([{'name':c.name,'value':c.value,'url':base}for c in jar]);owner_page=owner_context.new_page();owner_errors=[];owner_page.on('pageerror',lambda error:owner_errors.append(error.stack))
            owner_page.goto(base+'/module_release.php?id=milling',wait_until='domcontentloaded');owner_page.wait_for_selector('#ttConsoleTop',timeout=15000)
            check(not owner_errors,'Owner header insertions use the correct parent',owner_errors)
            account_context=browser.new_context(viewport={'width':1440,'height':1100});jar=next(h.cookiejar for h in clients['qaaccounts'].handlers if isinstance(h,urllib.request.HTTPCookieProcessor));account_context.add_cookies([{'name':c.name,'value':c.value,'url':base}for c in jar]);account_page=account_context.new_page()
            account_page.goto(base+'/stock-reconciliation.php?entity=TTI',wait_until='domcontentloaded')
            check('15,000.000' in account_page.locator('body').inner_text(),'Accounts browser sees the reconciled private result')
            if output:account_page.screenshot(path=str(Path(output)/'accounts-private-reconciliation.png'),full_page=True)
            check(not errors,'No unhandled Mill browser errors in repaired workflow',errors)
        finally:browser.close()


if __name__=='__main__':run()
