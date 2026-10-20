<?php
declare(strict_types=1);
/** Read-only Sales Tax source projection. File identities and source records are preserved. */
function ast_valid_date(string $value):bool {if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m))return false;return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
function ast_advice_matches(array $allocation,string $contract,string $lot,string $shipment,string $invoice):bool {
    $aContract=(string)($allocation['contractRef']??'');
    if($aContract!==''&&$aContract!==$contract)return false;
    foreach(['lotRef','lotId','shipmentId'] as $key)if(!empty($allocation[$key])&&!in_array((string)$allocation[$key],[$lot,$shipment],true))return false;
    $aInvoice=(string)($allocation['invoiceRef']??'');
    if($aInvoice!=='')return $invoice!==''&&$aInvoice===$invoice;
    return $aContract!==''&&$aContract===$contract;
}
function ast_exporter(array $s,array $c):string{$seller=strtoupper((string)($s['seller']??$c['seller']??'TTI'));if($seller==='TG')return strtoupper((string)($s['customs']['exporter']??$c['customsExporter']??'TTI'))==='BRM'?'BRM':'TTI';return$seller==='BRM'?'BRM':'TTI';}
function ast_refs(mixed $value):array{$out=[];foreach((array)$value as$x){$v=is_array($x)?(string)($x['no']??$x['number']??$x['ref']??''):(string)$x;if(trim($v)!=='')$out[]=trim($v);}return array_values(array_unique($out));}
function ast_gd_refs(array $shipment):array {
    $customs=(array)($shipment['customs']['gdRefs']??[]);
    $hasCustoms=false;
    foreach($customs as $row){
        $number=is_array($row)?($row['no']??$row['number']??$row['ref']??''):$row;
        if(trim((string)$number)!==''||(is_array($row)&&trim((string)($row['date']??''))!=='')){$hasCustoms=true;break;}
    }
    // Match Exports' shipmentGDReferences: B/L is a fallback, never an override
    // of current or partially entered Customs references.
    $source=$hasCustoms?$customs:($shipment['bl']['gdRefs']??[]);
    return array_values(array_unique(array_merge(ast_refs($source),ast_refs($shipment['commercial']['gd']??[]),ast_refs($shipment['commercial']['gdRefs']??[]))));
}

function ast_rows(array $root,array $accounts,array $docs,string $entity,string $from='',string $to='',string $needle=''):array {
$contracts=[];foreach((array)($root['contracts']??[])as$c)if(is_array($c))$contracts[(string)($c['ref']??$c['id']??'')]=$c;$rows=[];$shipments=array_values(array_filter((array)($root['shipments']??[]),'is_array'));$parents=[];
 foreach($shipments as $child)if(($child['kind']??'')==='lot'&&!empty($child['parentProcessId']))$parents[(string)$child['parentProcessId']]=true;
 foreach((array)($root['shipments']??[])as$s){if(!is_array($s)||!empty($s['cancelled']))continue;if(($s['kind']??'')==='process'&&isset($parents[(string)($s['id']??'')]))continue;$ref=(string)($s['contractRef']??'');$c=$contracts[$ref]??[];if(ast_exporter($s,$c)!==$entity)continue;$commercial=is_array($s['commercial']??null)?$s['commercial']:[];$customs=is_array($s['customs']??null)?$s['customs']:[];$date='';foreach([$commercial['date']??'',$commercial['invoiceDate']??'',$customs['date']??'',$customs['invoiceDate']??'',$s['shipmentDate']??''] as $candidate)if(trim((string)$candidate)!==''){$date=(string)$candidate;break;}if(($from!==''||$to!=='')&&!ast_valid_date($date))continue;if($from!==''&&$date<$from)continue;if($to!==''&&$date>$to)continue;$lot=(string)($s['lotId']??$s['lotRef']??$s['id']??'');$invoice=(string)($commercial['invoiceNo']??$customs['invoiceNo']??'');$customer=(string)($s['buyer']??$c['customer']??'');$gds=ast_gd_refs($s);$bl=(string)($s['bl']['blNo']??'');$hay=strtolower(implode(' ',[$ref,$lot,$invoice,$customer,$bl,implode(' ',$gds),(string)($s['id']??''),(string)($s['parentProcessId']??'')]));if($needle!==''&&!str_contains($hay,$needle))continue;$rowDocs=[];$seenDocs=[];foreach($docs as$d)if(($d['contractRef']??'')===$ref&&(($d['lotRef']??'')===''||($d['lotRef']??'')===$lot||($d['lotRef']??'')===(string)($s['id']??'')||(!empty($s['parentProcessId'])&&($d['lotRef']??'')===(string)$s['parentProcessId']))){$docKey=(string)($d['id']??$d['downloadUrl']??'');if($docKey!==''&&!isset($seenDocs[$docKey])){$seenDocs[$docKey]=true;$rowDocs[]=$d;}}$adv=[];foreach((array)($accounts['exportReceipts']??[])as$r){if(!is_array($r)||($r['entity']??'')!==$entity||!empty($r['amendedByReceiptId'])||!empty($r['reversedByPostId'])||in_array(strtolower((string)($r['status']??'')),['cancelled','deleted','reversed','amended','reversed for amendment'],true))continue;$match=false;foreach((array)($r['allocations']??[])as$a)if(is_array($a)&&ast_advice_matches($a,$ref,$lot,(string)($s['id']??''),$invoice)){$match=true;break;}if(!$match)continue;$ded=[];foreach((array)($r['deductions']??[])as$d)if(is_array($d))$ded[]=['name'=>(string)($d['name']??$d['masterCode']??'Tax / charge'),'amount'=>(float)($d['amount']??0),'taxSection'=>(string)($d['taxSection']??'')];$file=(string)($r['bankAdviceFileRef']??'');$adv[]=['receiptNo'=>(string)($r['id']??''),'date'=>(string)($r['date']??''),'bankAdviceRef'=>(string)($r['bankAdviceRef']??''),'fileRef'=>$file,'paperRef'=>(string)($r['bankAdvicePaperRef']??''),'downloadUrl'=>str_starts_with($file,'receipt-')?'/api/accounts_receipt_file.php?entity='.rawurlencode($entity).'&file='.rawurlencode($file):'','foreignAmount'=>(float)($r['foreignAmount']??0),'currency'=>(string)($r['transactionCurrency']??''),'pkrCredit'=>(float)($r['pkrBankCredit']??0),'deductions'=>$ded];}$rows[]=['shipmentId'=>(string)($s['id']??$lot),'contractRef'=>$ref,'lotRef'=>$lot,'date'=>$date,'customer'=>$customer,'commercialInvoice'=>$invoice,'customsInvoice'=>(string)($customs['invoiceNo']??''),'gdRefs'=>$gds,'blNo'=>$bl,'documents'=>$rowDocs,'advices'=>$adv];}
 usort($rows,static fn($a,$b)=>strcmp((string)$b['date'],(string)$a['date'])?:strcmp((string)$b['commercialInvoice'],(string)$a['commercialInvoice']));return $rows;
}
