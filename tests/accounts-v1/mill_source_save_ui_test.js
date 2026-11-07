const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const root=path.join(__dirname,'../..'),html=fs.readFileSync(path.join(root,'milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html'),'utf8');
const dom=new JSDOM('<body><section id="processingWork"><input id="keBillDate" value="2026-10-17"><input id="keReadFrom" value="2026-09-01"><input id="keReadTo" value="2026-09-30"><input id="keRef" value="KE-SEP"><input id="keAmount" value="1200"><input id="keUnits" value="80"></section></body>',{url:'https://fixture.invalid/module.php?id=milling',runScripts:'outside-only'}),w=dom.window;
let calls=[],resets=0,printed=[],alerts=[],fail=true;
w.TT_MODULE_ACCESS={userId:7,module:'Mill',super:true};w.STORE_KEBILLS='tt38kebills';w.currentMill={id:1,entity:'TTI'};
w.stockEntityForView=()=> 'TTI';w.operationalStockScope=()=>({entity:'TTI',millId:1,millName:'TTI Rice Mills'});w.get=()=>[];w.today=()=> '2026-10-17';w.nowText=()=> '2026-10-17T12:00:00Z';
w.renderProcessingExpenses=()=>{};w.renderReprocessHistory=()=>{};w.processingTab=()=>{resets++;w.document.querySelector('#keRef').value='';};
w.alert=s=>alerts.push(s);w.showNonBlockingMessage=()=>{};w.TT_SHARED_SYNC={appendMillBill:async(key,rec,prefix)=>{calls.push({key,rec:JSON.parse(JSON.stringify(rec)),prefix});if(fail)throw Error('Connection unavailable');return rec;}};
w.eval(fs.readFileSync(path.join(root,'accounts/mill-source-save.js'),'utf8'));
w.eval(html.split('\n').find(l=>l.startsWith('async function saveKEBill(')));
const q=s=>w.document.querySelector(s);
(async()=>{
 q('#keRef').focus();q('#keRef').dispatchEvent(new w.Event('input',{bubbles:true}));assert.equal(calls.length,0,'Typing saved a bill');
 await w.saveKEBill();assert.equal(calls.length,1);assert.equal(resets,0);assert.equal(q('#keRef').value,'KE-SEP');assert.equal(w.document.activeElement,q('#keRef'));assert.ok(q('[data-mill-save-recovery]'));
 const savedId=calls[0].rec.id;fail=false;await w.saveKEBill();assert.equal(calls[1].rec.id,savedId,'Retry created another identity');assert.equal(resets,1);assert.equal(q('#keRef').value,'');
 // Reloaded/reopened forms expose the prior explicit save without needing to retype it.
 fail=true;q('#keRef').value='KE-OCT';await w.saveKEBill();assert.equal(resets,1);q('#keRef').value='current unfinished entry';fail=false;
 q('[data-mill-save-recovery] button').click();await new Promise(r=>setTimeout(r,0));assert.equal(q('#keRef').value,'current unfinished entry','Recovery confirmation cleared another draft');assert.equal(resets,1);assert.match(q('[data-mill-save-recovery]').textContent,/saved/);
 // Actual source print functions escape saved text and use the confirmed record.
 w.escHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 w.labourAmount=x=>x.qty*x.rate;w.eval(html.split('\n').find(l=>l.startsWith('function printLabourBill(')));
 const target={document:{write:s=>printed.push(s),close:()=>{}}};w.printLabourBill({billNo:'LB-1',contractor:'<img src=x onerror=evil()>',from:'2026-09-01',till:'2026-09-30',commodity:'Rice',amount:100,lines:[{label:'<script>evil()</script>',qty:1,rate:100,basis:'bag'}]},target);
 assert.ok(!printed[0].includes('<img')&&!printed[0].includes('<script>evil'));assert.match(printed[0],/&lt;img/);
 w.STORE_EXPENSES='tt37processingexpenses';w.set=()=>{throw Error('Unacknowledged operational or cash write');};
 w.document.querySelector('#processingWork').insertAdjacentHTML('beforeend','<input id="expenseDate" value="2026-10-17"><input id="expenseFrom" value="2026-10-01"><input id="expenseTo" value="2026-10-15"><input id="expenseRef" value="PLANT-1"><input id="expenseParty" value="Service provider"><input id="expenseAmount" value="700"><input id="expenseType" value="Plant Expense"><input id="expenseRemarks" value="Actual repairs">');
 w.eval(html.split('\n').find(l=>l.startsWith('async function saveProcessingExpense(')));
 fail=true;let n=calls.length;await w.saveProcessingExpense();assert.equal(calls.length,n+1);assert.equal(q('#expenseRef').value,'PLANT-1');let costId=calls.at(-1).rec.id;assert.equal(calls.at(-1).key,'tt37processingexpenses');assert.equal(calls.at(-1).rec.settlementMode,'CANONICAL_PAYABLE');assert.equal(calls.at(-1).rec.payment,undefined);
 fail=false;await w.saveProcessingExpense();assert.equal(calls.at(-1).rec.id,costId,'Processing retry changed source identity');
 dom.window.close();console.log('PASS actual native bill Save waits for acknowledgement, preserves failed form/focus, retries same identity, exposes recovery without clearing current draft, escapes saved print text');
})().catch(e=>{console.error(e);process.exitCode=1;dom.window.close();});
