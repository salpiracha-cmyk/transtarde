<?php
declare(strict_types=1);

require_once __DIR__.'/arrival_withdrawal_core.php';
require_once __DIR__.'/mill_recovery_core.php';

const PR_RECEIPT_EVENT = 'COMMODITY_RECEIPT_ACCEPTED';
const PR_RECEIPT_MARKER = 'accountsReceiptRequested';

/** The committed Pohanch is the durable work item. Only the receipt save can assign its marker. */
function pr_handoff_lock() {
    $lock = fopen(TT_DATA_DIR.'/pohanch-handoff.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Pohanch handover is unavailable. Retry.');
    return $lock;
}

function pr_stamp_receipt_requests(string $prior, string $candidate, ?array $arrival, array $user): string {
    $before = array_column(json_decode($prior ?: '[]', true, 512, JSON_THROW_ON_ERROR), null, 'id');
    $rows = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
    $changed = false;
    foreach ($rows as &$row) {
        $old = $before[$row['id'] ?? ''] ?? null;
        $marker = $old[PR_RECEIPT_MARKER] ?? null;
        if ($old === null && $arrival !== null && (string)($row['queueId'] ?? '') === (string)$arrival['id']) {
            $marker = ['userId'=>(int)($user['id'] ?? 0), 'name'=>(string)($user['full_name'] ?? $user['username'] ?? 'Mill staff')];
        }
        if ($old !== null && $arrival !== null && (string)($row['queueId'] ?? '') === (string)$arrival['id'] &&
            pr_amendment_fields_changed($old, $row)) {
            $marker = ['userId'=>(int)($user['id'] ?? 0), 'name'=>(string)($user['full_name'] ?? $user['username'] ?? 'Mill staff'),
                'amendment'=>bin2hex(random_bytes(16))];
        }
        if ($marker === ($row[PR_RECEIPT_MARKER] ?? null)) continue;
        $changed = true;
        if ($marker === null) unset($row[PR_RECEIPT_MARKER]);
        else $row[PR_RECEIPT_MARKER] = $marker;
    }
    unset($row);
    return $changed ? json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $candidate;
}

function pr_source_key(array $slip): string {
    $mill = (string)($slip['millId'] ?? 'MILL');
    $bridge = (string)($slip['bridgeId'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._:-]{1,45}$/D', $mill) || !preg_match('/^[A-Za-z0-9._:-]{1,120}$/D', $bridge)) {
        throw new DomainException('The saved Pohanch lacks a stable Mill receipt identity.');
    }
    return 'POHANCH|'.$mill.'|'.$bridge;
}

function pr_soda(array $store, string $entity, array $slip): array {
    $no = (string)($slip['soda'] ?? '');
    $sodaId = (string)($slip['sourceSodaId'] ?? '');
    foreach (['purchaseSodas','purchaseSodasV2'] as $collection) {
        foreach (($store[$collection] ?? []) as $id => $soda) {
            if (($soda['entity'] ?? '') !== $entity || (string)($soda['sodaNo'] ?? '') !== $no) continue;
            if ($sodaId === '' || (string)($soda['id'] ?? $id) !== $sodaId) {
                throw new DomainException('The original SODA could not be matched to this Pohanch.');
            }
            if (strtoupper((string)($soda['readyRoute'] ?? '')) === 'EX_MILL' ||
                strtoupper((string)(($soda['manualStatus'] ?? '') ?: ($soda['status'] ?? ''))) === 'CANCELLED') {
                throw new DomainException('The linked SODA does not accept this Mill receipt.');
            }
            if (!empty($soda['locationId']) && (string)$soda['locationId'] !== (string)($slip['locationId'] ?? '')) {
                throw new DomainException('The saved receiving location differs from the SODA.');
            }
            return $soda;
        }
    }
    throw new DomainException('The original SODA could not be found for this company.');
}

/** Recognize older receipts as well as the current bridge identity, including reversed records. */
function pr_existing_receipt(array $store, string $entity, array $slip, string $source): ?string {
    $id = $store['postingIdentities'][$entity.'|'.$source]['journalId'] ?? null;
    if ($id !== null || isset($store['postingIdentities'][$entity.'|'.$source])) {
        return (string)$id;
    }
    $sameLegacy = static function(array $meta) use ($slip): bool {
        return (string)($meta['soda'] ?? '') === (string)($slip['soda'] ?? '') &&
            strcasecmp(trim((string)($meta['pohanch'] ?? '')), trim((string)$slip['slip'])) === 0 &&
            strcasecmp(trim((string)($meta['truck'] ?? '')), trim((string)$slip['truck'])) === 0;
    };
    foreach (($store['events'] ?? []) as $event) {
        if (($event['entity'] ?? '') !== $entity) continue;
        if (($event['sourceKey'] ?? '') === $source) return (string)($event['journalId'] ?? '');
        if (($event['eventType'] ?? '') !== PR_RECEIPT_EVENT) continue;
        $journal = $store['journals'][$event['journalId'] ?? ''] ?? [];
        if ($sameLegacy($journal['meta'] ?? [])) return (string)($event['journalId'] ?? '');
    }
    foreach (($store['journals'] ?? []) as $id => $journal) {
        if (($journal['entity'] ?? '') !== $entity || ($journal['sourceType'] ?? '') !== PR_RECEIPT_EVENT) continue;
        $meta = $journal['meta'] ?? [];
        if (($meta['sourceKey'] ?? '') === $source || $sameLegacy($meta)) return (string)$id;
    }
    foreach (($store['commodityBills'] ?? []) as $bill) {
        if (($bill['entity'] ?? '') === $entity && in_array($source, $bill['sourceKeys'] ?? [], true)) {
            return (string)($bill['journalId'] ?? '');
        }
    }
    return null;
}

function pr_number(mixed $n): float {
    $n = $n === '' || $n === null ? 0 : $n;
    if (!is_numeric($n) || !is_finite((float)$n) || (float)$n < 0) {
        throw new DomainException('The saved Pohanch contains an invalid weight, rate or KAT.');
    }
    return (float)$n;
}

function pr_receipt_values(array $slip, array $soda): array {
    $commodity = strtoupper((string)($slip['commodity'] ?? ''));
    if (!in_array($commodity, ['RICE','CORN','SESAME'], true) || $commodity !== strtoupper((string)($soda['commodity'] ?? ''))) {
        throw new DomainException('The saved commodity differs from the original SODA.');
    }
    $kg = pr_number($slip['payableWeight'] ?? null);
    $rate = pr_number($slip['purchaseRate'] ?? null);
    $moistureKg = $commodity === 'RICE' ? pr_number($slip['moistureKatKg'] ?? null) : 0;
    $fields = ['brokenKat','damageKat','chalkyKat','paddyKat'];
    if ($commodity !== 'RICE') $fields[] = 'moistureKat';
    $kat = array_sum(array_map(static fn(string $field): float => pr_number($slip[$field] ?? null), $fields));
    $netRate = $rate - $kat / 100;
    $amount = round(($kg - $moistureKg) * $netRate, 2);
    if ($kg <= $moistureKg || $netRate <= 0 || $amount <= 0 || !is_finite($amount)) {
        throw new DomainException('Review the original Pohanch purchase rate, weight and KAT before restoring its receipt.');
    }
    return compact('commodity','kg','rate','moistureKg','kat','netRate','amount');
}

/** Use the same inventory and goods-received-not-invoiced posting as the existing receipt event. */
function pr_post_receipt(array &$store, array $slip, array $actor): array {
    $entity = (string)($slip['entity'] ?? '');
    if (!in_array($entity, ['TTI','BRM'], true) || empty($slip['slip']) || empty($slip['soda']) || empty($slip['truck']) ||
        !empty($slip['deleted']) || !empty($slip['cancelled']) || !empty($slip['reversed']) ||
        in_array(strtolower((string)($slip['status'] ?? '')), ['cancelled','deleted','reversed'], true) ||
        tt_is_withdrawn_arrival($slip) || ($slip['readyRoute'] ?? '') === 'EX_MILL') {
        throw new DomainException('The saved record is not an active Mill Pohanch.');
    }
    $source = pr_source_key($slip);
    $existing = pr_existing_receipt($store, $entity, $slip, $source);
    $amendment = pr_pending_amendment($slip, $store['journals'][$existing] ?? []);
    if ($existing !== null && $amendment === '') return ['status'=>'existing','journalId'=>$existing];
    if ($existing !== null) pr_require_unbilled_receipt($store, $entity, $source, $existing);
    $date = (string)(($slip['unloadingDate'] ?? '') ?: ($slip['arrivalDate'] ?? ''));
    if (!mr_valid_date($date)) throw new DomainException('The saved Pohanch has no valid unloading date.');
    $soda = pr_soda($store, $entity, $slip);
    $value = pr_receipt_values($slip, $soda);
    $master = json_decode(file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $commodity = array_column($master['commodities'], null, 'code')[$value['commodity']] ?? [];
    $inventory = (string)($commodity['inventoryAccount'] ?? '');
    $names = array_column($master['chart'], 'name', 'code');
    if (empty($commodity['sodaEnabled']) || !isset($names[$inventory], $names['2210'])) {
        throw new DomainException('The receipt accounts are unavailable for this commodity.');
    }
    $identity = $entity.'|'.$source;
    $reference = (string)$slip['slip'];
    $creator = (string)($actor['name'] ?? 'Mill receipt service');
    $actorId = (int)($actor['userId'] ?? 0);
    $journalId = $existing ?? tt_next_post_id($store['journals'] ?? [], 'Accounts', 'Journal', $date);
    $meta = array_intersect_key($slip, array_flip(['soda','sourceSodaId','purchaseProductId','locationId',
        'millName','truck','variety','baseVariety','riceType','productStage','displayName','readyRoute']));
    $meta += ['pohanch'=>$reference,'broker'=>(string)($soda['broker'] ?? ''),'party'=>(string)($soda['party'] ?? ''),
        'bags'=>pr_number($slip['bags'] ?? null),'payableWeightKg'=>$value['kg'],'grossRatePerKg'=>$value['rate'],
        'katPaisaPerKg'=>$value['kat'],'provisionalNetRatePerKg'=>$value['netRate'],'sourceKey'=>$source,
        'postingIdentity'=>$identity,'valuation'=>'Provisional receipt value; final bill clears GRNI and posts authorized differences.'];
    $amount = $value['amount'];
    $journal = ['id'=>$journalId,'entity'=>$entity,'date'=>$date,'sourceType'=>PR_RECEIPT_EVENT,
        'reference'=>$reference,'narration'=>$commodity['name'].' receipt — Soda '.$slip['soda'].' — '.$slip['truck'],
        'lines'=>[
            ['account'=>$inventory,'accountName'=>$names[$inventory],'debit'=>$amount,'credit'=>0],
            ['account'=>'2210','accountName'=>$names['2210'],'debit'=>0,'credit'=>$amount],
        ],'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),
        'createdBy'=>$creator,'userId'=>$actorId,'reversalOf'=>null];
    if ($amendment !== '') $journal['meta']['pohanchAmendment'] = $amendment;
    if ($existing !== null) {
        $old = $store['journals'][$existing];
        $journal['meta']['sourceKey'] = $old['meta']['sourceKey'] ?? $source;
        $journal['meta']['postingIdentity'] = $old['meta']['postingIdentity'] ?? $identity;
        $journal['meta']['pohanchSourceKey'] = $source;
        $journal['meta'] = array_replace($old['meta'] ?? [], $journal['meta']);
        $before = array_intersect_key($old, array_flip(['date','reference','narration','lines','totalDebit','totalCredit','meta']));
        $store['journals'][$existing] = array_replace($old,
            array_intersect_key($journal, $before));
        $store['workflowAudit'][] = ['at'=>gmdate('c'),'type'=>'POHANCH_RECEIPT','id'=>$existing,
            'action'=>'AMEND','user'=>$creator,'before'=>$before,
            'after'=>array_intersect_key($journal, $before)];
        return ['status'=>'amended','journalId'=>$existing,'amount'=>$amount];
    }
    $store['journals'][$journalId] = $journal;
    $eventId = $entity.'|'.PR_RECEIPT_EVENT.'|'.$source;
    $event = ['id'=>$eventId,'entity'=>$entity,'eventType'=>PR_RECEIPT_EVENT,'sourceKey'=>$source,
        'postingIdentity'=>$identity,'journalId'=>$journalId,'postedAt'=>gmdate('c'),'postedBy'=>$creator,
        'commodity'=>$value['commodity']];
    $store['events'][$eventId] = $event;
    $store['postingIdentities'][$identity] = $event;
    return ['status'=>'posted','journalId'=>$journalId,'amount'=>$amount];
}

function pr_with_accounts(callable $work): array {
    $handle = fopen(TT_DATA_DIR.'/accounts.json', 'r+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Accounts storage is unavailable.');
    try {
        $store = json_decode(stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($store) || !is_array($store['journals'] ?? null) || !is_array($store['events'] ?? [])) {
            throw new RuntimeException('Accounts storage is invalid.');
        }
        $store['events'] ??= [];
        $result = $work($store);
        if (array_filter($result, static fn(array $r): bool => in_array($r['status'], ['posted','amended'], true))) {
            $store['revision'] = (int)($store['revision'] ?? 0) + 1;
            $encoded = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('Accounts could not save receipt postings.');
            }
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function pr_amendment_fields_changed(array $old, array $row): bool {
    $fields = ['entity','millId','millName','locationId','sourceSodaId','soda','slip','truck','queueId','bridgeId',
        'commodity','purchaseProductId','variety','baseVariety','riceType','productStage','displayName','readyRoute',
        'arrivalDate','unloadingDate','bags','payableWeight','purchaseRate','moistureKatKg','moistureKat',
        'brokenKat','damageKat','chalkyKat','paddyKat'];
    foreach ($fields as $field) {
        if ((string)($old[$field] ?? '') !== (string)($row[$field] ?? '')) return true;
    }
    return false;
}

function pr_pending_amendment(array $slip, array $journal): string {
    $revision = (string)($slip[PR_RECEIPT_MARKER]['amendment'] ?? '');
    $date = (string)($slip['amendedAt'] ?? '');
    if ($revision === '' && $date !== '' && !empty($journal['createdAt']) &&
        strtotime($date) > strtotime((string)$journal['createdAt'])) $revision = $date;
    return $revision === ($journal['meta']['pohanchAmendment'] ?? '') ? '' : $revision;
}

function pr_require_unbilled_receipt(array $store, string $entity, string $source, string $journalId): void {
    $journal = $store['journals'][$journalId] ?? [];
    if (($journal['entity'] ?? '') !== $entity || ($journal['sourceType'] ?? '') !== PR_RECEIPT_EVENT ||
        ($journal['status'] ?? '') !== 'Posted' || !empty($journal['reversalOf']) ||
        !empty($journal['reversedByPostId']) || !empty($journal['meta']['deletedFromBooks'])) {
        throw new DomainException('The Pohanch Accounts entry needs review before it can be amended.');
    }
    $sources = [$source];
    foreach ($store['events'] as $event) {
        if (($event['entity'] ?? '') !== $entity || ($event['journalId'] ?? '') !== $journalId) continue;
        if (!empty($event['billId']) || ($event['status'] ?? '') === 'Reversed')
            throw new DomainException('This Pohanch is already linked to an Accounts bill or reversal. Correct the Accounts entry before amending it.');
        $sources[] = (string)($event['sourceKey'] ?? '');
    }
    foreach (($store['commodityBills'] ?? []) as $bill) {
        if (($bill['entity'] ?? '') === $entity && array_intersect($sources, $bill['sourceKeys'] ?? []))
            throw new DomainException('This Pohanch already has an Accounts bill. Correct the bill before amending its Pohanch.');
    }
}

function pr_validate_amendments(string $prior, string $candidate): void {
    $before = array_column(json_decode($prior ?: '[]', true, 512, JSON_THROW_ON_ERROR), null, 'id');
    $amendments = [];
    foreach (json_decode($candidate, true, 512, JSON_THROW_ON_ERROR) as $row) {
        $old = $before[$row['id']] ?? null;
        if (!$old || !pr_amendment_fields_changed($old, $row)) continue;
        foreach (['entity','millId','bridgeId','queueId','slip'] as $field) {
            if ((string)($old[$field] ?? '') !== (string)($row[$field] ?? ''))
                throw new DomainException('Keep the original Pohanch identity when amending a receipt.');
        }
        if (empty($row[PR_RECEIPT_MARKER]['amendment']) ||
            ($old[PR_RECEIPT_MARKER]['amendment'] ?? '') === $row[PR_RECEIPT_MARKER]['amendment'])
            throw new DomainException('Reopen the linked Arrival before amending its Pohanch.');
        $amendments[] = [$old,$row];
    }
    if (!$amendments) return;
    pr_with_accounts(static function (array &$store) use ($amendments): array {
        foreach ($amendments as [$old,$row]) {
            $source = pr_source_key($old);
            $existing = pr_existing_receipt($store, $old['entity'], $old, $source);
            if ($existing !== null && $existing !== pr_existing_receipt($store, $row['entity'], $row, $source))
                throw new DomainException('The original Accounts receipt must remain linked when amending its Pohanch.');
            pr_post_receipt($store, $row, []);
        }
        return []; // Validate on a copy of the ledger before the operational commit.
    });
}

function pr_reconcile(?array $target = null, array $actor = [], bool $handoffLocked = false): array {
    $lock = $handoffLocked ? null : pr_handoff_lock();
    try {
        return pr_reconcile_locked($target, $actor);
    } finally {
        if ($lock !== null) fclose($lock);
    }
}

function pr_reconcile_locked(?array $target, array $actor): array {
    $values = mr_read_operations_source(TT_DATA_DIR.'/operations.json', ['tt30slips'])['values'];
    $slips = json_decode($values['tt30slips'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    $candidates = [];
    foreach ($slips as $slip) {
        if (!is_array($slip) || !empty($slip['deleted']) || !empty($slip['cancelled']) || !empty($slip['reversed'])) continue;
        if ($target !== null) {
            if (($slip['entity'] ?? '') !== $target['entity'] || (string)($slip['soda'] ?? '') !== $target['soda'] ||
                ($slip['slip'] ?? '') !== $target['pohanch'] || (isset($target['id']) && $target['id'] !== (string)($slip['id'] ?? ''))) continue;
        } elseif (!is_array($slip[PR_RECEIPT_MARKER] ?? null) && ($slip['printed'] ?? false) !== true && empty($slip['amendedAt'])) {
            // Issued slips from before the receipt marker also need reconciliation.
            continue;
        }
        $candidates[] = $slip;
    }
    if ($target !== null && count($candidates) !== 1) throw new DomainException('Could not match one original Pohanch for this SODA and company.');
    if (!$candidates) return [];
    $counts = [];
    foreach ($slips as $slip) {
        if (!is_array($slip) || empty($slip['bridgeId'])) continue;
        $key = ($slip['entity'] ?? '').'|'.$slip['bridgeId'];
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    return pr_with_accounts(static function (array &$store) use ($candidates, $counts, $actor, $target): array {
        $results = [];
        foreach ($candidates as $slip) {
            try {
                if (($counts[$slip['entity'].'|'.($slip['bridgeId'] ?? '')] ?? 0) !== 1) {
                    throw new DomainException('The saved Pohanch needs a unique receipt identity.');
                }
                if ($target === null && empty($slip[PR_RECEIPT_MARKER]) && ($slip['printed'] ?? false) !== true &&
                    pr_existing_receipt($store, $slip['entity'], $slip, pr_source_key($slip)) === null) continue;
                $result = pr_post_receipt($store, $slip, $actor ?: (is_array($slip[PR_RECEIPT_MARKER] ?? null) ? $slip[PR_RECEIPT_MARKER] : []));
            } catch (DomainException $error) {
                if ($target !== null) throw $error;
                $result = ['status'=>'review','error'=>$error->getMessage()];
            }
            $results[] = ['pohanch'=>$slip['slip']] + $result;
        }
        return $results;
    });
}

/** Try immediately after the operational commit. The marker survives any outage. */
function pr_try_saved_receipt(string $key, ?array $arrival, bool $printOnly, string $payload): void {
    if ($key !== 'tt30slips' || $arrival === null || $printOnly) return;
    try {
        foreach (json_decode($payload, true, 512, JSON_THROW_ON_ERROR) as $slip) {
            if ((string)$slip['queueId'] !== (string)$arrival['id'] || empty($slip[PR_RECEIPT_MARKER])) continue;
            pr_reconcile(['entity'=>$slip['entity'],'soda'=>(string)$slip['soda'],'pohanch'=>$slip['slip'],'id'=>(string)$slip['id']], [], true);
            return;
        }
    } catch (Throwable $error) {
        error_log('Mill Pohanch receipt pending: '.$error->getMessage());
    }
}
