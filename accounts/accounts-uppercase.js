(() => {
 'use strict';
 const style=document.createElement('style');style.textContent='body{text-transform:uppercase}input,textarea,select,button{text-transform:uppercase}input[type=email],input[type=url],input[type=password],[data-preserve-case]{text-transform:none}';document.head.appendChild(style);
 // Master selections retain their canonical value; CSS displays their labels in capitals.
 const editable=el=>el instanceof HTMLTextAreaElement||el instanceof HTMLInputElement&&['text','search','tel'].includes(el.type);
 function capitalise(el){if(!editable(el)||el.hasAttribute('data-preserve-case')||(el.hasAttribute('list')||el.closest?.('.tt-search-select'))&&!el.closest?.('#ttPartyInlineEditor')||el.readOnly||el.disabled)return;const value=el.value.toUpperCase();if(value===el.value)return;const a=el.selectionStart,b=el.selectionEnd;el.value=value;try{el.setSelectionRange(a,b)}catch{}}
 for(const type of ['input','change','blur'])document.addEventListener(type,e=>{if(!e.isComposing)capitalise(e.target)},true);
 document.addEventListener('compositionend',e=>capitalise(e.target),true);
 document.addEventListener('submit',e=>e.target.querySelectorAll('input,textarea').forEach(capitalise),true);
 // Preserve machine identifiers, enum values, CSRF, passwords, email addresses and URLs.
 const textKeys=new Set(['name','reference','referenceNo','bankReference','bankAdviceRef','onlineReference','transactionRef','chequeNo','narration','paymentNarration','description','remarks','reason','note','notes','exceptionNote','reviewNote','terms','invoiceNo','billNo','actualBlNo','forwarderRef','jobNo','customerRef','gdNo','phytoNo']);
 const normalise=value=>Array.isArray(value)?value.map(normalise):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).map(([key,v])=>[key,textKeys.has(key)&&typeof v==='string'?v.toUpperCase():normalise(v)])):value;
 const originalFetch=window.fetch;
 window.fetch=function(input,options){if(options?.body&&typeof options.body==='string'&&String(options.method||'').toUpperCase()==='POST'){try{const url=new URL(typeof input==='string'?input:input.url,location.href);if(url.origin===location.origin&&url.pathname.includes('/api/'))options={...options,body:JSON.stringify(normalise(JSON.parse(options.body)))};}catch{}}return originalFetch.call(this,input,options)};
})();
