<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/api/telegram_dispatch_core.php';

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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Social Media Settings | Transtrade</title>
<link rel="stylesheet" href="admin/document-reader.css">
<style>
.platform-nav{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0}
.platform-nav a{padding:8px 14px;border:1px solid #aab8c0;border-radius:8px;text-decoration:none}
.platform-nav a[aria-current]{background:#17364b;color:white;font-weight:700;border-color:#17364b}
.report-module{border:1px solid #ced8dc;border-radius:8px;padding:10px 12px}
.report-module summary{font-weight:700;cursor:pointer}
.report-module label{margin:10px 0 0}
.report-module input[type=checkbox]{width:auto;margin-right:6px}
</style>
</head>
<body>
<header><a href="index.php">TT · TRANSTRADE</a><span>Super Admin</span></header>
<main>
<a href="index.php">← Control Centre</a>
<h1>Social Media Settings</h1>
<nav class="platform-nav" aria-label="Social platforms">
<?php foreach ($platforms as $key=>$name): ?>
<a href="?platform=<?=$h($key)?>" <?=$platform===$key?'aria-current="page"':''?>><?=$h($name)?></a>
<?php endforeach; ?>
</nav>
<?php if ($error!==''): ?><p role="alert" class="error"><?=$h($error)?></p><?php elseif ($notice!==''): ?><p role="status"><?=$h($notice)?></p><?php endif; ?>
<section>
<h2><?=$h($label)?> connection</h2>
<p><?=$connected?'Connection saved. Enter new details only to replace it.':'Connect a private destination for Transtrade alerts.'?></p>
<form method="post" autocomplete="off" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<?php if ($telegram): ?>
<label>BotFather token <input type="password" name="token" autocomplete="new-password" maxlength="121" required></label>
<label>Telegram group ID <input name="chatId" placeholder="Include the minus sign" maxlength="17" required></label>
<?php else: ?>
<label>Discord webhook URL <input type="password" name="webhook" autocomplete="new-password" maxlength="240" required></label>
<p>In your private Discord text channel, open Edit Channel → Integrations → Webhooks and copy its webhook URL. A new or replacement connection starts disabled.</p>
<?php endif; ?>
<button type="submit" class="primary" name="action" value="save">Save connection</button>
</form>
</section>
<section>
<h2><?=$h($label)?> delivery</h2>
<p><strong><?=$enabled?'Enabled':'Disabled'?></strong></p>
<?php if ($connected): ?>
<form method="post" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<?php if (!$telegram && !$enabled): ?>
<p>Alerts include operational entries and Directors reports. Only approved recipients should have access to the Discord channel.</p>
<label><input type="checkbox" name="privateChannel" value="yes" required> I verified the channel is private and limited to approved recipients.</label>
<?php endif; ?>
<button type="submit" name="action" value="<?=$enabled?'disable':'enable'?>"><?=$enabled?'Disable':'Enable'?> <?=$h($label)?></button>
</form>
<p>Arrivals summary at 10 a.m. and Directors PDF at 11 a.m. Pakistan time. The server also checks for saved entries and due updates.</p>
<p>Last server check <?=$h($delivery['lastRun']??'Pending')?></p>
<?php if(!empty($delivery['lastRunErrors'])): ?><p role="alert" class="error">Some deliveries could not be confirmed.</p><?php endif; ?>
<?php if(!empty($delivery['pending'])): ?><p role="alert" class="error">A delivery result needs verification before it can be sent again.</p><?php endif; ?>
<form method="post" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<button type="submit" name="action" value="run" <?=$enabled?'':'disabled'?>>Send due updates now</button>
<button type="submit" name="action" value="test" <?=$enabled?'':'disabled'?>>Send test message</button>
<?php if($telegram): ?><button type="submit" name="action" value="recovery" <?=$enabled?'':'disabled'?>>Send TTI Recovery PDF · previous 7 days</button><?php endif; ?>
</form>
<?php else: ?><p>Save a connection to enable this platform.</p><?php endif; ?>
</section>
<section>
<h2>Optional <?=$h($label)?> reports</h2>
<p>Tick the extra PDFs you want at 11 a.m. Pakistan time. Standard alerts keep their existing schedule.</p>
<?php if($connected): ?>
<form method="post" action="<?=$h($return)?>">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<?php $selected=$config['reports']??[]; foreach(tt_social_report_catalog() as $module=>$reports): ?>
<details class="report-module">
<summary><?=$h($module)?> · <?=count(array_intersect(array_keys($reports),$selected))?> selected</summary>
<?php foreach($reports as $id=>$title): ?>
<label><input type="checkbox" name="reports[]" value="<?=$h($id)?>" <?=in_array($id,$selected,true)?'checked':''?>> <?=$h($title)?></label>
<?php endforeach; ?>
</details>
<?php endforeach; ?>
<button type="submit" name="action" value="reports">Save report selections</button>
</form>
<?php else: ?><p>Save a connection to choose optional reports.</p><?php endif; ?>
</section>
</main>
</body>
</html>
