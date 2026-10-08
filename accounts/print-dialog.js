(()=>{'use strict';
 let active=null;
 const dates=s=>window.TT_DATE?.display(s)??String(s??'').replace(/\b(\d{4})-(\d{2})-(\d{2})(?!\d)/g,'$3-$2-$1');
 function create(){
  if(active)active.remove();const frame=document.createElement('iframe');frame.title='Print document';frame.setAttribute('aria-hidden','true');frame.style.cssText='position:fixed;left:-10000px;top:0;width:800px;height:1000px;border:0';document.body.appendChild(frame);active=frame;
  const w=frame.contentWindow;w.addEventListener('afterprint',()=>{frame.remove();if(active===frame)active=null;});w.__nativePrint=w.print.bind(w);let requested=false;
  w.print=()=>{if(requested)return;requested=true;const walker=w.document.createTreeWalker(w.document.body,4);let node;while(node=walker.nextNode()){if(!node.parentElement?.closest('script,style,textarea'))node.data=dates(node.data);}setTimeout(()=>{if(!frame.isConnected)return;w.focus();frame.contentWindow.__nativePrint();},50);};
  // Save native print before overriding it.
  return w;
 }
 function html(content){const w=create();w.document.open();w.document.write(content);w.document.close();const doc=w.document;doc.querySelectorAll('.controls,button').forEach(el=>el.remove());Promise.resolve(doc.fonts?.ready).then(()=>w.print());return w;}
 window.TT_PRINT={create,html,dates};
})();
