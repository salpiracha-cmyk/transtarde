<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/api/telegram_dispatch_core.php';
require_once __DIR__.'/runtime_html.php';

$user=tt_require_login();
if (($user['role']??'')!=='Super Admin' || tt_managed_qa_write_blocked($user)) {
    http_response_code(403);
    exit('Super Admin access required.');
}
header('Cache-Control: private, no-store');
$platforms=tt_social_platforms();
$platform=(string)($_GET['platform']??'telegram');
if (!isset($platforms[$platform])) {
    http_response_code(404);
    exit('Social platform not found.');
}
$label=$platforms[$platform];
$return='social-media-settings.php?platform='.$platform;
$telegram=$platform==='telegram';
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!tt_verify_csrf((string)($_POST['csrf']??''))) throw new DomainException('Your session expired. Refresh and try again.');
        $action=(string)($_POST['action']??'');
        switch ($action) {
            case 'save':
                if ($telegram) tt_telegram_save(trim((string)($_POST['token']??'')),trim((string)($_POST['chatId']??'')));
                else tt_discord_save(trim((string)($_POST['webhook']??'')));
                break;
            case 'reports':
                $selected=$_POST['reports']??[];
                if(!is_array($selected)||tt_social_config($platform)===[])throw new DomainException('Save the connection before choosing reports.');
                tt_social_update_config($platform,['reports'=>tt_social_report_choices($selected)]);
                break;
            case 'enable':
            case 'disable':
                if (!$telegram && $action==='enable' && ($_POST['privateChannel']??'')!=='yes') {
                    throw new DomainException('Confirm that the Discord channel is private and limited to approved recipients.');
                }
                tt_social_update_config($platform,['enabled'=>$action==='enable']);
                break;
            case 'run':
            case 'test':
            case 'recovery':
                if (!tt_social_enabled($platform,tt_social_config($platform))) throw new DomainException($label.' delivery is disabled.');
                if ($action==='run') {
                    $result=tg_dispatch(true,$platform);
                    if (!empty($result['rateLimited'])) throw new DomainException('Discord is limiting requests. The worker will try again later.');
                    if (!empty($result['busy'])) throw new DomainException('Delivery is already running. Check the status shortly.');
                    if (!empty($result['errors'])) throw new DomainException('Some alerts need retry. Check the delivery status.');
                } elseif ($action==='recovery' && $telegram) {
                    tt_telegram_send_recovery_test();
                } elseif ($action==='test') {
                    if ($telegram) tt_telegram_send_test();
                    else tt_discord_deliver('Test from the Transtrade app. Discord delivery is connected.');
                } else {
                    throw new DomainException('Choose a supported delivery action.');
                }
                break;
            default:
                throw new DomainException('Choose a supported settings action.');
        }
        header('Location: '.$return.'&result='.$action,true,303);
        exit;
    } catch (DomainException $e) {
        $error=$e->getMessage();
    } catch (Throwable) {
        $error='Transtrade could not complete the '.$label.' action.';
    }
}
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$csrf=$h(tt_csrf());
$connected=$enabled=false;
$config=[];
$delivery=[];
try {
    $config=tt_social_config($platform);
    $connected=$config!==[];
    $enabled=tt_social_enabled($platform,$config);
    $dispatchPath=tt_social_file($platform,'dispatch');
    $delivery=is_file($dispatchPath)?json_decode((string)file_get_contents($dispatchPath),true,512,JSON_THROW_ON_ERROR):[];
} catch (Throwable) {
    $error='Saved '.$label.' settings could not be read.';
}
$notice=match ($_GET['result']??'') {
    'save'=>$label.' connection saved.',
    'enable'=>$label.' delivery enabled.',
    'disable'=>$label.' delivery disabled. Connection retained.',
    'run'=>'Checked for due '.$label.' updates.',
    'test'=>$label.' confirmed delivery of the test message.',
    'recovery'=>'Telegram confirmed delivery of the TTI Recovery PDF.',
    'reports'=>$label.' report selections saved.',
    default=>'',
};
$selected=$config['reports']??[];
$deliveryStatus=$enabled?'Enabled':($connected?'Disabled':'Not connected');
$lastRun='Pending';
if(!empty($delivery['lastRun'])) {
    try {
        $lastRun=(new DateTimeImmutable($delivery['lastRun']))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('d-m-Y, g:i a');
    } catch (Exception) {
        $lastRun='Unavailable';
    }
}
if($_SERVER['REQUEST_METHOD']==='GET')tt_release_read_session();
ob_start();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Social Media Settings | Transtrade</title>
<link rel="stylesheet" href="admin/document-reader.css">
<link rel="stylesheet" href="admin/social-media-settings.css">
</head>
<body>
<header><a href="index.php">TT · TRANSTRADE</a><span>Super Admin <a class="power" href="logout.php" aria-label="Log out">⏻</a></span></header>
<main>
<a class="back-link" href="index.php">← Control Centre</a>
<h1>Social Media Settings</h1>
<nav class="platform-nav" aria-label="Social platforms">
<?php foreach ($platforms as $key=>$name): ?>
<a href="?platform=<?=$h($key)?>" <?=$platform===$key?'aria-current="page"':''?>><?=$h($name)?></a>
<?php endforeach; ?>
</nav>
<?php if ($error!==''): ?><p role="alert" class="notice error"><?=$h($error)?></p><?php elseif ($notice!==''): ?><p role="status" class="notice"><?=$h($notice)?></p><?php endif; ?>

<div class="platform-status">
<div><strong><?=$h($label)?> delivery</strong> <span class="pill <?=$enabled?'live':''?>"><?=$h($deliveryStatus)?></span></div>
<?php if($connected): ?><span>Last server check · <?=$h($lastRun)?></span><?php endif; ?>
</div>
<?php if(!empty($delivery['lastRunErrors'])||!empty($delivery['pending'])): ?>
<p role="alert" class="notice error">Some deliveries could not be confirmed. Check delivery controls below.</p>
<?php endif; ?>

<section aria-labelledby="reports-heading">
<h2 id="reports-heading">Optional <?=$h($label)?> reports</h2>
<p>Choose which PDFs to send daily at <strong>11:00 a.m. Pakistan time</strong>. Each report shows its date range and source.</p>
<?php if(!$connected): ?><p>Save a connection below to choose reports for this platform.</p><?php endif; ?>
<form method="post" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<?php foreach(tt_social_report_catalog() as $module=>$reports): ?>
<?php $count=count(array_intersect(array_keys($reports),$selected)); ?>
<details class="report-module" <?=($count>0||($selected===[]&&$module==='Mill'))?'open':''?>>
<summary><span><?=$h($module)?></span><small><?=$count?> of <?=count($reports)?> saved</small></summary>
<ul class="report-list">
<?php foreach($reports as $id=>[$title,$source,$scope]): ?>
<li class="report-row">
<label>
<input type="checkbox" name="reports[]" value="<?=$h($id)?>" <?=in_array($id,$selected,true)?'checked':''?> <?=$connected?'':'disabled'?>>
<span><strong><?=$h($title)?></strong><small><?=$h($scope)?></small></span>
</label>
<a href="<?=$h($source)?>" target="_blank" rel="noopener" aria-label="Open source for <?=$h($title)?> in a new tab">Open source ↗</a>
</li>
<?php endforeach; ?>
</ul>
</details>
<?php endforeach; ?>
<div class="save-reports"><button type="submit" class="primary" name="action" value="reports" <?=$connected?'':'disabled'?>>Save report selections</button></div>
</form>
</section>

<details class="settings-panel">
<summary>Standard alerts and delivery controls</summary>
<dl class="schedule">
<dt>10:00 a.m.</dt><dd>Mill arrivals summary</dd>
<dt>11:00 a.m.</dt><dd>Directors’ morning PDF</dd>
<dt>On saved activity</dt><dd>Mill and Exports updates, including loading, production and completion reports</dd>
</dl>
<p>Scheduled times use Pakistan time.</p>
<?php if($connected): ?>
<form method="post" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<?php if(!$telegram&&!$enabled): ?>
<label class="tick"><input type="checkbox" name="privateChannel" value="yes" required> I verified the channel is private and limited to approved recipients.</label>
<?php endif; ?>
<button type="submit" name="action" value="<?=$enabled?'disable':'enable'?>"><?=$enabled?'Disable':'Enable'?> <?=$h($label)?></button>
</form>
<form class="actions" method="post" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<button type="submit" name="action" value="test" <?=$enabled?'':'disabled'?>>Send test message</button>
<button type="submit" name="action" value="run" <?=$enabled?'':'disabled'?>>Send due updates now</button>
<?php if($telegram): ?><button type="submit" name="action" value="recovery" <?=$enabled?'':'disabled'?>>Send TTI Recovery PDF · previous 7 days</button><?php endif; ?>
</form>
<?php else: ?><p>Save a connection to enable this platform.</p><?php endif; ?>
</details>

<details class="settings-panel" <?=$connected?'':'open'?>>
<summary><?=$h($label)?> connection</summary>
<p><?=$connected?'Connection saved. Enter new details only to replace it.':'Connect a private destination for Transtrade alerts.'?></p>
<form method="post" autocomplete="off" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<?php if ($telegram): ?>
<label class="key-label">BotFather token <input type="password" name="token" autocomplete="new-password" maxlength="121" required></label>
<label class="key-label">Telegram group ID <input name="chatId" placeholder="Include the minus sign" maxlength="17" required></label>
<?php else: ?>
<label class="key-label">Discord webhook URL <input type="password" name="webhook" autocomplete="new-password" maxlength="240" required></label>
<p>Copy the webhook URL from your private Discord channel under Edit Channel → Integrations → Webhooks. Saving a new connection disables delivery until you enable it.</p>
<?php endif; ?>
<button type="submit" class="primary" name="action" value="save">Save connection</button>
</form>
</details>
</main>
<script src="/session-activity.js"></script>
</body>
</html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/');
