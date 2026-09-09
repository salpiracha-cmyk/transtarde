(()=>{
  'use strict';
  if(window.TTValidation)return;
  const nativeAlert=window.alert.bind(window);
  const validationWords=/\b(required|invalid|enter|select|complete|must|cannot|can not|missing|upload|choose|exceed|match|positive|valid|available|allowed|needed|empty|failed|could not|not found|incorrect|error)\b/i;
  const css=`
  .tt-invalid{border-color:#c62828!important;box-shadow:0 0 0 3px rgba(198,40,40,.12)!important;background:#fff7f7!important}
  .tt-invalid-wrap>label,.tt-invalid-wrap label:first-child{color:#b42318!important}
  .tt-validation-error-tab{border-color:#c62828!important;box-shadow:inset 0 0 0 2px #c62828!important;background:#fff1f1!important;color:#9f1d1d!important}
  .tt-validation-banner{display:flex;gap:9px;align-items:flex-start;margin:10px 0;padding:11px 13px;border:1px solid #ef9a9a;border-left:5px solid #c62828;border-radius:9px;background:#fff4f4;color:#8d1b1b;font:700 13px/1.35 Arial,sans-serif}
  .tt-validation-banner button{margin-left:auto;border:0;background:transparent;color:#8d1b1b;font-weight:900;cursor:pointer;padding:0 2px}
  .tt-field-error-text{display:block;color:#b42318;font:700 11px/1.3 Arial,sans-serif;margin-top:4px}
  `;
  const style=document.createElement('style');style.id='ttGlobalValidationStyle';style.textContent=css;document.head.appendChild(style);

  const visible=el=>!!(el&&el.isConnected&&el.getClientRects().length&&!el.disabled);
  const fieldWrap=el=>el?.closest?.('.field,[class*="field"],td,th,.r2>div,.r3>div,.r4>div,.r5>div,.row>div')||el?.parentElement;
  const contextFor=el=>el?.closest?.('.workspaceDetail,.section,.card,.panel,dialog,.modal,.tt-cm-panel,main')||document.querySelector('.panel.active,.workspaceDetail,main')||document.body;
  const tabFor=el=>{
    const ctx=el?.closest?.('.workspaceDetail,.panel.active,.section,.card');
    const key=ctx?.id||ctx?.dataset?.workspace||'';
    if(key){const byKey=document.querySelector(`[data-workspace="${CSS.escape(key)}"],[data-tab="${CSS.escape(key)}"],[onclick*="'${key}'"],[onclick*='"${key}"']`);if(byKey)return byKey}
    return document.querySelector('.workspaceTile.active,.workspaceTile.selected,.tab.active,.tab.sel,.tile.active,.tile.sel,.step.active,.nav .active');
  };
  function banner(message,ctx){
    ctx=ctx||contextFor(document.activeElement);let b=ctx.querySelector?.(':scope > .tt-validation-banner');
    if(!b){b=document.createElement('div');b.className='tt-validation-banner';ctx.prepend(b)}
    b.innerHTML=`<span>⚠</span><span>${String(message||'Please correct the highlighted fields.').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</span><button type="button" aria-label="Dismiss">×</button>`;
    b.querySelector('button').onclick=()=>b.remove();return b;
  }
  function clearField(el){if(!el)return;el.classList.remove('tt-invalid');const w=fieldWrap(el);w?.classList.remove('tt-invalid-wrap');w?.querySelector?.('.tt-field-error-text')?.remove()}
  function markField(el,message=''){if(!visible(el))return;el.classList.add('tt-invalid');const w=fieldWrap(el);w?.classList.add('tt-invalid-wrap');if(message&&w&&!w.querySelector('.tt-field-error-text')){const t=document.createElement('span');t.className='tt-field-error-text';t.textContent=message;w.appendChild(t)}}
  function markContext(el){const t=tabFor(el);if(t)t.classList.add('tt-validation-error-tab')}
  function clearContext(el){const t=tabFor(el);if(t&&!document.querySelector('.tt-invalid'))t.classList.remove('tt-validation-error-tab')}
  function words(s){return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').split(/\s+/).filter(w=>w.length>2&&!['the','and','for','with','this','that','must','required','enter','select','valid','please','before'].includes(w))}
  function labelText(el){const id=el.id,lab=id?document.querySelector(`label[for="${CSS.escape(id)}"]`):null;return String(lab?.textContent||fieldWrap(el)?.querySelector?.('label')?.textContent||el.getAttribute('aria-label')||el.placeholder||'').toLowerCase()}
  function inferTargets(message){const keys=words(message),els=[...document.querySelectorAll('input,select,textarea')].filter(visible);if(!keys.length)return[];return els.map(el=>({el,score:keys.reduce((n,k)=>n+(labelText(el).includes(k)?1:0),0)})).filter(x=>x.score>0).sort((a,b)=>b.score-a.score).slice(0,4).map(x=>x.el)}
  function fail(targets,message,ctx){let els=[];if(typeof targets==='string')els=[...document.querySelectorAll(targets)];else if(targets instanceof Element)els=[targets];else if(Array.isArray(targets))els=targets.flatMap(x=>typeof x==='string'?[...document.querySelectorAll(x)]:x instanceof Element?[x]:[]);els=els.filter(visible);if(!els.length)els=inferTargets(message);els.forEach(el=>markField(el));const anchor=els[0]||document.activeElement;markContext(anchor);banner(message,ctx||contextFor(anchor));if(els[0]){try{els[0].scrollIntoView({behavior:'smooth',block:'center'});els[0].focus({preventScroll:true})}catch{}}return false}
  function validateElement(el,onBlur=false){if(!visible(el))return true;const mandatory=el.required||el.dataset.required==='true'||/\*/.test(labelText(el));if(!mandatory&&!onBlur)return true;let bad=false;if(mandatory&&String(el.value??'').trim()==='')bad=true;else if(typeof el.checkValidity==='function'&&!el.checkValidity())bad=true;if(bad){markField(el,mandatory&&String(el.value??'').trim()===''?'Required':'Check this value');markContext(el);return false}clearField(el);clearContext(el);return true}
  document.addEventListener('invalid',e=>{e.preventDefault();markField(e.target,e.target.validationMessage||'Check this value');markContext(e.target);banner('Please correct the highlighted field.',contextFor(e.target))},true);
  document.addEventListener('focusout',e=>{if(e.target?.matches?.('input,select,textarea'))validateElement(e.target,true)},true);
  document.addEventListener('input',e=>{if(e.target?.matches?.('input,select,textarea')){clearField(e.target);clearContext(e.target)}},true);
  document.addEventListener('change',e=>{if(e.target?.matches?.('input,select,textarea')){clearField(e.target);clearContext(e.target);validateElement(e.target,false)}},true);
  document.addEventListener('submit',e=>{const fields=[...e.target.querySelectorAll('input,select,textarea')].filter(visible),bad=fields.filter(el=>!validateElement(el,false));if(bad.length){e.preventDefault();e.stopImmediatePropagation();fail(bad,'Please correct the highlighted fields before continuing.',e.target.closest('.panel,.card,dialog,.tt-cm-panel')||e.target)}},true);
  window.alert=function(message){const text=String(message??'');if(validationWords.test(text)){fail([],text);return}return nativeAlert(message)};
  window.TTValidation={fail,mark:markField,clear:clearField,banner,validateElement,nativeAlert};
})();
