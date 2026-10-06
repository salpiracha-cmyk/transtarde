const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync('accounts/jv-workflow-ui.js','utf8');
const base={ok:true,canWrite:true,canApprove:true,jvs:[],accounts:[{code:'2110',name:'Supplier Payables',requiresSubledger:true}],opening:{allowed:true,enabled:true,canDisable:true,date:'2026-07-01',currency:'PKR',entities:['TTI','TG'],accounts:[{code:'2110',name:'Supplier Payables',requiresSubledger:true},{code:'1120',name:'Cash',requiresSubledger:false},{code:'1110',name:'Bank',requiresSubledger:false}],parties:['ACME RICE'],banks:[{id:'B1',name:'PK Bank',currency:'PKR'},{id:'B2',name:'USD Bank',currency:'USD'}],balances:[],entries:[]}};
async function scenario(privileged,standalone=false){
 const dom=new JSDOM('<main id="ws-jv" class="active"></main>',{url:'https://fixture.test/accounts/index.php',runScripts:'outside-only'}),w=dom.window;
 const observers=[],NativeObserver=w.MutationObserver;w.MutationObserver=class extends NativeObserver{constructor(callback){super(callback);observers.push(this)}};
 const cleanup=()=>{observers.forEach(observer=>observer.disconnect());dom.window.close()};
 const payload=structuredClone(base);if(!privileged)payload.opening={allowed:false};
 let calls=[],viewport=0;w.TT_ACCOUNT_ACCESS={csrf:'test'};w.TT_OPENING_ONLY=standalone;w.TT_FORM_VIEWPORT={open:()=>viewport++};w.localStorage.setItem('tt_accounts_entity','TTI');
 w.fetch=async(url,opt)=>{calls.push({url,body:opt.body?JSON.parse(opt.body):null});return {ok:true,json:async()=>({...payload,result:{journalId:'2026-00001'}})}};
 w.eval(source);await w.TT_JV_WORKFLOW.load();const q=id=>w.document.getElementById(id);
 if(!privileged){assert.equal(q('jvwOpeningTick'),null);assert.ok(q('jvwLines'));await new Promise(setImmediate);cleanup();return}
 assert.equal(q('jvwOpeningForm').hidden,!standalone);assert.equal(q('jvwOpeningDate').value,'2026-07-01');assert.ok(q('jvwOpeningDate').readOnly);
 if(!standalone){q('jvwNarration').value='KEEP THIS DRAFT';q('jvwOpeningTick').click();assert.ok(q('jvwRegular').hidden);q('jvwOpeningTick').click();assert.equal(q('jvwNarration').value,'KEEP THIS DRAFT');q('jvwOpeningTick').click()}
 function input(id,value){q(id).value=value;q(id).dispatchEvent(new w.Event('input',{bubbles:true}))}
 input('jvwOpeningAccount','2110 · Supplier Payables');assert.equal(q('jvwOpeningPartyBox').hidden,false);assert.equal(q('jvwOpeningBankBox').hidden,true);
 input('jvwOpeningParty','ACME RICE');input('jvwOpeningAmount','125');q('jvwOpeningSide').value='Credit';
 assert.equal(q('jvwOpeningPost').disabled,false);assert.equal(calls.length,1,'Typing and changing fields must not fetch or save');
 await q('jvwOpeningPost').onclick();assert.equal(calls.length,2);const posted=calls[1].body;
 assert.equal(posted.date,'2026-07-01');assert.equal(posted.side,'Credit');assert.equal(posted.amount,'125');assert.equal(posted.party,'ACME RICE');assert.equal(posted.lines,undefined);assert.ok(posted.requestKey);assert.equal(posted.action,'post_opening_balance');
 if(standalone)assert.ok(calls[0].url.includes('scope=opening'));
 input('jvwOpeningAccount','1110 · Bank');assert.equal(q('jvwOpeningPartyBox').hidden,true);assert.equal(q('jvwOpeningBankBox').hidden,false);
 input('jvwOpeningBank','B2');assert.equal(q('jvwOpeningRateBox').hidden,false);assert.ok(q('jvwOpeningAmountLabel').textContent.includes('USD'));
 input('jvwOpeningAmount','100');input('jvwOpeningRate','280');assert.ok(q('jvwOpeningBookAmount').textContent.includes('28,000'));assert.equal(q('jvwOpeningPost').disabled,false);
 input('jvwOpeningAccount','1120 · Cash');assert.equal(q('jvwOpeningBankBox').hidden,true);assert.equal(q('jvwOpeningRateBox').hidden,true);assert.equal(q('jvwOpeningPartyBox').hidden,true);
 payload.opening.balances=[{account:'2110',party:'ACME RICE',bankId:'',balance:-100,postIds:['2026-00009']}];await w.TT_JV_WORKFLOW.load();
 input('jvwOpeningAccount','2110 · Supplier Payables');input('jvwOpeningParty','acme.rice');input('jvwOpeningAmount','10');assert.equal(q('jvwOpeningPost').disabled,true);assert.ok(q('jvwOpeningExisting').textContent.includes('2026-00009'));
 payload.opening.enabled=false;await w.TT_JV_WORKFLOW.load();assert.equal(q('jvwOpeningPost'),null);assert.ok(q('jvwOpeningEnabled'));assert.ok(q('jvwOpeningEntity'),'Company switch remains available when opening entry is disabled');
 assert.ok(viewport>0);await new Promise(setImmediate);cleanup();
}
(async()=>{await scenario(false);await scenario(true);await scenario(true,true);console.log('Opening JV UI: privileged tick, conditional fields, draft preservation, fixed date, single-side submit, native rate, existing-balance block, disabled state, console route and manual-save behavior passed.')})().catch(e=>{console.error(e);process.exitCode=1});
