<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_SETTLEMENT_FILE = TT_DATA_DIR . '/accounts.json';
const TT_SETTLEMENT_MASTER = __DIR__ . '/../accounts/accounting_master_v1.json';
const TT_SETTLEMENT_POLICY = __DIR__ . '/../accounts/settlement_policy_v1.json';

function ss_respond(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function ss_json(string $path): array {
    $raw=is_file($path)?file_get_contents($path):false;
    $v=$raw?json_decode($raw,true):null;
    if(!is_array($v))throw new RuntimeException('Required settlement configuration is unavailable.');
    return $v;
}
function ss_default_store(): array {
    return [
        'revision'=>0,'journals'=>[],'events'=>[],'commodityBills'=>[],
        'supplierSettlements'=>[],'supplierAdvances'=>[],'payableHolds'=>[]
    ];
}
function ss_read(): array {
    tt_ensure_data_dir();
    if(!is_file(TT_SETTLEMENT_FILE))return ss_default_store();
    $h=fopen(TT_SETTLEMENT_FILE,'r');
    if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(ss_default_store(),$s):ss_default_store();
}
function ss_can_write(array $user): bool {
    if(($user['role']??'')==='Super Admin')return true;
    $p=$user['permissions']['Accounts']??null;
    if($p==='all')return true;
    if(!is_array($p))return false;
    if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true))return true;
    foreach($p as $a)if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)||in_array('Approve',$a,true)))return true;
    return false;
}
function ss_entity(string $v): string {
    $v=strtoupper(trim($v));
    if(!in_array($v,['TTI','BRM'],true))ss_respond(['ok'=>false,'error'=>'Supplier settlements are available for TTI or BRM books.'],422);
    return $v;
}
function ss_date(string $v): string {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))ss_respond(['ok'=>false,'error'=>'Valid settlement date required.'],422);
    return $v;
}
function ss_money(mixed $v,string $label='Amount',bool $allowZero=false): float {
    $n=round((float)$v,2);
    if($n<0||(!$allowZero&&$n<=0))ss_respond(['ok'=>false,'error'=>$label.' must be greater than zero.'],422);
    return $n;
}
function ss_catalog(): array {
    $master=ss_json(TT_SETTLEMENT_MASTER);$policy=ss_json(TT_SETTLEMENT_POLICY);$out=[];
    foreach((array)($master['chart']??[]) as $a)if(is_array($a)&&isset($a['code']))$out[(string)$a['code']]=$a;
    foreach((array)($master['peopleSubledgers']??[]) as $a)if(is_array($a)&&isset($a['code']))$out[(string)$a['code']]=array_merge(['class'=>'Subledger','level'=>'subledger'], $a);
    foreach((array)($policy['accounts']??[]) as $a)if(is_array($a)&&isset($a['code']))$out[(string)$a['code']]=$a;
    return $out;
}
function ss_line(string $account,float $dr,float $cr,array $catalog): array {
    if(!isset($catalog[$account]))ss_respond(['ok'=>false,'error'=>'Settlement account '.$account.' is not configured.'],422);
    return ['account'=>$account,'accountName'=>(string)($catalog[$account]['name']??$account),'debit'=>round($dr,2),'credit'=>round($cr,2)];
}
function ss_next_id(array $items,string $prefix): string {
    $n=count($items)+1;
    do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);$n++;}while(isset($items[$id]));
    return $id;
}
function ss_post_journal(array &$store,array $user,string $entity,string $date,string $sourceType,string $reference,string $narration,array $lines,array $meta=[]): array {
    $dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);
    if($dr<=0||abs($dr-$cr)>.005)throw new RuntimeException('Settlement journal did not balance.');
    $id=ss_next_id((array)$store['journals'],'AUTO');
    $store['journals'][$id]=[
        'id'=>$id,'entity'=>$entity,'date'=>$date,'sourceType'=>$sourceType,'reference'=>$reference,
        'narration'=>$narration,'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>$meta,
        'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts'),
        'userId'=>(int)($user['id']??0),'reversalOf'=>null
    ];
    return $store['journals'][$id];
}
function ss_find_bill(array $store,string $entity,string $billId): array {
    $b=$store['commodityBills'][$billId]??null;
    if(!is_array($b)||($b['entity']??'')!==$entity)ss_respond(['ok'=>false,'error'=>'Supplier bill was not found in the selected entity.'],404);
    return $b;
}
function ss_bill_allocation(array $bill,string $sourceKey): array {
    foreach((array)($bill['receiptAllocations']??[]) as $a)if(is_array($a)&&(string)($a['sourceKey']??'')===$sourceKey)return $a;
    ss_respond(['ok'=>false,'error'=>'Truck / Pohanch payable allocation was not found on the supplier bill.'],404);
}
function ss_previous_allocation_components(array $store,string $entity,string $billId,string $sourceKey): array {
    $commodity=0.0;$brokerage=0.0;$total=0.0;
    foreach((array)($store['supplierSettlements']??[]) as $s){
        if(!is_array($s)||($s['entity']??'')!==$entity||!in_array((string)($s['status']??''),['Posted','Approved / Posted'],true))continue;
        foreach((array)($s['allocations']??[]) as $a){
            if(!is_array($a)||(string)($a['billId']??'')!==$billId||(string)($a['sourceKey']??'')!==$sourceKey)continue;
            $commodity+=round((float)($a['commodityAmount']??0),2);$brokerage+=round((float)($a['brokerageAmount']??0),2);$total+=round((float)($a['amount']??0),2);
        }
    }
    return ['commodity'=>round($commodity,2),'brokerage'=>round($brokerage,2),'total'=>round($total,2)];
}
function ss_component_capacity(array $store,string $entity,array $bill,array $alloc): array {
    $billId=(string)($bill['id']??'');$sourceKey=(string)($alloc['sourceKey']??'');
    $share=round((float)($alloc['supplierPayableShare']??0),2);
    $final=round((float)($bill['finalCommodityValue']??0),2);
    $netBroker=max(0,round((float)($bill['brokerageGross']??0)-(float)($bill['brokerageWithholding']??0),2));
    $total=max(0,round((float)($bill['supplierPayableTotal']??($final+$netBroker)),2));
    $commodityCap=$total>0?round($share*($final/$total),2):$share;
    $brokerageCap=round($share-$commodityCap,2);
    $prev=ss_previous_allocation_components($store,$entity,$billId,$sourceKey);
    return [
        'commodity'=>max(0,round($commodityCap-$prev['commodity'],2)),
        'brokerage'=>max(0,round($brokerageCap-$prev['brokerage'],2)),
        'total'=>max(0,round($share-$prev['total'],2))
    ];
}
function ss_split_payment(float $amount,array $capacity): array {
    if($amount>$capacity['total']+.005)ss_respond(['ok'=>false,'error'=>'Settlement allocation exceeds the outstanding amount for the selected truck / Pohanch.'],422);
    $remaining=$capacity['total'];
    if($remaining<=0)ss_respond(['ok'=>false,'error'=>'Selected payable is already fully settled.'],409);
    $commodity=$remaining>0?round($amount*($capacity['commodity']/$remaining),2):0.0;
    $commodity=min($commodity,$capacity['commodity']);
    $brokerage=round($amount-$commodity,2);
    if($brokerage>$capacity['brokerage']+.005){$brokerage=$capacity['brokerage'];$commodity=round($amount-$brokerage,2);}
    return ['commodity'=>round($commodity,2),'brokerage'=>round($brokerage,2),'total'=>round($amount,2)];
}
function ss_prepare_allocations(array $store,string $entity,array $raw): array {
    if(!$raw||count($raw)>200)ss_respond(['ok'=>false,'error'=>'Select at least one supplier payable allocation.'],422);
    $out=[];$total=0.0;$commodity=0.0;$brokerage=0.0;$brokers=[];
    foreach($raw as $r){
        if(!is_array($r))continue;
        $billId=trim((string)($r['billId']??''));$sourceKey=trim((string)($r['sourceKey']??''));$amount=ss_money($r['amount']??0,'Allocation amount');
        $bill=ss_find_bill($store,$entity,$billId);$alloc=ss_bill_allocation($bill,$sourceKey);$cap=ss_component_capacity($store,$entity,$bill,$alloc);$split=ss_split_payment($amount,$cap);
        $brokers[]=(string)($bill['broker']??'');$total+=$amount;$commodity+=$split['commodity'];$brokerage+=$split['brokerage'];
        $out[]=[
            'billId'=>$billId,'billNo'=>(string)($bill['billNo']??''),'sourceKey'=>$sourceKey,
            'soda'=>(string)(($bill['sodas'][0]??'')?:''),'broker'=>(string)($bill['broker']??''),
            'truck'=>(string)($alloc['truck']??''),'pohanch'=>(string)($alloc['pohanch']??''),'dueDate'=>(string)($alloc['dueDate']??''),
            'amount'=>$amount,'commodityAmount'=>$split['commodity'],'brokerageAmount'=>$split['brokerage']
        ];
    }
    if(!$out)ss_respond(['ok'=>false,'error'=>'No valid supplier payable allocations were supplied.'],422);
    $unique=array_values(array_unique(array_filter(array_map('trim',$brokers))));
    if(count($unique)>1)ss_respond(['ok'=>false,'error'=>'One supplier payment / settlement cannot mix different brokers / payees.'],422);
    return ['rows'=>$out,'total'=>round($total,2),'commodity'=>round($commodity,2),'brokerage'=>round($brokerage,2),'broker'=>$unique[0]??''];
}
function ss_advance_available(array $store,string $entity,string $advanceId): array {
    $a=$store['supplierAdvances'][$advanceId]??null;
    if(!is_array($a)||($a['entity']??'')!==$entity)ss_respond(['ok'=>false,'error'=>'Supplier advance was not found.'],404);
    $available=max(0,round((float)($a['amount']??0)-(float)($a['allocatedAmount']??0),2));
    return ['advance'=>$a,'available'=>$available];
}
function ss_person_account(string $person): string {
    $k=strtoupper(trim($person));
    $map=['SALMAN'=>'REIMB-SALMAN','TALHA'=>'REIMB-TALHA','ABU'=>'REIMB-ABU','TAYYAB'=>'REIMB-TAYYAB'];
    if(!isset($map[$k]))ss_respond(['ok'=>false,'error'=>'This family/staff payer is not configured for reimbursement.'],422);
    return $map[$k];
}
function ss_advance_credit_account(string $relationship,string $person,string $payAccount): string {
    return match($relationship){
        'OWN_BANK'=>'1110',
        'OWN_CASH'=>'1120',
        'CUSTOMER_RECEIVABLE'=>'1220',
        'CUSTOMER_ADVANCE'=>'2160',
        'OTHER_THIRD_PARTY'=>'2170',
        'FAMILY_STAFF'=>ss_person_account($person),
        'GROUP_ENTITY'=>ss_respond(['ok'=>false,'error'=>'Group-entity payments must use the Intercompany workflow, not Third Party Settlement.'],422),
        default=>ss_respond(['ok'=>false,'error'=>'Select the payer relationship for this supplier advance.'],422)
    };
}

try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts'))ss_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);

    if($_SERVER['REQUEST_METHOD']==='GET'){
        $entity=ss_entity((string)($_GET['entity']??'TTI'));$store=ss_read();
        $adv=[];foreach((array)($store['supplierAdvances']??[]) as $a)if(is_array($a)&&($a['entity']??'')===$entity){$x=$a;$x['availableAmount']=max(0,round((float)($a['amount']??0)-(float)($a['allocatedAmount']??0),2));$adv[]=$x;}
        usort($adv,static fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??'')));
        $hist=[];foreach((array)($store['supplierSettlements']??[]) as $s)if(is_array($s)&&($s['entity']??'')===$entity)$hist[]=$s;
        usort($hist,static fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??'')));
        ss_respond(['ok'=>true,'advances'=>$adv,'settlements'=>$hist,'policy'=>ss_json(TT_SETTLEMENT_POLICY),'serverNow'=>gmdate('c')]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST')ss_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!ss_can_write($user))ss_respond(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))ss_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');$entity=ss_entity((string)($body['entity']??''));$date=ss_date((string)($body['date']??''));$catalog=ss_catalog();

    tt_ensure_data_dir();$h=fopen(TT_SETTLEMENT_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=ss_default_store();$store=array_replace_recursive(ss_default_store(),$store);

        if($action==='post_supplier_payment'){
            $prepared=ss_prepare_allocations($store,$entity,(array)($body['allocations']??[]));
            $payAccount=(string)($body['payAccount']??'');if(!in_array($payAccount,['1110','1120'],true))ss_respond(['ok'=>false,'error'=>'Select Transtrade Bank or Cash for a normal supplier payment.'],422);
            $lines=[];if($prepared['commodity']>0)$lines[]=ss_line('2110',$prepared['commodity'],0,$catalog);if($prepared['brokerage']>0)$lines[]=ss_line('2120',$prepared['brokerage'],0,$catalog);$lines[]=ss_line($payAccount,0,$prepared['total'],$catalog);
            $id=ss_next_id((array)$store['supplierSettlements'],'SP');$reference=trim((string)($body['reference']??''))?:$id;$journal=ss_post_journal($store,$user,$entity,$date,'SUPPLIER_PAYMENT',$reference,'Supplier payment — '.$prepared['broker'],$lines,['settlementId'=>$id,'broker'=>$prepared['broker'],'allocations'=>$prepared['rows'],'paymentSource'=>$payAccount]);
            $store['supplierSettlements'][$id]=['id'=>$id,'entity'=>$entity,'date'=>$date,'type'=>'Bank / Cash Supplier Payment','broker'=>$prepared['broker'],'amount'=>$prepared['total'],'allocations'=>$prepared['rows'],'paymentSource'=>$payAccount,'reference'=>$reference,'journalId'=>$journal['id'],'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts')];$result=$store['supplierSettlements'][$id];
        }
        elseif($action==='record_supplier_advance'){
            $amount=ss_money($body['amount']??0,'Advance amount');$supplier=trim((string)($body['supplier']??''));if($supplier==='')ss_respond(['ok'=>false,'error'=>'Supplier / broker is required.'],422);
            $relationship=strtoupper(trim((string)($body['payerRelationship']??'')));$payer=trim((string)($body['payer']??''));$person=trim((string)($body['person']??''));$credit=ss_advance_credit_account($relationship,$person,(string)($body['payAccount']??''));
            if(in_array($relationship,['CUSTOMER_RECEIVABLE','CUSTOMER_ADVANCE','OTHER_THIRD_PARTY'],true)&&$payer==='')ss_respond(['ok'=>false,'error'=>'Who paid is required for a third-party supplier advance.'],422);
            $soda=trim((string)($body['soda']??''));$sourceKey=trim((string)($body['sourceKey']??''));$reference=trim((string)($body['reference']??''));$id=ss_next_id((array)$store['supplierAdvances'],'SA');
            $lines=[ss_line('1250',$amount,0,$catalog),ss_line($credit,0,$amount,$catalog)];
            $journal=ss_post_journal($store,$user,$entity,$date,'SUPPLIER_ADVANCE',$reference?:$id,'Supplier / broker advance — '.$supplier.($payer?' — through '.$payer:''),$lines,['advanceId'=>$id,'supplier'=>$supplier,'soda'=>$soda,'sourceKey'=>$sourceKey,'payer'=>$payer,'payerRelationship'=>$relationship]);
            $store['supplierAdvances'][$id]=['id'=>$id,'entity'=>$entity,'date'=>$date,'supplier'=>$supplier,'soda'=>$soda,'sourceKey'=>$sourceKey,'payer'=>$payer,'payerRelationship'=>$relationship,'amount'=>$amount,'allocatedAmount'=>0.0,'availableAmount'=>$amount,'reference'=>$reference,'journalId'=>$journal['id'],'status'=>'Available / Unallocated','createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts')];$result=$store['supplierAdvances'][$id];
        }
        elseif($action==='apply_supplier_advance'){
            $advanceId=trim((string)($body['advanceId']??''));$av=ss_advance_available($store,$entity,$advanceId);$advance=$av['advance'];$prepared=ss_prepare_allocations($store,$entity,(array)($body['allocations']??[]));if($prepared['total']>$av['available']+.005)ss_respond(['ok'=>false,'error'=>'Selected supplier advance is not large enough for these allocations.'],422);
            if(strcasecmp(trim((string)($advance['supplier']??'')),$prepared['broker'])!==0)ss_respond(['ok'=>false,'error'=>'Supplier advance belongs to a different broker / supplier.'],422);
            $advSoda=trim((string)($advance['soda']??''));if($advSoda!==''){foreach($prepared['rows'] as $r)if(trim((string)($r['soda']??''))!==$advSoda)ss_respond(['ok'=>false,'error'=>'This advance is specifically linked to Soda '.$advSoda.' and cannot be applied elsewhere.'],422);}
            $lines=[];if($prepared['commodity']>0)$lines[]=ss_line('2110',$prepared['commodity'],0,$catalog);if($prepared['brokerage']>0)$lines[]=ss_line('2120',$prepared['brokerage'],0,$catalog);$lines[]=ss_line('1250',0,$prepared['total'],$catalog);
            $id=ss_next_id((array)$store['supplierSettlements'],'SAAP');$reference=trim((string)($body['reference']??''))?:$id;$journal=ss_post_journal($store,$user,$entity,$date,'SUPPLIER_ADVANCE_APPLIED',$reference,'Supplier advance applied — '.$prepared['broker'],$lines,['settlementId'=>$id,'advanceId'=>$advanceId,'broker'=>$prepared['broker'],'allocations'=>$prepared['rows']]);
            $store['supplierSettlements'][$id]=['id'=>$id,'entity'=>$entity,'date'=>$date,'type'=>'Supplier Advance Applied','broker'=>$prepared['broker'],'amount'=>$prepared['total'],'allocations'=>$prepared['rows'],'advanceId'=>$advanceId,'reference'=>$reference,'journalId'=>$journal['id'],'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts')];
            $store['supplierAdvances'][$advanceId]['allocatedAmount']=round((float)($store['supplierAdvances'][$advanceId]['allocatedAmount']??0)+$prepared['total'],2);$left=max(0,round((float)$store['supplierAdvances'][$advanceId]['amount']-(float)$store['supplierAdvances'][$advanceId]['allocatedAmount'],2));$store['supplierAdvances'][$advanceId]['availableAmount']=$left;$store['supplierAdvances'][$advanceId]['status']=$left>0.005?'Partly Applied':'Fully Applied';$result=$store['supplierSettlements'][$id];
        }
        else ss_respond(['ok'=>false,'error'=>'Unknown supplier settlement action.'],422);

        $store['revision']=(int)($store['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
    }finally{flock($h,LOCK_UN);fclose($h);}
    ss_respond(['ok'=>true,'result'=>$result,'revision'=>(int)$store['revision']]);
}catch(Throwable $e){
    ss_respond(['ok'=>false,'error'=>'The supplier settlement could not be completed.'],500);
}
