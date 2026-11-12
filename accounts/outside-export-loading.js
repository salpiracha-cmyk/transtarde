(()=>{
'use strict';
function mount(host,cfg){
const q=id=>host.querySelector('#'+id);
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const date=value=>/^\d{4}-\d{2}-\d{2}$/.test(value||'')?value.split('-').reverse().join('-'):value||'';
const status=(message,error=false)=>{q('oelStatus').textContent=message;q('oelStatus').classList.toggle('dtError',error);};
const api='/api/outside_export_loading.php?origin='+cfg.origin;
let data=null,selected=cfg.lot||new URLSearchParams(location.search).get('lot')||'',busy=false;
async function request(body){
 const multipart=body instanceof FormData;
 if(multipart)body.set('csrf',cfg.csrf);
 const r=await fetch(api,body?{method:'POST',...(multipart?{body}:{headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf:cfg.csrf})})}:{cache:'no-store'});
 const result=await r.json();
 if(!r.ok||!result.ok)throw Error(result.error||'Loading is unavailable.');
 return result;
}
async function refresh(){
 if(busy)return;
 busy=true;
 status('Loading assignments…');
 try{data=await request();render();status('');}catch(error){status(error.message,true);}finally{busy=false;}
}
const options=(rows,key,label,value)=>'<option value="">Choose</option>'+rows.map(r=>`<option value="${esc(r[key])}" ${String(r[key])===String(value)?'selected':''}>${esc(r[label])}</option>`).join('');
const field=(name,label,value='',type='text',attrs='')=>`<label>${esc(label)}<input name="${name}" type="${type}" value="${esc(value)}" ${attrs}></label>`;
function render(){
 const root=q('oelWorkspace');
 if(!data)return;
 const row=data.rows.find(r=>r.id===selected);
 if(!row){
  if(cfg.lot){root.innerHTML='<p>This lot is unavailable in your loading permissions.</p>';return;}
  const search=(cfg.search||q('oelSearch'))?.value.trim().toLowerCase().replace(/-/g,'')||'';
  const list=data.rows.filter(r=>[r.contract,r.lot,r.site,r.brand,r.assignment.supplier,...r.actuals.map(a=>a.number)].some(v=>String(v||'').toLowerCase().replace(/-/g,'').includes(search)));
  root.innerHTML=list.map(r=>`<p><button type="button" data-lot="${esc(r.id)}">${esc(r.contract)} · ${esc(r.lot)} · ${esc(r.site)}</button> ${r.actuals.length}/${r.expected} · ${r.complete?'Complete':r.assignment.route?'Assigned':'Awaiting assignment'}</p>`).join('')||(cfg.milling&&!search?'<p>No outside loads are assigned to your login.</p>':'<p>No matching outside loads.</p>');
  root.querySelectorAll('[data-lot]').forEach(b=>b.onclick=()=>{selected=b.dataset.lot;render();});
  return;
 }
 const assignment=row.assignment,assigned=assignment.route===(cfg.origin==='mill'?'MILL':'EXPORTS')&&Number(assignment.assigneeId)===data.userId;
 const assign=row.canEdit&&!row.closed&&!row.actuals.length&&cfg.origin==='exports';
 const automaticDeal=assign&&!assignment.route&&cfg.preferredRoute==='EXPORTS'&&row.deals.length===1?row.deals[0]:null;
 const edit=row.canEdit&&!row.closed&&!row.complete&&(assigned||automaticDeal);
 root.innerHTML=`${cfg.lot?'':'<button type="button" id="oelBack">← Loading list</button>'}
 <h2>${esc(row.contract)} · ${esc(row.lot)}</h2><p><strong>${esc(row.site)}</strong> · ${esc(row.entity)} · Planned ${esc(date(row.plannedDate))} · ${row.actuals.length}/${row.expected} containers</p>
 <p>${esc(row.brand)} · ${esc(row.packing)} · ${esc(assignment.supplier||automaticDeal?.label||'Awaiting SODA assignment')} · ${esc(assignment.assignee||'Unassigned')}</p>
 ${row.quality||row.specifications.length?`<details open><summary>Production and quality specifications</summary><p>${esc(row.quality)}</p>${row.specifications.map(spec=>`<p>${esc(spec.name)} · ${esc(spec.value)}</p>`).join('')}</details>`:''}
 ${assign&&!row.deals.length?'<p class="notice warn">Directors must link an active purchase SODA to this sales contract before containers can be recorded.</p>':assign&&!automaticDeal?`<form id="oelAssign"><div class="${cfg.milling ? 'r3' : 'grid'}">
 ${row.deals.length===1?`<input type="hidden" name="dealId" value="${esc(row.deals[0].id)}">`:`<label>Purchase SODA<select name="dealId" required>${options(row.deals,'id','label',assignment.dealId)}</select></label>`}
 <label>Who enters loading<select name="route"><option value="EXPORTS">Exports · assign to me</option><option value="MILL" ${(assignment.route||cfg.preferredRoute)==='MILL'?'selected':''}>TTI Mill staff · on site</option></select></label>
 <label id="oelAssignee">Assigned Mill user<select name="assigneeId">${options(data.staff.filter(s=>s.entities.includes(row.entity)),'id','name',assignment.assigneeId)}</select></label></div><button>Save assignment</button></form>`:''}
 ${row.actuals.length?`<div class="tableWrap"><table><thead><tr><th>Date</th><th>Container / seal</th><th>Truck</th><th>Bags</th><th>Net kg</th><th>Gross kg</th><th>Entered by</th><th>Evidence / inspection</th></tr></thead><tbody>${row.actuals.map((a,i)=>`<tr><td>${esc(date(a.loadingDate))}</td><td>${edit?`<button type="button" data-edit="${i}">${esc(a.number)}</button>`:esc(a.number)} / ${esc(a.seal)}</td><td>${esc(a.truck)}</td><td>${esc(a.bags)}</td><td>${esc(a.netKg)}</td><td>${esc(a.grossKg)}</td><td>${esc(a.enteredBy)}</td><td>${esc(a.evidence)}<br>${esc(a.inspection)}${a.evidenceFile?`<br><a href="${api}&amp;evidence=${encodeURIComponent(a.evidenceFile.id)}">${esc(a.evidenceFile.name)}</a>`:''}</td></tr>`).join('')}</tbody></table></div>`:''}
 <div id="oelContainer"></div>${edit&&row.actuals.length===row.expected?'<p><button type="button" id="oelComplete">Confirm loading complete</button></p>':''}
 ${row.complete?'<p><strong>Loading complete.</strong> The confirmed containers are available in Exports.</p>':''}`;
 if(q('oelBack'))q('oelBack').onclick=()=>{selected='';render();};
 if(q('oelAssign')){
  const form=q('oelAssign'),route=form.elements.route,assignee=form.elements.assigneeId;
  const sync=()=>{const mill=route.value==='MILL';q('oelAssignee').hidden=!mill;assignee.required=mill;assignee.disabled=!mill;};
  route.onchange=sync;sync();
  form.onsubmit=e=>{e.preventDefault();save(row,'assign',Object.fromEntries(new FormData(form)),form.querySelector('button'));};
 }
 if(edit){
  function container(a={}){
   const editing=!!a.number,finalContainer=cfg.origin==='exports'&&!editing&&row.actuals.length+1===row.expected;
   q('oelContainer').innerHTML=`<form id="oelActual"><h3>${editing?'Correct container details':'Add container'}</h3><div class="${cfg.milling ? 'r3' : 'grid'}">
    ${field('number','Container number',a.number,'text',editing?'readonly':'required maxlength="16"')}
    ${field('seal','Seal',a.seal,'text','required maxlength="40"')}
    ${field('site','Loading location',row.site,'text','readonly')}
    ${field('bags','Bags',a.bags,'number','required min="1" step="1"')}
    ${field('netKg','Net kg',a.netKg,'number','required min=".001" step=".001"')}
    ${field('grossKg','Gross kg',a.grossKg,'number','required min=".001" step=".001"')}
    ${field('date','Actual loading date',a.loadingDate||data.today,'date','required')}
    ${field('truck','Truck',a.truck,'text','required maxlength="40"')}
    <label>Loading sheet / weighbridge / gate-pass reference<textarea name="evidence" maxlength="500" required>${esc(a.evidence)}</textarea></label><label>Attach evidence (PDF or image)<input type="file" name="evidenceUpload" accept="application/pdf,image/png,image/jpeg,image/webp"></label>
    ${cfg.origin==='mill'?`<label>Measured quality and inspection findings<textarea name="inspection" maxlength="2000" required>${esc(a.inspection)}</textarea></label><label><input type="checkbox" name="qualityAccepted" value="yes" required> Quality meets the lot specification</label>`:''}
    </div><button>${finalContainer?'Save last container &amp; confirm loading':'Save container'}</button></form>`;
   const form=q('oelActual');form.onsubmit=e=>{e.preventDefault();const values=new FormData(form);values.delete('site');if(automaticDeal)values.set('dealId',automaticDeal.id);if(finalContainer)values.set('confirmComplete','yes');save(row,'container',values,form.querySelector('button'));};
  }
  if(row.actuals.length<row.expected)container();
  root.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>container(row.actuals[Number(b.dataset.edit)]));
  if(q('oelComplete'))q('oelComplete').onclick=()=>{if(confirm('Confirm the recorded containers and loading evidence are complete?'))save(row,'complete',{},q('oelComplete'));};
 }
}
async function save(row,action,fields,button){
 if(busy)return;
 busy=true;button.disabled=true;
 try{
 const payload={action,lotId:row.id,version:row.assignment.version||0};
 if(fields instanceof FormData)Object.entries(payload).forEach(([key,value])=>fields.set(key,value));
 data=await request(fields instanceof FormData?fields:{...fields,...payload});render();status('Saved.');
 if(cfg.onSaved)try{await cfg.onSaved();}catch{status('Saved. Refresh Exports before opening shipment documents.',true);}}
 catch(error){status(error.message,true);button.disabled=false;}finally{busy=false;}
}
if(q('oelRefresh'))q('oelRefresh').onclick=refresh;
if(q('oelSearch'))q('oelSearch').oninput=()=>{if(!selected)render();};
refresh();
return {refresh:()=>{if(cfg.milling&&q('oelActual')){status('Return to the loading list before refreshing.');return;}return refresh();}, reopen:()=>{if(!q('oelActual')){render();return refresh();}}, filter:()=>{if(!selected)render();}};
}
window.TT_OUTSIDE_LOADING_VIEW={mount};
if(window.TT_OUTSIDE_LOADING)mount(document,window.TT_OUTSIDE_LOADING);
})();
