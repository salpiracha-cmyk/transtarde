<?php
declare(strict_types=1);
// Read-only release gate: inspect metadata, never print requests, responses or identities.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$target=(string)($argv[1]??'');if($target===''||!is_dir($target)){fwrite(STDERR,"Application directory required.\n");exit(1);}
define('TT_DATA_DIR',dirname(rtrim($target,'/')).'/transtrade_private');
require dirname(__DIR__).'/offline_idempotency.php';
$path=tt_offline_store_file();$h=null;
try{
 if(!is_file($path)){echo "OFFLINE_LEDGER_READY {\"exists\":false}\n";exit;}
 $h=fopen($path,'rb');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Ledger unavailable.');
 $data=tt_offline_read_store_locked($h);
 echo 'OFFLINE_LEDGER_READY '.json_encode(['exists'=>true,'bytes'=>(int)fstat($h)['size'],'transactions'=>count($data['transactions']),'resources'=>count($data['resources'])],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Offline replay storage requires review before this release. No acknowledgements were modified.\n");exit(1);}
finally{if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
