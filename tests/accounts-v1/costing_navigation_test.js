const assert=require('assert/strict');
const fs=require('fs');
const {JSDOM}=require('jsdom');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function scenario(superUser){
  const dom=new JSDOM('<button class="entityBtn">Company</button><button class="appCard" data-key="purchases">Purchases</button><button class="appCard" data-key="receivables">Receivables</button><section id="ws-purchases"><div class="panelHead"></div></section><section id="ws-receivables"></section>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'});
  const w=dom.window,requests=[];w.TT_ACCOUNT_ACCESS={super:superUser,permissions:superUser?'all':['View']};
  w.localStorage.setItem('tt_accounts_entity','TTI');
  w.fetch=async(url,options={})=>{requests.push({path:String(url).split('?')[0],method:options.method||'GET'});return {ok:true,json:async()=>({ok:true,sheets:[],waiting:[],recent:[],rates:[],millingFallbackRate:3})}};
  for(const file of ['management-costing-ui','export-costing-ui','local-sales-costing-ui','costing-sheet-cogs-ui'])w.eval(fs.readFileSync('accounts/'+file+'.js','utf8'));
  for(const selector of ['.entityBtn','[data-key="purchases"]','[data-key="receivables"]']){w.document.querySelector(selector).click();await pause(300)}
  assert.equal(requests.filter(r=>r.method==='POST').length,0,'Company and area navigation must never post costing updates, even for the owner');
  assert(w.document.querySelector('[data-tt-export-cost-root]'),'Navigation still loads export costing');
  w.document.body.appendChild(w.document.createElement('aside'));await pause(450);
  assert.equal(requests.filter(r=>r.method==='POST').length,0,'Unrelated DOM changes cannot attach costing sheets');
  w.document.querySelector('#ttMcRefresh').click();await pause(40);
  assert.equal(requests.filter(r=>r.method==='POST'&&r.path.endsWith('management_costing.php')).length,superUser?1:0,'Only explicit permitted estimate preparation writes');
  assert.equal(requests.filter(r=>r.method==='POST'&&r.path.endsWith('management_costing_attach.php')).length,superUser?1:0,'Attachment follows successful explicit estimate preparation');
  requests.length=0;await w.TT_EXPORT_COSTING_UI.sync();await w.TT_LOCAL_SALES_COSTING_UI.sync();
  assert.equal(requests.filter(r=>r.method==='POST').length,superUser?2:0,'Read-only users cannot invoke costing posting through sync');
  w.close();
}
(async()=>{await scenario(false);await scenario(true);require('node:child_process').execFileSync(process.execPath,['tests/accounts-v1/mill_recovery_ui_test.js'],{stdio:'inherit',timeout:60000});console.log('PASS costing navigation is read-only; explicit authorised estimate preparation and save sync remain available')})().catch(error=>{console.error(error);process.exitCode=1});
