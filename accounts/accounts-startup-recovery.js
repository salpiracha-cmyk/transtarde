(()=>{
 'use strict';
 let timer,observer,panel;
 const pending=()=>document.documentElement.classList.contains('tt-accounts-boot');
 function cleanup(){clearTimeout(timer);observer?.disconnect();window.removeEventListener('error',failed,true);panel?.remove();}
 function show(){
  if(!pending()){cleanup();return;}if(panel)return;
  panel=document.createElement('section');panel.id='tt-accounts-startup-recovery';panel.setAttribute('role','alert');
  panel.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100001;width:min(440px,calc(100vw - 32px));box-sizing:border-box;padding:24px;border:1px solid #ccdcd3;border-radius:14px;background:#fff;color:#173c31;font:16px/1.5 Arial;box-shadow:0 12px 40px #0002;text-align:center';
  const title=document.createElement('strong');title.textContent='Accounts is taking longer than expected to start.';panel.appendChild(title);
  const note=document.createElement('p');note.textContent='Reload Accounts to retry the connection, or return to the Console.';panel.appendChild(note);
  const reload=document.createElement('button');reload.type='button';reload.textContent='Reload Accounts';reload.style.cssText='padding:11px 16px;border:0;border-radius:7px;background:#147a5b;color:white;font:700 15px Arial;cursor:pointer';reload.onclick=()=>location.reload();panel.appendChild(reload);
  const back=document.createElement('a');back.href='/index.php';back.textContent='Back to Console';back.style.cssText='display:block;margin-top:14px;color:#173c31';panel.appendChild(back);
  (document.body||document.documentElement).appendChild(panel);
 }
 function failed(event){const source=event.target?.src||event.filename||'';if(String(source).includes('app-bundle.php'))show();}
 observer=new MutationObserver(()=>{if(!pending())cleanup();});observer.observe(document.documentElement,{attributes:true,attributeFilter:['class']});
 timer=setTimeout(show,30000);window.addEventListener('error',failed,true);
})();
