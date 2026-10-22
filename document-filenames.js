/* User-facing download names only; no storage keys, document content or writes. */
(()=>{'use strict';
 if(window.TT_DOCUMENT_NAME)return;
 const bytes=s=>new TextEncoder().encode(s).length;
 function clean(value,limit=72){if(value&&typeof value==='object')value=value.name||value.label||'';let s=String(value??'').normalize('NFC').replace(/[\x00-\x1f\x7f-\x9f\u202a-\u202e\u2066-\u2069]/g,'').replace(/[<>:"/\\|?*]+/g,'-').replace(/\s+/g,' ').replace(/^[ .-]+|[ .-]+$/g,'');let out='';for(const ch of s){if(bytes(out+ch)>limit)break;out+=ch;}return out.replace(/[ .-]+$/g,'');}
 function name(o={}){const party=clean(o.party||o.company||'Transtrade',64)||clean(o.company||'Transtrade',64)||'Transtrade',title=clean(o.title||'Document',56)||'Document',reference=clean(o.reference,64),date=clean(o.from||o.to?(o.from||'Beginning')+' to '+(o.to||'Latest'):o.date,35),company=o.party&&o.company&&clean(o.party)!==clean(o.company)?clean(o.company,16):'',ccy=clean(o.currency,6);let parts=[party,title,reference,date,company,ccy].filter(Boolean);let stem=parts.join(' - ');if(bytes(stem)>220){parts[0]=clean(parts[0],Math.max(8,220-bytes(parts.slice(1).join(' - '))-3));stem=parts.join(' - ');}if(/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(stem))stem='Document - '+stem;const ext=String(o.extension||'').replace(/^\./,'').toLowerCase();return stem+(/^[a-z0-9]{1,10}$/.test(ext)?'.'+ext:'');}
 function infer(doc,extra={}){
  const scope=doc.querySelector('#printRoot:not(:empty)')||doc.body||doc.documentElement;
  const meta=scope.querySelector('[data-download-title]')||scope;
  const values={};
  // Only labelled document fields, never an arbitrary first row in a report.
  for(const el of scope.querySelectorAll('dt,b,strong,th')){
   const label=el.textContent.trim().replace(/[:\s]+$/,'').toLowerCase();
   const key=/^(party|buyer|buyer name|buyer \/ importer|final buyer \/ importer|customer|supplier|broker|contractor|labour contractor|mill|bank|account name|holder)$/.test(label)?'party':/^(invoice no\.?|invoice number|invoice ref\.?|contract reference|soda|soda no\.?|bill no\.?|bill number|post id|reference)$/.test(label)?'reference':'';
   if(!key)continue;let text='';
   if(el.tagName==='DT')text=el.nextElementSibling?.textContent||'';
   else if(el.tagName==='TH'){const row=el.parentElement;if(row?.children.length===2&&el.nextElementSibling?.tagName==='TD')text=el.nextElementSibling.textContent;}
   else {for(let n=el.nextSibling;n;n=n.nextSibling){if(n.nodeType===1&&/^(B|STRONG|BR|TABLE)$/.test(n.nodeName))break;text+=n.textContent||'';}if(!text&&el.parentElement?.children.length===1)text=el.parentElement.textContent.slice(el.textContent.length);}
   text=text.trim();if(text&&text.length<180)(values[key]??=new Set()).add(text);
  }
  const single=key=>values[key]?.size===1?[...values[key]][0]:'';
  return {party:single('party'),title:doc.title||scope.querySelector('h1,h2,h3')?.textContent||'Document',reference:single('reference'),...Object.fromEntries(Object.entries(meta.dataset).filter(([k])=>k.startsWith('download')).map(([k,v])=>[k.slice(8,9).toLowerCase()+k.slice(9),v])),...extra};
 }
 const active=new WeakMap();
 function prepare(doc,extra={}){if(active.has(doc))return active.get(doc);const old=doc.title;const filename=doc.querySelector('title[data-tt-filename]')?clean(doc.title,220):name(infer(doc,extra));doc.title=filename;let parentDoc;let parentTitle;try{if(doc!==document){parentDoc=document;parentTitle=document.title;parentDoc.title=filename;}}catch{}let finished=false;const restore=()=>{if(finished)return;finished=true;if(doc.title===filename)doc.title=old;if(parentDoc?.title===filename)parentDoc.title=parentTitle;active.delete(doc);};active.set(doc,restore);doc.defaultView?.addEventListener('afterprint',restore,{once:true});setTimeout(restore,120000);return restore;}
 function install(win){try{if(win.__ttFilenamePrint)return;win.__ttFilenamePrint=true;const print=win.print;win.print=function(){prepare(win.document);return print.apply(win,arguments)};const open=win.open;win.open=function(url,...rest){const child=open.call(win,url,...rest);if(child&&(!url||url==='about:blank'))install(child);return child;};}catch{}}
 window.TT_DOCUMENT_NAME={name,clean,infer,prepare};install(window);
 const frames=new WeakSet();function frame(el){if(frames.has(el))return;frames.add(el);const attach=()=>{try{if(!el.src||el.src==='about:blank'||el.hasAttribute('srcdoc'))install(el.contentWindow);}catch{}};attach();el.addEventListener('load',attach);}
 new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1){if(node.tagName==='IFRAME')frame(node);node.querySelectorAll?.('iframe').forEach(frame);}}).observe(document.documentElement,{childList:true,subtree:true});
})();
