const fs = require('node:fs');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');

(async () => {
  const requests = [], opened = [];
  const staff = {id:'2',name:'Fixture Director',username:'fixture.director',role:'Director',location:'All authorized locations',active:true,modules:['Accounts','Directors'],permissions:{Accounts:{reports:['View']},Directors:{tgmaster:['View','Edit'],future_screen:['View']}},masterAccess:true,masterPermissions:{products:['View'],reference_lists:['Use','View'],product_settings:['View','Edit']},lastActive:'2026-10-16T20:00:00+00:00'};
  const dom = new JSDOM(fs.readFileSync('index.html','utf8'), {url:'https://fixture.test/index.php',runScripts:'outside-only',pretendToBeVisual:true});
  const w = dom.window;
  w.TT_SESSION = {id:'1',name:'Fixture Owner',role:'Super Admin',csrf:'fixture'};
  w.scrollTo = () => {};
  w.matchMedia = () => ({matches:false,addEventListener(){},removeEventListener(){}});
  w.HTMLDialogElement.prototype.showModal = function(){this.open=true;};
  w.HTMLDialogElement.prototype.close = function(){this.open=false;};
  w.open = (...args) => opened.push(args);
  w.fetch = async (url,options={}) => {
    if(options.body)requests.push(JSON.parse(options.body));
    return {ok:true,json:async()=>({ok:true,users:[staff],masters:{},options:{},approvals:[],snapshots:[],serverSnapshots:[],office:{}})};
  };
  w.eval(fs.readFileSync('admin/app.js','utf8').replace('  initialize();','  window.testConsole={openUserDialog,saveUser}; initialize();'));
  await new Promise(resolve=>setTimeout(resolve,50));
  w.document.querySelector('[data-open-module="directors"]').click();
  assert.equal(opened[0][0],'directors/index.php');
  const save = async () => {requests.length=0;await w.testConsole.saveUser({preventDefault(){}});return requests.find(x=>x.action==='update');};
  w.testConsole.openUserDialog('2');
  assert.equal(w.document.querySelectorAll('[data-permission-module="Directors"]').length,18);
  w.document.getElementById('userName').value='Amended name only';
  let sent=await save();
  assert.deepEqual(sent.permissions,staff.permissions,'Name-only edit must preserve every grant, including unknown icons');
  assert.deepEqual(sent.masterPermissions,staff.masterPermissions,'Hidden master scopes must survive');
  w.testConsole.openUserDialog('2');
  w.document.querySelector('[data-permission-module="Directors"][data-permission-icon="tgmaster"][value="Edit"]').checked=false;
  sent=await save();
  assert.deepEqual(sent.permissions.Directors.tgmaster,['View'],'Explicit removal must take effect');
  assert.deepEqual(sent.permissions.Directors.future_screen,['View']);
  assert.deepEqual(sent.permissions.Accounts,staff.permissions.Accounts);
  for(const legacy of ['all',['View','Edit']]) {
    staff.permissions.Directors=legacy;
    w.testConsole.openUserDialog('2');
    sent=await save();
    assert.deepEqual(sent.permissions.Directors,legacy,'Unchanged legacy grant must retain its meaning');
  }
  assert.match(w.document.getElementById('userRows').textContent,/17-10-2026, 01:00/);
  for(const name of ['export_customers','business_parties','export_documents','export_terms']) {
    w.document.querySelector(`[data-master="${name}"]`).click();
    assert.equal([...w.document.querySelectorAll('#masterTableHead th')].filter(x=>x.textContent==='Status').length,1);
  }
  w.close();
  console.log('PASS Console launcher, granular/legacy permission preservation, explicit revocation, master scopes and status columns');
})().catch(error=>{console.error(error);process.exitCode=1;});
