const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
let records=[],writes=0,alerts=[];
const c={window:{},STORE_BAGS:'bags',get:()=>records,set:()=>writes++,defaultBagOrders:()=>[],alert:s=>alerts.push(s),currentBagOrder:7,bagOrdersBody:{innerHTML:''},bagReceiveCard:{classList:{add(){}}},transferBrand:{value:'A',innerHTML:''},transferQty:{value:'1'},transferMill:{value:'Other Mill'},bagReceiveQty:{value:'1'},document:{getElementById:()=>({value:'1',checked:false})},escHtml:s=>String(s)};
vm.createContext(c);
for(const name of ['normalizeBagOrderIds','bagOrderIdentityWarning','renderBagOrders','saveBagReceiptStatus','receiveBagsInline','receiveBags','transferBags']){const a=source.indexOf('function '+name+'('),b=source.indexOf('\n',a);assert(a>=0);vm.runInContext(source.slice(a,b),c);}
for(const bad of [[{id:7,brand:'A'},{id:7,brand:'B'}],[{brand:'A'}],[{id:0}],[{id:9007199254740992}],[{id:7},{id:'7'}],[{id:'007'}]]){
 records=structuredClone(bad);const before=JSON.stringify(records);assert.equal(c.normalizeBagOrderIds(records),null);c.currentBagOrder=7;c.renderBagOrders();assert.match(c.bagOrdersBody.innerHTML,/identities are missing or ambiguous/);assert.equal(c.currentBagOrder,null);
 for(const name of ['saveBagReceiptStatus','receiveBagsInline','receiveBags']){c.currentBagOrder=7;c[name]();assert.match(alerts.at(-1),/No records were changed/);}
 c.transferBags();assert.match(alerts.at(-1),/Reload Milling/);
 assert.equal(writes,0,'Neither list reads nor direct stale action calls may save ambiguous identities');assert.equal(JSON.stringify(records),before,'Original IDs and receipt associations are untouched');
}
records=[{id:7,brand:'A',transfers:[{to:'Old Mill'}]},{id:'8',brand:'B'}];const before=JSON.stringify(records),valid=c.normalizeBagOrderIds(records);assert.equal(valid[0].id,7);assert.equal(valid[1].id,8);assert.equal(JSON.stringify(records),before);assert.equal(writes,0);assert.notEqual(valid[0],records[0]);
console.log('PASS bag list never renumbers/saves on read; missing/duplicate/unsafe identities block stale receipt/status/transfer actions without changing history; valid identities remain usable.');
