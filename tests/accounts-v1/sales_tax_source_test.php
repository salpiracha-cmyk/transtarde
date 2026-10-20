<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_sales_tax_core.php';
function check(bool $pass,string $why):void {if(!$pass)throw new RuntimeException($why);}
$process=['id'=>'PROCESS1','kind'=>'process','contractRef'=>'C1','seller'=>'TTI','commercial'=>['date'=>'2026-09-01','invoiceNo'=>'PREP']];
$lot=['id'=>'L1','lotId'=>'LOT1','parentProcessId'=>'PROCESS1','kind'=>'lot','contractRef'=>'C1','seller'=>'TTI','commercial'=>['date'=>'2026-09-02','invoiceNo'=>'INV1']];
$lot2=array_replace($lot,['id'=>'L2','lotId'=>'LOT2','commercial'=>['date'=>'2026-09-03','invoiceNo'=>'INV2']]);
$brm=array_replace($lot,['id'=>'BRM1','seller'=>'TG','customs'=>['exporter'=>'BRM']]);
$undated=array_replace($lot,['id'=>'UNDATED','commercial'=>['date'=>'','invoiceNo'=>'UNDATED']]);
$root=['contracts'=>[['ref'=>'C1']],'shipments'=>[$process,$lot,$lot2,$brm,$undated]];
$doc=fn($id,$target,$name='Certificate')=>['id'=>$id,'contractRef'=>'C1','lotRef'=>$target,'name'=>$name,'downloadUrl'=>'/api/export_documents.php?id='.$id];
$docs=[$doc('CONTRACT',''),$doc('PARENT','PROCESS1'),$doc('F1','LOT1'),$doc('F1','LOT1'),$doc('F2','L1'),$doc('F3','LOT2')];
$receipt=['id'=>'R1','entity'=>'TTI','status'=>'Posted','allocations'=>[['contractRef'=>'C1','invoiceRef'=>'INV1']]];
$books=['exportReceipts'=>[$receipt,array_replace($receipt,['id'=>'OLD','status'=>'Reversed for Amendment']),array_replace($receipt,['id'=>'OTHERLOT','allocations'=>[['contractRef'=>'C1','lotId'=>'LOT2']]]),array_replace($receipt,['id'=>'WRONGCONTRACT','allocations'=>[['contractRef'=>'C2','invoiceRef'=>'INV1']]])]];
$before=serialize([$root,$books,$docs]);$rows=ast_rows($root,$books,$docs,'TTI','2026-09-01','2026-09-30');
check(count($rows)===2,'Preparation process or undated source duplicated period results');$by=array_column($rows,null,'lotRef');
check(array_column($by['LOT1']['documents'],'id')===['CONTRACT','PARENT','F1','F2'],'Keep parent uploads and distinct same-named originals, deduplicate only file ID');
check(array_column($by['LOT2']['documents'],'id')===['CONTRACT','PARENT','F3'],'Document from another lot leaked');
check(array_column($by['LOT1']['advices'],'receiptNo')===['R1'],'Wrong lot, reversed or wrong-contract advice included');
check(array_column($by['LOT2']['advices'],'receiptNo')===['OTHERLOT'],'Invoice-specific advice copied to every lot');
check(count(ast_rows($root,$books,$docs,'BRM'))===1,'TG Pakistan exporter not respected');
check(count(ast_rows(['shipments'=>[$process]],[],[],'TTI'))===1,'Unsplit preparation record disappeared');
check(count(ast_rows($root,$books,$docs,'TTI','','','process1'))===3,'Parent reference no longer searchable');
check(count(ast_rows($root,$books,$docs,'TTI','2026-09-03','2026-09-03'))===1,'Inclusive report cutoff failed');
check(ast_valid_date('2024-02-29')&&!ast_valid_date('2026-02-29')&&!ast_valid_date('2026-13-01'),'Impossible calendar date accepted');
check($before===serialize([$root,$books,$docs]),'Report projection rewrote source data');
$legacy=['id'=>'LEGACY-BL','kind'=>'lot','lotId'=>'OLD-LOT','contractRef'=>'OLD-C','seller'=>'TTI','buyer'=>'Legacy Buyer','commercial'=>['date'=>'2026-09-04','invoiceNo'=>'OLD-INV'],'customs'=>['gdRefs'=>[['number'=>'','date'=>'']]],'bl'=>['blNo'=>'OLD-BL','gdRefs'=>[['number'=>'GD-BL-1','date'=>'2026-09-01'],['number'=>'GD-BL-2','date'=>'2026-09-02'],['number'=>'GD-BL-1','date'=>'2026-09-01']]]];
$legacyRoot=['shipments'=>[$legacy]];$legacyBefore=serialize($legacyRoot);
foreach(['old-c','old-lot','old-inv','legacy buyer','old-bl','gd-bl-1','gd-bl-2'] as $reference){
    $found=ast_rows($legacyRoot,[],[],'TTI','','',$reference);
    check(count($found)===1,'B/L-only shipment reference missing: '.$reference);
    check($found[0]['gdRefs']===['GD-BL-1','GD-BL-2'],'B/L fallback must retain both unique GD numbers');
}
check(ast_rows($legacyRoot,[],[],'BRM')===[],'B/L fallback crossed exporter books');
check(ast_rows($legacyRoot,[],[],'TTI','','','unknown-ref')===[],'Unmatched reference returned a shipment');
check(ast_rows($legacyRoot,[],[],'TTI','2026-09-05')===[],'B/L fallback bypassed date cutoff');
$current=$legacy;$current['customs']['gdRefs']=[['number'=>'GD-CURRENT','date'=>'2026-09-03']];
check(ast_rows(['shipments'=>[$current]],[],[],'TTI','','','gd-bl-1')===[],'Obsolete B/L GD returned despite explicit Customs references');
check(count(ast_rows(['shipments'=>[$current]],[],[],'TTI','','','gd-current'))===1,'Current Customs GD disappeared');
$current['customs']['gdRefs']=[['number'=>'','date'=>'2026-09-03']];
check(ast_rows(['shipments'=>[$current]],[],[],'TTI','','','gd-bl-1')===[],'Partially entered Customs reference must not revive old B/L numbers');
$legacy['commercial']['gdRefs']=[['number'=>'COMMERCIAL-GD']];
check(count(ast_rows(['shipments'=>[$legacy]],[],[],'TTI','','','commercial-gd'))===1,'Existing commercial GD compatibility lost');
check(serialize($legacyRoot)===$legacyBefore,'B/L reference search rewrote original shipment');
echo "PASS Sales Tax canonical lots, original-file identity, parent uploads, scoped current advices, inclusive real dates and unchanged sources\n";
