const fs=require('fs');
const path=require('path');
const vm=require('vm');
const root=path.resolve(__dirname,'..');
const numbered=Array.from({length:16},(_,i)=>path.join(root,'exports',`app.part${String(i+1).padStart(2,'0')}.txt`));
const files=[...numbered,path.join(root,'exports','app.part18.txt'),path.join(root,'exports','app.part19.txt'),path.join(root,'exports','app.part17.txt')];
for(const f of files){if(!fs.existsSync(f))throw new Error(`Missing bundle part: ${path.basename(f)}`)}
const src=files.map(f=>fs.readFileSync(f,'utf8')).join('');
try{new vm.Script(src,{filename:'export-bundle.js'})}catch(e){console.error('BUNDLE SYNTAX FAILURE');console.error(e.stack);const m=String(e.stack).match(/export-bundle\.js:(\d+)/);if(m){const n=Number(m[1]),ls=src.split('\n');console.error('\nContext:');for(let i=Math.max(0,n-4);i<Math.min(ls.length,n+3);i++)console.error(`${i+1}: ${ls[i].slice(0,500)}`)}process.exit(1)}

globalThis.window=globalThis;
globalThis.__TT_ASSETS={TTI_header:'tti-header',TTI_sign:'tti-sign',BRM_header:'brm-header',BRM_sign:'brm-sign',TG_header:'tg-header',TG_footer:'tg-footer',TG_sign:'tg-sign'};
try{delete globalThis.document}catch{}
try{(0,eval)(src)}catch(e){console.error('BUNDLE SAFE-LOAD FAILURE');throw e}
if(!globalThis.__TT_EXPORT_TEST__)throw new Error('Export self-test API not exposed');
const result=globalThis.__TT_EXPORT_TEST__.runAcceptanceSelfTests();
for(const r of result.results)console.log(`${r.pass?'PASS':'FAIL'}  ${r.name}${r.detail?` — ${r.detail}`:''}`);
if(result.passed!==result.total)throw new Error(`Business-rule self-tests failed ${result.passed}/${result.total}`);
const mustInclude=['SAVE CUSTOMER & RETURN TO CONTRACT','REQUEST DIRECTOR REOPEN','GENERATE PO PDF & SEND TO MILL','PRODUCTION INSTRUCTIONS ALREADY SENT','SEND PRODUCTION INSTRUCTIONS TO MILL','CREATE LOT AND SEND INSTRUCTIONS TO MILL','TG-linked Pakistan Customs shipment','BANK COVERING — LAST','FI Register / FI Utilisation','Container / Loading Register'];
for(const s of mustInclude)if(!src.includes(s))throw new Error(`Required workflow text missing: ${s}`);
if(!src.includes("footer:''")||!src.includes('footer:BRAND_ASSETS.TG_footer'))throw new Error('TTI/BRM/TG footer rule not present');
if(!src.includes('WORKSPACE_ROWS'))throw new Error('Row accordion workspace implementation missing');
const loader=fs.readFileSync(path.join(root,'exports','app.js'),'utf8');
if(loader.includes('asset.TG_header.part')||loader.includes('asset.TTI_header.part'))throw new Error('Loader still depends on missing asset fragment files');
if(!loader.includes("'app.part18.txt','app.part19.txt','app.part17.txt'"))throw new Error('Loader does not execute final release hardening before final mount');
const css=fs.readFileSync(path.join(root,'exports','sol-fixes.css'),'utf8');
if(!css.includes('#ttUserBar{display:none!important}'))throw new Error('Bottom-right shared Sign out is not hidden in Export');
if(!css.includes('#ttSyncNotice{display:none!important}'))throw new Error('Shared sync/update notice is still visible in Export');
console.log(`\nExport SOL acceptance self-tests: ${result.passed}/${result.total} passed.`);
