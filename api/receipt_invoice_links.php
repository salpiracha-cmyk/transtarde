<?php
declare(strict_types=1);

/** Follow an exact FI/advice match and its committed utilisation, never a guessed contract match. */
function tt_receipt_invoice_links(array $s,array $root,array $fiLinks):array {
 $out=[];$lots=[];foreach((array)($root['shipments']??[]) as $lot)if(is_array($lot)&&!empty($lot['id'])&&!empty($lot['customs']['saved'])&&empty($lot['cancelled']))$lots[(string)$lot['id']]=$lot;
 foreach((array)($s['exportReceipts']??[]) as $r){if(($r['status']??'')!=='Accounts Approved / Posted')continue;$rid=(string)$r['id'];$cur=(string)$r['transactionCurrency'];
  foreach((array)($r['allocations']??[]) as $a){$ref=(string)($a['invoiceRef']??'');if(($a['targetType']??'')==='EXPORT_RECEIVABLE')$ref=(string)(($s['exportCandidates'][$a['targetId']??'']['meta']['commercialInvoiceNo']??'')?:$ref);if($ref!=='')$out[$rid][]=['invoiceRef'=>strtoupper($ref),'currency'=>$cur,'amount'=>round((float)$a['foreignAmount'],2),'source'=>'RECEIPT ALLOCATION'];}
 }
 foreach((array)($root['fi']??[]) as $fi){$tag=$fiLinks[(string)($fi['id']??'')]??[];if(($tag['status']??'')!=='Matched')continue;$rid=$tag['receiptId'];$r=$s['exportReceipts'][$rid]??[];
  // Existing invoice settlement is already explicit. FI utilisation adds references only to advances.
  $advance=0.0;foreach((array)($r['allocations']??[]) as $a)if(in_array($a['targetType']??'',['UNAPPLIED_TG','UNAPPLIED_ADVANCE'],true)&&empty($a['invoiceRef']))$advance+=(float)$a['foreignAmount'];
  $used=0.0;
  foreach((array)($fi['allocations']??[]) as $a){$lot=$lots[(string)($a['lotId']??$a['shipmentId']??'')]??null;if(!$lot||($lot['contractRef']??'')!==($a['contractRef']??''))continue;
   $custom=$lot['customs'];$pack=(array)($lot['tgdocs']??[]);$tg=strtoupper((string)($lot['seller']??''))==='TG';$doc=$tg&&!empty($pack['saved'])?$pack:$custom;
   $num=trim((string)($tg?($doc['customsInvoiceNo']??$custom['invoiceNo']??''):($custom['invoiceNo']??'')));$cur=strtoupper((string)($doc['currency']??$custom['currency']??''));$entity=strtoupper((string)($doc['exporter']??$custom['exporter']??$lot['seller']??''));
   if($num===''||$cur!==strtoupper((string)($r['transactionCurrency']??''))||$entity!==($r['entity']??''))continue;
   // Require the allocation to exist on both the FI and this Customs document.
   $committed=false;foreach((array)($custom['fiAllocations']??[]) as $ref)if(($ref['fiId']??'')===($fi['id']??'')&&($ref['allocationId']??'')===($a['id']??'')&&abs((float)($ref['amount']??0)-(float)($a['amount']??0))<.01)$committed=true;
   if(!$committed)continue;$amount=min(round((float)($a['amount']??0),2),round($advance-$used,2));if($amount<=0)continue;$used+=$amount;
   $out[$rid][]=['invoiceRef'=>strtoupper($num),'currency'=>$cur,'amount'=>$amount,'source'=>$tg?'TG PACK CUSTOMS':'CUSTOMS','fiId'=>$fi['id'],'allocationId'=>$a['id'],'lotId'=>$lot['id']];
  }
 }
 return $out;
}
function tt_receipt_invoice_project(array $s,array $root,array $fiLinks):array {
 $links=tt_receipt_invoice_links($s,$root,$fiLinks);
 foreach((array)($s['exportReceipts']??[]) as $id=>$r){$s['exportReceipts'][$id]['invoiceUtilisations']=$links[$id]??[];}
 foreach((array)($s['tgRemittanceDrafts']??[]) as $id=>$x){$refs=[];foreach((array)($x['receiptIds']??[]) as $rid)foreach($links[$rid]??[] as $l)$refs[]=$l['invoiceRef'];$s['tgRemittanceDrafts'][$id]['invoiceRefs']=array_values(array_unique($refs));}
 foreach((array)($s['tgRemittances']??[]) as $id=>$x){$refs=[];foreach((array)($x['receiptIds']??[]) as $rid)foreach($links[$rid]??[] as $l)$refs[]=$l['invoiceRef'];$s['tgRemittances'][$id]['invoiceRefs']=array_values(array_unique($refs));}
 foreach((array)($s['journals']??[]) as $id=>$j){if(($j['status']??'')!=='Posted'||!empty($j['reversalOf']))continue;
  $rids=(array)($j['meta']['receiptIds']??[]);if(!empty($j['meta']['receiptId']))$rids[]=$j['meta']['receiptId'];$parts=[];$refs=[];
  foreach(array_unique($rids) as $rid)foreach($links[$rid]??[] as $l){$refs[]=$l['invoiceRef'];$parts[]=$l['invoiceRef'].' '.$l['currency'].' '.number_format($l['amount'],2,'.',',');}
  if(!$rids||(!$parts&&!isset($j['meta']['invoiceLinkBaseNarration'])))continue;$base=(string)($j['meta']['invoiceLinkBaseNarration']??$j['narration']??'');$j['meta']['invoiceLinkBaseNarration']=$base;$j['meta']['invoiceUtilisations']=array_merge(...array_map(static fn($rid)=>$links[$rid]??[],array_unique($rids)));
  $j['meta']['invoiceRefs']=array_values(array_unique($refs));$j['narration']=strtoupper($base.($parts?' · UTILISED AGAINST '.implode('; ',array_unique($parts)):''));$s['journals'][$id]=$j;
 }
 return $s;
}
/** Called after an explicit successful Exports commit; updates metadata only. */
function tt_receipt_invoice_sync():void {
 $path=TT_DATA_DIR.'/accounts.json';if(!is_file($path))return;$h=fopen($path,'r+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Invoice linking storage unavailable.');
 try{$raw=stream_get_contents($h);$s=json_decode($raw?:'{}',true);if(!is_array($s))throw new RuntimeException('Invoice linking storage invalid.');$projected=tt_fi_advice_project($s,tt_fi_advice_root());
  if($projected!==$s){$projected['revision']=(int)($s['revision']??0)+1;$text=json_encode($projected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$text)!==strlen($text)||!fflush($h))throw new RuntimeException('Invoice links could not be saved.');}
 }finally{flock($h,LOCK_UN);fclose($h);}
}
