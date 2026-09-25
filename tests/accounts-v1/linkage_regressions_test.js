const assert=require('assert');
const fs=require('fs');
const vm=require('vm');

const root=process.cwd();
const read=file=>fs.readFileSync(root+'/'+file,'utf8');
const milling=read('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html');
const modulePhp=read('module.php');
const bagUi=read('accounts/bag-ops-sync-ui.js');
const sodaPhp=read('api/purchase_sodas.php');
const sodaFeedPhp=read('api/milling_purchase_sodas.php');
const sourceBridge=read('accounts/source-bridge.js');
const desk=read('accounts/accounts-accounting-desk.js');
const bagPurchases=read('accounts/bag-purchases-ui.js');
const arrivalBills=read('accounts/bill-smart-ui-v2.js');

function extractFunction(source,name){
  const start=source.indexOf('function '+name+'(');
  assert.ok(start>=0,'missing function '+name);
  const body=source.indexOf('{',start);
  let depth=0,quote='',escaped=false;
  for(let i=body;i<source.length;i++){
    const c=source[i];
    if(quote){if(escaped)escaped=false;else if(c==='\\')escaped=true;else if(c===quote)quote='';continue}
    if(c==='"'||c==="'"||c==='`'){quote=c;continue}
    if(c==='{')depth++;
    else if(c==='}'&&--depth===0)return source.slice(start,i+1);
  }
  throw new Error('unterminated function '+name);
}

assert.match(modulePhp,/poLineKey:lineKey/,'Export bag bridge must carry the explicit PO line key');
assert.match(modulePhp,/poLineKey:masterKey/,'Master bags must carry their own stable PO line key');
assert.match(bagUi,/tt:bag-workspace-open/,'opening Bags must have an explicit read-only refresh path');
assert.match(bagUi,/TT_BAG_PURCHASES_UI\?\.reload/,'the Bags workspace must refresh its server-owned view');
assert.doesNotMatch(bagUi,/method:\s*['"]POST['"]|setInterval/,'opening Bags must not post or poll in the background');
assert.match(desk,/ttPurchaseMode = 'bags'/,'Bags navigation must claim the shared purchase editor before asynchronous rendering');
assert.match(bagPurchases,/ttPurchaseMode='bags'/,'the Bags renderer must retain ownership of the shared purchase editor');
assert.match(arrivalBills,/ttPurchaseMode==='bags'/,'a late Arrival Bills response must not overwrite the Bags form');

assert.match(sodaPhp,/\$rows=&\$data\['masters'\]\['business_parties'\]/,'Master mutations must target the canonical stored array by reference');
assert.doesNotMatch(sodaPhp,/foreach\(\(array\)\(\$data\['masters'\]\['business_parties'\]/,'Master mutations must not iterate a cast temporary by reference');

const stores={};
const exContext={
  STORE_EXMILL:'ex',STORE_EXLOAD:'loads',purchaseSodaFeed:[],
  defaultExMillSodas:()=>[],get:(key,fallback)=>structuredClone(stores[key]??fallback),
  set:(key,value)=>{stores[key]=structuredClone(value)},
  millLocationIdentity:value=>String(value||'').toLowerCase().replace(/[^a-z0-9]+/g,''),
  stableSodaId:value=>700000000+String(value).length,nowText:()=> 'QA'
};
vm.createContext(exContext);
vm.runInContext(extractFunction(milling,'syncExMillPurchaseSodas'),exContext);

stores.ex=[{id:222,_ttBridge:'exports',_ttBridgeId:'EXP-A',entity:'TTI',mill:'Outside Mill',baseVariety:'IRRI-6',riceType:'White',loadedKg:0}];stores.loads=[];
exContext.purchaseSodaFeed=[{id:'S-BRM',entity:'BRM',sodaNo:'1',productStage:'READY',readyRoute:'EX_MILL',locationName:'Outside Mill',baseVariety:'IRRI-6',riceType:'White',displayName:'IRRI-6 White Ready Rice',qtyToKg:100000}];
exContext.syncExMillPurchaseSodas();
assert.equal(stores.ex.find(x=>x.id===222)._ttPurchaseSodaId,undefined,'Cross-entity Ex-Mill rows must not auto-link');

stores.ex=[{id:111,_ttBridge:'accounts-soda',_ttBridgeId:'S-1',_ttPurchaseSodaId:'S-1',entity:'TTI',mill:'Outside Mill',baseVariety:'IRRI-6',riceType:'White',loadedKg:0},{id:222,_ttBridge:'exports',_ttBridgeId:'EXP-1',entity:'TTI',mill:'Outside Mill',baseVariety:'IRRI-6',riceType:'White',loadedKg:0}];
stores.loads=[{id:1,sodaId:222,kg:25000}];
exContext.purchaseSodaFeed=[{id:'S-1',entity:'TTI',sodaNo:'1',productStage:'READY',readyRoute:'EX_MILL',locationName:'Outside Mill',baseVariety:'IRRI-6',riceType:'White',displayName:'IRRI-6 White Ready Rice',qtyToKg:100000}];
exContext.syncExMillPurchaseSodas();
assert.ok(stores.ex.some(x=>x.id===111&&x._ttBridgeId==='EXP-1'),'Exact merge must preserve the Accounts row identity');
assert.ok(!stores.ex.some(x=>x.id===222),'Merged duplicate row must be removed');
assert.equal(stores.loads[0].sodaId,111,'Dependent load references must be remapped to the surviving row');

exContext.purchaseSodaFeed=[];
exContext.syncExMillPurchaseSodas();
assert.equal(stores.ex.find(x=>x.id===111).sourceRouteStatus,'ROUTE_CHANGED','A route change with loaded history must be retained for review');

const stockFns=['millBaseVariety','millRiceType','millProductIdentity','stockEntityForView','isOperationalProductionRow','stockScopeMatches','resolveStockKey','shipmentLoadedWeight','computedStockRows'].map(n=>extractFunction(milling,n)).join('\n');
const stockStores={};
const stockContext={
  STORE_PROD:'prod',STORE_SLIPS:'slips',STORE_LOCALSALES:'local',STORE_EXPORTERSALE:'exporter',STORE_SHIP:'ship',STORE_STOCKADJ:'adj',
  currentMill:{id:1,name:'TTI Rice Mills'},get:(key,fallback)=>structuredClone(stockStores[key]??fallback),
  defaultExporterSales:()=>[],defaultShipments:()=>[],millLocationIdentity:value=>String(value||'').toLowerCase().replace(/[^a-z0-9]+/g,'')
};
vm.createContext(stockContext);vm.runInContext(stockFns,stockContext);
Object.assign(stockStores,{slips:[{millId:1,millName:'TTI Rice Mills',baseVariety:'IRRI-6',riceType:'White',productStage:'RAW',payableWeight:100000}],prod:[{millId:1,millName:'TTI Rice Mills',baseVariety:'IRRI-6',riceType:'White',inputStage:'RAW',rows:[{product:'Ready Rice — BRAND',bags:2000,bagWeight:50}]}],local:[],exporter:[],ship:[],adj:[]});
let stock=stockContext.computedStockRows();
assert.ok(!stock.some(x=>/Raw Rice$/i.test(x.name)),'Typed raw receipt and production consumption must cancel on the same stock key');

stockStores.slips=[{millId:1,millName:'TTI Rice Mills',baseVariety:'IRRI-6',riceType:'White',productStage:'RAW',payableWeight:10000},{millId:2,millName:'Other Mill',baseVariety:'IRRI-6',riceType:'White',productStage:'RAW',payableWeight:20000}];stockStores.prod=[];
stock=stockContext.computedStockRows();
assert.equal(stock.find(x=>/Raw Rice$/i.test(x.name)).kg,10000,'Stock must be scoped to the selected mill');

stockStores.slips=[];stockStores.prod=[{millId:1,millName:'TTI Rice Mills',baseVariety:'IRRI-6',riceType:'White',rows:[{product:'Ready Rice — SAME',bags:1200,bagWeight:50}]},{millId:1,millName:'TTI Rice Mills',baseVariety:'1121',riceType:'White',rows:[{product:'Ready Rice — SAME',bags:1200,bagWeight:50}]}];stockStores.ship=[{mill:'TTI Rice Mills',baseVariety:'1121',riceType:'White',brand:'SAME',containers:[{weight:25000}]}];
stock=stockContext.computedStockRows();
assert.equal(stock.find(x=>x.name==='IRRI-6 White Rice — SAME').kg,60000,'Same-brand loading must not reduce another product');
assert.equal(stock.find(x=>x.name==='1121 White Rice — SAME').kg,35000,'Loading must reduce the matching structured product identity');

assert.match(sourceBridge,/kind:'exMillLifting'/,'Ex-Mill loading must create an explicit Accounts fulfillment handoff');
assert.match(sodaFeedPhp,/purchaseSodaLiftings/,'Ex-Mill lifting handoffs must persist idempotently');
assert.match(sodaPhp,/\$store\['purchaseSodaLiftings'\]/,'Accounts Soda quantities must include Ex-Mill liftings');

const bankBridge=read('auth_store.php');
const bankApi=read('api/bank_accounts.php');
const receiptApi=read('api/export_receipts.php');
const receiptUi=read('accounts/export-receipts-ui.js');
assert.match(bankBridge,/\(string\)\(\$bank\['status'\] \?\? 'Active'\)/,'Derived company bank rows must expose master status, not notes');
assert.match(bankBridge,/'notes'=>\(string\)\(\$bank\['notes'\]/,'Company bank notes remain available separately');
assert.match(bankApi,/strcasecmp\(\(string\)\(\$a\['masterStatus'\]/,'Default receipt readiness must use the derived master status');
assert.match(receiptApi,/Complete the account number or IBAN in Company Master before receiving money/,'Receipts must reject incomplete accounts on the server');
const receiptBankContext={banks:{accounts:[
  {id:'tti-ready',currency:'PKR',masterStatus:'Active',settings:{active:true,allowReceipts:true},needsCompletion:false},
  {id:'tti-disabled',currency:'PKR',masterStatus:'Active',settings:{active:false,allowReceipts:true},needsCompletion:false},
  {id:'tti-incomplete',currency:'PKR',masterStatus:'Active',settings:{active:true,allowReceipts:true},needsCompletion:true},
  {id:'tti-master-inactive',currency:'PKR',masterStatus:'Inactive',settings:{active:true,allowReceipts:true},needsCompletion:false}
]}};
vm.createContext(receiptBankContext);
vm.runInContext(extractFunction(receiptUi,'receiptBanks')+'\n'+extractFunction(receiptUi,'bankUnavailableReason'),receiptBankContext);
assert.deepEqual([...receiptBankContext.receiptBanks('PKR')].map(row=>row.id),['tti-ready','tti-disabled'],'Old Accounts toggles must not disable an active company bank');
assert.match(receiptBankContext.bankUnavailableReason(receiptBankContext.banks.accounts[2]),/account number or IBAN/);
assert.match(receiptBankContext.bankUnavailableReason(receiptBankContext.banks.accounts[3]),/Active in Company Master/);
const tgReceiptContext={payerType:'TG',selectedTgPayment:null,currency:'USD',chosen:new Map(),num:Number,
  reference:'FI-TG-123',amount:500,
  q(selector){return selector==='#erForeign'?{value:String(this.amount)}:{value:this.reference}},
  sourceRows(){return [{isTg:true,currency:'USD',invoiceRef:'FI-TG-123',targetId:'posted-tg-1',contractRef:'TG-123',amount:700}];}
};
// Keep DOM inputs in the fixture separate from the source data so the exact
// reference and the unmatched receipt exercise the same form function.
tgReceiptContext.q=selector=>selector==='#erForeign'?{value:String(tgReceiptContext.amount)}:{value:tgReceiptContext.reference};
vm.createContext(tgReceiptContext);
vm.runInContext(extractFunction(receiptUi,'allocations'),tgReceiptContext);
assert.equal(tgReceiptContext.allocations()[0].targetId,'posted-tg-1','Exact TG invoice references should clear the linked receivable');
tgReceiptContext.reference='UNKNOWN-TG';
assert.equal(tgReceiptContext.allocations()[0].targetType,'UNAPPLIED_TG','A TG receipt without a posted invoice must remain identifiable and unapplied');
assert.match(receiptApi,/er_line\('2510'.*'Unapplied TG'/,'Unapplied TG money must credit the proper liability');

console.log('PASS Accounts cross-module linkage regressions');
