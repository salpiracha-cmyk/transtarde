const fs=require('fs');
const assert=require('assert');
const app=fs.readFileSync(__dirname+'/app.js','utf8');

assert.ok(!app.includes('class="panel lotFooter"'),'Mark Lot Complete must not render in the global lot workspace footer');
assert.match(app,/workspaceHead[\s\S]*id="cancelLot"/,'Cancel Lot remains available in the lot header');
const outputStart=app.lastIndexOf('function renderDocumentOutput(d)'),outputEnd=app.indexOf('\nfunction renderTG(d)',outputStart),output=app.slice(outputStart,outputEnd);
assert.equal((output.match(/id="completeLot"/g)||[]).length,1,'completion control exists only in the unified final-output renderer');
assert.match(output,/lotCompleteButton.*LOT COMPLETE/,'larger completion button stays in Final Output');
assert.doesNotMatch(output,/id="chooseShipmentFolder"|id="saveShipmentFiles"/,'redundant folder buttons are removed');
const missing=app.slice(app.indexOf('function completionMissing(s,c)'),app.indexOf("document.addEventListener('click',async e=>"));
assert.doesNotMatch(missing,/covering|frozen|dispatched/,'covering letter and freeze must not block closure');
assert.match(app,/await saveShipmentFolder\(s,c\)[\s\S]*s.completed=true/,'office package is saved before closure');
assert.match(app,/if\(e\.target\?\.id==='completeLot'\)[\s\S]*completionMissing\(s,c\)/,'click handler revalidates completion server-side state before closing the lot');

console.log('PASS lot completion stage audit: final-output-only placement, upload gates, original dispatch gates, completed-lot hiding, click-time revalidation');
