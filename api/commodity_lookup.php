<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
require_once __DIR__ . '/commodity_bill_calculation.php';
require_once __DIR__ . '/arrival_withdrawal_core.php';
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

/** Identify issued slips whose delivery is still missing from the Accounts receipt list. */
function cl_unconfirmed_pohanches(array $values, string $entity, array $sodas, array $receipts): array {
    $slipKey = static fn(string $soda, string $slip, string $truck): string =>
        $soda.'|'.strtoupper(trim($slip)).'|'.strtoupper(trim($truck));
    $sources = array_fill_keys(array_column($receipts, 'sourceKey'), true);
    $seen = [];
    foreach ($receipts as $receipt) {
        $seen[$slipKey($receipt['soda'], $receipt['pohanch'], $receipt['truck'])] = true;
    }
    $raw = $values['tt30slips'] ?? [];
    $slips = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $raw;
    $unconfirmed = [];
    foreach ($slips as $slip) {
        if (!is_array($slip) || ($slip['entity'] ?? '') !== $entity || empty($slip['slip']) ||
            !empty($slip['deleted']) || !empty($slip['cancelled']) || !empty($slip['reversed']) ||
            in_array(strtolower((string)($slip['status'] ?? '')), ['cancelled','deleted','reversed'], true) ||
            tt_is_withdrawn_arrival($slip)) continue;
        $number = (string)($slip['soda'] ?? '');
        $soda = $sodas[$number] ?? null;
        if (!$soda || ($soda['readyRoute'] ?? '') === 'EX_MILL' ||
            (!empty($slip['sourceSodaId']) && $slip['sourceSodaId'] !== ($soda['id'] ?? '')) ||
            (float)($slip['payableWeight'] ?? 0) <= 0) continue;
        $source = empty($slip['bridgeId']) ? '' : 'POHANCH|'.($slip['millId'] ?? 'MILL').'|'.$slip['bridgeId'];
        $key = $slipKey($number, (string)$slip['slip'], (string)($slip['truck'] ?? ''));
        if (isset($seen[$key]) || ($source !== '' && isset($sources[$source]))) continue;
        $seen[$key] = true;
        if ($source !== '') $sources[$source] = true;
        $unconfirmed[] = ['soda'=>$number, 'pohanch'=>(string)$slip['slip']];
    }
    return $unconfirmed;
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

    if($entity===''||!tt_user_can_access_entity($user,$entity,'View'))cl_respond(['ok'=>false,'error'=>'Choose an authorized company before opening Bill Posting.'],403);
    tt_ensure_data_dir();
    $file = TT_DATA_DIR . '/accounts.json';
    $store = ['journals'=>[],'events'=>[],'commodityBills'=>[]];
    if (is_file($file)) {
        $h = fopen($file, 'r');
        if ($h === false || !flock($h, LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
        try { $raw = stream_get_contents($h); }
        finally { flock($h, LOCK_UN); fclose($h); }
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) $store = array_replace_recursive($store, $decoded);
    }

    $operationalValues=tt_bill_operational_values();
    $pohanches=tt_bill_pohanch_index($operationalValues,$entity);
    $sodaRows = [];
    foreach (['purchaseSodas','purchaseSodasV2'] as $collection) {
        foreach ((array)($store[$collection] ?? []) as $id => $soda) {
            if (!is_array($soda) || ($soda['entity'] ?? '') !== $entity) continue;
            $number = (string)($soda['sodaNo'] ?? '');
            if ($number === '' || isset($sodaRows[$number])) continue;
            $soda['id'] = (string)($soda['id'] ?? $id);
            $soda['buyingBrokery'] = tt_broker_profile((string)($soda['broker'] ?? ''), (string)($soda['sodaDate'] ?? ''), 'buying')['rate'] ?? null;
            $sodaRows[$number] = $soda;
        }
    }
    $bills = (array)($store['commodityBills'] ?? []);
    $rows = [];
    foreach ((array)($store['events'] ?? []) as $event) {
        if (!is_array($event) || ($event['eventType'] ?? '') !== 'COMMODITY_RECEIPT_ACCEPTED'||($event['status']??'')==='Reversed') continue;
        $eventEntity = (string)($event['entity'] ?? '');
        if ($entity !== '' && $eventEntity !== $entity) continue;
        $journal = $store['journals'][$event['journalId'] ?? ''] ?? null;
        if (!is_array($journal)) continue;
        $meta = is_array($journal['meta'] ?? null) ? $journal['meta'] : [];
        $soda=$sodaRows[(string)($meta['soda']??'')]??[];
        $bagDefaults=tt_bill_bag_defaults($meta,$operationalValues);
        $commodity = cl_commodity($event, $meta);
        $identity = tt_product_identity($commodity,(string)($meta['displayName'] ?? $meta['baseVariety'] ?? $meta['variety'] ?? ''),(string)($meta['productStage'] ?? ''),(string)($meta['riceType'] ?? ''));
        $billId = (string)($event['billId'] ?? '');
        $bill = $billId !== '' && isset($bills[$billId]) && is_array($bills[$billId]) ? $bills[$billId] : null;
        $quality=null;$qualityError='';
        if ($commodity==='RICE'&&$billId==='') {
            try {$quality=tt_bill_pohanch_kat($pohanches,(string)($event['sourceKey']??''),$meta);}
            catch(InvalidArgumentException $e){$qualityError=$e->getMessage();}
        }
        $rows[] = [
            'pohanchKat'=>$quality,'pohanchKatError'=>$qualityError,
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
            'container'=>(string)($meta['container']??''),
            'weighbridgeWeightKg'=>(float)($meta['weighbridgeWeightKg']??$meta['payableWeightKg']??0),
            'emptyBagWeightGrams'=>$bagDefaults['emptyBagWeightGrams'],
            'broker'=>(string)($soda['broker'] ?? $meta['broker'] ?? ''),
            'party'=>(string)($soda['party'] ?? $meta['party'] ?? ''),
            'variety'=>(string)($meta['variety'] ?? ''),
            'baseVariety'=>(string)($meta['baseVariety'] ?? $identity['baseVariety']),
            'riceType'=>(string)($meta['riceType'] ?? $identity['riceType']),
            'productStage'=>(string)($meta['productStage'] ?? $identity['productStage']),
            'displayName'=>(string)($meta['displayName'] ?? $identity['displayName']),
            'stageInferred'=>!isset($meta['productStage']),
            'bags'=>(float)($meta['bags'] ?? 0),
            'payableWeightKg'=>(float)($meta['payableWeightKg'] ?? 0),
            'grossRatePerKg'=>(float)($soda['ratePerKg']??$soda['rate']??$meta['grossRatePerKg']??0),
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
        'bills'=>array_values(array_filter($bills,fn($bill)=>is_array($bill)&&($bill['entity']??'')===$entity)),
        'sodas'=>array_values($sodaRows),
        'brokers'=>tt_broker_profiles(),
        'pendingPohanches'=>cl_unconfirmed_pohanches($operationalValues, $entity, $sodaRows, $rows),
        'serverNow'=>gmdate('c'),
    ]);
} catch (Throwable $e) {
    cl_respond(['ok'=>false,'error'=>'Pohanch / bill lookup is temporarily unavailable.'], 500);
}

