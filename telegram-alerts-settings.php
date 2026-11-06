<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/api/telegram_alerts_core.php';

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
        if (($_POST['action'] ?? '') === 'test') {
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
<section><h2>Delivery test</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=$csrf?>">
<button type="submit" name="action" value="test">Send test message</button>
</form></section>
<?php endif; ?>
</main>
</body>
</html>
