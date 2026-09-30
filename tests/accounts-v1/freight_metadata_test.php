<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/tt-freight-'.bin2hex(random_bytes(8));mkdir($dir);define('TT_DATA_DIR',$dir);
require dirname(__DIR__,2).'/api/accounts_shipment_source.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=['customers'=>[['id'=>'C','name'=>'Customer']], 'contracts'=>[['ref'=>'A','seller'=>'TTI','customerId'=>'C','podPort'=>'Jeddah'],['ref'=>'B','seller'=>'TTI','podPort'=>'Jeddah'],['ref'=>'OTHER','seller'=>'TTI','podPort'=>'Dubai']], 'shipments'=>[
 ['id'=>'P','kind'=>'process','contractRef'=>'A','loading'=>['lots'=>[['lotRecordId'=>'L','plan'=>['allocations'=>[['containers'=>2]]]]]]],
 ['id'=>'L','kind'=>'lot','seller'=>'TTI','contractRef'=>'A','lotId'=>'A/01','loadingProgrammeNo'=>'OLD','loadingPlan'=>['shippingLine'=>'Old line','allocations'=>[['containers'=>2]]],'bl'=>['shippingLine'=>'Draft line','blNo'=>'KEEP-BL'],'millActuals'=>[['number'=>'KEEP1','netKg'=>24000]]],
 ['id'=>'L2','kind'=>'lot','seller'=>'TTI','contractRef'=>'B','loadingProgrammeNo'=>'SHARED'],
 ['id'=>'L3','kind'=>'lot','seller'=>'TTI','contractRef'=>'OTHER','loadingProgrammeNo'=>'WRONG-PORT']
 ],'millSync'=>['exportLoading'=>[['shipmentId'=>'L','plan'=>['allocations'=>[['containers'=>2]]],'production'=>['keep'=>true]]]]];
$a=['id'=>'FRA-1','entity'=>'TTI','shipmentId'=>'L','contractRef'=>'A','destinationPort'=>'Jeddah','loadingProgrammeNo'=>'SHARED','shippingLine'=>'New line','fromPort'=>'Karachi Port, Pakistan'];$user=['id'=>1,'full_name'=>'Disposable QA'];
$before=$root['shipments'][1];$next=tt_accounts_freight_metadata($root,$a,$user);
check($next['shipments'][1]['millActuals']===$before['millActuals']&&$next['shipments'][1]['loadingPlan']['allocations']===$before['loadingPlan']['allocations'],'Quantities changed');
check($next['shipments'][1]['bl']['blNo']==='KEEP-BL'&&$next['shipments'][1]['bl']['shippingLine']==='New line','BL metadata');
check($next['shipments'][0]['loading']['lots'][0]['loadingProgrammeNo']==='SHARED'&&$next['millSync']['exportLoading'][0]['loadingProgrammeNo']==='SHARED','Loading and Milling links');
check(tt_accounts_freight_metadata($next,$a,$user)===$next,'Retry should be idempotent');
try{tt_accounts_freight_metadata($root,array_replace($a,['loadingProgrammeNo'=>'WRONG-PORT']),$user);throw new RuntimeException('Accepted conflicting ports');}catch(InvalidArgumentException $expected){}
try{tt_accounts_freight_metadata($root,array_replace($a,['entity'=>'BRM']),$user);throw new RuntimeException('Accepted foreign company');}catch(InvalidArgumentException $expected){}
check(tt_accounts_shipment_rows($root,'TTI')[0]['shippingLine']==='Draft line','BL draft line precedence');
$key='transtrade_export_v3_operational';
if(getenv('TT_QA_MYSQL')==='1'){
 check(getenv('TT_DB_HOST')==='127.0.0.1'&&preg_match('/^transtrade_qa_[a-z0-9_]+$/',(string)getenv('TT_DB_NAME'))===1,'Only a local disposable QA database is allowed');
 $db=tt_accounts_exports_db();
 $db->exec('CREATE TABLE IF NOT EXISTS tt_operation_records (storage_key VARCHAR(96) PRIMARY KEY,payload LONGTEXT NOT NULL,version BIGINT UNSIGNED NOT NULL,updated_at DATETIME(6) NOT NULL,updated_by VARCHAR(160) NOT NULL,updated_by_user BIGINT NULL,updated_by_module VARCHAR(32) NOT NULL) ENGINE=InnoDB');
 $db->exec('CREATE TABLE IF NOT EXISTS tt_operation_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,storage_key VARCHAR(96) NOT NULL,version BIGINT UNSIGNED NOT NULL,payload_sha256 CHAR(64) NOT NULL,updated_at DATETIME(6) NOT NULL,updated_by VARCHAR(160) NOT NULL,updated_by_user BIGINT NULL,updated_by_module VARCHAR(32) NOT NULL) ENGINE=InnoDB');
}else $db=tt_accounts_exports_db();
$old=null;$oldHistory=0;
try{
 if($db){
  $stmt=$db->prepare('SELECT * FROM tt_operation_records WHERE storage_key=?');$stmt->execute([$key]);$old=$stmt->fetch();$oldHistory=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM tt_operation_history')->fetchColumn();
  $stmt=$db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,7,NOW(),?,1,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=7');$stmt->execute([$key,json_encode($root),'Disposable QA','Accounts']);
 }else file_put_contents($dir.'/operations.json',json_encode(['revision'=>7,'values'=>[$key=>json_encode($root),'other'=>'KEEP']]));
 tt_accounts_sync_freight($a,$user);$saved=tt_accounts_exports_root();check($saved['shipments']===$next['shipments']&&$saved['millSync']===$next['millSync'],'Stored source update');
 tt_accounts_sync_freight($a,$user);check(tt_accounts_exports_root()===$saved,'Stored retry idempotence');
 if($db){$stmt=$db->prepare('SELECT version FROM tt_operation_records WHERE storage_key=?');$stmt->execute([$key]);check((int)$stmt->fetchColumn()===8,'Source version should advance once');}
 else{$store=json_decode((string)file_get_contents($dir.'/operations.json'),true);check($store['revision']===8&&$store['values']['other']==='KEEP','Other module data changed');}
 echo 'Freight metadata, programme port validation, source version, retry and Milling linkage tests passed'.PHP_EOL;
}finally{
 if($db){$db->prepare('DELETE FROM tt_operation_history WHERE storage_key=? AND id>?')->execute([$key,$oldHistory]);if($old){$db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=?,updated_by_module=? WHERE storage_key=?')->execute([$old['payload'],$old['version'],$old['updated_at'],$old['updated_by'],$old['updated_by_user'],$old['updated_by_module'],$key]);}else $db->prepare('DELETE FROM tt_operation_records WHERE storage_key=?')->execute([$key]);}
 if(is_file($dir.'/operations.json'))unlink($dir.'/operations.json');rmdir($dir);
}
