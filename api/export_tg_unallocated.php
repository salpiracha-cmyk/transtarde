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

function export_tg_pending_amount(mixed $value):float {
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0)throw new RuntimeException('Invalid pending-payment amount.');
    return (float)$value;
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Exports') || !tt_user_can_module_action($user,'Exports','fi','View')) export_tg_unallocated_out(['ok' => false, 'error' => 'Exports access required.'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_tg_unallocated_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    tt_release_read_session();
    $path = TT_DATA_DIR . '/accounts.json';
    $rows = [];
    if (is_file($path)) {
        $handle = fopen($path, 'r');
        if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('Accounts data unavailable.');
        try { $raw = stream_get_contents($handle); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
        $store = $raw ? json_decode($raw, true,512,JSON_THROW_ON_ERROR) : [];
        if(!is_array($store))throw new RuntimeException('Invalid Accounts source.');
        foreach(['exportReceipts','tgBankTransactions','exportCandidates'] as $key)if(isset($store[$key])&&!is_array($store[$key]))throw new RuntimeException('Invalid Accounts records.');
        $completedTg = [];
        foreach ((array)($store['exportReceipts'] ?? []) as $receipt) if (is_array($receipt) && ($receipt['status']??'')!=='Reversed for Amendment' && (string)($receipt['tgPaymentId'] ?? '') !== '') {
            $payment=(string)$receipt['tgPaymentId'];$completedTg[$payment]=round(($completedTg[$payment]??0)+export_tg_pending_amount($receipt['foreignAmount']??0)+export_tg_pending_amount($receipt['correspondentForeignAmount']??0),2);
        }
        foreach ((array)($store['tgBankTransactions'] ?? []) as $tx) {
            if (!is_array($tx) || ($tx['kind'] ?? '') !== 'Payment' || ($tx['paymentType'] ?? '') !== 'LIABILITY' || ($tx['referenceType'] ?? '') !== 'UNALLOCATED' || empty($tx['pakistanCandidateId'])) continue;
            if(($tx['status']??'')==='Reversed for Amendment')continue;
            $exporter=(string)($store['exportCandidates'][$tx['pakistanCandidateId']]['entity']??'');if(!in_array($exporter,['TTI','BRM'],true))throw new RuntimeException('TG payment exporter source is missing.');
            $remaining=round(export_tg_pending_amount($tx['amountNative']??0)-(float)($completedTg[(string)($tx['id']??'')]??0),2);if($remaining<=.005)continue;
            $rows[] = ['id' => (string)($tx['id'] ?? ''), 'date' => (string)($tx['date'] ?? ''), 'currency' => (string)($tx['currency'] ?? ''), 'amount' => $remaining, 'invoiceRef' => (string)($tx['invoiceRef'] ?? ''), 'contractRef'=>'', 'bankReference' => (string)($tx['bankReference'] ?? ''), 'pakistanExporter' => $exporter, 'source'=>'TG outgoing payment'];
        }
        foreach ((array)($store['exportReceipts'] ?? []) as $receipt) {
            if (!is_array($receipt) || ($receipt['status']??'')==='Reversed for Amendment' || !in_array((string)($receipt['entity'] ?? ''), ['TTI','BRM'], true)) continue;
            foreach ((array)($receipt['allocations'] ?? []) as $i => $allocation) {
                if (!is_array($allocation) || ($allocation['targetType'] ?? '') !== 'UNAPPLIED_TG') continue;
                $rows[] = ['id'=>(string)($receipt['id']??'').'-'.($i+1),'date'=>(string)($receipt['date']??''),'currency'=>(string)($receipt['transactionCurrency']??''),'amount'=>export_tg_pending_amount($allocation['foreignAmount']??0),'invoiceRef'=>(string)($allocation['invoiceRef']??''),'contractRef'=>(string)($allocation['contractRef']??''),'bankReference'=>(string)($receipt['bankAdviceRef']??''),'pakistanExporter'=>(string)$receipt['entity'],'source'=>'Pakistan bank receipt'];
            }
        }
    }
    usort($rows, static fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['id'], $a['id']));
    export_tg_unallocated_out(['ok' => true, 'payments' => $rows]);
} catch (Throwable $error) {
    export_tg_unallocated_out(['ok' => false, 'error' => 'Unallocated TG payments could not be loaded.'], 500);
}
