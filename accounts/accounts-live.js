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
    const endpoint=method==='GET'?api+'?entity='+encodeURIComponent(entity()):api;
    const r=await fetch(endpoint,opt);let data={};try{data=await r.json()}catch{}
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
  function installUtilityReminderButton(){
    const save=q('#saveUtility');if(!save||q('#saveUtilityReminder'))return;
    const b=document.createElement('button');b.className='btn';b.id='saveUtilityReminder';b.type='button';b.textContent='Save Due-Date Reminder Only';
    save.parentElement.insertBefore(b,save);
  }
  async function refresh(){
    try{
      const data=await request();accountingMaster=data.accountingMaster||accountingMaster;
      const count=Object.values(data.reminders||{}).filter(r=>r.entity===entity()&&r.status==='Open').length;
      const el=q('#attentionCount');if(el)el.textContent=String(count);
      installAccountDatalist();installCardExpenseDatalist();installUtilityReminderButton();
    }catch(e){console.error(e)}
  }

  document.addEventListener('click',async e=>{
    const reminder=e.target.closest('#saveUtilityReminder');
    if(!reminder)return;
    e.preventDefault();e.stopImmediatePropagation();
    const due=q('#utilDue')?.value||'';
    if(!due){toast('Enter the due date first.',false);return}
    reminder.disabled=true;
    try{
      await request('POST',{action:'save_reminder',entity:entity(),type:'Utility',label:(q('#utilType')?.value||'Utility')+' — '+(q('#utilLoc')?.value||''),dueDate:due,expectedAmount:amount(q('#utilAmt')?.value)});
      toast('Due-date reminder saved. No ledger entry was created.');refresh();
    }catch(err){toast(err.message,false)}finally{reminder.disabled=false}
  },true);

  const observer=new MutationObserver(()=>{qa('.jvAccount').forEach(x=>x.setAttribute('list','ttAccountList'));installCardExpenseDatalist();installUtilityReminderButton()});
  observer.observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('click',e=>{if(e.target.closest('.entityBtn'))setTimeout(refresh,0)});
  refresh();
})();
