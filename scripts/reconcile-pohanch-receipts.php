<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/api/pohanch_receipt_core.php';

try {
    $results = pr_reconcile();
    foreach ($results as $result) {
        if ($result['status'] !== 'existing') echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Pohanch receipt reconciliation: '.$error->getMessage().PHP_EOL);
    exit(1);
}
