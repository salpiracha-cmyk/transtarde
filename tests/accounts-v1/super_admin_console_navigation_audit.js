'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const accounts=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8'),modules=fs.readFileSync('module.php','utf8');
const build=accounts.slice(accounts.indexOf('  function buildTopbar()'),accounts.indexOf('  function deskMarkup()'));
for(const access of [{super:true,workspaces:[]},{super:false,workspaces:[{},{}]},{super:false,workspaces:[{}]},{super:false,workspaces:[]}]){
 const dom=new JSDOM('<div class="topbar"><a class="power">Power</a></div>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
 w.fixtureAccess=access;w.eval("const access=window.fixtureAccess,q=(s,r=document)=>r.querySelector(s),qa=s=>[...document.querySelectorAll(s)],esc=v=>String(v||'');"+build+'buildTopbar();');
 const link=w.document.querySelector('#ttConsoleTop');
 if(access.super){assert.equal(link.textContent,'Console');assert.equal(link.getAttribute('href'),'/index.php');}
 else if(access.workspaces.length>1){assert.equal(link.textContent,'Home');assert.equal(link.getAttribute('href'),'/staff-home.php');}
 else assert.equal(link,null,'Single/no-module user has no owner Console route');
 w.close();
}
const start=modules.indexOf('(()=>{const addConsole='),end=modules.indexOf('</script>',start),header=modules.slice(start,end);
for(const moduleId of ['milling','exports'])for(const access of [{super:true,workspaces:[]},{super:false,workspaces:[{},{}]},{super:false,workspaces:[{}]}]){
 const dom=new JSDOM('<header class="topbar"><div id="ttUserBar"></div></header>',{url:'https://fixture.test/',runScripts:'outside-only'}),w=dom.window;w.TT_MODULE_ACCESS={...access,moduleId};w.eval(header);w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 const link=w.document.querySelector('#ttConsoleTop');if(access.super)assert.equal(link.getAttribute('href'),'/index.php');else if(access.workspaces.length>1){assert.equal(link.getAttribute('href'),'/staff-home.php');assert.equal(link.textContent,'Home');}else assert.equal(link,null);w.close();
}
console.log('PASS real header controllers: owner Console, permission-filtered multi-workspace Home, and no owner route for ordinary users');
