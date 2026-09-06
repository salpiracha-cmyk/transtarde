<?php
declare(strict_types=1);

const TT_DATA_DIR = __DIR__ . '/data';
const TT_STORE_FILE = TT_DATA_DIR . '/auth.json';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('TRANSTRADE_SESSION');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true, 'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function tt_ensure_data_dir(): void {
    if (!is_dir(TT_DATA_DIR) && !mkdir(TT_DATA_DIR, 0700, true) && !is_dir(TT_DATA_DIR)) throw new RuntimeException('The secure data folder could not be created.');
}

function tt_read_store(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_STORE_FILE)) return ['users' => [], 'audit' => []];
    $raw = file_get_contents(TT_STORE_FILE);
    $data = $raw === false || $raw === '' ? null : json_decode($raw, true);
    return is_array($data) ? array_merge(['users' => [], 'audit' => []], $data) : ['users' => [], 'audit' => []];
}

function tt_mutate_store(callable $callback): mixed {
    tt_ensure_data_dir();
    $handle = fopen(TT_STORE_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Secure storage is unavailable.');
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = ['users' => [], 'audit' => []];
        $data = array_merge(['users' => [], 'audit' => []], $data);
        $result = $callback($data);
        rewind($handle);
        if (!ftruncate($handle, 0)) throw new RuntimeException('Secure storage could not be updated.');
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $encoded) === false) throw new RuntimeException('Secure storage could not be written.');
        fflush($handle);
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function tt_has_admin(): bool {
    foreach (tt_read_store()['users'] as $user) if (($user['role'] ?? '') === 'Super Admin') return true;
    return false;
}

function tt_find_user_by_id(int $id): ?array {
    foreach (tt_read_store()['users'] as $user) if ((int)($user['id'] ?? 0) === $id) return $user;
    return null;
}

function tt_find_user_by_username(string $username): ?array {
    foreach (tt_read_store()['users'] as $user) if (strcasecmp((string)($user['username'] ?? ''), $username) === 0) return $user;
    return null;
}

function tt_create_admin(string $username, string $password): int {
    return tt_mutate_store(function (&$data) use ($username, $password): int {
        foreach ($data['users'] as $user) if (($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('Super Admin already exists.');
        $id = random_int(100000, 999999999);
        $data['users'][] = [
            'id' => $id, 'full_name' => 'Salman', 'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'Super Admin',
            'permissions' => ['Mill'=>'all','Exports'=>'all','Accounts'=>'all','Directors'=>'all'],
            'active' => true, 'must_change_password' => false,
            'created_at' => gmdate('c'), 'last_login_at' => null,
        ];
        return $id;
    });
}

function tt_set_last_login(int $id): void {
    tt_mutate_store(function (&$data) use ($id): void {
        foreach ($data['users'] as &$user) if ((int)($user['id'] ?? 0) === $id) { $user['last_login_at'] = gmdate('c'); break; }
        unset($user);
    });
}

function tt_current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $user = tt_find_user_by_id((int)$_SESSION['user_id']);
    return ($user && !empty($user['active'])) ? $user : null;
}

function tt_require_login(): array {
    $user = tt_current_user();
    if (!$user) { $_SESSION = []; header('Location: login.php'); exit; }
    return $user;
}

function tt_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function tt_verify_csrf(string $token): bool {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function tt_audit(?int $userId, string $username, string $action): void {
    tt_mutate_store(function (&$data) use ($userId, $username, $action): void {
        array_unshift($data['audit'], ['user_id'=>$userId, 'username'=>$username, 'action'=>$action, 'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '', 'created_at'=>gmdate('c')]);
        if (count($data['audit']) > 5000) $data['audit'] = array_slice($data['audit'], 0, 5000);
    });
}
