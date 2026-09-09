(()=>{'use strict';
let unloading=false;
addEventListener('pagehide',()=>{unloading=true},{once:true});
const fail=e=>{if(unloading)return;console.error('Transtrade Export bundle failed to load',e);const a=document.getElementById('app');if(a)a.innerHTML='<div style="margin:24px;padding:18px;border:1px solid #c33;border-radius:10px;font-family:Arial"><b>Export module could not load.</b><br>Please refresh. If this continues, contact the administrator.</div>'};
(async()=>{try{
 const scriptUrl=document.currentScript?.src||new URL('exports/app.js',location.href).href;
 const base=new URL('.',scriptUrl);
 const asset=name=>new URL('assets/'+name,base).href;
 const numbered=Array.from({length:16},(_,i)=>`app.part${String(i+1).padStart(2,'0')}.txt`);
 const appNames=[...numbered,'app.part18.txt','app.part17.txt','app.part19.txt'];
 window.__TT_ASSETS={
  TTI_header:asset('TTI_header.png'),TTI_sign:asset('TTI_sign.png'),
  BRM_header:asset('BRM_header.png'),BRM_sign:asset('BRM_sign.png'),
  TG_header:asset('TG_header.png'),TG_footer:asset('TG_footer.png'),TG_sign:asset('TG_sign.png')
 };
 const rs=await Promise.all(appNames.map(n=>fetch(new URL(n,base),{cache:'no-store'})));
 for(const r of rs)if(!r.ok)throw new Error(`Bundle HTTP ${r.status} for ${r.url}`);
 const texts=await Promise.all(rs.map(r=>r.text()));
 const src=texts.join('');
 (0,eval)(src+'\n//# sourceURL=transtrade-exports-clean-v1.js');
}catch(e){fail(e)}})();
})();
