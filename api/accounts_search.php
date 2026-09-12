<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const AS_FILE = TT_DATA_DIR . '/accounts.json';

function as_out(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function as_text(mixed $value): string {
    if (is_scalar($value) || $value === null) return (string)$value;
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
}

function as_first(array $row, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) if (isset($row[$key]) && as_text($row[$key]) !== '') return as_text($row[$key]);
    return $fallback;
}

function as_title(string $collection, string $key, array $row): string {
    return as_first($row, ['voucherNo','journalId','billNo','invoiceNo','sodaNo','pohanch','chequeNo','reference','id'], $key ?: ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $collection) ?? $collection));
}

function as_type(string $collection, array $row): string {
    $given = as_first($row, ['voucherType','eventType','type','kind','transactionType','expenseType']);
    if ($given !== '') return $given;
    $labels = [
        'purchaseSodas'=>'Purchase Soda', 'commodityBills'=>'Commodity Bill', 'supplierSettlements'=>'Supplier Payment Voucher',
        'journals'=>'Journal Voucher', 'exportReceipts'=>'Export Receipt Voucher', 'localCustomerReceipts'=>'Local Sale Receipt Voucher',
        'otherReceipts'=>'Other Receipt Voucher', 'expenses'=>'Expense Voucher', 'serviceBills'=>'Shipment Expense Bill',
        'freightBills'=>'Freight Bill', 'transportBills'=>'Transport Bill', 'salaryPayments'=>'Salary Payment Voucher',
        'rentPayments'=>'Recurring Payment Voucher', 'events'=>'Accounting Event'
    ];
    return $labels[$collection] ?? ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $collection) ?? $collection);
}

function as_printable(string $collection, array $row): bool {
    if (isset($row['voucherNo']) || isset($row['journalId'])) return true;
    return (bool)preg_match('/journal|settlement|payment|receipt|bill|expense/i', $collection);
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) as_out(['ok'=>false, 'error'=>'Accounts permission required.'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') as_out(['ok'=>false, 'error'=>'Method not allowed.'], 405);
    $entity = strtoupper(trim((string)($_GET['entity'] ?? 'TTI')));
    $allowed = tt_user_accounts_entities($user);
    if (!in_array($entity, $allowed, true)) as_out(['ok'=>false, 'error'=>'You do not have access to the selected legal books.'], 403);
    $query = trim((string)($_GET['q'] ?? ''));
    if (strlen($query) < 1 || strlen($query) > 120) as_out(['ok'=>false, 'error'=>'Enter a valid search value.'], 422);
    if (!is_file(AS_FILE)) as_out(['ok'=>true, 'results'=>[]]);
    $handle = fopen(AS_FILE, 'r');
    if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('store');
    try { $raw = stream_get_contents($handle); }
    finally { flock($handle, LOCK_UN); fclose($handle); }
    $store = $raw ? json_decode($raw, true) : null;
    if (!is_array($store)) as_out(['ok'=>true, 'results'=>[]]);
    $results = []; $needle = strtolower($query);
    foreach ($store as $collection => $records) {
        if (!is_array($records) || in_array($collection, ['revision','settings','masters'], true)) continue;
        foreach ($records as $key => $row) {
            if (!is_array($row)) continue;
            $rowEntity = strtoupper((string)($row['entity'] ?? $row['legalEntity'] ?? ''));
            if ($rowEntity !== '' && $rowEntity !== $entity) continue;
            $haystack = strtolower((string)$key . ' ' . as_text($row));
            if (!str_contains($haystack, $needle)) continue;
            $amount = as_first($row, ['amount','total','netAmount','finalCommodityValue','supplierPayableTotal','grossPkr','totalDebit']);
            $results[] = [
                'title'=>as_title((string)$collection, (string)$key, $row),
                'type'=>as_type((string)$collection, $row),
                'date'=>as_first($row, ['date','voucherDate','billDate','receiptDate','sodaDate','createdAt']),
                'party'=>as_first($row, ['party','broker','supplier','vendor','customer','payee','receivedFrom','accountName']),
                'amount'=>$amount !== '' ? $amount : null,
                'printable'=>as_printable((string)$collection, $row),
                'data'=>$row,
                '_sort'=>as_first($row, ['date','voucherDate','billDate','receiptDate','sodaDate','createdAt'])
            ];
            if (count($results) >= 100) break 2;
        }
    }
    usort($results, static fn(array $a, array $b): int => strcmp((string)$b['_sort'], (string)$a['_sort']));
    foreach ($results as &$result) unset($result['_sort']);
    as_out(['ok'=>true, 'results'=>array_slice($results, 0, 50)]);
} catch (Throwable $error) {
    error_log('accounts_search: ' . $error->getMessage());
    as_out(['ok'=>false, 'error'=>'Previous Accounts entries are temporarily unavailable.'], 500);
}
