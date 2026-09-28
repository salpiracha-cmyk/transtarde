<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Exports')&&!tt_user_can_open_module($user,'Accounts')) {
        http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Exports or Accounts permission required.']);exit;
    }
    $file=TT_DATA_DIR.'/accounts.json';
    $store=[];
    if(is_file($file)) {
        $handle=fopen($file,'r');
        if($handle===false||!flock($handle,LOCK_SH))throw new RuntimeException('Accounts receipt storage unavailable.');
        try {$raw=stream_get_contents($handle);} finally {flock($handle,LOCK_UN);fclose($handle);}
        $decoded=$raw?json_decode($raw,true):null;
        if(is_array($decoded))$store=$decoded;
    }
    $receipts=[];
    foreach((array)($store['tgBankTransactions']??[]) as $row) {
        if(!is_array($row)||($row['kind']??'')!=='Receipt'||!in_array(($row['receiptType']??''),['CUSTOMER_ADVANCE','EXPORT_RECEIVABLE'],true)||($row['status']??'')==='Reversed for Amendment')continue;
        $contract=trim((string)($row['contractRef']??''));
        if($contract==='')continue;
        $receipts[]=[
            'id'=>(string)($row['id']??''),'receiptNo'=>(string)($row['bankReference']??''),
            'contractRef'=>$contract,'invoiceRef'=>(string)($row['invoiceRef']??''),
            'amount'=>round((float)($row['settlementAmountNative']??0),2),
            'currency'=>(string)($row['currency']??''),'date'=>(string)($row['date']??''),
            'status'=>'Posted','customer'=>(string)($row['counterparty']??'')
        ];
    }
    echo json_encode(['ok'=>true,'receipts'=>$receipts],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    http_response_code(500);echo json_encode(['ok'=>false,'error'=>'TG Accounts receipts could not be loaded.']);
}
