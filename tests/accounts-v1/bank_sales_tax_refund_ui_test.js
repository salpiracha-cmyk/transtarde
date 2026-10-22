const fs=require('fs'),assert=require('assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
 w.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture'};let writes=0;
 const books={ok:true,canCreate:true,revision:1,banks:[{id:'ASKARI',label:'Askari PKR',currency:'PKR'}],payees:[],heads:[],subaccounts:[],facilities:[],history:[]};
 w.fetch=async(url,options={})=>{if(options.method==='POST')writes++;return{ok:true,json:async()=>url.includes('bank_accounts')?{ok:true,accounts:[{id:'ASKARI',displayName:'Askari PKR',currency:'PKR'}]}:url.includes('sales_tax_refunds')?{ok:true,receipts:[]}:books}};
 for(const file of ['accounts/account-management-ui.js','accounts/sales-tax-refund-ui.js'])w.eval(fs.readFileSync(file,'utf8'));
 for(const company of ['TTI','BRM']){
  w.localStorage.setItem('tt_accounts_entity',company);await w.TT_BANK_ENTRIES.open('ASKARI');const f=w.document.querySelector('#amBankForm');
  assert.equal(f.elements.type.querySelector('[value=SALES_TAX_REFUND]').textContent,'Sales Tax Refund Receipt');f.elements.date.value='2026-08-15';f.elements.type.value='SALES_TAX_REFUND';await f.elements.type.onchange();
  assert(!w.document.querySelector('#amWindow'));assert.equal(w.document.querySelector('#ttStrBank').value,'ASKARI');assert.equal(w.document.querySelector('#ttStrDate').value,'2026-08-15');assert.match(w.document.querySelector('#ttStrBody').textContent,/Bank Dr \/ Sales Tax Recoverable Cr/);assert.equal(writes,0);w.document.querySelector('#ttStrClose').click();
 }
 w.localStorage.setItem('tt_accounts_entity','TG');await w.TT_BANK_ENTRIES.open();assert(!w.document.querySelector('[value=SALES_TAX_REFUND]'));
 w.localStorage.setItem('tt_accounts_entity','TTI');await w.TT_BANK_ENTRIES.open();const f=w.document.querySelector('#amBankForm');w.TT_SALES_TAX_REFUND.open=async()=>{throw Error('Fixture load failure')};f.elements.type.value='SALES_TAX_REFUND';await f.elements.type.onchange();assert(w.document.querySelector('#amWindow'));assert.equal(f.elements.type.value,'');assert.match(w.document.querySelector('[data-message]').textContent,/Fixture load failure/);assert.equal(writes,0);
 dom.window.close();console.log('PASS Bank Entry refund shortcut, TTI/BRM scope, selected bank/date, load failure and zero posting on selection');
})().catch(e=>{console.error(e);process.exit(1)});
