const fs=require('fs');
const assert=require('node:assert/strict');
const app=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');

assert.match(app,/function ttSyncFobVisibility\(\)/);
assert.match(app,/const entered=String\(freight\.value\|\|''\)\.trim\(\)!==''/);
assert.match(app,/fob\.value=entered\?priceComponents\(packing,contractDraft\)\.fob\.toFixed\(2\):''/);
assert.match(app,/\[data-contract-rate\],\[data-freight\],\[data-ins\]/);

for(const key of ['removedPackingTypes','removedCurrencies','removedInspections'])assert.match(app,new RegExp(key));
for(const group of ['packing','currency','inspection'])assert.match(app,new RegExp("ttPrepareRemovableSelect\\([^\\n]*'"+group+"'"));
assert.match(app,/data-remove-option/);
assert.match(app,/class="optionMinus"/);
assert.match(app,/Permanently remove/);
assert.match(app,/Existing contracts and documents will keep their saved value/);
assert.match(app,/ttActiveOptions\('currency'\)/);
assert.match(app,/ttOptionIsRemoved\('currency',option\.value\)/);

console.log('PASS blank FOB and removable configurable dropdown options audit');
