<?php
declare(strict_types=1);

/** Invoice settlement projection; reads the same active allocations as Party Payments. */
function eiv_balance(array $store,array $bill): array {
    $allocated=0.0;$cash=0.0;$discount=0.0;$pending=0.0;$payments=[];
    foreach((array)($store['supplierSettlements']??[]) as $p){
        if(($p['entity']??'')!==($bill['entity']??'')||!in_array($p['status']??'',['Posted','Approved / Posted'],true))continue;
        foreach((array)($p['allocations']??[]) as $a){
            if(($a['billId']??'')!==($bill['id']??''))continue;
            $amount=round((float)($a['amount']??0),2);$allocated+=$amount;$discount+=(float)($a['discountAmount']??0);
            $issued=!empty($p['chequeStatus'])&&($p['chequeStatus']??'')!=='Cleared';
            if($issued)$pending+=(float)($a['paidAmount']??$amount);else $cash+=(float)($a['paidAmount']??$amount);
            $payments[]=['postId'=>$p['journalId']??'','date'=>$p['date']??'','allocated'=>$amount,'discount'=>$a['discountAmount']??0,'pendingCheque'=>$issued];
        }
    }
    $remaining=max(0,round((float)($bill['supplierPayableTotal']??0)-$allocated,2));
    $status=($bill['status']??'Posted')==='Cancelled'?'Cancelled':($remaining<=.005?($pending>.005?'Awaiting cheque clearance':'Paid'):($allocated>.005?'Part-paid':'Unpaid'));
    return ['allocated'=>round($allocated,2),'cashPaid'=>round($cash,2),'discount'=>round($discount,2),'pendingCheque'=>round($pending,2),'outstanding'=>$status==='Cancelled'?0.0:$remaining,'paymentStatus'=>$status,'payments'=>$payments];
}
function eiv_invoices(array $store,string $entity): array {
    $out=[];foreach((array)($store['supplierBills']??[]) as $bill){
        if(($bill['entity']??'')!==$entity||empty($bill['expenseInvoice']))continue;
        $out[]=array_merge($bill,eiv_balance($store,$bill));
    }
    usort($out,static fn($a,$b)=>strcmp($b['billDate'],$a['billDate'])?:strcmp($b['id'],$a['id']));return $out;
}
function eiv_assert_not_captured(array $store,string $entity,string $payee,string $reference,string $master='',string $month=''): void {
    foreach((array)($store['supplierBills']??[]) as $bill){
        if(($bill['entity']??'')!==$entity||empty($bill['expenseInvoice'])||($bill['status']??'')!=='Posted')continue;
        $sameRef=$reference!==''&&strcasecmp(trim((string)($bill['billNo']??'')),trim($reference))===0&&strcasecmp(trim((string)($bill['vendor']??'')),trim($payee))===0;
        $sameMonth=$master!==''&&($bill['utilityMasterId']??'')===$master&&($bill['billMonth']??'')===$month;
        if($sameRef||$sameMonth)throw new InvalidArgumentException('This invoice is already recorded. Pay its outstanding balance through Party Payments instead of recording the expense again.');
    }
}

function tt_reminder_due_date(string $month,int $day): string {
    $first=new DateTimeImmutable($month.'-01');
    return $month.'-'.str_pad((string)max(1,min($day,(int)$first->format('t'))),2,'0',STR_PAD_LEFT);
}

/** Recurring commitments create reminders only, never journals or payables. */
function tt_expense_monthly_reminders(array $store,string $entity,string $month): array {
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$rows=[];
    foreach(['UTILITY'=>'utilityMasters','CREDIT_CARD'=>'creditCardMasters'] as $type=>$collection){
        foreach((array)($store[$collection]??[]) as $master){
            if(!is_array($master)||($master['entity']??'')!==$entity||($master['status']??'Active')!=='Active'||(!empty($master['startMonth'])&&$month<$master['startMonth']))continue;
            $due=tt_reminder_due_date($month,(int)($master['dueDay']??10));$paid=false;$statement=null;$invoiceRows=[];
            if($type==='UTILITY'){
                foreach((array)($store['utilityPayments']??[]) as $payment)if(($payment['entity']??'')===$entity&&($payment['status']??'')!=='Deleted'&&($payment['utilityMasterId']??'')===$master['id']&&($payment['billMonth']??'')===$month){$paid=true;break;}
                $invoiceRows=array_values(array_filter(eiv_invoices($store,$entity),static fn($bill)=>($bill['status']??'')==='Posted'&&($bill['invoiceKind']??'')==='UTILITY'&&($bill['utilityMasterId']??'')===$master['id']&&($bill['billMonth']??'')===$month));
                if($invoiceRows){$due=(string)($invoiceRows[0]['dueDate']??$due);$paid=!array_filter($invoiceRows,static fn($bill)=>$bill['paymentStatus']!=='Paid');}
                $label=($master['utilityTypeName']??$master['utilityType']).' — '.($master['locationName']??'');
            }else{
                foreach((array)($store['creditCardStatements']??[]) as $candidate)if(($candidate['entity']??'')===$entity&&($candidate['status']??'')!=='Deleted'&&($candidate['cardMasterId']??'')===$master['id']&&($candidate['statementMonth']??'')===$month){$statement=$candidate;break;}
                if($statement){$due=(string)$statement['dueDate'];$paid=($statement['status']??'')==='Paid';}
                $label=($master['cardName']??'Credit Card').' — '.$month;
            }
            $rows[]=['type'=>$type,'masterId'=>$master['id'],'invoiceId'=>$invoiceRows[0]['id']??null,'supplier'=>$invoiceRows[0]['vendor']??'','statementId'=>$statement['id']??null,'label'=>$label,'month'=>$month,'dueDate'=>$due,'remindOn'=>(new DateTimeImmutable($due))->modify('-'.max(1,min(31,(int)($master['remindDays']??7))).' days')->format('Y-m-d'),'status'=>$paid?'Paid':($due<$today?'Overdue':($type==='CREDIT_CARD'&&!$statement?'Statement needed':'Due')),'paid'=>$paid,'amount'=>$statement['total']??null];
        }
    }
    usort($rows,static fn($a,$b)=>strcmp($a['dueDate'],$b['dueDate']));return $rows;
}

function tt_expense_due_reminders(array $store,string $entity): array {
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi'));$current=$now->format('Y-m');$start=$current;
    foreach(['utilityMasters','creditCardMasters'] as $collection)foreach((array)($store[$collection]??[]) as $master){$month=(string)($master['startMonth']??'');if(($master['entity']??'')===$entity&&($master['status']??'Active')==='Active'&&preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)&&$month<$start)$start=$month;}
    foreach(['utilityMasters','creditCardMasters'] as $collection)foreach($store[$collection]??[] as $id=>$master)if(empty($master['startMonth']))$store[$collection][$id]['startMonth']=$current;
    $end=$now->modify('first day of next month')->format('Y-m');$rows=[];
    for($date=new DateTimeImmutable($start.'-01');$date->format('Y-m')<=$end;$date=$date->modify('+1 month')){
        foreach(tt_expense_monthly_reminders($store,$entity,$date->format('Y-m')) as $reminder)if(!$reminder['paid']&&$reminder['remindOn']<=$now->format('Y-m-d'))$rows[]=$reminder;
    }
    return $rows;
}
