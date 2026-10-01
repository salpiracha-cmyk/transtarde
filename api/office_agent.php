<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/office_backup_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function office_agent_respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function office_agent_error(int $status, string $message): never {
    office_agent_respond(['ok' => false, 'error' => $message], $status);
}

function office_agent_root(): string {
    tt_ensure_data_dir();
    $root = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'office_agent_jobs';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('Office Agent job storage is unavailable.');
    return $root;
}

function office_agent_job_dir(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) office_agent_error(422, 'Invalid Office Agent job id.');
    return office_agent_root() . DIRECTORY_SEPARATOR . $id;
}

function office_agent_manifest_path(string $id): string { return office_agent_job_dir($id) . DIRECTORY_SEPARATOR . 'job.json'; }
function office_agent_file_dir(string $id): string { return office_agent_job_dir($id) . DIRECTORY_SEPARATOR . 'files'; }

function office_agent_safe_component(string $value): string {
    $value = trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1f]+/', '-', $value) ?? '');
    $value = rtrim($value, ". \t\n\r\0\x0B");
    if ($value === '' || preg_match('/^\.+$/', $value)) throw new InvalidArgumentException('Archive path contains an invalid name.');
    if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $value)) $value = '_' . $value;
    return substr($value, 0, 120);
}

function office_agent_can_export_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $g = $user['permissions']['Exports'] ?? [];
    if ($g === 'all') return true;
    if (!is_array($g)) return false;
    if (in_array('Create', $g, true) || in_array('Edit', $g, true)) return true;
    foreach (['bags','contracts','contract','customers'] as $k) {
        $a = $g[$k] ?? [];
        if ($a === 'all' || (is_array($a) && (in_array('Create', $a, true) || in_array('Edit', $a, true)))) return true;
    }
    return false;
}

function office_agent_require_token(): void {
    if (!tt_request_is_https()) office_agent_error(403, 'HTTPS is required.');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $retry = tt_auth_retry_after('office-agent', $ip, 5, 900, false);
    if ($retry > 0) { header('Retry-After: ' . $retry); office_agent_error(429, 'Office Agent credential temporarily locked.'); }
    $token = (string)($_SERVER['HTTP_X_TT_BACKUP_TOKEN'] ?? '');
    if (!tt_office_backup_token_valid($token)) {
        tt_auth_record_failure('office-agent', $ip, 5, 900, 900, false);
        office_agent_error(403, 'Invalid Office Agent credential.');
    }
    tt_auth_clear_failures('office-agent', $ip, false);
}

function office_agent_read_job(string $id): array {
    $path = office_agent_manifest_path($id);
    if (!is_file($path)) office_agent_error(404, 'Office Agent job not found.');
    $raw = file_get_contents($path);
    $job = $raw ? json_decode($raw, true) : null;
    if (!is_array($job)) office_agent_error(500, 'Office Agent job metadata is damaged.');
    return $job;
}

function office_agent_write_job(array $job): void {
    $id = (string)($job['id'] ?? '');
    $dir = office_agent_job_dir($id);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Office Agent job folder is unavailable.');
    $tmp = $dir . DIRECTORY_SEPARATOR . '.job-' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Office Agent job could not be saved.');
    @chmod($tmp, 0600);
    if (!rename($tmp, office_agent_manifest_path($id))) { @unlink($tmp); throw new RuntimeException('Office Agent job could not be committed.'); }
}

function office_agent_enqueue_shipment(): never {
    $user = tt_current_user();
    if (!$user) office_agent_error(401, 'Your login session expired. Please sign in again.');
    if (tt_managed_qa_write_blocked($user)) office_agent_error(403, 'The production QA account is read-only.');
    if (!tt_user_can_open_module($user, 'Exports') || !office_agent_can_export_write($user)) office_agent_error(403, 'Exports Create or Edit permission is required.');
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) office_agent_error(419, 'Your session expired. Refresh and try again.');

    $manifest = json_decode((string)($_POST['manifest'] ?? ''), true);
    if (!is_array($manifest) || ($manifest['type'] ?? '') !== 'shipment_archive') office_agent_error(422, 'Invalid shipment archive package.');
    $files = $_FILES['files'] ?? null;
    if (!is_array($files) || !is_array($files['name'] ?? null)) office_agent_error(422, 'Shipment archive package has no files.');
    $count = count($files['name']);
    if ($count < 1 || $count > 80 || $count !== count((array)($manifest['files'] ?? []))) office_agent_error(422, 'Shipment archive file manifest is incomplete.');

    $id = bin2hex(random_bytes(16));
    $dir = office_agent_job_dir($id);
    $fileDir = office_agent_file_dir($id);
    if (!mkdir($fileDir, 0700, true)) office_agent_error(500, 'Office Agent job storage is unavailable.');

    try {
        $jobFiles = [];
        foreach ((array)$manifest['folderParts'] as $part) office_agent_safe_component((string)$part);
        foreach ((array)$manifest['files'] as $index => $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Shipment archive file metadata is invalid.');
            if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('A shipment archive file did not upload completely.');
            $stored = office_agent_safe_component((string)($row['storedName'] ?? ('file-' . $index)));
            $name = office_agent_safe_component((string)($row['name'] ?? 'Document'));
            $folder = trim((string)($row['folder'] ?? ''));
            if ($folder !== '') $folder = office_agent_safe_component($folder);
            $tmp = (string)$files['tmp_name'][$index];
            $size = (int)($files['size'][$index] ?? 0);
            if ($size <= 0 || $size > 75 * 1024 * 1024 || !is_uploaded_file($tmp)) throw new InvalidArgumentException('A shipment archive file is invalid or too large.');
            $target = $fileDir . DIRECTORY_SEPARATOR . $stored;
            if (!move_uploaded_file($tmp, $target)) throw new RuntimeException('A shipment archive file could not be stored.');
            @chmod($target, 0600);
            $sha = hash_file('sha256', $target);
            if ($sha === false) throw new RuntimeException('A shipment archive file could not be verified.');
            $jobFiles[] = ['folder' => $folder, 'name' => $name, 'storedName' => $stored, 'size' => filesize($target) ?: $size, 'sha256' => $sha];
        }
        $now = gmdate('c');
        $job = [
            'id' => $id,
            'type' => 'shipment_archive',
            'status' => 'PENDING',
            'createdAt' => $now,
            'updatedAt' => $now,
            'createdBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
            'customer' => (string)($manifest['customer'] ?? ''),
            'contract' => (string)($manifest['contract'] ?? ''),
            'lot' => (string)($manifest['lot'] ?? ''),
            'folderParts' => array_values(array_map(static fn($x): string => office_agent_safe_component((string)$x), (array)$manifest['folderParts'])),
            'files' => $jobFiles,
            'attempts' => 0,
        ];
        office_agent_write_job($job);
        office_agent_respond(['ok' => true, 'job' => ['id' => $id, 'status' => 'PENDING']]);
    } catch (Throwable $error) {
        foreach (glob($fileDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) @unlink($path);
        @rmdir($fileDir); @rmdir($dir);
        office_agent_error($error instanceof InvalidArgumentException ? 422 : 500, $error->getMessage());
    }
}

function office_agent_poll(): never {
    office_agent_require_token();
    $jobs = [];
    foreach (glob(office_agent_root() . DIRECTORY_SEPARATOR . '*/job.json') ?: [] as $path) {
        $job = json_decode((string)file_get_contents($path), true);
        if (!is_array($job) || !in_array((string)($job['status'] ?? ''), ['PENDING','ERROR'], true)) continue;
        $jobs[] = [
            'id' => (string)$job['id'],
            'type' => (string)$job['type'],
            'status' => (string)$job['status'],
            'createdAt' => $job['createdAt'] ?? null,
            'updatedAt' => $job['updatedAt'] ?? null,
            'customer' => $job['customer'] ?? '',
            'contract' => $job['contract'] ?? '',
            'lot' => $job['lot'] ?? '',
            'folderParts' => $job['folderParts'] ?? [],
            'files' => $job['files'] ?? [],
            'attempts' => (int)($job['attempts'] ?? 0),
            'lastError' => $job['lastError'] ?? null,
        ];
    }
    usort($jobs, static fn(array $a, array $b): int => strcmp((string)$a['createdAt'], (string)$b['createdAt']));
    office_agent_respond(['ok' => true, 'jobs' => $jobs]);
}

function office_agent_file(): never {
    office_agent_require_token();
    $id = strtolower(trim((string)($_GET['id'] ?? '')));
    $name = (string)($_GET['file'] ?? '');
    $job = office_agent_read_job($id);
    $match = null;
    foreach ((array)($job['files'] ?? []) as $file) if (($file['storedName'] ?? '') === $name) { $match = $file; break; }
    if (!$match) office_agent_error(404, 'Office Agent job file not found.');
    $path = office_agent_file_dir($id) . DIRECTORY_SEPARATOR . basename($name);
    if (!is_file($path)) office_agent_error(404, 'Office Agent job file is unavailable.');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('X-File-SHA256: ' . (string)$match['sha256']);
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string)$match['name']));
    readfile($path);
    exit;
}

function office_agent_ack(): never {
    office_agent_require_token();
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) office_agent_error(400, 'Invalid Office Agent acknowledgement.');
    $id = strtolower(trim((string)($body['id'] ?? '')));
    $status = strtoupper(trim((string)($body['status'] ?? '')));
    if (!in_array($status, ['COMPLETE','ERROR'], true)) office_agent_error(422, 'Invalid Office Agent job status.');
    $job = office_agent_read_job($id);
    $job['status'] = $status === 'COMPLETE' ? 'VERIFIED' : 'ERROR';
    $job['updatedAt'] = gmdate('c');
    $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
    $job['agent'] = ['host' => substr((string)($body['host'] ?? ''), 0, 120), 'reportedAt' => gmdate('c')];
    $job['lastError'] = $status === 'ERROR' ? substr((string)($body['error'] ?? 'Office Agent could not complete the job.'), 0, 500) : null;
    $job['verifiedFiles'] = is_array($body['files'] ?? null) ? $body['files'] : [];
    office_agent_write_job($job);
    office_agent_respond(['ok' => true, 'job' => ['id' => $id, 'status' => $job['status']]]);
}

try {
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enqueue-shipment') office_agent_enqueue_shipment();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'poll') office_agent_poll();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'file') office_agent_file();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'ack') office_agent_ack();
    office_agent_error(404, 'Unknown Office Agent action.');
} catch (Throwable $error) {
    error_log('Transtrade Office Agent API: ' . $error->getMessage());
    office_agent_error(500, 'Office Agent service is temporarily unavailable.');
}
