'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const html=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const start=html.indexOf('function bagReceiptClosure('),end=html.indexOf('\nfunction selectBagOrder',start);
let orders=[],writes=0,messages=[],keep=false,delegations=[];
const context={window:{TT_BAG_RECEIPTS:{receive:(...args)=>delegations.push(args)}},normalizeBagOrderIds:r=>r,stockScopeMatches:()=>true,STORE_BAGS:'bags',currentBagOrder:1,defaultBagOrders:()=>[],get:()=>structuredClone(orders),set:(key,rows)=>{assert.equal(key,'bags');orders=rows;writes++},document:{getElementById:()=>({checked:keep})},renderBagOrders:()=>{},showNonBlockingMessage:m=>messages.push(m)};
vm.createContext(context);vm.runInContext(html.slice(html.indexOf('function bagOrderReceiptPosition('),html.indexOf('function bagTareGrams(')),context);vm.runInContext(html.slice(html.indexOf('function bagTareGrams('),start),context);assert.equal(context.bagTareGrams('75 g'),75);assert.equal(context.bagTareGrams('70.5 grams'),70.5);assert.equal(context.bagTareGrams('unknown'),0);vm.runInContext(html.slice(start,end),context);
orders=[{id:1,poNo:'P',ordered:3000,received:2985}];assert.equal(context.bagReceiptClosure(orders[0]).closed,true);
orders[0].received=2979;assert.equal(context.bagReceiptClosure(orders[0]).closed,false);
orders=[{id:1,poNo:'P',ordered:100,received:85},{id:2,poNo:'P',ordered:100,received:85}];assert.equal(context.bagReceiptClosure(orders[0]).closed,false,'Tolerance must not apply per line');
orders[1].received=100;assert.equal(context.bagReceiptClosure(orders[0]).closed,true);
keep=true;context.saveBagReceiptStatus({stopPropagation(){}});assert.deepEqual(delegations,[[true,true]]);assert.equal(writes,0,'Status must wait for the atomic receipt controller');assert.equal(orders[0].received,85);assert.equal(orders[1].received,100);
orders.forEach(row=>row.keepReceiptOpen=true);assert.equal(context.bagReceiptClosure(orders[0]).closed,false);
orders.forEach(row=>row.keepReceiptOpen=false);assert.equal(context.bagReceiptClosure(orders[0]).closed,true);assert.equal(orders[0].received,85,'Changing status never invents a receipt');
orders=[{id:1,poNo:'P',ordered:100,received:100},{id:2,poNo:'P',ordered:15,received:0}];assert.equal(context.bagReceiptClosure(orders[1]).closed,true,'Small unreceived packing line closes with the whole PO');
console.log('PASS Mill PO short-close boundaries, whole-PO tolerance, explicit reopen choice and untouched received quantities');

const nodes=Object.fromEntries(['completedBagOrders','completedBagOrdersBody'].map(id=>[id,{}]));
Object.assign(context,{
 document:{getElementById:id=>nodes[id]},bagOrdersBody:{},transferBrand:{},
 STORE_MILLS:'mills',defaultMills:()=>[],millNames:{},bagIssuedQty:()=>0,bagTransferredQty:()=>0,bagAvailableQty:()=>0,bagArtworkSource:()=>'',escHtml:String,
});
vm.runInContext(html.slice(html.indexOf('function renderBagOrders('),html.indexOf('function bagTareGrams(')),context);
orders=[
 {id:1,poNo:'PO-260001',contractRef:'TG/AMT/13',brand:'GHAZAL',ordered:5508,received:5508},
 {id:2,poNo:'PO-260005',contractRef:'TG/MGT/02',brand:'MEGA',ordered:5656,received:0},
];
context.currentBagOrder=null;
context.renderBagOrders();
assert.doesNotMatch(context.bagOrdersBody.innerHTML,/TG\/AMT\/13/);
assert.match(context.bagOrdersBody.innerHTML,/bag-marking-box[^>]*><span>MEGA<\/span>/);
assert.equal(nodes.completedBagOrders.hidden,false);
assert.match(nodes.completedBagOrdersBody.innerHTML,/PO-260001 · TG\/AMT\/13 · GHAZAL/);
console.log('PASS completed bag orders leave active receiving and retain receipt history');
