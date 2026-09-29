<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/auth_store.php';
function ah_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$_SERVER['REMOTE_ADDR']='192.0.2.10';
ah_check(tt_auth_rate_key('login-account','person',false)===tt_auth_rate_key('login-account','person',false),'Account key must be stable.');
$global=tt_auth_rate_key('login-emergency','all-users',false);
$_SERVER['REMOTE_ADDR']='198.51.100.20';
ah_check($global===tt_auth_rate_key('login-emergency','all-users',false),'Emergency key must span addresses.');
ah_check(tt_auth_rate_key('login-address','all-users',true)!==tt_auth_rate_key('login-address','all-users',false),'Address and global keys must differ.');
ah_check(password_verify('any-password',TT_LOGIN_DUMMY_HASH)===false,'Dummy hash must never authenticate a normal test password.');
ah_check(!defined('TT_ADMIN_RECOVERY_HASH'),'Recovery hash must not be committed as a PHP constant.');

$source=file_get_contents(dirname(__DIR__,2).'/login.php');
ah_check(str_contains($source,'TT_LOGIN_DUMMY_HASH'),'Unknown usernames must run a password verification.');
ah_check(str_contains($source,"'login-address'"),'Per-address limiter is required.');
ah_check(str_contains($source,"'login-emergency'"),'Emergency global limiter is required.');

echo "PASS layered authentication and external recovery protections\n";
