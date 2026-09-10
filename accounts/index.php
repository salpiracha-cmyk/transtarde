<?php
declare(strict_types=1);
require __DIR__ . '/../auth_store.php';
$user = tt_require_login();
if (!tt_user_can_open_module($user, 'Accounts')) {
    http_response_code(403);
    exit('You do not have permission to open this module.');
}
$file = __DIR__ . '/Transtrade_Accounts_Master_V1.html';
if (!is_file($file)) {
    http_response_code(404);
    exit('Accounts module not found.');
}
header('Content-Type: text/html; charset=UTF-8');
$html = (string)file_get_contents($file);
$access = [
    'module' => 'Accounts',
    'user' => (string)($user['full_name'] ?? ''),
    'role' => (string)($user['role'] ?? ''),
    'permissions' => $user['permissions']['Accounts'] ?? [],
    'super' => (($user['role'] ?? '') === 'Super Admin'),
    'csrf' => tt_csrf(),
];
$bootstrap = '<script>window.TT_ACCOUNT_ACCESS=' . json_encode($access, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . $bootstrap, $html, 1) ?? $html;
$html = preg_replace('/<\/body>/i', '<script src="accounts-live.js?v=20260907-3"></script><script src="accounts-enhancements.js?v=20260907-1"></script><script src="rent-salary-ui.js?v=20260908-1"></script><script src="rent-salary-treatment-ui.js?v=20260908-2"></script><script src="export-realization-master-bootstrap.js?v=20260908-1"></script><script src="export-realization-master-ui.js?v=20260908-2"></script><script src="export-tax-certificates-ui.js?v=20260908-2"></script><script src="export-tax-certificates-fix.js?v=20260908-1"></script><script src="bank-accounts-ui.js?v=20260908-3"></script><script src="bank-reconciliation-ui.js?v=20260910-1"></script><script src="bank-direct-entry-ui.js?v=20260910-1"></script><script src="tg-bank-transfer-ui.js?v=20260908-2"></script><script src="tg-bank-transactions-ui.js?v=20260908-3"></script><script src="tg-liabilities-ui.js?v=20260908-1"></script><script src="tg-year-end-revaluation-ui.js?v=20260908-2"></script><script src="export-receipts-ui.js?v=20260908-1"></script><script src="retention-receipt-filter.js?v=20260908-1"></script><script src="export-receipts-tax-ui-patch.js?v=20260908-1"></script><script src="export-bank-shortfall-ui.js?v=20260908-1"></script><script src="retention-remittance-ui.js?v=20260908-1"></script><script src="bill-smart-ui-v2.js?v=20260909-1"></script><script src="purchase-commodity-split-ui.js?v=20260909-1"></script><script src="bag-ops-sync-ui.js?v=20260910-1"></script><script src="bag-purchases-ui.js?v=20260910-1"></script><script src="nonwoven-bag-ui.js?v=20260910-1"></script><script src="bag-bill-review-ui.js?v=20260910-1"></script><script src="sales-tax-refund-ui.js?v=20260910-1"></script><script src="other-purchases-ui.js?v=20260910-1"></script><script src="rice-soda-control-ui.js?v=20260909-2"></script><script src="bill-payment-terms.js?v=20260908-1"></script><script src="payables-commodity-split-ui.js?v=20260910-2"></script><script src="bag-supplier-payments-ui.js?v=20260910-2"></script><script src="supplier-payment-planning-ui.js?v=20260908-1"></script><script src="payment-planning-bank-funds.js?v=20260908-1"></script><script src="supplier-settlement-ui.js?v=20260908-3"></script><script src="payables-bill-summary-ui.js?v=20260910-1"></script><script src="rice-payment-overpayment-guard.js?v=20260910-1"></script><script src="supplier-payment-broker-summary-ui.js?v=20260910-1"></script><script src="supplier-payment-history-ui.js?v=20260910-1"></script><script src="supplier-ledger-ui.js?v=20260910-1"></script><script src="rice-payables-link-ui.js?v=20260910-1"></script><script src="brokerage-ui.js?v=20260909-1"></script><script src="brokerage-wht-ui.js?v=20260910-1"></script><script src="local-sales-control-ui.js?v=20260908-3"></script><script src="local-sales-payment-ui.js?v=20260908-1"></script><script src="local-customer-ledger-ui.js?v=20260910-1"></script><script src="local-customer-receipts-ui.js?v=20260910-1"></script><script src="local-sales-costing-ui.js?v=20260908-2"></script><script src="production-costing-ui.js?v=20260908-1"></script><script src="management-costing-ui.js?v=20260909-4"></script><script src="production-fixed-overhead-ui.js?v=20260908-3"></script><script src="production-inventory-transfer-ui.js?v=20260908-1"></script><script src="export-recognition-ui.js?v=20260908-1"></script><script src="export-costing-ui.js?v=20260908-1"></script><script src="costing-sheet-cogs-ui.js?v=20260909-2"></script></body>', $html, 1) ?? $html;
echo $html;