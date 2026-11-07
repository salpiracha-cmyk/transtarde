<?php
declare(strict_types=1);

/**
 * Owner-authorized operational TEST DATA reset.
 * Clears Milling, Exports and Accounts transactions plus all Parties master rows.
 * Preserves users, permissions, Companies, Products & Quality, KAT, Mills/Locations,
 * Banks, document rules, artwork/configuration and other Master Console data.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';

function reset_write_json(string $path, array $data): void {
    $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
    $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    if(file_put_contents($tmp,$json,LOCK_EX)===false) throw new RuntimeException('Reset write failed for '.basename($path));
    @chmod($tmp,0600);
    if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Reset replace failed for '.basename($path));}
}

$apply=in_array('--apply',$argv,true);
if(!$apply){echo json_encode(['ok'=>true,'applied'=>false,'reason'=>'dry-run']).PHP_EOL;exit(0);}

$summary=[
    'ok'=>true,
    'applied'=>true,
    'snapshot'=>tt_create_server_snapshot('pre-owner-test-data-reset'),
    'partiesRemoved'=>0,
    'operationsKeysRemoved'=>0,
    'accounts'=>[],
    'preserved'=>['users','permissions','master_console_except_parties'],
    'resetAt'=>gmdate('c'),
];

// 1) Super Admin Parties master: clear absolutely all current rows.
$authPath=TT_STORE_FILE;
$auth=is_file($authPath)?json_decode((string)file_get_contents($authPath),true):null;
if(!is_array($auth)) throw new RuntimeException('Auth/master store is unavailable.');
if(!isset($auth['masters'])||!is_array($auth['masters'])) $auth['masters']=[];
$summary['partiesRemoved']=count((array)($auth['masters']['parties']??[]));
$auth['masters']['parties']=[];
array_unshift($auth['audit'],[
    'user_id'=>null,
    'username'=>'owner-reset',
    'action'=>'Owner RESET: cleared Parties master and Milling/Exports/Accounts operational test data',
    'ip_address'=>'',
    'created_at'=>gmdate('c'),
]);
$auth['audit']=array_slice((array)$auth['audit'],0,5000);
reset_write_json($authPath,$auth);

// 2) Milling + Exports shared operational store: everything here is operational/test data.
$operationsPath=TT_DATA_DIR.'/operations.json';
$operations=is_file($operationsPath)?json_decode((string)file_get_contents($operationsPath),true):null;
if(!is_array($operations)) $operations=['revision'=>0,'values'=>[],'meta'=>[]];
$summary['operationsKeysRemoved']=count((array)($operations['values']??[]));
$operations=[
    'revision'=>(int)($operations['revision']??0)+1,
    'values'=>[],
    'meta'=>[],
    'reset'=>['at'=>gmdate('c'),'by'=>'Super Admin','scope'=>'Milling + Exports operational test data'],
];
reset_write_json($operationsPath,$operations);

// 3) Accounts: remove transactional/workflow data but retain Accounts master/config sections.
$accountsPath=TT_DATA_DIR.'/accounts.json';
$accounts=is_file($accountsPath)?json_decode((string)file_get_contents($accountsPath),true):null;
if(is_array($accounts)){
    $preserveKeys=['masters','commodityKatMaster'];
    $preserved=[];
    foreach($preserveKeys as $key) if(array_key_exists($key,$accounts)) $preserved[$key]=$accounts[$key];

    $transactionKeys=[
        'journals','events','postingIdentities','reminders','commodityBills','supplierBills','supplierSettlements',
        'payableHolds','purchaseSodasV2','transportMaster','loadingProgrammes','transportBillsV1',
        'freightAgreements','freightBillsV1','exportServiceBillsV1','workflowAudit'
    ];
    foreach($transactionKeys as $key){
        $summary['accounts'][$key]=count((array)($accounts[$key]??[]));
        $accounts[$key]=[];
    }
    foreach($preserved as $key=>$value) $accounts[$key]=$value;
    $accounts['revision']=(int)($accounts['revision']??0)+1;
    $accounts['lastTestDataResetAt']=gmdate('c');
    reset_write_json($accountsPath,$accounts);
}else{
    $summary['accounts']['file']='not present';
}

echo json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
