(()=>{'use strict';
 const target=document.getElementById('taxInvoice');
 try{const html=window.TT_TAX_INVOICE_HTML().replace(/(?:\/exports\/)?assets\/(TTI_header|TTI_sign|BRM_header|BRM_sign)\.png/g,(_,name)=>'/api/monthly_tax_assets.php?entity='+encodeURIComponent(window.TT_TAX_ENTITY)+'&asset='+name+'.png');target.innerHTML=html;
 window.addEventListener('message',async event=>{if(event.origin!==location.origin||event.source!==parent||!['tt-tax-invoice-request','tt-tax-invoice-pdf'].includes(event.data?.type))return;try{if(event.data.type==='tt-tax-invoice-pdf'){const blob=await window.TT_SHIPMENT_FILES.pdf(html),buffer=await blob.arrayBuffer();parent.postMessage({type:'tt-tax-invoice-pdf',token:event.data.token,buffer},location.origin,[buffer]);}else parent.postMessage({type:'tt-tax-invoice-html',token:event.data.token,html},location.origin);}catch(error){parent.postMessage({type:'tt-tax-invoice-error',token:event.data.token,error:error.message},location.origin);}});
 }catch(error){target.textContent=error.message;}
})();
