<?php
declare(strict_types=1);
require_once __DIR__.'/tg_native_currency.php';

/** Cash-flow presentation uses saved native principals, never today's rate to
 * reconstruct a USD balance from AED books. Other reports retain book currency.
 */
function cfn_identity(array $j,array $l):string {
    $code=(string)($l['account']??'');$m=(array)($j['meta']??[]);
    $party=(string)($l['counterparty']??$l['party']??$l['partyName']??$l['supplier']??$l['broker']??$l['customer']??'');
    $bank=(string)($l['bankAccountId']??$l['paymentAccountId']??($code==='1110'?($m['bankAccountId']??$m['paymentAccountId']??''):''));
    return 'BOOK-'.substr(hash('sha256',($j['entity']??'').'|'.$code.'|'.$bank.'|'.$party.'|'.($l['subaccountId']??'').(in_array($code,['1230','2140'],true)&&($l['exporterDealId']??$m['exporterDealId']??'')!==''?'|SODA|'.($l['exporterDealId']??$m['exporterDealId']):'')),0,24);
}
function cfn_receivable_line(array $state,array $j,array $line,int|string $index):array {
    if(($line['account']??'')!=='1210')return $line;
    $m=(array)($j['meta']??[]);$cid=(string)($line['candidateId']??$line['sourceCandidateId']??$m['candidateId']??'');$c=$state['exportCandidates'][$cid]??[];
    if(($c['entity']??'')!==($j['entity']??''))$c=[];
    if(trim((string)($line['counterparty']??$line['customer']??''))===''){$party=trim((string)($c['meta']['customer']??$m['customer']??''));if($party!=='')$line['counterparty']=$party;}
    $currency=(string)($line['transactionCurrency']??$line['nativeCurrency']??$c['transactionCurrency']??$m['transactionCurrency']??'');
    if(!in_array($currency,['USD','AED','PKR'],true))return $line;
    $line['currency']=$line['currency']??$currency;
    if(isset($line['nativeDebit'],$line['nativeCredit']))return $line;
    $native=$line['nativeAmount']??$line['transactionAmount']??null;
    $allocations=(array)($m['allocations']??$state['exportReceipts'][(string)($m['receiptId']??'')]['allocations']??[]);
    if($cid!==''&&$allocations){
        $matches=array_values(array_filter($allocations,static fn($a)=>($a['targetId']??'')===$cid&&($a['targetType']??'')==='EXPORT_RECEIVABLE'));
        $ordinal=0;$count=0;foreach((array)$j['lines'] as $i=>$l)if(($l['account']??'')==='1210'&&($l['candidateId']??$l['sourceCandidateId']??'')===$cid){if($i<$index)$ordinal++;$count++;}
        $a=$matches[$ordinal]??null;$book=round(abs((float)($line['debit']??0)-(float)($line['credit']??0)),2);
        if($count!==count($matches)||!is_array($a)||abs($book-(float)($a['carryingPkr']??-1))>.005)$line['_cashflowNativeUnavailable']=true;
        else $native=$a['foreignAmount'];
    }
    if($native!==null&&is_numeric($native)){$line['nativeDebit']=(float)($line['debit']??0)>0?(float)$native:0.;$line['nativeCredit']=(float)($line['credit']??0)>0?(float)$native:0.;}
    elseif(count(array_filter((array)$j['lines'],static fn($l)=>($l['account']??'')==='1210'))>1)$line['_cashflowNativeUnavailable']=true;
    return $line;
}
function cfn_source(array $source,array $state,string $date,array $masters):array {
    $bases=array_column($source['rows'],null,'id');$native=[];$warnings=[];
    $bankCurrencies=[];foreach($masters as $master){$v=array_values((array)($master['values']??[]));$bankCurrencies[(string)($master['id']??'')]=strtoupper((string)($v[7]??''));}
    foreach((array)($state['journals']??[]) as $postId=>$j){
        if(!in_array($j['status']??'',['Posted','Posted / Bill Pending'],true)||!mr_valid_date((string)($j['date']??''))||$j['date']>$date)continue;
        foreach((array)($j['lines']??[]) as $lineIndex=>$line){
            $base=$bases[cfn_identity($j,$line)]??null;if(!$base||!empty($base['excluded']))continue;
            $line=cfn_receivable_line($state,$j,$line,$lineIndex);$targetId=cfn_identity($j,$line);
            if(($line['account']??'')==='1110'&&!empty($base['bankAccountId'])){$identityLine=$line;unset($identityLine['party'],$identityLine['partyName'],$identityLine['supplier'],$identityLine['broker'],$identityLine['customer']);$identityLine['counterparty']='';$targetId=cfn_identity($j,$identityLine);$base['party']='';$base['label']=$base['bankLabel']!==''?'Bank Accounts — '.$base['bankLabel']:$base['label'];}
            if(($line['account']??'')==='1210'&&empty($base['party'])&&!empty($line['counterparty'])){$base['party']=$line['counterparty'];$base['label'].=' — '.$line['counterparty'];}
            $book=round((float)($line['debit']??0),2)-round((float)($line['credit']??0),2);$code=(string)($line['account']??'');
            // Inventory is replaced by its reviewed PKR market valuation once.
            $useNative=$code==='1110'||(($j['entity']??'')==='TG'&&!str_starts_with($code,'13'))||($code==='1210'&&in_array($line['currency']??'',['USD','AED'],true));
            if($useNative&&str_contains((string)($j['sourceType']??''),'REVALUATION'))continue;
            $copy=$j;$copy['meta']['currency']=$copy['meta']['currency']??$copy['meta']['transactionCurrency']??$base['currency'];
            if($code==='1110'&&empty($line['currency'])&&empty($line['bankCurrency'])){$bankCurrency=$bankCurrencies[(string)$base['bankAccountId']]??'';$line['currency']=$bankCurrency!==''?$bankCurrency:$base['currency'];}
            $nativeCurrency=(string)($line['currency']??$line['bankCurrency']??$copy['meta']['currency']);
            $n=$useNative&&$nativeCurrency!==$base['currency']?tgn_line($copy,$line):['currency'=>$base['currency'],'debit'=>round((float)($line['debit']??0),2),'credit'=>round((float)($line['credit']??0),2),'unavailable'=>false];
            if(!empty($line['_cashflowNativeUnavailable']))$n['unavailable']=true;
            $currency=(string)$n['currency'];$key=$targetId.'|'.$currency;
            if(!isset($native[$key])){$native[$key]=$base;$native[$key]['id']=$currency===$base['currency']?$targetId:'NATIVE-'.substr(hash('sha256',$key),0,24);$native[$key]['currency']=$currency;$native[$key]['amount']=0.;$native[$key]['bookCurrency']=$base['currency'];$native[$key]['bookAmount']=0.;$native[$key]['postIds']=[];$native[$key]['nativeUnavailable']=false;}
            $r=&$native[$key];$r['bookAmount']+=$book;$r['postIds'][(string)($j['id']??$postId)]=true;
            if(!empty($n['unavailable'])){$r['nativeUnavailable']=true;$warnings[]='Original '.$currency.' amount required for '.$base['label'].' · Post '.($j['id']??$postId).'.';}else $r['amount']+=(float)$n['debit']-(float)$n['credit'];unset($r);
        }
    }
    $rows=[];foreach($source['rows'] as $base)if(!empty($base['excluded']))$rows[]=$base;
    foreach($native as $r){$r['amount']=$r['nativeUnavailable']?null:round($r['amount'],2);$r['bookAmount']=round($r['bookAmount'],2);if($r['amount']!==null&&abs($r['amount'])<.005)continue;$r['postIds']=array_keys($r['postIds']);$r['originalCurrency']=$r['currency'];$r['originalAmount']=$r['amount'];$r['basis']='Original transaction currency for PKR cash-flow conversion; company books unchanged';$r['sourceFingerprint']=hash('sha256',json_encode($r,JSON_THROW_ON_ERROR));$rows[]=$r;}
    usort($rows,static fn($a,$b)=>strcmp($a['entity'].'|'.$a['account'].'|'.$a['label'].'|'.$a['currency'],$b['entity'].'|'.$b['account'].'|'.$b['label'].'|'.$b['currency']));
    $source['rows']=$rows;$source['warnings']=array_values(array_unique(array_merge($source['warnings']??[],$warnings)));$source['basis'].=' Cash-flow conversion uses original USD/AED principals with explicit PKR rates; no double conversion.';return $source;
}
