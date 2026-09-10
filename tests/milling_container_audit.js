const fs=require('fs');
const assert=require('assert');
const vm=require('vm');

const html=fs.readFileSync(__dirname+'/../milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const start=html.indexOf('function containerFormat');
const end=html.indexOf('function containerExists');
assert.ok(start>=0&&end>start,'container helper functions must exist');

const context={};
vm.runInNewContext(html.slice(start,end),context);

assert.equal(context.containerFormat('ttqu0000083'),'TTQU000008-3','container input is normalized');
assert.equal(context.containerIdentity('TTQU000008-3'),'TTQU000008','permanent identity excludes the derived check digit');
assert.equal(context.containerIdentity('TTQU000008-6'),'TTQU000008','changing only the check digit cannot create another identity');
assert.equal(context.validContainer('TTQU000008-3'),true,'valid ISO 6346 check digit is accepted');
assert.equal(context.validContainer('TTQU000008-6'),false,'wrong ISO 6346 check digit is rejected');
assert.match(html,/function containerExists\(c\)[\s\S]{0,700}containerIdentity\(v\)===id/,'duplicate lookup compares permanent container identities');
assert.ok(html.includes('Changing only the check digit is not allowed.'),'operator receives a clear duplicate-identity message');

console.log('PASS milling container audit: ISO check digit and permanent duplicate identity are enforced');
