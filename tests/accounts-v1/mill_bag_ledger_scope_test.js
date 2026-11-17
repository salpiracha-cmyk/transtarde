const fs = require('node:fs');
const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const start = source.indexOf('async function renderBagLedger()');
const end = source.indexOf('\nfunction renderExportReport',start);
const markup = source.slice(source.indexOf('<div id="bagReport"'),source.indexOf('<div id="usedBagReport"'));
const dom = new JSDOM(markup,{runScripts:'outside-only'});
const w = dom.window;
const date = '2026-09-30';
const scope = {entity:'TTI',locationId:'mills-1'};
const movement = (date,kind,qty,reference,extra={}) => ({date,kind,qty,reference,type:kind,brand:'MEGA',size:50,...extra});
const rows = [
  movement('2026-08-31','stock',1000,'Receipt'),
  movement('2026-09-02','packing',-625,'Production',{shift:'Day'}),
  movement('2026-09-03','packedDispatch',-500,'GP-1 · Truck-1')
];
let reads = 0;
w.fetch = async url => {
  reads++;
  assert.match(url,/entity=TTI&locationId=mills-1&asOf=2026-09-30&purpose=ledger$/);
  return {ok:true,json:async()=>({ok:true,scope,asOf:date,rows})};
};
w.TT_MODULE_ACCESS = {masters:{mills:[{id:'mills-1',values:['TTI Rice Mills']}]}};
w.currentMill = {name:'TTI Rice Mills'};
w.stockEntityForView = ()=>'TTI';
w.millLocationIdentity = value=>value;
w.today = ()=>date;
w.escHtml = value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;');
w.eval(source.slice(start,end));
const q = selector=>w.document.querySelector(selector);
const closing = ()=>[...q('#bagLedgerBody tr:last-child').cells].map(cell=>cell.textContent);
(async()=>{
  await w.renderBagLedger();
  assert.equal(q('#bagPhysicalTab').getAttribute('aria-pressed'),'true');
  assert.doesNotMatch(q('#bagLedgerBody').textContent,/Production/);
  assert.match(q('#bagLedgerBody').textContent,/GP-1/);
  assert.equal(closing()[5],'500');
  q('#bagReportBrand').value='MEGA';
  q('#bagReportSize').value='50';
  q('#bagReportMode').value='From / To';
  q('#bagReportFrom').value='2026-09-01';
  w.selectBagLedgerView(true);
  assert.equal(reads,1);
  assert.equal(q('#bagReportBrand').value,'MEGA');
  assert.equal(q('#bagReportSize').value,'50');
  assert.match(q('#bagLedgerBody').textContent,/Day625/);
  assert.deepEqual(closing().slice(5,7),['375','125']);
  w.selectBagLedgerView(false);
  q('#bagReportMovement').value='Inward';
  await w.renderBagLedger();
  assert.doesNotMatch(q('#bagLedgerBody').textContent,/GP-1/);
  assert.equal(closing()[5],'500');
  q('#bagReportFrom').value='2026-10-01';
  await w.renderBagLedger();
  assert.match(q('#bagLedgerStatus').textContent,/valid From/);
  dom.window.close();
  console.log('PASS Mill bags: default physical view, dispatch without double counting, packing balance, shared filters, opening and hidden movement totals.');
})().catch(error=>{console.error(error);process.exitCode=1;});
