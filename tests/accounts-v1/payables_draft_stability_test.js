const assert=require('assert/strict'),fs=require('fs'),{JSDOM}=require('jsdom');
const pause=ms=>new Promise(r=>setTimeout(r,ms));
function observers(w){const all=[],Native=w.MutationObserver;w.MutationObserver=class extends Native{constructor(fn){super(fn);all.push(this)}};return ()=>all.forEach(o=>o.disconnect())}
async function nonwoven(){
 const dom=new JSDOM('<button class="entityBtn">Company</button><button data-pay-commodity="OTHER">Other</button><section id="ws-payables" class="workspace active"><div class="panelHead"></div></section>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
 const stop=observers(w);w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_PAYABLES_COMMODITY={get:()=> 'OTHER'};
 const requests=[],pending=[];let delayed=false;
 w.fetch=(url)=>{requests.push(url);const entity=new URL(url,'https://fixture.test/accounts/').searchParams.get('entity');const result={ok:true,json:async()=>({ok:true,entity,payables:[{id:'B1',supplier:entity+' Supplier',sellerInvoice:'INV1',outstanding:20}],banks:[{id:'BANK1',display:'Bank'}]})};return delayed?new Promise(resolve=>pending.push(()=>resolve(result))):Promise.resolve(result)};
 w.eval(fs.readFileSync('accounts/nonwoven-bag-ui.js','utf8'));await pause(200);
 w.document.querySelector('[data-nw-pay]').click();const input=w.document.querySelector('#nwPayRef');input.value='draft reference';input.focus();
 const reads=requests.length;w.document.body.appendChild(w.document.createElement('aside'));await pause(180);
 assert.equal(w.document.querySelector('#nwPayRef'),input,'Unrelated rendering must retain the payment form');assert.equal(input.value,'draft reference');assert.equal(w.document.activeElement,input);assert.equal(requests.length,reads,'Unrelated rendering must not read payable data again');
 delayed=true;w.document.querySelector('[data-pay-commodity]').click();await pause(100);
 w.localStorage.setItem('tt_accounts_entity','BRM');w.document.querySelector('.entityBtn').click();await pause(100);
 pending[1]();await pause(20);assert(w.document.querySelector('#ttNwPay').textContent.includes('BRM Supplier'));
 pending[0]();await pause(30);assert(!w.document.querySelector('#ttNwPay').textContent.includes('TTI Supplier'),'Late prior-company response must be ignored');
 w.localStorage.setItem('tt_accounts_entity','TG');w.document.querySelector('.entityBtn').click();await pause(100);assert(w.document.querySelector('#ttNwPay').classList.contains('hidden'),'Pakistan bag payables must stay hidden in TG');
 stop();w.close();
}
async function rice(){
 const dom=new JSDOM('<button class="entityBtn">Company</button><button id="ttstOpen">Settlement</button><section id="ws-payables" class="active"><div class="panelHead"></div></section>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
 const stop=observers(w);let opens=0;w.document.querySelector('#ttstOpen').onclick=()=>opens++;w.localStorage.setItem('tt_accounts_entity','TTI');w.sessionStorage.setItem('tt_rice_payable_focus',JSON.stringify({soda:'S1',broker:'Broker',company:'TTI'}));
 w.eval(fs.readFileSync('accounts/rice-payables-link-ui.js','utf8'));await pause(280);
 const banner=w.document.querySelector('#ttRicePayFocus'),clear=w.document.querySelector('#ttRicePayClear');let changes=0;const observer=new w.MutationObserver(()=>changes++);observer.observe(banner,{childList:true,subtree:true});
 w.document.body.appendChild(w.document.createElement('aside'));await pause(320);assert.equal(changes,0,'Focus banner cannot rebuild itself after unrelated mutations');assert.equal(w.document.querySelector('#ttRicePayClear'),clear);
 w.TT_RICE_PAYABLE_LINK.open('S1','Broker');w.localStorage.setItem('tt_accounts_entity','BRM');w.document.querySelector('.entityBtn').click();await pause(350);assert.equal(opens,0,'A delayed prior-company command cannot open settlement');assert.equal(w.document.querySelector('#ttRicePayFocus'),null);assert.equal(w.sessionStorage.getItem('tt_rice_payable_focus'),null);
 observer.disconnect();stop();w.close();
}
(async()=>{await nonwoven();await rice();console.log('PASS payable draft identity, focus, read counts, stale-company responses and stable Rice focus banner')})().catch(e=>{console.error(e);process.exitCode=1});
