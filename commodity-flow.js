/* Commodity identity and physical weights: no persistence or background work. */
(function(root){'use strict';
 const corn=value=>/\b(?:CORN|MAIZE|MAKAI)\b/i.test(String(value||''));
 const isCorn=row=>corn([row?.commodity,row?.product,row?.commercialProduct,row?.baseVariety,row?.variety,row?.displayName].filter(Boolean).join(' '));
 const isLooseBulk=row=>isCorn(row)&&(row?.looseBulk===true||/\b(?:loose|bulk)\b/i.test(String(row?.packingType||row?.packing||'')));
 function weights(input){
  const read=(key)=>{const n=Number(input[key]??0);if(!Number.isFinite(n)||n<0)throw new Error('Enter valid non-negative '+key+'.');return n};
  const weighbridgeKg=read('weighbridgeKg'),silicaKg=read('silicaKg'),kraftKg=read('kraftKg'),bagTareKg=read('bagTareKg'),containerTareKg=read('containerTareKg');
  if(!['COMMODITY_NET','PACKED_CARGO','VEHICLE_GROSS'].includes(input.basis))throw new Error('Select the weighbridge weight basis.');
  // Vehicle/container tare is subtracted only when that basis explicitly includes it.
  if(input.basis!=='VEHICLE_GROSS'&&containerTareKg>0)throw new Error('Vehicle tare is only allowed when included in the weighbridge reading.');
  const cargoGrossKg=weighbridgeKg-(input.basis==='VEHICLE_GROSS'?containerTareKg:0),packingKg=silicaKg+kraftKg+bagTareKg;
  if(input.basis==='COMMODITY_NET'&&packingKg>0)throw new Error('Net commodity weight must not include another packing deduction.');
  const netKg=cargoGrossKg-(input.basis==='COMMODITY_NET'?0:packingKg);
  if(netKg<=0)throw new Error('Net commodity weight must be greater than zero after deductions.');
  return {weighbridgeKg,weightBasis:input.basis,silicaKg,kraftKg,bagTareKg,containerTareKg,cargoGrossKg,packingKg,netKg};
 }
 const api={isCorn,isLooseBulk,weights};root.TT_COMMODITY_FLOW=api;if(typeof module==='object'&&module.exports)module.exports=api;
})(typeof globalThis!=='undefined'?globalThis:window);
