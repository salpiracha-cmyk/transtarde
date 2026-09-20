<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const PS_FILE = TT_DATA_DIR . '/accounts.json';
const PS_TRUCK_DOUBLE_KG = 35000.0;
const PS_MAX_OVER_KG = 950.0;

function ps_out(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ps_default(): array {
    return ['revision'=>0, 'events'=>[], 'journals'=>[], 'commodityBills'=>[], 'supplierSettlements'=>[], 'purchaseSodas'=>[]];
}

function ps_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $permissions = $user['permissions']['Accounts'] ?? null;
    if ($permissions === 'all') return true;
    if (!is_array($permissions)) return false;
    foreach ($permissions as $permission) {
        if (is_string($permission) && in_array($permission, ['Create','Edit','Approve'], true)) return true;
        if (is_array($permission) && array_intersect($permission, ['Create','Edit','Approve'])) return true;
    }
    return false;
}

function ps_user_name(array $user): string {
    return (string)($user['full_name'] ?? $user['username'] ?? 'Accounts');
}

function ps_read(): array {
    tt_ensure_data_dir();
    if (!is_file(PS_FILE)) return ps_default();
    $handle = fopen(PS_FILE, 'r');
    if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('store');
    try { $raw = stream_get_contents($handle); }
    finally { flock($handle, LOCK_UN); fclose($handle); }
    $store = $raw ? json_decode($raw, true) : null;
    return is_array($store) ? array_replace_recursive(ps_default(), $store) : ps_default();
}

function ps_date(string $value, string $label): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ps_out(['ok'=>false, 'error'=>$label . ' is required.'], 422);
    return $value;
}

function ps_decimal(mixed $value, string $label, bool $allowZero = false): float {
    if (!is_numeric($value)) ps_out(['ok'=>false, 'error'=>$label . ' is invalid.'], 422);
    $number = round((float)$value, 3);
    if ($number < 0 || (!$allowZero && $number <= 0)) ps_out(['ok'=>false, 'error'=>$label . ' is invalid.'], 422);
    return $number;
}

function ps_next_no(array $sodas): string {
    $year = gmdate('y');
    $maximum = 0;
    foreach ($sodas as $soda) {
        $number = (string)($soda['sodaNo'] ?? '');
        if (preg_match('/^' . preg_quote($year, '/') . '(\d{3})$/', $number, $match)) $maximum = max($maximum, (int)$match[1]);
    }
    return $year . str_pad((string)($maximum + 1), 3, '0', STR_PAD_LEFT);
}

function ps_paid_for_bill(array $store, string $billId): float {
    $total = 0.0;
    foreach ((array)($store['supplierSettlements'] ?? []) as $settlement) {
        if (!is_array($settlement) || !in_array((string)($settlement['status'] ?? ''), ['Posted','Approved / Posted'], true)) continue;
        foreach ((array)($settlement['allocations'] ?? []) as $allocation) {
            if (is_array($allocation) && (string)($allocation['billId'] ?? '') === $billId) $total += (float)($allocation['amount'] ?? 0);
        }
    }
    return round($total, 2);
}

function ps_rows(array $store, string $entity): array {
    $rows = [];
    foreach ((array)($store['purchaseSodas'] ?? []) as $id => $soda) {
        if (!is_array($soda) || strtoupper((string)($soda['entity'] ?? '')) !== $entity) continue;
        $commodity = strtoupper((string)($soda['commodity'] ?? 'RICE'));
        $number = (string)($soda['sodaNo'] ?? '');
        $received = 0.0; $truckUnits = 0; $physicalTrucks = 0; $unbilled = 0; $billed = 0.0; $paid = 0.0;
        $receipts = [];
        foreach ((array)($store['events'] ?? []) as $event) {
            if (!is_array($event) || ($event['eventType'] ?? '') !== 'COMMODITY_RECEIPT_ACCEPTED' || strtoupper((string)($event['entity'] ?? '')) !== $entity) continue;
            $journal = $store['journals'][$event['journalId'] ?? ''] ?? null;
            if (!is_array($journal)) continue;
            $meta = is_array($journal['meta'] ?? null) ? $journal['meta'] : [];
            if ((string)($meta['soda'] ?? '') !== $number) continue;
            $eventCommodity = strtoupper((string)($meta['commodity'] ?? $commodity));
            if ($eventCommodity !== $commodity) continue;
            $kg = (float)($meta['payableWeightKg'] ?? 0);
            $units = $kg > PS_TRUCK_DOUBLE_KG ? 2 : 1;
            $received += $kg; $truckUnits += $units; $physicalTrucks++;
            $billId = (string)($event['billId'] ?? '');
            if ($billId === '') $unbilled++;
            $receipts[] = ['date'=>(string)($journal['date'] ?? ''), 'truck'=>(string)($meta['truck'] ?? ''), 'pohanch'=>(string)($meta['pohanch'] ?? $journal['reference'] ?? ''), 'kg'=>$kg, 'truckUnits'=>$units, 'billed'=>$billId !== '', 'billId'=>$billId];
        }
        foreach ((array)($store['commodityBills'] ?? []) as $billId => $bill) {
            if (!is_array($bill) || strtoupper((string)($bill['entity'] ?? '')) !== $entity || !in_array($number, (array)($bill['sodas'] ?? []), true)) continue;
            if (strtoupper((string)($bill['commodity'] ?? $commodity)) !== $commodity) continue;
            $billed += (float)($bill['supplierPayableTotal'] ?? $bill['finalCommodityValue'] ?? 0);
            $paid += ps_paid_for_bill($store, (string)$billId);
        }
        $minimum = (float)($soda['qtyFromKg'] ?? 0); $maximum = (float)($soda['qtyToKg'] ?? 0); $expected = (int)($soda['expectedTrucks'] ?? 0);
        $hasWeight = $minimum > 0; $hasTrucks = $expected > 0;
        $weightMet = !$hasWeight || $received >= $minimum; $truckMet = !$hasTrucks || $truckUnits >= $expected;
        $maxAllowed = $maximum > 0 ? $maximum + PS_MAX_OVER_KG : 0; $overMaximum = $maxAllowed > 0 && $received > $maxAllowed + .001;
        $status = (string)($soda['status'] ?? 'Open');
        if (!in_array($status, ['Completed','Short Closed','Cancelled'], true)) {
            if ($received <= 0) $status = 'Open';
            elseif ($overMaximum) $status = 'Over Maximum — Review';
            elseif (!$weightMet || !$truckMet) $status = 'Part Received';
            elseif ($unbilled > 0) $status = 'Received / Bill Pending';
            else $status = 'Ready to Complete';
        }
        $rows[] = $soda + ['id'=>(string)$id, 'basis'=>$hasWeight && $hasTrucks ? 'BOTH' : ($hasWeight ? 'WEIGHT' : 'TRUCKS'), 'receivedKg'=>round($received,3), 'trucksReceived'=>$physicalTrucks, 'truckUnitsReceived'=>$truckUnits, 'unbilledArrivals'=>$unbilled, 'billedAmount'=>round($billed,2), 'paidAmount'=>round($paid,2), 'payableOutstanding'=>round(max(0,$billed-$paid),2), 'calculatedStatus'=>$status, 'maxAllowedKg'=>round($maxAllowed,3), 'overMaximum'=>$overMaximum, 'receipts'=>$receipts];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['sodaDate'] ?? ''), (string)($a['sodaDate'] ?? '')) ?: strcmp((string)($b['sodaNo'] ?? ''), (string)($a['sodaNo'] ?? '')));
    return $rows;
}

function ps_validate(array $body, ?array $existing = null): array {
    $commodity = strtoupper(trim((string)($body['commodity'] ?? $existing['commodity'] ?? 'RICE')));
    if (!in_array($commodity, ['RICE','CORN','SESAME'], true)) ps_out(['ok'=>false, 'error'=>'Select Rice, Corn / Makai or Sesame.'], 422);
    $date = ps_date((string)($body['sodaDate'] ?? ''), 'Soda date');
    $broker = trim((string)($body['broker'] ?? '')); $party = trim((string)($body['party'] ?? '')); $variety = trim((string)($body['variety'] ?? ''));
    if ($broker === '' || $variety === '') ps_out(['ok'=>false, 'error'=>'Broker and variety / type are required.'], 422);
    $brokerProfile = tt_broker_profile($broker, $date, 'buying');
    if (!$brokerProfile) ps_out(['ok'=>false, 'error'=>'Select an active Broker profile from Business Parties.'], 422);
    $broker = (string)$brokerProfile['name'];
    $fromRaw = trim((string)($body['qtyFromMT'] ?? '')); $toRaw = trim((string)($body['qtyToMT'] ?? '')); $trucksRaw = trim((string)($body['expectedTrucks'] ?? ''));
    if ($fromRaw === '' && $toRaw === '' && $trucksRaw === '') ps_out(['ok'=>false, 'error'=>'Enter minimum/maximum quantity, expected trucks, or both.'], 422);
    if ($toRaw !== '' && $fromRaw === '') ps_out(['ok'=>false, 'error'=>'Enter minimum quantity when using a maximum quantity.'], 422);
    $minimum = $fromRaw !== '' ? ps_decimal($fromRaw, 'Minimum quantity') * 1000 : 0.0;
    $maximum = $toRaw !== '' ? ps_decimal($toRaw, 'Maximum quantity') * 1000 : $minimum;
    if ($maximum > 0 && $maximum < $minimum) ps_out(['ok'=>false, 'error'=>'Maximum quantity cannot be below minimum quantity.'], 422);
    $trucks = $trucksRaw !== '' ? (int)$trucksRaw : 0;
    if ($trucksRaw !== '' && ($trucks < 1 || $trucks > 999)) ps_out(['ok'=>false, 'error'=>'Expected trucks must be between 1 and 999.'], 422);
    $rate = ps_decimal($body['rate'] ?? $body['ratePerKg'] ?? 0, 'Rate');
    $unit = strtoupper((string)($body['rateUnit'] ?? 'KG'));
    if (!in_array($unit, ['KG','MAUND'], true)) ps_out(['ok'=>false, 'error'=>'Invalid rate unit.'], 422);
    $payment = strtoupper((string)($body['paymentTermType'] ?? 'CASH'));
    if (!in_array($payment, ['CASH','CREDIT'], true)) ps_out(['ok'=>false, 'error'=>'Invalid payment terms.'], 422);
    $creditDays = $payment === 'CREDIT' ? (int)($body['creditDays'] ?? -1) : 0;
    if ($creditDays < 0 || $creditDays > 365) ps_out(['ok'=>false, 'error'=>'Credit days must be between 0 and 365.'], 422);
    $due = ps_date((string)($body['arrivalDueDate'] ?? $body['deliveryDeadline'] ?? ''), 'Arrival due date');
    return ['commodity'=>$commodity, 'sodaDate'=>$date, 'broker'=>$broker, 'party'=>$party, 'variety'=>$variety, 'location'=>trim((string)($body['location'] ?? '')), 'qtyFromKg'=>$minimum, 'qtyToKg'=>$maximum, 'expectedTrucks'=>$trucks, 'completionBasis'=>$minimum > 0 && $trucks > 0 ? 'BOTH' : ($minimum > 0 ? 'WEIGHT' : 'TRUCKS'), 'rate'=>$rate, 'ratePerKg'=>$unit === 'MAUND' ? round($rate/40, 6) : $rate, 'rateUnit'=>$unit, 'paymentTermType'=>$payment, 'creditDays'=>$creditDays, 'arrivalDueDate'=>$due, 'deliveryDeadline'=>$due, 'terms'=>trim((string)($body['terms'] ?? '')), 'remarks'=>trim((string)($body['remarks'] ?? '')), 'maxOverToleranceKg'=>PS_MAX_OVER_KG, 'doubleTruckAboveKg'=>PS_TRUCK_DOUBLE_KG];
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) ps_out(['ok'=>false, 'error'=>'Accounts permission required.'], 403);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $entity = strtoupper(trim((string)($_GET['entity'] ?? 'TTI')));
        if (!in_array($entity, ['TTI','BRM'], true)) ps_out(['ok'=>false, 'error'=>'Sodas are available only in the selected Pakistan legal books.'], 422);
        $store = ps_read();
        ps_out(['ok'=>true, 'sodas'=>ps_rows($store,$entity), 'nextSodaNo'=>ps_next_no((array)$store['purchaseSodas']), 'rules'=>['doubleTruckAboveKg'=>PS_TRUCK_DOUBLE_KG, 'maxOverKg'=>PS_MAX_OVER_KG]]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ps_out(['ok'=>false, 'error'=>'Method not allowed.'], 405);
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) ps_out(['ok'=>false, 'error'=>'Session expired. Refresh and try again.'], 419);
    $action = (string)($body['action'] ?? '');
    // The approved Accounts rule allows every Accounts user to amend a Soda.
    // Create and status controls continue to respect the user's write permission.
    if ($action !== 'amend' && !ps_can_write($user)) ps_out(['ok'=>false, 'error'=>'Accounts Create or Edit permission required.'], 403);
    $entity = strtoupper(trim((string)($body['entity'] ?? 'TTI')));
    if (!in_array($entity, ['TTI','BRM'], true)) ps_out(['ok'=>false, 'error'=>'Invalid legal entity.'], 422);
    tt_ensure_data_dir();
    $handle = fopen(PS_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('store');
    try {
        rewind($handle); $raw = stream_get_contents($handle); $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = ps_default();
        $store = array_replace_recursive(ps_default(), $store); $created = null;
        if ($action === 'create') {
            $values = ps_validate($body); $number = ps_next_no((array)$store['purchaseSodas']); $id = 'PS-' . $values['commodity'] . '-' . $number;
            $created = ['id'=>$id, 'entity'=>$entity, 'sodaNo'=>$number, 'status'=>'Open', 'createdAt'=>gmdate('c'), 'createdBy'=>ps_user_name($user), 'audit'=>[]] + $values;
            $store['purchaseSodas'][$id] = $created;
        } elseif ($action === 'amend') {
            $id = trim((string)($body['id'] ?? '')); $reason = trim((string)($body['reason'] ?? ''));
            if ($reason === '') ps_out(['ok'=>false, 'error'=>'Reason for amendment is required.'], 422);
            $old = $store['purchaseSodas'][$id] ?? null;
            if (!is_array($old) || strtoupper((string)($old['entity'] ?? '')) !== $entity) ps_out(['ok'=>false, 'error'=>'Soda not found in the selected legal books.'], 404);
            $values = ps_validate($body, $old); $changes = [];
            foreach ($values as $key => $newValue) {
                $oldValue = $old[$key] ?? null;
                if ((string)$oldValue !== (string)$newValue) $changes[$key] = ['from'=>$oldValue, 'to'=>$newValue];
            }
            if (!$changes) ps_out(['ok'=>false, 'error'=>'No Soda details were changed.'], 422);
            $audit = is_array($old['audit'] ?? null) ? $old['audit'] : [];
            $audit[] = ['at'=>gmdate('c'), 'by'=>ps_user_name($user), 'reason'=>$reason, 'changes'=>$changes];
            $store['purchaseSodas'][$id] = array_replace($old, $values, ['audit'=>$audit, 'amendedAt'=>gmdate('c'), 'amendedBy'=>ps_user_name($user)]);
        } elseif ($action === 'set_status') {
            $id = trim((string)($body['id'] ?? '')); $status = (string)($body['status'] ?? ''); $reason = trim((string)($body['reason'] ?? ''));
            if (!in_array($status, ['Open','Completed','Short Closed','Cancelled'], true)) ps_out(['ok'=>false, 'error'=>'Invalid Soda status.'], 422);
            if ($reason === '') ps_out(['ok'=>false, 'error'=>'Reason is required for a Soda status change.'], 422);
            $old = $store['purchaseSodas'][$id] ?? null;
            if (!is_array($old) || strtoupper((string)($old['entity'] ?? '')) !== $entity) ps_out(['ok'=>false, 'error'=>'Soda not found.'], 404);
            $audit = is_array($old['audit'] ?? null) ? $old['audit'] : [];
            $audit[] = ['at'=>gmdate('c'), 'by'=>ps_user_name($user), 'reason'=>$reason, 'changes'=>['status'=>['from'=>$old['status'] ?? '', 'to'=>$status]]];
            $store['purchaseSodas'][$id] = array_replace($old, ['status'=>$status, 'audit'=>$audit, 'statusChangedAt'=>gmdate('c'), 'statusChangedBy'=>ps_user_name($user)]);
        } else ps_out(['ok'=>false, 'error'=>'Unknown action.'], 422);
        $store['revision'] = (int)($store['revision'] ?? 0) + 1;
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); fflush($handle);
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    ps_out(['ok'=>true, 'sodas'=>ps_rows($store,$entity), 'nextSodaNo'=>ps_next_no((array)$store['purchaseSodas']), 'created'=>$created]);
} catch (Throwable $error) {
    error_log('purchase_sodas: ' . $error->getMessage());
    ps_out(['ok'=>false, 'error'=>'Soda control is temporarily unavailable.'], 500);
}
