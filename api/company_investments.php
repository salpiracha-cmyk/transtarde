<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/company_investments_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function inv_out(array $v,int $status=200):never {http_response_code($status);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $u=tt_require_login();$director=($_GET['scope']??'')==='directors';$module=$director?'Directors':'Accounts';if(!far_permission($u,$module,'View'))inv_out(['ok'=>false,'error'=>'Assets permission required.'],403);
    $write=$_SERVER['REQUEST_METHOD']==='POST';if(!$write&&$_SERVER['REQUEST_METHOD']!=='GET')inv_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $b=$write?json_decode(file_get_contents('php://input')?:'{}',true,512,JSON_THROW_ON_ERROR):[];if(!is_array($b))throw new DomainException('Invalid request.');$e=strtoupper((string)($b['entity']??$_GET['entity']??''));if(!in_array($e,['TTI','BRM','TG'],true))throw new DomainException('Select company books.');
    if(!$director&&!tt_user_can_access_entity($u,$e,'View'))inv_out(['ok'=>false,'error'=>'Company access denied.'],403);
    if($write){if(!tt_verify_csrf((string)($b['csrf']??'')))inv_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);$action=(string)($b['action']??'');$right=$action==='reverse'?'Edit':($action==='master'&&($b['operation']??'add')==='delete'?'Delete':($action==='master'&&($b['operation']??'add')==='edit'?'Edit':'Create'));if(!far_permission($u,$module,$right)||(!$director&&!tt_user_can_access_entity($u,$e,$right)))inv_out(['ok'=>false,'error'=>'You do not have permission for this action.'],403);}
    tt_ensure_data_dir();$file=TT_DATA_DIR.'/accounts.json';$h=fopen($file,'c+');if($h===false||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try {rewind($h);$raw=stream_get_contents($h);if($raw===false)throw new RuntimeException('Storage read failed.');$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];if(!is_array($s))throw new RuntimeException('Invalid Accounts storage.');$result=[];
        if($write){$key=(string)($b['requestKey']??'');if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key))throw new DomainException('Reopen the form before saving.');$identity=$e.'|'.($u['id']??0).'|'.$key;$fingerprint=hash('sha256',json_encode($b,JSON_THROW_ON_ERROR));$saved=$s['investmentRequestKeys'][$identity]??null;
            if($saved){if($saved['fingerprint']!==$fingerprint)inv_out(['ok'=>false,'error'=>'This request has already been used with different details.'],409);$result=$saved['result'];}
            else {if((int)($b['revision']??-1)!==(int)($s['revision']??0))inv_out(['ok'=>false,'error'=>'Accounts changed. Reopen Investments before posting. Your entry has not been posted.'],409);
                $result=match($action){'master'=>inv_master($s,$e,$b,$u),'post'=>inv_post($s,$e,$b,$u),'reverse'=>inv_reverse($s,$e,$b,$u),default=>throw new DomainException('Unknown investment action.')};$s['investmentRequestKeys'][$identity]=['fingerprint'=>$fingerprint,'result'=>$result];$s['revision']=(int)($s['revision']??0)+1;
                $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Accounts storage save failed.');
            }
        }$out=inv_payload($s,$e)+['result'=>$result,'salaryPeriods'=>inv_salary_periods($s,$e,$u),'canCreate'=>far_permission($u,$module,'Create')&&($director||tt_user_can_access_entity($u,$e,'Create')),'canEdit'=>far_permission($u,$module,'Edit')&&($director||tt_user_can_access_entity($u,$e,'Edit')),'canDelete'=>far_permission($u,$module,'Delete')&&($director||tt_user_can_access_entity($u,$e,'Delete'))];
    }finally{flock($h,LOCK_UN);fclose($h);}inv_out($out);
}catch(DomainException $ex){inv_out(['ok'=>false,'error'=>$ex->getMessage()],422);}catch(Throwable $ex){error_log('Company investments: '.$ex->getMessage());inv_out(['ok'=>false,'error'=>'Company investments could not be saved or loaded.'],500);}
