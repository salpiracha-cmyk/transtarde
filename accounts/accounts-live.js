(()=>{
  const access=window.TT_ACCOUNT_ACCESS||{};
  const api='../api/accounts.php';
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const q=s=>document.querySelector(s);
  const qa=s=>[...document.querySelectorAll(s)];
  let accountingMaster=null;

  function toast(message,ok=true){
    let el=q('#ttAccountsToast');
    if(!el){el=document.createElement('div');el.id='ttAccountsToast';el.style.cssText='position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:99999;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003';document.body.appendChild(el)}
    el.style.background=ok?'#147a5b':'#a93a34';el.textContent=message;el.hidden=false;clearTimeout(el._t);el._t=setTimeout(()=>el.hidden=true,3000);
  }
  async function request(method='GET',body=null){
    const opt={method,credentials:'same-origin',headers:{'Accept':'application/json'}};
    if(body){opt.headers['Content-Type']='application/json';opt.body=JSON.stringify({...body,csrf:access.csrf})}
    const r=await fetch(api,opt);let data={};try{data=await r.json()}catch{}
    if(!r.ok||!data.ok)throw new Error(data.error||'Accounts request failed.');return data;
  }
  const amount=v=>Number(v||0);
  const payCode=v=>/cash/i.test(String(v||''))?'1120':'1110';
  const sourceKey=(button,prefix)=>{if(!button.dataset.sourceKey)button.dataset.sourceKey=prefix+'-'+(crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random().toString(36).slice(2));return button.dataset.sourceKey};
  const clearSourceKey=button=>delete button.dataset.sourceKey;
  function expenseCode(label){
    const s=String(label||'').toLowerCase();
    if(/fuel|petrol|company cars/.test(s))return '6600';
    if(/travel|convey/.test(s))return '6500';
    if(/repair|maintenance/.test(s))return '6400';
    if(/utility|electric|internet|telephone|mobile|gas|water/.test(s))return '6100';
    if(/salary|wage|staff/.test(s))return '6200';
    if(/rent/.test(s))return '6300';
    if(/inspection|certificate|professional/.test(s))return '6700';
    if(/bank|finance charge/.test(s))return '6800';
    return '6900';
  }
  function installAccountDatalist(){
    if(!accountingMaster||q('#ttAccountList'))return;
    const dl=document.createElement('datalist');dl.id='ttAccountList';
    [...(accountingMaster.chart||[]),...(accountingMaster.peopleSubledgers||[])].filter(a=>a.level!=='heading').forEach(a=>{const o=document.createElement('option');o.value=a.code;o.label=a.code+' · '+a.name;dl.appendChild(o)});
    document.body.appendChild(dl);
    qa('.jvAccount').forEach(x=>x.setAttribute('list','ttAccountList'));
  }
  function installCardExpenseDatalist(){
    if(!accountingMaster)return;
    let dl=q('#ttExpenseList');
    if(!dl){dl=document.createElement('datalist');dl.id='ttExpenseList';(accountingMaster.chart||[]).filter(a=>['6100','6200','6300','6400','6500','6600','6700','6800','6900'].includes(a.code)).forEach(a=>{const o=document.createElement('option');o.value=a.name;o.label=a.code;dl.appendChild(o)});document.body.appendChild(dl)}
    qa('.ccDetail').forEach(x=>x.setAttribute('list','ttExpenseList'));
  }
  async function refresh(){
    try{
      const data=await request();accountingMaster=data.accountingMaster||accountingMaster;
      const count=Object.values(data.reminders||{}).filter(r=>r.entity===entity()&&r.status==='Open').length;
      const el=q('#attentionCount');if(el)el.textContent=String(count);
      installAccountDatalist();installCardExpenseDatalist();
    }catch(e){console.error(e)}
  }

  document.addEventListener('click',async e=>{
    const post=e.target.closest('#postJv');
    if(post){
      e.preventDefault();e.stopImmediatePropagation();
      const lines=qa('.jvRow').map(r=>({account:r.querySelector('.jvAccount')?.value?.trim()||'',debit:amount(r.querySelector('.jvDr')?.value),credit:amount(r.querySelector('.jvCr')?.value)})).filter(x=>x.account||x.debit||x.credit);
      post.disabled=true;
      try{
        const data=await request('POST',{action:'post_journal',entity:entity(),date:q('#jvDate')?.value||'',reference:q('#jvRef')?.value||'',narration:q('#jvNarr')?.value||'',sourceType:'JV',lines});
        toast('Posted '+data.journal.id);q('#jvRef').value='';q('#jvNarr').value='';qa('.jvDr,.jvCr,.jvAccount').forEach(x=>x.value='');
      }catch(err){toast(err.message,false)}finally{post.disabled=false}
      return;
    }

    const utility=e.target.closest('#saveUtility');
    if(utility){
      e.preventDefault();e.stopImmediatePropagation();
      const amt=amount(q('#utilAmt')?.value),date=q('#utilDate')?.value||'',key=sourceKey(utility,'UTIL');
      if(!(amt>0)||!date){toast('Enter payment date and amount.',false);return}
      utility.disabled=true;
      try{
        const data=await request('POST',{action:'post_event',eventType:'UTILITY_PAYMENT',entity:entity(),date,sourceKey:key,reference:q('#utilRef')?.value||key,narration:(q('#utilType')?.value||'Utility')+' — '+(q('#utilLoc')?.value||''),amount:amt,expenseAccount:'6100',payAccount:payCode(q('#utilPay')?.value),meta:{billingMonth:q('#utilMonth')?.value||'',location:q('#utilLoc')?.value||'',remarks:q('#utilRemarks')?.value||''}});
        const due=q('#utilDue')?.value||'';
        if(due)await request('POST',{action:'save_reminder',entity:entity(),type:'Utility',label:(q('#utilType')?.value||'Utility')+' — '+(q('#utilLoc')?.value||''),dueDate:due,expectedAmount:amt});
        toast('Posted '+data.journal.id+(due?' · reminder saved':''));clearSourceKey(utility);refresh();
      }catch(err){toast(err.message,false)}finally{utility.disabled=false}
      return;
    }

    const reimbursement=e.target.closest('#saveR');
    if(reimbursement){
      e.preventDefault();e.stopImmediatePropagation();
      const person=q('#rPerson')?.value||'',amt=amount(q('#rAmt')?.value),date=q('#rDate')?.value||'',base=sourceKey(reimbursement,'REIMB'),expense=expenseCode(q('#rCat')?.value);
      if(!(amt>0)||!date){toast('Enter expense date and amount.',false);return}
      reimbursement.disabled=true;
      try{
        const cap=await request('POST',{action:'post_event',eventType:'EXPENSE_REIMBURSEMENT_CAPTURE',entity:entity(),date,sourceKey:base+'-CAP',reference:base,narration:(q('#rCat')?.value||'Business expense')+' paid personally by '+person,amount:amt,person,expenseAccount:expense});
        let msg='Captured '+cap.journal.id;
        if((q('#rNow')?.value||'yes')==='yes'){
          const set=await request('POST',{action:'post_event',eventType:'EXPENSE_REIMBURSEMENT_SETTLE',entity:entity(),date:new Date().toISOString().slice(0,10),sourceKey:base+'-SET',reference:base,narration:'Reimburse '+person,amount:amt,person,payAccount:payCode(q('#rPay')?.value)});msg+=' · settled '+set.journal.id;
        }
        toast(msg);clearSourceKey(reimbursement);
      }catch(err){toast(err.message,false)}finally{reimbursement.disabled=false}
      return;
    }

    const card=e.target.closest('#saveCc');
    if(card){
      e.preventDefault();e.stopImmediatePropagation();installCardExpenseDatalist();
      const total=amount(q('#ccTotal')?.value),date=q('#ccDate')?.value||'',key=sourceKey(card,'CARD');
      const allocations=qa('.allocRow').map(r=>{
        const kindText=r.querySelector('.ccKind')?.value||'Business Expense',a=amount(r.querySelector('.ccAmt')?.value),detail=r.querySelector('.ccDetail')?.value?.trim()||'';
        const pm=kindText.match(/^(Salman|Talha|Abu|Tayyab)/i);
        return pm?{kind:'PERSONAL',person:pm[1],amount:a,detail}:{kind:'BUSINESS',account:detail||'6900',amount:a,detail};
      }).filter(x=>x.amount>0);
      if(!(total>0)||!date){toast('Enter card payment date and amount.',false);return}
      card.disabled=true;
      try{
        const data=await request('POST',{action:'post_event',eventType:'CREDIT_CARD_PAYMENT',entity:entity(),date,sourceKey:key,reference:q('#ccCard')?.value||key,narration:'Credit card payment — '+(q('#ccCard')?.value||''),amount:total,payAccount:payCode(q('#ccPay')?.value),allocations});
        toast('Posted '+data.journal.id);clearSourceKey(card);
      }catch(err){toast(err.message,false)}finally{card.disabled=false}
      return;
    }
  },true);

  const observer=new MutationObserver(()=>{qa('.jvAccount').forEach(x=>x.setAttribute('list','ttAccountList'));installCardExpenseDatalist()});
  observer.observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('click',e=>{if(e.target.closest('.entityBtn'))setTimeout(refresh,0)});
  refresh();
})();