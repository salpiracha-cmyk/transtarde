<?php
declare(strict_types=1);
// Durations only: no identities, record values, paths or credentials.
$GLOBALS['ttRequestTiming']=['start'=>hrtime(true),'session'=>0.0,'store_wait'=>0.0,'store_read'=>0.0,'store_decode'=>0.0,'store_mutate'=>0.0,'store_write_wait'=>0.0,'backup_auto'=>0.0,'password_verify'=>0.0,'store_reads'=>0];
header_register_callback(static function(): void {
    $t=$GLOBALS['ttRequestTiming'];
    $parts=['app;dur='.number_format((hrtime(true)-$t['start'])/1e6,2,'.','')];
    foreach(['session','store_wait','store_read','store_decode','store_mutate','store_write_wait','backup_auto','password_verify'] as $key)$parts[]=$key.';dur='.number_format($t[$key],2,'.','');
    $parts[]='store_reads;desc="'.(int)$t['store_reads'].'"';
    header('Server-Timing: '.implode(', ',$parts),false);
});
require_once __DIR__ . '/product_stage.php';

// Keep live credentials and master records outside public_html. Hostinger Git
// deployments replace the application directory, but must never replace the
// operational data created by Salman and his staff.
const TT_DATA_DIR = __DIR__ . '/../transtrade_private';
const TT_STORE_FILE = TT_DATA_DIR . '/auth.json';
const TT_STORE_LOCK_FILE = TT_DATA_DIR . '/auth.lock';
const TT_SETUP_LOCK_FILE = TT_DATA_DIR . '/setup.lock';
const TT_ADMIN_RECOVERY_HASH_FILE = TT_DATA_DIR . '/admin-recovery.hash';
// Capture only fatal error classifications while investigating intermittent
// hosting failures. Never persist exception messages, arguments or session IDs.
register_shutdown_function(static function(): void {
    $error=error_get_last();
    if(!$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
    $message=(string)($error['message']??'');$category='fatal';$detail='';
    if(preg_match('/Call to undefined function ([A-Za-z0-9_]+)/',$message,$match)){$category='undefined-function';$detail=$match[1];}
    elseif(str_contains($message,'Allowed memory size'))$category='memory-limit';
    elseif(str_contains($message,'Maximum execution time'))$category='execution-limit';
    elseif(preg_match('/Uncaught ([A-Za-z0-9_\\\\]+)/',$message,$match)){$category='uncaught';$detail=$match[1];}
    foreach(['Private session storage is unavailable.','Session migration is unavailable.','Session migration could not be saved.','Session migration could not be committed.','Session migration could not be recorded.','Private session storage could not be selected.','Your session could not be opened.']as$known)if(str_contains($message,$known)){$category='session-startup';$detail=$known;break;}
    $file=basename((string)($error['file']??''));if(!preg_match('/^[A-Za-z0-9_.-]+\.php$/D',$file))$file='hidden';
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);if(!preg_match('~^/[A-Za-z0-9_./-]*$~D',$path))$path='hidden';
    $entry=['time'=>gmdate('c'),'path'=>$path,'category'=>$category,'detail'=>$detail,'file'=>$file,'line'=>(int)($error['line']??0),'php'=>PHP_VERSION];
    $entry['sessionWarnings']=array_values(array_unique(array_intersect((array)($GLOBALS['ttSessionStartupWarnings']??[]),['other','decode','permission','disk-full','open-file-limit','headers-sent','read','write','storage-init','open'])));
    $entry['sessionSerializer']=ini_get('session.serialize_handler');
    $entry['sessionHandler']=ini_get('session.save_handler');
    $entry['sessionInput']=$GLOBALS['ttSessionInputMetadata']??null;
    $log=TT_DATA_DIR.'/sessions/runtime-errors.json';$handle=@fopen($log,'c+');if($handle===false)return;
    if(!@flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);return;}
    try{$rows=json_decode(stream_get_contents($handle)?:'[]',true);$rows=is_array($rows)?$rows:[];$rows[]=$entry;$json=json_encode(array_slice($rows,-100),JSON_UNESCAPED_SLASHES);if($json!==false){rewind($handle);ftruncate($handle,0);fwrite($handle,$json);fflush($handle);@chmod($log,0600);}}
    finally{flock($handle,LOCK_UN);fclose($handle);}
});
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

/** Keep file sessions in one private application-owned location across routes/workers. */
function tt_configure_session_storage(): void {
    if(session_status()===PHP_SESSION_ACTIVE||ini_get('session.save_handler')!=='files')return;
    $oldSetting=session_save_path();
    $parts=explode(';',$oldSetting);
    $oldRoot=(string)end($parts);
    if($oldRoot==='')$oldRoot=sys_get_temp_dir();
    $depth=count($parts)>1&&ctype_digit($parts[0])?(int)$parts[0]:0;
    tt_ensure_data_dir();
    $root=TT_DATA_DIR.'/sessions';
    if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Private session storage is unavailable.');
    if(is_link($root)||!is_writable($root))throw new RuntimeException('Private session storage is unavailable.');
    @chmod($root,0700);
    $id=session_id()?:((string)($_COOKIE[session_name()]??''));
    // Carry an existing live file session across the storage move. No IDs or
    // authentication data are written to logs, responses or business backups.
    if(!TTAtomicSessionStore::isNativeId($id)&&preg_match('/^[A-Za-z0-9,-]{1,256}$/D',$id)&&$depth<=strlen($id)){
        $old=rtrim($oldRoot,'/');
        for($i=0;$i<$depth;$i++)$old.='/'.$id[$i];
        $old.='/sess_'.$id;$target=$root.'/sess_'.$id;$migrated=$root.'/.migrated-'.hash('sha256',$id);
        if($old!==$target&&!is_file($target)&&!is_file($migrated)&&is_file($old)&&!is_link($old)){
            $lock=fopen($root.'/.migration.lock','c+');
            if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Session migration is unavailable.');
            try{
                if(!is_file($target)&&!is_file($migrated)){
                    $source=fopen($old,'rb');
                    if($source!==false){
                        try{
                            if(!flock($source,LOCK_SH))throw new RuntimeException('Session migration is unavailable.');
                            $temp=$root.'/.migration-'.bin2hex(random_bytes(12));
                            $destination=fopen($temp,'x+b');
                            if($destination===false)throw new RuntimeException('Session migration is unavailable.');
                            try{
                                @chmod($temp,0600);
                                if(stream_copy_to_stream($source,$destination)===false||!fflush($destination))throw new RuntimeException('Session migration could not be saved.');
                            }finally{fclose($destination);}
                            if(!rename($temp,$target))throw new RuntimeException('Session migration could not be committed.');
                            if(file_put_contents($migrated,'migrated',LOCK_EX)===false)throw new RuntimeException('Session migration could not be recorded.');
                            @chmod($migrated,0600);
                            @unlink($old); // Never resurrect a logged-out session from its legacy copy.
                        }finally{flock($source,LOCK_UN);fclose($source);if(isset($temp)&&is_file($temp))unlink($temp);}
                    }
                }
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }
    }
    if(session_save_path($root)===false)throw new RuntimeException('Private session storage could not be selected.');
    ini_set('session.gc_maxlifetime',(string)TT_SESSION_IDLE_TIMEOUT);
    ini_set('session.gc_probability','1');
    ini_set('session.gc_divisor','1000');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// Legacy file migration below runs before installing the atomic record handler.
session_name('TRANSTRADE_SESSION');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => tt_request_is_https(),
    'httponly' => true, 'samesite' => 'Strict',
]);
$ttSessionTimingStart=hrtime(true);
require_once __DIR__ . '/session_store.php';
tt_configure_session_storage();
$ttSessionStore=new TTAtomicSessionStore(session_save_path());
if(session_status()!==PHP_SESSION_ACTIVE){
    ini_set('session.serialize_handler','php_serialize');
    if(!session_set_save_handler($ttSessionStore,true))throw new RuntimeException('Private session storage could not be selected.');
}
$ttIncomingSession=(string)($_COOKIE[session_name()]??'');
$ttIncomingFile=$ttIncomingSession!==''&&$ttSessionStore->validateId($ttIncomingSession);
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Keep diagnostic data to fixed categories; never retain warning text or IDs.
    set_error_handler(static function(int $severity,string $message): bool {
        if(str_contains($message,'session_start')){
            $cause='other';
            foreach(['Failed to decode'=>'decode','Permission denied'=>'permission','No space left'=>'disk-full','Too many open files'=>'open-file-limit','headers already sent'=>'headers-sent','Failed to read'=>'read','Failed to write'=>'write','Failed to initialize'=>'storage-init','open('=>'open'] as $match=>$kind)
                if(str_contains($message,$match)){$cause=$kind;break;}
            $GLOBALS['ttSessionStartupWarnings'][]=$cause;
        }
        return false;
    },E_WARNING);
    try{$ttSessionOpened=session_start();}finally{restore_error_handler();}
    if(!$ttSessionOpened)throw new RuntimeException('Your session could not be opened.');
    unset($ttSessionOpened);
}
$GLOBALS['ttRequestTiming']['session']=(hrtime(true)-$ttSessionTimingStart)/1e6;
unset($ttSessionTimingStart);
// Session-dependent responses, including anonymous redirects, must bypass shared caches.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');
// Diagnostic enums never contain cookies, session IDs, user values or filesystem paths.
header('X-TT-Auth-Revision: 20261006-atomic-session-1');
header('X-TT-Session-Handler: '.ini_get('session.save_handler'));
header('X-TT-Session-Serializer: '.ini_get('session.serialize_handler'));
header('X-TT-Session-Node: '.substr(hash('sha256',(string)gethostname()),0,12));
header('X-TT-Session-File: '.($ttIncomingSession===''?'no-cookie':($ttIncomingFile?'resident':'missing')));
header('X-TT-Session-Transition: '.($ttIncomingSession===''?'new':(hash_equals($ttIncomingSession,session_id())?(empty($_SESSION['user_id'])?'resumed-empty':'resumed-state'):'replaced')));
unset($ttIncomingSession,$ttIncomingFile);

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
        $GLOBALS['ttAuthStoreGeneration']=($GLOBALS['ttAuthStoreGeneration']??0)+1;
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
    static $cachedFingerprint=null, $cachedData=null;
    tt_ensure_data_dir();
    $started=hrtime(true);
    $lock=tt_open_store_lock(LOCK_SH);
    $GLOBALS['ttRequestTiming']['store_wait']+=(hrtime(true)-$started)/1e6;
    $started=hrtime(true);
    try {
        clearstatcache(true,TT_STORE_FILE);
        if(!is_file(TT_STORE_FILE))return tt_empty_store();
        // This cache lives only within this PHP request. Keep the shared lock
        // and recheck the file identity on every call: atomic writes/restores
        // replace the inode, including same-size writes within one second.
        clearstatcache(true,TT_STORE_FILE);
        $stat=stat(TT_STORE_FILE);
        $fingerprint=$stat!==false && (int)$stat['ino']>0
            ? [$stat['dev'],$stat['ino'],$stat['size'],$stat['mtime'],$stat['ctime'],$GLOBALS['ttAuthStoreGeneration']??0] : null;
        if($fingerprint!==null && $fingerprint===$cachedFingerprint && $cachedData!==null)return $cachedData;
        $GLOBALS['ttRequestTiming']['store_reads']++;
        $handle=fopen(TT_STORE_FILE,'rb');
        if($handle===false)throw new RuntimeException('Secure storage is unavailable.');
        try{$raw=stream_get_contents($handle);}finally{fclose($handle);}
        if($raw===false)throw new RuntimeException('Secure storage could not be read.');
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
        $GLOBALS['ttRequestTiming']['store_read']+=(hrtime(true)-$started)/1e6;
    }
    $started=hrtime(true);
    try {
        $data=tt_decode_store($raw);
        $cachedFingerprint=$fingerprint;
        $cachedData=$data;
        return $data;
    }
    finally { $GLOBALS['ttRequestTiming']['store_decode']+=(hrtime(true)-$started)/1e6; }
}

function tt_mutate_store(callable $callback): mixed {
    $backupLib=__DIR__ . '/backup_lib.php';
    if (is_file($backupLib)) {
        require_once $backupLib;
        if (function_exists('tt_maybe_auto_backup')) {
            $backupStarted=hrtime(true);
            try { tt_maybe_auto_backup(); }
            finally { $GLOBALS['ttRequestTiming']['backup_auto']+=(hrtime(true)-$backupStarted)/1e6; }
        }
    }
    tt_ensure_data_dir();
    $mutateStarted=hrtime(true);
    $lock=tt_open_store_lock(LOCK_EX);
    $GLOBALS['ttRequestTiming']['store_write_wait']+=(hrtime(true)-$mutateStarted)/1e6;
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
        $GLOBALS['ttRequestTiming']['store_mutate']+=(hrtime(true)-$mutateStarted)/1e6;
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

/** Preserve legacy module grants; explicit matrices grant only the selected icon. */
function tt_user_can_module_action(array $user,string $module,string $icon,string $action): bool {
    if (($user['role']??'')==='Super Admin') return true;
    $permissions=$user['permissions'][$module]??[];
    if ($permissions==='all') return true;
    if (!is_array($permissions)) return false;
    if (in_array($action,$permissions,true)) return true; // legacy module-wide grant, including entity-scoped records
    return in_array($action,(array)($permissions[$icon]??[]),true);
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
    if (!empty($user['must_change_password'])) return 'change-password.php';
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
        setcookie(session_name(),'',[
            'expires'=>time()-42000,
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
    // Temporary credentials authenticate only the password-change lifecycle.
    // Keep this at the shared identity boundary, including API callers that
    // deliberately do not use tt_require_login(). Do not destroy the session.
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if (!empty($user['must_change_password']) && !in_array($path,
        ['/login.php','/change-password.php','/logout.php','/api/session_activity.php'],true)) {
        if (str_starts_with($path,'/api/')) tt_api_json_error(403,'Change your temporary password before using the software.');
        header('Location: /change-password.php',true,303);
        exit;
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

/** A URL, form and JSON body must identify the same protected company. */
function tt_resolve_api_entity(array $query,array $form,?array $body,array $policy): string {
    $entities=[];
    foreach ([$query,$form,$body??[]] as $values) {
        if (!array_key_exists('entity',$values)) continue;
        if (!is_string($values['entity'])) throw new InvalidArgumentException('Select a valid legal entity.');
        $entity=strtoupper(trim($values['entity']));
        if ($entity!=='') $entities[$entity]=true;
    }
    $fixed=(string)($policy['fixed']??'');
    if ($fixed!=='') $entities[$fixed]=true;
    if (count($entities)>1) throw new InvalidArgumentException('Conflicting legal entities were supplied. Refresh and select the company again.');
    return (string)(array_key_first($entities)??'');
}

/** Shared decision authority for legacy Director approval workflows. */
function tt_user_can_director_approve(array $user): bool {
    return ($user['role']??'')==='Super Admin'||(($user['role']??'')==='Director'&&tt_user_can_open_module($user,'Directors'));
}

/** Route ownership is server-defined; company grants never substitute for an icon. */
function tt_api_write_grants(string $endpoint,array $body): ?array {
    if(in_array($endpoint,['bank_entries.php','accounts_subaccounts.php'],true)){$right=($body['action']??'')==='reverse'?'Edit':(($body['operation']??'add')==='delete'?'Delete':(($body['operation']??'add')==='edit'?'Edit':'Create'));return [['Accounts',$endpoint==='bank_entries.php'?'cashbank':'masters',$right]];}
    if($endpoint==='bank_direct_entries.php'&&strtoupper((string)($body['type']??''))==='SAVING_PROFIT')return [['Accounts','cashbank'],['Accounts','reconciliation']];
    $routes=[
        'rent_salary.php'=>'expenses','rent_salary_v2.php'=>'expenses','expenses_v1.php'=>'expenses','donations.php'=>'expenses',
        'sales_tax_refunds.php'=>'purchases','production_costing.php'=>'purchases','production_fixed_overhead.php'=>'purchases',
        'production_inventory_transfer.php'=>'purchases','commodity_bills.php'=>'purchases','other_purchases.php'=>'purchases',
        'management_costing.php'=>'purchases','management_costing_attach.php'=>'purchases',
        'bank_entries.php'=>'cashbank','accounts_subaccounts.php'=>'masters',
        'bank_direct_entries.php'=>'reconciliation','bank_reconciliation.php'=>'reconciliation',
        'internal_bank_transfers.php'=>'cashbank','retention_remittances.php'=>'cashbank','tg_bank_transfer.php'=>'cashbank',
        'tg_year_end_revaluation.php'=>'cashbank','export_bank_shortfall.php'=>'cashbank',
        'bag_supplier_payments.php'=>'supplier','other_supplier_settlements.php'=>'supplier','supplier_settlements.php'=>'supplier',
        'payment_plans.php'=>'supplier','payables_planning.php'=>'supplier','tg_liabilities.php'=>'supplier','local_customer_receipts.php'=>'customer',
        'export_tax_certificates.php'=>'reports',
        'bank_accounts.php'=>'masters','bag_bill_file.php'=>'purchases',
        'tg_remittances.php'=>'tg','brokerage_transactions.php'=>'supplier',
    ];
    if(isset($routes[$endpoint]))return [['Accounts',$routes[$endpoint]]];
    $action=(string)($body['action']??'');
    if(in_array($endpoint,['export_costing.php','local_sales_costing.php'],true))return $action==='post_waiting'
        ?[['Accounts','customer'],['Accounts','purchases']]:[['Accounts','customer']];
    if($endpoint==='export_receipts.php'||$endpoint==='accounts_receipt_file.php')return [['Accounts','customer'],['Accounts','cashbank']];
    if($endpoint==='brokerage_master.php')return [['Accounts','supplier'],['Directors','brokerage']];
    if($endpoint==='accounts_workflows_v1.php'){
        $icons=['save_soda'=>'purchases','extend_soda'=>'purchases','resolve_soda'=>'purchases','sync_late_holds'=>'purchases',
            'save_service_bill'=>'services','save_transport_bill'=>'transport','save_transport_route'=>'transport',
            'save_freight_bill'=>'freight','settle_freight_dispute'=>'freight'];
        if(isset($icons[$action]))return [['Accounts',$icons[$action]]];
        if($action==='register_loading_program')return [['Exports','loading'],['Accounts','transport']];
        if($action==='save_freight_agreement')return [['Accounts','freight'],['Directors','freight']];
    }
    if($endpoint==='milling_purchase_sodas.php')return [['Mill','export'],['Accounts','purchases']];
    if($endpoint==='bag_purchases.php')return match($action){
        'sync_po'=>[['Exports','bags']], 'sync_receipt_snapshot'=>[['Mill','newbags']],
        'sync_export_usage'=>[['Exports','commercial']], 'approve_rate_exception'=>null,
        default=>[['Accounts','purchases']],
    };
    if($endpoint==='nonwoven_bag_bills.php'&&$action!=='approve_rate_exception')return [['Accounts','purchases']];
    if($endpoint==='local_sales_control.php')return match($action){
        'approve_candidate','reject_candidate'=>[['Accounts','customer']], 'queue_candidate'=>[['Mill','local']], default=>null,
    };
    if($endpoint==='local_sales_payments.php')return in_array($action,['approve_payment','reject_payment'],true)?[['Accounts','customer']]:[['Mill','local']];
    if($endpoint==='local_sales_entity_rule.php')return [['Mill','local'],['Accounts','customer']];
    if($endpoint==='tg_bank_transactions.php')return match($action){
        'post_receipt'=>[['Accounts','customer'],['Accounts','cashbank']],
        'apply_advance'=>[['Accounts','customer']],
        'post_payment'=>!empty($body['guidedPayment'])&&($body['paymentType']??'')==='LIABILITY'
            ?[['Accounts','supplier'],['Accounts','cashbank']]:[['Accounts','cashbank']],default=>null,
    };
    if($endpoint==='accounts.php'){
        if($action==='reverse_journal')return [['Accounts','jv','Approve']];
        if($action==='save_reminder')return [['Accounts','expenses']];
        if($action==='post_event')return match(strtoupper(trim((string)($body['eventType']??'')))){
            'UTILITY_PAYMENT','EXPENSE_REIMBURSEMENT_CAPTURE','EXPENSE_REIMBURSEMENT_SETTLE','CREDIT_CARD_PAYMENT'=>[['Accounts','expenses']],
            'COMMODITY_RECEIPT_ACCEPTED'=>[['Accounts','purchases'],['Mill','arrival']],
            'COMMODITY_BILL_VERIFIED','FIXED_ASSET_PURCHASE'=>[['Accounts','purchases']],
            'SUPPLIER_PAYMENT'=>[['Accounts','supplier']], 'BANK_TRANSFER'=>[['Accounts','cashbank']],
            'LOCAL_SALE_RECOGNIZED'=>[['Accounts','customer'],['Mill','local']],
            'EXPORT_SALE_RECOGNIZED'=>[['Accounts','customer'],['Exports','commercial']],
            'EXPORT_RECEIPT'=>[['Accounts','cashbank'],['Accounts','customer']],
            'TG_INTERCOMPANY_PAKISTAN','TG_INTERCOMPANY_TG'=>[['Accounts','tg']],default=>null,
        };
    }
    // Other endpoints retain their own specialized guards (JV, assets, Master,
    // approvals, office tokens). They are not authorized by this route table.
    return null;
}

function tt_api_icon_write_allowed(array $user,string $endpoint,array $body): bool {
    $action=(string)($body['action']??'');
    if(in_array($endpoint,['rent_salary.php','rent_salary_v2.php'],true)&&in_array($action,['save_salary_master','update_salary_master','deactivate_salary_master'],true)){
        $masterAction=match($action){'save_salary_master'=>'Create','update_salary_master'=>'Edit',default=>'Deactivate'};
        if(!tt_user_can_master($user,'salary_staff',$masterAction))return false;
    }
    $grants=tt_api_write_grants($endpoint,$body);
    if($grants===null)return true;
    foreach($grants as $grant)foreach(isset($grant[2])?[$grant[2]]:['Create','Edit','Approve'] as $action)
        if(tt_user_can_module_action($user,$grant[0],$grant[1],$action))return true;
    return false;
}

function tt_post_correction_allowed(array $user,array $journal): bool {
    $source=(string)($journal['meta']['originalSourceType']??$journal['sourceType']??'');
    if($source==='BANK_RECON_DIRECT_ENTRY'&&($journal['meta']['bankDirectType']??'')==='SAVING_PROFIT')return tt_user_can_module_action($user,'Accounts','cashbank','Edit')||tt_user_can_module_action($user,'Accounts','reconciliation','Edit');
    $groups=[
        'expenses'=>['UTILITY_PAYMENT','EXPENSE_REIMBURSEMENT_CAPTURE','EXPENSE_REIMBURSEMENT_SETTLE','CREDIT_CARD_PAYMENT','CREDIT_CARD_STATEMENT','CREDIT_CARD_STATEMENT_AMENDMENT','EXPORT_EXPENSE_PAYMENT','GENERAL_EXPENSE_PAYMENT','DONATION_PAYMENT','RENT_MONTHLY_ACCRUAL','RENT_PAYMENT','SALARY_ADVANCE','SALARY_BATCH_PAYMENT','SALARY_MONTHLY_ACCRUAL','SALARY_PAYMENT','SALARY_MONTH_COMPLETED'],
        'purchases'=>['COMMODITY_RECEIPT_ACCEPTED','COMMODITY_BILL_VERIFIED','EX_MILL_PURCHASE_LIABILITY','EXPORT_BAG_SUPPLIER_BILL','NON_WOVEN_BAG_SUPPLIER_BILL','OTHER_PURCHASE','FIXED_ASSET_PURCHASE','PRODUCTION_INVENTORY_TRANSFER','AUTO_PRODUCTION_COST','ACCOUNTS_BYPRODUCT_VALUATION','SALES_TAX_REFUND_RECEIPT'],
        'supplier'=>['SUPPLIER_PAYMENT','SUPPLIER_ADVANCE','SUPPLIER_ADVANCE_APPLIED','SUPPLIER_CHEQUE_BANK_REVERSAL','SUPPLIER_CHEQUE_CLEARED','SUPPLIER_CHEQUE_ISSUED','SUPPLIER_CHEQUE_PAYABLE_REOPENED','EXPORT_BAG_SUPPLIER_PAYMENT','NON_WOVEN_BAG_SUPPLIER_PAYMENT','OTHER_SUPPLIER_PAYMENT','BROKERAGE_WHT_DEPOSIT','TG_SUPPLIER_SERVICE_LIABILITY','TG_LIABILITY_PAYMENT','TG_SUPPLIER_ADVANCE'],
        'customer'=>['LOCAL_SALE_RECOGNIZED','LOCAL_SALE_ADVANCE_APPLIED','LOCAL_SALE_PAYMENT_APPROVED','LOCAL_SALE_COGS','EXPORT_COGS','LOCAL_CUSTOMER_CHEQUE_BANK_REVERSAL','LOCAL_CUSTOMER_CHEQUE_CLEARED','LOCAL_CUSTOMER_CHEQUE_RECEIVABLE_REOPENED','TG_CUSTOMER_ADVANCE_APPLIED','TG_CUSTOMER_ADVANCE_RECEIVED'],
        'cashbank'=>['BANK_TRANSFER','INTERNAL_BANK_TRANSFER','INTERCOMPANY_ADVANCE_RECEIPT','RETENTION_OUTWARD_REMITTANCE','TG_USD_AED_TRANSFER','TG_YEAR_END_FX_REVALUATION','EXPORT_FOREIGN_BANK_SHORTFALL','TG_BANK_PAYMENT','TG_BANK_RECEIPT','BANK_ENTRY','BANK_ENTRY_REVERSAL'],
        'reconciliation'=>['BANK_RECON_DIRECT_ENTRY'], 'transport'=>['TRANSPORT_BILL'], 'freight'=>['FREIGHT_BILL'],
        'services'=>['CLEARING_BILL','FUMIGATION_BILL','INSPECTION_BILL'],
        'tg'=>['TG_INTERCOMPANY_PAKISTAN','TG_INTERCOMPANY_TG','TG_INTERCOMPANY_PAYABLE_RECOGNIZED'],
    ];
    foreach($groups as $icon=>$sources)if(in_array($source,$sources,true))return tt_user_can_module_action($user,'Accounts',$icon,'Edit');
    if(!in_array($source,['JV','JV_REVERSAL'],true)&&in_array('Edit',(array)($user['permissions']['Accounts']??[]),true))return true;
    // Approved JVs and unclassified historical journals require the controlled
    // journal approval authority rather than borrowing an unrelated icon.
    return tt_user_can_module_action($user,'Accounts','jv','Approve');
}

/** Check the saved target while its store lock is held, before changing it. */
function tt_api_record_entity_allowed(array $user,array $body,string $entity): bool {
    if(!in_array($entity,['TTI','BRM','TG'],true))return false;
    $requested=strtoupper(trim((string)($body['entity']??$_GET['entity']??'')));
    if($requested!==''&&$requested!==$entity)return false;
    if(!tt_user_can_open_module($user,'Accounts'))return true; // separately guarded source/Director workflow
    foreach(['Create','Edit','Approve'] as $action)if(tt_user_can_access_entity($user,$entity,$action))return true;
    return false;
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
    $body=null;
    $apiWrite=str_starts_with($path,'/api/')&&!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),['GET','HEAD','OPTIONS'],true);
    if($apiWrite){
        $raw=file_get_contents('php://input')?:'';$decoded=$raw!==''?json_decode($raw,true):null;
        if(is_array($decoded))$body=$decoded;
        if(!tt_api_icon_write_allowed($user,basename($path),$body??$_POST))tt_api_json_error(403,'Write permission for this workflow is required.');
    }
    if(str_starts_with($path,'/api/')&&$path!=='/api/assets_registry.php'&&tt_user_can_open_module($user,'Accounts')){
        $policy=tt_api_entity_policy($path);
        try{$entity=tt_resolve_api_entity($_GET,$_POST,$body,$policy);}
        catch(InvalidArgumentException $e){tt_api_json_error(403,$e->getMessage());}
        if(tt_accounts_post_register_read($path,(string)($_SERVER['REQUEST_METHOD']??'GET'),$entity,(string)($_GET['account']??''))){
            if(!tt_user_accounts_entities($user))tt_api_json_error(403,'You do not have permission for any company books.');
            // accounts_ledger_browser.php filters every journal/post by entity View rights.
            tt_release_read_session();
            return $user;
        }
        if($entity===''&&!empty($policy['required'])&&($user['role']??'')!=='Super Admin')tt_api_json_error(403,'An authorized legal entity is required.');
        if($entity!==''&&!in_array($entity,['TTI','BRM','TG'],true))tt_api_json_error(403,'Select a valid legal entity.');
        if($entity!==''){
            $read=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='GET';
            $allowed=$read?tt_user_can_access_entity($user,$entity,'View'):(tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')||tt_user_can_access_entity($user,$entity,'Approve'));
            if(!$allowed)tt_api_json_error(403,'You do not have permission for this legal entity.');
        }
        // Downstream JSON handlers must use the company that this guard checked,
        // including when a caller supplies it only in the URL.
        if($apiWrite&&$entity!==''&&is_array($body)){
            $body['entity']=$entity;$GLOBALS['TT_AUTHORIZED_API_BODY']=$body;$_GET['entity']=$entity;
        }
    }
    // Every authenticated API read commits activity and CSRF before building
    // its response. Financial writes retain their session and store locks.
    if(str_starts_with($path,'/api/')&&strtoupper((string)($_SERVER['REQUEST_METHOD']??''))==='GET') tt_release_read_session();
    return $user;
}

/**
 * Call only after this GET route has authenticated and authorized the user.
 * Commit activity/CSRF before expensive read work; subsequent requests can then
 * use the same session without waiting for this response to finish.
 */
function tt_release_read_session(): void {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='GET') throw new LogicException('Only read requests may release the session here.');
    if (session_status()!==PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['user_id'])) throw new LogicException('Authenticate before releasing a read session.');
    tt_csrf();
    if (!session_write_close()) throw new RuntimeException('Your session could not be committed.');
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
    $raw=file_get_contents('php://input')?:'';$body=$GLOBALS['TT_AUTHORIZED_API_BODY']??json_decode($raw,true);
    return is_array($body)?json_encode(tt_accounts_uppercase_text($body),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$raw;
}

