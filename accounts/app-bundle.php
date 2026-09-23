<?php
declare(strict_types=1);

require __DIR__ . '/../auth_store.php';

$user = tt_require_login();
if (!tt_user_can_open_module($user, 'Accounts')) {
    http_response_code(403);
    exit('You do not have permission to open this module.');
}

$files = [
    'accounts-runtime.js',
    'accounts-live.js',
    'accounts-enhancements.js',
    'accounts-navigation-ui.js',
    'rent-salary-ui.js',
    'donations-ui.js',
    'expenses-v1-ui.js',
    'jv-workflow-ui.js',
    'export-realization-master-bootstrap.js',
    'export-realization-master-ui.js',
    'export-tax-certificates-ui.js',
    'export-tax-certificates-fix.js',
    'bank-accounts-ui.js',
    'bank-reconciliation-ui.js',
    'bank-direct-entry-ui.js',
    'tg-bank-transfer-ui.js',
    'tg-bank-transactions-ui.js',
    'tg-liabilities-ui.js',
    'tg-year-end-revaluation-ui.js',
    'export-receipts-ui.js',
    'retention-remittance-ui.js',
    'bill-smart-ui-v2.js',
    'purchase-commodity-split-ui.js',
    'bag-ops-sync-ui.js',
    'bag-purchases-ui.js',
    'nonwoven-bag-ui.js',
    'bag-bill-review-ui.js',
    'sales-tax-refund-ui.js',
    'other-purchases-ui.js',
    'bill-payment-terms.js',
    'payables-commodity-split-ui.js',
    'bag-supplier-payments-ui.js',
    'other-supplier-payments-ui.js',
    'supplier-payment-planning-ui.js',
    'payment-planning-bank-funds.js',
    'supplier-settlement-ui.js',
    'payables-bill-summary-ui.js',
    'rice-payment-overpayment-guard.js',
    'supplier-payment-broker-summary-ui.js',
    'supplier-payment-history-ui.js',
    'supplier-ledger-ui.js',
    'rice-payables-link-ui.js',
    'brokerage-wht-ui.js',
    'local-sales-control-ui.js',
    'local-sales-payment-ui.js',
    'local-customer-ledger-ui.js',
    'local-customer-receipts-ui.js',
    'local-sales-costing-ui.js',
    'production-costing-ui.js',
    'management-costing-ui.js',
    'production-fixed-overhead-ui.js',
    'production-inventory-transfer-ui.js',
    'export-recognition-ui.js',
    'export-costing-ui.js',
    'costing-sheet-cogs-ui.js',
    'accounts-v1-workflow-ui.js',
    'commodity-calculation-ui.js',
    'transport-controls-ui.js',
    'reports-ui.js',
    'profitability-ui.js',
    'accounts-clean-ui.js',
    'master-autocomplete.js',
    'accounts-accounting-desk.js',
];

$versionParts = [];
foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        http_response_code(503);
        exit('Accounts application asset is unavailable.');
    }
    $versionParts[] = $file . ':' . (string)filesize($path) . ':' . (string)filemtime($path);
}

$etag = '"' . sha1(implode('|', $versionParts)) . '"';
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: private, no-cache, must-revalidate');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

foreach ($files as $file) {
    echo "\n;/* " . str_replace('*/', '', $file) . " */\n";
    readfile(__DIR__ . '/' . $file);
}
