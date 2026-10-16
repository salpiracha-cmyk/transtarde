const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
(async()=>{
 const values=new Map(),calls=[],alerts=[];let posted=0,rendered=0;
 const context={window:{TT_MODULE_ACCESS:{csrf:'fixture'},TT_SHARED_SYNC:{saveNow:async()=>{posted++}}},
  STORE_EXMILL:'exmill',STORE_EXLOAD:'loads',STORE_EXINSTR:'instructions',STORE_MILLS:'mills',purchaseSodaFeed:[],
  get:(key,fallback)=>values.has(key)?values.get(key):fallback,set:(key,value)=>values.set(key,value),
  defaultExMillSodas:()=>[],defaultMills:()=>[],stableSodaId:id=>'local-'+id,refreshInstructionBadges(){},fillMasters(){},renderExMill(){},
  fetch:async()=>({ok:true,json:async()=>({ok:true,sodas:[{id:'S1',entity:'TTI',productStage:'READY',readyRoute:'EX_MILL',locationName:'Fixture mill',qtyToKg:1000}]})}),
  renderMillCards:()=>rendered++,prompt:()=>calls.shift(),alert:message=>alerts.push(message),console,Date,Map,Set,Number,String,Error,Promise};
 vm.createContext(context);
 const start=source.indexOf('function syncExMillPurchaseSodas('),end=source.indexOf('function queueProductChanged',start);
 vm.runInContext(source.slice(start,end),context);
 await context.loadPurchaseSodas();await context.loadPurchaseSodas();await Promise.resolve();
 assert.equal(posted,0,'Reading committed Sodas must not save operational state');
 assert.equal(values.get('exmill').length,1);assert.equal(values.get('exmill')[0]._ttPurchaseSodaId,'S1');
 const a=source.indexOf('async function syncLocationMaster('),b=source.indexOf('\n\n\nfunction defaultExMillSodas',a);
 vm.runInContext(source.slice(a,b),context);
 let release;context.fetch=()=>new Promise(resolve=>release=resolve);calls.push('Fixture mill','1');
 const pending=context.addMillPrompt();assert.equal(rendered,0);assert.ok(!values.has('mills'));
 release({ok:false,json:async()=>({ok:false,error:'Master save denied'})});await pending;
 assert.match(alerts[0],/Master save denied/);assert.equal(rendered,0);assert.ok(!values.has('mills'));
 context.fetch=async()=>({ok:true,json:async()=>({ok:true,location:{id:'M1',values:['Fixture mill']}})});
 calls.push('Fixture mill','1','Fixture mill','1');await context.addMillPrompt();await context.addMillPrompt();
 assert.equal(values.get('mills').length,1);assert.equal(values.get('mills')[0].masterId,'M1');assert.equal(rendered,2);assert.equal(posted,2,'Only the explicit Add Mill actions commit shared state');
 console.log('PASS Soda reads do not save, failed masters do not create local mills, acknowledged canonical IDs persist and retries do not duplicate');
})().catch(error=>{console.error(error);process.exitCode=1});
