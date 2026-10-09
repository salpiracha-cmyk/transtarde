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

/* Position newly opened forms once; background updates and typing keep their position. */
(()=>{'use strict';
 const dialogs='dialog,[role="dialog"],.modalBackdrop,.tt-layer,.tter-overlay';
 const excluded='.printDoc,#printRoot,.contractPreviewPane,.contractPreviewPaper';
 const scrollBoxes='.tt-window,.tter-dialog,.modal,.dialog-body,.tt-window-body,.modalBody,.modal-body';
 const pending=new Map();
 const visible=node=>node instanceof Element&&node.isConnected&&!node.closest('[hidden]')&&getComputedStyle(node).display!=='none'&&node.getClientRects().length>0;
 const firstInput=root=>[...root.querySelectorAll('input:not([type="hidden"]),select,textarea')].find(input=>visible(input)&&!input.disabled&&!input.readOnly&&!input.closest(excluded));
 function place(root){
  if(!visible(root)||root.closest(excluded))return false;
  for(const node of [root,...root.querySelectorAll(scrollBoxes)])node.scrollTop=0;
  let container=root.parentElement;
  while(container&&container!==document.body&&container!==document.documentElement){
   const style=getComputedStyle(container);
   if(/auto|scroll/.test(style.overflowY)&&container.scrollHeight>container.clientHeight)container.scrollTop=0;
   container=container.parentElement;
  }
  if(!root.matches(dialogs)&&!root.closest(dialogs)){
   let inset=12;
   for(const header of document.querySelectorAll('.topbar,header')){
    const style=getComputedStyle(header),rect=header.getBoundingClientRect();
    if(['sticky','fixed'].includes(style.position)&&rect.top<=1&&rect.bottom>0)inset=Math.max(inset,rect.bottom+12);
   }
   window.scrollTo({left:window.scrollX,top:Math.max(0,window.scrollY+root.getBoundingClientRect().top-inset),behavior:'instant'});
  }
  const input=firstInput(root);
  if(!input)return false;
  // Reveal the label with the control. Do not summon the phone keyboard or steal focus.
  const field=input.closest('label,.field')||input;
  field.scrollIntoView({block:'nearest',inline:'nearest',behavior:'instant'});
  return true;
 }
 function cancel(root){const task=pending.get(root);if(!task)return;task.observer.disconnect();clearTimeout(task.timer);cancelAnimationFrame(task.frame);pending.delete(root)}
 function open(root){
  if(!(root instanceof Element)||root.closest(excluded))return;
  cancel(root);
  const task={observer:null,timer:0,frame:0};
  const position=()=>{task.frame=0;if(place(root))cancel(root)};
  const schedule=()=>{if(!task.frame)task.frame=requestAnimationFrame(()=>{task.frame=requestAnimationFrame(position)})};
  task.observer=new MutationObserver(schedule);
  pending.set(root,task);
  task.observer.observe(root,{subtree:true,childList:true,attributes:true,attributeFilter:['hidden','class','style','disabled']});
  task.timer=setTimeout(()=>cancel(root),10000);
  schedule();
 }
 window.TT_FORM_VIEWPORT={open};
 const start=()=>{
  // A slow form load must not later move the page after the user starts working.
  const stop=()=>{for(const root of [...pending.keys()])cancel(root)};
  document.addEventListener('input',stop,true);document.addEventListener('wheel',stop,{capture:true,passive:true});
  document.addEventListener('touchmove',stop,{capture:true,passive:true});document.addEventListener('keydown',stop,true);
  document.addEventListener('tt:accounts-desk-form-opened',()=>{const root=[...document.querySelectorAll('.tt-layer,.workspace.active')].filter(visible).pop();if(root)open(root)});
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

// Shared numeric entry: display grouping without changing numeric storage.
/* Display grouping belongs to the entry control; the original numeric input
 * retains its ID, name, validation, handlers and unformatted numeric value. */
(()=>{'use strict';
 const value=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value');
 const records=new WeakMap();
 const raw=s=>String(s??'').replace(/[,\s]/g,'');
 const grouped=s=>{s=raw(s);if(!/^-?\d*(?:\.\d*)?$/.test(s))return s;const [whole,fraction]=s.split('.');return whole.replace(/\B(?=(\d{3})+(?!\d))/g,',')+(fraction===undefined?'':'.'+fraction)};
 function sync(input){const r=records.get(input);if(!r)return;const {proxy}=r;
  if(document.activeElement!==proxy)proxy.value=grouped(value.get.call(input));
  proxy.disabled=input.disabled;proxy.readOnly=input.readOnly;proxy.placeholder=input.placeholder;
  proxy.parentElement.hidden=input.hidden||input.style.display==='none'||getComputedStyle(input).display==='none';
  proxy.setAttribute('aria-invalid',input.validity.valid?'false':'true');
  proxy.setAttribute('aria-label',input.getAttribute('aria-label')||input.closest('label')?.textContent.trim()||input.id||'Number');
 }
 function enhance(input){if(records.has(input)||input.closest('.printDoc,#printRoot')||input.dataset.ttNumericProxy)return;
  const wrapper=document.createElement('span'),proxy=document.createElement('input');wrapper.className='tt-number-entry';
  proxy.type='text';proxy.inputMode='decimal';proxy.dataset.ttNumericProxy='1';proxy.className='tt-number-display';proxy.style.cssText=input.style.cssText;proxy.autocomplete='off';
  input.before(wrapper);wrapper.append(input,proxy);input.classList.add('tt-number-source');input.tabIndex=-1;records.set(input,{proxy});
  Object.defineProperty(input,'value',{configurable:true,get(){return value.get.call(this)},set(v){value.set.call(this,v);proxy.value=grouped(value.get.call(this))}});
  let forwarding=false;
  input.addEventListener('input',()=>{if(!forwarding)proxy.value=grouped(value.get.call(input))});
  const label=input.closest('label');if(label)label.addEventListener('click',event=>{if(!event.target.closest('input,button,select,a')){event.preventDefault();proxy.focus()}});
  proxy.addEventListener('input',()=>{
   const current=proxy.value,position=proxy.selectionStart??current.length,offset=raw(current.slice(0,position)).length,n=raw(current);
   const valid=/^-?(?:\d+(?:\.\d*)?|\.\d+)$/.test(n);
   value.set.call(input,valid?(n.endsWith('.')?n.slice(0,-1):n):'');input.setCustomValidity(n&&!valid?'Enter a valid number.':'');
   if(/^[-\d.]*$/.test(n)){proxy.value=grouped(n);let caret=0,count=0;while(caret<proxy.value.length&&count<offset){if(proxy.value[caret]!==',')count++;caret++}proxy.setSelectionRange(caret,caret)}
   forwarding=true;try{input.dispatchEvent(new Event('input',{bubbles:true}))}finally{forwarding=false}
  });
  proxy.addEventListener('change',()=>input.dispatchEvent(new Event('change',{bubbles:true})));
  proxy.addEventListener('blur',()=>{if(!input.validationMessage)proxy.value=grouped(value.get.call(input));input.dispatchEvent(new Event('blur'))});
  input.addEventListener('invalid',()=>{proxy.setAttribute('aria-invalid','true');proxy.focus()});
  for(const event of ['keydown','keyup'])proxy.addEventListener(event,e=>{const forwarded=new KeyboardEvent(event,{key:e.key,code:e.code,bubbles:true,cancelable:true,ctrlKey:e.ctrlKey,shiftKey:e.shiftKey,altKey:e.altKey,metaKey:e.metaKey});if(!input.dispatchEvent(forwarded))e.preventDefault()});
  sync(input);
 }
 function refresh(root=document){root.querySelectorAll('input[type="number"]').forEach(input=>{enhance(input);sync(input)})}
 document.addEventListener('focusin',e=>{if(e.target.matches?.('input[type="number"]')){enhance(e.target)}});
 document.addEventListener('reset',e=>queueMicrotask(()=>{e.target.querySelectorAll('input[type=number]').forEach(input=>{input.setCustomValidity('');input.value=value.get.call(input)});refresh(e.target)}));
 const observer=new MutationObserver(changes=>{for(const change of changes){if(change.type==='attributes'){if(records.has(change.target))sync(change.target);continue}for(const node of change.addedNodes){if(node.nodeType!==1)continue;if(node.matches('input[type="number"]'))enhance(node);refresh(node)}}});
 const start=()=>{refresh();observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled','readonly','value','placeholder','hidden','style','class']})};
 window.TT_NUMERIC_ENTRY={refresh,grouped};document.readyState==='loading'?document.addEventListener('DOMContentLoaded',start,{once:true}):start();
})();

/* Owner date rule: visible dates DD-MM-YYYY; persisted values remain ISO. */
(()=>{'use strict';
 const months={jan:'01',feb:'02',mar:'03',apr:'04',may:'05',jun:'06',jul:'07',aug:'08',sep:'09',oct:'10',nov:'11',dec:'12'};
 const display=v=>String(v??'').replace(/\b(\d{4})-(\d{2})-(\d{2})(?!\d)/g,'$3-$2-$1').replace(/\b(\d{2})\/(\d{2})\/(\d{4})\b/g,'$1-$2-$3').replace(/\b(\d{1,2}) (Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?) (\d{4})\b/gi,(_,d,m,y)=>d.padStart(2,'0')+'-'+months[m.slice(0,3).toLowerCase()]+'-'+y);
 const iso=v=>{const m=/^(\d{2})-(\d{2})-(\d{4})$/.exec(v.trim());if(!m)return '';const s=m[3]+'-'+m[2]+'-'+m[1],d=new Date(s+'T00:00:00Z');return Number.isNaN(d.getTime())||d.toISOString().slice(0,10)!==s?'':s;};
 window.TT_DATE={display,iso};
 const linked=new WeakMap(),value=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value');
 function dateField(input){if(linked.has(input)||input.closest('.tt-date-control'))return;
  const wrap=document.createElement('span');wrap.className='tt-date-control';wrap.style.cssText='display:block;position:relative;min-width:0;width:100%';input.before(wrap);wrap.appendChild(input);
  const text=document.createElement('input');text.type='text';text.inputMode='numeric';text.placeholder='DD-MM-YYYY';text.autocomplete='off';text.dataset.masterIgnore='';text.setAttribute('aria-label',(input.getAttribute('aria-label')||input.closest('label')?.textContent.trim()||'Date')+' DD-MM-YYYY');text.style.cssText='width:100%;box-sizing:border-box;padding-right:36px';wrap.prepend(text);
  input.style.cssText+=';position:absolute;right:0;top:0;width:30px!important;min-width:0!important;height:100%;opacity:0;cursor:pointer;';input.tabIndex=-1;
  const icon=document.createElement('span');icon.textContent='▦';icon.setAttribute('aria-hidden','true');icon.style.cssText='position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;color:#526475';wrap.appendChild(icon);
  const mask='__-__-____',slots=[0,1,3,4,6,7,8,9];
  const masked=raw=>{if(/^\d{4}-\d{2}-\d{2}$/.test(raw))raw=display(raw);if(/^[\d_]{2}-[\d_]{2}-[\d_]{4}$/.test(raw))return raw;const digits=raw.replace(/\D/g,'').slice(0,8),out=mask.split('');slots.forEach((at,i)=>out[at]=digits[i]||'_');return out.join('');};
  const validity=()=>{const s=iso(text.value),hasDigits=/\d/.test(text.value);text.setCustomValidity(hasDigits&&!s?'Enter a valid date as DD-MM-YYYY.':!s&&input.required?'Enter a date as DD-MM-YYYY.':s&&input.min&&s<input.min?'Date must be on or after '+display(input.min):s&&input.max&&s>input.max?'Date must be on or before '+display(input.max):'');};
  const sync=(preserve=false)=>{if(!preserve)text.value=display(value.get.call(input))||mask;text.required=false;text.disabled=input.disabled;text.readOnly=input.readOnly;validity();};linked.set(input,{text,sync});
  Object.defineProperty(input,'value',{configurable:true,get(){return value.get.call(this)},set(v){value.set.call(this,v);sync();}});
  const commit=()=>{value.set.call(input,iso(text.value));validity();input.dispatchEvent(new Event('input',{bubbles:true}));};
  text.addEventListener('beforeinput',event=>{if(text.readOnly||text.disabled||event.isComposing)return;const type=event.inputType;if(!type.startsWith('insert')&&!type.startsWith('delete'))return;event.preventDefault();let start=text.selectionStart||0,end=text.selectionEnd||start,out=masked(text.value).split('');
   const inserted=event.data||event.dataTransfer?.getData('text/plain')||'';
   if(start===0&&end===10&&type.startsWith('insert')&&/^\d{4}-\d{2}-\d{2}$/.test(inserted)){text.value=masked(inserted);text.setSelectionRange(10,10);commit();return;}
   if(end>start)slots.filter(at=>at>=start&&at<end).forEach(at=>out[at]='_');
   if(type.startsWith('delete')){if(start===end){const at=type==='deleteContentBackward'?[...slots].reverse().find(at=>at<start):slots.find(at=>at>=start);if(at!==undefined){out[at]='_';start=at;}}}
   else{const digits=(event.data||event.dataTransfer?.getData('text/plain')||'').replace(/\D/g,'');for(const digit of digits){const at=slots.find(at=>at>=start);if(at===undefined)break;out[at]=digit;start=at+1;}if(start===2||start===5)start++;}
   text.value=out.join('');text.setSelectionRange(start,start);commit();
  });
  text.addEventListener('paste',event=>{if(text.readOnly||text.disabled)return;event.preventDefault();const raw=event.clipboardData?.getData('text')||'';text.value=masked(raw);text.setSelectionRange(10,10);commit();});
  text.addEventListener('input',()=>{text.value=masked(text.value);commit();});
  let changingText=false;text.addEventListener('change',()=>{changingText=true;try{input.dispatchEvent(new Event('change',{bubbles:true}));}finally{changingText=false;}});input.addEventListener('change',()=>{if(!changingText)sync();});input.addEventListener('invalid',()=>text.focus());sync();
 }
 function scan(root){if(root.nodeType===1){if(root.matches('input[type=date]'))dateField(root);root.querySelectorAll('input[type=date]').forEach(dateField);}const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let n;while(n=walker.nextNode()){if(n.parentElement?.closest('script,style,textarea,input,option,[contenteditable]'))continue;const s=display(n.data);if(s!==n.data)n.data=s;}}
 function start(){scan(document.body);new MutationObserver(records=>{for(const r of records){if(r.type==='attributes'){linked.get(r.target)?.sync(true);continue;}if(r.type==='characterData'){const n=r.target;if(!n.parentElement?.closest('script,style,textarea,input,option,[contenteditable]')){const s=display(n.data);if(s!==n.data)n.data=s;}}else for(const n of r.addedNodes)if(n.nodeType===1)scan(n);else if(n.nodeType===3){const s=display(n.data);if(s!==n.data)n.data=s;}}}).observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['disabled','readonly','required','min','max']});document.addEventListener('reset',e=>queueMicrotask(()=>e.target.querySelectorAll('input[type=date]').forEach(x=>linked.get(x)?.sync())));}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
