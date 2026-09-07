(()=>{'use strict';
const fail=e=>{console.error('Transtrade Export bundle failed to load',e);const a=document.getElementById('app');if(a)a.innerHTML='<div style="margin:24px;padding:18px;border:1px solid #c33;border-radius:10px;font-family:Arial"><b>Export module could not load.</b><br>Please refresh. If this continues, contact the administrator.</div>'};
(async()=>{try{
  const names=['deploy.part01.b64','deploy.part02.b64','deploy.part03.b64','deploy.part04.b64','deploy.part05.b64','deploy.part06.b64','deploy.part07.b64','deploy.part08.b64','deploy.part09.b64','deploy.part10.b64','deploy.part11.b64','deploy.part12.b64','deploy.part13.b64','deploy.part14.b64','deploy.part15.b64','deploy.part16.b64','deploy.part17.b64','deploy.part18.b64','deploy.part19.b64','deploy.part20.b64','deploy.part21.b64'];
  const rs=await Promise.all(names.map(n=>fetch('exports/'+n,{cache:'no-store'})));for(const r of rs)if(!r.ok)throw new Error('Bundle HTTP '+r.status);
  const b64=(await Promise.all(rs.map(r=>r.text()))).join('').replace(/\s+/g,''),bin=atob(b64),bytes=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)bytes[i]=bin.charCodeAt(i);
  if(typeof DecompressionStream!=='function')throw new Error('This browser is too old for the Export module. Please update Chrome, Edge or Safari.');
  const stream=new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));const src=await new Response(stream).text();(0,eval)(src+'\n//# sourceURL=transtrade-exports-clean-v1.js');
}catch(e){fail(e)}})();
})();
