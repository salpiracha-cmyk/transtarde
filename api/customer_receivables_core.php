<?php
declare(strict_types=1);

/** Read-only external buyer invoice register. Never read Customs or TG settlement values. */
function tt_customer_receivables(array $store, array $root, array $entities): array {
    $contracts=[];$customers=[];$rows=[];$candidateKeys=[];
    foreach((array)($root['customers']??[]) as $c)if(is_array($c))$customers[(string)($c['id']??'')]=(string)($c['name']??'');
    foreach((array)($root['contracts']??[]) as $c)if(is_array($c))$contracts[(string)($c['ref']??$c['id']??'')]=$c;
    $key=static fn(string $entity,string $contract,string $invoice,string $currency)=>$entity.'|'.$contract.'|'.strtoupper(trim($invoice)).'|'.$currency;
    foreach((array)($store['exportCandidates']??[]) as $id=>$c){
        if(!is_array($c)||($c['candidateType']??'')!=='CUSTOMER_EXPORT_SALE'||!in_array($c['entity']??'', $entities,true))continue;
        $m=(array)($c['meta']??[]);$ref=(string)($m['contractRef']??'');$invoice=trim((string)($m['commercialInvoiceNo']??''));$cur=strtoupper((string)($c['transactionCurrency']??''));
        if($invoice===''||$cur==='')continue;
        $k=$key($c['entity'],$ref,$invoice,$cur);$candidateKeys[(string)($c['id']??$id)]=$k;
        $rows[$k]=['entity'=>$c['entity'],'contractRef'=>$ref,'reference'=>$invoice,'currency'=>$cur,'label'=>(string)($m['customer']??''),'date'=>(string)($m['commercialInvoiceDate']??''),'invoiceAmount'=>round((float)($c['transactionAmount']??0),2),'received'=>0.0,'recognized'=>!empty($c['journalId']),'draft'=>($m['invoiceStage']??'Final')==='Draft','lotId'=>''];
    }
    foreach((array)($root['shipments']??[]) as $s){
        if(!is_array($s)||!empty($s['cancelled']))continue;
        $ref=(string)($s['contractRef']??'');$c=$contracts[$ref]??[];
        if(!empty($c['cancelled'])||strcasecmp((string)($c['status']??''),'Cancelled')===0)continue;
        $entity=strtoupper((string)($s['seller']??$c['seller']??''));$doc=(array)($s['commercial']??[]);
        if(!in_array($entity,$entities,true)||empty($doc['saved']))continue;
        $invoice=trim((string)($doc['invoiceNo']??''));$cur=strtoupper((string)($doc['currency']??$c['currency']??'USD'));
        if($invoice==='')continue;
        $k=$key($entity,$ref,$invoice,$cur);$prior=$rows[$k]??[];
        // The saved buyer CI is authoritative, including reissues before recognition.
        $rows[$k]=['entity'=>$entity,'contractRef'=>$ref,'reference'=>$invoice,'currency'=>$cur,'label'=>(string)($s['buyer']??$customers[(string)($c['customerId']??'')]??$c['customer']??''),'date'=>(string)($doc['date']??''),'invoiceAmount'=>round((float)($doc['lastInvoiceValue']??$doc['invoiceGross']??$doc['netAmount']??$prior['invoiceAmount']??0),2),'received'=>0.0,'recognized'=>!empty($prior['recognized']),'draft'=>($doc['status']??'Draft')!=='Final','lotId'=>(string)($s['id']??'')];
    }
    $apply=static function(string $k,float $amount) use (&$rows):void {if(isset($rows[$k])&&$amount>0)$rows[$k]['received']=round($rows[$k]['received']+$amount,2);};
    foreach((array)($store['exportReceipts']??[]) as $r){
        if(!is_array($r)||($r['status']??'')!=='Accounts Approved / Posted')continue;
        $entity=(string)($r['entity']??'');$cur=strtoupper((string)($r['transactionCurrency']??''));
        foreach((array)($r['allocations']??[]) as $a){
            $id=(string)($a['targetId']??'');$k=$candidateKeys[$id]??$key($entity,(string)($a['contractRef']??''),(string)($a['invoiceRef']??''),$cur);
            if(in_array($a['targetType']??'',['EXPORT_RECEIVABLE','UNAPPLIED_ADVANCE'],true)&&isset($rows[$k])&&$rows[$k]['entity']===$entity&&$rows[$k]['currency']===$cur)$apply($k,(float)($a['foreignAmount']??0));
        }
        // FI utilisation is only a committed direct-customer advance application.
        foreach((array)($r['invoiceUtilisations']??[]) as $a){
            if(($a['source']??'')!=='CUSTOMS')continue;
            foreach($rows as $k=>$row)if($row['entity']===$entity&&$row['entity']!=='TG'&&$row['lotId']!==''&&$row['lotId']===($a['lotId']??'')&&$row['currency']===($a['currency']??''))$apply($k,(float)($a['amount']??0));
        }
    }
    foreach((array)($store['tgBankTransactions']??[]) as $r){
        if(!is_array($r)||($r['kind']??'')!=='Receipt'||($r['receiptType']??'')!=='EXPORT_RECEIVABLE'||($r['status']??'')==='Reversed for Amendment')continue;
        $k=$candidateKeys[(string)($r['sourceCandidateId']??'')]??$key('TG',(string)($r['contractRef']??''),(string)($r['invoiceRef']??''),strtoupper((string)($r['currency']??'')));
        if(isset($rows[$k])&&$rows[$k]['entity']==='TG'&&$rows[$k]['currency']===strtoupper((string)($r['currency']??'')))$apply($k,(float)($r['settlementAmountNative']??0));
    }
    foreach((array)($store['tgAdvanceApplications']??[]) as $a){
        if(!is_array($a)||in_array($a['status']??'',['Deleted','Reversed','Reversed for Amendment'],true))continue;
        $k=$candidateKeys[(string)($a['candidateId']??'')]??'';if(isset($rows[$k])&&$rows[$k]['entity']==='TG')$apply($k,(float)($a['amountNative']??0));
    }
    $open=[];$drafts=[];
    foreach($rows as $row){$row['amount']=max(0,round($row['invoiceAmount']-$row['received'],2));$row['status']=$row['draft']?'Draft estimate':($row['recognized']?'Recognized':'Issued — awaiting Accounts recognition');if($row['draft']){$drafts[]=$row;continue;}if($row['amount']>.005)$open[]=$row;}
    usort($open,static fn($a,$b)=>strcmp($a['entity'],$b['entity'])?:strcmp($a['reference'],$b['reference']));
    return ['rows'=>$open,'drafts'=>$drafts,'entities'=>$entities];
}
