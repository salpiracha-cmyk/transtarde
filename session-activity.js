(()=>{
  'use strict';
  const IDLE_MS=60*60*1000;
  const HEARTBEAT_MS=60*1000;
  const KEY='tt_session_last_activity_v1';
  let lastActivity=Date.now();
  let lastHeartbeat=0;
  let signingOut=false;

  const access=()=>window.TT_SESSION||window.TT_MODULE_ACCESS||window.TT_ACCOUNT_ACCESS||window.TT_DIRECTORS_ACCESS||{};
  const csrf=()=>String(access().csrf||'');
  const readShared=()=>{
    try{
      const value=Number(localStorage.getItem(KEY));
      return Number.isFinite(value)&&value>0&&value<=Date.now()+5000?value:0;
    }catch(_error){return 0}
  };
  const writeShared=value=>{try{localStorage.setItem(KEY,String(value))}catch(_error){}};
  const redirectInactive=()=>{
    if(signingOut)return;
    signingOut=true;
    window.location.replace('/logout.php?reason=inactive');
  };
  const heartbeat=async force=>{
    const token=csrf();
    if(!token||(!force&&Date.now()-lastHeartbeat<HEARTBEAT_MS))return;
    lastHeartbeat=Date.now();
    try{
      const response=await fetch('/api/session_activity.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
        headers:{'Content-Type':'application/json','X-TT-User-Activity':'1','X-CSRF-Token':token},
        body:JSON.stringify({csrf:token})
      });
      if(response.status===401)window.location.replace('/login.php?expired=inactive');
    }catch(_error){/* Normal application requests still enforce the server timeout. */}
  };
  const markActivity=()=>{
    const now=Date.now();
    lastActivity=now;
    writeShared(now);
    void heartbeat(false);
  };

  const shared=readShared();
  if(shared>0)lastActivity=shared;else writeShared(lastActivity);
  for(const name of ['pointerdown','keydown','input','change','touchstart','scroll']){
    addEventListener(name,markActivity,{capture:true,passive:true});
  }
  addEventListener('storage',event=>{
    if(event.key!==KEY)return;
    const value=Number(event.newValue);
    if(Number.isFinite(value)&&value>lastActivity)lastActivity=value;
  });
  setInterval(()=>{
    const sharedActivity=readShared();
    if(sharedActivity>lastActivity)lastActivity=sharedActivity;
    if(Date.now()-lastActivity>=IDLE_MS)redirectInactive();
  },15000);

  const nativeFetch=window.fetch.bind(window);
  window.fetch=(input,init={})=>{
    const url=typeof input==='string'?input:input instanceof URL?input.href:input?.url||'';
    const resolved=new URL(url,window.location.href);
    if(resolved.origin===window.location.origin&&Date.now()-lastActivity<5000){
      const headers=new Headers(init.headers||(typeof input!=='string'&&!(input instanceof URL)?input.headers:undefined));
      headers.set('X-TT-User-Activity','1');
      init={...init,headers};
    }
    return nativeFetch(input,init);
  };
})();
