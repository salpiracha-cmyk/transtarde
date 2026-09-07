(()=>{
  const access=window.TT_ACCOUNT_ACCESS||{};
  const api='../api/accounts.php';
  const q=s=>document.querySelector(s),qa=s=>[...document.querySelectorAll(s)];
  let master=null;
  async function load(){try{const r=await fetch(api,{credentials:'same-origin'}),d=await r.json();if(r.ok&&d.ok)master=d.accountingMaster||null}catch(e){console.error(e)}}
  function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}

  function buildEntityLanding(){
    if(q('#ttEntityLanding')||!q('#entityHome'))return;
    const landing=document.createElement('section');landing.id='ttEntityLanding';landing.innerHTML=`
      <div style="max-width:1180px;margin:34px auto">
        <p class="eyebrow">ACCOUNTS</p>
        <h1 style="font-size:30px;margin:0 0 7px">Select Company Books</h1>
        <p style="color:#6f7a89;margin:0 0 24px">Each entity keeps separate legal books. Select the entity you want to work in.</p>
        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px" id="ttEntityCards">
          <button class="appCard" data-tt-entity="TTI" style="min-height:190px"><div class="appIcon">TT</div><h3>Transtrade International</h3><p>Pakistan operating / export books.</p><div style="margin-top:20px;font-weight:800;color:#173c63">Open TTI Accounts →</div></button>
          <button class="appCard" data-tt-entity="BRM" style="min-height:190px"><div class="appIcon">BR</div><h3>Buksh Rice Mills</h3><p>Pakistan mill / processing books.</p><div style="margin-top:20px;font-weight:800;color:#173c63">Open BRM Accounts →</div></button>
          <button class="appCard" data-tt-entity="TG" style="min-height:190px;border-left:4px solid #8a5fbb"><div class="appIcon">TG</div><h3>Trans Grains</h3><p>Dubai / offshore books · restricted access.</p><div style="margin-top:20px;font-weight:800;color:#70429a">Open TG Accounts →</div></button>
        </div>
        <div class="notice" style="margin-top:20px"><span>ⓘ</span><div><b>Group relationship, separate books.</b> TG-linked and Pakistan transactions can be connected and reconciled without merging the legal ledgers.</div></div>
      </div>`;
    q('#entityHome').parentElement.insertBefore(landing,q('#entityHome'));
    q('#entityHome').style.display='none';
    const change=document.createElement('button');change.id='ttChangeEntity';change.className='btn';change.type='button';change.textContent='Change Entity';change.style.cssText='background:#ffffff16;color:#fff;border-color:#ffffff33;margin-left:4px';
    const power=q('.power');power?.parentElement.insertBefore(change,power);
    change.onclick=showLanding;
    qa('[data-tt-entity]').forEach(b=>b.onclick=()=>openEntity(b.dataset.ttEntity));
  }
  function showLanding(){
    qa('.workspace').forEach(w=>w.classList.remove('active'));
    if(q('#entityHome'))q('#entityHome').style.display='none';
    if(q('#ttEntityLanding'))q('#ttEntityLanding').style.display='block';
    scrollTo(0,0);
  }
  function openEntity(code){
    const hidden=q(`.entityBtn[data-entity="${code}"]`);if(hidden)hidden.click();else localStorage.setItem('tt_accounts_entity',code);
    q('#ttEntityLanding').style.display='none';q('#entityHome').style.display='block';scrollTo(0,0);
  }

  function ensureMasterTabs(){
    const row=q('.masterTabs');if(!row)return;
    if(!q('.tab[data-master="posting"]')){const b=document.createElement('button');b.className='tab';b.dataset.master='posting';b.textContent='Posting Rules';row.appendChild(b)}
    if(!q('.tab[data-master="close"]')){const b=document.createElement('button');b.className='tab';b.dataset.master='close';b.textContent='Period Close';row.appendChild(b)}
  }
  function chartHtml(){
    if(!master)return '<div class="split"><div class="formCard"><h3>Chart of Accounts</h3><p class="helper">Loading approved chart…</p></div></div>';
    const rows=(master.chart||[]).map(a=>`<tr><td><b>${esc(a.code)}</b></td><td>${esc(a.name)}</td><td>${esc(a.class)}</td><td>${esc(a.level)}</td><td>${esc(a.normal)}</td><td>${esc((a.legacy||[]).join('; '))}</td></tr>`).join('');
    return `<div style="padding:18px"><div class="formCard"><h3>Approved Chart of Accounts</h3><p class="helper">Legacy HOA / Control / General / Subsidiary logic is mapped into this cleaner hierarchy. User screens may use plain business language; automatic postings resolve to these accounts.</p><div class="tableWrap" style="margin-top:14px"><table><thead><tr><th>Code</th><th>Account</th><th>Class</th><th>Level</th><th>Normal</th><th>Legacy mapping</th></tr></thead><tbody>${rows}</tbody></table></div></div></div>`;
  }
  function postingHtml(){
    if(!master)return '<div class="split"><div class="formCard"><p class="helper">Loading…</p></div></div>';
    return `<div style="padding:18px"><div class="formCard"><h3>Automatic Posting Rules</h3><p class="helper">These rules describe what the system posts underneath each business event. They are not editable transaction-by-transaction.</p><div class="tableWrap" style="margin-top:14px"><table><thead><tr><th>Business Event</th><th>Debit</th><th>Credit</th><th>Control</th></tr></thead><tbody>${(master.postingRules||[]).map(r=>`<tr><td><b>${esc(r.event)}</b></td><td>${esc(r.debit)}</td><td>${esc(r.credit)}</td><td style="white-space:normal;min-width:330px">${esc(r.rule)}</td></tr>`).join('')}</tbody></table></div></div></div>`;
  }
  function closeHtml(){
    if(!master)return '<div class="split"><div class="formCard"><p class="helper">Loading…</p></div></div>';
    return `<div class="split"><div class="formCard"><h3>Period-Close Accounting Checks</h3>${(master.closeControls||[]).map((x,i)=>`<div class="ruleBox"><strong>${i+1}. ${esc(x)}</strong></div>`).join('')}</div><div class="infoCard"><h3>Why this matters</h3><div class="ruleBox"><strong>Simple entry does not mean cash-basis accounting.</strong><small>Utilities, cards, rent and salary reminders can remain non-ledger until payment, but a month/year cannot be locked if material incurred costs require accrual.</small></div><div class="ruleBox"><strong>Corrections remain traceable.</strong><small>Locked periods and posted journals are corrected through reversal/adjustment rather than silent overwrite.</small></div></div></div>`;
  }
  function renderMaster(k){const body=q('#masterBody');if(!body)return;if(k==='chart')body.innerHTML=chartHtml();if(k==='posting')body.innerHTML=postingHtml();if(k==='close')body.innerHTML=closeHtml()}

  document.addEventListener('click',e=>{
    const tab=e.target.closest('.tab[data-master]');if(tab&&['chart','posting','close'].includes(tab.dataset.master)){setTimeout(()=>{qa('.tab').forEach(t=>t.classList.toggle('active',t===tab));renderMaster(tab.dataset.master)},0)}
    if(e.target.closest('.appCard[data-key="masters"]'))setTimeout(ensureMasterTabs,0);
  });
  new MutationObserver(()=>ensureMasterTabs()).observe(document.documentElement,{childList:true,subtree:true});
  (async()=>{await load();buildEntityLanding();ensureMasterTabs()})();
})();