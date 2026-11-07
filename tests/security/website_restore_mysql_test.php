<?php
declare(strict_types=1);
if(getenv('TT_QA_MYSQL')!=='1'||getenv('TT_DB_HOST')!=='127.0.0.1'||!preg_match('/^transtrade_qa_[a-z0-9_]+$/D',(string)getenv('TT_DB_NAME')))throw new RuntimeException('Only a local disposable QA database is allowed.');
require __DIR__.'/../../restore_jobs.php';
$db=ttr_db();$original=ttr_db_rows($db);
$fixture=[
 'tt_operation_records'=>[['storage_key'=>'tt99restorefixture','payload'=>'[{"fixture":"restored"}]','version'=>2,'updated_at'=>'2026-10-26 00:00:00.000000','updated_by'=>'Fixture','updated_by_user'=>1,'updated_by_module'=>'Mill']],
 'tt_operation_history'=>[['id'=>1,'storage_key'=>'tt99restorefixture','version'=>2,'payload_sha256'=>str_repeat('b',64),'updated_at'=>'2026-10-26 00:00:00.000000','updated_by'=>'Fixture','updated_by_user'=>1,'updated_by_module'=>'Mill']],
 'tt_export_documents'=>[['id'=>str_repeat('a',32),'contract_ref'=>'FIXTURE','lot_ref'=>'LOT1','category'=>'fixture','original_name'=>'Original.pdf','stored_name'=>str_repeat('a',32).'.pdf','mime_type'=>'application/pdf','file_size'=>10,'uploaded_at'=>'2026-10-26 00:00:00.000000','uploaded_by'=>'Fixture','uploaded_by_user'=>1,'deleted_at'=>null]],
];
try{
 ttr_db_apply($db,$fixture);$actual=ttr_db_rows($db);foreach($fixture as$t=>$rows)if(ttr_db_signature($actual[$t])!==ttr_db_signature($rows))throw new RuntimeException('Actual database did not restore exactly.');
 $bad=$fixture;$bad['tt_export_documents'][]=$fixture['tt_export_documents'][0];$bad['tt_operation_records'][0]['payload']='[{"must":"roll-back"}]';
 $failed=false;try{ttr_db_apply($db,$bad);}catch(Throwable){$failed=true;}if(!$failed)throw new RuntimeException('Duplicate document unexpectedly committed.');
 $actual=ttr_db_rows($db);foreach($fixture as$t=>$rows)if(ttr_db_signature($actual[$t])!==ttr_db_signature($rows))throw new RuntimeException('Database transaction left a partial restore.');
 ttr_db_apply($db,$fixture); // Retry after a lost successful response is idempotent.
 $bad=$fixture;unset($bad['tt_operation_history']);$failed=false;try{ttr_db_apply($db,$bad);}catch(Throwable){$failed=true;}if(!$failed)throw new RuntimeException('Incomplete database snapshot accepted.');
 echo "WEBSITE_RESTORE_REAL_MYSQL_ATOMIC_VERIFY_ROLLBACK_OK\n";
}finally{ttr_db_apply($db,$original);}
