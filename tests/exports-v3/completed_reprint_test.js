'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(process.argv[2]||'exports/app.js','utf8'),start=source.indexOf('async function directPrint('),end=source.indexOf('\nfunction fitCOOPages(',start);
assert(start>=0&&end>start,'Current print entry point unavailable');
(async()=>{
 for(const mode of ['with','without'])for(const readOnly of [true,false])for(const available of [true,false]){
  let saves=0,prints=0,errors=0;
  const root={className:'',innerHTML:'',setAttribute(){},querySelectorAll:()=>[]};
  const context={document:{title:'Exports',createElement:()=>({innerHTML:'',content:{querySelector:()=>null}}),getElementById:()=>root},window:{TT_SHARED_SYNC:{saveNow:async()=>{saves++;if(!available)throw Error('No save acknowledgement');return{ok:true}}},print:()=>prints++},showExportSaveError:()=>errors++,waitForPrintImages:async()=>{},fitCOOPages:()=>true,fitTGProformaPages:()=>true,setTimeout:fn=>fn(),console,alert:()=>errors++};
  vm.createContext(context);vm.runInContext(source.slice(start,end),context);
  const result=await context.directPrint('Packing List','<packing>',mode,readOnly);
  assert.equal(saves,readOnly?0:1,'Completed reprint must not attempt a workspace save');
  assert.equal(prints,readOnly||available?1:0,'Active print must wait for save acknowledgement');
  assert.equal(result,readOnly||available,'Failed active save cannot print');assert.equal(errors,!readOnly&&!available?1:0);
 }
 console.log('PASS completed packing reprint: zero saves for both paper modes even with save service unavailable; active prints still require acknowledged save');
})().catch(error=>{console.error(error);process.exitCode=1});
