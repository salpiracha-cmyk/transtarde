<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const ABC_FILE = TT_DATA_DIR . '/accounts.json';
const ABC_PREFIX = 'TEST-DUMMY-ACCOUNTS-BULK-';
const ABC_CONFIRM = 'PURGE_TEST_DUMMY_ACCOUNTS_BULK';

function abc_out(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function abc_contains_marker(mixed $value): bool {
    if (is_scalar($value) || $value === null) return str_contains((string)$value, ABC_PREFIX);
    return str_contains(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', ABC_PREFIX);
}

function abc_plan(array $store): array {
    $journalIds = [];
    foreach ((array)($store['journals'] ?? []) as $id => $journal) {
        if (!is_array($journal)) continue;
        $markedSource = abc_contains_marker($journal['reference'] ?? '')
            || abc_contains_marker($journal['narration'] ?? '')
            || abc_contains_marker($journal['meta']['sourceKey'] ?? '');
        $testDummy = ($journal['meta']['testDummy'] ?? false) === true;
        if ($markedSource && $testDummy) $journalIds[(string)$id] = true;
    }
    // Bulk QA reversals point to an already proven test journal and use the same marker in narration.
    foreach ((array)($store['journals'] ?? []) as $id => $journal) {
        if (!is_array($journal)) continue;
        $original = (string)($journal['reversalOf'] ?? '');
        if ($original !== '' && isset($journalIds[$original]) && abc_contains_marker($journal['narration'] ?? '')) $journalIds[(string)$id] = true;
    }
    $eventIds = [];
    foreach ((array)($store['events'] ?? []) as $id => $event) {
        if (!is_array($event)) continue;
        $journalId = (string)($event['journalId'] ?? '');
        if (isset($journalIds[$journalId]) && abc_contains_marker($event['sourceKey'] ?? '')) $eventIds[(string)$id] = true;
    }
    $identityIds = [];
    foreach ((array)($store['postingIdentities'] ?? []) as $id => $identity) {
        if (!is_array($identity)) continue;
        $journalId = (string)($identity['journalId'] ?? '');
        if (isset($journalIds[$journalId]) && abc_contains_marker($identity['sourceKey'] ?? '')) $identityIds[(string)$id] = true;
    }
    return ['journalIds'=>array_keys($journalIds), 'eventIds'=>array_keys($eventIds), 'identityIds'=>array_keys($identityIds)];
}

function abc_counts(array $plan): array {
    return ['journals'=>count($plan['journalIds']), 'events'=>count($plan['eventIds']), 'postingIdentities'=>count($plan['identityIds'])];
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) abc_out(['ok'=>false, 'error'=>'Accounts permission required.'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') abc_out(['ok'=>false, 'error'=>'Method not allowed.'], 405);
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) abc_out(['ok'=>false, 'error'=>'Session expired.'], 419);
    $action = (string)($body['action'] ?? 'dry_run');
    if (!in_array($action, ['dry_run','execute'], true)) abc_out(['ok'=>false, 'error'=>'Invalid cleanup action.'], 422);
    if ($action === 'execute' && !hash_equals(ABC_CONFIRM, (string)($body['confirm'] ?? ''))) abc_out(['ok'=>false, 'error'=>'Exact cleanup confirmation is required.'], 422);

    tt_ensure_data_dir();
    $handle = fopen(ABC_FILE, 'c+');
    if ($handle === false || !flock($handle, $action === 'execute' ? LOCK_EX : LOCK_SH)) throw new RuntimeException('accounts store lock');
    try {
        rewind($handle); $raw = stream_get_contents($handle); $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = [];
        $plan = abc_plan($store); $counts = abc_counts($plan);
        if ($action === 'dry_run') abc_out(['ok'=>true, 'action'=>'dry_run', 'prefix'=>ABC_PREFIX, 'counts'=>$counts]);

        $backup = ['createdAt'=>gmdate('c'), 'createdBy'=>(string)($user['full_name'] ?? $user['username'] ?? 'Accounts'), 'prefix'=>ABC_PREFIX, 'records'=>['journals'=>[], 'events'=>[], 'postingIdentities'=>[]]];
        foreach ($plan['journalIds'] as $id) if (isset($store['journals'][$id])) $backup['records']['journals'][$id] = $store['journals'][$id];
        foreach ($plan['eventIds'] as $id) if (isset($store['events'][$id])) $backup['records']['events'][$id] = $store['events'][$id];
        foreach ($plan['identityIds'] as $id) if (isset($store['postingIdentities'][$id])) $backup['records']['postingIdentities'][$id] = $store['postingIdentities'][$id];
        $backupName = 'accounts-bulk-test-cleanup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $backupPath = TT_DATA_DIR . '/' . $backupName;
        if (file_put_contents($backupPath, json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('backup write');

        foreach ($plan['journalIds'] as $id) unset($store['journals'][$id]);
        foreach ($plan['eventIds'] as $id) unset($store['events'][$id]);
        foreach ($plan['identityIds'] as $id) unset($store['postingIdentities'][$id]);
        $store['revision'] = (int)($store['revision'] ?? 0) + 1;
        rewind($handle); ftruncate($handle, 0);
        if (fwrite($handle, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('accounts store write');
        fflush($handle);
        $remaining = abc_counts(abc_plan($store));
        abc_out(['ok'=>true, 'action'=>'execute', 'deleted'=>$counts, 'remaining'=>$remaining, 'backupFile'=>$backupName]);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
} catch (Throwable $error) {
    error_log('accounts_bulk_test_cleanup: ' . $error->getMessage());
    abc_out(['ok'=>false, 'error'=>'Accounts bulk-test cleanup could not be completed.'], 500);
}
