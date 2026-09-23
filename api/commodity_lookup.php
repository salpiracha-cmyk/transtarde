<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function cl_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function cl_commodity(array $event, array $meta): string {
    $saved = strtoupper(trim((string)($event['commodity'] ?? $meta['commodity'] ?? '')));
    if (in_array($saved, ['RICE','CORN','SESAME'], true)) return $saved;
    $v = strtoupper((string)($meta['variety'] ?? ''));
    if (str_contains($v, 'CORN') || str_contains($v, 'MAIZE')) return 'CORN';
    if (str_contains($v, 'SESAME')) return 'SESAME';
    return 'RICE';
}
function cl_remove_abandoned_qa_receipts(string $file): int {
    if(!is_file($file))return 0;$h=fopen($file,'c+');if($h===false||!flock($h,LOCK_EX))return 0;
    try{rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))return 0;
        if(!empty($store['cleanupMigrations']['remove_abandoned_qa_receipts_20260923']))return 0;$removed=0;
        foreach((array)($store['events']??[])as$id=>$event){if(!is_array($event)||($event['eventType']??'')!=='COMMODITY_RECEIPT_ACCEPTED'||!empty($event['billId']))continue;$journalId=(string)($event['journalId']??'');$journal=$store['journals'][$journalId]??null;$meta=is_array($journal['meta']??null)?$journal['meta']:[];$broker=strtoupper(trim((string)($meta['broker']??'')));$truck=strtoupper(trim((string)($meta['truck']??'')));if(!str_starts_with($broker,'QA BULK ')&&!str_starts_with($truck,'QA-'))continue;unset($store['events'][$id],$store['journals'][$journalId]);$identity=(string)($event['postingIdentity']??'');if($identity!=='')unset($store['postingIdentities'][$identity]);$removed++;}
        $store['cleanupMigrations']['remove_abandoned_qa_receipts_20260923']=['at'=>gmdate('c'),'removed'=>$removed];if($removed)$store['revision']=(int)($store['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);return$removed;
    }finally{flock($h,LOCK_UN);fclose($h);}
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) {
        cl_respond(['ok'=>false,'error'=>'Accounts permission required.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        cl_respond(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $entity = strtoupper(trim((string)($_GET['entity'] ?? '')));
    if ($entity !== '' && !in_array($entity, ['TTI','BRM','TG'], true)) {
        cl_respond(['ok'=>false,'error'=>'Invalid entity.'], 422);
    }

    tt_ensure_data_dir();
    $file = TT_DATA_DIR . '/accounts.json';
    $qaRemoved=cl_remove_abandoned_qa_receipts($file);
    $store = ['journals'=>[],'events'=>[],'commodityBills'=>[]];
    if (is_file($file)) {
        $h = fopen($file, 'r');
        if ($h === false || !flock($h, LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
        try { $raw = stream_get_contents($h); }
        finally { flock($h, LOCK_UN); fclose($h); }
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) $store = array_replace_recursive($store, $decoded);
    }

    $bills = (array)($store['commodityBills'] ?? []);
    $rows = [];
    foreach ((array)($store['events'] ?? []) as $event) {
        if (!is_array($event) || ($event['eventType'] ?? '') !== 'COMMODITY_RECEIPT_ACCEPTED') continue;
        $eventEntity = (string)($event['entity'] ?? '');
        if ($entity !== '' && $eventEntity !== $entity) continue;
        $journal = $store['journals'][$event['journalId'] ?? ''] ?? null;
        if (!is_array($journal)) continue;
        $meta = is_array($journal['meta'] ?? null) ? $journal['meta'] : [];
        $commodity = cl_commodity($event, $meta);
        $identity = tt_product_identity($commodity,(string)($meta['displayName'] ?? $meta['baseVariety'] ?? $meta['variety'] ?? ''),(string)($meta['productStage'] ?? ''),(string)($meta['riceType'] ?? ''));
        $billId = (string)($event['billId'] ?? '');
        $bill = $billId !== '' && isset($bills[$billId]) && is_array($bills[$billId]) ? $bills[$billId] : null;
        $rows[] = [
            'eventId'=>(string)($event['id'] ?? ''),
            'sourceKey'=>(string)($event['sourceKey'] ?? ''),
            'entity'=>$eventEntity,
            'journalId'=>(string)($event['journalId'] ?? ''),
            'date'=>(string)($journal['date'] ?? ''),
            'reference'=>(string)($journal['reference'] ?? ''),
            'provisionalAmount'=>(float)($journal['totalDebit'] ?? 0),
            'commodity'=>$commodity,
            'soda'=>(string)($meta['soda'] ?? ''),
            'pohanch'=>(string)($meta['pohanch'] ?? $journal['reference'] ?? ''),
            'truck'=>(string)($meta['truck'] ?? ''),
            'broker'=>(string)($meta['broker'] ?? ''),
            'party'=>(string)($meta['party'] ?? ''),
            'variety'=>(string)($meta['variety'] ?? ''),
            'baseVariety'=>(string)($meta['baseVariety'] ?? $identity['baseVariety']),
            'riceType'=>(string)($meta['riceType'] ?? $identity['riceType']),
            'productStage'=>(string)($meta['productStage'] ?? $identity['productStage']),
            'displayName'=>(string)($meta['displayName'] ?? $identity['displayName']),
            'stageInferred'=>!isset($meta['productStage']),
            'bags'=>(float)($meta['bags'] ?? 0),
            'payableWeightKg'=>(float)($meta['payableWeightKg'] ?? 0),
            'grossRatePerKg'=>(float)($meta['grossRatePerKg'] ?? 0),
            'katPaisaPerKg'=>(float)($meta['katPaisaPerKg'] ?? 0),
            'provisionalNetRatePerKg'=>(float)($meta['provisionalNetRatePerKg'] ?? 0),
            'billed'=>$billId !== '',
            'billId'=>$billId,
            'billNo'=>(string)($bill['billNo'] ?? ''),
            'billDate'=>(string)($bill['billDate'] ?? ''),
            'billStatus'=>(string)($bill['status'] ?? ''),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']) ?: strcmp((string)$b['pohanch'], (string)$a['pohanch']));

    cl_respond([
        'ok'=>true,
        'receipts'=>$rows,
        'bills'=>array_values($bills),
        'serverNow'=>gmdate('c'),
        'qaCleanupRemoved'=>$qaRemoved,
    ]);
} catch (Throwable $e) {
    cl_respond(['ok'=>false,'error'=>'Pohanch / bill lookup is temporarily unavailable.'], 500);
}
