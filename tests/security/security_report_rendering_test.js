const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
(async()=>{
function functions(html){const dom=new JSDOM(html),map=new Map();for(const script of dom.window.document.querySelectorAll('script:not([src])')){const code=script.textContent;new Function(code);for(const match of code.matchAll(/^(?:async )?function (\w+)\(/gm)){let end=code.indexOf('\n',match.index);if(end<0)end=code.length;if(!code.slice(match.index,end).trimEnd().endsWith('}'))end=code.indexOf('\n}',end)+2;map.set(match[1],code.slice(match.index,end));}}dom.window.close();return map;}

const outer=new JSDOM(source),frame=outer.window.document.querySelector('iframe[srcdoc]').getAttribute('srcdoc'),mainFns=functions(source),localFns=functions(frame);
const payload=`'\"><img src=x onerror="window.pwned=1"><svg onload="window.pwned=2">`;
function safe(document){assert.equal(document.querySelector('img,svg,iframe'),null);for(const el of document.querySelectorAll('*'))for(const a of el.attributes)if(/^on/i.test(a.name))assert.ok(!a.value.includes('window.pwned='),a.value);}
const dom=new JSDOM(frame,{runScripts:'outside-only',url:'https://fixture.test'}),w=dom.window;
for(const n of ['escHtml','safeJsToken','render','renderExcess','refreshAdjustmentSources','renderPayments','renderLoads','gate'])w.eval(localFns.get(n));
w.persistSales=()=>{};w.stat=()=> 'Open';w.money=x=>Number(x).toFixed(2);w.excess=()=>100;
w.sales=[{soda:payload,date:payload,party:payload,product:payload,tons:payload,rate:payload,doneBy:payload,pay:[],loads:[]}];w.sel=null;
w.render();safe(w.document);assert.equal(w.document.querySelectorAll('#activeBody tr').length,1);assert.match(w.document.getElementById('activeBody').textContent,/onerror/);
function gateFixture(win){
 win.eval(fs.readFileSync('milling/gate-pass.js','utf8'));win.TT_MODULE_ACCESS={csrf:'fixture'};win.alert=m=>{throw Error(m)};
 win.fetch=async(url,options)=>{assert.equal(url,'/api/mill_gate_pass.php');const body=JSON.parse(options.body);assert.equal(body.csrf,'fixture');assert.ok(['tt35localsales','tt30ship'].includes(body.key));return {ok:true,json:async()=>({ok:true,document:{...Object.fromEntries(['company','address','contact','title','serial','date','party','issuedFrom','reference','container','truck','product','brand','seal','driver','phone','packing','remarks','exporter','legacyReference'].map(k=>[k,payload])),bags:1,grossKg:50,deductionKg:0,netKg:50}})};};
}
gateFixture(w);
let opened;w.openSale=x=>opened=x;w.eval(w.document.querySelector('#activeBody tr').getAttribute('onclick'));assert.equal(opened,payload,'Quoted Soda identity must remain usable without becoming code');
w.sel={soda:payload,party:payload,product:payload,pay:[{date:payload,amount:25,type:payload,where:payload,accounts:payload}],loads:[{date:payload,truck:payload,kg:payload,remarks:payload}],closed:false};
w.structuredClone=structuredClone;w.commitLocalSale=async record=>{w.sel=record;return record;};w.renderPayments();w.renderLoads();safe(w.document);let printed='';w.open=()=>({document:{write:x=>printed=x,open(){},close(){}},focus(){},print(){},close(){}});await w.gate(0);let page=new JSDOM(printed);safe(page.window.document);page.window.close();
const pdom=new JSDOM('<table><tbody id="prodTable"></tbody></table><div id="savedExporterSales"></div>',{runScripts:'outside-only'}),p=pdom.window;
for(const name of ['escHtml','safeJsToken','printQueue','markPrinted','printGate','printExporterBill','printLabourBill','printReprocessBill','renderSavedExporterSales','renderProductionTable','renderReprocessHistory'])p.eval(mainFns.get(name));
gateFixture(p);p.printedPohanchSession=new Set();p.open=()=>({document:{write:x=>printed=x,open(){},close(){}},focus(){},print(){},close(){}});p.queueViewDate={value:'2026-10-26'};p.STORE_QUEUE='queue';p.STORE_SLIPS='slips';p.STORE_SHIP='tt30ship';p.renderUnprinted=()=>{};p.set=()=>{};p.millProductIdentity=()=>({displayName:'Rice'});
const record=new Proxy({id:'1',date:'2026-10-26',damageKat:1,chalkyKat:1},{get:(t,k)=>k in t?t[k]:payload});record.containers=[record];p.get=()=>[record];
for(const call of [()=>p.printQueue(),()=>p.markPrinted('1'),()=>p.printGate(record,record),()=>p.printReprocessBill(Object.fromEntries(['billNo','mill','from','till','arrivalKg','rawRemovedKg','billableKg','rate','loadingExtra','other','amount','paid','balance'].map(k=>[k,payload])))]){await call();page=new JSDOM(printed);safe(page.window.document);assert.match(page.window.document.body.textContent,/onerror/);page.window.close();}
p.currentExporterRecord=()=>null;p.calcExporterSale=()=>({net:5,rv:10,final:10});p.exporterContainers=[{c:payload,t:payload,b:payload,g:5,tare:0,net:5}];p.printExporterBill();page=new JSDOM(printed);safe(page.window.document);page.window.close();
p.labourAmount=()=>10;p.printLabourBill({billNo:payload,contractor:payload,from:payload,till:payload,commodity:payload,lines:[{label:payload,basis:payload,qty:payload,rate:payload}],amount:10});page=new JSDOM(printed);safe(page.window.document);page.window.close();
p.currentExporterSale='S';p.today=()=> '2026-10-26';p.exporterSalesData=()=>[{ref:'S',date:payload,buyer:payload,product:payload,brand:payload,qty:1,rate:1,through:payload,broker:payload,tare:payload,containers:[],charges:{loading:payload,craftLabour:payload,craftPaper:payload,misc:payload}}];p.renderExporterContainers=()=>{};p.renderSavedExporterSales();safe(p.document);assert.equal(p.document.getElementById('exLoading').value,payload);
p.productionRows=[{product:'Rice',systemFixed:true,bags:1,bagWeight:payload,narration:payload}];p.renderProductionTable();safe(p.document);assert.equal(p.document.querySelectorAll('#prodTable input').length,2);
p.document.body.insertAdjacentHTML('beforeend','<div id="reprocessBillHistory"></div>');p.STORE_REPROCESSBILLS='rp';p.currentMill=null;p.operationalAccountsStatus=()=> 'Unverified';p.get=()=>[{billNo:'R1',from:'2026-10-01',till:'2026-10-26',arrivalKg:payload,rawRemovedKg:payload,amount:1,paid:0,balance:1}];p.renderReprocessHistory();safe(p.document);
const expense=fs.readFileSync('accounts/expense-desk-ui.js','utf8'),start=expense.indexOf('function csvCell(');const csv=eval('('+expense.slice(start,expense.indexOf('\n  }',start)+4)+')');
for(const x of ['=1+1',' +CMD','\t@SUM(1)','\r-2','\u0000=1','\ufeff=1'])assert.ok(csv(x).startsWith('"\''),JSON.stringify(x));assert.equal(csv(-25),'"-25"');assert.equal(csv('Normal, "invoice"'),'"Normal, ""invoice"""');
outer.window.close();dom.window.close();pdom.window.close();console.log('PASS stored text/attribute/JS-token payloads in Mill tables, controls and print documents; original row identity and ordinary CSV numbers remain usable');

})().catch(e=>{console.error(e);process.exitCode=1;});
