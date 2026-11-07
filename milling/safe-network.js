/* A stalled connection must release Save controls and retain the same retry identity. */
(()=>{'use strict';
 if(window.TT_MODULE_ACCESS?.moduleId!=='milling'||!window.fetch||!window.AbortController)return;
 const original=window.fetch.bind(window);
 window.fetch=async function(input,options={}){
  let url;try{url=new URL(typeof input==='string'?input:input.url,location.href);}catch{return original(input,options);}
  if(url.origin!==location.origin||!url.pathname.startsWith('/api/'))return original(input,options);
  const controller=new AbortController(),signal=options.signal||input?.signal;let expired=false,closed=false;
  const cancel=()=>controller.abort(signal?.reason),cleanup=()=>{if(closed)return;closed=true;clearTimeout(timer);signal?.removeEventListener('abort',cancel);},timer=setTimeout(()=>{expired=true;controller.abort();},45000);
  if(signal?.aborted)cancel();else signal?.addEventListener('abort',cancel,{once:true});
  const failure=error=>expired?Error('The connection timed out before confirmation. Your entry is retained. Retry the same save to check the server.'):error;
  try{const response=await original(input,{...options,signal:controller.signal});
   for(const method of ['json','text','blob','arrayBuffer','formData'])if(typeof response[method]==='function'){const consume=response[method].bind(response);response[method]=async(...args)=>{try{return await consume(...args);}catch(error){throw failure(error);}finally{cleanup();}};}
   // Callers consume API JSON immediately; retain the timeout through the body read.
   return response;
  }catch(error){cleanup();throw failure(error);}
 };
})();
