const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM(fs.readFileSync('index.html','utf8'),{url:'https://fixture.test/index.php',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
 const entries=[{date:'2026-10-16T19:00:00Z',user:'<actor>',area:'Master',action:'Recorded',detail:'Server master sentinel',ref:'POST-2026-00042',source:'Server audit'}];
 let fail=false,posts=0,exports=[],confirmations=[];
 w.TT_SESSION={id:'1',name:'Fixture Owner',role:'Super Admin',csrf:'fixture'};
 w.scrollTo=()=>{};w.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
 w.confirm=message=>{confirmations.push(message);return true};
 w.Blob=function(parts){this.parts=parts};w.URL.createObjectURL=blob=>{exports.push(blob.parts.join(''));return 'blob:fixture'};w.URL.revokeObjectURL=()=>{};w.HTMLAnchorElement.prototype.click=()=>{};
 w.fetch=async(url,options={})=>{
  if(options.method==='POST')posts++;
  if(String(url).includes('admin_audit')){if(fail)throw Error('Fixture audit unavailable');return {ok:true,json:async()=>({ok:true,entries})}}
  return {ok:true,json:async()=>({ok:true,users:[],masters:{},options:{},approvals:[],snapshots:[],office:{}})};
 };
 w.eval(fs.readFileSync('admin/app.js','utf8').replace('  initialize();','  window.auditTest={state,renderAudit,loadServerAudit,exportAudit,toggleLock}; initialize();'));
 await new Promise(resolve=>setTimeout(resolve,30));
 const api=w.auditTest;
 assert.match(w.document.getElementById('auditRows').textContent,/Server master sentinel/);
 assert.ok(!w.document.getElementById('auditRows').innerHTML.includes('<actor>'));
 assert.match(w.document.getElementById('recentActivity').textContent,/Server master sentinel/);
 w.document.getElementById('auditSearch').value='00042';api.renderAudit();api.exportAudit();
 assert.match(exports[0],/POST-2026-00042/);assert.match(exports[0],/17-10-2026/);
 api.state.audit.push({date:'17-10-2026',user:'Local',area:'Module',action:'Note',detail:'Browser-only sentinel',ref:'LOCAL'});
 assert.doesNotMatch(w.document.getElementById('auditRows').textContent,/Browser-only/);
 fail=true;await api.loadServerAudit();assert.match(w.document.getElementById('auditRows').textContent,/may be out of date/);
 api.exportAudit();assert.equal(exports.length,1,'Do not export an unverified server feed as current');
 w.document.getElementById('auditSearch').value='';w.document.getElementById('auditSource').value='browser';api.renderAudit();
 assert.match(w.document.getElementById('auditRows').textContent,/Browser-only sentinel/);assert.doesNotMatch(w.document.getElementById('auditRows').textContent,/Server master sentinel/);
 api.toggleLock('milling');assert.match(confirmations[0],/does not prevent|does not control/);
 assert.doesNotMatch(w.document.getElementById('lockGrid').textContent,/workflow is protected/);
 assert.equal(posts,0,'Read/audit/note controls must not create server writes or permissions');
 w.close();console.log('PASS authoritative audit, separated browser notes, escaped actors, filtered exports, stale-feed failure and truthful local approvals');
})().catch(error=>{console.error(error);process.exitCode=1});
