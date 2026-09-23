<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function export_tg_unallocated_out(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Exports')) export_tg_unallocated_out(['ok' => false, 'error' => 'Exports access required.'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_tg_unallocated_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    $path = TT_DATA_DIR . '/accounts.json';
    $rows = [];
    if (is_file($path)) {
        $handle = fopen($path, 'r');
        if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('Accounts data unavailable.');
        try { $raw = stream_get_contents($handle); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
        $store = $raw ? json_decode($raw, true) : [];
        foreach ((array)($store['tgBankTransactions'] ?? []) as $tx) {
            if (!is_array($tx) || ($tx['kind'] ?? '') !== 'Payment' || ($tx['paymentType'] ?? '') !== 'LIABILITY' || ($tx['referenceType'] ?? '') !== 'UNALLOCATED' || empty($tx['pakistanCandidateId'])) continue;
            $rows[] = ['id' => (string)($tx['id'] ?? ''), 'date' => (string)($tx['date'] ?? ''), 'currency' => (string)($tx['currency'] ?? ''), 'amount' => (float)($tx['amountNative'] ?? 0), 'invoiceRef' => (string)($tx['invoiceRef'] ?? ''), 'bankReference' => (string)($tx['bankReference'] ?? ''), 'pakistanExporter' => (string)(($store['exportCandidates'][$tx['pakistanCandidateId']]['entity'] ?? ''))];
        }
    }
    usort($rows, static fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['id'], $a['id']));
    export_tg_unallocated_out(['ok' => true, 'payments' => $rows]);
} catch (Throwable $error) {
    export_tg_unallocated_out(['ok' => false, 'error' => 'Unallocated TG payments could not be loaded.'], 500);
}
