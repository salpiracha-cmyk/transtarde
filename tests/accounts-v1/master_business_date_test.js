'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 for(const [instant,expected] of [['2026-10-19T18:59:59Z','2026-10-19'],['2026-10-19T19:00:00Z','2026-10-20'],['2026-10-31T19:00:00Z','2026-11-01'],['2026-12-31T19:00:00Z','2027-01-01'],['2028-02-28T19:00:00Z','2028-02-29'],['2028-02-29T19:00:00Z','2028-03-01']]){
  const dom=new JSDOM(fs.readFileSync('index.html','utf8'),{url:'https://fixture.test/index.php',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,posts=[];
  const DateBase=w.Date;w.Date=class extends DateBase{constructor(...args){super(...(args.length?args:[instant]))}static now(){return new DateBase(instant).getTime()}};
  w.TT_SESSION={id:'1',name:'Fixture Owner',role:'Super Admin',csrf:'fixture'};w.scrollTo=()=>{};w.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});
  w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
  const old={id:'old-salary',values:['Original','TTI','OFFICE_STAFF','1000','0','0','2026-07-01','','STAFF_COST','No','Active','Original']};
  w.fetch=async(url,options={})=>{if(options.method==='POST')posts.push(options.body);return {ok:true,json:async()=>({ok:true,users:[],masters:{salary_staff:[old]},options:{},approvals:[],snapshots:[]})}};
  w.eval(fs.readFileSync('admin/app.js','utf8').replace('  initialize();','  window.dateAudit={state,openMasterDialog}; initialize();'));
  await new Promise(r=>setTimeout(r,30));
  w.document.querySelector('[data-master="salary_staff"]').click();w.document.getElementById('addMasterRecord').click();
  assert.equal(w.document.getElementById('masterField6').value,expected,instant+' new Salary Master effective date');
  w.dateAudit.openMasterDialog('old-salary');assert.equal(w.document.getElementById('masterField6').value,'2026-07-01','Saved effective dates remain original');
  w.document.getElementById('masterDialog').close();w.document.querySelector('[data-master="business_parties"]').click();w.document.getElementById('addMasterRecord').click();
  const broker=w.document.querySelector('[data-master-field-index="2"][value="Broker"]');broker.checked=true;broker.dispatchEvent(new w.Event('change'));
  w.document.querySelector('[data-add-brokery-rate="buying"]').click();w.document.querySelector('[data-add-brokery-rate="selling"]').click();
  for(const field of w.document.querySelectorAll('[data-brokery-from]'))assert.equal(field.value,expected,instant+' new Brokery effective date');
  assert.equal(posts.length,0,'Opening and adding draft rates must not save');dom.window.close();
 }
 console.log('PASS actual Salary/Brokery master defaults: Karachi midnight, month/year rollover, leap days, saved dates and zero POSTs');
})().catch(e=>{console.error(e);process.exitCode=1});
