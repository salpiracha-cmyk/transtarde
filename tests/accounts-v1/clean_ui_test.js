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

requireText(ui, 'captureWorkspaceLaunchers', 'preserve native feature adapters');
for(const obsolete of ['const groups =','function buildDialog','function rebuildHome','openGroup(activeGroup)']) {
  if(ui.includes(obsolete)) throw new Error('Obsolete home writer remains: '+obsolete);
}
for (const marker of ['popupWorkspaces: true','searchableSelects: true','Type 1 or 2 letters to search','× Close','prepareSecondTier','Generated automatically when saved','Bill adjustments (only when required)']) requireText(ui, marker, 'shared form controls');
const desk=read('accounts/accounts-accounting-desk.js');
requireText(desk,"action.native==='reconciliation'",'reconciliation icon routes to operational form');
requireText(desk,'TT_BANK_RECONCILIATION.open()','operational reconciliation owner');
const template=read('accounts/Transtrade_Accounts_Master_V1.html');
for(const marker of ['Foundation:','const postPreview=','function initJv']) if(template.includes(marker)) throw new Error('Obsolete template form handler remains: '+marker);
requireText(ui, 'settleSalaryView', 'render-aware salary and rent mode');
requireText(ui, "root.closest?.('#expenseEditor')", 'salary mutation ancestor detection');
requireText(ui, "if (!workspace?.classList.contains('active')) return;", 'background expense updates cannot reopen closed modals');
requireText(ui, "button.removeAttribute('data-back')", 'independent popup close control');
requireText(ui, "function stageEditor", 'shared native editor staging');
requireText(ui, "button.removeAttribute('data-editor-back')", 'legacy editor handler isolation');
requireText(ui, "tt:accounts-desk-form-opened", 'Accounts desk form close-shell integration');

requireText(read('accounts/rent-salary-ui.js'), "action:'complete_salary_month'", 'single final salary posting');
requireText(salaryApi, "$action==='salary_batch_payment'", 'salary API');
requireText(salaryApi, "SALARY_BATCH_PAYMENT", 'single salary voucher');
requireText(salaryApi, "Cheque number is required for a bank salary payment.", 'salary cheque control');
requireText(salaryApi, "'salaryBatch'=>true", 'salary audit metadata');

requireText(workflowUi, 'value="INSPECTION"', 'inspection UI');
requireText(workflowApi, "['CLEARING','FUMIGATION','INSPECTION']", 'inspection validation');
requireText(workflowApi, "'INSPECTION'=>['5530'", 'inspection posting');

const settlementUi=read('accounts/supplier-settlement-ui.js');
const receiptVoucherUi=read('accounts/export-receipts-ui.js');
const settlementApi=read('api/supplier_settlements.php');
requireText(settlementUi, 'Print Payment Voucher', 'posted supplier payment voucher');
requireText(receiptVoucherUi, 'Print Receipt Voucher', 'posted receipt voucher');
requireText(settlementApi, 'Cheque number and bank are required', 'bank payment reference control');
requireText(bundle, "'accounts-clean-ui.js'", 'bundle');
if (bundle.indexOf("'accounts-clean-ui.js'") < bundle.indexOf("'reports-ui.js'")) throw new Error('Clean UI must load after feature modules.');
requireText(index, 'app-bundle.php?v=current', 'non-manual bundle URL');
requireText(bundle, "Cache-Control: private, no-cache, must-revalidate", 'bundle revalidation');
if (bundle.includes('immutable')) throw new Error('Accounts bundle must not remain cached without revalidation.');

console.log('Accounts clean UI and workflow assertions passed.');
