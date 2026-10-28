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

/** Estimate only a missing bill's next due date from its last recorded billing cycle.
 * A bill month and its due month are separate; payment dates never define a cycle.
 */
function tt_expense_cycle_due(array $store,string $entity,string $month,array $master,string $type):string {
    $latest=null;$monthKey=$type==='CREDIT_CARD'?'statementMonth':'billMonth';$idKey=$type==='CREDIT_CARD'?'cardMasterId':'utilityMasterId';
    $records=$type==='CREDIT_CARD'?(array)($store['creditCardStatements']??[]):array_merge((array)($store['supplierBills']??[]),(array)($store['utilityPayments']??[]));
    foreach($records as $record){
        if(!is_array($record)||($record['entity']??'')!==$entity||($record[$idKey]??'')!==$master['id']||in_array($record['status']??'',['Deleted','Cancelled','Reversed','Amended'],true))continue;
        if($type==='UTILITY'&&isset($record['expenseInvoice'])&&empty($record['expenseInvoice']))continue;
        $cycle=(string)($record[$monthKey]??'');$due=(string)($record['dueDate']??'');
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$cycle)||$cycle>=$month||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$due,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))continue;
        if($latest===null||strcmp($cycle,$latest[$monthKey])>0||($cycle===$latest[$monthKey]&&strcmp($due,$latest['dueDate'])>0))$latest=$record;
    }
    if($latest===null)return tt_reminder_due_date($month,(int)($master['dueDay']??10));
    $cycle=$latest[$monthKey];$due=$latest['dueDate'];$offset=12*((int)substr($due,0,4)-(int)substr($cycle,0,4))+(int)substr($due,5,2)-(int)substr($cycle,5,2);
    if($offset<0||$offset>12)return tt_reminder_due_date($month,(int)($master['dueDay']??10));
    $dueMonth=(new DateTimeImmutable($month.'-01'))->modify('+'.$offset.' months')->format('Y-m');$day=(int)substr($due,8,2);
    // Preserve a configured 29/30/31 when the previous bill was capped by February.
    if($day===(int)(new DateTimeImmutable($due))->format('t')&&(int)($master['dueDay']??0)>$day)$day=(int)$master['dueDay'];
    return tt_reminder_due_date($dueMonth,$day);
}

/** Recurring commitments create reminders only, never journals or payables. */
function tt_expense_monthly_reminders(array $store,string $entity,string $month,?DateTimeImmutable $asOf=null): array {
    $today=($asOf??new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$rows=[];
    foreach(['UTILITY'=>'utilityMasters','CREDIT_CARD'=>'creditCardMasters'] as $type=>$collection){
        foreach((array)($store[$collection]??[]) as $master){
            if(!is_array($master)||($master['entity']??'')!==$entity||($master['status']??'Active')!=='Active'||(!empty($master['startMonth'])&&$month<$master['startMonth']))continue;
            $due=tt_expense_cycle_due($store,$entity,$month,$master,$type);$paid=false;$statement=null;$invoiceRows=[];$recorded=false;
            if($type==='UTILITY'){
                foreach((array)($store['utilityPayments']??[]) as $payment)if(($payment['entity']??'')===$entity&&!in_array($payment['status']??'',['Deleted','Cancelled','Reversed','Amended'],true)&&($payment['utilityMasterId']??'')===$master['id']&&($payment['billMonth']??'')===$month){$paid=true;$recorded=true;break;}
                $invoiceRows=array_values(array_filter(eiv_invoices($store,$entity),static fn($bill)=>($bill['status']??'')==='Posted'&&($bill['invoiceKind']??'')==='UTILITY'&&($bill['utilityMasterId']??'')===$master['id']&&($bill['billMonth']??'')===$month));
                if($invoiceRows){
                    // A paid invoice must not hide a second unpaid invoice in this cycle.
                    $open=array_values(array_filter($invoiceRows,static fn($bill)=>$bill['paymentStatus']!=='Paid'));
                    usort($open,static fn($a,$b)=>strcmp($a['dueDate']??'',$b['dueDate']??'')?:strcmp($a['id'],$b['id']));
                    $invoiceRows=$open?:$invoiceRows;$due=(string)($invoiceRows[0]['dueDate']??$due);$paid=!$open;$recorded=true;
                }
                $label=($master['utilityTypeName']??$master['utilityType']).' — '.($master['locationName']??'');
            }else{
                $statements=array_values(array_filter((array)($store['creditCardStatements']??[]),static fn($candidate)=>is_array($candidate)&&($candidate['entity']??'')===$entity&&!in_array($candidate['status']??'',['Deleted','Cancelled','Reversed'],true)&&($candidate['cardMasterId']??'')===$master['id']&&($candidate['statementMonth']??'')===$month));
                usort($statements,static fn($a,$b)=>(($a['status']??'')==='Paid'?1:0)<=>(($b['status']??'')==='Paid'?1:0)?:strcmp($a['dueDate']??'',$b['dueDate']??''));
                $statement=$statements[0]??null;
                if($statement){$due=(string)$statement['dueDate'];$paid=($statement['status']??'')==='Paid';$recorded=true;}
                $label=($master['cardName']??'Credit Card').' — '.$month;
            }
            $rows[]=['type'=>$type,'masterId'=>$master['id'],'invoiceId'=>$invoiceRows[0]['id']??null,'supplier'=>$invoiceRows[0]['vendor']??'','statementId'=>$statement['id']??null,'label'=>$label,'month'=>$month,'dueDate'=>$due,'remindOn'=>(new DateTimeImmutable($due))->modify('-'.max(1,min(31,(int)($master['remindDays']??7))).' days')->format('Y-m-d'),'estimatedDueDate'=>!$recorded,'status'=>$paid?'Paid':($due<$today?'Overdue':($type==='CREDIT_CARD'&&!$statement?'Statement needed':'Due')),'paid'=>$paid,'amount'=>$statement['total']??null];
        }
    }
    usort($rows,static fn($a,$b)=>strcmp($a['dueDate'],$b['dueDate']));return $rows;
}

function tt_expense_due_reminders(array $store,string $entity,?DateTimeImmutable $asOf=null): array {
    $now=$asOf??new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi'));$current=$now->format('Y-m');$start=$current;
    foreach(['utilityMasters','creditCardMasters'] as $collection)foreach((array)($store[$collection]??[]) as $master){$month=(string)($master['startMonth']??'');if(($master['entity']??'')===$entity&&($master['status']??'Active')==='Active'&&preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)&&$month<$start)$start=$month;}
    foreach(['utilityMasters','creditCardMasters'] as $collection)foreach($store[$collection]??[] as $id=>$master)if(empty($master['startMonth']))$store[$collection][$id]['startMonth']=$current;
    $end=$now->modify('first day of next month')->format('Y-m');$rows=[];
    for($date=new DateTimeImmutable($start.'-01');$date->format('Y-m')<=$end;$date=$date->modify('+1 month')){
        foreach(tt_expense_monthly_reminders($store,$entity,$date->format('Y-m'),$now) as $reminder){
            // A forecast alone does not establish an unpaid future bill. Recorded
            // invoices still remind before their month starts when actually due.
            if(!empty($reminder['estimatedDueDate'])&&$reminder['month']>$current)continue;
            if(!$reminder['paid']&&$reminder['remindOn']<=$now->format('Y-m-d'))$rows[]=$reminder;
        }
    }
    usort($rows,static fn($a,$b)=>strcmp($a['dueDate'],$b['dueDate'])?:strcmp($a['label'],$b['label']));return $rows;
}
