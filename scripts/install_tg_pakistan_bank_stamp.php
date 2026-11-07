<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/auth_store.php';
const TG_PK_STAMP_ID='tg-pakistan-bank-stamp-20261022';
$source=dirname(__DIR__).'/exports/assets/TG_pakistan_bank_stamp.png';
if(!is_file($source)||getimagesize($source)[2]!==IMAGETYPE_PNG)throw new RuntimeException('TG Pakistan bank stamp PNG is missing.');
$store=tt_read_store();$company=null;
foreach((array)($store['masters']['companies']??[]) as $row)if(strtoupper(trim((string)($row['values'][1]??'')))==='TG'){$company=$row;break;}
if(!$company){echo "TG_BANK_STAMP no TG company; no changes\n";exit;}
$docs=tt_master_json_array($company['values'][14]??'');
foreach($docs as $doc)if(($doc['id']??'')===TG_PK_STAMP_ID){echo "TG_BANK_STAMP already registered; no changes\n";exit;}
if(!in_array('--apply',$argv,true)){echo "TG_BANK_STAMP ready to register\n";exit;}
$result=tt_mutate_store(static function(array &$data)use($source):string{
 foreach($data['masters']['companies'] as &$row){
  if(strtoupper(trim((string)($row['values'][1]??'')))!=='TG')continue;
  $docs=tt_master_json_array($row['values'][14]??'');foreach($docs as $doc)if(($doc['id']??'')===TG_PK_STAMP_ID)return 'already registered';
  $dir=TT_DATA_DIR.'/master-documents';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Master document storage unavailable.');
  $backup=$dir.'/tg-company-before-pakistan-bank-stamp.json';
  if(!is_file($backup)&&file_put_contents($backup,json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false)throw new RuntimeException('TG company recovery copy failed.');chmod($backup,0600);
  $storage=TG_PK_STAMP_ID.'.png';if(!copy($source,$dir.'/'.$storage)||hash_file('sha256',$source)!==hash_file('sha256',$dir.'/'.$storage))throw new RuntimeException('TG stamp copy verification failed.');chmod($dir.'/'.$storage,0600);
  $docs[]=['id'=>TG_PK_STAMP_ID,'type'=>'Stamp','label'=>'TG stamp for Pakistan bank','version'=>1,'status'=>'Active','isDefault'=>false,'purpose'=>'Pakistan bank pack','storageName'=>$storage,'originalName'=>'TG_pakistan_bank_stamp.png','mime'=>'image/png','uploadedAt'=>gmdate('c'),'uploadedBy'=>'System — owner supplied 22 October 2026'];
  while(count($row['values'])<15)$row['values'][]='';$row['values'][14]=json_encode($docs,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  array_unshift($data['audit'],['user_id'=>null,'username'=>'System','action'=>'Registered owner-supplied TG stamp for Pakistan bank; preserved existing company identities','ip_address'=>'Production deployment','created_at'=>gmdate('c')]);return 'registered';
 }
 return 'no TG company';
});echo 'TG_BANK_STAMP '.$result."\n";
