const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 for(const kind of ['accounts','directors'])for(const editable of [false,true]){
  const markup=kind==='accounts'?'<button data-master="rules" class="active">Rules</button><div id="masterBody"></div>':'<div id="directorHome"><button id="openBrokerageMaster">Brokerage</button></div><div id="brokerageWorkspace" class="hidden"><div id="brokerageMasterRoot"></div></div>';
  const dom=new JSDOM(markup,{url:'https://fixture.test/'+kind+'/index.php',runScripts:'outside-only'}),w=dom.window,observers=[];let writes=0;
  const Observer=w.MutationObserver;w.MutationObserver=class extends Observer{constructor(fn){super(fn);observers.push(this)}};
  w.alert=message=>{throw Error(message)};w.fetch=async(url,options={})=>{if(options.method==='POST')writes++;return {ok:true,json:async()=>({ok:true,productRates:[],whtRates:[],canCreate:editable,canEdit:false})}};
  w.eval(fs.readFileSync(kind==='accounts'?'accounts/brokerage-ui.js':'directors/brokerage-master.js','utf8'));
  w.document.querySelector(kind==='accounts'?'[data-master=rules]':'#openBrokerageMaster').click();await new Promise(resolve=>setTimeout(resolve,100));
  const root=w.document.querySelector(kind==='accounts'?'#ttBrokerageMasterInline':'#brokerageMasterRoot');assert(root);
  assert([...root.querySelectorAll('input')].every(n=>n.readOnly===!editable));assert([...root.querySelectorAll('button')].every(n=>n.disabled===!editable));assert.equal(writes,0,'Opening a rate view never saves');
  observers.forEach(observer=>observer.disconnect());w.close();
 }
 console.log('PASS Accounts and Directors rate views obey server capabilities without adding writes');
})().catch(error=>{console.error(error);process.exitCode=1});
