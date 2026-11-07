"use strict";
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const source=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');
 const block=source.slice(source.indexOf('  let transitionGeneration=0;'),source.indexOf('  async function launchAction(action)'));
 for(const company of ['TTI','TG','BRM']){
  const d=new JSDOM('<body><div id="amWindow">Previous bank form</div><div id="ttExpenseDesk">Previous expense form</div></body>',{runScripts:'outside-only'}),w=d.window;let resolve;
  w.nextForm=()=>new Promise(r=>resolve=r);
  w.eval('const q=s=>document.querySelector(s);'+block+'async function launchAction(action){await nextForm();} window.openForm=launch;');
  const pending=w.openForm({title:'Bank Entry · '+company});assert(w.document.querySelector('#ttDeskTransition'));assert(!w.document.querySelector('#amWindow'));assert(!w.document.querySelector('#ttExpenseDesk'));
  resolve();await pending;assert(!w.document.querySelector('#ttDeskTransition'));d.window.close();
 }
 console.log('PASS synchronous old-editor retirement and loading transition across TTI/TG/BRM');
})().catch(e=>{console.error(e);process.exitCode=1});
