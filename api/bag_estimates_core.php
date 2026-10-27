<?php
declare(strict_types=1);
require_once __DIR__.'/expense_estimates_core.php';
require_once __DIR__.'/bag_order_report_core.php';
/** Dated physical receipts minus posted invoices; no orders-as-receipts or guessed tax rates. */
function bag_estimates_rows(array $state,string $date,array $values=[]):array {
    $read=static function(string $key)use($values):array{$v=$values[$key]??[];if(is_string($v))$v=json_decode($v,true,512,JSON_THROW_ON_ERROR);if(!is_array($v))throw new DomainException('Invalid bag receipt source.');return $v;};
    $orders=(array)($state['bagPurchaseOrders']??[]);$mill=$read('tt30bags');$receipts=$read('tt33bagreceipts');$events=$read('tt41bagtransfers');$root=$read('transtrade_export_v3_operational');$out=[];$warnings=[];$sourceWarnings=[];
    foreach(['TTI','BRM'] as $entity){
        $report=bor_report(bor_original_dates($orders,$root,$entity),$mill,$receipts,$entity,['from'=>'1900-01-01','to'=>$date,'basis'=>'all'],(array)($events['receiptDispositions']??[]));
        foreach($report['warnings'] as $warning)$warnings[]=$entity.' bags · '.$warning;
        $history=ee_actuals($state,$entity);$actualKeys=array_fill_keys(array_column($history,'key'),true);
        foreach($report['rows'] as $r){
            $po=null;$line=null;foreach($orders as $candidate)if(($candidate['entity']??'TTI')===$entity&&($candidate['poNo']??'')===$r['poNo']){if($po!==null)throw new DomainException('Duplicate bag PO identity requires review.');$po=$candidate;}
            foreach((array)($po['lines']??[]) as $candidate)if(($candidate['lineKey']??'')===$r['lineKey']){if($line!==null)throw new DomainException('Duplicate bag PO line identity requires review.');$line=$candidate;}
            if(!$po||!$line||in_array($po['status']??'',['Cancelled','Deleted'],true))continue;
            $billed=0.;foreach(['bagSupplierBills','nonWovenBagSupplierBills'] as $collection)foreach((array)($state[$collection]??[]) as $id=>$bill){
                if(!isset($actualKeys[$collection.'|'.$id])||($bill['poNo']??'')!==$r['poNo']||($bill['lineKey']??'')!==$r['lineKey']||($bill['sellerInvoiceDate']??'')>$date)continue;
                $billed+=(float)($bill['bags']??0);
            }
            $quantity=max(0.,(float)$r['netReceivedThroughEnd']-$billed);if($quantity<=0)continue;
            $currency=strtoupper((string)($po['currency']??'PKR'));$proof=null;
            foreach($history as $h)if($h['date']<=$date&&$h['currency']===$currency&&ee_key($h['supplier'])===ee_key($r['supplier'])&&ee_packing($h['packingSize'])===ee_packing($r['packingSize'])&&ee_key($h['specification'])===ee_key($r['bagType'])&&$h['unitRate']>0){$proof=$h;break;}
            $rate=$proof['unitRate']??null;$tax=$proof['taxRate']??null;$rateSource=$proof?'Comparable bill '.$proof['reference'].' · '.$proof['date']:'';
            // Explicit agreed PO rate is a supported fallback. A missing tax
            // designation is unknown, not an assumed exemption.
            if($rate===null&&is_numeric($line['ratePerBag']??null)&&(float)$line['ratePerBag']>0){
                $rate=(float)$line['ratePerBag'];$rateSource='Agreed PO rate';
                if(array_key_exists('salesTaxInvoiceDefault',$line)){
                    $tax=empty($line['salesTaxInvoiceDefault'])?0.:null;
                    if(!empty($line['salesTaxInvoiceDefault'])){$rates=array_values(array_filter((array)($state['bagTaxRates']??[]),static fn($t)=>($t['effectiveDate']??'')<=$date&&is_numeric($t['rate']??null)));usort($rates,static fn($a,$b)=>strcmp($b['effectiveDate'],$a['effectiveDate']));if($rates)$tax=(float)$rates[0]['rate'];}
                }
            }
            $reason='';if($r['supplier']==='')$reason='Supplier identity required.';
            elseif(!in_array($currency,['PKR','USD','AED'],true))$reason='Confirm the original bag currency.';
            elseif($rate===null||$tax===null||!is_finite($rate)||!is_finite($tax)||$tax<0||$tax>100)$reason='Comparable bill or agreed bag rate and sales-tax treatment required.';
            $gross=$reason===''?round($quantity*$rate*(1+$tax/100),2):null;if($gross!==null&&(!is_finite($gross)||$gross>100000000000)){$gross=null;$reason='Estimated amount exceeds the supported limit.';}
            $sourceKey='BAG|'.$r['poNo'].'|'.$r['lineKey'];$label=$r['supplier'].' — '.$r['poNo'].' / '.$r['lineKey'].' · '.$r['packingSize'].' bags';
            $row=['id'=>'AUTO-BAG-'.substr(hash('sha256',$entity.'|'.$sourceKey),0,24),'type'=>'FINANCIAL','entity'=>$entity,'account'=>'2210','party'=>$r['supplier'],'label'=>mb_strcut($label,0,280,'UTF-8'),'currency'=>in_array($currency,['PKR','USD','AED'],true)?$currency:'PKR','originalCurrency'=>$currency,'amount'=>$gross===null?null:-$gross,'originalAmount'=>$gross===null?null:-$gross,'cashflowSection'=>'estimates','source'=>'Received bags awaiting invoice','sourceKind'=>'BAGS','sourceKey'=>$sourceKey,'sourceDate'=>$date,'shipmentId'=>(string)($po['shipmentId']??''),'shipmentRef'=>$r['shipment'],'expenseKind'=>'BAGS','postIds'=>[],'excluded'=>$reason!=='','reason'=>$reason,'quantity'=>$quantity,'unitRate'=>$rate,'taxRate'=>$tax,'grossAmount'=>$gross,'whtAmount'=>null,'whtStatus'=>'Confirm applicable WHT; any deduction is already within gross.','rateSource'=>$rateSource,'rateSourceSnapshot'=>$proof,'basis'=>'Dated net receipts less posted billed bags; gross includes WHT, deduction rate unconfirmed.'];
            $row['sourceFingerprint']=hash('sha256',json_encode([$r,$billed,$line,$proof,$gross],JSON_THROW_ON_ERROR));$out[]=$row;
            if($reason!==''){$warning=$entity.' · '.$label.' — '.$reason;$warnings[]=$warning;$sourceWarnings[$entity.'|'.$sourceKey][]=$warning;}
        }
    }
    return ['rows'=>$out,'warnings'=>array_values(array_unique($warnings)),'sourceWarnings'=>$sourceWarnings];
}
