const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('admin/app.js','utf8');
const code=source.slice(source.indexOf('  async function refreshApprovals()'),source.indexOf('  function exportAudit()'));
const nodes=Object.fromEntries(['refreshApprovals','approvalStatusCount','approvalStatusTitle','approvalStatusDetail'].map(id=>[id,{}]));
const list={},count={},panel={querySelector:selector=>selector==='.approval-list'?list:count};
const context={IS_SUPER_ADMIN:true,pendingDeletionRequests:[],pendingBankDeletionRequests:[],pendingDirectorApprovals:[],approvalsError:'',approvalsLoaded:false,
  state:{masters:{companies:[{id:'C1',banks:[{id:'old',currency:'PKR',isDefault:true},{id:'new',currency:'PKR',bankName:'Fixture Bank',accountTitle:'TTI',iban:'PK1'},{id:'usd',currency:'USD'}]}]}},
  companyBanks:company=>company?.banks||[],eligibleDefaultBank:()=>true,
  escapeHtml:value=>String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;'),
  document:{getElementById:id=>nodes[id],querySelector:()=>panel},loadServerMasters:async()=>{},apiRequest:async()=>({approvals:[]})};
vm.createContext(context);vm.runInContext(code,context);
(async()=>{
  context.pendingBankDeletionRequests=[{id:'R1',companyId:'C1',bankId:'old',company:'TTI',bank:'Old Bank'}];
  context.apiRequest=async()=>({approvals:[{kind:'bag',billId:'B1',entity:'TTI',title:'<img src=x onerror=alert(1)>',detail:'Rate mismatch'}]});
  await context.refreshApprovals();
  assert.equal(count.textContent,'2 open');assert.equal(nodes.approvalStatusCount.textContent,'2');
  assert.ok(list.innerHTML.includes('data-review-rate-exception="B1"'));
  assert.ok(list.innerHTML.includes('data-rate-approval-reason'));
  assert.ok(!list.innerHTML.includes('<img'));
  assert.ok(list.innerHTML.includes('data-bank-approval-replacement="R1"'));
  assert.ok(list.innerHTML.includes('value="new"'));assert.ok(!list.innerHTML.includes('value="usd"'));
  context.apiRequest=async()=>{throw Error('Queue unavailable');};
  await context.refreshApprovals();
  assert.equal(count.textContent,'Awaiting verification');assert.ok(list.innerHTML.includes('role="alert"'));
  assert.ok(list.innerHTML.includes('Existing entries may be out of date'));
  context.pendingBankDeletionRequests=[];context.apiRequest=async()=>({approvals:[]});
  await context.refreshApprovals();assert.equal(count.textContent,'0 open');assert.ok(list.innerHTML.includes('No pending approvals'));
  assert.equal(nodes.refreshApprovals.disabled,false);
  console.log('Director approvals UI: shared count, escaped content, rate reasons, bank replacement and visible failures passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
