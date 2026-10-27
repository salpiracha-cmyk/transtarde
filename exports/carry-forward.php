<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
tt_require_login();
header('Cache-Control: no-store');
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Shipment B/F entry has been retired. Existing shipment records and documents are retained. Use Accounts for their balances and settlements.';
