const assert=require('node:assert/strict');
const fs=require('node:fs');

const read=file=>fs.readFileSync(file,'utf8');
const bridge=read('module.php');
const mill=read('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html');
const exportsApp=read('exports/app.js');

assert.match(bridge,/put\('tt40exinstructions',exInstructions\)/,'Export Ex-Mill allocations must be stored as instructions');
assert.doesNotMatch(bridge,/exmills\.push\(/,'Export must never create an Ex-Mill SODA');
assert.match(bridge,/exmills=exmills\.filter\(x=>x\._ttBridge!=='exports'\)/,'legacy Export-created pseudo-SODAs must be removed');

const exMillMarkup=mill.slice(mill.indexOf('<section id="exmill"'),mill.indexOf('<section id="export"'));
assert.doesNotMatch(exMillMarkup,/SODA Conditions|Soda Quantity|Soda balance/i,'Mill staff must not see SODA details in Ex-Mill loading');
assert.match(exMillMarkup,/Loading Instructions/,'the selected outside mill must expose Loading Instructions');
assert.match(mill,/event\.target\.closest\('input,select,button,textarea'\)/,'form controls must not collapse the parent loading row');
assert.match(mill,/data-ex-instruction-id/,'Ex-Mill rows must use instruction identity rather than SODA identity');
assert.match(mill,/function chooseMill\(id\)[\s\S]*?exMillInstructions\(\)\.find[\s\S]*?openExMillWorkspace\(\);selectExMill\(exMillName\)/,'selecting an instructed outside mill must open its loading form directly');
assert.match(mill,/instructionId:x\.id,sodaId:soda\.id/,'loads must keep exact instruction identity and the hidden Accounts authorization link');
assert.match(mill,/STORE_INSTRUCTION_SEEN='tt40instructionseen'/,'unseen Export instruction state must persist per Mill user');
assert.match(mill,/class="update-count"/,'green numbered update badges must be present on Mill home');
assert.match(mill,/border-radius:50%;background:#18a34a/,'new instruction count must use the approved green circular badge');
assert.doesNotMatch(mill,/OPEN LOADING INSTRUCTION\$\{open===1/,'mill cards must not show an open-instruction sentence inside the box');
assert.doesNotMatch(mill,/Waiting for valid Accounts authorization/,'normal loading workflow must not be described as an authorization request');
assert.match(mill,/exMillVarietyKey\(instruction\.baseVariety/,'Accounts SODA matching must normalize the Export product description');
assert.match(mill,/sodaLoaded\+kg>Number\(soda\.qtyKg\|\|0\)\*1\.05/,'container posting must enforce the approved Accounts tolerance');

assert.match(exportsApp,/await validateExMillSodaCapacity\(normalized,c\)/,'Exports must validate Accounts authorization before issuing an Ex-Mill instruction');
assert.match(exportsApp,/reserved\+here>capacity/,'Exports must include prior instructions when enforcing the authorization ceiling');

console.log('PASS Ex-Mill instruction authority, hidden SODA UI, stable accordion, badges, and quantity ceilings');
