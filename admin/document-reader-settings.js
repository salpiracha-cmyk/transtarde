(()=>{
'use strict';
const root=document.getElementById('tiers');const tiers=window.READER_SETTINGS.tiers.map(t=>({...t}));
function render(){root.replaceChildren();tiers.forEach((tier,i)=>{
 const row=document.createElement('div');row.className='tier';
 const title=document.createElement('strong');title.textContent='Tier '+(i+1);row.append(title);
 const providerLabel=document.createElement('label');providerLabel.textContent='Provider';
 const select=document.createElement('select');for(const [value,text] of [['openai','OpenAI'],['gemini','Gemini']]){const o=new Option(text,value);select.add(o);}select.value=tier.provider;select.onchange=()=>{tier.provider=select.value;tier.model='';model.value='';};providerLabel.append(select);row.append(providerLabel);
 const modelLabel=document.createElement('label');modelLabel.textContent='Model ID';modelLabel.className='model';const model=document.createElement('input');model.value=tier.model;model.required=true;model.pattern='[A-Za-z0-9._-]{3,100}';model.placeholder='Enter exact model ID';model.oninput=()=>tier.model=model.value.trim();modelLabel.append(model);row.append(modelLabel);
 const enabledLabel=document.createElement('label');enabledLabel.textContent='Enabled';const enabled=document.createElement('input');enabled.type='checkbox';enabled.checked=tier.enabled;enabled.onchange=()=>tier.enabled=enabled.checked;enabledLabel.append(enabled);row.append(enabledLabel);
 const actions=document.createElement('div');actions.className='actions';
 for(const [label,aria,disabled,fn] of [['↑','Move tier up',i===0,()=>{[tiers[i-1],tiers[i]]=[tiers[i],tiers[i-1]];render();}],['↓','Move tier down',i===tiers.length-1,()=>{[tiers[i+1],tiers[i]]=[tiers[i],tiers[i+1]];render();}],['−','Remove tier',tiers.length===1,()=>{tiers.splice(i,1);render();}]]){const b=document.createElement('button');b.type='button';b.textContent=label;b.setAttribute('aria-label',aria);b.disabled=disabled;b.onclick=fn;if(label==='−')b.className='remove';actions.append(b);}row.append(actions);root.append(row);
 });document.getElementById('addTier').disabled=tiers.length>=20;}
document.getElementById('addTier').onclick=()=>{tiers.push({provider:'gemini',model:'',enabled:true});render();};
document.getElementById('readerForm').onsubmit=e=>{if(e.submitter?.value==='check'){e.submitter.textContent='Checking connections…';return;}const ids=tiers.map(t=>t.provider+':'+t.model);if(new Set(ids).size!==ids.length){e.preventDefault();alert('The same provider and model cannot appear twice.');return;}document.getElementById('tiersPayload').value=JSON.stringify(tiers);};render();
})();
