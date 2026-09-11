(()=>{
  const access=window.TT_ACCOUNT_ACCESS||{};
  const api='../api/accounts.php',billApi='../api/commodity_bills.php';
  const q=s=>document.querySelector(s),qa=s=>[...document.querySelectorAll(s)];
  const currentEntity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  let master=null,billData={receipts:[],bills:[]};
  async function load(){try{const r=await fetch(api+'?entity='+encodeURIComponent(currentEntity()),{credentials:'same-origin'}),d=await r.json();if(r.ok&&d.ok)master=d.accountingMaster||null}catch(e){console.error(e)}}
  function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
  function fmt(n){return Number(n||0).toLocaleString('en-PK',{maximumFractionDigits:2})}
  function toast(message,ok=true){let el=q('#ttEnhanceToast');if(!el){el=document.createElement('div');el.id='ttEnhanceToast';el.style.cssText='position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:99999;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003';document.body.appendChild(el)}el.style.background=ok?'#147a5b':'#a93a34';el.textContent=message;el.hidden=false;clearTimeout(el._t);el._t=setTimeout(()=>el.hidden=true,3200)}

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
    q('#entityHome').parentElement.insertBefore(landing,q('#entityHome'));q('#entityHome').style.display='none';
    const change=document.createElement('button');change.id='ttChangeEntity';change.className='btn';change.type='button';change.textContent='Change Entity';change.style.cssText='background:#ffffff16;color:#fff;border-color:#ffffff33;margin-left:4px';
    const power=q('.power');power?.parentElement.insertBefore(change,power);change.onclick=showLanding;const allowed=Array.isArray(access.entities)?access.entities:[];qa('[data-tt-entity]').forEach(b=>{b.hidden=!allowed.includes(b.dataset.ttEntity);b.onclick=()=>openEntity(b.dataset.ttEntity)});
  }
  function showLanding(){qa('.workspace').forEach(w=>w.classList.remove('active'));if(q('#entityHome'))q('#entityHome').style.display='none';if(q('#ttEntityLanding'))q('#ttEntityLanding').style.display='block';scrollTo(0,0)}
  function openEntity(code){if(!Array.isArray(access.entities)||!access.entities.includes(code))return;const hidden=q(`.entityBtn[data-entity="${code}"]`);if(hidden)hidden.click();q('#ttEntityLanding').style.display='none';q('#entityHome').style.display='block';load();scrollTo(0,0)}

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

  async function fetchBillData(){
    const r=await fetch(billApi+'?entity='+encodeURIComponent(currentEntity()),{credentials:'same-origin',headers:{Accept:'application/json'}});let d={};try{d=await r.json()}catch{}if(!r.ok||!d.ok)throw new Error(d.error||'Could not load unbilled receipts.');billData=d;return d;
  }
  function sodaGroups(){const m=new Map();for(const r of billData.receipts||[]){const key=r.soda||'NO-SODA';if(!m.has(key))m.set(key,[]);m.get(key).push(r)}return m}
  function billReceiptTable(rows){
    if(!rows.length)return '<div class="note">No unbilled Pohanch receipts are available for this entity.</div>';
    return `<div class="tableWrap"><table><thead><tr><th>Use</th><th>Pohanch</th><th>Truck</th><th>Variety</th><th>Kg</th><th>Soda Rate / Kg</th><th>KAT Paisa / Kg</th><th>Provisional Net Rate</th><th>Provisional Value</th></tr></thead><tbody>${rows.map(r=>`<tr><td><input class="cbReceipt" data-source-key="${esc(r.sourceKey)}" type="checkbox" checked style="width:auto"></td><td><b>${esc(r.pohanch)}</b></td><td>${esc(r.truck)}</td><td>${esc(r.variety)}</td><td>${fmt(r.payableWeightKg)}</td><td>${fmt(r.grossRatePerKg)}</td><td>${fmt(r.katPaisaPerKg)}</td><td>${fmt(r.provisionalNetRatePerKg)}</td><td>Rs ${fmt(r.provisionalAmount)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  function verifiedBillsHtml(){
    const rows=(billData.bills||[]).filter(b=>b.entity===currentEntity()).slice().reverse().slice(0,20);
    return `<div class="formCard" style="margin:18px"><h3>Recently Verified Commodity Bills</h3><div class="tableWrap"><table><thead><tr><th>Bill</th><th>Date</th><th>Soda</th><th>Broker</th><th>Commodity Value</th><th>Brokerage</th><th>Journal</th><th>Status</th></tr></thead><tbody>${rows.length?rows.map(b=>`<tr><td><b>${esc(b.billNo||b.id)}</b></td><td>${esc(b.billDate)}</td><td>${esc((b.sodas||[]).join(', '))}</td><td>${esc(b.broker||'—')}</td><td>Rs ${fmt(b.finalCommodityValue)}</td><td>Rs ${fmt(b.brokerageGross)}</td><td>${esc(b.journalId)}</td><td><span class="pill green">${esc(b.status)}</span></td></tr>`).join(''):'<tr><td colspan="8">No verified commodity bills yet.</td></tr>'}</tbody></table></div></div>`;
  }
  function selectedBillRows(){const keys=new Set(qa('.cbReceipt:checked').map(x=>x.dataset.sourceKey));return (billData.receipts||[]).filter(r=>keys.has(r.sourceKey))}
  function recalcBill(){
    const rows=selectedBillRows(),prov=rows.reduce((s,r)=>s+Number(r.provisionalAmount||0),0),kg=rows.reduce((s,r)=>s+Number(r.payableWeightKg||0),0),avg=kg?prov/kg:0;
    const lessKg=Number(q('#cbLessWeight')?.value||0),weightDed=lessKg*avg,rateAdj=Number(q('#cbRateAdj')?.value||0),allow=Number(q('#cbAllowance')?.value||0),kanta=Number(q('#cbKanta')?.value||0),fill=Number(q('#cbFilling')?.value||0),add=Number(q('#cbOtherAdd')?.value||0),ded=Number(q('#cbOtherDeduct')?.value||0);
    const final=Math.max(0,prov-weightDed+rateAdj+allow+kanta+fill+add-ded);
    if(q('#cbProv'))q('#cbProv').value=prov.toFixed(2);if(q('#cbSelectedKg'))q('#cbSelectedKg').value=kg.toFixed(2);if(q('#cbWeightValue'))q('#cbWeightValue').value=weightDed.toFixed(2);if(q('#cbFinal'))q('#cbFinal').value=final.toFixed(2);
    const bg=Number(q('#cbBrokerage')?.value||0),pct=Number(q('#cbWithPct')?.value||0);if(q('#cbWithAmt')&&document.activeElement!==q('#cbWithAmt'))q('#cbWithAmt').value=(bg*pct/100).toFixed(2);
    if(q('#cbTotalPayable'))q('#cbTotalPayable').textContent='Rs '+fmt(final+bg-Number(q('#cbWithAmt')?.value||0));
  }
  function bindCommodityBill(){
    qa('.cbReceipt').forEach(x=>x.onchange=recalcBill);['cbLessWeight','cbRateAdj','cbAllowance','cbKanta','cbFilling','cbOtherAdd','cbOtherDeduct','cbBrokerage','cbWithPct','cbWithAmt'].forEach(id=>{const el=q('#'+id);if(el)el.oninput=recalcBill});
    const soda=q('#cbSoda');if(soda)soda.onchange=()=>renderCommodityBill(soda.value);
    const save=q('#cbVerify');if(save)save.onclick=verifyCommodityBill;recalcBill();
  }
  function renderCommodityBill(selectedSoda){
    const editor=q('#purchaseEditor');if(!editor)return;
    if(currentEntity()==='TG'){editor.innerHTML='<div class="split"><div class="formCard"><h3>Commodity Purchases</h3><div class="note">Pakistan Soda/Pohanch purchasing does not post directly into TG books. Use TG / Intercompany for linked Pakistan shipments and TG-side purchases.</div></div></div>';return}
    const groups=sodaGroups(),sodas=[...groups.keys()],soda=selectedSoda&&groups.has(selectedSoda)?selectedSoda:(sodas[0]||''),rows=soda?groups.get(soda):[],first=rows[0]||{};
    editor.innerHTML=`<div style="padding-top:4px"><div class="split"><div class="formCard"><h3>Commodity Purchase Bill — Receipt Linked</h3><div class="grid2"><label>Commodity / Soda<select id="cbSoda">${sodas.length?sodas.map(s=>`<option value="${esc(s)}"${s===soda?' selected':''}>${esc(s)} · ${esc((groups.get(s)[0]||{}).variety||'Commodity')}</option>`).join(''):'<option>No unbilled receipts</option>'}</select></label><label>Broker / Payee<input id="cbBroker" value="${esc(first.broker||'')}"></label><label>Bill Date<input id="cbDate" type="date" value="${new Date().toISOString().slice(0,10)}"></label><label>Supplier / Broker Bill No.<input id="cbBillNo" placeholder="Optional if no external bill number"></label></div><div style="margin-top:14px">${billReceiptTable(rows)}</div><div class="grid3" style="margin-top:14px"><label>Selected Receipt Kg<input id="cbSelectedKg" readonly></label><label>Provisional Receipt Value<input id="cbProv" readonly></label><label>Less Weight (kg)<input id="cbLessWeight" type="number" step="0.01" min="0" value="0"></label><label>Weight Deduction Value<input id="cbWeightValue" readonly></label><label>Rate / Quality Adjustment (+/− Rs)<input id="cbRateAdj" type="number" step="0.01" value="0"></label><label>Allowance / Adjustment (+/− Rs)<input id="cbAllowance" type="number" step="0.01" value="0"></label><label>Kanta / Weighing Amount<input id="cbKanta" type="number" step="0.01" min="0" value="0"></label><label>Filling Amount<input id="cbFilling" type="number" step="0.01" min="0" value="0"></label><label>Other Addition<input id="cbOtherAdd" type="number" step="0.01" min="0" value="0"></label><label>Other Deduction<input id="cbOtherDeduct" type="number" step="0.01" min="0" value="0"></label><label>Final Commodity Value<input id="cbFinal" type="number" step="0.01" min="0"></label><label>Gross Brokerage<input id="cbBrokerage" type="number" step="0.01" min="0" value="0"></label><label>Brokerage Withholding %<input id="cbWithPct" type="number" step="0.0001" min="0" value="0" placeholder="Auditor-confirmed master later"></label><label>Brokerage Withholding Amount<input id="cbWithAmt" type="number" step="0.01" min="0" value="0"></label><label class="full">Remarks<textarea id="cbRemarks" placeholder="Bill adjustments / auditor reference / exceptions"></textarea></label></div><div class="accountPreview"><strong>Posting preview</strong><div class="helper">Clears provisional GRNI from the selected Pohanch receipts; adjusts the receipt inventory cost to the verified bill value; creates commodity supplier/broker payable. Gross brokerage, if entered, is capitalized to commodity inventory and split between broker payable and withholding-tax payable. Final percentages remain master-controlled.</div><div class="postingLine"><span>Net cash/payable exposure shown here</span><span id="cbTotalPayable">Rs 0</span></div></div><div class="actions"><button class="btn primary" id="cbVerify" ${rows.length?'':'disabled'}>Verify & Post Commodity Bill</button></div></div><div class="infoCard"><h3>Controls retained from the old system</h3><div class="ruleBox"><strong>Soda + individual truck receipts stay traceable.</strong><small>One Soda may contain multiple trucks/Pohanch records. The same receipt cannot be included in two bills.</small></div><div class="ruleBox"><strong>Old bill adjustments are preserved, but modernized.</strong><small>Weight/rate adjustments, allowance, kanta, filling, additions and deductions are recorded as the explanation for the final commodity value rather than hidden calculations.</small></div><div class="ruleBox"><strong>Brokerage / withholding is separate from commodity value.</strong><small>The rate will default from an effective-dated auditor-approved Accounts Master once confirmed; until then there is no hard-coded percentage.</small></div></div></div>${verifiedBillsHtml()}</div>`;
    bindCommodityBill();
  }
  async function openCommodityBill(){
    const editor=q('#purchaseEditor');if(editor)editor.innerHTML='<div class="formCard" style="margin:18px"><h3>Commodity Purchase Bill</h3><p class="helper">Loading unbilled Pohanch receipts…</p></div>';
    try{await fetchBillData();renderCommodityBill()}catch(e){if(editor)editor.innerHTML=`<div class="formCard" style="margin:18px"><h3>Commodity Purchase Bill</h3><div class="note">${esc(e.message)}</div></div>`}
  }
  async function verifyCommodityBill(){
    const btn=q('#cbVerify'),rows=selectedBillRows();if(!rows.length)return toast('Select at least one Pohanch receipt.',false);
    const final=Number(q('#cbFinal')?.value||0),brokerage=Number(q('#cbBrokerage')?.value||0),withholding=Number(q('#cbWithAmt')?.value||0);if(!(final>0))return toast('Final commodity value must be greater than zero.',false);
    btn.disabled=true;
    try{
      const body={action:'verify_bill',csrf:access.csrf,entity:currentEntity(),billDate:q('#cbDate')?.value||'',billNo:q('#cbBillNo')?.value?.trim()||'',broker:q('#cbBroker')?.value?.trim()||'',sourceKeys:rows.map(r=>r.sourceKey),finalCommodityValue:final,brokerageGross:brokerage,brokerageWithholding:withholding,remarks:q('#cbRemarks')?.value||'',adjustments:{lessWeightKg:Number(q('#cbLessWeight')?.value||0),weightDeductionValue:Number(q('#cbWeightValue')?.value||0),rateQualityAdjustment:Number(q('#cbRateAdj')?.value||0),allowance:Number(q('#cbAllowance')?.value||0),kanta:Number(q('#cbKanta')?.value||0),filling:Number(q('#cbFilling')?.value||0),otherAddition:Number(q('#cbOtherAdd')?.value||0),otherDeduction:Number(q('#cbOtherDeduct')?.value||0),brokerageWithholdingPct:Number(q('#cbWithPct')?.value||0)}};
      const r=await fetch(billApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});let d={};try{d=await r.json()}catch{}if(!r.ok||!d.ok)throw new Error(d.error||'Commodity bill posting failed.');toast('Posted '+d.bill.id+' · '+d.journal.id);await fetchBillData();renderCommodityBill();
    }catch(e){toast(e.message,false)}finally{if(q('#cbVerify'))q('#cbVerify').disabled=false}
  }

  document.addEventListener('click',e=>{
    const tab=e.target.closest('.tab[data-master]');if(tab&&['chart','posting','close'].includes(tab.dataset.master)){setTimeout(()=>{qa('.tab').forEach(t=>t.classList.toggle('active',t===tab));renderMaster(tab.dataset.master)},0)}
    if(e.target.closest('.appCard[data-key="masters"]'))setTimeout(ensureMasterTabs,0);
    if(e.target.closest('[data-purchase="commodity"]'))setTimeout(openCommodityBill,0);
  });
  new MutationObserver(()=>ensureMasterTabs()).observe(document.documentElement,{childList:true,subtree:true});
  (async()=>{await load();buildEntityLanding();ensureMasterTabs()})();
})();
