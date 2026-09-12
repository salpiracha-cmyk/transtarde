'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),read=p=>fs.readFileSync(p,'utf8');
const index=read('accounts/index.php'),html=read('accounts/Transtrade_Accounts_Master_V1.html'),master=JSON.parse(read('accounts/accounting_master_v1.json'));
const scriptRefs=[...index.matchAll(/script src="([^"]+)"/g)].map(x=>x[1]);
assert.equal(new Set(scriptRefs).size,scriptRefs.length,'Accounts must not load a script twice');
for(const ref of scriptRefs){const file=ref.split('?')[0],resolved=file.startsWith('/')?file.slice(1):path.join('accounts',file);assert.ok(fs.existsSync(resolved),file+' referenced by Accounts must exist');}
const bundledScripts=[...read('accounts/app-bundle.php').matchAll(/'([^']+\.js)'/g)].map(x=>x[1]);
assert.equal(new Set(bundledScripts).size,bundledScripts.length,'Accounts bundle must not load a script twice');
for(const file of bundledScripts)assert.ok(fs.existsSync(path.join('accounts',file)),file+' referenced by Accounts bundle must exist');
for(const key of ['expenses','purchases','bank','receivables','payables','jv','reconciliation','tg','reports','masters'])assert.match(html,new RegExp('data-key="'+key+'"'));

const uiRefs=scriptRefs.map(x=>x.split('?')[0]).filter(x=>x.endsWith('.js'));
if(scriptRefs.some(x=>x.split('?')[0]==='app-bundle.php')){
  const bundle=read('accounts/app-bundle.php');
  const bundledRefs=[...bundle.matchAll(/^\s*'([^']+\.js)',\s*$/gm)].map(x=>x[1]);
  assert.ok(bundledRefs.length>0,'Accounts bundle must declare its ordered JavaScript files');
  uiRefs.push(...bundledRefs);
}
assert.equal(new Set(uiRefs).size,uiRefs.length,'Accounts bundle must not load a script twice');
for(const file of uiRefs){const resolved=file.startsWith('/')?file.slice(1):path.join('accounts',file);assert.ok(fs.existsSync(resolved),file+' referenced by Accounts bundle must exist');}
const allUi=uiRefs.map(x=>read(path.join('accounts',x))).join('\n');
for(const key of ['utility','card','rent','salary','donations','reimburse','general'])assert.match(allUi,new RegExp('data-expense=[\\\'"]'+key));
for(const key of ['commodity','other'])assert.match(allUi,new RegExp('data-purchase=[\\\'"]'+key));

const secured=['api/accounts.php','api/donations.php','api/expenses_v1.php','api/journal_vouchers.php','api/other_purchases.php','api/rent_salary_v2.php','api/production_costing.php','api/production_fixed_overhead.php','api/export_accounting.php','api/accounts_workflows_v1.php'];
for(const file of secured){const s=read(file);assert.match(s,/tt_require_login/,file+' login');assert.match(s,/tt_user_can_open_module/,file+' module access');assert.match(s,/tt_verify_csrf/,file+' CSRF');assert.match(s,/LOCK_EX/,file+' locked writes');assert.match(s,/tt_user_can_access_entity/,file+' entity access');}
const reports=read('api/accounts_reports.php');
assert.match(reports,/\(\$journal\['entity'\]\s*\?\?\s*''\)\s*===\s*\$entity/);
assert.match(reports,/\(\$journal\['status'\]\s*\?\?\s*''\)\s*===\s*'Posted'/);
assert.doesNotMatch(reports,/jvDrafts/,'draft JVs must never enter financial reports');

const jv=read('api/journal_vouchers.php'),legacy=read('api/accounts.php');
assert.match(jv,/'jvDrafts'=>\[\]/);
assert.match(jv,/Pending Approval/);
assert.match(jv,/The preparer cannot approve their own JV/);
assert.match(jv,/This JV has already been reversed/);
assert.match(legacy,/Direct JV posting has been retired/);

const donation=read('api/donations.php'),expenses=read('api/expenses_v1.php'),purchases=read('api/other_purchases.php');
for(const s of [donation,expenses,purchases]){assert.match(s,/\$entity==='TG'\?'AED':'PKR'|\$e==='TG'\?'AED':'PKR'/);}
for(const s of [read('accounts/donations-ui.js'),read('accounts/other-purchases-ui.js'),read('accounts/reports-ui.js')])assert.match(s,/entity\(\)==='TG'\?'AED':'Rs'/);

const people=Object.fromEntries((master.peopleSubledgers||[]).map(x=>[x.code,x]));
for(const code of ['FAM-SALMAN','FAM-TALHA','FAM-ABU','FAM-TAYYAB']){assert.equal(people[code].class,'Equity');assert.equal(people[code].normal,'Debit');}
for(const code of ['REIMB-SALMAN','REIMB-TALHA','REIMB-ABU','REIMB-TAYYAB']){assert.equal(people[code].class,'Liability');assert.equal(people[code].normal,'Credit');}
for(const code of ['7210','7220','7230'])assert.ok(master.chart.some(x=>String(x.code)===code));
console.log('Accounts end-to-end structural, security, currency and reporting audit passed.');
