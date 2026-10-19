const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<div id="ws-receivables"><div class="panelHead"></div></div>',{url:'https://fixture.test/accounts/index.php',runScripts:'outside-only'}),w=dom.window;
 let requests=0;w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_ACCOUNT_ACCESS={csrf:'fixture'};
 w.fetch=async()=>{requests++;return{ok:true,json:async()=>({ok:true,entity:'TTI',summary:{restricted:true,warningPercent:4.8,criticalPercent:5},pending:[{id:'TTI-S',entity:'TTI',party:'TTI Buyer',product:'Rice',amount:100,loadedKg:1,status:'Pending Accounts Approval'}]})}};
 w.eval(fs.readFileSync('accounts/local-sales-control-ui.js','utf8'));await w.TT_LOCAL_SALES_CONTROL_UI.mount();
 const text=w.document.querySelector('[data-tt-local-sales-root]').textContent;assert.match(text,/Group financial totals require View access/);assert.match(text,/TTI Buyer/);assert.equal(w.document.querySelector('.ttls-kpis'),null,'Restricted amounts must not look like zero balances');assert.equal(requests,1);
 w.localStorage.setItem('tt_accounts_entity','TG');await w.TT_LOCAL_SALES_CONTROL_UI.mount();assert.equal(requests,1,'TG does not request Pakistan company records');assert.match(w.document.body.textContent,/Pakistan Local Sales stay outside TG books/);
 w.close();console.log('PASS Local Sales restricted UI: pending company rows retained, group totals hidden explicitly, no false zero balances, and no TG source read');
})().catch(e=>{console.error(e);process.exitCode=1});
