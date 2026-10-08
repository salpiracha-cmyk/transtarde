<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function irr_out(array $data,int $status=200):never {http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function irr_next(array $values,array $b,array $u):array {
 if(!hash_equals(tt_inv_review_revision($values),(string)($b['revision']??'')))throw new RuntimeException('Stock changed. Refresh the report before reviewing.',409);
 return tt_inv_review($values,(string)$b['entity'],(string)($b['confirmationId']??''),(string)($b['reason']??''),$u,gmdate('c'));
}
try{
 $u=tt_require_login();if($_SERVER['REQUEST_METHOD']!=='POST')irr_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $b=json_decode(file_get_contents('php://input')?:'{}',true,512,JSON_THROW_ON_ERROR);$e=strtoupper((string)($b['entity']??''));$b['entity']=$e;
 if(!in_array($e,['TTI','BRM'],true)||!tt_inv_can_review($u,$e))irr_out(['ok'=>false,'error'=>'Company stock reconciliation review permission required.'],403);
 if(!tt_verify_csrf((string)($b['csrf']??'')))irr_out(['ok'=>false,'error'=>'Session expired.'],419);
 $env=static function(string $key):string{$name='TT_DB_'.$key;return defined($name)?(string)constant($name):(string)(getenv($name)?:'');};
 if($env('HOST')!==''&&$env('NAME')!==''&&$env('USER')!==''){
  $db=new PDO('mysql:host='.$env('HOST').';dbname='.$env('NAME').';charset=utf8mb4',$env('USER'),$env('PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $lock='tt_inventory_'.substr(hash('sha256',$env('NAME')),0,24);$take=$db->prepare('SELECT GET_LOCK(?,15)');$take->execute([$lock]);if((int)$take->fetchColumn()!==1)throw new RuntimeException('Stock storage is busy.');
  try{
   $db->beginTransaction();$keys=array_values(array_unique(array_merge(TT_INV_SOURCE_KEYS,TT_INV_PRIVATE_KEYS)));$read=$db->prepare('SELECT storage_key,payload,version FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count($keys),'?')).') ORDER BY storage_key FOR UPDATE');$read->execute($keys);$records=$read->fetchAll();$values=[];$versions=[];
   foreach($records as $r){$values[$r['storage_key']]=$r['payload'];$versions[$r['storage_key']]=$r['version'];}$next=irr_next($values,$b,$u);
   $write=$db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by),updated_by_user=VALUES(updated_by_user),updated_by_module=VALUES(updated_by_module)');
   $history=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');
   $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');$name=$u['full_name']??$u['username']??'Accounts';
   foreach($next as $key=>$value)if(($values[$key]??'')!==$value){$version=(int)($versions[$key]??0)+1;$write->execute([$key,$value,$version,$now,$name,$u['id']??null,'System']);$history->execute([$key,$version,hash('sha256',$value),$now,$name,$u['id']??null,'System']);}
   $db->commit();
  }catch(Throwable $x){if($db->inTransaction())$db->rollBack();throw $x;}finally{$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}
 }else{
  tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/operations.json','c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Stock storage unavailable.');
  try{
   $raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):['revision'=>0,'values'=>[],'meta'=>[]];if(!is_array($s))throw new RuntimeException('Invalid stock storage.');
   $values=(array)($s['values']??[]);$next=irr_next($values,$b,$u);foreach($next as $key=>$value)if(($values[$key]??'')!==$value){$s['values'][$key]=$value;$s['revision']=(int)($s['revision']??0)+1;$s['meta'][$key]=['version'=>(int)($s['meta'][$key]['version']??0)+1,'updatedAt'=>gmdate('c'),'updatedBy'=>$u['full_name']??$u['username']??'Accounts','userId'=>$u['id']??null,'module'=>'System'];}
   $encoded=json_encode($s,JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Stock review save failed.');
  }finally{flock($h,LOCK_UN);fclose($h);}
 }
 irr_out(['ok'=>true]);
}catch(DomainException $x){irr_out(['ok'=>false,'error'=>$x->getMessage()],403);}catch(InvalidArgumentException $x){irr_out(['ok'=>false,'error'=>$x->getMessage()],422);}catch(Throwable $x){error_log('Stock review: '.$x->getMessage());irr_out(['ok'=>false,'error'=>$x->getCode()===409?$x->getMessage():'Stock reconciliation review could not be saved.'], $x->getCode()===409?409:500);}
