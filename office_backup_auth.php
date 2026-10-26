<?php
declare(strict_types=1);


function tt_office_backup_token_file(): string { return TT_DATA_DIR . '/office-backup-token.sha256'; }
function tt_office_backup_enabled(): bool { return is_file(tt_office_backup_token_file()); }

function tt_office_backup_issue_token(): string {
    tt_ensure_data_dir();
    $token = bin2hex(random_bytes(32));
    $path = tt_office_backup_token_file();
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    try {
        if (file_put_contents($temp, hash('sha256', $token), LOCK_EX) !== 64) throw new RuntimeException('The office backup credential could not be saved.');
        @chmod($temp, 0600);
        if (!rename($temp, $path)) throw new RuntimeException('The office backup credential could not be activated.');
    } finally { if (is_file($temp)) @unlink($temp); }
    return $token;
}

function tt_office_backup_revoke_token(): void {
    $path = tt_office_backup_token_file();
    if (is_file($path) && !unlink($path)) throw new RuntimeException('The office backup credential could not be revoked.');
}

function tt_office_backup_token_valid(string $token): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $stored = @file_get_contents(tt_office_backup_token_file());
    return is_string($stored) && preg_match('/^[a-f0-9]{64}$/D', $stored) === 1
        && hash_equals($stored, hash('sha256', $token));
}

/** Only an authenticated owner may enroll this independent, existing PC secret. */
function tt_office_policy_file(): string { return TT_DATA_DIR.'/office-backup-policy.json'; }
function tt_office_policy_read(): ?array {
    $path=tt_office_policy_file();
    if(!file_exists($path))return null; // Explicit legacy migration state; never enroll from an agent request.
    if(is_link($path)||filesize($path)>8192)throw new RuntimeException('Office security policy requires owner review.');
    $policy=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($policy)||($policy['schema']??null)!==1||!is_string($policy['passwordHash']??null)||
       !preg_match('/^[a-f0-9]{64}$/D',(string)($policy['tokenHash']??''))||!is_bool($policy['deliveryRequired']??null))throw new RuntimeException('Office security policy requires owner review.');
    return $policy;
}
function tt_office_policy_write(array $policy): void {
    tt_ensure_data_dir();$path=tt_office_policy_file();$temp=$path.'.tmp-'.bin2hex(random_bytes(8));
    try{$data=json_encode($policy,JSON_THROW_ON_ERROR);if(file_put_contents($temp,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Office security policy could not be saved.');
        chmod($temp,0600);if(!rename($temp,$path))throw new RuntimeException('Office security policy could not be activated.');
    }finally{if(is_file($temp))unlink($temp);}
}
function tt_office_policy_update(Closure $change): void {
    tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/office-backup-policy.lock','c+');
    if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Office security policy is busy.');
    try{$p=$change(tt_office_policy_read());if($p!==null)tt_office_policy_write($p);}
    finally{flock($h,LOCK_UN);fclose($h);}
}
function tt_office_policy_active(array $policy): bool {
    $hash=@file_get_contents(tt_office_backup_token_file());
    return is_string($hash)&&hash_equals($policy['tokenHash'],$hash);
}
function tt_office_policy_status(): array {
    $p=tt_office_policy_read();$active=$p!==null&&tt_office_policy_active($p);
    return ['sharedPasswordConfigured'=>tt_backup_password_get()!==null,'passwordPinned'=>$p!==null,'credentialMatches'=>$active,'deliveryRequired'=>(bool)($p['deliveryRequired']??false),
        'deliveryReady'=>$active&&(int)($p['deliveryProofAt']??0)>time()-900];
}
function tt_office_policy_pin(string $password): void {
    if(strlen($password)<12||strlen($password)>256)throw new InvalidArgumentException('Enter the existing Office PC recovery password (12–256 characters).');
    $tokenHash=@file_get_contents(tt_office_backup_token_file());
    if(!is_string($tokenHash)||!preg_match('/^[a-f0-9]{64}$/D',$tokenHash))throw new InvalidArgumentException('An active Office PC credential is required.');
    tt_office_policy_update(static fn($old)=>['schema'=>1,'tokenHash'=>$tokenHash,'passwordHash'=>password_hash(hash('sha256',$password),PASSWORD_DEFAULT),
        'deliveryRequired'=>(bool)($old['deliveryRequired']??false),'deliveryProofAt'=>0]);
}
function tt_office_password_allowed(string $password): bool {
    $p=tt_office_policy_read();
    return $p===null||(strlen($password)>=12&&strlen($password)<=256&&tt_office_policy_active($p)&&password_verify(hash('sha256',$password),$p['passwordHash']));
}
function tt_office_delivery_allowed(string $encoded): bool {
    if(tt_backup_password_get()!==null)return true; // This owner-selected mode uses the authenticated PC key.
    $p=tt_office_policy_read();if($p===null)return true;if(!tt_office_policy_active($p))return false;
    $password=strlen($encoded)<=344&&preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D',$encoded)?base64_decode($encoded,true):false;
    $valid=is_string($password)&&base64_encode($password)===$encoded&&tt_office_password_allowed($password);
    if($valid&&(int)($p['deliveryProofAt']??0)<time()-300)tt_office_policy_update(static function($current)use($p){
        if($current===null||!hash_equals($p['passwordHash'],$current['passwordHash'])||!hash_equals($p['tokenHash'],$current['tokenHash']))return null;
        $current['deliveryProofAt']=time();return $current;
    });
    return empty($p['deliveryRequired'])||$valid;
}
function tt_office_policy_require_delivery(): void {
    tt_office_policy_update(static function($p){
        if($p===null||!tt_office_policy_active($p)||(int)($p['deliveryProofAt']??0)<=time()-900)throw new InvalidArgumentException('Update and run the Office Agent first. A password-verified delivery connection is required within the last 15 minutes.');
        $p['deliveryRequired']=true;return $p;
    });
}

// Owner-managed ZIP password. This installation's vault never enters a backup
// and is never replaced by restoration of an older archive.
function tt_backup_password_write(string $path,string $bytes):void {
    if(is_link($path))throw new RuntimeException('Unsafe backup password storage.');
    $temp=tempnam(TT_DATA_DIR,'.backup-password-');if($temp===false)throw new RuntimeException('Backup password storage unavailable.');
    try{chmod($temp,0600);if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)||!rename($temp,$path))throw new RuntimeException('Backup password could not be saved.');}finally{if(is_file($temp))unlink($temp);}
}
function tt_backup_password_key():string {
    $path=TT_DATA_DIR.'/backup-password.key';
    if(is_link($path)||!is_file($path)||filesize($path)!==32)throw new RuntimeException('Backup password key unavailable.');
    $key=file_get_contents($path);if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Backup password key unavailable.');return $key;
}
function tt_backup_password_get():?string {
    $path=TT_DATA_DIR.'/backup-password.json';if(!file_exists($path))return null;
    if(is_link($path)||filesize($path)>8192)throw new RuntimeException('Backup password storage needs owner review.');
    $data=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??0)!==1)throw new RuntimeException('Backup password storage needs owner review.');
    $decode=static function($v):string{$x=is_string($v)?base64_decode($v,true):false;if($x===false)throw new RuntimeException('Invalid backup password storage.');return $x;};
    $nonce=$decode($data['nonce']??null);$tag=$decode($data['tag']??null);
    if(strlen($nonce)!==12||strlen($tag)!==16)throw new RuntimeException('Invalid backup password storage.');
    $value=openssl_decrypt($decode($data['cipher']??null),'aes-256-gcm',tt_backup_password_key(),OPENSSL_RAW_DATA,$nonce,$tag,'TRANSTRADE-OWNER-BACKUP-PASSWORD');
    if(!is_string($value)||strlen($value)<12||strlen($value)>256)throw new RuntimeException('Backup password storage failed authentication.');return $value;
}
function tt_backup_password_set(string $password):void {
    if(strlen($password)<12||strlen($password)>256)throw new InvalidArgumentException('Use a backup password of 12–256 characters.');
    tt_ensure_data_dir();$lockPath=TT_DATA_DIR.'/backup-password.lock';if(is_link($lockPath))throw new RuntimeException('Unsafe backup password lock.');
    $lock=fopen($lockPath,'c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Backup password settings are busy.');
    try{
        $path=TT_DATA_DIR.'/backup-password.key';
        if(!file_exists($path)){if(file_exists(TT_DATA_DIR.'/backup-password.json'))throw new RuntimeException('Existing backup password key is missing.');tt_backup_password_write($path,random_bytes(32));}
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($password,'aes-256-gcm',tt_backup_password_key(),OPENSSL_RAW_DATA,$nonce,$tag,'TRANSTRADE-OWNER-BACKUP-PASSWORD');
        if($cipher===false)throw new RuntimeException('Backup password encryption failed.');
        tt_backup_password_write(TT_DATA_DIR.'/backup-password.json',json_encode(['schema'=>1,'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher),'changedAt'=>gmdate('c')],JSON_THROW_ON_ERROR));
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
