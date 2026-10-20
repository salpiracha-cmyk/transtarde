'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const app=fs.readFileSync(process.argv[2]||'exports/app.js','utf8');
const moduleSource=fs.readFileSync(process.argv[3]||'module.php','utf8');
const permissionStart=moduleSource.indexOf("(()=>{const c=window.TT_MODULE_ACCESS||{},p=c.permissions");
assert(permissionStart>=0);
const guard=moduleSource.slice(permissionStart,moduleSource.indexOf('</script>',permissionStart));
const lineFunction=name=>{const line=app.split('\n').find(line=>line.startsWith('function '+name+'('));assert(line,name+' source');return line;};
const outputStart=app.lastIndexOf('function renderDocumentOutput(d)'),outputEnd=app.indexOf('\nfunction renderTG(d)',outputStart);
for(const completed of [true,false]){
 const dom=new JSDOM('<div class="topbar"></div><div id="ttUserBar"><span id="ttUserName"></span></div><button id="completed">Completed Shipments</button><button id="output" data-workspace="uploads">Document Output</button><main></main>',{runScripts:'outside-only',url:'https://fixture.test/module.php?id=exports'});
 const w=dom.window,alerts=[],prints=[],observers=[];const NativeObserver=w.MutationObserver;
 w.MutationObserver=class extends NativeObserver{constructor(callback){super(callback);observers.push(this)}};
 const permissions={completed:['View'],print:['View']};
 w.TT_MODULE_ACCESS={moduleId:'exports',user:'Fixture',role:'Staff',permissions};w.alert=message=>alerts.push(message);
 w.eval('var modalStack=[];var uid=()=>"fixture-modal";var esc=String;var num=Number;var SELLER_DETAILS={TTI:{}};');
 w.shipment=()=>({completed,status:completed?'Completed':'Draft',contractRef:'TTI/FIX/01'});w.contractByRef=()=>({seller:'TTI'});
 w.finalShipmentDocuments=()=>[{name:'Packing List',source:'System',status:'Ready',ready:true,render:()=>'<saved-packing>'}];
 w.completionMissing=()=>[];w.directPrint=(title,html,mode,readOnly)=>prints.push({mode,readOnly});
 w.eval([lineFunction('openModal'),lineFunction('closeModal'),lineFunction('printShipmentDoc__legacy_v0')].join('\n'));
 w.printShipmentDoc=w.printShipmentDoc__legacy_v0;
 w.eval(guard);w.document.getElementById(completed?'completed':'output').click();w.eval(app.slice(outputStart,outputEnd));
 const root=w.document.querySelector('main');w.renderDocumentOutput(root);
 const output=root.querySelector('[data-final-document]');output.click();
 if(completed){
  assert(w.document.getElementById('printWith'),'Completed View may open the actual print modal without Create');
  w.document.getElementById('printWith').click();assert.deepEqual(prints,[{mode:'with',readOnly:true}]);assert.equal(alerts.length,0);
  output.click();w.document.getElementById('printWithout').click();assert.deepEqual(prints.at(-1),{mode:'without',readOnly:true});
  // A read intent never supplies missing View, and active saves stay protected.
  permissions.print=[];output.click();assert.equal(alerts.at(-1),'This icon is not allowed for your login.');assert.equal(prints.length,2);
  permissions.print=['View'];permissions.completed=[];output.click();assert.equal(alerts.at(-1),'This icon is not allowed for your login.');assert.equal(prints.length,2);
 }else{
  assert.equal(w.document.getElementById('printWith'),null,'Active Print / Save still requires a write grant');
  assert.equal(alerts.at(-1),'Create permission is not allowed for this icon.');assert.equal(prints.length,0);
  permissions.print.push('Create');output.click();assert(w.document.getElementById('printWith'));
  w.document.getElementById('printWith').click();assert.deepEqual(prints,[{mode:'with',readOnly:false}]);
 }
 observers.forEach(observer=>observer.disconnect());dom.window.close();
}
console.log('PASS real module capture guard and completed print buttons: both paper modes work with View; missing View and active writes remain denied');
