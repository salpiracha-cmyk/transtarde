const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const source=fs.readFileSync('exports/app.js','utf8');
const start=source.indexOf('function exMillAllocation(a)');
const end=source.indexOf('function renderLoading__legacy_v0(',start);
assert(start>=0&&end>start,'Exports authorization functions are available');
const contract={ref:'TTI/ASAS/62',seller:'TTI',variety:'IRRI-6',riceType:'White Rice',brokenText:'100%'};
const soda={sodaNo:'26001',entity:'TTI',readyRoute:'EX_MILL',locationName:'indus rice mill',baseVariety:'IRRI-6',riceType:'White',brokenGrade:'100% Broken',qtyToKg:80400};
let sodas=[soda],handoffs=[];
const scope={
  fetch:async()=>({ok:true,json:async()=>({ok:true,sodas})}),
  state:{millSync:{get exportLoading(){return handoffs}}},
  normalizeLoadingAllocations:rows=>rows,
  contractByRef:()=>contract,
  num:value=>Number(value)||0,
  Date,
};
vm.createContext(scope);
vm.runInContext(source.slice(start,end),scope);
const rows=[{type:'Ex-Mill',name:'  Indus   Rice Mill ',containers:3,weightPer:26.8}];
const check=()=>scope.validateExMillSodaCapacity(rows,contract);

(async()=>{
  assert.equal(await check(),'','80.4 MT matches SODA 26001 despite White/White Rice and 100%/100% Broken');
  sodas=[{...soda,brokenGrade:'5% Broken'}];
  assert.match(await check(),/different broken grade/,'a different grade stays blocked');
  sodas=[{...soda,riceType:'Steam'}];
  assert.match(await check(),/different rice type/,'a different processing type stays blocked');
  sodas=[{...soda,baseVariety:'PK-386'}];
  assert.match(await check(),/different rice variety/,'a different variety stays blocked');
  sodas=[{...soda,entity:'BRM'}];
  assert.match(await check(),/No active TTI Ex-Mill/,'another company cannot authorize TTI');
  sodas=[{...soda,readyRoute:'DELIVER_TO_STOCK'}];
  assert.match(await check(),/No active TTI Ex-Mill/,'a stock delivery cannot authorize outside loading');
  sodas=[{...soda,locationName:'another rice mill'}];
  assert.match(await check(),/No active TTI Ex-Mill/,'another mill cannot authorize this loading');
  sodas=[{...soda,qtyToKg:80000}];
  assert.match(await check(),/exceed.*0\.400 MT/,'authorized quantity is still enforced');
  sodas=[soda];
  handoffs=[{shipmentId:'earlier',contractRef:contract.ref,plan:{allocations:[{type:'Ex-Mill',name:'indus rice mill',containers:1,weightPer:26.8}]}}];
  assert.match(await check(),/exceed.*26\.800 MT/,'prior instructions reserve capacity');
  assert.equal(await scope.validateExMillSodaCapacity(rows,contract,'earlier'),'','reissuing the same instruction excludes its previous reservation');
  console.log('PASS Ex-Mill product identity, location, entity, route, and capacity matching');
})().catch(error=>{console.error(error);process.exitCode=1});
