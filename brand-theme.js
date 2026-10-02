(()=>{'use strict';
 // Hide explanatory green commentary; retain operational messages, warnings and controls.
 const generic=/^(?:One covering letter only|Commercial Invoice and Packing List come|Same office folder|Mill actuals, contractual|Custom Invoice is the controlling|Customs Invoice is the controlling|The reviewed L\/C master controls|Exactly two|Use this (?:page|screen|section)|This (?:screen|section|module) (?:shows|allows|provides)|All (?:financial|company|shipment) (?:details|values|data) flow)/i;
 const grids='.grid2,.grid3,.grid4,.row,.r2,.r3,.r5,.grid,.ttv-grid,.ttrs-grid,.tt-batch-grid,.ttsb-grid,.tter-grid,.ttlsc-grid,.ttsl-filters,.tt-bill-adjustment';
 const titleMinimum=new WeakMap();let frame=0;
 const equalize=()=>{for(const grid of document.querySelectorAll(grids)){
  if(grid.closest('.printDoc,#printRoot')||getComputedStyle(grid).display!=='grid')continue;
  const fields=[...grid.children].map(field=>({field,title:field.matches('label.ttRowField')?field.querySelector(':scope>.ttRowLabel'):field.querySelector(':scope>label:first-child')})).filter(row=>row.title&&row.field.querySelector('input,select,textarea')&&!row.field.querySelector('input[type="checkbox"],input[type="radio"]'));
  for(const row of fields){if(!titleMinimum.has(row.title))titleMinimum.set(row.title,row.title.style.minHeight);row.title.style.minHeight=titleMinimum.get(row.title)}
  const rows=new Map();for(const row of fields){const rect=row.field.getBoundingClientRect();if(!rect.width||!rect.height)continue;const key=Math.round(rect.top),group=rows.get(key)||[];group.push(row);rows.set(key,group)}
  for(const group of rows.values()){const height=Math.max(...group.map(row=>row.title.getBoundingClientRect().height));for(const row of group)row.title.style.minHeight=height+'px'}
 }};
 const schedule=()=>{if(frame)return;frame=(window.requestAnimationFrame||((fn)=>setTimeout(fn,0)))(()=>{frame=0;equalize()})};
 const align=root=>{for(const label of root.querySelectorAll(':is(.grid2,.grid3,.grid4,.ttv-grid,.ttrs-grid,.tt-batch-grid,.ttsb-grid,.tter-grid,.ttlsc-grid,.ttsl-filters,.tt-bill-adjustment)>label')){
  if(label.querySelector('.ttRowLabel')||!label.querySelector('input,select,textarea')||label.querySelector('input[type="checkbox"],input[type="radio"]'))continue;
  const nodes=[...label.childNodes].filter(node=>node.nodeType===3),text=nodes.map(node=>node.textContent).join(' ').trim();if(!text)continue;
  const title=document.createElement('span');title.className='ttRowLabel';title.textContent=text;nodes.forEach(node=>node.remove());label.prepend(title);label.classList.add('ttRowField');
 }};
 const clean=root=>{align(root);schedule();for(const node of root.querySelectorAll('.notice:not(.warn):not(.red):not(.error):not([role="status"])')){
  if(node.closest('.printDoc,#printRoot')||node.querySelector('button,input,select,textarea,a'))continue;
  const text=node.textContent.trim();const specific=/\d|saved|failed|error|missing|required|pending|blocked|complete.*first|select.*first|no (?:records|documents|shipments|stock)|access|permission/i.test(text);
  if(generic.test(text)||(!specific&&text.length>100))node.classList.add('ttGenericCommentary');
 }};
 const start=()=>{clean(document);window.addEventListener('resize',schedule);document.addEventListener('click',schedule,true);document.fonts?.ready.then(schedule);new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1){if(node.matches('.notice'))clean(node.parentElement||document);else clean(node.parentElement||node)}}).observe(document.body,{childList:true,subtree:true})};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();

/* Reset only a newly opened form or workspace; edits and background refreshes keep their position. */
(()=>{'use strict';
 const dialogs='dialog,[role="dialog"],.modalBackdrop,.tt-layer';
 const excluded='.printDoc,#printRoot,.contractPreviewPane,.contractPreviewPaper';
 const visible=node=>node instanceof Element&&node.isConnected&&!node.closest('[hidden]')&&getComputedStyle(node).display!=='none'&&node.getClientRects().length>0;
 function place(root){
  if(!visible(root)||root.closest(excluded))return;
  for(const node of [root,...root.querySelectorAll('.dialog-body,.tt-window-body,.modalBody,.modal-body')])node.scrollTop=0;
  let container=root.parentElement;
  while(container&&container!==document.body&&container!==document.documentElement){
   const style=getComputedStyle(container);
   if(/auto|scroll/.test(style.overflowY)&&container.scrollHeight>container.clientHeight)container.scrollTop=0;
   container=container.parentElement;
  }
  if(root.matches(dialogs)||root.closest(dialogs))return;
  let inset=12;
  for(const header of document.querySelectorAll('.topbar,header')){
   const style=getComputedStyle(header),rect=header.getBoundingClientRect();
   if(['sticky','fixed'].includes(style.position)&&rect.top<=1&&rect.bottom>0)inset=Math.max(inset,rect.bottom+12);
  }
  window.scrollTo({left:window.scrollX,top:Math.max(0,window.scrollY+root.getBoundingClientRect().top-inset),behavior:'instant'});
 }
 function open(root){
  if(!root)return;
  // Layout and native focus scrolling settle before positioning the form title.
  requestAnimationFrame(()=>requestAnimationFrame(()=>place(root)));
 }
 window.TT_FORM_VIEWPORT={open};
 const start=()=>{
  new MutationObserver(records=>{
   const opened=new Set();
   for(const record of records){
    if(record.type==='childList')for(const node of record.addedNodes){
     if(node.nodeType!==1)continue;
     if(node.matches(dialogs))opened.add(node);
     node.querySelectorAll(dialogs).forEach(dialog=>opened.add(dialog));
    }
    if(record.type==='attributes'){
     const node=record.target;
     if(node.matches(dialogs)&&(record.attributeName==='open'&&node.hasAttribute('open')||record.attributeName==='hidden'&&!node.hidden&&record.oldValue!==null))opened.add(node);
     if(node.matches('.panel.active,.workspace.active')&&record.attributeName==='class'&&!String(record.oldValue||'').split(/\s+/).includes('active'))opened.add(node);
    }
   }
   for(const root of opened)open(root);
  }).observe(document.body,{subtree:true,childList:true,attributes:true,attributeOldValue:true,attributeFilter:['class','open','hidden']});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
