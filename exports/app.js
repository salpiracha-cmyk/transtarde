(()=>{'use strict';
const fail=e=>{console.error('Transtrade Export bundle failed to load',e);const a=document.getElementById('app');if(a)a.innerHTML='<div style="margin:24px;padding:18px;border:1px solid #c33;border-radius:10px;font-family:Arial"><b>Export module could not load.</b><br>Please refresh. If this continues, contact the administrator.</div>'};
(async()=>{try{
 const appNames=Array.from({length:17},(_,i)=>`app.part${String(i+1).padStart(2,'0')}.txt`);
 window.__TT_ASSETS={
  TTI_header:'exports/assets/TTI_header.png',TTI_sign:'exports/assets/TTI_sign.png',
  BRM_header:'exports/assets/BRM_header.png',BRM_sign:'exports/assets/BRM_sign.png',
  TG_header:'exports/assets/TG_header.png',TG_footer:'exports/assets/TG_footer.png',TG_sign:'exports/assets/TG_sign.png'
 };
 const rs=await Promise.all(appNames.map(n=>fetch('exports/'+n,{cache:'no-store'})));
 for(const r of rs)if(!r.ok)throw new Error(`Bundle HTTP ${r.status} for ${r.url}`);
 const texts=await Promise.all(rs.map(r=>r.text()));
 const src=texts.join('');
 (0,eval)(src+'\n//# sourceURL=transtrade-exports-clean-v1.js');
}catch(e){fail(e)}})();
})();
