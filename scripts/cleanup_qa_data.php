<?php
declare(strict_types=1);

/** Remove only unmistakably labelled QA/TEST/DUMMY records. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';

const QA_EXPORT_KEY = 'transtrade_export_v3_operational';
const QA_ACCOUNTS_PREFIX = 'TEST-DUMMY-ACCOUNTS-BULK-';

function qa_text(mixed $value): string {
    if (is_scalar($value) || $value === null) return (string)$value;
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
}
function qa_marked(mixed $value): bool {
    return (bool)preg_match('/(^|[\s\/_-])(QA|TEST|DUMMY|DEMO|BULK)([\s\/_-]|$)/i', qa_text($value));
}
function qa_filter(array $rows, callable $remove, int &$count): array {
    $kept=[]; foreach ($rows as $key=>$row) { if ($remove($row,$key)) { $count++; continue; } $kept[$key]=$row; }
    return array_is_list($rows) ? array_values($kept) : $kept;
}
function qa_write_json(string $path, array $data): void {
    $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
    if (file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('QA cleanup write failed.');
    @chmod($tmp,0600); if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('QA cleanup replace failed.');}
}

$apply=in_array('--apply',$argv,true);
$summary=['ok'=>true,'applied'=>false,'exports'=>[],'shared'=>[],'accounts'=>[],'admin'=>[]];
if(!$apply){$summary['reason']='dry-run';echo json_encode($summary).PHP_EOL;exit(0);}
$summary['safetySnapshot']=tt_create_server_snapshot('pre-qa-cleanup');

$operationsPath=tt_operations_file();
$store=is_file($operationsPath)?json_decode((string)file_get_contents($operationsPath),true):null;
$store=is_array($store)?$store:['revision'=>0,'values'=>[],'meta'=>[]];
$rootRaw=(string)($store['values'][QA_EXPORT_KEY]??'');
$root=$rootRaw!==''?json_decode($rootRaw,true):null;
if(is_array($root)){
    $qaRefs=[];$qaIds=[];$qaCustomerIds=[];
    foreach((array)($root['contracts']??[]) as $row){if(!is_array($row))continue;$ref=(string)($row['ref']??'');if(qa_marked($ref)||qa_marked($row['customerName']??'')){$qaRefs[$ref]=true;if(!empty($row['id']))$qaIds[(string)$row['id']]=true;if(!empty($row['customerId']))$qaCustomerIds[(string)$row['customerId']]=true;}}
    foreach((array)($root['shipments']??[]) as $row){if(!is_array($row))continue;$ref=(string)($row['contractRef']??'');if(qa_marked($ref)||qa_marked($row['lotId']??'')||qa_marked($row['brand']??'')){$qaRefs[$ref]=true;if(!empty($row['id']))$qaIds[(string)$row['id']]=true;}}
    foreach(['contracts','shipments'] as $key){$n=0;$root[$key]=qa_filter((array)($root[$key]??[]),static fn($row)=>is_array($row)&&(isset($qaRefs[(string)($row[$key==='contracts'?'ref':'contractRef']??'')])||isset($qaIds[(string)($row['id']??'')])), $n);$summary['exports'][$key]=$n;}
    foreach(['customers','suppliers','fi','accountsReceipts','alerts','audits'] as $key){$n=0;$root[$key]=qa_filter((array)($root[$key]??[]),static function($row)use($key,$qaCustomerIds){if(!is_array($row))return false;if($key==='customers'&&isset($qaCustomerIds[(string)($row['id']??'')]))return true;return qa_marked($row);},$n);$summary['exports'][$key]=$n;}
    $root['millSync']=(array)($root['millSync']??[]);
    foreach(['newExportBags','productionInstructions','exportLoading'] as $key){$n=0;$root['millSync'][$key]=qa_filter((array)($root['millSync'][$key]??[]),static fn($row)=>is_array($row)&&(isset($qaRefs[(string)($row['contractRef']??'')])||qa_marked($row)),$n);$summary['exports']['millSync.'.$key]=$n;}
    $tombstones=(array)($root['deletedShipments']??[]);
    foreach(array_keys(array_filter($qaRefs,static fn($v,$k)=>$k!=='',ARRAY_FILTER_USE_BOTH)) as $ref){$tombstones[]=['id'=>'QA-CLEAN-'.substr(hash('sha256',$ref),0,16),'contractRef'=>$ref,'deletedAt'=>gmdate('c'),'qaCleanup'=>true];}
    $root['deletedShipments']=$tombstones;
    $store['values'][QA_EXPORT_KEY]=json_encode($root,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $store['revision']=(int)($store['revision']??0)+1;$version=(int)($store['meta'][QA_EXPORT_KEY]['version']??0)+1;
    $store['meta'][QA_EXPORT_KEY]=['version'=>$version,'updatedAt'=>gmdate('c'),'updatedBy'=>'QA cleanup','userId'=>0,'module'=>'Super Admin'];
}
foreach(['tt30bags','tt30prodinst','tt30ship','tt32exportsync','tt39bridgequarantine','tt35exmill','tt35exload'] as $key){if(!isset($store['values'][$key]))continue;$rows=json_decode((string)$store['values'][$key],true);if(!is_array($rows))continue;$n=0;$rows=qa_filter($rows,static fn($row)=>qa_marked($row),$n);$summary['shared'][$key]=$n;if($n){$store['values'][$key]=json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$store['revision']=(int)($store['revision']??0)+1;$store['meta'][$key]=['version'=>(int)($store['meta'][$key]['version']??0)+1,'updatedAt'=>gmdate('c'),'updatedBy'=>'QA cleanup','userId'=>0,'module'=>'Super Admin'];}}
qa_write_json($operationsPath,$store);

$accountsPath=TT_DATA_DIR.'/accounts.json';
if(is_file($accountsPath)){$accounts=json_decode((string)file_get_contents($accountsPath),true);if(is_array($accounts)){$journalIds=[];foreach((array)($accounts['journals']??[]) as $id=>$row){if(is_array($row)&&(($row['meta']['testDummy']??false)===true)&&str_contains(qa_text($row),QA_ACCOUNTS_PREFIX))$journalIds[(string)$id]=true;}foreach(['journals','events','postingIdentities'] as $key){$n=0;$accounts[$key]=qa_filter((array)($accounts[$key]??[]),static function($row,$id)use($key,$journalIds){if($key==='journals')return isset($journalIds[(string)$id])||(is_array($row)&&isset($journalIds[(string)($row['reversalOf']??'')]));return is_array($row)&&isset($journalIds[(string)($row['journalId']??'')]);},$n);$summary['accounts'][$key]=$n;}if(array_sum($summary['accounts'])>0){$accounts['revision']=(int)($accounts['revision']??0)+1;qa_write_json($accountsPath,$accounts);}}}

$authPath=TT_STORE_FILE;$auth=is_file($authPath)?json_decode((string)file_get_contents($authPath),true):null;
if(is_array($auth)){$n=0;$auth['audit']=qa_filter((array)($auth['audit']??[]),static fn($row)=>qa_marked($row),$n);$summary['admin']['audit']=$n;$n=0;$auth['masters']['parties']=qa_filter((array)($auth['masters']['parties']??[]),static function($row){$text=qa_text($row);return qa_marked($row)||str_contains(strtolower($text),'sample overseas buyer');},$n);$summary['admin']['sampleParties']=$n;qa_write_json($authPath,$auth);}

$summary['applied']=array_sum($summary['exports'])+array_sum($summary['shared'])+array_sum($summary['accounts'])+array_sum($summary['admin'])>0;
echo json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
