<?php
declare(strict_types=1);

function tt_telegram_report_file(string $pdf, string $name): string {
    $dir = TT_DATA_DIR.'/telegram-reports';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Report folder unavailable.');
    foreach (glob($dir.'/*.json') ?: [] as $meta) {
        if (filemtime($meta) < time() - 7*86400) {
            $stem = substr($meta, 0, -5);
            if (is_file($stem.'.pdf')) unlink($stem.'.pdf');
            unlink($meta);
        }
    }
    $token = bin2hex(random_bytes(24));
    $base = $dir.'/'.$token;
    try {
        if (file_put_contents($base.'.pdf', $pdf, LOCK_EX) !== strlen($pdf)) throw new RuntimeException('PDF could not be saved.');
        chmod($base.'.pdf', 0600);
        $data = json_encode(['name'=>$name,'expires'=>time()+7*86400,'sha256'=>hash('sha256',$pdf)], JSON_THROW_ON_ERROR);
        if (file_put_contents($base.'.json', $data, LOCK_EX) !== strlen($data)) throw new RuntimeException('PDF link could not be saved.');
        chmod($base.'.json', 0600);
    } catch (Throwable $e) {
        @unlink($base.'.pdf'); @unlink($base.'.json');
        throw $e;
    }
    return $token;
}

function tt_telegram_send_pdf(array $report, string $name, string $caption): void {
    require_once __DIR__.'/mill_recovery_pdf.php';
    $pdf = mr_pdf_render($report);
    $token = tt_telegram_report_file($pdf, $name);
    tt_telegram_deliver('sendDocument', [
        'document'=>new CURLFile(TT_DATA_DIR.'/telegram-reports/'.$token.'.pdf','application/pdf',$name),
        'caption'=>$caption."\n\nDownload from Transtrade (valid 7 days)\nhttps://app.transtradeinternational.com/telegram-report.php?file=".$token,
    ]);
}
