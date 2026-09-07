(()=>{'use strict';
const fail=e=>{console.error('Transtrade Export bundle failed to load',e);const a=document.getElementById('app');if(a)a.innerHTML='<div style="margin:24px;padding:18px;border:1px solid #c33;border-radius:10px;font-family:Arial"><b>Export module could not load.</b><br>Please refresh. If this continues, contact the administrator.</div>'};
(async()=>{try{
  const names=['app.part01.txt','app.part02.txt','app.part03.txt','app.part04.txt','app.part05.txt','app.part06.txt','app.part07.txt'];
  const rs=await Promise.all(names.map(n=>fetch('exports/'+n,{cache:'no-store'})));for(const r of rs)if(!r.ok)throw new Error('Bundle HTTP '+r.status);
  const src=(await Promise.all(rs.map(r=>r.text()))).join('');(0,eval)(src+'\n//# sourceURL=transtrade-exports-clean-v1.js');
}catch(e){fail(e)}})();
})();
