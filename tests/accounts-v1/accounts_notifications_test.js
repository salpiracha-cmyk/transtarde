'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
  constructor(){this.children=[];this.style={};this.dataset={};this.hidden=false;}
  setAttribute(key,value){this[key]=value;}
  appendChild(child){this.children.push(child);}
  querySelector(){return this.children[0];}
  contains(node){return node===this||this.children.includes(node);}
}
(async()=>{
  const listeners={},timers=new Map();let sequence=0;const body=new Element(),document={body,readyState:'loading',addEventListener(type,fn){listeners[type]=fn;},createElement:()=>new Element()};
  let response=new Response(JSON.stringify({ok:false,error:'Bank advice differs by Rs 13.56'}),{status:422});
  const window={fetch:async()=>{if(response instanceof Error)throw response;return response;}};
  vm.runInNewContext(fs.readFileSync('accounts/accounts-notifications.js','utf8'),{window,document,setTimeout:(fn,delay)=>{timers.set(++sequence,{fn,delay});return sequence;},clearTimeout:id=>timers.delete(id)});
  const returned=await window.fetch('../api/export_receipts.php',{method:'POST'});
  assert.equal(returned,response);assert.equal((await returned.json()).error,'Bank advice differs by Rs 13.56','error reporting preserves the response for the form');
  const panel=body.children[0];assert.equal(panel.role,'alert');assert.match(panel.style.cssText,/top:12px/);assert.match(panel.style.cssText,/z-index:2147483647/);assert.match(panel.children[0].textContent,/13.56/);
  assert.equal(panel.dataset.persistent,'true');assert.equal(timers.size,0);listeners.click({target:{closest:()=>null}});assert.equal(panel.hidden,false,'Failed posting stays visible on outside clicks');panel.children[1].onclick();assert.equal(panel.hidden,true);
  window.alert('Choose Payment Part Reference');assert.equal(panel.hidden,false);assert.equal(body.children.length,1);assert.match(panel.children[0].textContent,/Payment Part/);
  assert.equal([...timers.values()][0].delay,30000);[...timers.values()][0].fn();assert.equal(panel.hidden,true,'Ordinary notice expires at 30 seconds');window.alert('Ordinary notice');listeners.click({target:{closest:()=>null}});assert.equal(panel.hidden,true,'Outside click dismisses ordinary notices');window.TT_ACCOUNTS_NOTICE.error('<img onerror=attack>');assert.equal(panel.children[0].textContent,'<img onerror=attack>','messages are text, never HTML');
  response=new Error('offline');await assert.rejects(window.fetch('../api/accounts.php'),/offline/);assert.match(panel.children[0].textContent,/not confirmed/);
  panel.hidden=true;response=new Response(JSON.stringify({ok:true}));await window.fetch('../api/accounts.php');assert.equal(panel.hidden,true,'successful reads do not raise errors');
  window.TT_ACCOUNTS_NOTICE.error('Posting failed',{persistent:true});response=new Response(JSON.stringify({ok:true,previews:[]}));await window.fetch('../api/accounts.php',{method:'POST'});assert.equal(panel.hidden,false,'Preview does not dismiss an unresolved posting failure');response=new Response(JSON.stringify({ok:true,result:{journalId:'POST-2026-1'}}));await window.fetch('../api/accounts.php',{method:'POST'});assert.equal(panel.hidden,true,'Confirmed save dismisses its resolved failure');
  console.log('PASS Accounts top error popup, dismissal, safe text, API response preservation and connection failures');
})().catch(error=>{console.error(error);process.exitCode=1;});
