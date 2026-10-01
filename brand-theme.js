(()=>{'use strict';
 // Hide explanatory green commentary; retain operational messages, warnings and controls.
 const generic=/^(?:One covering letter only|Commercial Invoice and Packing List come|Same office folder|Mill actuals, contractual|Custom Invoice is the controlling|Customs Invoice is the controlling|The reviewed L\/C master controls|Exactly two|Use this (?:page|screen|section)|This (?:screen|section|module) (?:shows|allows|provides)|All (?:financial|company|shipment) (?:details|values|data) flow)/i;
 const clean=root=>{for(const node of root.querySelectorAll('.notice:not(.warn):not(.red):not(.error):not([role="status"])')){
  if(node.closest('.printDoc,#printRoot')||node.querySelector('button,input,select,textarea,a'))continue;
  const text=node.textContent.trim();const specific=/\d|saved|failed|error|missing|required|pending|blocked|complete.*first|select.*first|no (?:records|documents|shipments|stock)|access|permission/i.test(text);
  if(generic.test(text)||(!specific&&text.length>100))node.classList.add('ttGenericCommentary');
 }};
 const start=()=>{clean(document);new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1){if(node.matches('.notice'))clean(node.parentElement||document);else clean(node)}}).observe(document.body,{childList:true,subtree:true})};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
