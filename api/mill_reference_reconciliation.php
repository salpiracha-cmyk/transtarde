<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/mill_reference_core.php';
require_once __DIR__.'/mill_liabilities_store.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mref_out(array $data,int $status=200):never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function mref_allowed(array $u,string $entity,string $action):bool {return tt_user_can_open_module($u,'Accounts')&&tt_user_can_module_action($u,'Accounts','expenses',$action)&&tt_user_can_access_entity($u,$entity,$action);}
function mref_location_allowed(array $u,string $mill):bool {$scope=ml_name((string)($u['location']??''));return ($u['role']??'')==='Super Admin'||in_array($scope,['','all locations','all authorized locations'],true)||$scope===ml_name($mill);}
function mref_rows(array $books,array $values,array $masters,string $entity,array $u):array {
    $rows=[];$seen=[];
    foreach(mref_policies() as $kind=>$collection)foreach(ml_rows($values,$collection) as $r){
        if(!is_array($r)||($r['entity']??'')!==$entity||!mref_location_allowed($u,(string)($r['millName']??'')))continue;
        $id=(string)($r['id']??'');$key=$entity.'|'.$collection.'|'.$id;$seen[$key]=true;
        $row=['key'=>$key,'kind'=>$kind,'sourceId'=>$id,'millName'=>(string)($r['millName']??''),'date'=>(string)($r['date']??''),'amount'=>$r['amount']??null,'person'=>(string)($r['person']??''),'reference'=>(string)($r['ref']??''),'status'=>'Needs source review','choices'=>[]];
        try{$source=mref_source($values,$masters,$entity,$kind,$id);$status=mref_status($books,$source);$row=array_replace($row,array_diff_key($source,['snapshot'=>true]),$status);$row['choices']=mref_choices($books,$source);if(isset($row['link']))$row['link']=array_intersect_key((array)$row['link'],array_flip(['status','reason','by','at','targetId','journalId']));}
        catch(DomainException $e){$row['blocker']=$e->getMessage();}
        $row['linkFingerprint']=ml_fingerprint($books['millReferenceLinks'][$key]??[]);$rows[]=$row;
    }
    foreach((array)($books['millReferenceLinks']??[]) as $key=>$link)if(is_array($link)&&($link['entity']??'')===$entity&&($link['status']??'')==='Linked'&&!isset($seen[$key])&&mref_location_allowed($u,(string)($link['millName']??'')))$rows[]=['key'=>$key,'kind'=>$link['kind'],'sourceId'=>$link['sourceId'],'millName'=>$link['millName'],'status'=>'Needs source review','blocker'=>'The original reference is no longer available. Preserve and reconcile its source history.','choices'=>[]];
    return $rows;
}
try{
    $user=tt_require_login();$method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true))mref_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=[];if($method==='POST'){$raw=function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input');if(strlen((string)$raw)>16384)mref_out(['ok'=>false,'error'=>'Request too large.'],413);$body=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))mref_out(['ok'=>false,'error'=>'Refresh your session before saving.'],419);}
    $entity=(string)($body['entity']??$_GET['entity']??'');if(!in_array($entity,['TTI','BRM'],true))mref_out(['ok'=>false,'error'=>'Choose TTI or BRM.'],422);
    if(!mref_allowed($user,$entity,'View')||($method==='POST'&&!mref_allowed($user,$entity,'Edit')))mref_out(['ok'=>false,'error'=>'Accounts expense and company permissions required.'],403);
    if($method==='GET'&&function_exists('tt_release_read_session'))tt_release_read_session();$masters=tt_list_masters();
    $result=ml_with_sources(static function($values)use($method,$entity,$user,$body,$masters):array {
        $h=@fopen(TT_DATA_DIR.'/accounts.json',$method==='POST'?'r+b':'rb');if(!$h)throw new RuntimeException('Accounts source unavailable.');
        try{if(!flock($h,$method==='POST'?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts source busy.');$books=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($books)||($books!==[]&&array_is_list($books)))throw new RuntimeException('Invalid Accounts source.');
            $result=null;if($method==='POST'){$source=mref_source($values,$masters,$entity,(string)($body['kind']??''),(string)($body['sourceId']??''));if(!mref_location_allowed($user,$source['millName']))throw new DomainException('This reference is outside your assigned mill.');$result=mref_apply($books,$source,$body,$user);if(!$result['duplicate']){$encoded=json_encode($books,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);rewind($h);if(fwrite($h,$encoded)!==strlen($encoded)||!ftruncate($h,strlen($encoded))||!fflush($h))throw new RuntimeException('Reconciliation save was not acknowledged.');}}
            return ['ok'=>true,'entity'=>$entity,'rows'=>mref_rows($books,$values,$masters,$entity,$user),'canEdit'=>mref_allowed($user,$entity,'Edit'),'revision'=>(int)($books['revision']??0),'result'=>$result];
        }finally{flock($h,LOCK_UN);fclose($h);}
    },array_values(mref_policies()));mref_out($result);
}catch(DomainException|JsonException $e){mref_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Mill reference reconciliation: '.$e->getMessage());mref_out(['ok'=>false,'error'=>'Reconciliation could not be verified. No new monetary posting is made by this workflow.'],500);}
