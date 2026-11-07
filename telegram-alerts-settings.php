<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/api/telegram_alerts_core.php';
require_once __DIR__.'/api/telegram_dispatch_core.php';

$user = tt_require_login();
if (($user['role'] ?? '') !== 'Super Admin' || tt_managed_qa_write_blocked($user)) {
    http_response_code(403);
    exit('Super Admin access required.');
}
header('Cache-Control: private, no-store');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) {
            throw new DomainException('Your session expired. Refresh and try again.');
        }
        if (($_POST['action'] ?? '') === 'run') {
            $result=tg_dispatch(true);
            if(!empty($result['errors']))throw new DomainException('Some alerts need retry. Check the delivery status.');
            header('Location: telegram-alerts-settings.php?ran=1', true, 303);
        } elseif (($_POST['action'] ?? '') === 'recovery') {
            tt_telegram_send_recovery_test();
            header('Location: telegram-alerts-settings.php?pdf=1', true, 303);
        } elseif (($_POST['action'] ?? '') === 'test') {
            tt_telegram_send_test();
            header('Location: telegram-alerts-settings.php?sent=1', true, 303);
        } else {
            tt_telegram_save(trim((string)($_POST['token'] ?? '')), trim((string)($_POST['chatId'] ?? '')));
            header('Location: telegram-alerts-settings.php?saved=1', true, 303);
        }
        exit;
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Transtrade could not complete the Telegram action.';
    }
}
$connected = is_file(TT_DATA_DIR.'/telegram-alerts.json');
$dispatchPath=TT_DATA_DIR.'/telegram-dispatch.json';
$delivery=is_file($dispatchPath)?json_decode((string)file_get_contents($dispatchPath),true):[];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$csrf = htmlspecialchars(tt_csrf(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Telegram Alerts | Transtrade</title>
<link rel="stylesheet" href="admin/document-reader.css">
</head>
<body>
<header><a href="index.php">TT · TRANSTRADE</a><span>Super Admin</span></header>
<main>
<a href="index.php">← Control Centre</a>
<h1>Telegram Alerts</h1>
<p>Connect the private Transtrade Operations Alerts group and verify delivery from the server.</p>
<?php if ($error !== ''): ?><p role="alert" class="error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p><?php endif; ?>
<?php if (isset($_GET['saved']) && $connected): ?><p role="status">Telegram connection saved.</p><?php endif; ?>
<?php if (isset($_GET['pdf']) && $connected): ?><p role="status">Telegram confirmed delivery of the TTI Recovery PDF.</p><?php endif; ?>
<?php if (isset($_GET['sent']) && $connected): ?><p role="status">Telegram confirmed delivery of the test message.</p><?php endif; ?>
<section>
<h2><?= $connected ? 'Replace connection' : 'Connect private group' ?></h2>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<label>BotFather token <input type="password" name="token" autocomplete="new-password" maxlength="121" required></label>
<label>Telegram group ID <input name="chatId" placeholder="Include the minus sign" maxlength="17" required></label>
<button type="submit" class="primary">Save connection</button>
</form>
</section>
<?php if ($connected): ?>
<section><h2>Automatic updates</h2>
<p><?=$delivery?'Activated':'Awaiting activation'?></p>
<p>Arrivals summary at 10 a.m. and Directors PDF at 11 a.m. Pakistan time. New saved entries trigger alerts, with the server checking every minute for anything still due.</p>
<p>Last server check <?= $h($delivery['lastRun']??'Pending') ?></p>
<?php if(!empty($delivery['lastRunErrors'])): ?><p role="alert" class="error">Some deliveries could not be confirmed.</p><?php endif; ?>
<?php if(!empty($delivery['pending'])): ?><p role="alert" class="error">A delivery result needs verification before it can be sent again.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><button type="submit" name="action" value="run">Send due updates now</button></form>
</section>
<section><h2>Delivery test</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<button type="submit" name="action" value="test">Send test message</button>
<button type="submit" name="action" value="recovery">Send TTI Recovery PDF · previous 7 days</button>
</form></section>
<?php endif; ?>
</main>
</body>
</html>
