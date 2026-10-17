<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/mill_liabilities_core.php';
require_once __DIR__.'/mill_liabilities_store.php';
define('TT_SETTLEMENTS_FUNCTIONS_ONLY',true);
require_once __DIR__.'/supplier_settlements.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mls_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $user=tt_require_login();if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') mls_out(['ok'=>false,'error'=>'Read only endpoint.'],405);
    $labour=tt_user_can_module_action($user,'Mill','labour','View');$reprocess=tt_user_can_module_action($user,'Mill','reprocessbill','View');
    if (!tt_user_can_open_module($user,'Mill')||(!$labour&&!$reprocess)) mls_out(['ok'=>false,'error'=>'Mill bill View permission required.'],403);
    $entity=(string)($_GET['entity']??'');$mill=trim((string)($_GET['millName']??''));
    if (!in_array($entity,['TTI','BRM'],true)||$mill==='') mls_out(['ok'=>false,'error'=>'Choose the saved company and mill.'],422);
    $accountGrants=(array)($user['permissions']['Accounts']??[]);$entityScoped=false;foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$accountGrants))$entityScoped=true;
    if ($entityScoped&&!tt_user_can_access_entity($user,$entity,'View')) mls_out(['ok'=>false,'error'=>'Company View permission required.'],403);
    $scope=ml_name((string)($user['location']??''));
    if (($user['role']??'')!=='Super Admin'&&!in_array($scope,['','all locations','all authorized locations'],true)&&$scope!==ml_name($mill)) mls_out(['ok'=>false,'error'=>'This mill is outside your assigned location.'],403);
    $masters=tt_list_masters();$matches=[];
    foreach ((array)($masters['mills']??[]) as $row) {
        $v=array_values((array)($row['values']??[]));if (ml_name((string)($v[0]??''))!==ml_name($mill)||strcasecmp((string)($v[5]??''),'Active')!==0) continue;
        $owner=strtoupper(trim((string)($v[7]??'')));if ($owner===''&&($row['id']??'')==='mills-1'&&ml_name($mill)==='tti rice mills') $owner='TTI';
        if (($v[2]??'')==='Own Mill'&&$owner!==$entity) continue;
        if (!in_array($v[2]??'',['Own Mill','External Mill','Reprocessing Mill'],true)) continue;
        $matches[]=['id'=>(string)$row['id'],'name'=>(string)$v[0],'type'=>(string)$v[2]];
    }
    if (count($matches)!==1) mls_out(['ok'=>false,'error'=>'Resolve one active company mill in Mills & Locations Master.'],403);
    if (function_exists('tt_release_read_session')) tt_release_read_session();
    $rows=ml_with_sources(static function($values)use($entity,$mill,$labour,$reprocess,$masters):array {
        $store=ml_read_accounts();$result=[];$policies=ml_kinds();
        foreach ((array)($store['millLiabilities']??[]) as $record) {
            if (!is_array($record)||($record['entity']??'')!==$entity) continue;
            $original=(array)($record['sourceSnapshot']??[]);
            if (ml_name((string)($original['millName']??$original['mill']??''))!==ml_name($mill)) continue;
            $kind=null;foreach ($policies as $name=>$policy) if ($policy['collection']===($record['sourceCollection']??'')) $kind=$name;
            if (!$kind||($kind==='REPROCESSING'?!$reprocess:!$labour)) continue;
            $journal=$store['journals'][$record['journalId']??'']??null;$verified=is_array($journal)&&($journal['status']??'')==='Posted'&&($journal['meta']['millSourceKey']??'')===($record['key']??'');
            $current=null;try {$current=ml_source($values,$masters,$kind,(string)$record['sourceRecordId'],$entity);}catch (DomainException $e) {}
            $changed=!$current||!hash_equals((string)$record['fingerprint'],$current['fingerprint']);
            $bill=$store['supplierBills'][$record['billId']??'']??null;$outstanding=null;
            if (is_array($bill)&&($bill['entity']??'')===$entity) $outstanding=ss_component_capacity($store,$entity,$bill,(array)($bill['receiptAllocations'][0]??[]))['total'];
            $status=$verified?'Posted':'Posting unverified';
            if (($record['status']??'')==='Reversed') {
                $reverse=$store['journals'][$record['reversalJournalId']??'']??null;
                $status=is_array($reverse)&&($reverse['reversalOf']??'')===($record['journalId']??'')?'Reversed':'Reversal unverified';
            }
            $result[]=['collection'=>$record['sourceCollection'],'sourceId'=>(string)$record['sourceRecordId'],'entity'=>$entity,'millName'=>$mill,'status'=>$status,'postId'=>(string)($journal['meta']['publicPostId']??$record['journalId']??''),'reversalPostId'=>(string)($record['reversalJournalId']??''),'postedAmount'=>(float)$record['amount'],'outstanding'=>$outstanding,'sourceNeedsReview'=>$changed];
        }
        return $result;
    });
    mls_out(['ok'=>true,'entity'=>$entity,'millName'=>$mill,'rows'=>$rows,'checkedAt'=>gmdate('c')]);
} catch (Throwable $e) {error_log('Mill liability status: '.$e->getMessage());mls_out(['ok'=>false,'error'=>'Accounts status could not be verified. No posting is assumed.'],500);}
