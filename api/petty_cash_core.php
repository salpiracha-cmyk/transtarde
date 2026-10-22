<?php
declare(strict_types=1);

/** Stable cash identities; names are snapshots, never keys or reassigned staff identities. */
function pc_staff(array $s,string $e):array {
    $out=[];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    foreach(array_replace(sm_seed_masters(),(array)($s['salaryMasters']??[])) as $id=>$m){
        if(!is_array($m)||($m['entity']??'')!==$e||!in_array($m['category']??'',['MILL_STAFF','OFFICE_STAFF','HOME_STAFF'],true))continue;
        $out[(string)$id]=['id'=>(string)$id,'name'=>(string)$m['name'],'active'=>($m['status']??'Active')==='Active'&&(($m['effectiveFrom']??'')===''||$m['effectiveFrom']<=$today)&&(($m['effectiveTo']??'')===''||$m['effectiveTo']>=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'))];
    }
    return $out;
}
function pc_holders(array $s,string $e):array {
    $staff=pc_staff($s,$e);$out=[];
    foreach((array)($s['pettyCashHolders']??[]) as $id=>$h)if(is_array($h)&&($h['entity']??'')===$e){
        $h['id']=(string)$id;$h['active']=!empty($h['active'])&&!empty($staff[$h['staffId']]['active']);
        $out[(string)$id]=$h;
    }
    return $out;
}
function pc_line_id(array $line,string $e):string {
    if(!empty($line['millLocationId']))return 'CASH|'.$e.'|MILL|'.$line['millLocationId'];
    $id=(string)($line['cashAccountId']??$line['paymentAccountId']??'');
    return str_starts_with($id,'CASH|'.$e)?$id:'CASH|'.$e;
}
function pc_rows(array $s,string $e):array {
    $s=alv_amendment_books($s);$out=[];
    foreach((array)($s['journals']??[]) as $key=>$j){
        if(!is_array($j)||($j['entity']??'')!==$e||!alv_posted($j))continue;
        // Current source rows only. Amendments retain their public ID and immutable audit elsewhere.
        if(!empty($j['reversalOf'])||!empty($j['meta']['reversalOf'])||!empty($j['reversedByPostId'])||!empty($j['amendedByPostId']))continue;
        foreach((array)($j['lines']??[]) as $i=>$line)if(($line['account']??'')==='1120'){
            $party=(string)($j['meta']['payee']??$line['counterparty']??'');
            if($party==='')foreach((array)$j['lines'] as $other)if(($other['account']??'')!=='1120'&&!empty($other['counterparty'])){$party=$other['counterparty'];break;}
            $out[]=['id'=>(string)$key.'|'.$i,'journalId'=>(string)$key,'postId'=>(string)($j['meta']['publicPostId']??$j['id']??$key),'date'=>(string)$j['date'],'cashId'=>pc_line_id($line,$e),'payee'=>$party,'narration'=>(string)($line['memo']??$j['meta']['paymentNarration']??$j['narration']??''),'received'=>round((float)($line['debit']??0),2),'paid'=>round((float)($line['credit']??0),2),'source'=>(string)($j['sourceType']??''),'expenseId'=>(string)($j['meta']['generalExpenseId']??'')];
        }
    }
    usort($out,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['journalId'],$b['journalId'])?:strcmp($a['id'],$b['id']));
    $balances=[];foreach($out as &$row){$id=$row['cashId'];$balances[$id]=round(($balances[$id]??0)+$row['received']-$row['paid'],2);$row['balance']=$balances[$id];}unset($row);
    return $out;
}
function pc_balance(array $s,string $e,string $id,?string $date=null):float {
    $date=tt_accounts_balance_date($date);$n=0;foreach(pc_rows($s,$e) as $row)if($row['cashId']===$id&&$row['date']<=$date)$n+=$row['received']-$row['paid'];return round($n,2);
}
function pc_assert_balances(array $s,string $e):void {
    // Validate by day so an intra-day transfer/expense order does not invent a negative balance.
    $days=[];foreach(pc_rows($s,$e) as $row)if(str_contains($row['cashId'],'|STAFF|'))$days[$row['date']][$row['cashId']]=($days[$row['date']][$row['cashId']]??0)+$row['received']-$row['paid'];
    ksort($days);$balances=[];foreach($days as $rows)foreach($rows as $id=>$net){$balances[$id]=round(($balances[$id]??0)+$net,2);if($balances[$id]<-.005)throw new DomainException('This entry would spend or transfer more than the staff cash held. Check the amount and date.');}
}
function pc_account(array $s,string $e,string $id,bool $incoming=false):array {
    if($id==='CASH|'.$e){if(isset($s['bankAccountSettings'][$id]['active'])&&!$s['bankAccountSettings'][$id]['active'])throw new DomainException('Office petty cash is inactive.');return ['id'=>$id,'name'=>'Office Petty Cash','active'=>true];}
    $h=pc_holders($s,$e)[$id]??null;if(!$h)throw new DomainException('Select a cash holder in this company.');
    if($incoming&&!$h['active'])throw new DomainException('Inactive cash holders cannot receive new cash. Settle or return their remaining cash.');
    return $h;
}
function pc_credit(array $s,string $e,string $id,float $amount,array $names):array {
    $h=pc_account($s,$e,$id);return ev1_line('1120',0,$amount,$names,['cashAccountId'=>$id,'paymentAccountId'=>$id,'cashAccountName'=>'Cash with '.$h['name'],'subledger'=>'Cash with '.$h['name'],'currency'=>'PKR','paymentType'=>'Cash']);
}
function pc_manage(array &$s,string $e,array $b,array $u):array {
    $op=(string)($b['operation']??'add');$id=(string)($b['holderId']??'');$old=null;
    if($op==='add'){
        $staff=pc_staff($s,$e)[(string)($b['staffId']??'')]??null;if(!$staff||!$staff['active'])throw new DomainException('Choose an active employee from Staff Master.');
        $id='CASH|'.$e.'|STAFF|'.$staff['id'];$old=$s['pettyCashHolders'][$id]??null;
        $h=['id'=>$id,'entity'=>$e,'staffId'=>$staff['id'],'name'=>$old['name']??$staff['name'],'active'=>true];
    }elseif(in_array($op,['inactive','active'],true)){
        $old=$s['pettyCashHolders'][$id]??null;if(!$old||$old['entity']!==$e)throw new DomainException('Cash holder not found.');
        if($op==='active'&&empty(pc_staff($s,$e)[$old['staffId']]['active']))throw new DomainException('This employee is inactive in Staff Master.');
        $h=array_replace($old,['active'=>$op==='active']);
    }else throw new DomainException('Unknown cash holder operation.');
    $h['updatedAt']=gmdate('c');$h['updatedBy']=$u['username']??'';$s['pettyCashHolders'][$id]=$h;
    $s['pettyCashAudit'][]=['action'=>'HOLDER_'.$op,'before'=>$old,'after'=>$h,'by'=>$u['username']??'','at'=>gmdate('c')];return ['holderId'=>$id];
}
function pc_post(array &$s,string $e,array $b,array $u,array $names):array {
    $date=ev1_date((string)($b['date']??''));$type=(string)($b['type']??'');$from=(string)($b['from']??'');$source=pc_account($s,$e,$from);$amount=ev1_amount($b['amount']??0);
    $note=trim((string)($b['narration']??''));if($note===''||strlen($note)>500)throw new DomainException('Enter a narration of up to 500 characters.');
    if($type==='EXPENSE'){
        if(str_contains($from,'|STAFF|')&&empty($b['confirmed']))throw new DomainException('Confirm the staff spending amount before settling.');
        $payee=trim((string)($b['payee']??''));if($payee==='')throw new DomainException('Enter who was paid, or the cash holder for a verbal settlement.');
        $result=dex_post($s,$e,['paymentDate'=>$date,'paymentAccountId'=>$from,'payee'=>$payee,'paymentNarration'=>$note,'expenseLines'=>[['category'=>'OFFICE','accountCode'=>(string)($b['accountCode']??''),'amount'=>$amount,'purpose'=>$note,'expenseArea'=>'OFFICE','expenseFor'=>'SHARED','personalTreatment'=>'COMPANY']]],$u,$names,[]);
    }elseif($type==='TRANSFER'){
        $to=(string)($b['to']??'');$destination=pc_account($s,$e,$to,true);if($from===$to)throw new DomainException('Choose different cash accounts.');
        $credit=$from==='CASH|'.$e?ev1_line('1120',0,$amount,$names,['cashAccountId'=>$from,'paymentAccountId'=>$from,'subledger'=>'Office Petty Cash','currency'=>'PKR']):pc_credit($s,$e,$from,$amount,$names);
        $label=$to==='CASH|'.$e?'Office Petty Cash':'Cash with '.$destination['name'];
        $debit=ev1_line('1120',$amount,0,$names,['cashAccountId'=>$to,'paymentAccountId'=>$to,'subledger'=>$label,'cashAccountName'=>$label,'currency'=>'PKR']);
        $id=ev1_journal($s,$e,$date,'PETTY_CASH_TRANSFER','',$note,[$debit,$credit],$u,['payee'=>$source['name'].' → '.$destination['name'],'pettyCash'=>true,'fromCashId'=>$from,'toCashId'=>$to]);$result=['journalId'=>$id];
    }else throw new DomainException('Select expense or transfer.');
    pc_assert_balances($s,$e);return $result;
}
function pc_payload(array $s,string $e,array $u):array {
    $holders=[];foreach(pc_holders($s,$e) as $h)$holders[]=$h+['balance'=>pc_balance($s,$e,$h['id'])];
    $chart=[];$names=ev1_names();foreach(sac_entity_chart($s,$e) as $code=>$head)if(($head['class']??'')==='Expense'&&!in_array($head['level']??'',['heading','system'],true)&&isset($names[(string)$code]))$chart[]=['id'=>(string)$code,'name'=>$head['name']];
    return ['ok'=>true,'entity'=>$e,'revision'=>(int)($s['revision']??0),'officeBalance'=>pc_balance($s,$e,'CASH|'.$e),'holders'=>$holders,'staff'=>array_values(pc_staff($s,$e)),'rows'=>pc_rows($s,$e),'expenseHeads'=>$chart,'canCreate'=>tt_user_can_module_action($u,'Accounts','expenses','Create')&&tt_user_can_access_entity($u,$e,'Create'),'canEdit'=>tt_user_can_module_action($u,'Accounts','expenses','Edit')&&tt_user_can_access_entity($u,$e,'Edit')];
}
