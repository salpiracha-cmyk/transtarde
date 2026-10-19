<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/mill_liabilities_core.php';
require_once __DIR__.'/mill_liabilities_store.php';
require_once __DIR__.'/accounts_posting_preview.php';
define('TT_SETTLEMENTS_FUNCTIONS_ONLY',true);
require_once __DIR__.'/supplier_settlements.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');header('X-TT-Posting-Preview: available');

function ml_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function ml_location_allowed(array $user,string $name): bool {
    $scope=ml_name((string)($user['location']??''));
    return ($user['role']??'')==='Super Admin'||in_array($scope,['','all locations','all authorized locations'],true)||$scope===ml_name($name);
}
function ml_permission(array $user,string $entity,string $action): bool {
    return tt_user_can_open_module($user,'Accounts')&&tt_user_can_module_action($user,'Accounts','expenses',$action)&&tt_user_can_access_entity($user,$entity,$action);
}
function ml_review_rows(array $values,array $masters,array $store,string $entity): array {
    $rows=[];
    foreach (ml_kinds() as $kind=>$policy) foreach (ml_rows($values,$policy['collection']) as $r) {
        if (!is_array($r)||($r['entity']??'')!==$entity) continue;
        $summary=['commodity'=>(string)($r['commodity']??''),'kind'=>$kind,'sourceId'=>(string)($r['id']??''),'reference'=>(string)($r['ref']??$r['billNo']??''),'date'=>(string)($r['date']??''),'from'=>(string)($r['readFrom']??$r['from']??''),'to'=>(string)($r['readTo']??$r['till']??''),'millName'=>(string)($r['millName']??$r['mill']??''),'party'=>(string)($r['party']??$r['contractor']??$r['mill']??''),'amount'=>$r['amount']??null];
        $summary['key']='MILL|'.$entity.'|'.($r['millId']??'').'|'.$policy['collection'].'|'.$summary['sourceId'];
        $summary['posted']=$store['millLiabilities'][$summary['key']]??null;
        if (is_array($summary['posted'])) unset($summary['posted']['sourceSnapshot'],$summary['posted']['versions']);
        try {
            $source=ml_source($values,$masters,$kind,$summary['sourceId'],$entity);unset($source['original']);$summary=array_merge($summary,$source);
            $summary['posted']=$store['millLiabilities'][$source['key']]??null;
            if (is_array($summary['posted'])) unset($summary['posted']['sourceSnapshot'],$summary['posted']['versions']);
            $summary['blocker']='';
        } catch (DomainException $e) {$summary['blocker']=$e->getMessage();}
        $rows[]=$summary;
    }
    $seen=array_fill_keys(array_column($rows,'key'),true);
    foreach ((array)($store['millLiabilities']??[]) as $record) {
        if (!is_array($record)||($record['entity']??'')!==$entity||isset($seen[$record['key']??''])) continue;
        $kind=null;foreach(ml_kinds() as $name=>$policy)if($policy['collection']===($record['sourceCollection']??''))$kind=$name;
        if (!$kind) continue;$r=(array)($record['sourceSnapshot']??[]);$posted=$record;unset($posted['sourceSnapshot'],$posted['versions']);
        $rows[]=['commodity'=>(string)($r['commodity']??''),'kind'=>$kind,'sourceId'=>(string)$record['sourceRecordId'],'key'=>$record['key'],'fingerprint'=>'','reference'=>(string)($r['ref']??$r['billNo']??''),'date'=>(string)($r['date']??''),'from'=>(string)($r['readFrom']??$r['from']??''),'to'=>(string)($r['readTo']??$r['till']??''),'millName'=>(string)($r['millName']??$r['mill']??''),'party'=>(string)($r['party']??$r['contractor']??$r['mill']??''),'amount'=>$record['amount'],'posted'=>$posted,'blocker'=>'The original Mill source is missing. The preserved posting and source snapshot require review.'];
    }
    foreach ($rows as &$summary) {
        if (is_array($summary['posted'])) {
            $j=$store['journals'][$summary['posted']['journalId']??'']??null;
            if (!is_array($j)||($j['status']??'')!=='Posted'||($j['meta']['millSourceKey']??'')!==$summary['key']) {$summary['posted']['status']='Posting unverified';$summary['blocker']='The linked journal could not be verified. Reconcile its original posting.';}
        }
        if (is_array($summary['posted'])&&($summary['posted']['entity']??'')===$entity) {
            $canonical=$store['supplierBills'][$summary['posted']['billId']]??null;
            if (is_array($canonical)&&($canonical['entity']??'')===$entity) {
                $allocation=$canonical['receiptAllocations'][0]??[];$capacity=ss_component_capacity($store,$entity,$canonical,$allocation);
                $summary['posted']['outstandingAmount']=$capacity['total'];
                $summary['posted']['settledAmount']=ss_previous_allocation_components($store,$entity,$canonical['id'],(string)($allocation['sourceKey']??''))['total'];
            } else {$summary['blocker']='The linked canonical supplier bill is missing. Reconcile its original posting.';}
        }
    }unset($summary);
    usort($rows,static fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcmp($a['reference'],$b['reference']));return $rows;
}

try {
    $user=tt_require_login();$method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='GET') {
        $entity=(string)($_GET['entity']??'');
        if (!in_array($entity,['TTI','BRM'],true)||!ml_permission($user,$entity,'View')) ml_out(['ok'=>false,'error'=>'Accounts expense review and company View permission required.'],403);
        if (function_exists('tt_release_read_session')) tt_release_read_session();
        $result=ml_with_sources(static fn($values)=>array_values(array_filter(ml_review_rows($values,tt_list_masters(),ml_read_accounts(),$entity),static fn($r)=>ml_location_allowed($user,$r['millName']))));
        ml_out(['ok'=>true,'entity'=>$entity,'rows'=>$result,'canPost'=>ml_permission($user,$entity,'Create'),'canCorrect'=>ml_permission($user,$entity,'Edit')&&ml_permission($user,$entity,'Approve')]);
    }
    if ($method!=='POST') ml_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw=function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input');
    if (strlen((string)$raw)>32768) ml_out(['ok'=>false,'error'=>'Request too large.'],413);
    $body=json_decode((string)$raw,true);
    if (!is_array($body)||!tt_verify_csrf((string)($body['csrf']??''))) ml_out(['ok'=>false,'error'=>'Refresh your session before posting.'],419);
    $entity=(string)($body['entity']??'');$action=(string)($body['action']??'');
    if (!in_array($entity,['TTI','BRM'],true)||!in_array($action,['post','reverse','replace'],true)) ml_out(['ok'=>false,'error'=>'Choose a supported company and action.'],422);
    if (!ml_permission($user,$entity,$action==='post'?'Create':'Edit')||($action!=='post'&&!ml_permission($user,$entity,'Approve'))) ml_out(['ok'=>false,'error'=>'Accounts expense and company permissions are required for this action.'],403);
    $requestKey=(string)($body['requestKey']??'');
    if (!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$requestKey)) ml_out(['ok'=>false,'error'=>'A stable save request identity is required.'],422);
    $requestBody=tt_preview_request_body($body);unset($requestBody['csrf']);$requestFingerprint=ml_fingerprint($requestBody);
    $masters=tt_list_masters();
    $result=ml_with_sources(static function($values)use($body,$entity,$action,$masters,$user,$requestKey,$requestFingerprint):array {
        $source=$action==='reverse'?null:ml_source($values,$masters,(string)($body['kind']??''),(string)($body['sourceId']??''),$entity);
        if ($source&&!ml_location_allowed($user,$source['millName'])) throw new DomainException('This bill belongs to another authorised location.');
        // A valid explicit post is the first point at which storage may be created.
        $preview=!empty($body['preview']);$path=TT_DATA_DIR.'/accounts.json';
        if(!$preview)tt_ensure_data_dir();$h=$preview?(is_file($path)?fopen($path,'rb'):null):fopen($path,'c+');
        if(($h===false)||($h&&!flock($h,$preview?LOCK_SH:LOCK_EX)))throw new RuntimeException('Accounts storage unavailable.');
        try {
            $raw=$h?stream_get_contents($h):'';$store=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if (!is_array($store)) throw new RuntimeException('Invalid Accounts store.');
            $requestId=$entity.'|'.$requestKey;$priorRequest=$store['millLiabilityRequests'][$requestId]??null;
            if (is_array($priorRequest)) {
                if (!hash_equals((string)$priorRequest['fingerprint'],$requestFingerprint)) throw new DomainException('This save identity was already used for another request.');
                return $priorRequest['result']+['replayed'=>true];
            }
            $before=$store;$now=gmdate('c');$reason=trim((string)($body['reason']??''));
            if ($action==='post') {
                $jid=tt_next_post_id((array)($store['journals']??[]),'Accounts','Journal');
                $out=ml_post($store,$source,$user,(string)($body['fingerprint']??''),$jid,$now);
            } else {
                $key=$source['key']??(string)($body['sourceKey']??'');$existing=$store['millLiabilities'][$key]??null;
                if (is_array($existing)&&!ml_location_allowed($user,(string)($existing['sourceSnapshot']['millName']??$existing['sourceSnapshot']['mill']??''))) throw new DomainException('This liability belongs to another location.');
                if (!is_array($existing)||($existing['entity']??'')!==$entity) throw new DomainException('The linked liability was not found in this company.');
                if (!hash_equals((string)($existing['fingerprint']??''),(string)($body['postedFingerprint']??''))) throw new DomainException('The posted liability changed after review. Reopen it.');
                if ($action==='replace'&&hash_equals($existing['fingerprint'],$source['fingerprint'])) throw new DomainException('The source has not changed. No replacement is required.');
                $jid=tt_next_post_id((array)($store['journals']??[]),'Accounts','Journal');
                $out=ml_reverse($store,$key,$entity,$user,$reason,$jid,(string)($body['correctionDate']??''),$now);
                if ($action==='replace') {
                    $replacement=tt_next_post_id((array)($store['journals']??[]),'Accounts','Journal');
                    $out=ml_post($store,$source,$user,(string)($body['fingerprint']??''),$replacement,$now);
                }
            }
            tt_posting_preview_exit($body,$store,array_keys((array)($before['journals']??[])));
            $store['millLiabilityRequests'][$requestId]=['fingerprint'=>$requestFingerprint,'result'=>$out+['revision'=>(int)($store['revision']??0)+1],'at'=>$now,'userId'=>(int)($user['id']??0)];
            if ($store!==$before) {
                $store['revision']=(int)($store['revision']??0)+1;$encoded=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                rewind($h);if (!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h)) throw new RuntimeException('Accounts liability could not be saved.');
            }
            return $out+['revision'=>(int)($store['revision']??0)];
        } finally {if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
    });
    ml_out(['ok'=>true,'result'=>$result]);
} catch (DomainException|InvalidArgumentException $e) {ml_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch (Throwable $e) {error_log('Mill liability: '.$e->getMessage());ml_out(['ok'=>false,'error'=>'Mill liability review is temporarily unavailable.'],500);}
