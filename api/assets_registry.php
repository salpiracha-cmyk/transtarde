<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/assets_registry_core.php';
require_once __DIR__.'/accounts_bank_payment.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function far_out(array $v,int $status=200):never {http_response_code($status);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $u=tt_require_login();$director=($_GET['scope']??'')==='directors';$module=$director?'Directors':'Accounts';
    if(!far_permission($u,$module,'View'))far_out(['ok'=>false,'error'=>'Assets permission required.'],403);
    $body=$_SERVER['REQUEST_METHOD']==='POST'?json_decode(file_get_contents('php://input')?:'{}',true):[];
    if(!is_array($body))far_out(['ok'=>false,'error'=>'Invalid request.'],422);
    $e=strtoupper((string)($body['entity']??$_GET['entity']??''));if(!in_array($e,['TTI','BRM','TG'],true))far_out(['ok'=>false,'error'=>'Select company books.'],422);
    if(!$director&&!tt_user_can_access_entity($u,$e,'View'))far_out(['ok'=>false,'error'=>'Company access denied.'],403);
    $write=$_SERVER['REQUEST_METHOD']==='POST';if(!$write&&$_SERVER['REQUEST_METHOD']!=='GET')far_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    if($write){if(!tt_verify_csrf((string)($body['csrf']??'')))far_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
        if(!far_permission($u,$module,'Create')&&!far_permission($u,$module,'Edit')&&!far_permission($u,$module,'Approve'))far_out(['ok'=>false,'error'=>'Assets write permission required.'],403);
        if(!$director&&!tt_user_can_access_entity($u,$e,'Create')&&!tt_user_can_access_entity($u,$e,'Edit')&&!tt_user_can_access_entity($u,$e,'Approve'))far_out(['ok'=>false,'error'=>'Company write permission required.'],403);
    }
    tt_ensure_data_dir();$file=TT_DATA_DIR.'/accounts.json';$h=fopen($file,'c+');if($h===false||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Storage unavailable.');
    try {
        rewind($h);$raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];if(!is_array($s))throw new RuntimeException('Invalid Accounts storage.');$result=[];
        if($write){$key=(string)($body['requestKey']??'');if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key))throw new DomainException('Refresh the form before saving.');
            $identity=$module.'|'.$e.'|'.(string)($u['id']??0).'|'.$key;$fingerprint=hash('sha256',json_encode($body));$saved=$s['assetRequestKeys'][$identity]??null;
            if($saved){if($saved['fingerprint']!==$fingerprint)throw new DomainException('This request has already been used. Reopen the form.');$result=$saved['result'];}
            else {
                $action=(string)($body['action']??'');
                if($action==='register')$result=far_register($s,$e,$body,$u);
                elseif($action==='location')$result=far_location_action($s,$body);
                elseif(in_array($action,['payment','reopen','amend'],true)){
                    $id=(string)($body['assetId']??'');$a=$s['managedAssets'][$id]??null;
                    if(!is_array($a)||$a['entity']!==$e||!far_visible($a,$director))far_out(['ok'=>false,'error'=>'Asset was not found.'],404);
                    if((int)($body['version']??-1)!==$a['version'])throw new DomainException('This asset changed. Refresh before saving.');
                    if($action==='payment'){$p=far_payment($s,$a,$body,$u);$result=['postId'=>$p['journalId']?:$p['id'],'message'=>$p['historicalOnly']?'PAYMENT HISTORY RECORDED — NO LEDGER ENTRY':'ASSET PAYMENT POSTED'];}
                    elseif($action==='reopen'){
                        if(!$director)far_out(['ok'=>false,'error'=>'A director must reopen a restricted asset.'],403);
                        $reason=far_text($body['reason']??'',500);if($reason==='')throw new DomainException('Enter a reason to reopen for Accounts.');$a['reopened']=true;$a['reopenReason']=$reason;$a['version']++;$result=['message'=>'OPEN FOR ACCOUNTS AMENDMENT'];
                    }else {
                        $reason=far_text($body['reason']??'',500);if($reason==='')throw new DomainException('Enter a valid amendment reason.');
                        foreach(['name','address','unitNo','seller','registrationNo','chassisNo','engineNo','privateNotes','documentReferences'] as $field)if(array_key_exists($field,$body))$a[$field]=far_text($body[$field],2000);
                        if($a['name']===''||($a['type']==='PROPERTY'&&($a['address']===''||$a['unitNo']==='')))throw new DomainException('Complete the asset name and property details.');
                        $a['amendments'][]=['date'=>gmdate('c'),'reason'=>$reason,'by'=>(string)($u['full_name']??$u['username']??'Accounts')];$a['version']++;$a['reopened']=false;$result=['postId'=>$id,'message'=>'ASSET RECORD AMENDED'];
                    }$s['managedAssets'][$id]=$a;
                }else throw new DomainException('Unknown asset action.');
                $s['assetRequestKeys'][$identity]=['fingerprint'=>$fingerprint,'result'=>$result];$s['revision']=(int)($s['revision']??0)+1;
                rewind($h);if(!ftruncate($h,0)||fwrite($h,json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false||!fflush($h))throw new RuntimeException('Save failed.');
            }
        }
        $payload=far_payload($s,$e,$director);$requested=(string)($_GET['assetId']??'');
        if($requested!==''){$a=$s['managedAssets'][$requested]??null;if(!is_array($a)||$a['entity']!==$e||!far_visible($a,$director))far_out(['ok'=>false,'error'=>'Asset was not found.'],404);$payload['asset']=far_view($a,$director);}
        $payload['banks']=far_banks($s,$e);$payload['result']=$result;
    } finally {flock($h,LOCK_UN);fclose($h);}
    far_out($payload);
}catch(DomainException $ex){far_out(['ok'=>false,'error'=>$ex->getMessage()],422);}catch(Throwable $ex){error_log('Assets registry: '.$ex->getMessage());far_out(['ok'=>false,'error'=>'Asset could not be saved or loaded.'],500);}
