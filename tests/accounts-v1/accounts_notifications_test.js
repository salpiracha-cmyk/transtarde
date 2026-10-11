'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
  constructor(){this.children=[];this.style={};this.dataset={};this.hidden=false;}
  setAttribute(key,value){this[key]=value;}
  appendChild(child){this.children.push(child);}
  querySelector(){return this.children[0];}
}
(async()=>{
  const body=new Element(),document={body,createElement:()=>new Element()};
  let response=new Response(JSON.stringify({ok:false,error:'Bank advice differs by Rs 13.56'}),{status:422});
  const window={fetch:async()=>{if(response instanceof Error)throw response;return response;}};
  vm.runInNewContext(fs.readFileSync('accounts/accounts-notifications.js','utf8'),{window,document});
  const returned=await window.fetch('../api/export_receipts.php',{method:'POST'});
  assert.equal(returned,response);assert.equal((await returned.json()).error,'Bank advice differs by Rs 13.56','error reporting preserves the response for the form');
  const panel=body.children[0];assert.equal(panel.role,'alert');assert.match(panel.style.cssText,/top:12px/);assert.match(panel.style.cssText,/z-index:2147483647/);assert.match(panel.children[0].textContent,/13.56/);
  panel.children[1].onclick();assert.equal(panel.hidden,true);
  window.alert('Choose Payment Part Reference');assert.equal(panel.hidden,false);assert.equal(body.children.length,1);assert.match(panel.children[0].textContent,/Payment Part/);
  window.TT_ACCOUNTS_NOTICE.error('<img onerror=attack>');assert.equal(panel.children[0].textContent,'<img onerror=attack>','messages are text, never HTML');
  response=new Error('offline');await assert.rejects(window.fetch('../api/accounts.php'),/offline/);assert.match(panel.children[0].textContent,/not confirmed/);
  panel.hidden=true;response=new Response(JSON.stringify({ok:true}));await window.fetch('../api/accounts.php');assert.equal(panel.hidden,true,'successful reads do not raise errors');
  console.log('PASS Accounts top error popup, dismissal, safe text, API response preservation and connection failures');
})().catch(error=>{console.error(error);process.exitCode=1;});
