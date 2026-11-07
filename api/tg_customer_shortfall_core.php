<?php
declare(strict_types=1);
require_once __DIR__.'/tg_native_currency.php';
function tgcs_balances(array $store,string $through):array {
    $balances=[];
    foreach($store['journals']??[] as $journal) {
        if(($journal['entity']??'')!=='TG'||($journal['status']??'')!=='Posted'||($journal['date']??'')>$through)continue;
        foreach($journal['lines']??[] as $line) {
            if(($line['account']??'')!=='1210')continue;
            $native=tgn_line($journal,$line);
            if(($native['currency']??'')!=='USD'||!empty($native['unavailable']))continue;
            $party=trim((string)(($line['counterparty']??'')?:($line['subledger']??'')));
            if($party==='')continue;
            $balances[$party]=($balances[$party]??0)+(int)round(($native['debit']-$native['credit'])*100);
        }
    }
    ksort($balances);return $balances;
}
function tgcs_prepare(array $store,array $plan,array $user,float $rate):array {
    if(($user['role']??'')!=='Super Admin')throw new DomainException('Super Admin access required.');
    if(!is_finite($rate)||$rate<=0)throw new DomainException('Configure the USD/AED master rate.');
    $date=(string)($plan['date']??'');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date||$date<'2026-01-01'||$date>'2026-12-31')throw new DomainException('Select a valid 2026 adjustment date.');
    $ref=trim((string)($plan['reference']??''));$note=trim((string)($plan['narration']??''));
    if(!preg_match('/^[A-Za-z0-9._-]{12,80}$/D',$ref)||$note===''||strlen($note)>500)throw new DomainException('Enter a unique reference and explanation.');
    $hash=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));
    foreach($store['journals']??[] as $j)if(($j['entity']??'')==='TG'&&($j['sourceType']??'')==='TG_LEGACY_CUSTOMER_SHORTFALL'&&($j['reference']??'')===$ref){
        if(($j['meta']['planHash']??'')!==$hash)throw new DomainException('Reference already used with different details.');
        return ['replayed'=>true,'journal'=>$j];
    }
    $rows=$plan['rows']??[];if(!is_array($rows)||count($rows)<1||count($rows)>20)throw new DomainException('Select one to twenty customer balances.');
    $atDate=tgcs_balances($store,$date);$current=tgcs_balances($store,'9999-12-31');$seen=[];$lines=[];
    foreach($rows as $row){
        $party=trim((string)($row['party']??''));$amount=(string)($row['amount']??'');
        if(isset($seen[$party])||!preg_match('/^\d{1,4}\.\d{2}$/D',$amount))throw new DomainException('Select distinct customers and exact USD amounts.');
        $seen[$party]=true;$cents=(int)round((float)$amount*100);
        if($cents<=0||$cents>100000||($atDate[$party]??0)!==$cents||($current[$party]??0)!==$cents)throw new DomainException('The USD amount must equal the customer receivable at the adjustment date and now (maximum USD 1,000). Refresh and review.');
        $book=round($cents/100*$rate,2);
        $common=['currency'=>'USD','nativeCurrency'=>'USD','memo'=>$note];
        $lines[]=$common+['account'=>'6810','accountName'=>'Correspondent Bank Charges','debit'=>$book,'credit'=>0,'nativeDebit'=>$cents/100,'nativeCredit'=>0,'chargeNative'=>$cents/100,'chargeType'=>'Historical customer short payment','shortfallCustomer'=>$party];
        $lines[]=$common+['account'=>'1210','accountName'=>'Customer / Export Receivables','counterparty'=>$party,'subledger'=>$party,'debit'=>0,'credit'=>$book,'nativeDebit'=>0,'nativeCredit'=>$cents/100];
    }
    $total=round(array_sum(array_column($lines,'debit')),2);$now=gmdate('c');
    return ['replayed'=>false,'journal'=>['entity'=>'TG','date'=>$date,'reference'=>$ref,'narration'=>$note,'sourceType'=>'TG_LEGACY_CUSTOMER_SHORTFALL','status'=>'Posted','lines'=>$lines,'totalDebit'=>$total,'totalCredit'=>$total,'meta'=>['planHash'=>$hash,'transactionCurrency'=>'USD','functionalRate'=>$rate,'adjustmentOnly'=>true,'noBankMovement'=>true],'createdAt'=>$now,'createdBy'=>$user['full_name']??'Super Admin','userId'=>(int)($user['id']??0),'approvedAt'=>$now,'approvedBy'=>$user['full_name']??'Super Admin','reversalOf'=>null]];
}
