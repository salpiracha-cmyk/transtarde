(()=>{
 'use strict';
 const access=window.TT_ACCOUNT_ACCESS,frame=document.getElementById('millLocalFrame'),review=document.getElementById('ws-receivables');
 async function reviews(){
  if(frame)frame.hidden=true;review.hidden=false;
  await Promise.all([window.TT_LOCAL_SALES_CONTROL_UI.mount(),window.TT_LOCAL_SALES_PAYMENT_UI.mount()]);
 }
 document.getElementById('localMillTab')?.addEventListener('click',()=>{review.hidden=true;frame.hidden=false;});
 document.getElementById('localReviewTab').addEventListener('click',reviews);
 document.getElementById('localReviewRefresh').addEventListener('click',reviews);
 if(!frame)void reviews();
})();
