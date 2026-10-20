'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM(fs.readFileSync('index.html','utf8'),{url:'https://fixture.test/index.php',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,requests=[];
 w.TT_SESSION={id:'1',name:'Fixture Owner',role:'Super Admin',csrf:'fixture'};w.scrollTo=()=>{};w.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
 w.fetch=async(url,options={})=>{if(options.method==='POST')requests.push(JSON.parse(options.body));return {ok:true,json:async()=>({ok:true,users:[],masters:{},options:{currencies:['USD'],currency_names:{USD:'United States Dollars'},packing_types:['Fixture bags']},approvals:[],snapshots:[]})}};
 w.eval(fs.readFileSync('admin/app.js','utf8').replace('  initialize();','  window.referenceAudit={openMasterDialog,saveMasterRecord}; initialize();'));
 await new Promise(r=>setTimeout(r,50));
 w.document.querySelector('[data-master="reference_lists"]').click();
 assert.match(w.document.getElementById('masterTableBody').textContent,/Currencies/);assert(!w.document.getElementById('masterTableBody').textContent.includes('packing_types'));
 w.document.getElementById('addMasterRecord').click();
 const field=i=>w.document.getElementById('masterField'+i);
 assert.equal(field(0).options[0].textContent,'Currencies');assert.equal(field(0).options[0].value,'currencies');assert(!field(0).disabled);
 field(2).value='Unfinished full name';field(0).value='packing_types';field(0).dispatchEvent(new w.Event('change'));
 assert(field(2).closest('label').hidden);assert.equal(field(1).closest('label').firstChild.textContent,'Option name');
 field(0).value='currencies';field(0).dispatchEvent(new w.Event('change'));assert(!field(2).closest('label').hidden);assert.equal(field(2).value,'Unfinished full name');
 assert.equal(requests.length,0,'List selection must not save a reference');
 w.referenceAudit.openMasterDialog('currencies::USD');assert(field(0).disabled);assert.equal(field(2).value,'United States Dollars');
 // Even a programmatic change to the disabled choice must not rename a different list.
 field(0).value='packing_types';field(1).value='USN';await w.referenceAudit.saveMasterRecord({preventDefault(){}});
 const sent=requests.find(r=>r.action==='manage-option');assert(sent);assert.equal(sent.optionKey,'currencies');assert.equal(sent.old,'USD');assert.equal(sent.value,'USN');assert.equal(sent.fullName,'United States Dollars');
 dom.window.close();console.log('PASS reference labels, currency-only fields, unsaved restoration and source-list-safe explicit rename');
})().catch(e=>{console.error(e);process.exitCode=1});
