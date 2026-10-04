<?php
declare(strict_types=1);
require_once __DIR__ . '/product_stage.php';

// Keep live credentials and master records outside public_html. Hostinger Git
// deployments replace the application directory, but must never replace the
// operational data created by Salman and his staff.
const TT_DATA_DIR = __DIR__ . '/../transtrade_private';
const TT_STORE_FILE = TT_DATA_DIR . '/auth.json';
const TT_STORE_LOCK_FILE = TT_DATA_DIR . '/auth.lock';
const TT_SETUP_LOCK_FILE = TT_DATA_DIR . '/setup.lock';
const TT_ADMIN_RECOVERY_HASH_FILE = TT_DATA_DIR . '/admin-recovery.hash';
require_once __DIR__ . '/offline_idempotency.php';
const TT_AUTH_RATE_FILE = TT_DATA_DIR . '/auth-rate.json';
const TT_SESSION_IDLE_TIMEOUT = 3600;
// Public dummy hash equalizes password verification for unknown users. It is
// not an account credential and has no access to the application.
const TT_LOGIN_DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
require_once __DIR__ . '/master_store.php';

function tt_request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    $forwarded=(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'');
    if (strtolower(trim(explode(',',$forwarded)[0]??''))==='https') return true;
    $visitor=json_decode((string)($_SERVER['HTTP_CF_VISITOR']??''),true);
    if (is_array($visitor)&&strtolower((string)($visitor['scheme']??''))==='https') return true;
    if ((int)($_SERVER['SERVER_PORT']??0)===443) return true;
    return strtolower(preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??'')))==='app.transtradeinternational.com';
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('TRANSTRADE_SESSION');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => tt_request_is_https(),
    'httponly' => true, 'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function tt_ensure_data_dir(): void {
    if (!is_dir(TT_DATA_DIR) && !mkdir(TT_DATA_DIR, 0700, true) && !is_dir(TT_DATA_DIR)) throw new RuntimeException('The secure data folder could not be created.');
}

function tt_empty_store(): array {
    return ['users'=>[], 'audit'=>[], 'masters'=>tt_default_masters(), 'master_options'=>tt_default_master_options(), 'master_options_disabled'=>[]];
}

function tt_decode_store(string $raw): array {
    if (trim($raw)==='') throw new RuntimeException('Secure storage is empty. Restore the last valid backup before continuing.');
    try { $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Secure storage is damaged. Restore the last valid backup before continuing.',0,$e); }
    if(!is_array($data)) throw new RuntimeException('Secure storage has an invalid format. Restore the last valid backup before continuing.');
    $data=array_merge(tt_empty_store(),$data);
    $data['masters']=tt_normalize_masters(is_array($data['masters']??null)?$data['masters']:[]);
    return $data;
}

function tt_open_store_lock(int $mode) {
    tt_ensure_data_dir();
    $handle=fopen(TT_STORE_LOCK_FILE,'c+');
    if($handle===false||!flock($handle,$mode)){
        if(is_resource($handle))fclose($handle);
        throw new RuntimeException('Secure storage is unavailable.');
    }
    return $handle;
}

function tt_write_store_atomic(array $data): void {
    $encoded=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $temp=TT_DATA_DIR.'/.auth.'.bin2hex(random_bytes(12)).'.tmp';
    $handle=fopen($temp,'x+b');
    if($handle===false)throw new RuntimeException('Secure storage could not be prepared.');
    try{
        @chmod($temp,0600);
        $length=strlen($encoded);$written=0;
        while($written<$length){$bytes=fwrite($handle,substr($encoded,$written));if($bytes===false||$bytes===0)throw new RuntimeException('Secure storage could not be written.');$written+=$bytes;}
        if(!fflush($handle))throw new RuntimeException('Secure storage could not be flushed.');
        if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Secure storage could not be synchronized.');
        fclose($handle);$handle=null;
        if(!rename($temp,TT_STORE_FILE))throw new RuntimeException('Secure storage could not be committed.');
        @chmod(TT_STORE_FILE,0600);
    }finally{
        if(is_resource($handle))fclose($handle);
        if(is_file($temp))@unlink($temp);
    }
}

function tt_setup_token_configured(): bool {
    $token=(string)(getenv('TT_SETUP_TOKEN')?:'');
    return strlen($token)>=24;
}

function tt_setup_token_valid(string $provided): bool {
    $token=(string)(getenv('TT_SETUP_TOKEN')?:'');
    return strlen($token)>=24&&$provided!==''&&hash_equals($token,$provided);
}

function tt_setup_locked(): bool {
    return is_file(TT_SETUP_LOCK_FILE)||tt_has_admin();
}

function tt_lock_setup(): void {
    tt_ensure_data_dir();
    $handle=@fopen(TT_SETUP_LOCK_FILE,'x+b');
    if($handle===false){if(is_file(TT_SETUP_LOCK_FILE))return;throw new RuntimeException('The setup lock could not be created.');}
    try{if(fwrite($handle,"Setup completed ".gmdate('c')."\n")===false||!fflush($handle))throw new RuntimeException('The setup lock could not be saved.');@chmod(TT_SETUP_LOCK_FILE,0600);}finally{fclose($handle);}
}

function tt_auth_rate_mutate(callable $callback): mixed {
    tt_ensure_data_dir();
    $handle=fopen(TT_AUTH_RATE_FILE,'c+');
    if($handle===false||!flock($handle,LOCK_EX))throw new RuntimeException('Authentication protection is unavailable.');
    try{
        @chmod(TT_AUTH_RATE_FILE,0600);
        rewind($handle);$raw=stream_get_contents($handle);$data=$raw?json_decode($raw,true):null;
        if(!is_array($data))$data=[];
        $result=$callback($data);
        $now=time();
        foreach($data as $key=>&$row){
            if(!is_array($row)){unset($data[$key]);continue;}
            $row['attempts']=array_values(array_filter((array)($row['attempts']??[]),static fn($at):bool=>(int)$at>$now-86400));
            if(!$row['attempts']&&(int)($row['locked_until']??0)<=$now)unset($data[$key]);
        }
        unset($row);
        if(count($data)>2000){
            $latest=static fn(array $row):int=>max((int)($row['locked_until']??0),(int)($row['attempts'][array_key_last((array)($row['attempts']??[]))]??0));
            uasort($data,static fn($a,$b):int=>$latest((array)$b)<=>$latest((array)$a));
            $data=array_slice($data,0,2000,true);
        }
        rewind($handle);if(!ftruncate($handle,0))throw new RuntimeException('Authentication protection could not be updated.');
        if(fwrite($handle,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Authentication protection could not be saved.');
        fflush($handle);return$result;
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}

function tt_auth_rate_key(string $scope,string $identity,bool $bindIp=true): string {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    return hash('sha256',$scope.'|'.strtolower(trim($identity)).'|'.($bindIp?$ip:'all-addresses'));
}

function tt_auth_retry_after(string $scope,string $identity,int $limit=5,int $window=900,bool $bindIp=true): int {
    return tt_auth_rate_mutate(function (&$data) use ($scope,$identity,$limit,$window,$bindIp): int {
        $now=time();$key=tt_auth_rate_key($scope,$identity,$bindIp);$row=is_array($data[$key]??null)?$data[$key]:[];
        $attempts=array_values(array_filter((array)($row['attempts']??[]),static fn($at):bool=>(int)$at>$now-$window));
        $lockedUntil=(int)($row['locked_until']??0);
        if($lockedUntil<=$now&&count($attempts)<$limit){if($attempts)$data[$key]=['attempts'=>$attempts,'locked_until'=>0];else unset($data[$key]);return 0;}
        return max(1,$lockedUntil-$now);
    });
}

function tt_auth_record_failure(string $scope,string $identity,int $limit=5,int $window=900,int $lockSeconds=900,bool $bindIp=true): void {
    tt_auth_rate_mutate(function (&$data) use ($scope,$identity,$limit,$window,$lockSeconds,$bindIp): void {
        $now=time();$key=tt_auth_rate_key($scope,$identity,$bindIp);$row=is_array($data[$key]??null)?$data[$key]:[];
        $attempts=array_values(array_filter((array)($row['attempts']??[]),static fn($at):bool=>(int)$at>$now-$window));
        $attempts[]=$now;$lockedUntil=(int)($row['locked_until']??0);
        if(count($attempts)>=$limit)$lockedUntil=max($lockedUntil,$now+$lockSeconds);
        $data[$key]=['attempts'=>$attempts,'locked_until'=>$lockedUntil];
    });
}

function tt_auth_clear_failures(string $scope,string $identity,bool $bindIp=true): void {
    tt_auth_rate_mutate(function (&$data) use ($scope,$identity,$bindIp): void {unset($data[tt_auth_rate_key($scope,$identity,$bindIp)]);});
}

function tt_read_store(): array {
    tt_ensure_data_dir();
    $lock=tt_open_store_lock(LOCK_SH);
    try {
        if(!is_file(TT_STORE_FILE))return tt_empty_store();
        $handle=fopen(TT_STORE_FILE,'rb');
        if($handle===false)throw new RuntimeException('Secure storage is unavailable.');
        try{$raw=stream_get_contents($handle);}finally{fclose($handle);}
        if($raw===false)throw new RuntimeException('Secure storage could not be read.');
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
    return tt_decode_store($raw);
}

function tt_mutate_store(callable $callback): mixed {
    $backupLib=__DIR__ . '/backup_lib.php';
    if (is_file($backupLib)) { require_once $backupLib; if (function_exists('tt_maybe_auto_backup')) tt_maybe_auto_backup(); }
    tt_ensure_data_dir();
    $lock=tt_open_store_lock(LOCK_EX);
    try {
        if(is_file(TT_STORE_FILE)){
            $handle=fopen(TT_STORE_FILE,'rb');if($handle===false)throw new RuntimeException('Secure storage is unavailable.');
            try{$raw=stream_get_contents($handle);}finally{fclose($handle);}
            if($raw===false)throw new RuntimeException('Secure storage could not be read.');
            $data=tt_decode_store($raw);
        }else{$data=tt_empty_store();}
        $result = $callback($data);
        tt_write_store_atomic($data);
        return $result;
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
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
            'masterAccess' => !empty($user['master_access']),
            'masterPermissions' => is_array($user['master_permissions'] ?? null) ? $user['master_permissions'] : [],
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
            'master_access'=>!empty($input['masterAccess']), 'master_permissions'=>$input['masterPermissions'] ?? [],
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
            if (!empty($user['system_qa']) || (strcasecmp((string)($user['username'] ?? ''),'qa.assistant')===0 && !empty($user['test_data_only']))) {
                // Super Admin may disable/reactivate the read-only test login.
                // Its reserved identity and permissions remain system-enforced.
                $user['active']=$input['active'];
                unset($user);return;
            }
            $user['full_name']=$input['name']; $user['username']=$input['username'];
            $user['role']=$input['role']; $user['location']=$input['location'];
            $user['permissions']=$input['permissions']; $user['active']=$input['active'];
            $user['master_access']=!empty($input['masterAccess']);
            $user['master_permissions']=$input['masterPermissions'] ?? [];
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
            $user['must_change_password']=true;
            $user['session_version']=max(1,(int)($user['session_version']??1))+1;
            unset($user); return;
        }
        unset($user); throw new RuntimeException('User not found.');
    });
    return $temporaryPassword;
}

function tt_change_own_password(int $id, string $newPassword): int {
    return tt_mutate_store(function (&$data) use ($id, $newPassword): int {
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            $user['password_hash']=password_hash($newPassword, PASSWORD_DEFAULT);
            $user['must_change_password']=false;
            $user['session_version']=max(1,(int)($user['session_version']??1))+1;
            $version=(int)$user['session_version']; unset($user); return $version;
        }
        unset($user); throw new RuntimeException('User not found.');
    });
}

function tt_admin_recovery_hash(): string {
    $environment=trim((string)(getenv('TT_ADMIN_RECOVERY_HASH')?:''));
    if ($environment!=='') return $environment;
    if (!is_file(TT_ADMIN_RECOVERY_HASH_FILE)) return '';
    $hash=trim((string)file_get_contents(TT_ADMIN_RECOVERY_HASH_FILE));
    return str_starts_with($hash,'$2y$')||str_starts_with($hash,'$argon2') ? $hash : '';
}

function tt_admin_recovery_configured(): bool { return tt_admin_recovery_hash()!==''; }

function tt_rotate_admin_recovery_code(): string {
    tt_ensure_data_dir();
    $code='TT-'.strtoupper(implode('-',str_split(bin2hex(random_bytes(16)),8)));
    $hash=password_hash((string)preg_replace('/[^A-Z0-9]/','',$code),PASSWORD_BCRYPT,['cost'=>12]);
    $temp=TT_DATA_DIR.'/.recovery.'.bin2hex(random_bytes(12)).'.tmp';
    $handle=fopen($temp,'x+b');
    if($handle===false)throw new RuntimeException('Recovery security could not be prepared.');
    try{
        @chmod($temp,0600);
        if(fwrite($handle,$hash."\n")===false||!fflush($handle))throw new RuntimeException('Recovery security could not be saved.');
        if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Recovery security could not be synchronized.');
        fclose($handle);$handle=null;
        if(!rename($temp,TT_ADMIN_RECOVERY_HASH_FILE))throw new RuntimeException('Recovery security could not be committed.');
        @chmod(TT_ADMIN_RECOVERY_HASH_FILE,0600);
    }finally{
        if(is_resource($handle))fclose($handle);
        if(is_file($temp))@unlink($temp);
    }
    return $code;
}

function tt_recovery_code_valid(string $code): bool {
    $normalized=strtoupper((string)preg_replace('/[^A-Z0-9]/i','',$code));
    $hash=tt_admin_recovery_hash();
    return $hash!==''&&strlen($normalized)>=24&&password_verify($normalized,$hash);
}

function tt_reset_admin_with_recovery(string $newPassword): int {
    return tt_mutate_store(function (&$data) use ($newPassword): int {
        foreach ($data['users'] as &$user) {
            if (($user['role'] ?? '')!=='Super Admin') continue;
            $user['password_hash']=password_hash($newPassword,PASSWORD_DEFAULT);
            $user['must_change_password']=false;
            $user['recovered_at']=gmdate('c');
            $user['session_version']=max(1,(int)($user['session_version']??1))+1;
            $id=(int)$user['id']; unset($user); return $id;
        }
        unset($user); throw new RuntimeException('Super Admin account not found.');
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

function tt_user_can_access_masters(array $user): bool {
    if (($user['role'] ?? '')==='Super Admin') return true;
    foreach (['Mill','Exports','Accounts','Directors'] as $module) if (tt_user_can_open_module($user,$module)) return true;
    return false;
}

/**
 * Module-scoped Master Records retained for compatibility with staff accounts
 * created before the explicit per-master matrix was introduced.
 */
function tt_default_master_scopes(): array {
    return [
        'Accounts'=>['companies','banks','export_realization_charges','export_customers','business_parties','products','purchase_products','mills','product_settings','export_documents','export_terms','salary_staff','reference_lists'],
        'Exports'=>['export_customers','business_parties','products','product_settings','export_documents','export_terms','reference_lists'],
        'Mill'=>['business_parties','purchase_products','mills','reference_lists'],
        'Directors'=>['companies','banks'],
    ];
}

/** Return the exact permissions supplied by the historical module fallback. */
function tt_legacy_master_permissions(array $user): array {
    $all=['Use','View','Create','Edit','Deactivate','View Documents','Download Documents'];
    $matrix=[];
    foreach (tt_default_master_scopes() as $module=>$types) {
        if (!tt_user_can_open_module($user,$module)) continue;
        $actions=$module==='Directors' ? ['View'] : $all;
        foreach ($types as $type) {
            $matrix[$type]=array_values(array_unique(array_merge((array)($matrix[$type] ?? []),$actions)));
        }
    }
    return $matrix;
}

/**
 * Make every pre-matrix staff account explicit before the fallback is
 * hardened. This is intentionally lossless: current effective access becomes
 * the saved matrix, so deployment does not add or remove any staff right.
 */
function tt_migrate_legacy_master_permissions(array &$data): array {
    $changed=[];
    if (!isset($data['users']) || !is_array($data['users'])) $data['users']=[];
    foreach ($data['users'] as &$user) {
        if (($user['role'] ?? '')==='Super Admin' || !empty($user['master_access'])) continue;
        // The managed QA identity is deliberately read-only and its stable
        // profile is restored independently by qa_account.php.
        if (!empty($user['system_qa']) || !empty($user['test_data_only'])) continue;
        $matrix=tt_legacy_master_permissions((array)$user);
        if (!$matrix) continue;
        $user['master_access']=true;
        $user['master_permissions']=$matrix;
        $user['master_permissions_migrated_at']=gmdate('c');
        $user['master_permissions_migration']='legacy-effective-access-v1';
        $changed[]=(string)($user['id'] ?? '');
    }
    unset($user);
    if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings']=[];
    $data['settings']['master_permission_schema']=2;
    $data['settings']['master_permission_schema_at']=$data['settings']['master_permission_schema_at'] ?? gmdate('c');
    return $changed;
}

function tt_user_can_master(array $user,string $type,string $action='View'): bool {
    if (($user['role'] ?? '')==='Super Admin') return true;
    if (!tt_user_can_access_masters($user)) return false;
    if (in_array($type,['purchase_kat','commodities'],true)) $type='purchase_products';
    // An explicit Super Admin matrix overrides the module defaults, including View.
    if (!empty($user['master_access'])) return in_array($action,(array)($user['master_permissions'][$type] ?? []),true);
    // New or incomplete accounts may see and use only their module-related
    // masters. Create/Edit/Deactivate and document access require an explicit
    // Super Admin matrix.
    foreach (tt_default_master_scopes() as $module=>$types) {
        if (tt_user_can_open_module($user,$module) && in_array($type,$types,true)) {
            return in_array($action,['Use','View'],true);
        }
    }
    return false;
}

function tt_user_visible_masters(array $user): array {
    $masters=tt_list_masters();
    if (($user['role'] ?? '')==='Super Admin') return $masters;
    $out=[];
    foreach ($masters as $type=>$rows) if (tt_user_can_master($user,$type,'View')) $out[$type]=$rows;
    return $out;
}

/**
 * Entity access is stored inside the Accounts permission matrix as
 * entity-tti/entity-brm/entity-tg rows when Super Admin has explicitly
 * configured company scope. An Accounts user without entity rows can work in
 * all three group books; a configured matrix restricts the selected books.
 */
function tt_user_can_access_entity(array $user, string $entity, string $action = 'View'): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $entity = strtoupper(trim($entity));
    if (!in_array($entity, ['TTI','BRM','TG'], true)) return false;
    $permissions = $user['permissions']['Accounts'] ?? null;
    if ($permissions === 'all') return true;
    if (!is_array($permissions)) return false;
    $scoped=false;
    foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$permissions)){$scoped=true;break;}
    if(!$scoped)return tt_user_can_open_module($user,'Accounts');
    $row = $permissions['entity-' . strtolower($entity)] ?? [];
    if (!is_array($row)) return false;
    return in_array($action, $row, true)
        || ($action === 'View' && (in_array('Create', $row, true) || in_array('Edit', $row, true) || in_array('Approve', $row, true)));
}

function tt_user_accounts_entities(array $user): array {
    return array_values(array_filter(['TTI','BRM','TG'], static fn(string $entity): bool => tt_user_can_access_entity($user, $entity, 'View')));
}

function tt_user_landing_url(array $user): string {
    // The owner-level Super Admin always starts in the Control Centre.
    // Operational users continue directly to their assigned workspace.
    if (($user['role'] ?? '') === 'Super Admin') return 'index.php';
    if (tt_user_can_open_module($user, 'Accounts')) return 'accounts/index.php';
    if (tt_user_can_open_module($user, 'Directors')) return 'directors/index.php';
    foreach (['Mill'=>'milling','Exports'=>'exports'] as $name=>$id) {
        if (tt_user_can_open_module($user, $name)) return 'module.php?id=' . $id;
    }
    if (tt_user_can_access_masters($user)) return 'index.php?view=masters';
    return 'staff-home.php';
}

function tt_list_masters(): array { return tt_visible_masters(tt_read_store()['masters']); }

function tt_company_fx_rate(string $companyCode, string $from, string $to): ?float {
    $companyCode=strtoupper(trim($companyCode));$from=strtoupper(trim($from));$to=strtoupper(trim($to));
    if($from===$to)return 1.0;
    foreach((array)(tt_read_store()['masters']['companies']??[]) as $company) {
        $values=(array)($company['values']??[]);
        if(strtoupper(trim((string)($values[1]??'')))!==$companyCode)continue;
        $pairs=json_decode((string)($values[15]??'[]'),true);
        foreach(is_array($pairs)?$pairs:[] as $pair) {
            if(!is_array($pair))continue;
            $a=strtoupper((string)($pair['currencyA']??''));$b=strtoupper((string)($pair['currencyB']??''));
            if($a===$from&&$b===$to){$rate=(float)($pair['rateAToB']??0);return $rate>0?$rate:null;}
            if($a===$to&&$b===$from){$rate=(float)($pair['rateBToA']??0);return $rate>0?$rate:null;}
        }
        return null;
    }
    return null;
}

function tt_company_vat_rate(string $companyCode, float $default=5.0): float {
    $companyCode=strtoupper(trim($companyCode));
    foreach((array)(tt_read_store()['masters']['companies']??[]) as $company){
        $values=(array)($company['values']??[]);
        if(strtoupper(trim((string)($values[1]??'')))!==$companyCode)continue;
        $rate=filter_var($values[18]??null,FILTER_VALIDATE_FLOAT);
        return $rate!==false&&$rate>=0&&$rate<=100?(float)$rate:$default;
    }
    return $default;
}

function tt_create_master(string $type, array $values): string {
    return tt_mutate_store(function (&$data) use ($type,$values): string {
        if (!isset($data['masters'][$type]) || !is_array($data['masters'][$type])) $data['masters'][$type]=[];
        $id=$type.'-'.random_int(100000,999999999);
        $data['masters'][$type][]= ['id'=>$id,'values'=>$values];
        return $id;
    });
}

function tt_update_master(string $type, string $id, array $values): void {
    tt_mutate_store(function (&$data) use ($type,$id,$values): void {
        if (!isset($data['masters'][$type]) || !is_array($data['masters'][$type])) throw new RuntimeException('Master type not found.');
        foreach ($data['masters'][$type] as &$row) if (($row['id'] ?? '')===$id) { $row['values']=$values; unset($row); return; }
        unset($row); throw new RuntimeException('Master record not found.');
    });
}

function tt_delete_master(string $type, string $id): void {
    tt_mutate_store(function (&$data) use ($type,$id): void {
        if (!isset($data['masters'][$type]) || !is_array($data['masters'][$type])) throw new RuntimeException('Master type not found.');
        $before=count($data['masters'][$type]);
        $data['masters'][$type]=array_values(array_filter($data['masters'][$type],static fn(array $row): bool => ($row['id'] ?? '')!==$id));
        if ($before===count($data['masters'][$type])) throw new RuntimeException('Master record not found.');
    });
}

function tt_user_session_version(array $user): int { return max(1,(int)($user['session_version']??1)); }

function tt_destroy_session_state(): void {
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,[
            'path'=>$p['path']?:'/', 'domain'=>$p['domain']??'',
            'secure'=>(bool)($p['secure']??false), 'httponly'=>(bool)($p['httponly']??true),
            'samesite'=>$p['samesite']??'Strict',
        ]);
    }
    if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
}

function tt_bind_user_session(array $user): void {
    $csrf=(string)($_SESSION['csrf']??'');
    session_regenerate_id(true);
    $_SESSION=[
        'user_id'=>(int)$user['id'],
        'auth_version'=>tt_user_session_version($user),
        'authenticated_at'=>time(),
        'last_activity_at'=>time(),
    ];
    if ($csrf!=='') $_SESSION['csrf']=$csrf;
}

function tt_request_has_user_activity(): bool {
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if (!str_starts_with($path,'/api/')) return true;
    return hash_equals('1',(string)($_SERVER['HTTP_X_TT_USER_ACTIVITY']??''));
}

function tt_touch_session_activity(): void { $_SESSION['last_activity_at']=time(); }

function tt_current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $now=time();
    $last=(int)($_SESSION['last_activity_at']??0);
    if ($last>0&&$now-$last>=TT_SESSION_IDLE_TIMEOUT) {
        $GLOBALS['TT_SESSION_END_REASON']='inactive';
        tt_destroy_session_state();
        return null;
    }
    if ($last<=0) $_SESSION['last_activity_at']=$now; // Gracefully adopt sessions created before this release.
    $user=tt_find_user_by_id((int)$_SESSION['user_id']);
    if (!$user||empty($user['active'])) {
        $GLOBALS['TT_SESSION_END_REASON']='credentials';
        tt_destroy_session_state();
        return null;
    }
    $boundVersion=max(1,(int)($_SESSION['auth_version']??1));
    if ($boundVersion!==tt_user_session_version($user)) {
        $GLOBALS['TT_SESSION_END_REASON']='credentials';
        tt_destroy_session_state();
        return null;
    }
    if (tt_request_has_user_activity()) tt_touch_session_activity();
    return $user;
}

function tt_api_json_error(int $status,string $message): never {
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode(['ok'=>false,'error'=>$message]);exit;
}

function tt_managed_qa_write_blocked(array $user): bool {
    if(empty($user['system_qa'])&&empty($user['test_data_only']))return false;
    return !in_array(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),['GET','HEAD','OPTIONS'],true);
}

function tt_api_entity_policy(string $path): array {
    $endpoint=basename($path);
    $entityIndependent=['operations.php','operations.mysql.php','export_documents.php','export_customers.php','export_realization_master.php','masters.php','master_documents.php','users.php','backup.php','accounts_bulk_test_cleanup.php','location-master.php','commodity_lookup.php','bag_bill_file.php','bridge_outbox.php'];
    // Assets serves both Accounts and the separately authorised Directors view.
    // Its endpoint enforces module/icon rights and company scope on every request.
    if($endpoint==='assets_registry.php')return['required'=>false,'fixed'=>''];
    if(in_array($endpoint,$entityIndependent,true))return['required'=>false,'fixed'=>''];
    if(str_starts_with($endpoint,'tg_'))return['required'=>true,'fixed'=>'TG'];
    return['required'=>true,'fixed'=>''];
}

/** Only the read-only Post ID register aggregates authorized company books. */
function tt_accounts_post_register_read(string $path,string $method,string $entity,string $account): bool {
    return $path==='/api/accounts_ledger_browser.php'&&strtoupper($method)==='GET'&&$entity==='ALL'&&$account==='POSTS';
}

function tt_require_login(): array {
    $user = tt_current_user();
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if (!$user) {
        $reason=(string)($GLOBALS['TT_SESSION_END_REASON']??'');
        if (str_starts_with($path,'/api/')) {
            tt_api_json_error(401,$reason==='inactive'?'You were signed out after one hour without activity. Sign in again.':'Your session has expired. Sign in again.');
        }
        header('Location: /login.php'.($reason!==''?'?expired='.rawurlencode($reason):''));
        exit;
    }
    if (!str_starts_with($path,'/api/')) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    if(str_starts_with($path,'/api/')&&tt_managed_qa_write_blocked($user)){
        tt_api_json_error(403,'The production QA account is read-only. Use disposable test storage for write testing.');
    }
    tt_offline_request_guard($user);
    if(str_starts_with($path,'/api/')&&$path!=='/api/assets_registry.php'&&tt_user_can_open_module($user,'Accounts')){
        $policy=tt_api_entity_policy($path);
        $entity=strtoupper(trim((string)($_GET['entity']??$_POST['entity']??'')));
        if($entity===''&&strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){
            $raw=file_get_contents('php://input')?:'';
            $body=$raw!==''?json_decode($raw,true):null;
            if(is_array($body))$entity=strtoupper(trim((string)($body['entity']??'')));
        }
        if($entity===''&&$policy['fixed']!=='')$entity=$policy['fixed'];
        if(tt_accounts_post_register_read($path,(string)($_SERVER['REQUEST_METHOD']??'GET'),$entity,(string)($_GET['account']??''))){
            if(!tt_user_accounts_entities($user))tt_api_json_error(403,'You do not have permission for any company books.');
            // accounts_ledger_browser.php filters every journal/post by entity View rights.
            return $user;
        }
        if($entity===''&&!empty($policy['required'])&&($user['role']??'')!=='Super Admin')tt_api_json_error(403,'An authorized legal entity is required.');
        if($entity!==''&&!in_array($entity,['TTI','BRM','TG'],true))tt_api_json_error(403,'Select a valid legal entity.');
        if($entity!==''){
            $read=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='GET';
            $allowed=$read?tt_user_can_access_entity($user,$entity,'View'):(tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')||tt_user_can_access_entity($user,$entity,'Approve'));
            if(!$allowed)tt_api_json_error(403,'You do not have permission for this legal entity.');
        }
    }
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
    $shorten=static fn(string $value,int $length):string=>function_exists('mb_substr')?mb_substr($value,0,$length):substr($value,0,$length);
    $username=trim((string)preg_replace('/[\x00-\x1F\x7F]+/u',' ',$shorten($username,80)));
    $action=trim((string)preg_replace('/[\x00-\x1F\x7F]+/u',' ',$shorten($action,500)));
    if($username==='')$username='unknown';
    tt_mutate_store(function (&$data) use ($userId, $username, $action): void {
        array_unshift($data['audit'], ['user_id'=>$userId, 'username'=>$username, 'action'=>$action, 'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '', 'created_at'=>gmdate('c')]);
        if (count($data['audit']) > 5000) $data['audit'] = array_slice($data['audit'], 0, 5000);
    });
}

/** Uppercase user accounting text without modifying identity keys or workflow enums. */
function tt_accounts_uppercase_text(mixed $value):mixed {
    if(!is_array($value))return $value;
    $keys=['name','reference','referenceNo','bankReference','bankAdviceRef','onlineReference','transactionRef','chequeNo','narration','paymentNarration','description','remarks','reason','note','notes','exceptionNote','reviewNote','terms','invoiceNo','billNo','actualBlNo','forwarderRef','jobNo','customerRef','gdNo','phytoNo'];
    foreach($value as$key=>$item){if(is_string($item)&&in_array((string)$key,$keys,true))$value[$key]=function_exists('mb_strtoupper')?mb_strtoupper($item,'UTF-8'):strtoupper($item);elseif(is_array($item))$value[$key]=tt_accounts_uppercase_text($item);}
    return$value;
}
function tt_accounts_input():string {
    $raw=file_get_contents('php://input')?:'';$body=json_decode($raw,true);
    return is_array($body)?json_encode(tt_accounts_uppercase_text($body),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$raw;
}
