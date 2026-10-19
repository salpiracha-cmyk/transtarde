const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<div id="entityHome"></div><section id="ws-reports" class="workspace"><div class="panelHead"><h2>Reports</h2></div></section>',{url:'https://fixture.test/accounts/index.php',runScripts:'outside-only'}),w=dom.window;let csv='',printed='',requests=[];
 const quantity={type:'Purchase',date:'2026-09-01',sourceId:'PURCHASE|TTI|P1',reference:'26001',party:'Supplier',commodity:'CORN',product:'Makai',brand:'STAR',orderedMinMT:10,orderedMaxMT:12,movedBeforeMT:2,movedPeriodMT:.001,movedToDateMT:2.001,outstandingMinMT:7.999,outstandingMaxMT:9.999,status:'Open',movementSourceIds:['tt30slips|A1']};
 const financial={ok:true,from:'2026-10-01',asOf:'2026-10-15',generalLedger:[{date:'2026-10-02',journalId:'POST1',account:'4200',credit:100,debit:0}],expenseActivity:[],profitAndLoss:{income:[],expenses:[{account:'7210',name:'Zakat',amount:null}],profit:null,warnings:['Reconcile original Zakat source POST2']},zakatReporting:{basis:'Actual paid only',warnings:['Reconcile original Zakat source POST2']}};
 w.localStorage.setItem('tt_accounts_entity','TTI');w.fetch=async(url,options)=>{requests.push({url,options});return {ok:true,json:async()=>url.includes('commodity_quantity_report')?{ok:true,basis:'Original MT sources. Financial B/F creates no physical quantity.',rows:[quantity,{...quantity,sourceId:'OTHER',brand:'BLUE',product:'Rice'}],sourceHolds:[{sourceId:'LOCAL1',message:'Missing dated source'}]}:financial}};
 w.TT_PRINT={create:()=>({document:{write:html=>printed=html,close(){}},print(){}})};w.Blob=function(parts){this.parts=parts};w.URL.createObjectURL=blob=>{csv=blob.parts.join('');return 'blob:fixture'};w.URL.revokeObjectURL=()=>{};w.HTMLAnchorElement.prototype.click=()=>{};
 w.eval(fs.readFileSync('accounts/reports-ui.js','utf8'));await w.TT_REPORTS.open('commodity');const q=s=>w.document.querySelector(s);
 assert(q('#ttReportsBody').textContent.includes('0.001'),'MT precision must retain a one kg movement');assert(q('#ttReportsBody').textContent.includes('Missing dated source'));
 q('#ttCommodityBrand').value='STAR';q('#ttCommodityBrand').dispatchEvent(new w.Event('input'));assert(!q('#ttReportsBody').textContent.includes('BLUE'));
 q('#ttReportCsv').click();assert(csv.includes('STAR')&&!csv.includes('BLUE')&&csv.includes('0.001')&&csv.includes('Missing dated source')&&csv.includes('POST1'));
 q('#ttReportPrint').click();assert(printed.includes('0.001')&&!printed.includes('BLUE')&&printed.includes('Missing dated source'));
 await q('#ttReportRun').onclick();assert.equal(q('#ttCommodityBrand').value,'STAR');assert.equal(requests.filter(x=>x.url.includes('commodity_quantity_report')).length,2);
 await w.TT_REPORTS.open('pl');assert(q('#ttReportsBody').textContent.includes('Unavailable')&&q('#ttReportsBody').textContent.includes('Review required'));q('#ttReportCsv').click();assert(csv.includes('Review required')&&csv.includes('POST2'));
 assert(requests.every(x=>!x.options.method||x.options.method==='GET'));w.close();
 console.log('PASS commodity screen/CSV/print same source rows, one kg precision, brand filter, deliberate refresh, source holds and unavailable Zakat total without writes');
})().catch(error=>{console.error(error);process.exitCode=1});
