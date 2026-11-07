'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<!doctype html><html class="tt-accounts-boot"><body></body></html>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
(async()=>{try{let retry;w.setTimeout=(fn,delay)=>{assert.equal(delay,30000);retry=fn;return 1;};w.clearTimeout=()=>{};w.eval(fs.readFileSync('accounts/accounts-startup-recovery.js','utf8'));
 assert(!w.document.querySelector('#tt-accounts-startup-recovery'),'Do not interrupt ordinary startup');retry();const panel=w.document.querySelector('[role=alert]');assert(panel.textContent.includes('Reload Accounts'));assert.equal(panel.querySelector('a').getAttribute('href'),'/index.php');assert.equal(panel.querySelector('button').type,'button');
 w.document.documentElement.classList.remove('tt-accounts-boot');await new Promise(setImmediate);assert(!w.document.querySelector('#tt-accounts-startup-recovery'),'Successful startup removes recovery UI without reloading');
 assert(!/fetch\s*\(|setInterval|\.click\s*\(/.test(fs.readFileSync('accounts/accounts-startup-recovery.js','utf8')),'Recovery must not submit data, poll or reload automatically');console.log('PASS startup recovery: delayed/manual retry, explicit Console route, no writes/automatic reload, cleanup after successful boot');
 }finally{w.close();}})().catch(e=>{console.error(e);process.exitCode=1});
