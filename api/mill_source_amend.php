<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/mill_source_amend_core.php';
require_once __DIR__.'/mill_source_amend_store.php';
require_once __DIR__.'/mill_liabilities_store.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function msa_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
try{
    $user=tt_require_login();$method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $collection=(string)($_GET['collection']??'');$kind=msa_kind($collection);$id=(string)($_GET['sourceId']??'');$entity=(string)($_GET['entity']??'');
        if(function_exists('tt_release_read_session'))tt_release_read_session();
        $result=ml_with_sources(static function($values)use($user,$collection,$kind,$id,$entity){
            $rows=array_values(array_filter(ml_rows($values,$collection),static fn($r)=>is_array($r)&&(string)($r['id']??'')===$id));
            if(count($rows)!==1||($rows[0]['entity']??'')!==$entity)throw new DomainException('The saved bill was not found in this company.');
            $row=$rows[0];msa_scope($user,$row,$kind,'View');$hold='';$canEdit=true;
            try{msa_scope($user,$row,$kind,'Edit');msa_dependencies(ml_read_accounts(),$row,$collection);}catch(DomainException $e){$hold=$e->getMessage();$canEdit=false;}
            return ['record'=>$row,'fingerprint'=>ml_fingerprint($row),'canEdit'=>$canEdit,'hold'=>$hold,'arrivals'=>$kind==='REPROCESSING'?msa_reprocess_arrivals($values,$row,(string)($_GET['from']??$row['from']),(string)($_GET['till']??$row['till'])):null];
        },$kind==='REPROCESSING'?['tt30slips']:[]);msa_out(['ok'=>true,'collection'=>$collection,'entity'=>$entity]+$result);
    }
    if($method!=='POST')msa_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw=(function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'';
    if(strlen($raw)>131072)msa_out(['ok'=>false,'error'=>'Amendment too large.'],413);
    $body=json_decode($raw,true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))msa_out(['ok'=>false,'error'=>'Refresh the session before amending.'],419);
    $collection=(string)($body['collection']??'');msa_kind($collection);$masters=tt_list_masters();
    $result=msa_write($collection,$user,static fn($values,$books,$now)=>msa_amend($values,$masters,$books,$user,$body,$now));
    msa_out(['ok'=>true,'collection'=>$collection,'entity'=>$result['record']['entity'],'result'=>$result]);
}catch(DomainException|InvalidArgumentException $e){msa_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Mill source amendment: '.$e->getMessage());msa_out(['ok'=>false,'error'=>'The source amendment could not be confirmed. Retain the draft and retry the same action.'],500);}
