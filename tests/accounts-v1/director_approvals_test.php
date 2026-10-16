<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/director_approvals_core.php';
$bill=['id'=>'B1','entity'=>'TTI','poNo'=>'PO1','lineKey'=>'L1','bags'=>5,'ratePerBag'=>999,'status'=>'Hold - Director Approval Required','supplierSettlement'=>'Supplier contacted; specifications accepted'];
$line=['lineKey'=>'L1','orderedQty'=>100,'receivedQty'=>100,'ratePerBag'=>1,'acceptance'=>['printing'=>true]];
$store=['bagPurchaseOrders'=>['PO1'=>['entity'=>'TTI','lines'=>[$line]],'PO2'=>['entity'=>'BRM','lines'=>[$line]],'CLEAN'=>['entity'=>'TTI','lines'=>[array_replace($line,['acceptance'=>[]])]]],
'bagSupplierBills'=>['B1'=>$bill,'C'=>array_replace($bill,['id'=>'C','status'=>'Cancelled']),'P'=>array_replace($bill,['id'=>'P','journalId'=>'J1','status'=>'Posted']),'R'=>array_replace($bill,['id'=>'R','poNo'=>'CLEAN']),'M'=>array_replace($bill,['id'=>'M','lineKey'=>'missing']),'H'=>array_replace($bill,['id'=>'H','historical'=>true])],
'nonWovenBagSupplierBills'=>['N1'=>array_replace($bill,['id'=>'N1','poNo'=>'PO2','entity'=>'BRM'])]];
$before=$store;$requests=tt_pending_director_rate_approvals($store);
if(count($requests)!==2||$requests[0]['billId']!=='B1'||$requests[1]['kind']!=='nonwoven'||$store!==$before)throw new RuntimeException('Physical acceptance queue regression');
if(tt_pending_director_rate_approvals([])!==[])throw new RuntimeException('Empty queue regression');
echo "PASS shared physical acceptance queue; rate-only differences excluded; source/entity/history boundaries and zero writes\n";

$store['jvDrafts']=['JVD1'=>['id'=>'JVD1','entity'=>'TTI','status'=>'Pending Approval','date'=>'2026-10-06','createdBy'=>'Tayyab','reference'=>'','narration'=>'JV to review','lines'=>[['account'=>'1120','debit'=>10],['account'=>'1110','credit'=>10]],'totalDebit'=>10,'totalCredit'=>10],'JVD2'=>['entity'=>'TTI','status'=>'Draft'],'JVD3'=>['entity'=>'TG','status'=>'Posted']];
$before=$store;$jvs=tt_pending_jv_approvals($store);
if(count($jvs)!==1||$jvs[0]['id']!=='JVD1'||count($jvs[0]['lines'])!==2||$store!==$before)throw new RuntimeException('Pending JV queue must be complete and read-only.');
echo "Pending JV queue includes Tayyab, original lines and approval identity without importing drafts or posted records.\n";
