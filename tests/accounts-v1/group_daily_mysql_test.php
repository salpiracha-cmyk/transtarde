<?php
declare(strict_types=1);
/** Actual selected-key reader, restricted to the disposable CI database. */
if(getenv('TT_QA_MYSQL')!=='1'||getenv('TT_DB_HOST')!=='127.0.0.1'||!str_starts_with((string)getenv('TT_DB_NAME'),'transtrade_qa_'))throw new RuntimeException('Disposable Daily database guard required.');
require __DIR__.'/../../api/group_daily_core.php';
$db=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$original=$db->query('SELECT * FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_NUM);$file=tempnam(sys_get_temp_dir(),'daily-stale-');if($file===false)throw new RuntimeException('Fixture unavailable.');
try{
    file_put_contents($file,json_encode(['revision'=>999,'values'=>['transtrade_export_v3_operational'=>json_encode(['contracts'=>[['ref'=>'STALE-FILE','qty'=>999]]])]],JSON_THROW_ON_ERROR));
    $db->exec('DELETE FROM tt_operation_records');$insert=$db->prepare("INSERT INTO tt_operation_records VALUES(?,?,?,NOW(6),'Fixture',7,'Mill')");
    foreach(array_slice(TT_INV_SOURCE_KEYS,0,6) as $key)$insert->execute([$key,'[]',3]);
    $root=['contracts'=>[['ref'=>'TG/SQL','seller'=>'TG','date'=>'2026-09-01','commodity'=>'RICE','product'=>'Fixture Rice','qty'=>100,'received'=>true]],'shipments'=>[]];
    $insert->execute(['transtrade_export_v3_operational',json_encode($root,JSON_THROW_ON_ERROR),7]);$insert->execute(['tt35exload','[]',4]);$insert->execute(['tt35exmill','[]',4]);$insert->execute(['tt38kebills','[]',999]);
    $before=$db->query('SELECT * FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_NUM);$fileBefore=file_get_contents($file);
    $source=gd_read_operations($file);if($source['sourceBackend']!=='mysql'||$source['revision']!==7||count($source['values'])!==9||($source['versions']['transtrade_export_v3_operational']??0)!==7)throw new RuntimeException('Selected database source versions are incorrect.');
    $projection=gd_sources(['journals'=>[],'revision'=>8],$source,'2026-10-17');if($projection['salesContracts'][0]['ref']!=='TG/SQL'||$projection['salesContracts'][0]['contractedMT']!==100.0)throw new RuntimeException('Reader borrowed the stale file.');
    if($db->query('SELECT * FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_NUM)!==$before||file_get_contents($file)!==$fileBefore)throw new RuntimeException('Daily projection changed original source storage.');
    echo "PASS actual MySQL Daily reader: authoritative selected sources and versions, unrelated revisions excluded, stale file ignored and zero source writes\n";
}finally{$db->exec('DELETE FROM tt_operation_records');$restore=$db->prepare('INSERT INTO tt_operation_records VALUES(?,?,?,?,?,?,?)');foreach($original as $row)$restore->execute($row);unlink($file);}
