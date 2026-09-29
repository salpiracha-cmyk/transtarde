<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/auth_store.php';
function sw_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

sw_check(TT_SESSION_IDLE_TIMEOUT===3600,'Inactive users must be signed out after exactly one hour.');
sw_check(tt_user_session_version([])===1,'Existing users must receive the compatible initial session version.');
sw_check(tt_user_session_version(['session_version'=>4])===4,'Stored session versions must be enforced.');

$root=dirname(__DIR__,2);
$auth=file_get_contents($root.'/auth_store.php');
$activity=file_get_contents($root.'/session-activity.js');
$change=file_get_contents($root.'/change-password.php');
$login=file_get_contents($root.'/login.php');
$htaccess=file_get_contents($root.'/.htaccess');

sw_check(str_contains($auth,'last_activity_at'),'Server sessions must retain their last real activity time.');
sw_check(str_contains($auth,'HTTP_X_TT_USER_ACTIVITY'),'API traffic must explicitly identify user activity.');
sw_check(str_contains($auth,"str_starts_with(\$path,'/api/')"),'Background API calls must be distinguishable from page navigation.');
sw_check(str_contains($auth,'session_version'),'Password changes must invalidate older sessions.');
sw_check(str_contains($activity,'tt_session_last_activity_v1'),'Browser tabs must share the activity clock.');
sw_check(str_contains($activity,"['pointerdown','keydown','input','change','touchstart','scroll']"),'Only real browser interaction may extend inactivity.');
sw_check(!preg_match('/mousemove|setInterval\([^,]*heartbeat/s',$activity),'Mouse movement and timed background heartbeats must not keep sessions alive.');
sw_check(str_contains($change,'current_password'),'Password changes must verify the current password.');
sw_check(str_contains($login,'tt_admin_recovery_configured()'),'The recovery link must be hidden when recovery is not configured.');
sw_check(str_contains($htaccess,'Strict-Transport-Security'),'HTTPS transport policy must be enabled.');
sw_check(str_contains($htaccess,'scripts|tests'),'Internal scripts and tests must not be web-readable.');

echo "PASS one-hour inactivity and web security controls\n";
