(()=>{
 'use strict';
 // Presentation-only entry into the original Mill workflow. Its existing
 // location, stock, source-version, save acknowledgement and action guards run.
 const originalOpen=window.openPanel;
 window.openPanel=function(id){return originalOpen(id==='millLanding'?'millLanding':'local');};
 window.goHome=()=>originalOpen('millLanding');
 const embedded=new URLSearchParams(location.search).get('embedded')==='1';
 if(embedded){const style=document.createElement('style');style.textContent='body>header{display:none!important}#local>h2{margin-top:12px}';document.head.appendChild(style);}
 document.body.dataset.ttLocalSalesReady='true';
 originalOpen('millLanding');
})();
