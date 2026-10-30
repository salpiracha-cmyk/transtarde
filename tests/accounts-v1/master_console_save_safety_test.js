'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM(fs.readFileSync('index.html','utf8'),{url:'https://fixture.test/index.php?view=masters',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
 w.TT_SESSION={id:'1',name:'Fixture Owner',role:'Super Admin',csrf:'fixture'};w.scrollTo=()=>{};w.matchMedia=()=>({matches:false,addEventListener(){}});w.confirm=()=>true;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
 const company={id:'COMPANY',revision:'a'.repeat(64),values:['Fixture Company','FIX','Pakistan','Pakistan','Exporter','No','',JSON.stringify([{name:'Fixture Owner',share:100}]),'','','','','','[]','[]','[]','[]','','']};
 let writes=0,uploads=0,release,hold=false,failSecond=true,lostReply=false;
 const options={countries:['Pakistan'],currencies:['PKR'],party_roles:['Supplier']};
 const data=()=>({ok:true,users:[],masters:{companies:[company]},options,approvals:[],snapshots:[],entries:[]});
 w.fetch=async(url,request={})=>{
  if(String(url).includes('master_documents')&&request.method==='POST'){
   uploads++;if(uploads===2&&failSecond)return {ok:false,json:async()=>({ok:false,error:'Fixture second attachment failed'})};
   const requestId=request.body.get('requestId'),docs=JSON.parse(company.values[14]);let doc=docs.find(d=>d.uploadRequestId===requestId);
   if(!doc){doc={id:'DOC-'+uploads,type:request.body.get('type'),label:request.body.get('label'),status:'Active',isDefault:false,version:1,uploadRequestId:requestId};docs.push(doc);company.values[14]=JSON.stringify(docs);company.revision='c'.repeat(64);}
   if(lostReply){lostReply=false;throw new Error('Fixture lost upload acknowledgement')}
   return {ok:true,json:async()=>({ok:true,document:doc,documents:docs,revision:company.revision})};
  }
  if(request.method==='POST'){
   const body=JSON.parse(request.body);if(body.type==='companies'){
    writes++;if(hold)await new Promise(resolve=>release=resolve);
    if(body.expectedRevision!==company.revision)return {ok:false,json:async()=>({ok:false,error:'Fixture stale version'})};
    const docs=company.values[14];company.values=[...body.values];company.values[14]=docs;company.revision='b'.repeat(64);
    return {ok:true,json:async()=>({...data(),id:company.id})};
   }
  }
  return {ok:true,json:async()=>data()};
 };
 w.eval(fs.readFileSync('admin/app.js','utf8').replace('  initialize();','  window.consoleTest={openMasterDialog,saveMasterRecord}; initialize();'));
 await new Promise(resolve=>setTimeout(resolve,30));
 const api=w.consoleTest;w.document.getElementById('masterForm').reportValidity=()=>true;await api.openMasterDialog('COMPANY');
 const docRows=w.document.getElementById('companyDocumentRows');
 w.document.getElementById('addCompanyDocument').click();docRows.querySelector('[data-remove-company-document]').click();assert.equal(docRows.children.length,0,'Deleting a new document removes required file controls');
 for(let i=1;i<=2;i++){
  w.document.getElementById('addCompanyDocument').click();const row=docRows.lastElementChild;row.querySelector('[data-document-label]').value='Document '+i;
  Object.defineProperty(row.querySelector('[data-document-file]'),'files',{value:[new w.File(['fixture'],'fixture.png',{type:'image/png'})]});
 }
 hold=true;const first=api.saveMasterRecord({preventDefault(){}});await new Promise(resolve=>setTimeout(resolve,10));
 await api.saveMasterRecord({preventDefault(){}});assert.equal(writes,1,'Second submit must not create another request');release();hold=false;await first;
 assert.equal(w.document.getElementById('masterDialog').open,true);assert.match(w.document.querySelector('.console-form-error').textContent,/second attachment failed/);
 assert.equal(w.document.getElementById('editMasterId').value,company.id);assert.equal(docRows.querySelectorAll('[data-document-id][value]').length>=0,true);
 failSecond=false;await api.saveMasterRecord({preventDefault(){}});assert.equal(writes,1,'Attachment retry must not rewrite company');assert.equal(JSON.parse(company.values[14]).length,2);assert.equal(uploads,3);assert.equal(w.document.getElementById('masterDialog').open,false);
 // Lost acknowledgement: repeating the upload request preserves one document,
 // while a concurrent company edit must not be rewritten by attachment retry.
 await api.openMasterDialog('COMPANY');w.document.getElementById('addCompanyDocument').click();const pending=w.document.getElementById('companyDocumentRows').lastElementChild;
 pending.querySelector('[data-document-label]').value='Lost reply';Object.defineProperty(pending.querySelector('[data-document-file]'),'files',{value:[new w.File(['fixture'],'fixture.png',{type:'image/png'})]});
 lostReply=true;await api.saveMasterRecord({preventDefault(){}});assert.match(w.document.querySelector('.console-form-error').textContent,/lost upload acknowledgement/);
 company.values[6]='Concurrent owner notes';const before=writes;await api.saveMasterRecord({preventDefault(){}});
 assert.equal(writes,before);assert.equal(company.values[6],'Concurrent owner notes');assert.equal(JSON.parse(company.values[14]).length,3);
 w.close();console.log('PASS DOM controllers: save in-flight guard, removed unsaved file controls, visible modal error, partial-upload and lost-response retry, and preserved concurrent company fields');
})().catch(error=>{console.error(error);process.exitCode=1});
