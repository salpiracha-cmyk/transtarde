(()=>{
'use strict';
const icons=[['dashboard','Directors Dashboard'],['tgmaster','TG Master'],['alerts','Alerts & Renewals'],['approvals','Approvals'],['reports','Management Reports']];
const actions=['View','Create','Edit'];
let users=null,loading=false;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function loadUsers(){if(users||loading)return;loading=true;try{const r=await fetch('api/users.php',{credentials:'same-origin',headers:{Accept:'application/json'}});const d=await r.json();if(r.ok&&d.ok)users=d.users||[]}catch{}finally{loading=false}}
function checked(saved,icon,action){if(saved==='all')return true;if(Array.isArray(saved))return saved.includes(action);return Array.isArray(saved?.[icon])&&saved[icon].includes(action)}
async function inject(){const matrix=document.getElementById('permissionMatrix');if(!matrix||matrix.querySelector('[data-directors-permission-section]'))return;await loadUsers();const id=document.getElementById('editUserId')?.value||'',user=(users||[]).find(u=>String(u.id)===String(id)),saved=user?.permissions?.Directors||{};const sec=document.createElement('section');sec.className='permission-module';sec.dataset.directorsPermissionSection='1';sec.innerHTML=`<div class="permission-module-head"><strong>Directors</strong><label><input type="checkbox" data-select-module="Directors"> Select all Directors</label></div><div class="permission-row header"><strong>Icon / Screen</strong>${actions.map(a=>`<span>${a}</span>`).join('')}</div>${icons.map(([icon,label])=>`<div class="permission-row"><strong>${esc(label)}</strong>${actions.map(a=>`<label title="Directors · ${esc(label)} · ${a}"><input type="checkbox" data-permission-module="Directors" data-permission-icon="${icon}" value="${a}" ${checked(saved,icon,a)?'checked':''}></label>`).join('')}</div>`).join('')}`;matrix.appendChild(sec)}
const mo=new MutationObserver(()=>setTimeout(inject,0));const start=()=>{const m=document.getElementById('permissionMatrix');if(m)mo.observe(m,{childList:true,subtree:false});inject()};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
document.addEventListener('click',e=>{if(e.target.closest('[data-open-user]')||e.target.closest('#addUser')||e.target.closest('[data-edit-user]')){users=null;setTimeout(inject,80)}},true);
})();
