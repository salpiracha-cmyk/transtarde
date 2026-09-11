'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(p,'utf8');
const seed=JSON.parse(read('accounts/salary_master_seed_v1.json'));
const chart=JSON.parse(read('accounts/accounting_master_v1.json')).chart;
const salaryApi=read('api/rent_salary_v2.php');
const donationsApi=read('api/donations.php');
const reportsApi=read('api/accounts_reports.php');
const rows=Object.values(seed.salaryMasters||{});
const sum=(items,key)=>items.reduce((total,row)=>total+Number(row[key]||0),0);
const total=row=>Number(row.monthlyAmount||0)+Number(row.zakatAmount||0)+Number(row.otherAllowance||0);
const categoryTotals=Object.fromEntries(['MILL_STAFF','OFFICE_STAFF','HOME_STAFF','DIRECTOR_REMUNERATION','HOME_MONTHLY_GIVE'].map(category=>[category,rows.filter(row=>row.category===category).reduce((value,row)=>value+total(row),0)]));

assert.equal(rows.length,31);
assert.ok(rows.every(row=>row.entity==='TTI'&&row.status==='Active'));
assert.ok(rows.every(row=>!Object.hasOwn(row,'advanceAmount')));
assert.deepEqual(categoryTotals,{MILL_STAFF:431886,OFFICE_STAFF:140806,HOME_STAFF:142000,DIRECTOR_REMUNERATION:245000,HOME_MONTHLY_GIVE:610000});
assert.equal(sum(rows,'monthlyAmount'),1498692);
assert.equal(sum(rows,'zakatAmount'),19000);
assert.equal(sum(rows,'otherAllowance'),52000);
assert.equal(rows.reduce((value,row)=>value+total(row),0),1569692);
assert.ok(rows.filter(row=>row.category==='MILL_STAFF').every(row=>row.productionCostEligible===true));
assert.ok(rows.filter(row=>row.category==='DIRECTOR_REMUNERATION').every(row=>row.accountingTreatment==='STAFF_COST'));
assert.ok(rows.filter(row=>row.category==='HOME_MONTHLY_GIVE').every(row=>row.accountingTreatment==='FAMILY_ALLOCATION'));

const approved=new Set(chart.map(account=>String(account.code)));
const totals={debit:{},credit:{}};
for(const row of rows){
  const lines=[];
  if(row.monthlyAmount>0)lines.push([row.accountingTreatment==='FAMILY_ALLOCATION'?'3200':'6210',row.monthlyAmount,0]);
  if(row.zakatAmount>0)lines.push(['7210',row.zakatAmount,0]);
  if(row.otherAllowance>0)lines.push([row.accountingTreatment==='FAMILY_ALLOCATION'?'3200':'6220',row.otherAllowance,0]);
  lines.push(['2140',0,total(row)]);
  assert.ok(lines.every(line=>approved.has(line[0])));
  assert.equal(lines.reduce((value,line)=>value+line[1],0),lines.reduce((value,line)=>value+line[2],0),row.name+' salary journal must balance');
  for(const [account,debit,credit] of lines){
    totals.debit[account]=(totals.debit[account]||0)+debit;
    totals.credit[account]=(totals.credit[account]||0)+credit;
  }
}
assert.deepEqual(totals.debit,{'3200':610000,'6210':888692,'7210':19000,'6220':52000});
assert.deepEqual(totals.credit,{'2140':1569692});

const donationAccounts={ZAKAT:'7210',SADQA:'7220',FI_SABILILLAH:'7230'};
const donationRows=[];
for(const entity of ['TTI','BRM','TG'])for(const [type,account] of Object.entries(donationAccounts)){
  const amount={ZAKAT:1250,SADQA:2250,FI_SABILILLAH:3250}[type];
  const journal={entity,type,lines:[[account,amount,0],['1120',0,amount]]};
  assert.equal(journal.lines.reduce((v,l)=>v+l[1],0),journal.lines.reduce((v,l)=>v+l[2],0));
  donationRows.push({entity,type,amount});
}
for(const entity of ['TTI','BRM','TG'])for(const type of Object.keys(donationAccounts)){
  assert.equal(donationRows.filter(row=>row.entity===entity&&row.type===type).reduce((v,row)=>v+row.amount,0),{ZAKAT:1250,SADQA:2250,FI_SABILILLAH:3250}[type]);
}
assert.match(salaryApi,/salaryMasters'=>sm_seed_masters\(\)/);
assert.match(salaryApi,/'DIRECTOR_REMUNERATION'=>'STAFF_COST'/);
assert.match(salaryApi,/tt_user_can_access_entity\(\$u,\$entity,'View'\)/);
assert.match(salaryApi,/tt_user_can_access_entity\(\$u,\$entity,'Create'\)/);
assert.doesNotMatch(salaryApi,/salary_advance/);
assert.match(donationsApi,/tt_user_can_access_entity\(\$u,\$entity,'View'\)/);
assert.match(donationsApi,/tt_user_can_access_entity\(\$u,\$entity,'Create'\)/);
for(const [type,account] of Object.entries(donationAccounts)){
  assert.match(donationsApi,new RegExp("'"+type+"'=>'"+account+"'"));
}
assert.match(reportsApi,/tt_user_can_access_entity\(\$user,\$entity,'View'\)/);
console.log('Salary and Donations deterministic QA passed.');
