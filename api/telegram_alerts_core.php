<?php
declare(strict_types=1);

function tt_telegram_request(string $token, string $method, array $fields = []): array {
    if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,100}$/D', $token)) {
        throw new DomainException('Enter the bot token from BotFather.');
    }
    $curl = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => isset($fields['document']) ? $fields : http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $response = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || empty($response['ok']) || !is_array($response['result'] ?? null)) {
        throw new DomainException(match ($status) {
            400, 404 => 'Telegram could not find the bot or group. Check the token and group ID.',
            401 => 'Telegram rejected the bot token.',
            403 => 'Telegram denied access. Make sure the bot can post in the group.',
            429 => 'Telegram is limiting requests. Try again shortly.',
            default => 'The server could not confirm delivery with Telegram.',
        });
    }
    return $response['result'];
}

function tt_telegram_group(string $token, string $chatId): array {
    if (!preg_match('/^-[1-9][0-9]{0,15}$/D', $chatId)) {
        throw new DomainException('Enter the private Telegram group ID, including its minus sign.');
    }
    $group = tt_telegram_request($token, 'getChat', ['chat_id' => $chatId]);
    if ((string)($group['id'] ?? '') !== $chatId
        || !in_array($group['type'] ?? '', ['group', 'supergroup'], true)
        || !empty($group['username']) || !empty($group['active_usernames'])
        || ($group['title'] ?? '') !== 'Transtrade Operations Alerts') {
        throw new DomainException('Choose the private Transtrade Operations Alerts group.');
    }
    return $group;
}

function tt_telegram_save(string $token, string $chatId): void {
    tt_telegram_group($token, $chatId);
    $bot = tt_telegram_request($token, 'getMe');
    if (strcasecmp((string)($bot['username'] ?? ''), 'TranstradeOperationsAlertsBot') !== 0) {
        throw new DomainException('Use the token for @TranstradeOperationsAlertsBot.');
    }
    tt_ensure_data_dir();
    $temp = tempnam(TT_DATA_DIR, '.telegram-');
    if ($temp === false) throw new RuntimeException('Could not save the Telegram connection.');
    try {
        $data = json_encode(['token' => $token, 'chatId' => $chatId], JSON_THROW_ON_ERROR);
        if (!chmod($temp, 0600) || file_put_contents($temp, $data) !== strlen($data)
            || !rename($temp, TT_DATA_DIR.'/telegram-alerts.json')) {
            throw new RuntimeException('Could not save the Telegram connection.');
        }
    } finally {
        if (is_file($temp)) unlink($temp);
    }
}

function tt_telegram_deliver(string $method, array $fields): void {
    $path = TT_DATA_DIR.'/telegram-alerts.json';
    if (!is_file($path)) throw new DomainException('Save the Telegram connection first.');
    $settings = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $token = (string)($settings['token'] ?? '');
    $chatId = (string)($settings['chatId'] ?? '');
    tt_telegram_group($token, $chatId);
    $message = tt_telegram_request($token, $method, ['chat_id' => $chatId] + $fields);
    if (empty($message['message_id']) || (string)($message['chat']['id'] ?? '') !== $chatId) {
        throw new RuntimeException('Telegram did not confirm delivery.');
    }
}

function tt_telegram_send_test(): void {
    tt_telegram_deliver('sendMessage', ['text' => 'Test from the Transtrade app. Telegram delivery is connected.']);
}

function tt_telegram_send_recovery_test(): void {
    require_once dirname(__DIR__).'/inventory_reconciliation.php';
    require_once __DIR__.'/mill_recovery_core.php';
    require_once __DIR__.'/mill_recovery_pdf.php';

    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Karachi'));
    $from = $today->modify('-7 days')->format('Y-m-d');
    $to = $today->modify('-1 day')->format('Y-m-d');
    $ops = mr_read_operations_source(TT_DATA_DIR.'/operations.json');
    $runs = json_decode($ops['values']['tt30prod'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    $report = mr_report($runs, ['entity' => 'TTI', 'mode' => 'daily', 'from' => $from, 'to' => $to]);
    if (!$report['groups']) throw new DomainException('There are no TTI recovery records in the previous seven days.');

    $pdf = mr_pdf_render($report);
    $path = tempnam(sys_get_temp_dir(), 'tt-recovery-');
    if ($path === false) throw new RuntimeException('Could not create the report PDF.');
    try {
        if (file_put_contents($path, $pdf) !== strlen($pdf)) throw new RuntimeException('Could not write the report PDF.');
        tt_telegram_deliver('sendDocument', [
            'document' => new CURLFile($path, 'application/pdf', 'TTI-Recovery-'.$from.'-to-'.$to.'.pdf'),
            'caption' => 'Transtrade PDF delivery test | TTI Daily Recovery | '.$from.' to '.$to,
        ]);
    } finally {
        unlink($path);
    }
}
