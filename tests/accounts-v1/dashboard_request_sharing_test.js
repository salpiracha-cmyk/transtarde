'use strict';
// Display grouping must retain every due date, broker and currency without changing source balances.
{
 const a=require('node:assert/strict'),f=require('node:fs'),v=require('node:vm');
 const source=f.readFileSync('accounts/accounts-accounting-desk.js','utf8');
 const scope={Map,Date,JSON,Number,String,esc:s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),money:n=>Number(n).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}),today:()=> '2026-11-24'};
 v.createContext(scope);v.runInContext(source.slice(source.indexOf('  function commodityDueDetail('),source.indexOf('  function homeDataNeeded(')),scope);
 const rows=[{broker:'Shams Broker',date:'2026-12-07',currency:'PKR',amount:4658855,reference:'LATE-BILL'},
  {broker:'Shams Broker',date:'2026-11-29',currency:'PKR',amount:4961000,reference:'BILL-A'},
  {broker:'shams  broker',date:'2026-11-29',currency:'PKR',amount:4627597.30,reference:'BILL-B'},
  {broker:'Other Broker',date:'2026-11-29',currency:'PKR',amount:.10},
  {broker:'Other Broker',date:'2026-11-29',currency:'PKR',amount:.20},
  {broker:'Other Broker',date:'2026-11-29',currency:'USD',amount:7},
  {broker:'<script>bad</script>',date:'2026-11-10',currency:'PKR',amount:1},
  {date:'',currency:'PKR',amount:2}];
 const before=JSON.stringify(rows),html=scope.commodityDueDetail(rows);
 a(html.indexOf('10-11-2026')<html.indexOf('29-11-2026')&&html.indexOf('29-11-2026')<html.indexOf('07-12-2026'),'Due dates must be earliest first, including overdue');
 a.equal((html.match(/29-11-2026/g)||[]).length,1,'One heading per due date');
 a(html.includes('PKR 9,588,597.30')&&html.includes('PKR 4,658,855.00'),'Same broker combines only within the same due date');
 a(html.includes('PKR 0.30')&&html.includes('USD 7.00'),'Round monetary cents and keep currencies separate');
 a(!html.includes('BILL-A')&&!html.includes('LATE-BILL'),'Dropdown contains broker amounts, not individual bill references');
 a(html.includes('&lt;script&gt;')&&!html.includes('<script>'),'Broker names must be escaped');
 a(html.indexOf('Due date not recorded')>html.indexOf('07-12-2026')&&html.includes('Broker not recorded'),'Missing dates go last and missing brokers are explicit');
 a.equal(JSON.stringify(rows),before,'Display grouping cannot modify source amounts or dates');
 const many=scope.commodityDueDetail(Array.from({length:31},(_,i)=>({broker:'B'+i,date:'2026-12-'+String(i+1).padStart(2,'0'),amount:1})));
 a(many.includes('31-12-2026'),'Do not truncate balances after twenty rows');
 a.equal(scope.commodityDueDetail([]),'<div>No open balance.</div>');
 const summary=scope.attentionSummary({kind:'SODA',reference:'26011',message:'Old text',statement:{broker:'Shams <Broker>',dueDate:'2026-11-10',shortKg:19200}});
 for(const text of ['SODA 26011','Shams &lt;Broker&gt;','19.200 MT outstanding','Due 10-11-2026','14 days overdue'])a(summary.includes(text),text);
 a.equal(scope.attentionSummary({kind:'HOLD',message:'Keep <hold>',reference:'H1'}),'Keep &lt;hold&gt; · H1','Other attention items keep their existing text');
 console.log('PASS broker/date grouping, due ordering, currency and cents, missing values, full coverage, escaping and overdue SODA details');
}
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');
const calls=[],context={URLSearchParams,fetch:(url,options)=>new Promise(resolve=>calls.push({url,options,resolve}))};
vm.createContext(context);vm.runInContext(source.slice(source.indexOf('  const dashboardRequests ='),source.indexOf('  async function loadSodas()'))+';globalThis.request=json;',context);
const done=(index,body={ok:true},status=200)=>calls[index].resolve({ok:status<400,status,json:async()=>body});
(async()=>{
 const url='../api/accounts_dashboard.php?entity=TTI';
 const first=context.request(url),duplicate=context.request(url);assert.equal(first,duplicate);assert.equal(calls.length,1);
 const tg=context.request('../api/accounts_dashboard.php?entity=TG');assert.equal(calls.length,2);done(1);await tg;done(0);await first;
 const fresh=context.request(url);assert.equal(calls.length,3,'Completed balances must never be cached');done(2);await fresh;
 const old=context.request(url),write=context.request('../api/supplier_settlements.php',{method:'POST'});done(4);await write;
 const afterWrite=context.request(url);assert.equal(calls.length,6,'Successful mutation must bypass a pre-mutation read');done(3);await old;
 assert.equal(context.request(url),afterWrite,'Old completion must not evict a newer in-flight request');done(5);await afterWrite;
 const failed=context.request(url);done(6,{ok:false,error:'Unavailable'},500);await assert.rejects(failed,/Unavailable/);
 const retry=context.request(url);assert.equal(calls.length,8);done(7);await retry;
 const recent='../api/accounts_ledger_browser.php?entity=TTI&activity=vouchers&page=1&pageSize=50';const r=context.request(recent);assert.equal(context.request(recent),r);done(8);await r;
 console.log('PASS concurrent reads, company isolation, uncached completion, mutation freshness, stale completion and error retry');
})().catch(e=>{console.error(e);process.exitCode=1;});
