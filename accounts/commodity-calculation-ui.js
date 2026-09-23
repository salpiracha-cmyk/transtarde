(()=>{
  'use strict';

  const nativeFetch=window.fetch.bind(window);
  const api='../api/accounts_workflows_v1.php';
  let data=null;
  let busy=false;

  const q=selector=>document.querySelector(selector);
  const qa=selector=>[...document.querySelectorAll(selector)];
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const number=id=>Number(q('#'+id)?.value||0);
  const normal=value=>String(value||'').trim().toLowerCase();

  function selected(){
    const smartRows=window.TT_SMART_COMMODITY_BILLS_V2?.selectionRows?.();
    if(Array.isArray(smartRows)&&smartRows.length)return smartRows.map(row=>({
      commodity:String(row.commodity||'RICE').toUpperCase(),
      kg:Number(row.payableWeightKg||0),
      bags:Number(row.bags||0),
      soda:String(row.soda||'')
    }));
    return qa('.ttsb-select:checked').map(input=>{
      const cells=[...input.closest('tr').children];
      const label=String(cells[4]?.textContent||'').toUpperCase();
      return {
        commodity:/SESAME/.test(label)?'SESAME':/CORN|MAIZE|MAKAI/.test(label)?'CORN':'RICE',
        kg:Number(String(cells[6]?.textContent||'0').replace(/,/g,'')),bags:0,
        soda:(input.closest('.ttsb-group')?.querySelector('.ttsb-grouphead b')?.textContent||'').replace(/^.*Soda\s*/i,'').trim()
      };
    });
  }

  function current(){
    const rows=selected();
    const sodaNo=rows[0]?.soda||'';
    const soda=(data?.sodas||[]).find(item=>String(item.sodaNo)===sodaNo);
    return {rows,commodity:rows[0]?.commodity||'RICE',soda,profile:soda?.katProfile||''};
  }

  async function load(){
    if(data||busy)return;
    busy=true;
    try{
      const response=await nativeFetch(api+'?entity='+encodeURIComponent(entity())+'&section=sodas',{credentials:'same-origin'});
      const payload=await response.json();
      if(response.ok&&payload.ok)data=payload;
    }finally{
      busy=false;
    }
  }

  function inspection(){
    return {
      karachiWeightKg:number('ttKatKarachiKg'),
      bags:number('ttBrokeryBags'),
      moisturePct:number('ttKatMoisture'),
      damageFungusPct:number('ttKatDamage'),
      admixturePct:number('ttKatAdmixture'),
      otherDeductionKgPer100:number('ttKatOther'),
      otherDeductionReason:q('#ttKatOtherReason')?.value.trim()||''
    };
  }

  function activeBuyingBrokery(context){
    return (data?.brokers||[]).find(profile=>normal(profile.name)===normal(context.soda?.broker))?.rate||null;
  }

  function brokeryValue(context,weightKg,bags){
    const rate=activeBuyingBrokery(context);
    if(!rate)return {amount:0,rate:null,label:'No active Buying Brokery in this broker profile'};
    const figure=Number(rate.amount||0);
    const units={
      PER_100_KG:weightKg/100,
      PER_50_KG_BAG:weightKg/50,
      PER_BAG:bags,
      PER_MAUND:weightKg/40,
      PER_TON:weightKg/1000
    }[rate.basis]||0;
    return {
      amount:units*figure,
      rate,
      label:`Rs ${figure.toLocaleString()} · ${String(rate.basisLabel||rate.basis||'').replaceAll('_',' ').toLowerCase()}`
    };
  }

  function applyBrokeryPreview(){
    const context=current();
    const weight=context.commodity==='RICE'?context.rows.reduce((sum,row)=>sum+row.kg,0):number('ttKatKarachiKg');
    const recordedBags=context.rows.reduce((sum,row)=>sum+Number(row.bags||0),0);
    const brokery=brokeryValue(context,weight,recordedBags||number('ttBrokeryBags'));
    const input=q('#ttsbBrokerage');
    if(input&&!q('#ttsbRate')){
      input.value=brokery.amount.toFixed(2);
      input.readOnly=true;
      input.title='Calculated from the selected Soda broker’s active Buying Brokery profile.';
    }
    q('#ttsbWithPct')?.dispatchEvent(new Event('input',{bubbles:true}));
    const preview=q('#ttBrokeryPreview');
    if(preview)preview.innerHTML=`<b>Buying Brokery:</b> ${brokery.label} = <b>Rs ${brokery.amount.toLocaleString(undefined,{maximumFractionDigits:2})}</b>. The server checks this again before posting.`;
    return brokery;
  }

  function calculateCommodity(){
    const context=current();
    const box=q('#ttCommodityKatCalc');
    if(!box||!context.soda||context.commodity==='RICE')return;
    const values=inspection();
    const rules=data?.katMaster?.[context.profile]?.rules||{};
    const maund=Number(rules.maundKg||40);
    const rate=Number(context.soda.rate||0);
    let per100=values.otherDeductionKgPer100;
    let detail='';
    if(context.commodity==='CORN'){
      const moisture=Math.max(0,values.moisturePct-Number(rules.moistureFreePct??13))*Number(rules.moistureKgPer100PerPct||1);
      const damage=Math.max(0,values.damageFungusPct-Number(rules.damageFungusFreePct??2))*Number(rules.damageFungusKgPer100PerPct||1);
      per100+=moisture+damage;
      detail=`Moisture ${moisture.toFixed(3)} + damage/fungus ${damage.toFixed(3)} + other ${values.otherDeductionKgPer100.toFixed(3)} kg per 100 kg`;
    }else{
      const free=Number(rules.admixtureFreePct??(context.profile==='SESAME_RAW'?3:1));
      const admixture=Math.max(0,values.admixturePct-free)*Number(rules.admixtureKgPer100PerPct||1);
      per100+=admixture;
      detail=`Admixture ${admixture.toFixed(3)} + other ${values.otherDeductionKgPer100.toFixed(3)} kg per 100 kg`;
    }
    const deduction=values.karachiWeightKg*per100/100;
    const net=Math.max(0,values.karachiWeightKg-deduction);
    const value=net/maund*rate;
    if(q('#ttsbFinal'))q('#ttsbFinal').value=value.toFixed(2);
    window.TT_SMART_COMMODITY_BILLS_V2?.refreshTotals?.();
    const brokery=applyBrokeryPreview();
    q('#ttKatResult').innerHTML=`<b>System calculation:</b> ${values.karachiWeightKg.toLocaleString()} kg − ${deduction.toFixed(3)} kg = ${net.toFixed(3)} kg ÷ ${maund} × Rs ${rate.toLocaleString()} = <b>Rs ${value.toLocaleString(undefined,{maximumFractionDigits:2})}</b><br>${detail}. Buying Brokery (${brokery.label}): <b>Rs ${brokery.amount.toLocaleString(undefined,{maximumFractionDigits:2})}</b>.`;
  }

  function relabelLegacyBillFields(bill){
    const input=q('#ttsbBrokerage');
    if(input?.closest('label'))input.closest('label').childNodes[0].nodeValue='Buying Brokery';
    const details=[...bill.querySelectorAll('details')].find(item=>/Brokerage|Brokery/i.test(item.querySelector('summary')?.textContent||''));
    if(details?.querySelector('summary'))details.querySelector('summary').textContent='Brokery / Tax ▾';
    const total=[...bill.querySelectorAll('.ttsb-kpi span')].find(item=>/Total Payable/i.test(item.textContent||''));
    if(total)total.textContent='Total Payable incl. Brokery';
    const helper=details?.querySelector('.helper');
    if(helper)helper.textContent='Buying Brokery is taken from the selected Soda broker’s profile. The figure is system-calculated and checked again before posting.';
  }

  async function install(){
    const bill=q('.ttsb-bill');
    if(!bill||q('#ttBrokeryPreview'))return;
    await load();
    const context=current();
    if(!context.soda)return;
    relabelLegacyBillFields(bill);
    const weight=context.rows.reduce((sum,row)=>sum+row.kg,0);
    const rate=activeBuyingBrokery(context);
    const recordedBags=context.rows.reduce((sum,row)=>sum+Number(row.bags||0),0);
    const perBag=rate?.basis==='PER_BAG'&&recordedBags<=0;
    const box=document.createElement('div');
    box.id=context.commodity==='RICE'?'ttBuyingBrokeryCalc':'ttCommodityKatCalc';
    box.className='ttsb-disclose';
    box.open=true;
    if(q('#ttsbRate')&&rate&&Number(q('#ttsbRate').value||0)===0&&['PER_100_KG','PER_50_KG_BAG','PER_MAUND'].includes(rate.basis)){q('#ttsbRate').value=String(rate.amount||0);q('#ttsbBasis').value=rate.basis;q('#ttsbRate').dispatchEvent(new Event('input',{bubbles:true}));}
    if(context.commodity==='RICE'){
      box.innerHTML=`<h3>Buying Brokery Reference</h3><div>${perBag?'<div class="ttsb-adjust"><label>Actual Bags<input id="ttBrokeryBags" type="number" min="0" step="1" value="0"></label></div>':''}<div id="ttBrokeryPreview" class="notice" style="margin-top:12px"></div></div>`;
    }else{
      const corn=context.commodity==='CORN';
      box.innerHTML=`<h3>${corn?'Corn / Makai':'Sesame'} KAT & Buying Brokery</h3><div><div class="ttsb-adjust"><label>Karachi Weighbridge Weight (kg)<input id="ttKatKarachiKg" type="number" min="0" step=".001" value="${weight}"></label>${corn?'<label>Moisture %<input id="ttKatMoisture" type="number" min="0" step=".01" value="13"></label><label>Damage / Fungus %<input id="ttKatDamage" type="number" min="0" step=".01" value="2"></label>':'<label>Admixture %<input id="ttKatAdmixture" type="number" min="0" step=".01" value="'+Number(data?.katMaster?.[context.profile]?.rules?.admixtureFreePct||(context.profile==='SESAME_RAW'?3:1))+'"></label>'}${perBag?'<label>Actual Bags<input id="ttBrokeryBags" type="number" min="0" step="1" value="0"></label>':''}<label>Other Deduction kg / 100 kg<input id="ttKatOther" type="number" min="0" step=".001" value="0"></label><label>Other Deduction Reason<input id="ttKatOtherReason"></label></div><div id="ttKatResult" class="notice" style="margin-top:12px"></div><div id="ttBrokeryPreview" class="notice" style="margin-top:8px"></div><div class="helper">The server recalculates from the approved KAT Master, Soda rate and Broker Brokery profile; typed totals are never trusted.</div></div>`;
    }
    const firstDisclosure=bill.querySelector('.ttsb-disclose');
    firstDisclosure?.insertAdjacentElement('beforebegin',box);
    box.querySelectorAll('input').forEach(input=>input.addEventListener('input',context.commodity==='RICE'?applyBrokeryPreview:calculateCommodity));
    if(context.commodity==='RICE')applyBrokeryPreview();else calculateCommodity();
  }

  window.fetch=async(input,init)=>{
    const url=typeof input==='string'?input:String(input?.url||'');
    if(/commodity_bills\.php(?:\?|$)/.test(url)&&String(init?.method||'GET').toUpperCase()==='POST'&&typeof init?.body==='string'){
      try{
        const body=JSON.parse(init.body);
        if(body.action==='verify_bill'){
          body.inspection=inspection();
          init={...init,body:JSON.stringify(body)};
        }
      }catch{}
    }
    return nativeFetch(input,init);
  };

  const updateHeading=()=>{
    const heading=qa('.ttsb-wrap .formCard h3').find(item=>/Rice\s*&\s*Corn Purchase Bills/i.test(item.textContent||''));
    if(heading)heading.textContent='All Commodity Purchase Bills';
  };
  const observer=new MutationObserver(()=>{
    updateHeading();
    if(q('.ttsb-bill')&&!q('#ttBrokeryPreview'))setTimeout(install,30);
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('change',event=>{
    if(event.target.matches('.ttsb-select')){
      data=null;
      setTimeout(install,100);
    }
  });
  updateHeading();
  setTimeout(install,600);
})();
