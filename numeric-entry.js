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
  const nativeFocus=input.focus.bind(input);input.focus=options=>{if(!proxy.disabled)proxy.focus(options);else nativeFocus(options)};
  input.addEventListener('focus',()=>proxy.focus());
  input.addEventListener('input',()=>{if(forwarding)return;const n=value.get.call(input);if(n!==raw(proxy.value)&&Number(n)!==Number(raw(proxy.value)))proxy.value=grouped(n)});
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
 document.addEventListener('focusin',e=>{if(e.target.matches?.('input[type="number"]')){enhance(e.target);records.get(e.target)?.proxy.focus()}});
 document.addEventListener('reset',e=>queueMicrotask(()=>{e.target.querySelectorAll('input[type=number]').forEach(input=>{input.setCustomValidity('');input.value=value.get.call(input)});refresh(e.target)}));
 const observer=new MutationObserver(changes=>{for(const change of changes){if(change.type==='attributes'){if(records.has(change.target))sync(change.target);continue}for(const node of change.addedNodes){if(node.nodeType!==1)continue;if(node.matches('input[type="number"]'))enhance(node);refresh(node)}}});
 const start=()=>{refresh();observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled','readonly','value','placeholder','hidden','style','class']})};
 window.TT_NUMERIC_ENTRY={refresh,grouped};document.readyState==='loading'?document.addEventListener('DOMContentLoaded',start,{once:true}):start();
})();
