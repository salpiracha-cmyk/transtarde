/* Explicit, isolated saves for the remaining operational Milling forms. */
(()=>{'use strict';
 const keys=new Set(['tt30queue','tt30slips','tt30directoralerts','tt37usedbags','tt33pettyexp','tt32stockadj','tt35localsales','tt35exportersale']);
 const canonical=x=>JSON.stringify(x&&typeof x==='object'?Array.isArray(x)?x.map(v=>JSON.parse(canonical(v))):Object.fromEntries(Object.keys(x).sort().map(k=>[k,JSON.parse(canonical(x[k]))])):x);
 const same=(a,b)=>canonical(a)===canonical(b);
 const confirms=(found,want)=>found&&Object.keys(want).every(k=>same(found[k],want[k]));
 const rows=v=>{const a=JSON.parse(v||'[]');if(!Array.isArray(a))throw Error('Saved operational records are invalid.');return a;};
 async function commit({endpoint,access,key,record,before=null,prefix=''}){
  const identity=r=>r?.id??(key==='tt35localsales'?r?.soda:key==='tt35exportersale'?r?.ref:undefined),refField=key==='tt35localsales'?'soda':key==='tt35exportersale'?'ref':'slip';
  if(!keys.has(key)||!identity(record))throw Error('A supported record and stable identity are required.');
  const response=await fetch(endpoint+'?asset_refs=1&r='+Date.now(),{credentials:'same-origin'}),snapshot=await response.json();
  if(!response.ok||!snapshot.ok||(snapshot.restrictedKeys||[]).includes(key))throw Error(snapshot.error||'Saved operational records could not be checked.');
  const current=rows(snapshot.values?.[key]),matches=current.filter(r=>String(identity(r))===String(identity(record)));
  const comparable=r=>{const x={...r};if(prefix)delete x[refField];return x;};
  if(matches.length===1&&confirms(comparable(matches[0]),comparable(record)))return {record:matches[0],value:JSON.stringify(current),version:Number(snapshot.meta?.[key]?.version||0),related:key==='tt32stockadj'?{tt32labourfeed:{value:snapshot.values?.tt32labourfeed||'[]',version:Number(snapshot.meta?.tt32labourfeed?.version||0)}}:{}};
  if(matches.length>1||(before?!same(matches[0],before):matches.length))throw Object.assign(Error('This record changed elsewhere. Reload and review it before amending.'),{editable:true});
  const candidate=JSON.parse(JSON.stringify(record));
  if(prefix&&!before){candidate[refField]=prefix+String(current.reduce((n,r)=>Math.max(n,Number(String(r[refField]||'').replace(prefix,''))||0),0)+1).padStart(5,'0');}
  if(before)current[current.indexOf(matches[0])]=candidate;else current.push(candidate);
  const write=await fetch(endpoint+'?asset_refs=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:access.csrf,key,value:JSON.stringify(current),baseVersion:Number(snapshot.meta?.[key]?.version||0),sourceModule:access.module})}),saved=await write.json();
  if(!write.ok||!saved.ok)throw Object.assign(Error(saved.conflict?'Another record was saved meanwhile. Retry to check the current records.':saved.error||'The save was not confirmed.'),{editable:[403,422].includes(write.status)});
  const value=typeof saved.value==='string'?saved.value:JSON.stringify(current),confirmed=rows(value).filter(r=>String(identity(r))===String(identity(candidate)));
  if(confirmed.length!==1||!confirms(confirmed[0],candidate))throw Error('The server did not confirm these details. Retry the same save.');
  return {record:confirmed[0],value,version:Number(saved.keyVersion||0),related:saved.related||{}};
 }
 const access=()=>window.TT_MODULE_ACCESS||{};
 const tokenFor=(key,record)=>'tt_ui_mill_action_'+access().userId+'_'+key+'_'+(record.entity||'TTI')+'_'+(record.millId||record.millName||record.locationName||'');
 const signature=(record,before)=>{const r={...record};for(const k of ['id','time','clientRequestId','slip',...(before?[]:['soda','bridgeId'])])delete r[k];if(!before&&Array.isArray(r.containers)&&/^ES-/.test(r.ref||''))delete r.ref;if(Array.isArray(r.containers))r.containers=r.containers.map(({id,...c})=>c);if(Array.isArray(r.pay))r.pay=r.pay.map(({bridgeId,...p})=>p);if(Array.isArray(r.loads))r.loads=r.loads.map(({bridgeId,gatePassRef,...p})=>p);return canonical({record:r,before});};
 let busy=false;
 function pendingPanel(token,intent){
  document.querySelectorAll('[data-mill-action-recovery]').forEach(e=>{if(e.dataset.millActionRecovery===token)e.remove();});
  const host=document.querySelector('.panel.active');if(!host)return;
  const box=document.createElement('section');box.className='card';box.dataset.millActionRecovery=token;
  const note=document.createElement('p');note.textContent='Previous '+intent.label+' save awaiting confirmation. Confirm it before entering another record; your current draft will be retained.';
  const button=document.createElement('button');button.textContent='Confirm previous save';box.append(note,button);host.prepend(box);
  button.onclick=async()=>{if(busy)return;busy=true;button.disabled=true;try{await window.TT_SHARED_SYNC.commitMillAction(intent.key,intent.record,intent.before,intent.prefix);localStorage.removeItem(token);note.textContent=intent.label+' saved. Reopen the screen to review it. Your current draft is retained.';button.remove();}catch(e){note.textContent=e.message;button.disabled=false;}finally{busy=false;}};
 }
 function showRecovery(){for(let i=0;i<localStorage.length;i++){const token=localStorage.key(i);if(!token.startsWith('tt_ui_mill_action_'+access().userId+'_'))continue;try{const intent=JSON.parse(localStorage.getItem(token));const mill=typeof currentMill==='undefined'?null:currentMill;if(mill&&String(intent.record.millId||'')&&String(mill.id)!==String(intent.record.millId))continue;pendingPanel(token,intent);}catch{}}}
 async function save(key,record,{before=null,prefix='',label='Operational record',onSaved}={}){
  if(busy)return;
  if(!access().userId||!window.TT_SHARED_SYNC?.commitMillAction){alert('Reload Milling before saving. Your entry is retained.');return;}
  const token=tokenFor(key,record),sig=signature(record,before);let intent;
  try{intent=JSON.parse(localStorage.getItem(token)||'null');}catch{alert('The previous save needs review. Your entry is retained.');return;}
  if(intent&&intent.signature!==sig){if(intent.editable)intent=null;else{pendingPanel(token,intent);alert('Confirm the previous save first. Your current entry is retained.');return;}}
  if(!intent)intent={key,label,prefix,before,signature:sig,record:{...record,id:before?record.id:Date.now()*1000+Math.floor(Math.random()*1000)}};
  try{localStorage.setItem(token,JSON.stringify(intent));}catch{alert('The retry identity could not be retained. No request was sent.');return;}
  busy=true;const form=document.querySelector('.panel.active'),wasInert=form?.inert;if(form)form.inert=true;
  try{const saved=await window.TT_SHARED_SYNC.commitMillAction(key,intent.record,before,prefix);localStorage.removeItem(token);document.querySelectorAll('[data-mill-action-recovery]').forEach(e=>{if(e.dataset.millActionRecovery===token)e.remove();});try{onSaved?.(saved);}catch{alert(label+' is saved. Reopen the screen to refresh its display.');}return saved;}
  catch(e){if(e.editable){intent.editable=true;localStorage.setItem(token,JSON.stringify(intent));}pendingPanel(token,intent);alert(e.message+' Your entry is retained. Retry Save to confirm the same record.');}
  finally{busy=false;if(form)form.inert=wasInert;}
 }
 window.TT_MILL_ACTION_SAVE={commit,save,showRecovery};
})();
