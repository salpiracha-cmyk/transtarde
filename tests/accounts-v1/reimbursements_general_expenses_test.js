'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),read=p=>fs.readFileSync(p,'utf8');
const master=JSON.parse(read('accounts/accounting_master_v1.json')),api=read('api/expenses_v1.php'),ui=read('accounts/expenses-v1-ui.js');
const people=Object.fromEntries((master.peopleSubledgers||[]).map(x=>[x.code,x]));
for(const code of ['REIMB-SALMAN','REIMB-TALHA','REIMB-ABU','REIMB-TAYYAB']){
 assert.ok(people[code],code+' must exist');
 assert.equal(people[code].class,'Liability');
 assert.equal(people[code].normal,'Credit');
}
for(const code of ['FAM-SALMAN','FAM-TALHA','FAM-ABU','FAM-TAYYAB']){
 assert.ok(people[code],code+' must exist');
 assert.equal(people[code].class,'Equity');
 assert.equal(people[code].normal,'Debit');
}
const rules=Object.fromEntries((master.postingRules||[]).map(x=>[x.event,x]));
for(const event of ['EXPENSE_REIMBURSEMENT_CAPTURE','EXPENSE_REIMBURSEMENT_SETTLE','GENERAL_EXPENSE_PAYMENT'])assert.ok(rules[event],event+' rule missing');

const reimbursement={expense:18000,settlements:[7000,11000]};
const capture={debit:{account:'6900',amount:reimbursement.expense},credit:{account:'REIMB-TALHA',amount:reimbursement.expense}};
const settlementEntries=reimbursement.settlements.map(amount=>({debit:{account:'REIMB-TALHA',amount},credit:{account:'1110',amount}}));
assert.equal(capture.debit.amount,capture.credit.amount);
assert.equal(settlementEntries.reduce((s,x)=>s+x.debit.amount,0),reimbursement.expense);
assert.equal(capture.credit.amount-settlementEntries.reduce((s,x)=>s+x.debit.amount,0),0);
assert.equal([capture,...settlementEntries].filter(x=>x.debit.account==='6900').length,1,'expense must be recognized once');

const direct={debit:{account:'6400',amount:42000},credit:{account:'1120',amount:42000}};
assert.equal(direct.debit.amount,direct.credit.amount);
assert.match(api,/capture_reimbursement/);
assert.match(api,/settle_reimbursement/);
assert.match(api,/pay_general_expense/);
assert.match(api,/'EXPENSE_REIMBURSEMENT_CAPTURE'/);
assert.match(api,/'EXPENSE_REIMBURSEMENT_SETTLE'/);
assert.match(api,/'GENERAL_EXPENSE_PAYMENT'/);
assert.match(api,/Settlement cannot exceed the outstanding reimbursement/);
assert.match(api,/This reimbursement reference is already recorded/);
assert.match(api,/This expense reference is already recorded/);
assert.match(api,/\['MILL','OFFICE','OTHER'\]/);
assert.match(api,/tt_user_can_access_entity/);
assert.match(ui,/data-expense="reimburse"/);
assert.match(ui,/data-expense="general"/);
assert.match(ui,/Partial settlement is allowed/);
assert.match(ui,/does not record the expense again/);
console.log('Reimbursements and General Expenses deterministic QA passed.');
