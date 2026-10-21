<?php
declare(strict_types=1);

/** Presentation only: never rewrite a saved voucher or infer a missing FX rate. */
function tgn_line(array $journal,array $line):array {
    $meta=(array)($journal['meta']??[]);
    $currency=strtoupper((string)($line['currency']??$line['bankCurrency']??$meta['currency']??$meta['transactionCurrency']??'AED'));
    if($currency==='')$currency='AED';
    $dr=$line['nativeDebit']??null;$cr=$line['nativeCredit']??null;
    if(($line['account']??'')==='1110'){$dr=$line['bankDebit']??$dr;$cr=$line['bankCredit']??$cr;}
    if($dr===null&&isset($line['chargeNative'])){$dr=(float)($line['debit']??0)>0?$line['chargeNative']:0;$cr=(float)($line['credit']??0)>0?$line['chargeNative']:0;}
    if($dr===null&&isset($line['vatNative'])){$dr=(float)($line['debit']??0)>0?$line['vatNative']:0;$cr=(float)($line['credit']??0)>0?$line['vatNative']:0;}
    if($dr===null&&($meta['targetAccount']??'')===($line['account']??'')&&(isset($meta['settlementAmountNative'])||isset($meta['amountNative']))){
        $amount=(float)($meta['settlementAmountNative']??$meta['amountNative']);$dr=(float)($line['debit']??0)>0?$amount:0;$cr=(float)($line['credit']??0)>0?$amount:0;
    }
    if($dr===null&&in_array($line['account']??'',['1210','1250','2500','2510'],true)&&isset($meta['transactionAmount'])){
        $dr=(float)($line['debit']??0)>0?$meta['transactionAmount']:0;$cr=(float)($line['credit']??0)>0?$meta['transactionAmount']:0;
    }
    if($dr===null||$cr===null){
        $rate=$currency==='AED'?1:0;
        foreach([$line['exchangeRate']??0,$line['recognitionRate']??0,$meta['recognitionRate']??0,$meta['functionalRate']??0,$meta['rate']??0,$meta['exchangeRate']??0] as $candidate)if($currency!=='AED'&&(float)$candidate>0){$rate=(float)$candidate;break;}
        if($rate<=0)return ['currency'=>$currency,'debit'=>null,'credit'=>null,'unavailable'=>true];
        $dr=(float)($line['debit']??0)/$rate;$cr=(float)($line['credit']??0)/$rate;
    }
    return ['currency'=>$currency,'debit'=>round((float)$dr,2),'credit'=>round((float)$cr,2),'unavailable'=>false];
}
function tgn_master_rate(string $currency,string $to='AED',string $date=''):?float {
    if($currency===$to)return 1.0;
    $rate=function_exists('tt_company_fx_rate')?tt_company_fx_rate('TG',$currency,$to):null;
    if($rate!==null&&$rate>0)return $rate;
    if($to!=='AED'){ $fromRate=tgn_master_rate($currency,'AED',$date);$toRate=tgn_master_rate($to,'AED',$date);return $fromRate&&$toRate?$fromRate/$toRate:null; }
    if($currency!=='USD'||!function_exists('tt_read_store'))return null;
    $best=null;$from='';
    foreach((array)(tt_read_store()['masters']['tg_currency_rates']??[]) as $row){$v=(array)($row['values']??[]);$status=strtolower((string)($v[10]??''));$effective=(string)($v[6]??'');if(str_contains($status,'inactive')||str_contains($status,'historical')||($date!==''&&($effective>$date||(!empty($v[7])&&$v[7]<$date))))continue;if($best===null||$effective>$from){$best=(float)((float)($v[5]??0)>0?$v[5]:($v[3]??0));$from=$effective;}}
    return $best!==null&&$best>0?$best:null;
}

/** Translate a report copy only. Native postings and stored historical values stay intact. */
function tgn_present(array $journal,string $currency,string $date):array {
    $lines=[];
    if(str_contains((string)($journal['sourceType']??''),'REVALUATION')){$journal['lines']=[];return $journal;}
    foreach((array)($journal['lines']??[]) as $line){
        if(($line['account']??'')==='7100')continue;
        $native=tgn_line($journal,$line);
        if($native['unavailable'])throw new DomainException('Native currency detail is missing for '.$journal['id'].'. Review its original bank/transaction record before translating reports.');
        $rate=tgn_master_rate($native['currency'],$currency,$date);
        if($rate===null)throw new DomainException('Configure '.$native['currency'].' / '.$currency.' in Super Admin → Companies → Trans Grains → Exchange rates before translating this report.');
        $line['debit']=round($native['debit']*$rate,2);$line['credit']=round($native['credit']*$rate,2);$line['presentationCurrency']=$currency;$lines[]=$line;
    }
    $difference=round(array_sum(array_column($lines,'credit'))-array_sum(array_column($lines,'debit')),2);
    if(abs($difference)>.005)$lines[]=['account'=>'7100','accountName'=>'Report Currency Translation','debit'=>max(0,$difference),'credit'=>max(0,-$difference),'presentationOnly'=>true];
    $journal['lines']=$lines;return $journal;
}
