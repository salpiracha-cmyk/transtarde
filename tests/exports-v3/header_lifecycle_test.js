'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('exports/app.js','utf8'),start=source.indexOf('function mount(){'),end=source.indexOf('function render(){',start),mount=source.slice(start,end);
for(const state of ['standalone','shared-present','shared-removed-old']){
 const dom=new JSDOM('<div id="app"></div>'+(state==='standalone'?'':'<div id="ttUserBar"><a aria-label="Log out">Power</a></div>'),{url:'https://fixture.test/',runScripts:'outside-only'}),w=dom.window,timers=[];w.setTimeout=fn=>{timers.push(fn);return timers.length};w.eval("function upgradeState(){} function fmt(x){return x} function TODAY(){return '2026-10-19'} function render(){}"+source.split('\n').filter(line=>line.startsWith('const esc=')||line.startsWith('const fmtHTML=')).join('\n')+mount+'mount();');
 const original=w.document.getElementById('logoutTop');assert(original);
 if(state==='shared-removed-old')original.remove();
 for(const timer of timers)assert.doesNotThrow(timer,'Shared header may remove the legacy control before its delayed cleanup');
 if(state==='standalone')assert.notEqual(original.style.display,'none','Standalone Exports retains its own logout');
 else{assert(w.document.getElementById('ttUserBar'));if(state==='shared-present')assert.equal(original.style.display,'none');}
 w.close();
}
console.log('PASS actual Exports mount: standalone logout, shared header cleanup, and legacy control removed before delayed callback');
