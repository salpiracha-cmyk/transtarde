'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://fixture.test/**',r=>r.fulfill({contentType:'text/html',body:'<div id="entityHome"><div class="entityHero">Accounts</div><div id="homeGrid"><button class="appCard" data-key="payables">Payables</button></div></div>'}));await page.goto('http://fixture.test/');
  await page.evaluate(()=>{
   localStorage.setItem('tt_accounts_entity','TTI');window.TT_ACCOUNT_ACCESS={role:'Super Admin',csrf:'fixture'};window.printed=[];
   window.TT_PRINT={create:()=>({document:{write:s=>printed.push(s),close(){}},focus(){},print(){}})};
   const rows=[{billId:'A',sourceKey:'A-truck',rowKey:'A|A-truck',group:'Broker A',broker:'Supplier A',postId:'POST-2026-00001',truck:'TRUCK-A',dueDate:'2026-09-05',outstanding:100,held:false},{billId:'A',sourceKey:'A-truck|BROKERAGE',rowKey:'A|A-truck|BROKERAGE',group:'Broker A',broker:'Broker A',postId:'POST-2026-00001',truck:'TRUCK-A',dueDate:'2026-09-05',outstanding:20,held:false},{billId:'B',sourceKey:'B-truck',rowKey:'B|B-truck',group:'Supplier B',broker:'Supplier B',postId:'POST-2026-00002',truck:'TRUCK-B',dueDate:'2026-09-06',outstanding:100,held:false}];
   window.fixture={payerAccounts:[{id:'1220|abc',name:'ABC',account:'1220',accountName:'Local Sales Receivables',balance:500}],payerParties:['ABC','Supplier B'],banks:[{id:'BANK1',bankName:'First Bank',accountTitle:'Company',accountNumber:'123456789',currency:'PKR',availableBalance:150},{id:'BANK2',bankName:'Owner Bank',accountTitle:'Personal',iban:'PK00ABC987654321',currency:'PKR',availableBalance:200}],rows,parties:['Broker A','Supplier B'],onAccountPayments:[],brokerRows:[],plans:[],adjustments:[],advances:[]};window.commits=[];
   window.fetch=async(url,options={})=>{const b=options.body?JSON.parse(options.body):null;if(!b)return {ok:true,json:async()=>String(url).includes('accounts_ledger_browser')?{ok:true,post:{id:new URL(String(url),'http://fixture.test').searchParams.get('postId'),entity:'TTI',date:'2026-10-11',lines:[{account:'1230',debit:120},{account:'1220',credit:120}]}}:{ok:true,...structuredClone(fixture)}};if(b.action.startsWith('preview_')){const total=(b.sources||[]).reduce((n,s)=>n+Number(s.amount),0);return new Response(JSON.stringify({ok:true,result:{preview:{id:'PREVIEW',entity:'TTI',date:b.date,narration:'Payment accounting preview',lines:[{account:'2110',accountName:'Supplier / broker payable',debit:total,credit:0},{account:'1110',accountName:'Bank / third-party source',debit:0,credit:total}]}}}),{headers:{'Content-Type':'application/json'}});}commits.push(b);let result={};if(b.action==='on_account'){const settlement={id:'ON-ACCOUNT-1',type:'On Account Payment',status:'Posted',journalId:'POST-2026-00101',broker:b.party,date:b.date,netPayment:b.sources.reduce((n,s)=>n+Number(s.amount),0),onAccountAmount:b.sources.reduce((n,s)=>n+Number(s.amount),0),allocations:[],sources:b.sources.map(s=>({...s,label:s.payer||s.bankAccountId}))};fixture.onAccountPayments.push(settlement);result={settlement}}else if(b.action==='correct_on_account'){fixture.onAccountPayments.find(s=>s.id===b.settlementId).status='Reversed'}else if(b.action==='create'){const groups={};for(const row of fixture.rows)if(b.rowKeys.includes(row.rowKey)){groups[row.group]??={rowKeys:[],settlementId:''};groups[row.group].rowKeys.push(row.rowKey)}const plan={id:'PLAN-2026-000001',entity:'TTI',mode:b.mode,bankIds:b.tablePlanning?fixture.banks.map(x=>x.id):b.bankIds,groupByPayee:!!b.tablePlanning,throughDate:b.throughDate,status:'Open',version:1,groups};fixture.plans=[plan];result={plan:structuredClone(plan)}}else{const plan=fixture.plans[0];if(b.drafts)plan.drafts=structuredClone(b.drafts);if(b.action==='post'){const allocations=fixture.rows.filter(r=>b.rowKeys.includes(r.rowKey)).map(r=>({...r,amount:r.outstanding,balanceAfter:0}));const set={id:'SP-'+b.group,date:b.date,broker:b.group,journalId:'POST-2026-00100',netPayment:allocations.reduce((n,r)=>n+r.amount,0),allocations,sources:b.sources.map(s=>({...s,label:s.bankAccountId||s.payer}))};if(plan.drafts)delete plan.drafts[b.group];plan.groups[b.group].settlementId=set.id;plan.groups[b.group].voucher=set;fixture.rows=fixture.rows.filter(r=>!b.rowKeys.includes(r.rowKey));result={settlement:set}}else if(b.action==='correct'){const g=plan.groups[b.group];fixture.rows.push(...g.voucher.allocations.map(r=>({...r,outstanding:r.amount})));g.settlementId='';delete g.voucher;}else if(b.action==='change'){if(!b.rowKeys.length)delete plan.groups[b.group];else plan.groups[b.group].rowKeys=b.rowKeys;}else if(b.action==='close'){fixture.plans=[];}plan.version++;result.plan=structuredClone(plan)}return new Response(JSON.stringify({ok:true,result}),{headers:{'Content-Type':'application/json'}});};
  });
  await page.addScriptTag({content:fs.readFileSync('accounts/post-confirmation-ui.js','utf8')});
  await page.addScriptTag({content:fs.readFileSync('accounts/payment-plans-ui.js','utf8')});
  await page.evaluate(()=>{fixture.rows.forEach(r=>{r.group=r.broker;r.payee=r.broker;r.partyLabel=r.billId==='A'?'Supplier A / Broker A':'Supplier B';r.soda='SODA-42';});fixture.banks.push({id:'CASH|TTI',isCash:true,bankName:'Petty Cash',availableBalance:1000});fixture.banks[0].settings={defaultPaymentAccount:true};});
  await page.evaluate(()=>TT_PAYMENT_PLANS.choose());await page.click('[data-choose-planning]');
  await page.waitForSelector('[data-expected]');
  assert.equal(await page.locator('[data-planning-parties] button').count(),0,'No parties before a date is chosen');
  await page.fill('[data-expected]','500');assert.match(await page.locator('#ttPlanNote').textContent(),/650.00/);
  assert.equal(await page.locator('tfoot').first().textContent(),'TOTAL220.00');
  await page.click('[data-print-daily]');assert.match(await page.evaluate(()=>printed.at(-1)),/SODA-42/);
  assert.equal(await page.evaluate(()=>commits.length),0,'Date choice and printing never save');
  await page.fill('[data-clear-through]','2026-09-06');await page.click('[data-show-dues]');
  assert.equal(await page.locator('[data-prepare-party]').count(),3);
  await page.click('[data-prepare-party="Supplier A"]');await page.waitForSelector('[data-popup-group="Supplier A"]:not([hidden])');
  let card=page.locator('[data-party-card="Supplier A"]');
  assert.equal(await card.locator('[data-row]').count(),1);assert.match(await card.textContent(),/POST-2026-00001/);
  assert.equal(await card.getByText('Pay now',{exact:true}).count(),0);
  assert.match(await card.locator('[data-payment-summary]').textContent(),/100.00/);
  await card.locator('[data-expand-truck]').click();assert.equal(await card.locator('[data-truck-detail]').isVisible(),true);
  await card.locator('[data-row]').uncheck();assert.match(await card.locator('[data-payment-summary]').textContent(),/0.00/);
  await card.locator('[data-row]').check();await card.locator('[data-expand-truck]').click();assert.equal(await card.locator('[data-row]').isChecked(),true,'Collapse preserves tick');
  await card.locator('[data-source-type]').selectOption('CASH');assert.equal(await card.locator('[data-source-bank]').inputValue(),'CASH|TTI');assert.equal(await card.locator('[data-source-method]').isVisible(),false);
  await card.locator('[data-source-type]').selectOption('THIRD_PARTY');assert.equal(await card.locator('[data-source-payer]').isVisible(),true);assert.equal(await card.locator('[data-source-bank]').isVisible(),false);
  await card.locator('[data-source-type]').selectOption('BANK');await card.locator('[data-source-reference]').fill('1001');
  await card.locator('[data-payment-narration]').fill('Selected truck settlement');await card.locator('[data-post-party]').click();
  await page.waitForSelector('[data-review-post]');assert.equal(await page.evaluate(()=>commits.filter(b=>b.action==='post').length),0,'Review is read-only');
  await page.locator('[data-review-post]').click();await page.waitForSelector('[data-party-card="Supplier A"] [data-voucher]');
  assert.equal(await page.evaluate(()=>commits.find(b=>b.action==='post').narration),'Selected truck settlement');
  await page.locator('[data-popup-group="Supplier A"] [data-close-party]').click();
  await page.locator('[data-open-party="Supplier B"]').click();card=page.locator('[data-party-card="Supplier B"]');await card.locator('[data-source-reference]').fill('KEEP-THIS');
  await page.locator('[data-popup-group="Supplier B"] [data-close-party]').click();await page.locator('[data-open-party="Broker A"]').click();
  await page.locator('[data-popup-group="Broker A"] [data-close-party]').click();await page.locator('[data-open-party="Supplier B"]').click();
  assert.equal(await card.locator('[data-source-reference]').inputValue(),'KEEP-THIS','Switching popups preserves draft');
  await page.screenshot({path:'/tmp/payment-planning-popup.png',fullPage:true});
  assert.deepEqual(errors,[]);console.log('PASS daily total, expected funds, date-first parties, flat trucks, Post IDs, expandable selection, bank/cash/third-party, review and retained drafts');
  await require('./payment_source_dates_browser_test.js')();
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});

