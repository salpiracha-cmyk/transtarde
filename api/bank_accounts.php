<?php
declare(strict_types=1);
require_once __DIR__.'/tg_remittance_core.php';

require_once dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_BANK_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';

function ba_respond(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function ba_can_write(array $user): bool {
    if(($user['role']??'')==='Super Admin')return true;
    $p=$user['permissions']['Accounts']??null;
    if($p==='all')return true;
    if(!is_array($p))return false;
    if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true))return true;
    foreach($p as $a)if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)||in_array('Approve',$a,true)))return true;
    return false;
}
function ba_entity(string $v): string {
    $v=strtoupper(trim($v));
    if(!in_array($v,['TTI','BRM','TG'],true))ba_respond(['ok'=>false,'error'=>'Select valid company books.'],422);
    return $v;
}
function ba_default_store(): array {
    return ['revision'=>0,'journals'=>[],'bankAccountSettings'=>[]];
}
function ba_read(): array {
    tt_ensure_data_dir();
    if(!is_file(TT_BANK_ACCOUNTS_FILE))return ba_default_store();
    $h=fopen(TT_BANK_ACCOUNTS_FILE,'r');
    if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(ba_default_store(),$s):ba_default_store();
}
function ba_entity_from_linked(string $linked): string {
    $u=strtoupper($linked);
    if(str_contains($u,'BRM')||str_contains($u,'BUKSH RICE'))return 'BRM';
    if(str_contains($u,'TG')||str_contains($u,'TRANS GRAINS'))return 'TG';
    if(str_contains($u,'TTI')||str_contains($u,'TRANSTRADE INTERNATIONAL'))return 'TTI';
    return '';
}
function ba_mask(string $v,int $last=5): string {
    $clean=preg_replace('/\s+/','',trim($v))??'';
    if($clean==='')return '';
    $tail=substr($clean,-$last);
    return str_repeat('•',max(0,strlen($clean)-strlen($tail))).$tail;
}
function ba_display_label(string $title,string $bank,string $number,string $iban): string {
    $number=trim($number);$iban=trim($iban);
    return implode(' · ',array_filter([trim($bank),$number!==''?$number:$iban,trim($title)],static fn($part)=>$part!==''));
}
function ba_master_accounts(): array {
    $masters=tt_list_masters();$out=[];
    foreach((array)($masters['banks']??[]) as $row){
        if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<14)$v[]='';
        $entity=ba_entity_from_linked((string)$v[1]);
        $out[(string)($row['id']??'')]=[
            'id'=>(string)($row['id']??''),'companyId'=>(string)($row['companyId']??''),'accountType'=>(string)$v[0],'linkedCompany'=>(string)$v[1],'entity'=>$entity,
            'personalOwner'=>(string)$v[2],'accountTitle'=>(string)$v[3],'bankName'=>(string)$v[4],'branch'=>(string)$v[5],
            'country'=>(string)$v[6],'currency'=>strtoupper(trim((string)$v[7])),'accountNumber'=>(string)$v[8],
            'accountNumberMasked'=>ba_mask((string)$v[8]),'accountLast5'=>substr(preg_replace('/\W+/','',(string)$v[8])??'',-5),
            'iban'=>(string)$v[9],'ibanMasked'=>ba_mask((string)$v[9]),'displayLabel'=>!empty($row['linkedRetentionAccount'])?(string)$v[4]:($entity==='TG'?strtoupper((string)$v[7]).' · ':'').ba_display_label((string)$v[3],(string)$v[4],(string)$v[8],(string)$v[9]),'swift'=>(string)$v[10],'purpose'=>(string)$v[11],
            'visibility'=>(string)$v[12],'masterStatus'=>(string)$v[13],'masterRetentionAccount'=>$row['retentionAccount']??null,'linkedRetentionAccount'=>!empty($row['linkedRetentionAccount']),'retentionParentBankId'=>(string)($row['retentionParentBankId']??''),'depositType'=>(string)($row['depositType']??'')
        ];
    }
    return $out;
}
function ba_operational_account_type(string $type): bool {
    return in_array($type,['Company Account','Proprietor / Owner Account','Personal Account'],true);
}
function ba_default_setting(array $a): array {
    $complete=!empty($a['linkedRetentionAccount'])||trim((string)($a['accountNumber']??''))!==''||trim((string)($a['iban']??''))!=='';
    $company=ba_operational_account_type((string)($a['accountType']??''));
    $receiptReady=$company&&$complete&&strcasecmp((string)($a['masterStatus']??'Active'),'Active')===0;
    return [
        'active'=>$receiptReady,'allowPayments'=>$receiptReady,'allowReceipts'=>$receiptReady,'includeInPaymentPlanning'=>false,
        'visibleToMill'=>false,'reconciliationEnabled'=>true,'retentionAccount'=>false,'defaultReceiptAccount'=>false,'defaultPaymentAccount'=>false,'displayName'=>'','notes'=>'','updatedAt'=>null,'updatedBy'=>null
    ];
}
function ba_balance(array $store,string $entity,string $bankId,string $currency): float {
    $bal=0.0;$currency=strtoupper(trim($currency));
    foreach((array)($store['journals']??[]) as $j){
        if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$entity)continue;
        $jm=is_array($j['meta']??null)?$j['meta']:[];
        foreach((array)($j['lines']??[]) as $line){
            if(!is_array($line)||(string)($line['account']??'')!=='1110')continue;
            $lineBank=(string)($line['bankAccountId']??$jm['bankAccountId']??'');if($lineBank!==$bankId)continue;
            if($currency==='PKR'){$bal+=(float)($line['debit']??0)-(float)($line['credit']??0);continue;}
            $bal+=(float)($line['bankDebit']??0)-(float)($line['bankCredit']??0);
        }
    }
    return round($bal,2);
}
function ba_cash_balance(array $store,string $entity): float {
    $bal=0.0;
    foreach((array)($store['journals']??[]) as $j){
        if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$entity)continue;
        foreach((array)($j['lines']??[]) as $line)if(is_array($line)&&(string)($line['account']??'')==='1120')$bal+=(float)($line['debit']??0)-(float)($line['credit']??0);
    }
    return round($bal,2);
}
function ba_unassigned_bank_balance(array $store,string $entity): float {
    $bal=0.0;
    foreach((array)($store['journals']??[]) as $j){
        if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$entity)continue;
        $jm=is_array($j['meta']??null)?$j['meta']:[];
        foreach((array)($j['lines']??[]) as $line){
            if(!is_array($line)||(string)($line['account']??'')!=='1110')continue;
            $lineBank=(string)($line['bankAccountId']??$jm['bankAccountId']??'');
            if($lineBank==='')$bal+=(float)($line['debit']??0)-(float)($line['credit']??0);
        }
    }
    return round($bal,2);
}
function ba_payload(array $store,string $entity): array {
    $masters=ba_master_accounts();$rows=[];$planning=0.0;$planningCurrency=$entity==='TG'?'USD':'PKR';$balances=[];
    $pending=[];foreach((array)(tt_read_store()['bank_deletion_requests']??[]) as $request)if(($request['status']??'')==='Pending')$pending[(string)($request['bankId']??'')]=$request;
    foreach($masters as $id=>$a){
        if(($a['entity']??'')!==$entity||!ba_operational_account_type((string)($a['accountType']??'')))continue;
        $setting=array_replace(ba_default_setting($a),is_array($store['bankAccountSettings'][$id]??null)?$store['bankAccountSettings'][$id]:[]);
        // Bank identity and status are controlled in Company Master. Old Accounts flags
        // must not silently disable a valid company account.
        $available=!empty(ba_default_setting($a)['active']);
        $setting['active']=$available;$setting['allowPayments']=$available;$setting['allowReceipts']=$available;
        if(!$available){$setting['active']=false;$setting['allowPayments']=false;$setting['allowReceipts']=false;$setting['defaultReceiptAccount']=false;$setting['defaultPaymentAccount']=false;}
        if($a['masterRetentionAccount']!==null)$setting['retentionAccount']=(bool)$a['masterRetentionAccount'];
        if($a['accountType']!=='Company Account')$setting['retentionAccount']=false;
        $currency=strtoupper(trim((string)($a['currency']??'')))?:$planningCurrency;$postedBook=ba_balance($store,$entity,$id,$currency);$reserve=$entity==='TG'?tgr_reserved($store,$id):0.0;$book=round($postedBook-$reserve,2);
        $balances[$currency]=round(($balances[$currency]??0)+$book,2);
        if($currency===$planningCurrency&&!empty($setting['active'])&&!empty($setting['includeInPaymentPlanning']))$planning+=max(0,$book);
        $rows[]=array_merge($a,['settings'=>$setting,'bookBalance'=>$postedBook,'pendingRemittances'=>$reserve,'availableBalance'=>$book,'needsCompletion'=>(empty($a['linkedRetentionAccount'])&&trim((string)$a['accountNumber'])===''&&trim((string)$a['iban'])===''),'deletionPending'=>isset($pending[$id]),'deletionRequest'=>$pending[$id]??null]);
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['bankName'],(string)$b['bankName'])?:strcmp((string)$a['accountTitle'],(string)$b['accountTitle']));
    $cashKey='CASH|'.$entity;$cashCurrency=$entity==='TG'?'AED':'PKR';$cashSetting=array_replace([
        'active'=>true,'allowPayments'=>true,'allowReceipts'=>true,'includeInPaymentPlanning'=>false,'visibleToMill'=>false,
        'reconciliationEnabled'=>true,'retentionAccount'=>false,'defaultReceiptAccount'=>false,'defaultPaymentAccount'=>false,'displayName'=>'Cash / Petty Cash','notes'=>'','updatedAt'=>null,'updatedBy'=>null
    ],is_array($store['bankAccountSettings'][$cashKey]??null)?$store['bankAccountSettings'][$cashKey]:[]);
    $cashSetting['active']=true;$cashSetting['allowPayments']=true;$cashSetting['allowReceipts']=true;$cashSetting['defaultReceiptAccount']=false;$cashSetting['defaultPaymentAccount']=false;
    $cash=ba_cash_balance($store,$entity);$balances[$cashCurrency]=round(($balances[$cashCurrency]??0)+$cash,2);
    if($cashCurrency===$planningCurrency&&!empty($cashSetting['active'])&&!empty($cashSetting['includeInPaymentPlanning']))$planning+=max(0,$cash);
    ksort($balances);
    return [
        'accounts'=>$rows,'balancesByCurrency'=>$balances,
        'cash'=>['id'=>$cashKey,'entity'=>$entity,'accountTitle'=>'Cash / Petty Cash','currency'=>$cashCurrency,'bookBalance'=>$cash,'settings'=>$cashSetting],
        'paymentPlanningCurrency'=>$planningCurrency,'paymentPlanningFunds'=>round($planning,2),
        'unassignedBankBalance'=>ba_unassigned_bank_balance($store,$entity),'unassignedBankBalanceCurrency'=>'PKR'
    ];
}

if(defined('TT_BANK_FUNCTIONS_ONLY'))return;
try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts'))ba_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $entity=ba_entity((string)($_GET['entity']??'TTI'));tt_release_read_session();$store=ba_read();
        ba_respond(['ok'=>true,'entity'=>$entity]+ba_payload($store,$entity)+['serverNow'=>gmdate('c')]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')ba_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!ba_can_write($user))ba_respond(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
    $body=json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))ba_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');
    $entity=ba_entity((string)($body['entity']??''));$id=trim((string)($body['accountId']??''));if($id==='')ba_respond(['ok'=>false,'error'=>'Select a bank or cash account.'],422);
    $masters=ba_master_accounts();$cashKey='CASH|'.$entity;$planningCurrency=$entity==='TG'?'USD':'PKR';
    if($action==='save_deposit_type'){
        if(!tt_user_can_master($user,'companies','Edit'))ba_respond(['ok'=>false,'error'=>'Company Master Edit permission is required.'],403);
        $a=$masters[$id]??null;if(!is_array($a)||($a['entity']??'')!==$entity)ba_respond(['ok'=>false,'error'=>'Bank account does not belong to these company books.'],422);
        $depositType=(string)($body['depositType']??'');if(!in_array($depositType,['CURRENT','SAVING'],true))ba_respond(['ok'=>false,'error'=>'Select Saving or Current.'],422);
        tt_mutate_store(static function(&$auth)use($a,$id,$depositType,$user):void{
            foreach($auth['masters']['companies'] as &$company){if((string)($company['id']??'')!==$a['companyId'])continue;
                $banks=tt_master_json_array($company['values'][13]??'');foreach($banks as &$bank){if((string)($bank['id']??'')!==$id)continue;$before=$bank['depositType']??'';$bank['depositType']=$depositType;$company['values'][13]=json_encode($banks,JSON_THROW_ON_ERROR);$auth['audit'][]=['at'=>gmdate('c'),'action'=>'BANK_ACCOUNT_TYPE','id'=>$id,'before'=>$before,'after'=>$depositType,'by'=>$user['username']??''];return;}unset($bank);
            }unset($company);throw new InvalidArgumentException('Bank account no longer exists.');
        });
        ba_respond(['ok'=>true,'entity'=>$entity]+ba_payload(ba_read(),$entity));
    }
    if($action==='request_delete'){
        if($id===$cashKey)ba_respond(['ok'=>false,'error'=>'Cash cannot be deleted through bank approval.'],422);
        $a=$masters[$id]??null;
        if(!is_array($a)||($a['entity']??'')!==$entity||!ba_operational_account_type((string)($a['accountType']??'')))ba_respond(['ok'=>false,'error'=>'Select a valid company bank account.'],422);
        if(strcasecmp((string)($a['masterStatus']??'Active'),'Active')!==0)ba_respond(['ok'=>false,'error'=>'This bank is already inactive in Company Master.'],422);
        $reason=trim((string)($body['reason']??''));if(strlen($reason)<5||strlen($reason)>500)ba_respond(['ok'=>false,'error'=>'Explain why this bank account should be deleted.'],422);
        $companyId=(string)($a['companyId']??'');if($companyId==='')ba_respond(['ok'=>false,'error'=>'The linked Company Master could not be identified.'],422);
        $request=tt_mutate_store(static function (&$auth) use($companyId,$id,$a,$reason,$user):array {
            $auth['bank_deletion_requests']??=[];
            foreach($auth['bank_deletion_requests'] as $old)if(($old['companyId']??'')===$companyId&&($old['bankId']??'')===$id&&($old['status']??'')==='Pending')throw new InvalidArgumentException('This bank already has a deletion request awaiting approval.');
            $item=['id'=>bin2hex(random_bytes(12)),'companyId'=>$companyId,'bankId'=>$id,'company'=>(string)($a['linkedCompany']??''),'bank'=>(string)($a['bankName']??'').' · '.(string)($a['accountTitle']??''),'reason'=>$reason,'status'=>'Pending','requestedBy'=>(string)($user['full_name']??$user['username']??'Accounts'),'requestedAt'=>gmdate('c')];
            $auth['bank_deletion_requests'][]=$item;return $item;
        });
        tt_audit((int)$user['id'],(string)($user['username']??'Accounts'),'Requested bank deletion approval '.$id);
        $store=ba_read();ba_respond(['ok'=>true,'entity'=>$entity,'request'=>$request]+ba_payload($store,$entity));
    }
    if($action!=='save_settings')ba_respond(['ok'=>false,'error'=>'Unknown bank-account action.'],422);
    if($id!==$cashKey){
        $a=$masters[$id]??null;
        if(!is_array($a)||($a['entity']??'')!==$entity)ba_respond(['ok'=>false,'error'=>'Bank account does not belong to these company books.'],422);
        if(!ba_operational_account_type((string)($a['accountType']??'')))ba_respond(['ok'=>false,'error'=>'Personal-only bank accounts cannot be activated as company Cash & Bank accounts.'],422);
        $sourceCurrency=strtoupper(trim((string)(($a['currency']??'')?:$planningCurrency)));
    }else $sourceCurrency=$entity==='TG'?'AED':'PKR';
    $retentionRequested=$id!==$cashKey&&($a['accountType']??'')==='Company Account'&&(($a['masterRetentionAccount']??null)!==null?(bool)$a['masterRetentionAccount']:(bool)(ba_read()['bankAccountSettings'][$id]['retentionAccount']??false));
    if($retentionRequested&&($entity==='TG'||$sourceCurrency==='PKR'||$id===$cashKey))ba_respond(['ok'=>false,'error'=>'Foreign Retention Account can only be enabled for a non-PKR TTI/BRM company bank.'],422);
    $defaultReceiptRequested=(bool)($body['defaultReceiptAccount']??false);
    $defaultPaymentRequested=(bool)($body['defaultPaymentAccount']??false);
    if($defaultPaymentRequested&&$id===$cashKey)ba_respond(['ok'=>false,'error'=>'The default payment account must be a company bank account, not cash.'],422);
    if($defaultReceiptRequested&&$id===$cashKey)ba_respond(['ok'=>false,'error'=>'The default receipt account must be a company bank account, not cash.'],422);
    $setting=[
        'active'=>$id===$cashKey||(!empty($a)&&!empty(ba_default_setting($a)['active'])),'allowPayments'=>true,'allowReceipts'=>true,
        'includeInPaymentPlanning'=>(bool)($body['includeInPaymentPlanning']??false),'visibleToMill'=>(bool)($body['visibleToMill']??false),
        'reconciliationEnabled'=>(bool)($body['reconciliationEnabled']??true),'retentionAccount'=>$retentionRequested,
        'defaultReceiptAccount'=>$defaultReceiptRequested,'defaultPaymentAccount'=>$defaultPaymentRequested,
        'displayName'=>trim((string)($body['displayName']??'')),'notes'=>trim((string)($body['notes']??'')),'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Accounts')
    ];
    if($sourceCurrency!==$planningCurrency)$setting['includeInPaymentPlanning']=false;
    if(!$setting['active']||!$setting['allowReceipts'])$setting['defaultReceiptAccount']=false;
    if(!$setting['active']||!$setting['allowPayments'])$setting['defaultPaymentAccount']=false;
    if($id!==$cashKey&&($defaultReceiptRequested||$defaultPaymentRequested)){
        if(strcasecmp((string)($a['masterStatus']??'Active'),'Active')!==0)ba_respond(['ok'=>false,'error'=>'Activate this bank inside Super Admin Company Master before enabling payments or receipts.'],422);
        if(empty($a['linkedRetentionAccount'])&&trim((string)$a['accountNumber'])===''&&trim((string)$a['iban'])==='')ba_respond(['ok'=>false,'error'=>'Complete the account number or IBAN in the Company Master before enabling payments or receipts.'],422);
    }
    tt_ensure_data_dir();$h=fopen(TT_BANK_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=ba_default_store();$store=array_replace_recursive(ba_default_store(),$store);
        if($setting['defaultReceiptAccount']||$setting['defaultPaymentAccount']){
            foreach((array)($store['bankAccountSettings']??[]) as $otherId=>$otherSetting){
                if($otherId===$id||!is_array($otherSetting))continue;
                $otherMaster=$masters[$otherId]??null;
                if(is_array($otherMaster)&&($otherMaster['entity']??'')===$entity&&strtoupper(trim((string)(($otherMaster['currency']??'')?:$planningCurrency)))===$sourceCurrency){
                    if($setting['defaultReceiptAccount'])$store['bankAccountSettings'][$otherId]['defaultReceiptAccount']=false;
                    if($setting['defaultPaymentAccount'])$store['bankAccountSettings'][$otherId]['defaultPaymentAccount']=false;
                }
            }
        }
        $store['bankAccountSettings'][$id]=$setting;$store['revision']=(int)($store['revision']??0)+1;
        rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
    }finally{flock($h,LOCK_UN);fclose($h);}
    ba_respond(['ok'=>true,'saved'=>$setting]+ba_payload($store,$entity)+['revision'=>$store['revision']]);
}catch(InvalidArgumentException $e){ba_respond(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){ba_respond(['ok'=>false,'error'=>'The bank-account action could not be completed.'],500);}


