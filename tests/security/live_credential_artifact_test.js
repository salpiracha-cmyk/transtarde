'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const source=fs.readFileSync('tests/live/hostinger-qa.spec.js','utf8');
const uses=[];const test=new Proxy(()=>{},{get:(_,key)=>key==='use'?options=>uses.push(options):()=>{}});
// Evaluate the real suite registration without executing any production tests or credentials.
vm.runInNewContext(source,{require:name=>{assert.equal(name,'@playwright/test');return{test,expect:()=>{}}},process:{env:{}},Date,BigInt});
assert.equal(uses.length,1);assert.equal(uses[0].trace,'off');
assert(['only-on-failure','off'].includes(uses[0].screenshot));
const workflow=fs.readFileSync('.github/workflows/live-hostinger-qa.yml','utf8');
const section=workflow.slice(workflow.indexOf('name: Upload screenshots and video only'),workflow.indexOf('  cleanup:'));
assert(section.includes('actions/upload-artifact@v4'));
const paths=section.match(/path: \|\n([\s\S]*?)          retention-days:/)[1].trim().split('\n').map(p=>p.trim());
assert.deepEqual(paths,['test-results/**/*.png','test-results/**/*.webm']);
for(const path of ['test-results/trace.zip','test-results/auth.har','playwright-report/index.html','test-results/login.json'])assert(!paths.some(p=>path.endsWith(p.slice(p.lastIndexOf('.')))));
assert(!/tracing\.start|recordHar\s*:|storageState\s*:/i.test(source),'Live suite must not independently persist authentication data');
console.log('PASS production trace capture disabled; upload allowlist excludes trace, HAR, report and authentication-state files; no live requests');
