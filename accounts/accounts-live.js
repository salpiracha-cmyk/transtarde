(()=>{
  const access=window.TT_ACCOUNT_ACCESS||{};
  const api='../api/accounts.php';
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const q=s=>document.querySelector(s);
  const qa=s=>[...document.querySelectorAll(s)];
  function toast(message,ok=true){
    let el=q('#ttAccountsToast');
    if(!el){el=document.createElement('div');el.id='ttAccountsToast';el.style.cssText='position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:99999;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003';document.body.appendChild(el)}
    el.style.background=ok?'#147a5b':'#a93a34';el.textContent=message;el.hidden=false;clearTimeout(el._t);el._t=setTimeout(()=>el.hidden=true,2600);
  }
  async function request(method='GET',body=null){
    const opt={method,credentials:'same-origin',headers:{'Accept':'application/json'}};
    if(body){opt.headers['Content-Type']='application/json';opt.body=JSON.stringify({...body,csrf:access.csrf})}
    const r=await fetch(api,opt);let data={};try{data=await r.json()}catch{}
    if(!r.ok||!data.ok)throw new Error(data.error||'Accounts request failed.');return data;
  }
  async function refresh(){
    try{const data=await request();const count=Object.values(data.reminders||{}).filter(r=>r.entity===entity()&&r.status==='Open').length;const el=q('#attentionCount');if(el)el.textContent=String(count)}catch(e){console.error(e)}
  }
  document.addEventListener('click',async e=>{
    const post=e.target.closest('#postJv');
    if(post){
      e.preventDefault();e.stopImmediatePropagation();
      const lines=qa('.jvRow').map(r=>({account:r.querySelector('.jvAccount')?.value?.trim()||'',debit:Number(r.querySelector('.jvDr')?.value||0),credit:Number(r.querySelector('.jvCr')?.value||0)})).filter(x=>x.account||x.debit||x.credit);
      post.disabled=true;
      try{
        const data=await request('POST',{action:'post_journal',entity:entity(),date:q('#jvDate')?.value||'',reference:q('#jvRef')?.value||'',narration:q('#jvNarr')?.value||'',sourceType:'JV',lines});
        toast('Posted '+data.journal.id);q('#jvRef').value='';q('#jvNarr').value='';qa('.jvDr,.jvCr,.jvAccount').forEach(x=>x.value='');
      }catch(err){toast(err.message,false)}finally{post.disabled=false}
      return;
    }
    const utility=e.target.closest('#saveUtility');
    if(utility){
      const due=q('#utilDue')?.value||'';
      if(due){
        try{await request('POST',{action:'save_reminder',entity:entity(),type:'Utility',label:(q('#utilType')?.value||'Utility')+' — '+(q('#utilLoc')?.value||''),dueDate:due,expectedAmount:Number(q('#utilAmt')?.value||0)});toast('Reminder saved. Payment posting remains pending master-account mapping.');refresh()}catch(err){toast(err.message,false)}
      }
    }
  },true);
  document.addEventListener('click',e=>{if(e.target.closest('.entityBtn'))setTimeout(refresh,0)});
  refresh();
})();
