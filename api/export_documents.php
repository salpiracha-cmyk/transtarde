<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';

function export_docs_respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function export_docs_env(string $name): string {
    $constant = 'TT_' . $name;
    if (defined($constant)) return (string)constant($constant);
    $value = getenv($constant);
    return $value === false ? '' : $value;
}

function export_docs_db(): PDO {
    $host = export_docs_env('DB_HOST');
    $name = export_docs_env('DB_NAME');
    $user = export_docs_env('DB_USER');
    $pass = export_docs_env('DB_PASS');
    if ($host === '' || $name === '' || $user === '') throw new RuntimeException('MySQL is not configured.');
    return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function export_docs_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $granted = (array)(($user['permissions'] ?? [])['Exports'] ?? []);
    return in_array('Create', $granted, true) || in_array('Edit', $granted, true);
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Exports')) export_docs_respond(['ok' => false, 'error' => 'Exports access is required.'], 403);
    $db = export_docs_db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = strtolower(trim((string)($_GET['id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) export_docs_respond(['ok' => false, 'error' => 'Invalid document reference.'], 422);
        $query = $db->prepare('SELECT original_name, stored_name, mime_type, file_size FROM tt_export_documents WHERE id = ? AND deleted_at IS NULL');
        $query->execute([$id]);
        $row = $query->fetch();
        if (!$row) export_docs_respond(['ok' => false, 'error' => 'Document not found.'], 404);
        $base = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export_documents';
        $path = $base . DIRECTORY_SEPARATOR . basename((string)$row['stored_name']);
        if (!is_file($path)) export_docs_respond(['ok' => false, 'error' => 'Stored document is unavailable.'], 404);
        header('Content-Type: ' . (string)$row['mime_type']);
        header('Content-Length: ' . (string)filesize($path));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string)$row['original_name']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') export_docs_respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
    if (!export_docs_can_write($user)) export_docs_respond(['ok' => false, 'error' => 'Create or Edit permission is required.'], 403);
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) export_docs_respond(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) export_docs_respond(['ok' => false, 'error' => 'Choose a valid document file.'], 422);
    $size = (int)$_FILES['file']['size'];
    if ($size < 1 || $size > 10 * 1024 * 1024) export_docs_respond(['ok' => false, 'error' => 'Document must be between 1 byte and 10 MB.'], 413);
    $tmp = (string)$_FILES['file']['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowed = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) export_docs_respond(['ok' => false, 'error' => 'Only PDF, PNG, JPEG and WebP documents are accepted.'], 415);
    $category = strtolower(trim((string)($_POST['category'] ?? '')));
    if (!preg_match('/^[a-z0-9-]{3,64}$/', $category)) export_docs_respond(['ok' => false, 'error' => 'Invalid document category.'], 422);
    $contractRef = trim((string)($_POST['contractRef'] ?? ''));
    $lotId = trim((string)($_POST['lotId'] ?? ''));
    if ($contractRef === '' || strlen($contractRef) > 100 || strlen($lotId) > 100) export_docs_respond(['ok' => false, 'error' => 'Invalid contract or lot reference.'], 422);
    $id = bin2hex(random_bytes(16));
    $stored = $id . '.' . $allowed[$mime];
    tt_ensure_data_dir();
    $base = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export_documents';
    if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new RuntimeException('Document storage could not be created.');
    if (!move_uploaded_file($tmp, $base . DIRECTORY_SEPARATOR . $stored)) throw new RuntimeException('Document could not be stored.');
    $name = trim((string)$_FILES['file']['name']);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: ('document.' . $allowed[$mime]);
    $insert = $db->prepare('INSERT INTO tt_export_documents (id, contract_ref, lot_ref, category, original_name, stored_name, mime_type, file_size, uploaded_at, uploaded_by, uploaded_by_user) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?,?)');
    $insert->execute([$id, $contractRef, $lotId, $category, mb_substr($name, 0, 255), $stored, $mime, $size, (string)($user['full_name'] ?? $user['username'] ?? 'Staff'), isset($user['id']) ? (int)$user['id'] : null]);
    export_docs_respond(['ok' => true, 'document' => ['id' => $id, 'name' => $name, 'mime' => $mime, 'size' => $size, 'category' => $category, 'uploadedAt' => gmdate('c'), 'downloadUrl' => 'api/export_documents.php?id=' . $id]]);
} catch (Throwable $e) {
    error_log('Transtrade export document API: ' . $e->getMessage());
    export_docs_respond(['ok' => false, 'error' => 'The protected document operation could not be completed.'], 500);
}
