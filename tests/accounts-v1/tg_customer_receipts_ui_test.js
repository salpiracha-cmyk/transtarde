const fs=require('fs'),assert=require('assert'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window,posted=[];
 const invoices=['AMT ENTERPRISE','EASTBOURNE MARITIME TRADING LTD.','GLOBAL TRANSIT'].map((customer,n)=>({id:'CF-'+n+'|BUYER',contractRef:'CONTRACT-'+n,reference:'INVOICE-'+n,customer,currency:'USD',outstandingNative:100,recognized:true}));
 w.localStorage.setItem('tt_accounts_entity','TG');w.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture'};
 w.fetch=async(url,options)=>{if(options?.method==='POST'){posted.push(JSON.parse(options.body));return {ok:true,json:async()=>({ok:true,journal:{id:'POST-1',date:'2026-10-02',narration:'Buyer receipt'},transaction:{}})}}return {ok:true,json:async()=>({ok:true,contracts:[],invoices,openAdvances:[],vatRate:7.5,banks:[{id:'USD',currency:'USD',bank:'TG BANK'}]})}};
 w.eval(fs.readFileSync('accounts/tg-customer-receipts-ui.js','utf8'));await w.TT_TG_CUSTOMER_RECEIPTS.open();
 const q=s=>w.document.querySelector(s),change=(s,v)=>{q(s).value=v;q(s).dispatchEvent(new w.Event('change',{bubbles:true}))};
 assert.equal(q('#tgRcCustomer').options.length,4,'Registered invoice customers need no operational contract');
 for(const i of invoices){change('#tgRcCustomer',i.customer);assert.equal(q('#tgRcTarget').options.length,2);change('#tgRcTarget','INV|'+i.id);assert.equal(q('#tgRcBank').value,'USD');assert(!q('[data-receipt-details]').hidden);}
 assert.equal(q('#tgRcVatPct').value,'7.5');assert(q('#tgRcVatPct').readOnly);
 q('#tgRcCredit').value='100';q('#tgRcCharge').value='10';q('#tgRcCharge').dispatchEvent(new w.Event('input'));assert.equal(q('#tgRcVat').value,'0.75');
 await q('#tgRcPost').onclick();assert.equal(posted.length,1);assert.equal(posted[0].receiptType,'EXPORT_RECEIVABLE');assert.equal(posted[0].sourceCandidateId,invoices[2].id);assert.equal(posted[0].counterparty,'GLOBAL TRANSIT');assert.equal(posted[0].settlementAmountNative,100);
 dom.window.close();console.log('PASS TG registered customer dropdown, invoice selection without operational contract, master VAT and linked receipt payload');
})().catch(e=>{console.error(e);process.exit(1)});
