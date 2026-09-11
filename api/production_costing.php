<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_PC_ACCOUNTS = TT_DATA_DIR . '/accounts.json';
const TT_PC_OPERATIONS = TT_DATA_DIR . '/operations.json';

function pc_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pc_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $p = $user['permissions']['Accounts'] ?? null;
    if ($p === 'all') return true;
    if (!is_array($p)) return false;
    if (in_array('Create', $p, true) || in_array('Edit', $p, true) || in_array('Approve', $p, true)) return true;
    foreach ($p as $actions) {
        if (is_array($actions) && (in_array('Create', $actions, true) || in_array('Edit', $actions, true) || in_array('Approve', $actions, true))) return true;
    }
    return false;
}

function pc_require_entity(array $user,string $entity,bool $write=false): void {
    if (($user['role'] ?? '') === 'Super Admin') return;
    $allowed=$write?(tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')||tt_user_can_access_entity($user,$entity,'Approve')):tt_user_can_access_entity($user,$entity,'View');
    if(!$allowed) pc_out(['ok'=>false,'error'=>'You do not have permission for this legal entity.'],403);
}

function pc_accounts_default(): array {
    return [
        'revision' => 0,
        'journals' => [],
        'events' => [],
        'commodityBills' => [],
        'inventoryCostRates' => [],
        'localSalesCostRates' => [],
        'productionCostRuns' => [],
    ];
}

function pc_read_accounts(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_PC_ACCOUNTS)) return pc_accounts_default();
    $h = fopen(TT_PC_ACCOUNTS, 'r');
    if ($h === false || !flock($h, LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try { $raw = stream_get_contents($h); }
    finally { flock($h, LOCK_UN); fclose($h); }
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) ? array_replace_recursive(pc_accounts_default(), $decoded) : pc_accounts_default();
}

function pc_read_operations(): array {
    if (!is_file(TT_PC_OPERATIONS)) return ['values' => []];
    $h = fopen(TT_PC_OPERATIONS, 'r');
    if ($h === false || !flock($h, LOCK_SH)) throw new RuntimeException('Operational storage unavailable.');
    try { $raw = stream_get_contents($h); }
    finally { flock($h, LOCK_UN); fclose($h); }
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : ['values' => []];
}

function pc_op(array $ops, string $key): array {
    $raw = $ops['values'][$key] ?? '';
    if (!is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function pc_date(string $value): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) pc_out(['ok' => false, 'error' => 'Valid date required.'], 422);
    return $value;
}

function pc_entity(string $value): string {
    $value = strtoupper(trim($value));
    if (!in_array($value, ['TTI', 'BRM'], true)) pc_out(['ok' => false, 'error' => 'Production costing is available for TTI / BRM Pakistan books.'], 422);
    return $value;
}

function pc_norm(string $value): string {
    $value = preg_replace('/[^A-Z0-9]+/i', ' ', trim($value)) ?? trim($value);
    return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
}

function pc_variety_key(string $value): string {
    $u = pc_norm($value);
    if (str_contains($u, 'IRRI 6')) $base = 'IRRI-6';
    elseif (str_contains($u, '1121')) $base = '1121';
    elseif (str_contains($u, 'PK 386')) $base = 'PK-386';
    elseif (str_contains($u, 'SUPER KERNEL')) $base = 'SUPER KERNEL';
    elseif (str_contains($u, 'D 98')) $base = 'D-98';
    elseif (preg_match('/(^| )C 9( |$)/', $u)) $base = 'C-9';
    else $base = $u ?: 'RICE';
    if (str_contains($u, 'PARBOIL')) $base .= ' PARBOIL';
    return $base;
}

function pc_product_key(string $value): string {
    $u = pc_norm($value);
    if (str_starts_with($u, 'B2 CSR')) return 'B2 CSR';
    if (str_starts_with($u, 'B2') || str_contains($u, 'BROKEN B2')) return 'B2';
    if ($u === 'CSR') return 'CSR';
    if ($u === 'B3') return 'B3';
    if ($u === 'PADDY') return 'PADDY';
    if ($u === 'POWDER') return 'POWDER';
    return $u;
}

function pc_is_waste(string $value): bool {
    $u = pc_norm($value);
    return str_contains($u, 'STONE') || str_contains($u, 'SUTLI') || str_contains($u, 'DUST');
}

function pc_next(array $items, string $prefix): string {
    $n = count($items) + 1;
    do {
        $id = $prefix . '-' . gmdate('Y') . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
        $n++;
    } while (isset($items[$id]));
    return $id;
}

function pc_rates(array $store): array {
    $rows = [];
    foreach ((array)($store['localSalesCostRates'] ?? []) as $r) if (is_array($r)) $rows[] = $r;
    foreach ((array)($store['inventoryCostRates'] ?? []) as $r) if (is_array($r)) $rows[] = $r;
    return $rows;
}

function pc_rate_for(array $store, string $entity, string $product, string $date): ?array {
    $best = null;
    $key = pc_product_key($product);
    foreach (pc_rates($store) as $r) {
        if (($r['status'] ?? 'Active') !== 'Active' || ($r['entity'] ?? '') !== $entity) continue;
        $matches = pc_product_key((string)($r['product'] ?? '')) === $key;
        if (!$matches) {
            foreach ((array)($r['aliases'] ?? []) as $alias) {
                if (pc_product_key((string)$alias) === $key) { $matches = true; break; }
            }
        }
        if (!$matches) continue;
        $from = (string)($r['effectiveFrom'] ?? '');
        if ($from === '' || $from > $date) continue;
        if ($best === null || $from > (string)($best['effectiveFrom'] ?? '')) $best = $r;
    }
    return $best;
}

function pc_bill_values(array $store): array {
    $out = [];
    foreach ((array)($store['commodityBills'] ?? []) as $bill) {
        if (!is_array($bill) || ($bill['status'] ?? '') !== 'Verified / Posted') continue;
        $allocations = (array)($bill['receiptAllocations'] ?? []);
        if (!$allocations) continue;
        $base = 0.0;
        foreach ($allocations as $a) $base += max(0, (float)($a['provisionalAmount'] ?? 0));
        $inventoryValue = round((float)($bill['finalCommodityValue'] ?? 0) + (float)($bill['brokerageGross'] ?? 0), 2);
        if ($inventoryValue <= 0) continue;
        $used = 0.0;
        $validIndexes = array_keys($allocations);
        $lastIndex = end($validIndexes);
        foreach ($allocations as $i => $a) {
            $sourceKey = trim((string)($a['sourceKey'] ?? ''));
            if ($sourceKey === '') continue;
            if ($i === $lastIndex) $part = round($inventoryValue - $used, 2);
            else {
                $weight = $base > 0 ? max(0, (float)($a['provisionalAmount'] ?? 0)) / $base : 1 / max(1, count($allocations));
                $part = round($inventoryValue * $weight, 2);
            }
            $used = round($used + $part, 2);
            $out[$sourceKey] = ['value' => $part, 'billId' => (string)($bill['id'] ?? ''), 'final' => true];
        }
    }
    return $out;
}

function pc_receipts(array $store): array {
    $billed = pc_bill_values($store);
    $rows = [];
    foreach ((array)($store['events'] ?? []) as $event) {
        if (!is_array($event) || ($event['eventType'] ?? '') !== 'COMMODITY_RECEIPT_ACCEPTED') continue;
        $journal = $store['journals'][(string)($event['journalId'] ?? '')] ?? null;
        if (!is_array($journal)) continue;
        $meta = is_array($journal['meta'] ?? null) ? $journal['meta'] : [];
        $kg = (float)($meta['payableWeightKg'] ?? 0);
        if ($kg <= 0) continue;
        $sourceKey = (string)($event['sourceKey'] ?? '');
        $bill = $billed[$sourceKey] ?? null;
        $variety = (string)($meta['variety'] ?? '');
        $rows[] = [
            'entity' => (string)($event['entity'] ?? $journal['entity'] ?? 'TTI'),
            'date' => (string)($journal['date'] ?? ''),
            'sourceKey' => $sourceKey,
            'variety' => $variety,
            'varietyKey' => pc_variety_key($variety),
            'kg' => $kg,
            'value' => $bill ? (float)$bill['value'] : (float)($journal['totalDebit'] ?? 0),
            'final' => $bill !== null,
            'billId' => $bill['billId'] ?? '',
        ];
    }
    usort($rows, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
    return $rows;
}

function pc_production(array $ops): array {
    $rows = [];
    foreach (pc_op($ops, 'tt30prod') as $production) {
        if (!is_array($production)) continue;
        $date = (string)($production['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $outputs = [];
        $inputKg = 0.0;
        $readyKg = 0.0;
        foreach ((array)($production['rows'] ?? []) as $r) {
            if (!is_array($r)) continue;
            if (!empty($r['systemFixed']) && !empty($r['noStockPost'])) continue;
            $kg = (float)($r['systemKg'] ?? 0);
            if ($kg <= 0) $kg = (float)($r['bags'] ?? 0) * (float)($r['bagWeight'] ?? 0);
            if ($kg <= 0) continue;
            $name = trim((string)($r['product'] ?? 'Unknown'));
            $isReady = str_starts_with(strtoupper($name), 'READY RICE');
            $outputs[] = ['product' => $name, 'kg' => round($kg, 3), 'ready' => $isReady];
            $inputKg += $kg;
            if ($isReady) $readyKg += $kg;
        }
        if ($inputKg <= 0) continue;
        $variety = (string)($production['variety'] ?? 'Rice');
        $rows[] = [
            'id' => (string)($production['id'] ?? ($date . '|' . ($production['shift'] ?? '') . '-' . count($rows))),
            'date' => $date,
            'shift' => (string)($production['shift'] ?? ''),
            'variety' => $variety,
            'varietyKey' => pc_variety_key($variety),
            'inputKg' => round($inputKg, 3),
            'readyKg' => round($readyKg, 3),
            'outputs' => $outputs,
        ];
    }
    usort($rows, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']) ?: strcmp((string)$a['shift'], (string)$b['shift']));
    return $rows;
}

function pc_group_production(array $production): array {
    $groups = [];
    foreach ($production as $p) {
        $id = $p['date'] . '|' . $p['varietyKey'];
        if (!isset($groups[$id])) {
            $groups[$id] = [
                'id' => $id,
                'date' => $p['date'],
                'variety' => $p['variety'],
                'varietyKey' => $p['varietyKey'],
                'inputKg' => 0.0,
                'readyKg' => 0.0,
                'outputs' => [],
                'runIds' => [],
            ];
        }
        $groups[$id]['inputKg'] += (float)$p['inputKg'];
        $groups[$id]['readyKg'] += (float)$p['readyKg'];
        $groups[$id]['runIds'][] = $p['id'];
        foreach ($p['outputs'] as $o) {
            $name = (string)$o['product'];
            if (!isset($groups[$id]['outputs'][$name])) $groups[$id]['outputs'][$name] = ['product' => $name, 'kg' => 0.0, 'ready' => $o['ready']];
            $groups[$id]['outputs'][$name]['kg'] += (float)$o['kg'];
        }
    }
    foreach ($groups as &$g) {
        $g['inputKg'] = round($g['inputKg'], 3);
        $g['readyKg'] = round($g['readyKg'], 3);
        $g['outputs'] = array_values($g['outputs']);
        foreach ($g['outputs'] as &$o) $o['kg'] = round((float)$o['kg'], 3);
        unset($o);
    }
    unset($g);
    uasort($groups, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']) ?: strcmp((string)$a['varietyKey'], (string)$b['varietyKey']));
    return $groups;
}

function pc_cost_sources(array $ops, array $groups): array {
    $sources = [];
    $add = function(string $id, string $type, string $from, string $to, float $amount, string $reference = '') use (&$sources, $groups): void {
        if ($amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) return;
        $kg = 0.0;
        foreach ($groups as $g) if ($g['date'] >= $from && $g['date'] <= $to) $kg += (float)$g['inputKg'];
        if ($kg <= 0) return;
        $sources[] = [
            'id' => $id,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'amount' => round($amount, 2),
            'productionKg' => round($kg, 3),
            'ratePerKg' => $amount / $kg,
            'reference' => $reference,
        ];
    };

    foreach (pc_op($ops, 'tt38kebills') as $x) {
        if (!is_array($x)) continue;
        $add('KE|' . ($x['id'] ?? $x['ref'] ?? count($sources)), 'Electricity', (string)($x['readFrom'] ?? $x['date'] ?? ''), (string)($x['readTo'] ?? $x['date'] ?? ''), (float)($x['amount'] ?? 0), (string)($x['ref'] ?? ''));
    }
    foreach (pc_op($ops, 'tt38labourbills') as $x) {
        if (!is_array($x)) continue;
        $add('LAB|' . ($x['id'] ?? $x['billNo'] ?? count($sources)), 'Labour', (string)($x['from'] ?? $x['date'] ?? ''), (string)($x['till'] ?? $x['date'] ?? ''), (float)($x['amount'] ?? 0), (string)($x['billNo'] ?? $x['ref'] ?? ''));
    }
    foreach (pc_op($ops, 'tt37processingexpenses') as $x) {
        if (!is_array($x)) continue;
        $date = (string)($x['date'] ?? '');
        $add('EXP|' . ($x['id'] ?? $x['ref'] ?? count($sources)), (string)($x['type'] ?? 'Processing Expense'), $date, $date, (float)($x['amount'] ?? 0), (string)($x['ref'] ?? ''));
    }
    return $sources;
}

function pc_calculate(array $store, array $ops, string $entity): array {
    $groups = pc_group_production(pc_production($ops));
    $receipts = array_values(array_filter(pc_receipts($store), static fn($r) => ($r['entity'] ?? 'TTI') === $entity));
    $sources = pc_cost_sources($ops, $groups);

    $events = [];
    foreach ($receipts as $r) $events[] = ['date' => $r['date'], 'kind' => 'R', 'priority' => 0, 'data' => $r];
    foreach ($groups as $id => $g) $events[] = ['date' => $g['date'], 'kind' => 'P', 'priority' => 1, 'id' => $id, 'data' => $g];
    usort($events, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']) ?: ((int)$a['priority'] <=> (int)$b['priority']));

    $pool = [];
    $result = [];
    foreach ($events as $event) {
        if ($event['kind'] === 'R') {
            $r = $event['data'];
            $key = $r['varietyKey'];
            if (!isset($pool[$key])) $pool[$key] = ['kg' => 0.0, 'value' => 0.0, 'provisionalKg' => 0.0];
            $pool[$key]['kg'] += (float)$r['kg'];
            $pool[$key]['value'] += (float)$r['value'];
            if (empty($r['final'])) $pool[$key]['provisionalKg'] += (float)$r['kg'];
            continue;
        }

        $g = $event['data'];
        $key = $g['varietyKey'];
        $state = $pool[$key] ?? ['kg' => 0.0, 'value' => 0.0, 'provisionalKg' => 0.0];
        $available = (float)$state['kg'];
        $average = $available > 0 ? (float)$state['value'] / $available : 0.0;
        $need = (float)$g['inputKg'];
        $enough = $available + 0.001 >= $need;
        $share = $available > 0 ? min(1, $need / $available) : 0;
        $provisionalConsumed = (float)$state['provisionalKg'] * $share;
        $rawCost = round($need * $average, 2);

        if ($available > 0) {
            $consume = min($need, $available);
            $valueConsume = $consume * $average;
            $provisionalConsume = (float)$state['provisionalKg'] * ($consume / $available);
            $state['kg'] -= $consume;
            $state['value'] -= $valueConsume;
            $state['provisionalKg'] = max(0, (float)$state['provisionalKg'] - $provisionalConsume);
            $pool[$key] = $state;
        }

        $conversion = 0.0;
        $sourceRows = [];
        foreach ($sources as $source) {
            if ($g['date'] < $source['from'] || $g['date'] > $source['to']) continue;
            $allocated = (float)$g['inputKg'] * (float)$source['ratePerKg'];
            $conversion += $allocated;
            $sourceRows[] = [
                'id' => $source['id'],
                'type' => $source['type'],
                'reference' => $source['reference'],
                'allocatedAmount' => round($allocated, 2),
                'ratePerKg' => round((float)$source['ratePerKg'], 6),
                'from' => $source['from'],
                'to' => $source['to'],
            ];
        }
        $conversion = round($conversion, 2);
        $jointCost = round($rawCost + $conversion, 2);

        $byProductValue = 0.0;
        $byProducts = [];
        $missing = [];
        foreach ($g['outputs'] as $output) {
            if (!empty($output['ready'])) continue;
            $product = (string)$output['product'];
            $kg = (float)$output['kg'];
            if (pc_is_waste($product)) {
                $byProducts[] = ['product' => $product, 'kg' => $kg, 'costPerKg' => 0, 'value' => 0, 'source' => 'Waste / Nil value'];
                continue;
            }
            $savedRate = pc_rate_for($store, $entity, $product, $g['date']);
            if (!$savedRate) {
                $missing[] = pc_product_key($product);
                $byProducts[] = ['product' => $product, 'kg' => $kg, 'costPerKg' => null, 'value' => null, 'source' => 'Missing'];
                continue;
            }
            $costPerKg = (float)($savedRate['costPerKg'] ?? 0);
            $value = round($kg * $costPerKg, 2);
            $byProductValue += $value;
            $byProducts[] = ['product' => $product, 'kg' => $kg, 'costPerKg' => $costPerKg, 'value' => $value, 'source' => (string)($savedRate['id'] ?? 'Saved cost')];
        }
        $missing = array_values(array_unique($missing));
        $readyCost = round($jointCost - $byProductValue, 2);
        $readyRate = $g['readyKg'] > 0 && $readyCost > 0 ? $readyCost / (float)$g['readyKg'] : 0;
        $approved = $store['productionCostRuns'][$g['id']] ?? null;

        if ($approved) $status = 'Approved';
        elseif (!$enough) $status = 'Waiting — purchase stock is short';
        elseif ($average <= 0) $status = 'Waiting — purchase cost is missing';
        elseif ($provisionalConsumed > 0.001) $status = 'Waiting — final purchase bill is pending';
        elseif ($g['readyKg'] <= 0) $status = 'Waiting — Ready Rice output is missing';
        elseif ($missing) $status = 'Waiting — by-product value is missing';
        elseif (!$sourceRows) $status = 'Waiting — production costs are missing';
        elseif ($readyCost <= 0) $status = 'Waiting — by-product value is too high';
        else $status = 'Ready for Accounts Review';

        $result[] = [
            'id' => $g['id'],
            'date' => $g['date'],
            'entity' => $entity,
            'variety' => $g['variety'],
            'varietyKey' => $g['varietyKey'],
            'inputKg' => $g['inputKg'],
            'readyKg' => $g['readyKg'],
            'outputs' => $g['outputs'],
            'rawAvailableBeforeKg' => round($available, 3),
            'rawAverageCostPerKg' => round($average, 6),
            'rawCost' => $rawCost,
            'usesProvisionalPurchaseCost' => $provisionalConsumed > 0.001,
            'provisionalInputKg' => round($provisionalConsumed, 3),
            'conversionCost' => $conversion,
            'conversionCostPerKg' => $g['inputKg'] > 0 ? round($conversion / $g['inputKg'], 6) : 0,
            'conversionSources' => $sourceRows,
            'jointCost' => $jointCost,
            'byProducts' => $byProducts,
            'missingByProductValues' => $missing,
            'byProductValue' => round($byProductValue, 2),
            'readyRiceCost' => $readyCost,
            'suggestedReadyRiceCostPerKg' => round($readyRate, 6),
            'enoughRawStock' => $enough,
            'status' => $status,
            'readyToApprove' => $status === 'Ready for Accounts Review',
            'approvedRateId' => $approved['rateId'] ?? null,
            'approvedAt' => $approved['approvedAt'] ?? null,
        ];
    }

    return ['groups' => $result, 'sourceCosts' => $sources];
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) pc_out(['ok' => false, 'error' => 'Accounts permission required.'], 403);

    $entity = strtoupper(trim((string)($_GET['entity'] ?? 'TTI')));
    if ($entity === '') $entity = 'TTI';
    if (!in_array($entity, ['TTI', 'BRM'], true)) {
        pc_out(['ok' => true, 'entity' => $entity, 'groups' => [], 'sourceCosts' => [], 'message' => 'Production stock costing is not used for TG.']);
    }

    pc_require_entity($user,$entity,false);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $store = pc_read_accounts();
        $ops = pc_read_operations();
        $calc = pc_calculate($store, $ops, $entity);
        pc_out(['ok' => true, 'entity' => $entity] + $calc + ['serverNow' => gmdate('c')]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') pc_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    if (!pc_can_write($user)) pc_out(['ok' => false, 'error' => 'Accounts approval permission required.'], 403);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) pc_out(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    $action = (string)($body['action'] ?? '');

    tt_ensure_data_dir();
    $h = fopen(TT_PC_ACCOUNTS, 'c+');
    if ($h === false || !flock($h, LOCK_EX)) throw new RuntimeException('Accounts storage unavailable.');
    try {
        rewind($h);
        $raw = stream_get_contents($h);
        $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = pc_accounts_default();
        $store = array_replace_recursive(pc_accounts_default(), $store);
        $ops = pc_read_operations();

        if ($action === 'save_byproduct_value') {
            $entity = pc_entity((string)($body['entity'] ?? ''));
            pc_require_entity($user,$entity,true);
            $product = trim((string)($body['product'] ?? ''));
            if ($product === '') pc_out(['ok' => false, 'error' => 'By-product is required.'], 422);
            $effectiveFrom = pc_date((string)($body['effectiveFrom'] ?? ''));
            $cost = round((float)($body['costPerKg'] ?? 0), 6);
            if ($cost < 0) pc_out(['ok' => false, 'error' => 'By-product value cannot be negative.'], 422);
            $id = pc_next((array)$store['inventoryCostRates'], 'ICR');
            $store['inventoryCostRates'][$id] = [
                'id' => $id,
                'entity' => $entity,
                'product' => $product,
                'effectiveFrom' => $effectiveFrom,
                'costPerKg' => $cost,
                'inventoryAccount' => '1330',
                'status' => 'Active',
                'source' => 'ACCOUNTS_BYPRODUCT_VALUATION',
                'notes' => 'Accounts by-product stock value used by automatic production costing.',
                'createdAt' => gmdate('c'),
                'createdBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Accounts'),
            ];
            $result = ['rateId' => $id];
        } elseif ($action === 'approve_rate') {
            $entity = pc_entity((string)($body['entity'] ?? ''));
            pc_require_entity($user,$entity,true);
            if (($body['costsReviewed'] ?? false) !== true) pc_out(['ok' => false, 'error' => 'Confirm that Accounts checked the purchase and production-cost figures first.'], 422);
            $groupId = trim((string)($body['groupId'] ?? ''));
            $calc = pc_calculate($store, $ops, $entity);
            $group = null;
            foreach ($calc['groups'] as $candidate) {
                if (($candidate['id'] ?? '') === $groupId) { $group = $candidate; break; }
            }
            if (!$group) pc_out(['ok' => false, 'error' => 'Production cost calculation was not found.'], 404);
            if (empty($group['readyToApprove'])) pc_out(['ok' => false, 'error' => (string)($group['status'] ?? 'This cost is not ready to approve.')], 422);
            if (isset($store['productionCostRuns'][$groupId])) pc_out(['ok' => false, 'error' => 'This production cost has already been approved.'], 409);

            $costPerKg = round((float)$group['suggestedReadyRiceCostPerKg'], 6);
            if ($costPerKg <= 0) pc_out(['ok' => false, 'error' => 'Calculated Ready Rice cost is invalid.'], 422);
            $rateId = pc_next((array)$store['inventoryCostRates'], 'ICR');
            $aliases = [];
            foreach ((array)$group['outputs'] as $output) if (!empty($output['ready'])) $aliases[] = (string)$output['product'];
            $calculation = [
                'groupId' => $groupId,
                'rawAverageCostPerKg' => $group['rawAverageCostPerKg'],
                'rawCost' => $group['rawCost'],
                'conversionCost' => $group['conversionCost'],
                'byProductValue' => $group['byProductValue'],
                'readyRiceCost' => $group['readyRiceCost'],
                'inputKg' => $group['inputKg'],
                'readyKg' => $group['readyKg'],
                'usesProvisionalPurchaseCost' => false,
            ];
            $store['inventoryCostRates'][$rateId] = [
                'id' => $rateId,
                'entity' => $entity,
                'product' => (string)$group['variety'],
                'aliases' => array_values(array_unique($aliases)),
                'effectiveFrom' => (string)$group['date'],
                'costPerKg' => $costPerKg,
                'inventoryAccount' => '1320',
                'status' => 'Active',
                'source' => 'AUTO_PRODUCTION_COST',
                'notes' => 'Automatically calculated from finalized purchase stock cost plus saved production costs, less saved by-product values.',
                'calculation' => $calculation,
                'createdAt' => gmdate('c'),
                'createdBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Accounts'),
            ];
            $store['productionCostRuns'][$groupId] = [
                'id' => $groupId,
                'entity' => $entity,
                'date' => $group['date'],
                'variety' => $group['variety'],
                'rateId' => $rateId,
                'costPerKg' => $costPerKg,
                'calculation' => $calculation,
                'costsReviewed' => true,
                'approvedAt' => gmdate('c'),
                'approvedBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Accounts'),
            ];
            $result = ['rateId' => $rateId, 'costPerKg' => $costPerKg];
        } else {
            pc_out(['ok' => false, 'error' => 'Unknown production costing action.'], 422);
        }

        $store['revision'] = (int)($store['revision'] ?? 0) + 1;
        rewind($h);
        if (!ftruncate($h, 0)) throw new RuntimeException('Accounts storage could not be updated.');
        $encoded = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (fwrite($h, $encoded) === false) throw new RuntimeException('Accounts storage could not be updated.');
        fflush($h);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }

    pc_out(['ok' => true, 'result' => $result, 'revision' => $store['revision']]);
} catch (Throwable $e) {
    pc_out(['ok' => false, 'error' => 'Production stock cost could not be calculated.'], 500);
}

