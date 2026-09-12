'use strict';

const fs = require('fs');

const read = path => fs.readFileSync(path, 'utf8');
const ui = read('accounts/accounts-clean-ui.js');
const bundle = read('accounts/app-bundle.php');
const index = read('accounts/index.php');
const salaryApi = read('api/rent_salary_v2.php');
const workflowApi = read('api/accounts_workflows_v1.php');
const workflowUi = read('accounts/accounts-v1-workflow-ui.js');

const requireText = (source, value, label) => {
  if (!source.includes(value)) throw new Error(`${label}: missing ${value}`);
};

for (const label of ['Purchases','Ledgers','Bags','Local Sales','Export Bills','Expenses & Overheads','Reports','Masters']) {
  requireText(ui, `title:'${label}'`, 'clean Accounts hub');
}

for (const marker of ['popupWorkspaces: true','searchableSelects: true','Type 1 or 2 letters to search','× Cancel','Generated automatically when saved','Bill adjustments (only when required)']) {
  requireText(ui, marker, 'clean UI rule');
}

requireText(ui, "action:'salary_batch_payment'", 'salary client');
requireText(salaryApi, "$action==='salary_batch_payment'", 'salary API');
requireText(salaryApi, "SALARY_BATCH_PAYMENT", 'single salary voucher');
requireText(salaryApi, "Cheque number is required for a bank salary payment.", 'salary cheque control');
requireText(salaryApi, "'salaryBatch'=>true", 'salary audit metadata');

requireText(workflowUi, 'value="INSPECTION"', 'inspection UI');
requireText(workflowApi, "['CLEARING','FUMIGATION','INSPECTION']", 'inspection validation');
requireText(workflowApi, "'INSPECTION'=>['5530'", 'inspection posting');

requireText(bundle, "'accounts-clean-ui.js'", 'bundle');
if (bundle.indexOf("'accounts-clean-ui.js'") < bundle.indexOf("'reports-ui.js'")) throw new Error('Clean UI must load after feature modules.');
requireText(index, 'app-bundle.php?v=20260912-8', 'cache version');

console.log('Accounts clean UI and workflow assertions passed.');
