<?php
declare(strict_types=1);

// Keep live credentials and master records outside public_html. Hostinger Git
// deployments replace the application directory, but must never replace the
// operational data created by Salman and his staff.
const TT_DATA_DIR = __DIR__ . '/../transtrade_private';
const TT_STORE_FILE = TT_DATA_DIR . '/auth.json';

function tt_default_masters(): array {
    return [
        'companies'=>[['id'=>'companies-1','values'=>['Transtrade International','TTI','Pakistan operations']],['id'=>'companies-2','values'=>['BRM','BRM','Authorized documents']]],
        'parties'=>[['id'=>'parties-1','values'=>['Shams','BRK-001','Broker']],['id'=>'parties-2','values'=>['Sample Overseas Buyer','BUY-001','Export buyer']]],
        'products'=>[['id'=>'products-1','values'=>['IRRI-6 White Rice','IR6-W','Ready rice']],['id'=>'products-2','values'=>['IRRI-6 Parboiled Rice','IR6-P','Ready rice']],['id'=>'products-3','values'=>['B2 Sortex Broken','B2-S','By-product']]],
        'mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mill','TTI-MILL','Own mill']],['id'=>'mills-2','values'=>['Karachi Office','KHI-OFF','Office']]],
        'banks'=>[['id'=>'banks-1','values'=>['Sample Operating Bank ••••• 12345','BANK-01','Accounts / Directors']],['id'=>'banks-2','values'=>['Sample Collection Bank ••••• 48291','BANK-02','Accounts only']]],
        'bags'=>[['id'=>'bags-1','values'=>['Generic 25 KG Export Bag','BAG-25','New export bag']],['id'=>'bags-2','values'=>['Arrival Used Bags','USED-ARR','Used bag source']],['id'=>'bags-3','values'=>['Outside Used Bags','USED-EXT','Separate used bag source']]],
        'ports'=>[['id'=>'ports-1','values'=>['Port Qasim','PKBQM','Port']],['id'=>'ports-2','values'=>['Karachi Port','PKKHI','Port']]],
    ];
}

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
    if (!is_file(TT_STORE_FILE)) return ['users' => [], 'audit' => [], 'masters'=>tt_default_masters()];
    $raw = file_get_contents(TT_STORE_FILE);
    $data = $raw === false || $raw === '' ? null : json_decode($raw, true);
    return is_array($data) ? array_merge(['users' => [], 'audit' => [], 'masters'=>tt_default_masters()], $data) : ['users' => [], 'audit' => [], 'masters'=>tt_default_masters()];
}

function tt_mutate_store(callable $callback): mixed {
    tt_ensure_data_dir();
    $handle = fopen(TT_STORE_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Secure storage is unavailable.');
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = ['users' => [], 'audit' => [], 'masters'=>tt_default_masters()];
        $data = array_merge(['users' => [], 'audit' => [], 'masters'=>tt_default_masters()], $data);
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

function tt_list_public_users(): array {
    return array_map(static function (array $user): array {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        return [
            'id' => (string)$user['id'], 'name' => (string)($user['full_name'] ?? ''),
            'username' => (string)($user['username'] ?? ''), 'role' => (string)($user['role'] ?? ''),
            'location' => (string)($user['location'] ?? 'All authorized locations'),
            'active' => !empty($user['active']), 'modules' => array_keys($permissions),
            'permissions' => $permissions,
            'lastActive' => empty($user['last_login_at']) ? 'Not activated' : (string)$user['last_login_at'],
            'mustChangePassword' => !empty($user['must_change_password']),
        ];
    }, tt_read_store()['users']);
}

function tt_generate_temporary_password(): string {
    return 'Tt' . random_int(10, 99) . '-' . bin2hex(random_bytes(4)) . 'A';
}

function tt_create_staff_user(array $input): array {
    $temporaryPassword = tt_generate_temporary_password();
    $id = tt_mutate_store(function (&$data) use ($input, $temporaryPassword): int {
        foreach ($data['users'] as $user) if (strcasecmp((string)($user['username'] ?? ''), $input['username']) === 0) throw new InvalidArgumentException('That username already exists.');
        $id = random_int(100000, 999999999);
        $data['users'][] = [
            'id'=>$id, 'full_name'=>$input['name'], 'username'=>$input['username'],
            'password_hash'=>password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'role'=>$input['role'], 'location'=>$input['location'], 'permissions'=>$input['permissions'],
            'active'=>$input['active'], 'must_change_password'=>true,
            'created_at'=>gmdate('c'), 'last_login_at'=>null,
        ];
        return $id;
    });
    return ['id'=>$id, 'temporaryPassword'=>$temporaryPassword];
}

function tt_update_staff_user(int $id, array $input): void {
    tt_mutate_store(function (&$data) use ($id, $input): void {
        foreach ($data['users'] as $existing) if ((int)($existing['id'] ?? 0) !== $id && strcasecmp((string)($existing['username'] ?? ''), $input['username']) === 0) throw new InvalidArgumentException('That username already exists.');
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            if (($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('The Super Admin account cannot be changed here.');
            $user['full_name']=$input['name']; $user['username']=$input['username'];
            $user['role']=$input['role']; $user['location']=$input['location'];
            $user['permissions']=$input['permissions']; $user['active']=$input['active'];
            unset($user); return;
        }
        unset($user);
        throw new RuntimeException('User not found.');
    });
}

function tt_delete_staff_user(int $id): void {
    tt_mutate_store(function (&$data) use ($id): void {
        foreach ($data['users'] as $user) if ((int)($user['id'] ?? 0) === $id && ($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('The Super Admin account cannot be deleted.');
        $before=count($data['users']);
        $data['users']=array_values(array_filter($data['users'], static fn(array $user): bool => (int)($user['id'] ?? 0) !== $id));
        if ($before === count($data['users'])) throw new RuntimeException('User not found.');
    });
}

function tt_reset_staff_password(int $id): string {
    $temporaryPassword=tt_generate_temporary_password();
    tt_mutate_store(function (&$data) use ($id, $temporaryPassword): void {
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            if (($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('Use the private password-change screen for Super Admin.');
            $user['password_hash']=password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $user['must_change_password']=true; unset($user); return;
        }
        unset($user); throw new RuntimeException('User not found.');
    });
    return $temporaryPassword;
}

function tt_change_own_password(int $id, string $newPassword): void {
    tt_mutate_store(function (&$data) use ($id, $newPassword): void {
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            $user['password_hash']=password_hash($newPassword, PASSWORD_DEFAULT);
            $user['must_change_password']=false; unset($user); return;
        }
        unset($user); throw new RuntimeException('User not found.');
    });
}

function tt_user_can_open_module(array $user, string $module): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $permissions=$user['permissions'][$module] ?? [];
    if ($permissions === 'all') return true;
    if (!is_array($permissions)) return false;
    if (in_array('View', $permissions, true)) return true; // legacy records
    foreach ($permissions as $actions) if (is_array($actions) && in_array('View', $actions, true)) return true;
    return false;
}

function tt_user_landing_url(array $user): string {
    if (($user['role'] ?? '') === 'Super Admin') return 'index.php';
    foreach (['Mill'=>'milling','Exports'=>'exports'] as $name=>$id) {
        if (tt_user_can_open_module($user, $name)) return 'module.php?id=' . $id;
    }
    return 'staff-home.php';
}

function tt_list_masters(): array { return tt_read_store()['masters']; }

function tt_create_master(string $type, array $values): string {
    return tt_mutate_store(function (&$data) use ($type,$values): string {
        $id=$type.'-'.random_int(100000,999999999);
        $data['masters'][$type][]= ['id'=>$id,'values'=>$values];
        return $id;
    });
}

function tt_update_master(string $type, string $id, array $values): void {
    tt_mutate_store(function (&$data) use ($type,$id,$values): void {
        foreach ($data['masters'][$type] as &$row) if (($row['id'] ?? '')===$id) { $row['values']=$values; unset($row); return; }
        unset($row); throw new RuntimeException('Master record not found.');
    });
}

function tt_delete_master(string $type, string $id): void {
    tt_mutate_store(function (&$data) use ($type,$id): void {
        $before=count($data['masters'][$type]);
        $data['masters'][$type]=array_values(array_filter($data['masters'][$type],static fn(array $row): bool => ($row['id'] ?? '')!==$id));
        if ($before===count($data['masters'][$type])) throw new RuntimeException('Master record not found.');
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
