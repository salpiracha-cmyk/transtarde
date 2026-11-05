/* A resumed Mill tab must confirm its session before accepting entry. No record posts. */
(()=>{'use strict';
 if(window.TT_MODULE_ACCESS?.moduleId!=='milling')return;
 const IDLE_MS=15*60*1000,nativeFetch=window.fetch.bind(window);
 let ready=false,expired=false,checking=null,deadline=0,lastInteraction=Date.now(),needsCheck=true,oldInert=false,ownedMain=null,previousFocus=null;
 const panelId='ttMillSessionGate',dialogId='ttMillIdleDialog',logoutUrl='/logout.php?reason=inactive';
 let logoutConfirmed=false;
 function lock(text='',retry=false){
  ready=false;
  const main=document.querySelector('main');if(main&&ownedMain!==main){ownedMain=main;oldInert=!!main.inert;previousFocus=main.contains(document.activeElement)?document.activeElement:null;main.inert=true;}
  if(!text){document.getElementById(panelId)?.remove();return;}
  if(!document.body)return;
  let panel=document.getElementById(panelId);if(!panel){panel=document.createElement('section');panel.id=panelId;panel.setAttribute('role','status');panel.style.cssText='position:fixed;top:0;left:0;right:0;z-index:100400;background:#fff6f4;color:#8f1d15;padding:10px 18px;border-bottom:2px solid #b42318;font:15px Arial';const title=document.createElement('strong');title.textContent='Milling';const message=document.createElement('p');message.dataset.sessionMessage='';message.setAttribute('role','status');const again=document.createElement('button');again.type='button';again.dataset.sessionRetry='';again.textContent='Retry session check';again.onclick=()=>void verify();panel.append(title,message,again);document.body.append(panel);}
  panel.querySelector('[data-session-message]').textContent=text;panel.querySelector('[data-session-retry]').hidden=!retry||expired;
 }
 function unlock(){if(expired)return;ready=true;needsCheck=false;document.getElementById(panelId)?.remove();if(ownedMain){ownedMain.inert=oldInert;ownedMain=null;if(!oldInert&&previousFocus?.isConnected)previousFocus.focus({preventScroll:true});previousFocus=null;}}
 function goToSignIn(){window.location.replace(logoutConfirmed?'/login.php?expired=inactive':logoutUrl);}
 function end(){
  if(expired)return;
  expired=true;lock();
  try{window.TT_SESSION_BEFORE_REDIRECT?.({url:logoutUrl});}catch{/* A draft recovery error must leave entry locked. */}
  void nativeFetch(logoutUrl,{credentials:'same-origin',cache:'no-store',redirect:'manual',keepalive:true})
   .then(response=>{logoutConfirmed=response.type==='opaqueredirect';}).catch(()=>{});
  const dialog=document.createElement('dialog');dialog.id=dialogId;
  dialog.setAttribute('aria-labelledby','ttMillIdleTitle');
  dialog.style.cssText='width:min(24rem, calc(100% - 3rem));padding:1.25rem;border:1px solid #ccd6c8;border-radius:12px;color:#203022;background:#fff;box-shadow:0 12px 40px #0005';
  const heading=document.createElement('h2');heading.id='ttMillIdleTitle';heading.textContent='Session expired';
  const message=document.createElement('p');message.textContent='You were signed out after 15 minutes of inactivity. Select OK to sign in again.';
  const ok=document.createElement('button');ok.type='button';ok.className='btn';ok.textContent='OK';ok.onclick=goToSignIn;
  dialog.append(heading,message,ok);document.body.append(dialog);
  dialog.addEventListener('cancel',event=>{event.preventDefault();goToSignIn();});
  try{history.pushState(null,'',location.href);}catch{/* A restored protected page is still checked. */}
  if(typeof dialog.showModal==='function')dialog.showModal();else dialog.open=true;
  ok.focus();
 }
 function checkIdle(){if(!expired&&Date.now()-lastInteraction>=IDLE_MS)end();}
 function verify(){
  if(expired)return Promise.resolve(false);if(checking)return checking;
  lock();
  const controller=new AbortController(),checkedAt=Date.now(),timer=setTimeout(()=>controller.abort(),12000);
  checking=Promise.resolve().then(async()=>{try{
   const response=await nativeFetch('/api/session_activity.php',{method:'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal});
   if(response.status===401||response.status===403){end();return false;}
   const data=await response.json();if(!response.ok||!data?.ok||data.idleTimeoutSeconds!==IDLE_MS/1000||!Number.isInteger(data.idleRemainingSeconds)||data.idleRemainingSeconds<=0||data.idleRemainingSeconds>IDLE_MS/1000)throw Error('Session check unavailable');
   if(expired)return false;deadline=checkedAt+data.idleRemainingSeconds*1000;if(deadline<=Date.now()){end();return false;}lastInteraction=deadline-IDLE_MS;unlock();return true;
  }catch{if(!expired){needsCheck=true;window.TT_ARRIVAL_DEVICE_GUARD?.preserve?.();window.TT_PRODUCTION_RECOVERY?.preserveDeviceDraft?.();lock('Unable to confirm your sign-in. Entry is locked. Your existing details have been kept. Check the connection and retry.',true);}return false;
  }finally{clearTimeout(timer);checking=null;}});return checking;
 }
 function guard(event){
  if(event.target?.closest?.('#'+dialogId))return;
  checkIdle();
  if(!expired&&event.target?.closest?.('#'+panelId+',#arrivalSessionLock'))return;
  const now=Date.now();if(!ready||needsCheck||now>=deadline||now-lastInteraction>=IDLE_MS){
   if(event.cancelable)event.preventDefault();event.stopImmediatePropagation();if(!expired&&!checking&&!document.getElementById(panelId))void verify();return;
  }
  if(['pointerdown','keydown','input','change','touchstart','scroll'].includes(event.type))lastInteraction=now;
 }
 // Installed in the head, before native handlers or the shared activity clock.
 for(const name of ['pointerdown','keydown','beforeinput','input','change','touchstart','scroll','click','submit'])window.addEventListener(name,guard,{capture:true,passive:false});
 document.addEventListener('visibilitychange',()=>{if(document.hidden)needsCheck=true;else{checkIdle();if(needsCheck)void verify();}});
 window.addEventListener('focus',()=>{checkIdle();if(needsCheck||Date.now()-lastInteraction>=60000)void verify();});
 window.addEventListener('pageshow',event=>{if(event.persisted){if(expired){goToSignIn();return;}needsCheck=true;void verify();}});
 window.addEventListener('popstate',()=>{checkIdle();if(expired)goToSignIn();});
 window.addEventListener('offline',()=>{if(expired)return;needsCheck=true;window.TT_ARRIVAL_DEVICE_GUARD?.preserve?.();window.TT_PRODUCTION_RECOVERY?.preserveDeviceDraft?.();lock('Connection unavailable. Entry is locked until your sign-in can be checked. Existing details have been kept.',true);});
 window.addEventListener('online',()=>{if(needsCheck)void verify();});
 const prior=window.TT_SESSION_BEFORE_REDIRECT;window.TT_SESSION_BEFORE_REDIRECT=details=>{window.TT_PRODUCTION_RECOVERY?.preserveDeviceDraft?.();prior?.(details);return true;};
 setInterval(checkIdle,1000);
 window.TT_MILL_SESSION={verify,expire:end,ready:()=>ready&&!expired,activityConfirmed:at=>{if(ready&&!expired)deadline=(Number.isFinite(at)?Math.min(at,Date.now()):Date.now())+IDLE_MS;}};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>void verify());else void verify();
})();
