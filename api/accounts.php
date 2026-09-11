<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';
const TT_ACCOUNTS_MASTER_FILE = __DIR__ . '/../accounts/accounting_master_v1.json';

function accounts_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function accounts_user_has_module_write(array $user, string $moduleName): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $module = $user['permissions'][$moduleName] ?? null;
    if ($module === 'all') return true;
    if (!is_array($module)) return false;
    if (in_array('Create', $module, true) || in_array('Edit', $module, true)) return true;
    foreach ($module as $actions) {
        if (is_array($actions) && (in_array('Create', $actions, true) || in_array('Edit', $actions, true))) return true;
    }
    return false;
}

function accounts_can_write(array $user): bool {
    return accounts_user_has_module_write($user, 'Accounts');
}

function accounts_source_event_allowed(array $user, string $eventType): bool {
    if (accounts_can_write($user)) return true;
    $map = [
        'COMMODITY_RECEIPT_ACCEPTED' => 'Mill',
        'LOCAL_SALE_RECOGNIZED' => 'Mill',
        'EXPORT_SALE_RECOGNIZED' => 'Exports',
    ];
    $module = $map[$eventType] ?? null;
    return $module ? accounts_user_has_module_write($user, $module) : false;
}

function accounts_master(): array {
    if (!is_file(TT_ACCOUNTS_MASTER_FILE)) throw new RuntimeException('Accounts master configuration missing.');
    $raw = file_get_contents(TT_ACCOUNTS_MASTER_FILE);
    $master = $raw ? json_decode($raw, true) : null;
    if (!is_array($master)) throw new RuntimeException('Accounts master configuration invalid.');
    return $master;
}

function accounts_store_default(): array {
    $master = accounts_master();
    return [
        'revision' => 0,
        'journals' => [],
        'events' => [],
        'reminders' => [],
        'masters' => [
            'commodities' => (array)($master['commodities'] ?? []),
            'rules' => [],
            'bankAccounts' => [],
            'creditCards' => [],
            'expenseMappings' => [],
        ],
    ];
}

function accounts_read(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_ACCOUNTS_FILE)) return accounts_store_default();
    $h = fopen(TT_ACCOUNTS_FILE, 'r');
    if ($h === false || !flock($h, LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try { $raw = stream_get_contents($h); }
    finally { flock($h, LOCK_UN); fclose($h); }
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) ? array_replace_recursive(accounts_store_default(), $decoded) : accounts_store_default();
}

function accounts_validate_entity(string $entity): string {
    $entity = strtoupper(trim($entity));
    if (!in_array($entity, ['TTI','BRM','TG'], true)) accounts_respond(['ok'=>false,'error'=>'Invalid legal entity.'], 422);
    return $entity;
}

function accounts_require_entity_access(array $user, string $entity, string $action = 'View'): void {
    if (!tt_user_can_access_entity($user, $entity, $action)) {
        accounts_respond(['ok'=>false,'error'=>'You do not have permission for this legal entity.'], 403);
    }
}

function accounts_account_catalog(): array {
    $m = accounts_master();
    $out = [];
    foreach ((array)($m['chart'] ?? []) as $a) {
        if (!is_array($a) || empty($a['code'])) continue;
        $out[(string)$a['code']] = $a;
    }
    foreach ((array)($m['peopleSubledgers'] ?? []) as $a) {
        if (!is_array($a) || empty($a['code'])) continue;
        $out[(string)$a['code']] = array_merge(['class'=>'Subledger','level'=>'subledger','normal'=>'Debit'], $a);
    }
    return $out;
}

function accounts_resolve_account(string $value): array {
    $value = trim($value);
    if ($value === '') accounts_respond(['ok'=>false,'error'=>'Account is required.'], 422);
    $catalog = accounts_account_catalog();
    if (isset($catalog[$value])) return $catalog[$value];
    foreach ($catalog as $a) if (strcasecmp((string)($a['name'] ?? ''), $value) === 0) return $a;
    accounts_respond(['ok'=>false,'error'=>'Account is not in the approved Chart of Accounts: '.$value], 422);
}

function accounts_normalize_lines(mixed $raw): array {
    if (!is_array($raw) || count($raw) < 2 || count($raw) > 100) accounts_respond(['ok'=>false,'error'=>'A journal requires at least two valid lines.'], 422);
    $lines = []; $dr = 0.0; $cr = 0.0;
    foreach ($raw as $line) {
        if (!is_array($line)) accounts_respond(['ok'=>false,'error'=>'Invalid journal line.'], 422);
        $account = accounts_resolve_account((string)($line['account'] ?? ''));
        $debit = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);
        if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
            accounts_respond(['ok'=>false,'error'=>'Every journal line needs either a debit or a credit, not both.'], 422);
        }
        $lines[] = ['account'=>(string)$account['code'],'accountName'=>(string)$account['name'],'debit'=>$debit,'credit'=>$credit];
        $dr += $debit; $cr += $credit;
    }
    $dr = round($dr, 2); $cr = round($cr, 2);
    if ($dr <= 0 || abs($dr - $cr) > 0.005) accounts_respond(['ok'=>false,'error'=>'Journal is not balanced.'], 422);
    return [$lines,$dr,$cr];
}

function accounts_next_id(array $items, string $prefix): string {
    $n = count($items) + 1;
    do { $id = $prefix . '-' . gmdate('Y') . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT); $n++; }
    while (array_key_exists($id, $items));
    return $id;
}

function accounts_write_locked(callable $mutator): array {
    tt_ensure_data_dir();
    $h = fopen(TT_ACCOUNTS_FILE, 'c+');
    if ($h === false || !flock($h, LOCK_EX)) throw new RuntimeException('Accounts storage unavailable.');
    try {
        rewind($h); $raw = stream_get_contents($h); $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = accounts_store_default();
        $store = array_replace_recursive(accounts_store_default(), $store);
        $result = $mutator($store);
        $store['revision'] = (int)($store['revision'] ?? 0) + 1;
        rewind($h);
        if (!ftruncate($h, 0)) throw new RuntimeException('Accounts storage could not be updated.');
        $encoded = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (fwrite($h, $encoded) === false) throw new RuntimeException('Accounts storage could not be written.');
        fflush($h);
        return ['store'=>$store,'result'=>$result];
    } finally { flock($h, LOCK_UN); fclose($h); }
}

function accounts_valid_date(string $date): string {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $parsed->format('Y-m-d') !== $date) {
        accounts_respond(['ok'=>false,'error'=>'Valid accounting date required.'], 422);
    }
    return $date;
}

function accounts_positive_amount(mixed $value, string $label = 'Amount'): float {
    $amount = round((float)$value, 2);
    if ($amount <= 0) accounts_respond(['ok'=>false,'error'=>$label.' must be greater than zero.'], 422);
    return $amount;
}

function accounts_assert_account_class(string $code, array $allowedCodes = [], ?string $class = null): array {
    $a = accounts_resolve_account($code);
    if ($allowedCodes && !in_array((string)$a['code'], $allowedCodes, true)) accounts_respond(['ok'=>false,'error'=>'Account '.$code.' is not allowed for this event.'], 422);
    if ($class !== null && strcasecmp((string)($a['class'] ?? ''), $class) !== 0) accounts_respond(['ok'=>false,'error'=>'Account '.$code.' has the wrong accounting class for this event.'], 422);
    return $a;
}

function accounts_post_journal_to_store(array &$store, array $user, string $entity, string $date, string $sourceType, string $reference, string $narration, array $rawLines, array $meta = [], ?string $forcedPrefix = null): array {
    [$lines,$dr,$cr] = accounts_normalize_lines($rawLines);
    $id = accounts_next_id((array)$store['journals'], $forcedPrefix ?: 'JV');
    $store['journals'][$id] = [
        'id'=>$id,'entity'=>$entity,'date'=>$date,'sourceType'=>$sourceType,'reference'=>$reference,
        'narration'=>$narration,'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted',
        'meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
        'userId'=>(int)($user['id'] ?? 0),'reversalOf'=>null,
    ];
    return $store['journals'][$id];
}

function accounts_person_reimbursement_account(string $person): string {
    $k = strtoupper(trim($person));
    $map = ['SALMAN'=>'REIMB-SALMAN','TALHA'=>'REIMB-TALHA','ABU'=>'REIMB-ABU','TAYYAB'=>'REIMB-TAYYAB'];
    if (!isset($map[$k])) accounts_respond(['ok'=>false,'error'=>'Person is not configured for reimbursement.'], 422);
    return $map[$k];
}

function accounts_person_personal_account(string $person): string {
    $k = strtoupper(trim($person));
    $map = ['SALMAN'=>'FAM-SALMAN','TALHA'=>'FAM-TALHA','ABU'=>'FAM-ABU','TAYYAB'=>'FAM-TAYYAB'];
    if (!isset($map[$k])) accounts_respond(['ok'=>false,'error'=>'Person is not configured for family/personal allocation.'], 422);
    return $map[$k];
}

function accounts_event_lines(string $eventType, array $body): array {
    $amount = accounts_positive_amount($body['amount'] ?? 0);
    switch ($eventType) {
        case 'UTILITY_PAYMENT': {
            $expense = (string)($body['expenseAccount'] ?? '6100');
            accounts_assert_account_class($expense, ['6100']);
            $pay = (string)($body['payAccount'] ?? '');
            accounts_assert_account_class($pay, ['1110','1120']);
            if (!empty($body['accrualAccount'])) {
                $accrual = (string)$body['accrualAccount']; accounts_assert_account_class($accrual, ['2220']);
                return [['account'=>$accrual,'debit'=>$amount,'credit'=>0],['account'=>$pay,'debit'=>0,'credit'=>$amount]];
            }
            return [['account'=>$expense,'debit'=>$amount,'credit'=>0],['account'=>$pay,'debit'=>0,'credit'=>$amount]];
        }
        case 'EXPENSE_REIMBURSEMENT_CAPTURE': {
            $expense = (string)($body['expenseAccount'] ?? '');
            $allowed = ['6100','6200','6300','6400','6500','6600','6700','6800','6900'];
            accounts_assert_account_class($expense, $allowed);
            $payable = accounts_person_reimbursement_account((string)($body['person'] ?? ''));
            return [['account'=>$expense,'debit'=>$amount,'credit'=>0],['account'=>$payable,'debit'=>0,'credit'=>$amount]];
        }
        case 'EXPENSE_REIMBURSEMENT_SETTLE': {
            $payable = accounts_person_reimbursement_account((string)($body['person'] ?? ''));
            $pay = (string)($body['payAccount'] ?? ''); accounts_assert_account_class($pay, ['1110','1120']);
            return [['account'=>$payable,'debit'=>$amount,'credit'=>0],['account'=>$pay,'debit'=>0,'credit'=>$amount]];
        }
        case 'COMMODITY_RECEIPT_ACCEPTED': {
            $commodity = strtoupper(trim((string)($body['commodity'] ?? '')));
            $master = accounts_master(); $found = null;
            foreach ((array)($master['commodities'] ?? []) as $c) if (($c['code'] ?? '') === $commodity) { $found = $c; break; }
            if (!is_array($found) || empty($found['sodaEnabled'])) accounts_respond(['ok'=>false,'error'=>'Commodity is not enabled for Soda purchasing.'], 422);
            $inv = (string)($found['inventoryAccount'] ?? '1310');
            return [['account'=>$inv,'debit'=>$amount,'credit'=>0],['account'=>'2210','debit'=>0,'credit'=>$amount]];
        }
        case 'COMMODITY_BILL_VERIFIED': {
            $grni = accounts_positive_amount($body['grniAmount'] ?? $amount, 'GRNI amount');
            $gross = accounts_positive_amount($body['grossPayable'] ?? $amount, 'Gross payable');
            $variance = round($gross - $grni, 2);
            $lines = [['account'=>'2210','debit'=>$grni,'credit'=>0]];
            if ($variance > 0) $lines[] = ['account'=>'5400','debit'=>$variance,'credit'=>0];
            elseif ($variance < 0) $lines[] = ['account'=>'5400','debit'=>0,'credit'=>abs($variance)];
            $lines[] = ['account'=>'2110','debit'=>0,'credit'=>$gross];
            return $lines;
        }
        case 'SUPPLIER_PAYMENT': {
            $pay = (string)($body['payAccount'] ?? ''); accounts_assert_account_class($pay, ['1110','1120']);
            return [['account'=>'2110','debit'=>$amount,'credit'=>0],['account'=>$pay,'debit'=>0,'credit'=>$amount]];
        }
        case 'BANK_TRANSFER': {
            $from = (string)($body['fromAccount'] ?? ''); $to = (string)($body['toAccount'] ?? '');
            accounts_assert_account_class($from, ['1110','1120']); accounts_assert_account_class($to, ['1110','1120']);
            if ($from === $to) accounts_respond(['ok'=>false,'error'=>'Source and destination accounts cannot be the same.'], 422);
            return [['account'=>$to,'debit'=>$amount,'credit'=>0],['account'=>$from,'debit'=>0,'credit'=>$amount]];
        }
        case 'FIXED_ASSET_PURCHASE': {
            $asset = (string)($body['assetAccount'] ?? ''); accounts_assert_account_class($asset, ['1510','1520','1530','1540']);
            $credit = (string)($body['creditAccount'] ?? ''); accounts_assert_account_class($credit, ['1110','1120','2100']);
            return [['account'=>$asset,'debit'=>$amount,'credit'=>0],['account'=>$credit,'debit'=>0,'credit'=>$amount]];
        }
        case 'LOCAL_SALE_RECOGNIZED': {
            $receivable = (string)($body['receivableAccount'] ?? '1220'); accounts_assert_account_class($receivable, ['1220','1230']);
            return [['account'=>$receivable,'debit'=>$amount,'credit'=>0],['account'=>'4200','debit'=>0,'credit'=>$amount]];
        }
        case 'EXPORT_SALE_RECOGNIZED': {
            return [['account'=>'1210','debit'=>$amount,'credit'=>0],['account'=>'4100','debit'=>0,'credit'=>$amount]];
        }
        case 'EXPORT_RECEIPT': {
            $bank = (string)($body['bankAccount'] ?? ''); accounts_assert_account_class($bank, ['1110']);
            return [['account'=>$bank,'debit'=>$amount,'credit'=>0],['account'=>'1210','debit'=>0,'credit'=>$amount]];
        }
        case 'TG_INTERCOMPANY_PAKISTAN': {
            return [['account'=>'1240','debit'=>$amount,'credit'=>0],['account'=>'4500','debit'=>0,'credit'=>$amount]];
        }
        case 'TG_INTERCOMPANY_TG': {
            return [['account'=>'1310','debit'=>$amount,'credit'=>0],['account'=>'2500','debit'=>0,'credit'=>$amount]];
        }
        case 'CREDIT_CARD_PAYMENT': {
            $pay = (string)($body['payAccount'] ?? ''); accounts_assert_account_class($pay, ['1110','1120']);
            $alloc = $body['allocations'] ?? null;
            if (!is_array($alloc) || !$alloc) accounts_respond(['ok'=>false,'error'=>'Credit-card allocations are required.'], 422);
            $lines = []; $sum = 0.0;
            foreach ($alloc as $row) {
                if (!is_array($row)) accounts_respond(['ok'=>false,'error'=>'Invalid credit-card allocation.'], 422);
                $v = accounts_positive_amount($row['amount'] ?? 0, 'Allocation amount'); $sum += $v;
                $kind = strtoupper(trim((string)($row['kind'] ?? 'BUSINESS')));
                if ($kind === 'PERSONAL') $acct = accounts_person_personal_account((string)($row['person'] ?? ''));
                else {
                    $acct = (string)($row['account'] ?? '6900');
                    accounts_assert_account_class($acct, ['6100','6200','6300','6400','6500','6600','6700','6800','6900']);
                }
                $lines[] = ['account'=>$acct,'debit'=>$v,'credit'=>0];
            }
            if (abs(round($sum,2) - $amount) > 0.005) accounts_respond(['ok'=>false,'error'=>'Credit-card allocations must equal the payment amount.'], 422);
            $lines[] = ['account'=>$pay,'debit'=>0,'credit'=>$amount];
            return $lines;
        }
    }
    accounts_respond(['ok'=>false,'error'=>'Unsupported accounting event.'], 422);
}

try {
    $user = tt_require_login();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!tt_user_can_open_module($user, 'Accounts')) accounts_respond(['ok'=>false,'error'=>'Accounts permission required.'], 403);
        $entity = accounts_validate_entity((string)($_GET['entity'] ?? ''));
        accounts_require_entity_access($user, $entity);
        $store = accounts_read(); $master = accounts_master();
        $forEntity = static fn(array $row): bool => strtoupper((string)($row['entity'] ?? '')) === $entity;
        accounts_respond([
            'ok'=>true,'entity'=>$entity,'entities'=>tt_user_accounts_entities($user),'revision'=>(int)$store['revision'],
            'journals'=>array_filter((array)$store['journals'], $forEntity),
            'events'=>array_filter((array)$store['events'], $forEntity),
            'reminders'=>array_filter((array)$store['reminders'], $forEntity),
            'masters'=>$store['masters'],'accountingMaster'=>$master,'serverNow'=>gmdate('c')
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') accounts_respond(['ok'=>false,'error'=>'Method not allowed.'], 405);

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 1024 * 1024) accounts_respond(['ok'=>false,'error'=>'Accounts update is too large.'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) accounts_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'], 419);
    $action = (string)($body['action'] ?? '');
    $eventTypeForPermission = strtoupper(trim((string)($body['eventType'] ?? '')));
    if ($action === 'post_event') {
        if (!accounts_source_event_allowed($user, $eventTypeForPermission)) accounts_respond(['ok'=>false,'error'=>'You do not have permission to create this accounting event.'], 403);
    } elseif (!accounts_can_write($user)) {
        accounts_respond(['ok'=>false,'error'=>'Accounts Create or Edit permission is required.'], 403);
    }

    if ($action === 'post_journal') {
        accounts_respond(['ok'=>false,'error'=>'Direct JV posting has been retired. Use the controlled JV Draft and Approval workflow.'], 410);
    }

    if ($action === 'post_event') {
        $entity = accounts_validate_entity((string)($body['entity'] ?? ''));
        accounts_require_entity_access($user, $entity, 'Create');
        $date = accounts_valid_date((string)($body['date'] ?? ''));
        $eventType = $eventTypeForPermission;
        $sourceKey = trim((string)($body['sourceKey'] ?? ''));
        if ($sourceKey === '' || strlen($sourceKey) > 180) accounts_respond(['ok'=>false,'error'=>'A stable source reference is required to prevent duplicate posting.'], 422);
        $reference = trim((string)($body['reference'] ?? $sourceKey));
        $narration = trim((string)($body['narration'] ?? $eventType));
        $lines = accounts_event_lines($eventType, $body);
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $written = accounts_write_locked(function(array &$store) use ($user,$entity,$date,$eventType,$sourceKey,$reference,$narration,$lines,$meta) {
            $eventId = $entity.'|'.$eventType.'|'.$sourceKey;
            if (isset($store['events'][$eventId])) accounts_respond(['ok'=>false,'error'=>'This source event has already been posted.','existing'=>$store['events'][$eventId]], 409);
            $journal = accounts_post_journal_to_store($store,$user,$entity,$date,$eventType,$reference,$narration,$lines,array_merge($meta,['sourceKey'=>$sourceKey]),'AUTO');
            $store['events'][$eventId] = ['id'=>$eventId,'entity'=>$entity,'eventType'=>$eventType,'sourceKey'=>$sourceKey,'journalId'=>$journal['id'],'postedAt'=>gmdate('c'),'postedBy'=>(string)($user['full_name'] ?? $user['username'] ?? 'Staff')];
            return ['event'=>$store['events'][$eventId],'journal'=>$journal];
        });
        accounts_respond(['ok'=>true,'event'=>$written['result']['event'],'journal'=>$written['result']['journal'],'revision'=>(int)$written['store']['revision']]);
    }

    if ($action === 'reverse_journal') {
        $target = trim((string)($body['journalId'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        if ($target === '' || $reason === '') accounts_respond(['ok'=>false,'error'=>'Journal and reversal reason are required.'], 422);
        $written = accounts_write_locked(function(array &$store) use ($user,$target,$reason) {
            $original = $store['journals'][$target] ?? null;
            if (!is_array($original) || ($original['status'] ?? '') !== 'Posted') accounts_respond(['ok'=>false,'error'=>'Posted journal not found.'], 404);
            accounts_require_entity_access($user, accounts_validate_entity((string)($original['entity'] ?? '')), 'Edit');
            foreach ((array)$store['journals'] as $j) if (($j['reversalOf'] ?? null) === $target) accounts_respond(['ok'=>false,'error'=>'This journal has already been reversed.'], 409);
            $id = accounts_next_id((array)$store['journals'], 'RV');
            $lines = array_map(fn($l)=>['account'=>$l['account'],'accountName'=>$l['accountName'] ?? '', 'debit'=>(float)$l['credit'],'credit'=>(float)$l['debit']], (array)$original['lines']);
            $store['journals'][$id] = [
                'id'=>$id,'entity'=>$original['entity'],'date'=>gmdate('Y-m-d'),'sourceType'=>'REVERSAL','reference'=>$target,
                'narration'=>'Reversal of '.$target.': '.$reason,'lines'=>$lines,'totalDebit'=>$original['totalCredit'],'totalCredit'=>$original['totalDebit'],
                'status'=>'Posted','meta'=>['reason'=>$reason],'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
                'userId'=>(int)($user['id'] ?? 0),'reversalOf'=>$target,
            ];
            return $store['journals'][$id];
        });
        accounts_respond(['ok'=>true,'journal'=>$written['result'],'revision'=>(int)$written['store']['revision']]);
    }

    if ($action === 'save_reminder') {
        $entity = accounts_validate_entity((string)($body['entity'] ?? ''));
        accounts_require_entity_access($user, $entity, 'Create');
        $label = trim((string)($body['label'] ?? ''));
        $dueDate = accounts_valid_date((string)($body['dueDate'] ?? ''));
        if ($label === '') accounts_respond(['ok'=>false,'error'=>'Reminder label is required.'], 422);
        $written = accounts_write_locked(function(array &$store) use ($user,$entity,$label,$dueDate,$body) {
            $id = accounts_next_id((array)$store['reminders'], 'REM');
            $store['reminders'][$id] = [
                'id'=>$id,'entity'=>$entity,'label'=>$label,'dueDate'=>$dueDate,'type'=>trim((string)($body['type'] ?? 'General')),
                'expectedAmount'=>round((float)($body['expectedAmount'] ?? 0),2),'status'=>'Open','ledgerPosted'=>false,
                'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
            ];
            return $store['reminders'][$id];
        });
        accounts_respond(['ok'=>true,'reminder'=>$written['result'],'revision'=>(int)$written['store']['revision']]);
    }

    accounts_respond(['ok'=>false,'error'=>'Unknown Accounts action.'], 422);
} catch (Throwable $e) {
    accounts_respond(['ok'=>false,'error'=>'The Accounts update could not be completed.'], 500);
}
