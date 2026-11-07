(() => {
'use strict';
const norm=value=>String(value??'').trim().replace(/\s+/g,' ').toLowerCase();
let baseline=null,review=null,generation=0;

function brand(row){
 if(row.noStockPost||row.managedReconciliation||row.systemFixed)return '';
 const name=String(row.product||'').match(/^(?:Ready Rice|B2)\s*—\s*(.+)$/u)?.[1]?.trim()||'';
 return ['local','local bags'].includes(norm(name))?'':name;
}
function location(record){
 const id=record.locationId||record.bagAllocationScope?.locationId||'';
 const matches=(window.TT_MODULE_ACCESS?.masters?.mills||[]).filter(row=>id?String(row.id)===String(id):norm(row.values?.[0])===norm(record.millName||record.mill));
 if(matches.length!==1)throw Error('Resolve the actual production location in Mills & Locations.');
 return String(matches[0].id);
}
function usage(record){
 return (record.rows||[]).flatMap(row=>{
  const name=brand(row);
  if(!name)return [];
  const raw=row.actualBagCount??row.bags??0,qty=Number(raw),packingKg=Number(row.bagWeight);
  if(String(raw).trim()===''||!Number.isSafeInteger(qty)||qty<0)throw Error('Enter the actual whole bag count for '+name+' in the production row.');
  if(!qty)return [];
  if(!Number.isFinite(packingKg)||packingKg<=0)throw Error('Enter the actual packing weight for '+name+'.');
  return [{name,qty,packingKg,lotId:row.bagSourceLotId||'',dealId:row.exporterDealId||'',contractRef:row.contractRef||''}];
 });
}
function signature(record){
 return JSON.stringify([String(record.id??''),record.entity,location(record),record.date,usage(record)]);
}
function allocate(needs,lots){
 const allocations=new Map(),selectedLots=new Set(needs.filter(need=>need.lotId).map(need=>need.lotId));
 const available=lot=>lot.hold?0:Math.max(0,(lot.committableQty??0)-(allocations.get(lot.id)||0));
 // Reserve explicitly selected markings first. Unbound rows draw from the remaining compatible stock.
 for(const need of [...needs.filter(row=>row.lotId),...needs.filter(row=>!row.lotId)]){
  const matching=lots.filter(lot=>norm(lot.brand)===norm(need.name)&&Number(lot.packingKg)===need.packingKg&&
   (need.lotId?lot.id===need.lotId:!selectedLots.has(lot.id))&&(lot.exporterDealId||'')===need.dealId&&(!need.contractRef||lot.contractRef===need.contractRef));
  const total=matching.reduce((sum,lot)=>sum+available(lot),0);
  if(total<need.qty)throw Error(need.name+' · '+need.packingKg+' kg: '+need.qty+' bags needed, '+total+' available. Check bags received for this mill and date.');
  let remaining=need.qty;
  for(const lot of matching){
   const qty=Math.min(remaining,available(lot));
   if(qty)allocations.set(lot.id,(allocations.get(lot.id)||0)+qty);
   remaining-=qty;
   if(!remaining)break;
  }
 }
 return [...allocations].map(([lotId,qty])=>({lotId,qty}));
}
async function autoReview(record){
 review=null;
 const request=++generation,needs=usage(record);
 if(!needs.length&&!baseline?.bagAllocationVersion)return;
 const stamp=signature(record);
 if(baseline?.bagAllocationVersion===1&&signature(baseline)===stamp)return;
 const millId=location(record);
 const query=new URLSearchParams({purpose:'production',entity:record.entity,locationId:millId,asOf:record.date,...(baseline&&record.id!=null?{excludeProductionId:String(record.id)}:{})});
 const response=await fetch('/api/bag_transfers.php?'+query,{credentials:'same-origin',headers:{Accept:'application/json'}});
 const data=await response.json();
 if(!response.ok||!data.ok)throw Error(data.error||'Bag stock could not be checked. Your production entry is retained.');
 const date=document.getElementById('prodDate')?.value,entity=document.getElementById('prodEntity')?.value;
 const currentNeeds=typeof productionRows==='undefined'?needs:usage({rows:productionRows});
 if(request!==generation||date&&date!==record.date||entity&&entity!==record.entity||
  JSON.stringify(needs)!==JSON.stringify(currentNeeds)||data.entity!==record.entity||data.locationId!==millId||data.inventory?.asOfDate!==record.date)throw Error('The production or stock scope changed. Check the entry and save again.');
 review={stamp,inventory:data.inventory};
}
function prepare(record){
 const needs=usage(record);
 if(!needs.length&&!baseline?.bagAllocationVersion)return record;
 const stamp=signature(record),locationId=location(record);
 if(baseline?.bagAllocationVersion===1&&signature(baseline)===stamp){
  const saved={...record,locationId};
  for(const key of ['bagAllocationVersion','bagAllocations','bagStockFingerprint','bagAllocationActor','bagAllocationScope'])saved[key]=baseline[key];
  return saved;
 }
 if(review?.stamp!==stamp)throw Error('Check bag stock again before saving this production entry.');
 const bagAllocations=allocate(needs,Object.values(review.inventory.lots||{}));
 return {...record,locationId,bagAllocationVersion:1,bagStockFingerprint:review.inventory.fingerprint,bagAllocations};
}
function restore(record=null){baseline=record?JSON.parse(JSON.stringify(record)):null;review=null;generation++;}
window.TT_PRODUCTION_BAGS={autoReview,prepare,restore};
})();
