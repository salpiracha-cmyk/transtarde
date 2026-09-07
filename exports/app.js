(()=>{'use strict';
const fail=e=>{console.error('Transtrade Export bundle failed to load',e);const a=document.getElementById('app');if(a)a.innerHTML='<div style="margin:24px;padding:18px;border:1px solid #c33;border-radius:10px;font-family:Arial"><b>Export module could not load.</b><br>Please refresh. If this continues, contact the administrator.</div>'};
(async()=>{try{
 const appNames=['app.part01.txt','app.part02.txt','app.part03.txt','app.part04.txt','app.part05.txt','app.part06.txt','app.part07.txt','app.part08.txt','app.part09.txt','app.part10.txt','app.part11.txt','app.part12.txt','app.part13.txt','app.part14.txt','app.part15.txt','app.part16.txt','app.part17.txt'];
 const assetParts={
  'BRM_header':['asset.BRM_header.part01.txt','asset.BRM_header.part02.txt'],
  'BRM_sign':['asset.BRM_sign.part01.txt','asset.BRM_sign.part02.txt'],
  'TG_footer':['asset.TG_footer.part01.txt'],
  'TG_header':['asset.TG_header.part01.txt','asset.TG_header.part02.txt'],
  'TG_sign':['asset.TG_sign.part01.txt'],
  'TTI_header':['asset.TTI_header.part01.txt','asset.TTI_header.part02.txt','asset.TTI_header.part03.txt'],
  'TTI_sign':['asset.TTI_sign.part01.txt'],
 };
 const files=[...appNames,...Object.values(assetParts).flat()]; const rs=await Promise.all(files.map(n=>fetch('exports/'+n,{cache:'no-store'}))); for(const r of rs)if(!r.ok)throw new Error('Bundle HTTP '+r.status);
 const texts=Object.fromEntries(await Promise.all(rs.map(async(r,i)=>[files[i],await r.text()])));
 window.__TT_ASSETS={}; for(const [k,names] of Object.entries(assetParts)) window.__TT_ASSETS[k]='data:image/png;base64,'+names.map(n=>texts[n]).join('');
 const src=appNames.map(n=>texts[n]).join(''); (0,eval)(src+'\n//# sourceURL=transtrade-exports-clean-v1.js');
}catch(e){fail(e)}})();
})();
