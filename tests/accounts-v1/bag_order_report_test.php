<?php
declare(strict_types=1);
require __DIR__.'/../../api/bag_order_report_core.php';
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$orders=[
 ['entity'=>'TTI','poNo'=>'SEPT','supplier'=>'Bag One','issuedAt'=>'2026-09-12T10:00:00Z','contractRef'=>'C1','lines'=>[['lineKey'=>'L1','brand'=>'XYZ','bagType'=>'PP','packingSize'=>'25 KG','orderedQty'=>1000,'receivedQty'=>800]]],
 ['entity'=>'TTI','poNo'=>'AUG','supplier'=>'Bag One','issuedAt'=>'2026-08-20','contractRef'=>'C2','lines'=>[['lineKey'=>'L1','brand'=>'XYZ','bagType'=>'PP','packingSize'=>'25 KG','orderedQty'=>800,'receivedQty'=>400]]],
 ['entity'=>'BRM','poNo'=>'BRM','supplier'=>'Other','issuedAt'=>'2026-09-10','lines'=>[['lineKey'=>'L1','brand'=>'XYZ','orderedQty'=>999,'receivedQty'=>999]]],
 ['entity'=>'TTI','poNo'=>'OTHER','supplier'=>'Bag Two','issuedAt'=>'2026-09-10','lines'=>[['lineKey'=>'L1','brand'=>'ABC','orderedQty'=>50,'receivedQty'=>0]]],
];
$mill=[['id'=>1,'poNo'=>'SEPT','_ttBridge'=>'exports','_ttBridgeId'=>'contract|1','received'=>800],['id'=>2,'poNo'=>'AUG','_ttBridge'=>'exports','_ttBridgeId'=>'other|1','received'=>400],['id'=>3,'poNo'=>'BRM','_ttBridge'=>'exports','_ttBridgeId'=>'brm|1','received'=>999]];
$receipts=[['id'=>11,'bagId'=>1,'date'=>'2026-09-15','qty'=>600],['id'=>12,'bagId'=>1,'date'=>'2026-10-01','qty'=>100],['id'=>13,'bagId'=>2,'date'=>'2026-09-20','qty'=>400],['id'=>14,'bagId'=>3,'date'=>'2026-09-20','qty'=>999]];
$originalOrders=$orders;$orders[0]['issuedAt']='2026-10-14';
$exports=['shipments'=>[['seller'=>'TTI','bagOrders'=>[['poNo'=>'SEPT','supplier'=>'Bag One','issuedAt'=>'2026-10-14','versions'=>[['snapshot'=>['issuedAt'=>'2026-09-12T10:00:00Z']]]]]]]];
$orders=bor_original_dates($orders,$exports,'TTI');check($orders[0]['originalIssuedAt']==='2026-09-12','Amendments never move an order to another month');check(!isset($originalOrders[0]['originalIssuedAt']),'Original persisted source unchanged');
$filters=['from'=>'2026-09-01','to'=>'2026-09-30','brand'=>'xyz','basis'=>'activity'];$before=json_encode([$orders,$mill,$receipts]);
$r=bor_report($orders,$mill,$receipts,'TTI',$filters);
check(count($r['rows'])===2,'Company and brand scope');check($r['totals']['orderedInPeriod']===1000.0,'Order date controls ordered month');check($r['totals']['receivedInPeriod']===1000.0,'Receipt date controls receipt month including older orders');check($r['totals']['receivedThroughEnd']===1000.0,'October delivery excluded from September as-of');check($r['totals']['receivedCurrent']===1200.0&&$r['totals']['undatedReceived']===100.0,'Unmatched prior snapshot remains undated');check($r['totals']['outstandingCurrent']===600.0,'Current outstanding separately labelled');check(count($r['warnings'])===1,'Undated receipts visibly flagged');
$r=bor_report($orders,$mill,$receipts,'TTI',$filters+[]);check($r['rows'][0]['poNo']==='AUG','Chronological output');
$filters['basis']='orders';$r=bor_report($orders,$mill,$receipts,'TTI',$filters);check(count($r['rows'])===1&&$r['totals']['receivedInPeriod']===600.0,'Order cohort excludes receipts for older orders');
foreach(['supplier'=>'Bag Two','bagType'=>'Cotton','packingSize'=>'50 KG','q'=>'C2'] as $key=>$value){$r=bor_report($orders,$mill,$receipts,'TTI',array_replace($filters,[$key=>$value]));check(count($r['rows'])===0,'Filter '.$key);}
$duplicate=$receipts;$duplicate[]=$receipts[0];$r=bor_report($orders,$mill,$duplicate,'TTI',$filters);check($r['totals']['receivedInPeriod']===600.0,'Duplicate source receipt is not counted twice');
check(bor_date('2026-02-30')===''&&bor_date('2026-09-20T10:00:00Z')==='2026-09-20','Strict calendar dates');check(json_encode([$orders,$mill,$receipts])===$before,'Report never changes operational or accounting records');
check(bor_issue_date('2026-09-30T20:00:00Z')==='2026-10-01','UTC late September order belongs to October in Karachi');
check(bor_issue_date('2026-09-30T18:59:59Z')==='2026-09-30','Karachi month-end boundary');
check(bor_issue_date('2026-10-01T00:30:00+05:00')==='2026-10-01','Explicit issue timezone retained');
check(bor_issue_date('2026-09-30')==='2026-09-30','Date-only historical orders do not shift');
check(bor_date('2026-09-30')==='2026-09-30','Receipt date-only values unchanged');
$boundaryOrders=$originalOrders;$boundaryOrders[0]['issuedAt']='2026-09-30T20:00:00Z';$r=bor_report([$boundaryOrders[0]],[],[],'TTI',['from'=>'2026-09-01','to'=>'2026-09-30','basis'=>'orders']);check(count($r['rows'])===0,'September cohort excludes October local order');
$r=bor_report([$boundaryOrders[0]],[],[],'TTI',['from'=>'2026-10-01','to'=>'2026-10-31','basis'=>'orders']);check(count($r['rows'])===1,'October cohort includes local month boundary order');
echo "PASS bag order report: company/brand/type/size/supplier/PO filters, order versus receipt dates, cohorts, cutoff, undated balances, duplicate receipt protection and read-only sources\n";
