<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/fellow_exporters_core.php';

try {
    $path=TT_DATA_DIR.'/accounts.json';
    if (!is_file($path)) {echo "No Accounts records to adopt.\n";exit;}
    $file=fopen($path,'rb');
    if (!$file || !flock($file,LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try {$accounts=json_decode((string)stream_get_contents($file),true,512,JSON_THROW_ON_ERROR);}
    finally {flock($file,LOCK_UN);fclose($file);}
    $legacy=(array)($accounts['fellowExporterParties']??[]);
    $snapshot=tt_read_store()['masters']['business_parties']??[];
    $count=fe_adopt_legacy_parties($snapshot,$legacy);
    if ($count>0 && in_array('--apply',$argv,true)) {
        $count=tt_mutate_store(static function (array &$store) use ($legacy): int {
            $store['masters']['business_parties']??=[];
            return fe_adopt_legacy_parties($store['masters']['business_parties'],$legacy);
        },false);
    }
    echo 'Fellow exporter identities linked: '.$count.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR,'Fellow exporter adoption failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
