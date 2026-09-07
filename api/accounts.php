<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';

function accounts_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function accounts_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $module = $user['permissions']['Accounts'] ?? null;
    if ($module === 'all') return true;
    foreach ((array)$module as $actions) {
        if (is_array($actions) && (in_array('Create', $actions, true) || in_array('Edit', $actions, true))) return true;
    }
    return false;
}

function accounts_store_default(): array {
    return [
        'revision' => 0,
        'journals' => [],
        'reminders' => [],
        'masters' => [
            'commodities' => [
                ['code'=>'RICE','name'=>'Rice','active'=>true,'sodaEnabled'=>true],
                ['code'=>'CORN','name'=>'Corn','active'=>true,'sodaEnabled'=>true],
                ['code'=>'SESAME','name'=>'Sesame','active'=>true,'sodaEnabled'=>true],
            ],
            'rules' => [],
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

function accounts_normalize_lines(mixed $raw): array {
    if (!is_array($raw) || count($raw) < 2 || count($raw) > 100) accounts_respond(['ok'=>false,'error'=>'A journal requires at least two valid lines.'], 422);
    $lines = []; $dr = 0.0; $cr = 0.0;
    foreach ($raw as $i => $line) {
        if (!is_array($line)) accounts_respond(['ok'=>false,'error'=>'Invalid journal line.'], 422);
        $account = trim((string)($line['account'] ?? ''));
        $debit = round((float)($line['debit'] ?? 0), 2);
        $credit = round((float)($line['credit'] ?? 0), 2);
        if ($account === '' || $debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0) || ($debit <= 0 && $credit <= 0)) {
            accounts_respond(['ok'=>false,'error'=>'Every journal line needs one account and either a debit or a credit.'], 422);
        }
        $lines[] = ['account'=>$account,'debit'=>$debit,'credit'=>$credit];
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

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) accounts_respond(['ok'=>false,'error'=>'Accounts permission required.'], 403);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $store = accounts_read();
        accounts_respond(['ok'=>true,'revision'=>(int)$store['revision'],'journals'=>$store['journals'],'reminders'=>$store['reminders'],'masters'=>$store['masters'],'serverNow'=>gmdate('c')]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') accounts_respond(['ok'=>false,'error'=>'Method not allowed.'], 405);
    if (!accounts_can_write($user)) accounts_respond(['ok'=>false,'error'=>'Create or Edit permission is required.'], 403);

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 1024 * 1024) accounts_respond(['ok'=>false,'error'=>'Accounts update is too large.'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) accounts_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'], 419);
    $action = (string)($body['action'] ?? '');

    if ($action === 'post_journal') {
        $entity = accounts_validate_entity((string)($body['entity'] ?? ''));
        [$lines,$dr,$cr] = accounts_normalize_lines($body['lines'] ?? null);
        $date = (string)($body['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) accounts_respond(['ok'=>false,'error'=>'Valid journal date required.'], 422);
        $narration = trim((string)($body['narration'] ?? ''));
        if ($narration === '') accounts_respond(['ok'=>false,'error'=>'Narration is required.'], 422);
        $sourceType = trim((string)($body['sourceType'] ?? 'JV')) ?: 'JV';
        $reference = trim((string)($body['reference'] ?? ''));
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $written = accounts_write_locked(function(array &$store) use ($user,$entity,$lines,$dr,$cr,$date,$narration,$sourceType,$reference,$meta) {
            $id = accounts_next_id((array)$store['journals'], 'JV');
            $store['journals'][$id] = [
                'id'=>$id,'entity'=>$entity,'date'=>$date,'sourceType'=>$sourceType,'reference'=>$reference,
                'narration'=>$narration,'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted',
                'meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
                'userId'=>(int)($user['id'] ?? 0),'reversalOf'=>null,
            ];
            return $store['journals'][$id];
        });
        accounts_respond(['ok'=>true,'journal'=>$written['result'],'revision'=>(int)$written['store']['revision']]);
    }

    if ($action === 'reverse_journal') {
        $target = trim((string)($body['journalId'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        if ($target === '' || $reason === '') accounts_respond(['ok'=>false,'error'=>'Journal and reversal reason are required.'], 422);
        $written = accounts_write_locked(function(array &$store) use ($user,$target,$reason) {
            $original = $store['journals'][$target] ?? null;
            if (!is_array($original) || ($original['status'] ?? '') !== 'Posted') accounts_respond(['ok'=>false,'error'=>'Posted journal not found.'], 404);
            foreach ((array)$store['journals'] as $j) if (($j['reversalOf'] ?? null) === $target) accounts_respond(['ok'=>false,'error'=>'This journal has already been reversed.'], 409);
            $id = accounts_next_id((array)$store['journals'], 'RV');
            $lines = array_map(fn($l)=>['account'=>$l['account'],'debit'=>(float)$l['credit'],'credit'=>(float)$l['debit']], (array)$original['lines']);
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
        $label = trim((string)($body['label'] ?? ''));
        $dueDate = (string)($body['dueDate'] ?? '');
        if ($label === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) accounts_respond(['ok'=>false,'error'=>'Reminder label and due date are required.'], 422);
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
