<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_subaccounts_core.php';
require_once __DIR__.'/accounts_reports_core.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const AR_FILE = TT_DATA_DIR.'/accounts.json';
const AR_MASTER = __DIR__.'/../accounts/accounting_master_v1.json';

function ar_out(array $value, int $status = 200): never {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ar_date(string $value, string $label): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date
        || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        ar_out(['ok' => false, 'error' => $label.' is invalid.'], 422);
    }
    return $value;
}

function ar_read(string $path): array {
    if(!is_file($path))throw new RuntimeException('Report source is unavailable.');
    $handle=@fopen($path,'rb');
    if($handle===false)throw new RuntimeException('Report source is unavailable.');
    try {
        if(!flock($handle,LOCK_SH))throw new RuntimeException('Report source is busy.');
        $value=json_decode((string)stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||($value!==[]&&array_is_list($value)))throw new RuntimeException('Report source is invalid.');
        return $value;
    } finally {flock($handle,LOCK_UN);fclose($handle);}
}


try {
    $user = tt_require_login();
    if (!tt_user_can_module_action($user, 'Accounts','reports','View')) {
        ar_out(['ok' => false, 'error' => 'Accounts Reports View permission required.'], 403);
    }

    $entity = strtoupper(trim((string)($_GET['entity'] ?? '')));
    if (
        !in_array($entity, ['TTI', 'BRM', 'TG'], true)
        || !tt_user_can_access_entity($user, $entity, 'View')
    ) {
        ar_out(['ok' => false, 'error' => 'You do not have permission for this legal entity.'], 403);
    }

    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $asOf = ar_date((string)($_GET['asOf'] ?? $today), 'As-of date');
    $from = ar_date((string)($_GET['from'] ?? substr($asOf, 0, 4).'-01-01'), 'From date');
    if ($from > $asOf) {
        ar_out(['ok' => false, 'error' => 'From date cannot be after the as-of date.'], 422);
    }

    ar_out(ar_build_report(ar_read(AR_FILE),ar_read(AR_MASTER),$entity,$from,$asOf,isset($_GET['currency'])?(string)$_GET['currency']:null));

} catch (DomainException $error) { ar_out(['ok'=>false,'error'=>$error->getMessage()],422);
} catch (Throwable $error) {
    error_log('Accounts reports failed: '.$error->getMessage());
    ar_out(['ok' => false, 'error' => 'Accounts reports could not be prepared.'], 500);
}
