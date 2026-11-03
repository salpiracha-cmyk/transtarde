/* Explicit, isolated and acknowledged Milling setup; never flush another form. */
(()=>{'use strict';
 const keys=['tt30arrivaldefaults','tt38labourrates','tt38costmaster','tt39rentmaster','tt39salarymaster'],baseline=new Map();let busy=false;
 const ordered=v=>Array.isArray(v)?v.map(ordered):v&&typeof v==='object'?Object.fromEntries(Object.keys(v).sort().map(k=>[k,ordered(v[k])])):v,same=(a,b)=>JSON.stringify(ordered(a))===JSON.stringify(ordered(b));
 const access=()=>window.TT_MODULE_ACCESS||{},token=()=> 'tt_ui_mill_settings_pending_'+access().userId;
 const parse=v=>v===null?null:JSON.parse(v);
 const signature=entries=>JSON.stringify(ordered(entries.map(({key,value})=>({key,value:key==='tt39salarymaster'?value.map(({id,updated,...r})=>r):value}))));
 function remember(){for(const key of keys)baseline.set(key,window.TT_SHARED_SYNC?.readCommitted(key)??null);const d=parse(baseline.get('tt30arrivaldefaults'))||{moisture:15,damage:2,chalky:5,paddy:80};for(const [id,key]of [['masterMoisture','moisture'],['masterDamage','damage'],['masterChalky','chalky'],['masterPaddy','paddy']]){const input=document.getElementById(id);if(input)input.value=d[key];}showRecovery();}
 async function commit({endpoint,access,entries,onConfirmed}){
  const result=[];
  for(const entry of entries){if(!keys.includes(entry.key))throw Error('Unsupported Milling setup.');const key=entry.key;
   const read=await fetch(endpoint+'?asset_refs=1&r='+Date.now(),{credentials:'same-origin'}),source=await read.json();
   if(!read.ok||!source.ok||(source.restrictedKeys||[]).includes(key))throw Error(source.error||'The saved setup could not be reviewed.');
   const absent=source.values&&source.meta&&!Object.prototype.hasOwnProperty.call(source.values,key)&&!Object.prototype.hasOwnProperty.call(source.meta,key);
   if(!absent&&(typeof source.values?.[key]!=='string'||!Number.isInteger(source.meta?.[key]?.version)))throw Error('The setup source/version was not confirmed.');
   const current=absent?null:parse(source.values[key]),version=absent?0:source.meta[key].version;
   let row={key,value:JSON.stringify(current),version};
   if(!same(current,entry.value)){
    if(!same(current,parse(entry.before)))throw Object.assign(Error('This setup changed since it was opened. Reopen Masters and review before saving. Your fields are retained.'),{editable:true});
    const response=await fetch(endpoint+'?asset_refs=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:access.csrf,key,value:JSON.stringify(entry.value),baseVersion:version,sourceModule:access.module})}),saved=await response.json();
    if(!response.ok||!saved.ok)throw Object.assign(Error(saved.error||'Setup was not confirmed.'),{editable:[403,409,422].includes(response.status)});
    if(typeof saved.value!=='string'||!Number.isInteger(saved.keyVersion)||saved.keyVersion<=version||!same(parse(saved.value),entry.value))throw Error('The setup acknowledgement is incomplete. Confirm the same save before entering different values.');
    row={key,value:saved.value,version:saved.keyVersion};
   }
   result.push(row);onConfirmed?.(row);
  }
  return result;
 }
 function showRecovery(){const host=document.getElementById('masters');if(!host)return;host.querySelector('[data-mill-settings-recovery]')?.remove();let intent;try{intent=JSON.parse(localStorage.getItem(token())||'null');}catch{}if(!intent)return;const panel=document.createElement('section');panel.className='card';panel.dataset.millSettingsRecovery='';panel.innerHTML='<p role="status">Previous setup save needs confirmation. Some settings may already be saved. Your current fields will be kept.</p><button type="button">Confirm previous setup save</button>';panel.querySelector('button').onclick=()=>run(intent,null,panel);host.prepend(panel);}
 async function run(intent,onSaved,panel=null){if(busy)return;busy=true;const host=document.getElementById('masters'),wasInert=host?.inert;if(host)host.inert=true;try{localStorage.setItem(token(),JSON.stringify(intent));const rows=await window.TT_SHARED_SYNC.commitMillSettings(intent.entries);localStorage.removeItem(token());for(const row of rows)baseline.set(row.key,row.value);host?.querySelector('[data-mill-settings-recovery]')?.remove();try{onSaved?.();}catch{alert('Setup confirmed on server. Reopen Masters to refresh its display.');}if(!onSaved)alert('Previous setup save confirmed on server. Your current fields are retained.');return rows;}catch(error){if(error.editable){intent.editable=true;try{localStorage.setItem(token(),JSON.stringify(intent));}catch{}}showRecovery();alert(error.message+' Your setup entry is retained.');}finally{busy=false;if(host)host.inert=wasInert;}}
 async function save(values,onSaved){
  if(!access().userId||!window.TT_SHARED_SYNC?.commitMillSettings){alert('Reload Milling before saving setup. Your entry is retained.');return;}
  const entries=values.map(([key,value])=>({key,value,before:baseline.get(key)??null})),sig=signature(entries);let intent;
  try{intent=JSON.parse(localStorage.getItem(token())||'null');if(intent&&signature(intent.entries)!==sig){if(!intent.editable){showRecovery();alert('Confirm the previous setup save before saving different values. Your current fields are retained.');return;}localStorage.setItem(token()+'_history_'+Date.now(),JSON.stringify(intent));intent=null;}}catch{alert('The previous setup save could not be retained. No request was sent.');return;}
  return run(intent||{entries},onSaved);
 }
 window.TT_MILL_SETTINGS_SAVE={commit,save,remember};
 const original=window.openPanel;if(typeof original==='function')window.openPanel=function(id){const result=original.apply(this,arguments);if(id==='masters')remember();return result;};
})();
