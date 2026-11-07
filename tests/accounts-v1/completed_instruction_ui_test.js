const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8'),start=source.indexOf('function renderProductionInstructions()'),end=source.indexOf('\nfunction ',start+20);
assert(start>=0&&end>start);
const rows=[{id:1,contractRef:'OLD',requiredMT:80.4,producedMT:0,brand:'STAR',variety:'Rice',quality:'Export',requiredBy:''}];
for(const status of ['Completed','Closed','Cancelled','Open']){
 let writes=0;const context={STORE_PRODINST:'pi',defaultProductionInstructions:()=>[],get:key=>key==='pi'?rows:{contracts:[{ref:'OLD',status}]},instructionList:{innerHTML:''},escHtml:v=>String(v??'').replaceAll('<','&lt;'),localStorage:{setItem(){writes++}}};
 vm.runInNewContext(source.slice(start,end),context);context.renderProductionInstructions();
 assert.equal(context.instructionList.innerHTML.includes('No new production due'),status!=='Open');
 assert.equal(context.instructionList.innerHTML.includes('80.4 MT'),true,'Original specification quantities remain');
 assert.equal(rows[0].producedMT,0,'No fake production is recorded');assert.equal(writes,0,'Rendering never changes stock or instructions');
}
console.log('PASS completed production instructions: reference specifications retained, no new requirement or fake production, active requirements unchanged');
