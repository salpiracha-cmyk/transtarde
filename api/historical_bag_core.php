<?php
declare(strict_types=1);
require_once __DIR__.'/opening_balance_core.php';

const HBG_OPENING_DATE='2026-07-01';
const HBG_LINK_CUTOFF='2026-10-01';
function hbg_date(mixed $value):string {
    $value=(string)$value;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$d||$d->format('Y-m-d')!==$value||$value>(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'))throw new DomainException('Enter the original valid invoice date, no later than today.');
    return $value;
}
function hbg_amount(mixed $value,string $label,bool $positive=false,int $digits=2):float {
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>1e11)throw new DomainException('Enter a valid '.$label.'.');
    $value=round((float)$value,$digits);if($positive&&$value<=0)throw new DomainException($label.' must be greater than zero.');return $value;
}
function hbg_key(string $value):string{return strtolower(preg_replace('/[^\p{L}\p{N}]/u','',$value)??'');}
function hbg_active(array $j):bool{return ($j['status']??'')==='Posted'&&empty($j['reversedByPostId'])&&empty($j['reversalOf'])&&empty($j['amendedByPostId'])&&empty($j['meta']['deletedFromBooks']);}
function hbg_assert_link(string $date,array $po):void {
    if($date>=HBG_LINK_CUTOFF&&trim((string)($po['shipmentRef']??$po['contractRef']??''))===''&&trim((string)($po['contractRef']??''))==='')throw new DomainException('Export bag invoices dated 1 October 2026 onwards require a shipment-linked bag PO.');
}
function hbg_openings(array $s,string $e):array {
    $out=[];
    foreach((array)($s['journals']??[]) as $id=>$j){
        if(($j['entity']??'')!==$e||($j['date']??'')!==HBG_OPENING_DATE||!hbg_active($j)||!in_array($j['sourceType']??'',['OPENING_BALANCE_BF','CARRY_FORWARD_OPENING'],true)||!empty($j['meta']['historicalBagBillId']))continue;
        foreach($j['lines'] as $i=>$l){if(($l['account']??'')!=='2140'||strtoupper((string)($l['nativeCurrency']??$l['currency']??'PKR'))!=='PKR')continue;$available=round((float)($l['credit']??0)-(float)($l['debit']??0),2);
            foreach((array)($s['carryForwardOpeningLinks']??[]) as $link)if(($link['journalId']??'')===$id&&(int)($link['lineIndex']??-1)===$i)$available=round($available-(float)$link['nativeAmount'],2);
            if($available>.005)$out[]=['journalId'=>$id,'lineIndex'=>$i,'supplier'=>sop_party_name($l,(array)($j['meta']??[])),'available'=>$available];
        }
    }return $out;
}
function hbg_prior_opening(array $s,string $e,string $account,?string $supplier=null):bool {
    foreach((array)($s['journals']??[]) as $j){if(($j['entity']??'')!==$e||($j['date']??'')>HBG_OPENING_DATE||!hbg_active($j)||!empty($j['meta']['historicalBagBillId']))continue;
        foreach((array)$j['lines'] as $l)if(($l['account']??'')===$account&&abs((float)($l['debit']??0)-(float)($l['credit']??0))>.005&&($supplier===null||hbg_key(sop_party_name($l,(array)($j['meta']??[])))===hbg_key($supplier)))return true;
    }return false;
}
function hbg_asset_capacity(array $s,string $e,string $account):float {
    $amount=0;foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['date']??'')<=HBG_OPENING_DATE&&hbg_active($j)&&empty($j['meta']['historicalBagBillId']))foreach((array)$j['lines'] as $l)if(($l['account']??'')===$account)$amount+=(float)($l['debit']??0)-(float)($l['credit']??0);
    foreach((array)($s['bagSupplierBills']??[]) as $b)if(($b['entity']??'')===$e&&($b['status']??'')==='Posted'&&!empty($b['historicalOpening'])){
        if($account==='1230'&&($b['openingGstMode']??'')==='INCLUDED')$amount-=(float)$b['openingGstRemaining'];
        if($account==='1340'&&($b['openingStockMode']??'')==='INCLUDED')$amount-=round((float)$b['openingStockBags']*(float)$b['ratePerBag'],2);
    }return max(0,round($amount,2));
}
function hbg_create(array &$s,array $in,array $u):array {
    $e=ent((string)($in['entity']??''));$date=hbg_date($in['sellerInvoiceDate']??'');if($date>=HBG_LINK_CUTOFF)throw new DomainException('Historical export bag entry ends on 30 September 2026. From 1 October choose a shipment-linked PO.');
    $party=bag_supplier(trim((string)($in['supplier']??'')));$supplier=(string)$party['values'][0];$invoice=trim((string)($in['sellerInvoice']??''));$type=trim((string)($in['bagType']??''));
    if($invoice===''||$type===''||strlen($invoice)>180)throw new DomainException('Enter the supplier invoice number and bag type.');
    if(!is_numeric($in['bags']??null)||(float)$in['bags']!=(int)$in['bags'])throw new DomainException('Enter a whole number of bags.');$qty=hbg_amount($in['bags']??null,'number of bags',true);if($qty!=(int)$qty)throw new DomainException('Enter a whole number of bags.');$rate=hbg_amount($in['ratePerBag']??null,'rate per bag',true,4);
    foreach(['bagSupplierBills','supplierBills','nonWovenBagSupplierBills'] as $collection)foreach((array)($s[$collection]??[]) as $old)if(($old['entity']??'')===$e&&!in_array($old['status']??'',['Cancelled','Deleted'],true)&&hbg_key((string)($old['supplier']??$old['vendor']??''))===hbg_key($supplier)&&hbg_key((string)($old['sellerInvoice']??$old['billNo']??''))===hbg_key($invoice))throw new DomainException('This supplier invoice is already registered. Use the existing bill.');
    $hasTax=filter_var($in['salesTaxInvoice']??false,FILTER_VALIDATE_BOOLEAN);$gst=$hasTax?hbg_amount($in['gstRate']??null,'invoice GST percentage'):0;if($gst>100)throw new DomainException('GST percentage cannot exceed 100.');
    $base=round($qty*$rate,2);$tax=$hasTax?hbg_amount($in['gstAmount']??round($base*$gst/100,2),'invoice GST amount'):0;$total=round($base+$tax,2);
    $id=nextId((array)($s['bagSupplierBills']??[]),'BAGB');$pre=$date<HBG_OPENING_DATE;$treatment=(string)($in['stockTreatment']??'STOCK');if(!in_array($treatment,['STOCK','CONSUMED'],true))throw new DomainException('Choose bags in stock or consumed.');
    $b=['id'=>$id,'entity'=>$e,'supplier'=>$supplier,'supplierId'=>$party['id'],'sellerInvoice'=>$invoice,'sellerInvoiceDate'=>$date,'bagType'=>$type,'packingSize'=>trim((string)($in['packingSize']??'')),'brand'=>trim((string)($in['brand']??'')),'bags'=>(int)$qty,'ratePerBag'=>$rate,'baseAmount'=>$base,'gstRate'=>$gst,'gstAmount'=>$tax,'totalAmount'=>$total,'salesTaxInvoice'=>$hasTax,'billPurpose'=>'HISTORICAL_EXPORT','historical'=>true,'historicalOpening'=>$pre,'stockTreatment'=>$treatment,'poNo'=>'','lineKey'=>'','attachmentToken'=>trim((string)($in['attachmentToken']??'')),'status'=>'Posted','journalId'=>null,'createdAt'=>gmdate('c'),'createdBy'=>$u['username']??'Accounts'];
    $n=names();$lines=[];$linked='';
    if($pre){
        if(!job_authorized($u)||!in_array($e,job_entities($u),true)||(function_exists('tt_user_can_access_entity')&&!tt_user_can_access_entity($u,$e,'Approve')))throw new DomainException('Pre-July opening details require Super Admin or authorised Directors company approval access.');
        if(($s['openingBalanceSettings'][$e]['enabled']??true)!==true)throw new DomainException('Opening entry is disabled for these books.');
        $payable=hbg_amount($in['openingPayableAmount']??null,'supplier amount unpaid at 1 July');$gstRemaining=hbg_amount($in['openingGstRemaining']??null,'GST still recoverable at 1 July');$stock=hbg_amount($in['openingStockBags']??null,'bags still in stock at 1 July');
        if($payable>$total+.005||$gstRemaining>$tax+.005||$stock>$qty||$stock!=(int)$stock)throw new DomainException('Opening amounts cannot exceed the original invoice amounts or bags.');
        $b+=['openingPayableAmount'=>$payable,'paidBeforeOpening'=>round($total-$payable,2),'openingGstRemaining'=>$gstRemaining,'openingStockBags'=>(int)$stock];
        $mode=(string)($in['openingPayableMode']??'NEW');if(!in_array($mode,['NEW','LINK'],true))throw new DomainException('Choose a new or existing supplier opening balance.');$b['openingPayableMode']=$mode;
        if($payable>.005&&$mode==='LINK'){
            $linked=(string)($in['openingJournalId']??'');$index=(int)($in['openingLineIndex']??-1);$found=null;foreach(hbg_openings($s,$e) as $o)if($o['journalId']===$linked&&$o['lineIndex']===$index&&hbg_key($o['supplier'])===hbg_key($supplier))$found=$o;
            if(!$found||$payable>$found['available']+.005)throw new DomainException('Choose an unassigned opening line for this bag supplier with sufficient balance.');sop_assert_unpaid($s,$linked);
            $s['carryForwardOpeningLinks']['HISTB|'.$id]=['journalId'=>$linked,'lineIndex'=>$index,'nativeAmount'=>$payable,'historicalBagBillId'=>$id];$b['linkedOpeningJournalId']=$linked;$b['openingLineIndex']=$index;
        }elseif($payable>.005){if(hbg_prior_opening($s,$e,'2140',$supplier))throw new DomainException('This supplier already has an opening balance. Choose its existing opening line to avoid duplication.');$lines[]=line('2140',0,$payable,$n,['supplier'=>$supplier,'counterparty'=>$supplier,'subledger'=>$supplier]);}
        foreach([['1230',$gstRemaining,'openingGstMode','GST'],['1340',round($stock*$rate,2),'openingStockMode','bag stock']] as [$account,$amount,$field,$label]){
            $assetMode=(string)($in[$field]??'NEW');if(!in_array($assetMode,['NEW','INCLUDED'],true))throw new DomainException('Choose how the '.$label.' opening balance is recorded.');$b[$field]=$assetMode;
            if($amount>.005&&$assetMode==='INCLUDED'&&$amount>hbg_asset_capacity($s,$e,$account)+.005)throw new DomainException('There is not enough unassigned existing '.$label.' opening balance. Review its opening posting before linking these details.');
            if($amount>.005&&$assetMode==='NEW'){if(hbg_prior_opening($s,$e,$account))throw new DomainException('An earlier '.$label.' balance is already recorded. Reconcile it, then choose Already included in opening balance.');$lines[]=line($account,$amount,0,$n,['subledger'=>$account==='1230'?'Sales Tax Recoverable / Refund Receivable':'Opening Bag Stock','sellerInvoice'=>$invoice]);}
        }
        $diff=round(array_sum(array_column($lines,'credit'))-array_sum(array_column($lines,'debit')),2);if(abs($diff)>.005)$lines[]=line('3400',max(0,$diff),max(0,-$diff),$n);
    }else{
        $lines[]=line($treatment==='CONSUMED'?'5550':'1340',$base,0,$n);if($tax>.005)$lines[]=line('1230',$tax,0,$n,['subledger'=>'Sales Tax Recoverable / Refund Receivable','sellerInvoice'=>$invoice]);$lines[]=line('2140',0,$total,$n,['supplier'=>$supplier,'counterparty'=>$supplier,'subledger'=>$supplier]);
    }
    if($lines){$postDate=$pre?HBG_OPENING_DATE:$date;$jid=tt_next_post_id((array)($s['journals']??[]),'Accounts','Journal',$postDate);$dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);if(abs($dr-$cr)>.005||$dr<=0)throw new DomainException('The historical bag entry must balance.');
        $s['journals'][$jid]=['id'=>$jid,'entity'=>$e,'date'=>$postDate,'sourceType'=>$pre?'BAG_HISTORICAL_OPENING':'EXPORT_BAG_SUPPLIER_BILL','reference'=>$invoice,'narration'=>($pre?'Opening details for historical':'Historical export').' bag bill · '.$supplier.' · Supplier Bill No. '.$invoice.' · Invoice date '.$date.' · Brand '.$b['brand'].' · Bags '.number_format($qty),'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>['bagBillId'=>$id,'historicalBagBillId'=>$id,'historicalOpening'=>$pre,'invoiceDate'=>$date,'billPurpose'=>'HISTORICAL_EXPORT'],'createdAt'=>gmdate('c'),'createdBy'=>$u['full_name']??$u['username']??'Accounts','userId'=>$u['id']??0];$b['journalId']=$jid;
    }
    $s['bagSupplierBills'][$id]=$b;$s['historicalBagAudit'][]=['action'=>'create','billId'=>$id,'actor'=>$u['username']??'','at'=>gmdate('c')];return $b;
}
function hbg_totals(array $bills):array {
    $r=['invoiceGst'=>0,'openingGst'=>0,'newOpeningGst'=>0,'openingStockBags'=>0];
    foreach($bills as $b)if(!empty($b['historicalOpening'])&&($b['status']??'')==='Posted'){
        $r['invoiceGst']+=(float)$b['gstAmount'];$r['openingGst']+=(float)$b['openingGstRemaining'];$r['openingStockBags']+=(int)$b['openingStockBags'];
        if(($b['openingGstMode']??'NEW')==='NEW')$r['newOpeningGst']+=(float)$b['openingGstRemaining'];
    }foreach($r as &$amount)$amount=round($amount,2);unset($amount);return $r;
}
function hbg_cancel(array &$s,string $e,string $id,string $reason,array $u):array {
    $b=$s['bagSupplierBills'][$id]??null;if(!$b||($b['entity']??'')!==$e||empty($b['historical'])||($b['status']??'')!=='Posted')throw new DomainException('Choose an active historical bag invoice in these company books.');
    if(!empty($b['historicalOpening'])&&(!job_authorized($u)||!in_array($e,job_entities($u),true)||(function_exists('tt_user_can_access_entity')&&!tt_user_can_access_entity($u,$e,'Approve'))))throw new DomainException('Historical opening corrections require authorised management and company approval access.');
    $reason=trim($reason);if(strlen($reason)<5||strlen($reason)>300)throw new DomainException('Enter a correction reason of 5 to 300 characters.');
    foreach((array)($s['supplierSettlements']??[]) as $pay)if(in_array($pay['status']??'',['Posted','Approved / Posted','Approved'],true))foreach((array)($pay['allocations']??[]) as $a)if(($a['billId']??'')===$id)throw new DomainException('Reverse the linked supplier payment before correcting this historical bill.');
    foreach((array)($s['bagSupplierPayments']??[]) as $pay)if(($pay['billId']??'')===$id&&!in_array($pay['status']??'',['Cancelled','Deleted','Reversed','Reversed for Amendment'],true))throw new DomainException('Reverse the linked bag supplier payment before correcting this historical bill.');
    $jid=$b['journalId']??null;$reverseId=null;
    if($jid){$old=$s['journals'][$jid]??null;if(!$old||!hbg_active($old))throw new DomainException('The historical posting is already reversed or unavailable.');$reverseId=tt_next_post_id((array)$s['journals'],'Accounts','Journal',$old['date']);$reverse=$old;$reverse['id']=$reverseId;$reverse['reversalOf']=$jid;$reverse['sourceType']='BAG_HISTORICAL_REVERSAL';$reverse['createdAt']=gmdate('c');$reverse['createdBy']=$u['full_name']??$u['username']??'Accounts';$reverse['narration']='Historical bag correction · '.$reason;$reverse['meta']['deletedFromBooks']=true;$reverse['meta']['correctionReason']=$reason;
        foreach($reverse['lines'] as &$l)[$l['debit'],$l['credit']]=[$l['credit'],$l['debit']];unset($l);[$reverse['totalDebit'],$reverse['totalCredit']]=[$old['totalCredit'],$old['totalDebit']];$s['journals'][$reverseId]=$reverse;$s['journals'][$jid]['reversedByPostId']=$reverseId;$s['journals'][$jid]['meta']['deletedFromBooks']=true;
    }
    unset($s['carryForwardOpeningLinks']['HISTB|'.$id]);$s['bagSupplierBills'][$id]['status']='Cancelled';$s['bagSupplierBills'][$id]['reversalJournalId']=$reverseId;$s['historicalBagAudit'][]=['action'=>'cancel','before'=>$b,'reason'=>$reason,'actor'=>$u['username']??'','at'=>gmdate('c')];return ['billId'=>$id,'journalId'=>$reverseId];
}
function hbg_payment_state(array $s,array $b):array {
    $prior=!empty($b['historicalOpening'])?(float)($b['paidBeforeOpening']??0):0;$capacity=!empty($b['historicalOpening'])?(float)$b['openingPayableAmount']:(float)$b['totalAmount'];$paid=0;
    foreach((array)($s['supplierSettlements']??[]) as $p)if(in_array($p['status']??'',['Posted','Approved / Posted','Approved'],true))foreach((array)($p['allocations']??[]) as $a)if(($a['billId']??'')===$b['id'])$paid+=(float)$a['amount'];
    foreach((array)($s['bagSupplierPayments']??[]) as $p)if(($p['billId']??'')===$b['id']&&empty($p['settlementId'])&&!in_array($p['status']??'',['Cancelled','Deleted','Reversed','Reversed for Amendment'],true))$paid+=(float)$p['amount'];
    $b['paidAmount']=round($prior+$paid,2);$b['outstanding']=max(0,round($capacity-$paid,2));$b['paymentStatus']=($b['status']??'')==='Cancelled'?'Cancelled':($b['outstanding']<=.005?'Paid':($b['paidAmount']>.005?'Partly paid':'Unpaid'));return $b;
}
