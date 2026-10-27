const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('accounts/mill-action-save.js','utf8');
const dom=new JSDOM('<section class="panel active"><input id="draft" value="keep me"></section>',{url:'https://fixture.invalid',runScripts:'outside-only'}),w=dom.window;
w.eval(source);let remote=[],version=0,posts=0,fail=false,lost=false,alerts=[],clears=0;
w.alert=s=>alerts.push(s);w.TT_MODULE_ACCESS={userId:7,module:'Mill'};
w.fetch=async(url,opts={})=>{if(!opts.method)return {ok:true,json:async()=>({ok:true,values:{tt37usedbags:JSON.stringify(remote)},meta:{tt37usedbags:{version}}})};posts++;if(fail)throw Error('offline');const b=JSON.parse(opts.body);assert.equal(b.key,'tt37usedbags');assert.equal(b.baseVersion,version);remote=JSON.parse(b.value);version++;if(lost){lost=false;throw Error('ack lost');}return {ok:true,json:async()=>({ok:true,value:JSON.stringify(remote),keyVersion:version})};};
w.TT_SHARED_SYNC={commitMillAction:(key,record,before,prefix)=>w.TT_MILL_ACTION_SAVE.commit({endpoint:'/fixture',access:w.TT_MODULE_ACCESS,key,record,before,prefix}).then(x=>x.record)};
const rec={id:1,entity:'TTI',millId:1,millName:'Fixture Mill',date:'2026-10-27',qty:5};
const save=r=>w.TT_MILL_ACTION_SAVE.save('tt37usedbags',r,{label:'Used bags',onSaved:()=>{clears++;w.draft.value='';}});
(async()=>{
 fail=true;await save(rec);assert.equal(clears,0);assert.equal(w.draft.value,'keep me');assert.equal(remote.length,0);assert(w.document.querySelector('[data-mill-action-recovery]'));
 fail=false;lost=true;await save({...rec,id:2});assert.equal(remote.length,1);const id=remote[0].id;assert.equal(clears,0);
 await save({...rec,id:3});assert.equal(remote.length,1);assert.equal(remote[0].id,id);assert.equal(posts,2,'Lost acknowledgement replay must read the same source, without posting twice');assert.equal(clears,1);
 fail=true;w.draft.value='second draft';await save({...rec,qty:6});const previousPosts=posts;await save({...rec,qty:7});assert.equal(posts,previousPosts,'Changed draft must not silently replace uncertain save');assert.equal(w.draft.value,'second draft');
 fail=false;w.document.querySelector('[data-mill-action-recovery] button').click();await new Promise(r=>setTimeout(r,20));assert.equal(remote.length,2);assert.equal(remote[1].qty,6);assert.equal(w.draft.value,'second draft');
 const before=JSON.parse(JSON.stringify(remote[0]));remote[0].qty=99;
 await assert.rejects(()=>w.TT_MILL_ACTION_SAVE.commit({endpoint:'/fixture',access:w.TT_MODULE_ACCESS,key:'tt37usedbags',record:{...before,qty:8},before}),/changed elsewhere/);
 assert.equal(remote[0].qty,99);assert.equal(remote[1].qty,6);dom.window.close();console.log('PASS isolated operational saves, failed form retention, lost acknowledgement replay, changed-draft recovery and stale amendment rejection');
})().catch(e=>{console.error(e);dom.window.close();process.exitCode=1;});
