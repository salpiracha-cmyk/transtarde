<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
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
function ba_master_accounts(): array {
    $masters=tt_list_masters();$out=[];
    foreach((array)($masters['banks']??[]) as $row){
        if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<14)$v[]='';
        $entity=ba_entity_from_linked((string)$v[1]);
        $out[(string)($row['id']??'')]=[
            'id'=>(string)($row['id']??''),'accountType'=>(string)$v[0],'linkedCompany'=>(string)$v[1],'entity'=>$entity,
            'personalOwner'=>(string)$v[2],'accountTitle'=>(string)$v[3],'bankName'=>(string)$v[4],'branch'=>(string)$v[5],
            'country'=>(string)$v[6],'currency'=>strtoupper(trim((string)$v[7])),'accountNumber'=>(string)$v[8],
            'accountNumberMasked'=>ba_mask((string)$v[8]),'accountLast5'=>substr(preg_replace('/\W+/','',(string)$v[8])??'',-5),
            'iban'=>(string)$v[9],'ibanMasked'=>ba_mask((string)$v[9]),'swift'=>(string)$v[10],'purpose'=>(string)$v[11],
            'visibility'=>(string)$v[12],'masterStatus'=>(string)$v[13]
        ];
    }
    return $out;
}
function ba_default_setting(array $a): array {
    return [
        'active'=>false,'allowPayments'=>false,'allowReceipts'=>false,'includeInPaymentPlanning'=>false,
        'visibleToMill'=>false,'reconciliationEnabled'=>true,'displayName'=>'','notes'=>'','updatedAt'=>null,'updatedBy'=>null
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
    foreach($masters as $id=>$a){
        if(($a['entity']??'')!==$entity||($a['accountType']??'')!=='Company Account')continue;
        $setting=array_replace(ba_default_setting($a),is_array($store['bankAccountSettings'][$id]??null)?$store['bankAccountSettings'][$id]:[]);
        $currency=strtoupper(trim((string)($a['currency']??'')))?:$planningCurrency;$book=ba_balance($store,$entity,$id,$currency);
        $balances[$currency]=round(($balances[$currency]??0)+$book,2);
        if($currency===$planningCurrency&&!empty($setting['active'])&&!empty($setting['includeInPaymentPlanning']))$planning+=max(0,$book);
        $rows[]=array_merge($a,['settings'=>$setting,'bookBalance'=>$book,'needsCompletion'=>(trim((string)$a['accountNumber'])===''&&trim((string)$a['iban'])==='')]);
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['bankName'],(string)$b['bankName'])?:strcmp((string)$a['accountTitle'],(string)$b['accountTitle']));
    $cashKey='CASH|'.$entity;$cashCurrency=$entity==='TG'?'AED':'PKR';$cashSetting=array_replace([
        'active'=>true,'allowPayments'=>true,'allowReceipts'=>true,'includeInPaymentPlanning'=>false,'visibleToMill'=>false,
        'reconciliationEnabled'=>true,'displayName'=>'Cash / Petty Cash','notes'=>'','updatedAt'=>null,'updatedBy'=>null
    ],is_array($store['bankAccountSettings'][$cashKey]??null)?$store['bankAccountSettings'][$cashKey]:[]);
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

try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts'))ba_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $entity=ba_entity((string)($_GET['entity']??'TTI'));$store=ba_read();
        ba_respond(['ok'=>true,'entity'=>$entity]+ba_payload($store,$entity)+['serverNow'=>gmdate('c')]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')ba_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!ba_can_write($user))ba_respond(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))ba_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    if((string)($body['action']??'')!=='save_settings')ba_respond(['ok'=>false,'error'=>'Unknown bank-account action.'],422);
    $entity=ba_entity((string)($body['entity']??''));$id=trim((string)($body['accountId']??''));if($id==='')ba_respond(['ok'=>false,'error'=>'Select a bank or cash account.'],422);
    $masters=ba_master_accounts();$cashKey='CASH|'.$entity;$planningCurrency=$entity==='TG'?'USD':'PKR';
    if($id!==$cashKey){
        $a=$masters[$id]??null;
        if(!is_array($a)||($a['entity']??'')!==$entity)ba_respond(['ok'=>false,'error'=>'Bank account does not belong to these company books.'],422);
        if(($a['accountType']??'')!=='Company Account')ba_respond(['ok'=>false,'error'=>'Personal / family bank accounts cannot be activated as company Cash & Bank accounts.'],422);
        $sourceCurrency=strtoupper(trim((string)(($a['currency']??'')?:$planningCurrency)));
    }else $sourceCurrency=$entity==='TG'?'AED':'PKR';
    $setting=[
        'active'=>(bool)($body['active']??false),'allowPayments'=>(bool)($body['allowPayments']??false),'allowReceipts'=>(bool)($body['allowReceipts']??false),
        'includeInPaymentPlanning'=>(bool)($body['includeInPaymentPlanning']??false),'visibleToMill'=>(bool)($body['visibleToMill']??false),
        'reconciliationEnabled'=>(bool)($body['reconciliationEnabled']??true),'displayName'=>trim((string)($body['displayName']??'')),
        'notes'=>trim((string)($body['notes']??'')),'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Accounts')
    ];
    if($sourceCurrency!==$planningCurrency)$setting['includeInPaymentPlanning']=false;
    if($id!==$cashKey&&($setting['allowPayments']||$setting['allowReceipts'])){
        if(trim((string)$a['accountNumber'])===''&&trim((string)$a['iban'])==='')ba_respond(['ok'=>false,'error'=>'Complete the account number or IBAN in the shared Banks & Accounts master before enabling payments or receipts.'],422);
    }
    tt_ensure_data_dir();$h=fopen(TT_BANK_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=ba_default_store();$store=array_replace_recursive(ba_default_store(),$store);
        $store['bankAccountSettings'][$id]=$setting;$store['revision']=(int)($store['revision']??0)+1;
        rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
    }finally{flock($h,LOCK_UN);fclose($h);}
    ba_respond(['ok'=>true,'saved'=>$setting]+ba_payload($store,$entity)+['revision'=>$store['revision']]);
}catch(Throwable $e){ba_respond(['ok'=>false,'error'=>'The bank-account action could not be completed.'],500);}
