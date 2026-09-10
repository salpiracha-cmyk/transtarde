const fs=require('fs');
const assert=require('assert');
const app=fs.readFileSync(__dirname+'/app.js','utf8');

assert.ok(!app.includes('class="panel lotFooter"'),'Mark Lot Complete must not render in the global lot workspace footer');
assert.match(app,/workspaceHead[\s\S]*id="cancelLot"/,'Cancel Lot remains available in the lot header');
assert.equal((app.match(/id="completeLot"/g)||[]).length,2,'completion control exists only in standard and TG final-output renderers');
assert.equal((app.match(/missing\.length\?'':'<button class="btn navy" id="completeLot">MARK LOT COMPLETE<\/button>'/g)||[]).length,2,'completion control is rendered only when every completion gate passes');
assert.match(app,/requiredDispatchDocs[\s\S]*documentCounts[\s\S]*originals/,'required original dispatch quantities are enforced');
assert.match(app,/if\(e\.target\?\.id==='completeLot'\)[\s\S]*completionMissing\(s,c\)/,'click handler revalidates completion server-side state before closing the lot');

console.log('PASS lot completion stage audit: final-output-only placement, upload gates, original dispatch gates, click-time revalidation');
