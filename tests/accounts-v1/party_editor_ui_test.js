const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
const dom=new JSDOM('<main></main>',{url:'https://fixture.test/accounts/',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
const observers=[],Native=w.MutationObserver;w.MutationObserver=class extends Native{constructor(cb){super(cb);observers.push(this)}};
w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
w.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture',masters:{business_parties:[]}};let request,saved;
w.fetch=async(url,options)=>{request=JSON.parse(options.body);return {ok:true,json:async()=>({ok:true,masters:{[request.type]:[{id:'P1',values:request.values}]}})}};
w.eval(fs.readFileSync('accounts/master-autocomplete.js','utf8'));
w.TT_ACCOUNTS_MASTER_CHOICES.manageParty('Packing Team',name=>saved=name);
let form=w.document.querySelector('dialog form');form.elements.namedItem('partyName').value='Packing Team';
for(const role of ['Bag Supplier','Labour Contractor']){const box=[...form.querySelectorAll('[name=partyRole]')].find(x=>x.value===role);assert.ok(box);box.checked=true}
await form.onsubmit({preventDefault(){}});assert.equal(saved,'Packing Team');assert.equal(request.action,'create');assert.equal(request.values[2],'Bag Supplier; Labour Contractor');assert.equal(request.csrf,'fixture');
w.TT_ACCOUNTS_MASTER_CHOICES.manageParty('Packing Team',()=>{});form=w.document.querySelector('dialog form');assert.equal(form.querySelectorAll('[name=partyRole]:checked').length,2);form.elements.namedItem('partyName').value='Updated Team';await form.onsubmit({preventDefault(){}});assert.equal(request.action,'update');assert.equal(request.id,'P1');
w.TT_ACCOUNTS_MASTER_CHOICES.manageParty('New Buyer',()=>{});form=w.document.querySelector('dialog form');form.elements.namedItem('newPartyType').value='buyer';form.elements.namedItem('newPartyType').onchange();form=w.document.querySelector('dialog form');assert.ok(form.elements.namedItem('address'));form.elements.namedItem('address').value='Buyer address';await form.onsubmit({preventDefault(){}});assert.equal(request.type,'export_customers');assert.equal(request.values[0],'New Buyer');assert.equal(request.values[3],'Buyer address');
observers.forEach(x=>x.disconnect());w.close();console.log('Inline party Add/Edit: categories, master create/update, saved callback and preserved roles passed.');
})().catch(e=>{console.error(e);process.exitCode=1});
