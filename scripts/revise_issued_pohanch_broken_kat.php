<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const POHANCH_KAT_BATCH = [
    1=>[40,65,46470], 2=>[40,65,50500], 3=>[38,49,47030], 4=>[35,25,34430],
    5=>[39,57,38460], 6=>[38,49,35870], 7=>[40,65,29490], 8=>[38,49,37460],
    9=>[40,65,25860], 10=>[45,140,38270], 11=>[40,65,48160], 12=>[40,65,26020],
    13=>[40,65,39110], 14=>[45,140,26020],
];

function pohanch_kat_json(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function pohanch_kat_plan(array $values, array $accounts): array {
    $slips = json_decode($values['tt30slips'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    $arrivals = json_decode($values['tt30queue'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($slips) || !array_is_list($slips) || !is_array($arrivals) || !array_is_list($arrivals)) {
        throw new RuntimeException('Invalid operational collections.');
    }
    foreach ($slips as $row) {
        if (empty($row['printed']) || ($row['displayName'] ?? '') !== 'IRRI-6 White Raw Rice') continue;
        $date = (string)($row['unloadingDate'] ?? $row['arrivalDate'] ?? '');
        if ($date > '2026-11-11') continue;
        $ref = (string)($row['slip'] ?? '');
        if (!preg_match('/^PH-(\d+)$/D', $ref, $match) || !isset(POHANCH_KAT_BATCH[(int)$match[1]])
            || ($row['entity'] ?? '') !== 'TTI' || ($row['millName'] ?? '') !== 'TTI Rice Mills') {
            throw new RuntimeException('Additional issued IRRI-6 White Raw Rice Pohanch needs review.');
        }
    }
    $plan = [];
    $blockers = [];
    foreach (POHANCH_KAT_BATCH as $number => [$percent, $oldKat, $kg]) {
        $ref = sprintf('PH-%05d', $number);
        $matches = array_keys(array_filter($slips, static fn($row) => ($row['slip'] ?? '') === $ref && ($row['entity'] ?? '') === 'TTI'));
        if (count($matches) !== 1) throw new RuntimeException("$ref is missing or ambiguous.");
        $index = $matches[0];
        $slip = $slips[$index];
        $newKat = 0;
        foreach ([[21,30,2],[31,35,3],[36,40,8],[41,45,15],[46,50,20],[51,55,25],[56,60,40]] as [$from,$through,$rate]) {
            $newKat += max(0, min($percent, $through) - $from + 1) * $rate;
        }
        if (empty($slip['printed']) || ($slip['displayName'] ?? '') !== 'IRRI-6 White Raw Rice'
            || ($slip['millName'] ?? '') !== 'TTI Rice Mills'
            || !is_numeric($slip['broken'] ?? null) || (float)$slip['broken'] !== (float)$percent
            || !is_numeric($slip['payableWeight'] ?? null) || (float)$slip['payableWeight'] !== (float)$kg
            || !in_array((string)($slip['brokenKat'] ?? ''), [(string)$oldKat,(string)$newKat], true)
            || empty($slip['queueId']) || empty($slip['bridgeId'])) {
            throw new RuntimeException("$ref differs from the reviewed receipt.");
        }
        $queue = array_keys(array_filter($arrivals, static fn($row) => (string)($row['id'] ?? '') === (string)$slip['queueId']));
        if (count($queue) !== 1) throw new RuntimeException("$ref has no unique linked Arrival.");
        $arrival = $arrivals[$queue[0]];
        if (($arrival['entity'] ?? '') !== 'TTI' || ($arrival['vehicle'] ?? '') !== ($slip['truck'] ?? '')
            || !is_numeric($arrival['broken'] ?? null) || (float)$arrival['broken'] !== (float)$percent
            || !in_array((string)($arrival['brokenKat'] ?? ''), [(string)$oldKat,(string)$newKat], true)) {
            throw new RuntimeException("$ref linked Arrival differs from the reviewed deduction.");
        }
        $sourceKeys = ['POHANCH|'.($slip['millId'] ?? 'MILL').'|'.$slip['bridgeId'], 'POHANCH|'.$ref];
        $linked = static fn(array $row) => ($row['entity'] ?? '') === 'TTI' && (
            in_array($row['sourceKey'] ?? $row['meta']['sourceKey'] ?? '', $sourceKeys, true)
            || ($row['reference'] ?? '') === $ref || ($row['meta']['pohanch'] ?? '') === $ref
        );
        $dependencies = [];
        foreach (['events','journals','postingIdentities','bridgeOutbox'] as $collection) {
            foreach ((array)($accounts[$collection] ?? []) as $item) {
                if (is_array($item) && $linked($collection === 'bridgeOutbox' ? ($item['body'] ?? []) : $item)) {
                    $dependencies[$collection] = ($dependencies[$collection] ?? 0) + 1;
                }
            }
        }
        if ((float)$slip['brokenKat'] !== (float)$newKat || (float)$arrival['brokenKat'] !== (float)$newKat) {
            if ($dependencies) $blockers[$ref] = $dependencies;
            $slips[$index]['brokenKat'] = is_string($slip['brokenKat']) ? (string)$newKat : $newKat;
            $arrivals[$queue[0]]['brokenKat'] = is_string($arrival['brokenKat']) ? (string)$newKat : $newKat;
            $plan[$ref] = ['brokenPercent'=>$percent,'oldPaisaPerKg'=>$oldKat,'newPaisaPerKg'=>$newKat,'deductionIncreasePkr'=>round($kg * ($newKat-$oldKat)/100,2)];
        }
    }
    return ['plan'=>$plan,'blockers'=>$blockers,'payloads'=>['tt30slips'=>pohanch_kat_json($slips),'tt30queue'=>pohanch_kat_json($arrivals)]];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$options = getopt('', ['apply','root:']);
$root = realpath((string)($options['root'] ?? (getenv('TT_DIAGNOSTIC_ROOT') ?: dirname(__DIR__))));
if (!$root || !is_file($root.'/auth_store.php')) throw new RuntimeException('Application root required.');
define('TT_RESTORE_CONTROL', true);
require $root.'/auth_store.php';
require $root.'/api/accounts_shipment_source.php';

$apply = isset($options['apply']);
$db = null;
$gate = fopen(TT_DATA_DIR.'/restore-request.lock', 'c+');
if (!$gate || !flock($gate, LOCK_EX)) throw new RuntimeException('Application request lock unavailable.');
try {
    if (is_file(TT_DATA_DIR.'/restore-maintenance.lock')) throw new RuntimeException('Recovery is in progress.');
    $accounts = json_decode((string)file_get_contents(TT_DATA_DIR.'/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($accounts) || !isset($accounts['journals'])) throw new RuntimeException('Accounts source unavailable.');
    $db = tt_accounts_exports_db();
    $path = TT_DATA_DIR.'/operations.json';
    if ($db) {
        $db->beginTransaction();
        $rows = $db->query("SELECT storage_key,payload,version FROM tt_operation_records WHERE storage_key IN ('tt30queue','tt30slips') ORDER BY storage_key FOR UPDATE")->fetchAll(PDO::FETCH_UNIQUE);
        $values = array_map(static fn($row) => $row['payload'], $rows);
    } else {
        $raw = file_get_contents($path);
        $store = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        $values = $store['values'] ?? [];
    }
    $result = pohanch_kat_plan($values, $accounts);
    $report = ['applied'=>false,'records'=>$result['plan'],'accountsDependencies'=>$result['blockers']];
    if ($apply && $result['blockers']) {
        echo pohanch_kat_json($report).PHP_EOL;
        throw new RuntimeException('Linked Accounts records require reconciliation before applying the batch.');
    }
    if ($apply && $result['plan']) {
        $backup = TT_DATA_DIR.'/pohanch-broken-kat-20261111-before.json';
        $before = pohanch_kat_json($db ? $rows : $store);
        $handle = fopen($backup, 'x');
        if (!$handle) throw new RuntimeException('Recovery copy already exists or cannot be created.');
        chmod($backup, 0600);
        try {
            if (fwrite($handle,$before) !== strlen($before) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Recovery copy could not be written.');
            }
        } finally { fclose($handle); }
        if (!hash_equals(hash('sha256',$before),hash_file('sha256',$backup))) throw new RuntimeException('Recovery copy verification failed.');
        $by = 'Owner-authorized Broken KAT correction 2026-11-11';
        if ($db) {
            $write = $db->prepare("UPDATE tt_operation_records SET payload=?,version=?,updated_at=UTC_TIMESTAMP(6),updated_by=?,updated_by_user=NULL,updated_by_module='System' WHERE storage_key=?");
            $history = $db->prepare("INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,UTC_TIMESTAMP(6),?,NULL,'System')");
            foreach ($result['payloads'] as $key => $payload) {
                $version = (int)$rows[$key]['version'] + 1;
                $write->execute([$payload,$version,$by,$key]);
                $history->execute([$key,$version,hash('sha256',$payload),$by]);
            }
            $db->commit();
        } else {
            foreach ($result['payloads'] as $key => $payload) {
                $store['values'][$key] = $payload;
                $store['meta'][$key]['version'] = (int)($store['meta'][$key]['version'] ?? 0) + 1;
            }
            $store['revision'] = (int)($store['revision'] ?? 0) + 1;
            $temp = tempnam(TT_DATA_DIR,'.pohanch-kat-');
            if ($temp === false) throw new RuntimeException('Operations staging file unavailable.');
            try {
                $after = pohanch_kat_json($store);
                if (file_put_contents($temp,$after) !== strlen($after) || !rename($temp,$path)) throw new RuntimeException('Operations update failed.');
            } finally { if (is_file($temp)) unlink($temp); }
        }
        $report['applied'] = true;
    }
    echo pohanch_kat_json($report).PHP_EOL;
} finally {
    if ($db && $db->inTransaction()) $db->rollBack();
    flock($gate, LOCK_UN);
    fclose($gate);
}
