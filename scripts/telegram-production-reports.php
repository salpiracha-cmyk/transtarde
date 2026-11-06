<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once dirname(__DIR__).'/document_filenames.php';
require_once dirname(__DIR__).'/api/telegram_alerts_core.php';
require_once dirname(__DIR__).'/api/telegram_production_reports.php';
require_once dirname(__DIR__).'/api/mill_recovery_pdf.php';

const TT_REPORT_EXPORT_KEY = 'transtrade_export_v3_operational';

try {
    $path = TT_DATA_DIR.'/telegram-production-reports.json';
    $lock = fopen($path.'.lock', 'c');
    if (!$lock) throw new RuntimeException('Could not open the report delivery lock.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
    $send = in_array('--send', $argv, true);
    if (!is_file(TT_DATA_DIR.'/telegram-alerts.json')) throw new RuntimeException('Telegram connection unavailable.');
    $state = is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [
        'since' => time(), 'sent' => [],
    ];
    $save = static function (array $state) use ($path): void {
        $temp = tempnam(TT_DATA_DIR, '.telegram-reports-');
        if ($temp === false) throw new RuntimeException('Could not create delivery state.');
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (!chmod($temp, 0600) || file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $path)) {
                throw new RuntimeException('Could not save delivery state.');
            }
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    };
    $ops = mr_read_operations_source(TT_DATA_DIR.'/operations.json', ['tt30prod', TT_REPORT_EXPORT_KEY]);
    if (!isset($ops['values'][TT_REPORT_EXPORT_KEY])) throw new RuntimeException('Saved Exports records are unavailable.');
    $root = json_decode($ops['values'][TT_REPORT_EXPORT_KEY], true, 512, JSON_THROW_ON_ERROR);
    $runs = json_decode($ops['values']['tt30prod'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    if ($send && !is_file($path)) $save($state);
    $count = 0;
    foreach (tt_telegram_production_reports($root, $runs, $state['since']) as $job) {
        $key = hash('sha256', json_encode($job['key'], JSON_THROW_ON_ERROR));
        if (isset($state['sent'][$key])) continue;
        $count++;
        if (!$send) continue;
        $report = $job['report'];
        $report['title'] = $job['title'];
        $report['reference'] = $job['ref'];
        $name = tt_document_name(['company'=>$report['entity'], 'title'=>$job['title'],
            'reference'=>$job['ref'], 'from'=>$report['filters']['from'], 'to'=>$report['filters']['to'], 'extension'=>'pdf']);
        $pdf = mr_pdf_render($report);
        $temp = tempnam(TT_DATA_DIR, '.telegram-pdf-');
        if ($temp === false) throw new RuntimeException('Could not create the report file.');
        try {
            if (file_put_contents($temp, $pdf) !== strlen($pdf)) throw new RuntimeException('Could not write the report file.');
            tt_telegram_deliver('sendDocument', [
                'document'=>new CURLFile($temp, 'application/pdf', $name),
                'caption'=>$job['title'].' | '.$job['ref'],
            ]);
            $state['sent'][$key] = gmdate('c');
            $save($state);
        } finally {
            unlink($temp);
        }
    }
    echo ($send ? 'Delivered' : 'Ready to send').": $count\n";
} catch (Throwable) {
    fwrite(STDERR, "Telegram production report run failed. Check the source and group connection.\n");
    exit(1);
}
