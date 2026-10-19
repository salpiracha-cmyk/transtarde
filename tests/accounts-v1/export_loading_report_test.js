const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const file=process.env.EXPORT_REPORT_SOURCE||'milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html',source=fs.readFileSync(file,'utf8'),a=source.indexOf('function renderExportReport(){'),b=source.indexOf('\nfunction showNonBlockingMessage',a);
assert(a>=0&&b>a,'Native report function required');
const dom=new JSDOM('<input id="exportReportFrom" value="2026-09-01"><input id="exportReportTo" value="2026-09-30"><select id="exportReportSource"><option>All</option><option>Transtrade Rice Mill</option><option>Ex-Mill</option></select><select id="exportReportStatus"><option>All</option><option>Loading</option><option>Completed</option></select><select id="exportReportBrand"><option>All Brands</option></select><select id="exportReportMill"><option>All</option></select><table><tbody id="exportReportBody"></tbody></table>',{runScripts:'outside-only'}),w=dom.window;
let entity='TTI';
const stores={ships:[
 {entity:'TTI',millName:'Indus Rice Mill',brand:'RICE',status:'Completed',containers:[{container:'OWN-IN',date:'2026-09-23',weight:1000,bags:20},{container:'FUTURE',date:'2026-10-01',weight:90},{container:'UNKNOWN-DATE',date:'',weight:40},{container:'IMPOSSIBLE-DATE',date:'2026-02-30',weight:5}]},
 {entity:'BRM',millName:'BRM Mill',brand:'PRIVATE',containers:[{container:'BRM-ONLY',date:'2026-09-23',weight:999999}]},
 {entity:'TTI',stockLocation:'Second Warehouse',brand:'MAKAI',containers:[{container:'ZERO-BAG-BULK',date:'2026-09-25',weight:25000,bags:0}]},
 {brand:'LEGACY',containers:[{container:'NO-OWNER-OR-MILL',date:'2026-09-24',weight:1}]}
],sodas:[{id:7,entity:'TTI',mill:'Outside Mill',product:'CORN',brand:'MAKAI'},{id:8,entity:'BRM',mill:'BRM Outside',brand:'OTHER'},{id:9,entity:'TTI',mill:'AMBIGUOUS ONE'},{id:'9',entity:'TTI',mill:'AMBIGUOUS TWO'}],loads:[
 {sodaId:'7',instructionId:'I7',shipmentId:'S7',contractRef:'C7',lotRef:'L7',issue:'OK',container:'EX-IN',date:'2026-09-26',kg:27000,bags:0},
 {sodaId:7,instructionId:'I8',shipmentId:'S8',contractRef:'C8',lotRef:'L8',issue:'HOLD',container:'EX-PENDING',date:'2026-09-26',kg:15,bags:1},
 {sodaId:7,instructionId:'CONFLICT',shipmentId:'S7',contractRef:'C7',lotRef:'L7',issue:'OK',container:'EX-CONFLICT',date:'2026-09-26',kg:5},
 {sodaId:7,instructionId:'DUP-INSTRUCTION',issue:'OK',container:'EX-DUP-INSTRUCTION',date:'2026-09-26',kg:1},
 {sodaId:8,container:'BRM-EX',date:'2026-09-26',kg:9000},
 {sodaId:99,container:'UNLINKED',date:'2026-09-26',kg:10},
 {sodaId:9,container:'DUPLICATE-PARENT',date:'2026-09-26',kg:20}
]};
stores.instructions=[{id:'I7',entity:'TTI',localSodaId:7,shipmentId:'S7',contractRef:'C7',lotRef:'L7',loadingComplete:true},{id:'I8',entity:'TTI',localSodaId:7,shipmentId:'S8',contractRef:'C8',lotRef:'L8',loadingComplete:false},{id:'CONFLICT',entity:'TTI',localSodaId:99,shipmentId:'S7',contractRef:'C7',lotRef:'L7',loadingComplete:true},{id:'DUP-INSTRUCTION',localSodaId:7,loadingComplete:true},{id:'DUP-INSTRUCTION',localSodaId:7,loadingComplete:true}];
w.STORE_SHIP='ships';w.STORE_EXLOAD='loads';w.STORE_EXINSTR='instructions';w.get=(key,fallback)=>stores[key]||fallback;w.exMillSodas=()=>stores.sodas;w.defaultShipments=()=>[];w.stockEntityForView=()=>entity;
w.today=()=>{throw Error('A report must not invent a loading date');};w.set=()=>{throw Error('A report must not save');};
w.escHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));w.eval(source.slice(a,b));
const q=id=>w.document.getElementById(id),text=()=>q('exportReportBody').textContent,render=()=>w.renderExportReport(),row=id=>[...q('exportReportBody').rows].find(r=>r.textContent.includes(id)),before=JSON.stringify(stores);
render();assert.match(row('OWN-IN').textContent,/Indus Rice Mill — RICE/);assert.doesNotMatch(text(),/TTI Rice Mills|BRM-ONLY|BRM-EX|999,999|FUTURE/);
assert.match(row('UNKNOWN-DATE').textContent,/Undated.*load date not recorded/);assert.match(row('IMPOSSIBLE-DATE').textContent,/Undated.*invalid/);
assert.match(row('ZERO-BAG-BULK').textContent,/Second Warehouse — MAKAI/);assert.equal(row('ZERO-BAG-BULK').cells[6].textContent,'0');
assert.match(row('EX-IN').textContent,/Outside Mill — MAKAI/);assert.match(row('EX-IN').cells[9].textContent,/^Completed · Quality: OK$/);assert.match(row('EX-PENDING').cells[9].textContent,/^Loading · Quality: HOLD$/);assert.match(row('EX-CONFLICT').cells[9].textContent,/^Loading.*instruction conflicts with source/);assert.match(row('EX-DUP-INSTRUCTION').cells[9].textContent,/^Loading.*ambiguous loading instruction/);assert.match(row('NO-OWNER-OR-MILL').textContent,/Mill not recorded.*company not recorded; mill not recorded/);
assert.match(row('UNLINKED').textContent,/missing Soda link/);assert.match(row('DUPLICATE-PARENT').textContent,/ambiguous Soda link/);assert.doesNotMatch(text(),/AMBIGUOUS ONE|AMBIGUOUS TWO/);
q('exportReportMill').value='Indus Rice Mill';render();assert(row('OWN-IN'));assert(!row('EX-IN'));assert(!row('ZERO-BAG-BULK'));
q('exportReportMill').value='All';q('exportReportSource').value='Ex-Mill';render();assert(row('EX-IN'));assert(!row('OWN-IN'));
q('exportReportSource').value='All';q('exportReportStatus').value='Completed';render();assert(row('OWN-IN'));assert(row('EX-IN'));assert(!row('EX-PENDING'));assert(!row('EX-CONFLICT'));assert(!row('EX-DUP-INSTRUCTION'));
q('exportReportStatus').value='Loading';render();assert(!row('OWN-IN'));assert(!row('EX-IN'));assert(row('EX-PENDING'));assert(row('EX-CONFLICT'));
q('exportReportStatus').value='All';q('exportReportBrand').value='MAKAI';render();assert(row('EX-IN'));assert(row('ZERO-BAG-BULK'));assert(!row('OWN-IN'));
q('exportReportBrand').value='All Brands';entity='BRM';render();assert(row('BRM-ONLY'));assert(row('BRM-EX'));assert(!row('OWN-IN'));assert(!row('NO-OWNER-OR-MILL'));assert(!row('UNLINKED'));
entity='TTI';q('exportReportFrom').value='2026-10-01';q('exportReportTo').value='2026-09-01';render();assert.match(text(),/valid From/);
q('exportReportFrom').value='2026-09-01';q('exportReportTo').value='2026-09-23';render();assert(row('OWN-IN'));assert(!row('EX-IN'));assert(row('UNKNOWN-DATE'),'Undated history stays explicit instead of acquiring a date');
stores.ships.push({entity:'TTI',millName:'<script>bad()</script>',brand:'<b>unsafe</b>',containers:[{container:'ESCAPED',date:'2026-09-23',weight:1}]});render();assert(row('ESCAPED'));assert.equal(q('exportReportBody').querySelectorAll('script,b').length,0,'Saved labels must be escaped');stores.ships.pop();
assert.equal(JSON.stringify(stores),before,'Native sources must remain byte-equivalent after filtering/rendering');
console.log('PASS native Unified Export Loading Report: original mill/company, exact/undated/invalid loading dates, periods, zero-bag Corn, unique Soda/instruction identity, separate completion/quality filters, escaped labels and zero source writes.');
