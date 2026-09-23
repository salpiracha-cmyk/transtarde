'use strict';

const fs = require('fs');
const assert = require('assert');

const desk = fs.readFileSync('accounts/accounts-accounting-desk.js', 'utf8');
const bundle = fs.readFileSync('accounts/app-bundle.php', 'utf8');
const sodaApi = fs.readFileSync('api/purchase_sodas.php', 'utf8');
const billUi = fs.readFileSync('accounts/bill-smart-ui-v2.js', 'utf8');
const bridge = fs.readFileSync('accounts/source-bridge.js', 'utf8');
const milling = fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html', 'utf8');
const searchApi = fs.readFileSync('api/accounts_search.php', 'utf8');
const auth = fs.readFileSync('auth_store.php', 'utf8');

assert(bundle.includes("'accounts-accounting-desk.js'"), 'professional Accounts desk must be loaded last');
assert(desk.includes("master.id = 'ttMasterTop'"), 'Masters must be available through top-bar M');
assert(desk.includes('New Soda') && desk.includes('Search / Amend Soda'), 'Soda Centre must have New and Search/Amend choices');
assert(desk.includes('Saving a Soda creates an open purchase commitment only'), 'Soda form must explain that no GL entry is made');
assert(desk.includes('Reason for Amendment'), 'Soda amendment reason must be visible and compulsory');
assert(desk.includes("title:'ARRIVAL BILLS'") && !desk.includes("title:'Arrival / Rice Bill'"), 'purchase entry must use the commodity-neutral Arrival Bills label');
assert(!desk.includes('class="btn tt-prev-direct"'), 'duplicate Search Previous button must not appear in the Purchases workspace');
assert(desk.includes('data-soda-delete') && sodaApi.includes("$action === 'delete'"), 'Super Admin must be able to delete an unused Soda from Search/Amend');
assert(billUi.includes('data-open-receipt') && !billUi.includes('type="checkbox" class="ttsb-select"'), 'Arrival Bills must open from a receipt row without tick boxes');
assert(bridge.includes("'POHANCH|' + String(saved.millId") || bridge.includes("'POHANCH|'+String(saved.millId"), 'Pohanch handoff identity must not rely on the reusable printed number');
assert(milling.includes('function editSlip') && milling.includes('Save Amendment'), 'saved unprinted Pohanch must provide an edit workflow');
assert(desk.includes('Search Previous Accounts Entry'), 'every workspace must expose previous-entry search');
assert(desk.includes('Print Voucher'), 'third-party accounting search results must expose voucher print');
assert(desk.includes('Export Payment Received') && desk.includes('Local Sale Payment') && desk.includes('Other Payment Received'), 'receipt work must use the three approved categories');
assert(desk.includes('Freight') && desk.includes('Clearing') && desk.includes('Fumigation') && desk.includes('Inspection') && desk.includes('Transport'), 'shipment bill categories must remain available');
assert(desk.includes(".workspace.tt-clean-modal .accountPreview{display:block!important"), 'live accounting preview must not be hidden');
assert(desk.includes("box.id = 'ttUtilityTreatment'") && desk.includes('Accounting treatment before posting'), 'utility entry must render a live Debit/Credit preview');
assert(desk.includes('Generated automatically'), 'Soda/internal number must be system-generated');
assert(sodaApi.includes("$action === 'amend'"), 'Soda API must support amendments');
assert(sodaApi.includes("'reason'=>$reason") && sodaApi.includes("'changes'=>$changes"), 'Soda audit must retain reason and old/new changes');
assert(sodaApi.includes('tt_purchase_product_profiles()'), 'Soda must use the approved Purchase Product master instead of free-text commodity choices');
assert(searchApi.includes("'voucherNo','journalId','billNo','invoiceNo','sodaNo','pohanch','chequeNo','reference'"), 'universal search must cover accounting and operational references');
assert(searchApi.includes("as_text($row)"), 'universal search must include linked shipment fields such as container, B/L, vessel, line and port');
assert(auth.includes("if (($user['role'] ?? '') === 'Super Admin') return 'index.php'"), 'Super Admin must land in the Control Centre');
assert(auth.includes("if (tt_user_can_open_module($user, 'Accounts')) return 'accounts/index.php'"), 'Accounts staff must still land directly in Accounts');

console.log('Accounts professional desk: passed');
