'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Input{constructor(type,value){this.type=type;this.value=value;this.selectionStart=2;this.selectionEnd=2}hasAttribute(){return false}setSelectionRange(a,b){this.selectionStart=a;this.selectionEnd=b}}
class Textarea extends Input{}
const events={},requests=[],sandbox={HTMLInputElement:Input,HTMLTextAreaElement:Textarea,URL,location:{href:'https://app.example/accounts/index.php',origin:'https://app.example'},document:{head:{appendChild:()=>{}},createElement:()=>({}),addEventListener:(type,fn)=>events[type]=fn},window:{fetch:(input,options)=>requests.push(options)}};
vm.createContext(sandbox);vm.runInContext(fs.readFileSync('accounts/accounts-uppercase.js','utf8'),sandbox);
const ref=new Input('text','ft/26265');events.input({target:ref});assert.equal(ref.value,'FT/26265');assert.equal(ref.selectionStart,2);
const supplier=new Input('text','Indus Rice');supplier.hasAttribute=name=>name==='list';events.input({target:supplier});assert.equal(supplier.value,'Indus Rice','Master selections retain canonical identity while displaying capitals');
const newName=new Input('text','New Transporter');newName.hasAttribute=name=>name==='list';newName.closest=()=>({});events.input({target:newName});assert.equal(newName.value,'NEW TRANSPORTER','New names are entered in capitals');
const email=new Input('email','user@example.com');events.input({target:email});assert.equal(email.value,'user@example.com');
sandbox.window.fetch('../api/export_receipts.php',{method:'POST',body:JSON.stringify({bankAdviceRef:'ft/26265',csrf:'AbCd',bankAccountId:'bank-AbC',action:'post_receipt',allocations:[{targetId:'source-AbC',reference:'ref1'}]})});
const body=JSON.parse(requests[0].body);assert.equal(body.bankAdviceRef,'FT/26265');assert.equal(body.allocations[0].reference,'REF1');assert.equal(body.allocations[0].targetId,'source-AbC');assert.equal(body.csrf,'AbCd');assert.equal(body.bankAccountId,'bank-AbC');assert.equal(body.action,'post_receipt');
console.log('Accounts uppercase input, caret, nested POST text and machine identity preservation passed');
