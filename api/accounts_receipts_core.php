<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_subaccounts_core.php';

/** Generic receipts select the credit side explicitly; expenses require an explicit refund. */
function arc_heads(array $s,string $e,string $kind='OTHER'):array {
    if(!in_array($kind,['OTHER','EXPENSE_REFUND'],true))throw new DomainException('Select the receipt nature.');
    $out=[];foreach(sac_entity_chart($s,$e) as $code=>$head){
        if(in_array($head['level']??'',['heading','system'],true)||in_array((string)$code,['1100','1110','1120','1430','1440','1610','2610','3300','3400'],true))continue;
        $class=(string)($head['class']??'');if($kind==='EXPENSE_REFUND'?$class!=='Expense':!in_array($class,['Asset','Liability','Equity','Revenue','Income'],true))continue;
        $out[]=['id'=>(string)$code,'name'=>(string)$head['name'],'class'=>$class];
    }return $out;
}
function arc_credit(array $s,string $e,array $b,float $amount):array {
    $payee=trim((string)($b['payee']??''));if(strlen($payee)>180)throw new DomainException('Received from must be at most 180 characters.');$kind=(string)($b['receiptKind']??'OTHER');$sub=trim((string)($b['subaccountId']??''));$a=$sub!==''?sac_resolve($s,$e,$sub):null;
    $code=trim((string)($b['creditAccountCode']??($a['parentCode']??'')));$head=null;foreach(arc_heads($s,$e,$kind) as $h)if($h['id']===$code){$head=$h;break;}
    if(!$head)throw new DomainException('Select a valid receipt credit account. Expense accounts require Expense refund.');
    if($a&&$a['parentCode']!==$code)throw new DomainException('The selected subledger does not belong to this credit account.');
    return ['account'=>$code,'accountName'=>$head['name'],'debit'=>0,'credit'=>round($amount,2),'counterparty'=>trim((string)($b['payee']??''))]+($a?sac_extra($a):[]);
}
