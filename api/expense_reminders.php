<?php
declare(strict_types=1);

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
            $due=tt_reminder_due_date($month,(int)($master['dueDay']??10));$paid=false;$statement=null;
            if($type==='UTILITY'){
                foreach((array)($store['utilityPayments']??[]) as $payment)if(($payment['entity']??'')===$entity&&($payment['status']??'')!=='Deleted'&&($payment['utilityMasterId']??'')===$master['id']&&($payment['billMonth']??'')===$month){$paid=true;break;}
                $label=($master['utilityTypeName']??$master['utilityType']).' — '.($master['locationName']??'');
            }else{
                foreach((array)($store['creditCardStatements']??[]) as $candidate)if(($candidate['entity']??'')===$entity&&($candidate['status']??'')!=='Deleted'&&($candidate['cardMasterId']??'')===$master['id']&&($candidate['statementMonth']??'')===$month){$statement=$candidate;break;}
                if($statement){$due=(string)$statement['dueDate'];$paid=($statement['status']??'')==='Paid';}
                $label=($master['cardName']??'Credit Card').' — '.$month;
            }
            $rows[]=['type'=>$type,'masterId'=>$master['id'],'statementId'=>$statement['id']??null,'label'=>$label,'month'=>$month,'dueDate'=>$due,'remindOn'=>(new DateTimeImmutable($due))->modify('-'.max(1,min(31,(int)($master['remindDays']??7))).' days')->format('Y-m-d'),'status'=>$paid?'Paid':($due<$today?'Overdue':($type==='CREDIT_CARD'&&!$statement?'Statement needed':'Due')),'paid'=>$paid,'amount'=>$statement['total']??null];
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
