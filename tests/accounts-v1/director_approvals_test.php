<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/api/director_approvals_core.php';
$bill = ['id'=>'B1','entity'=>'TTI','poNo'=>'PO1','lineKey'=>'L1','ratePerBag'=>12,'poRatePerBag'=>12,'status'=>'Hold - Billed Above Received'];
$store = ['bagPurchaseOrders'=>['PO1'=>['lines'=>[['lineKey'=>'L1','ratePerBag'=>10]]]],
    'bagSupplierBills'=>['B1'=>$bill,'B2'=>array_replace($bill,['id'=>'B2','rateExceptionApprovedAt'=>'2026-10-01']),
        'B3'=>array_replace($bill,['id'=>'B3','journalId'=>'J1']), 'B4'=>array_replace($bill,['status'=>'Cancelled']),
        'B5'=>array_replace($bill,['ratePerBag'=>10]), 'B6'=>array_replace($bill,['entity'=>'TG']),
        'B7'=>array_replace($bill,['lineKey'=>'missing']), 'B8'=>array_replace($bill,['status'=>'Posted'])],
    'nonWovenBagSupplierBills'=>['N1'=>array_replace($bill,['id'=>'N1','entity'=>'BRM'])]];
$before = $store;
$requests = tt_pending_director_rate_approvals($store);
if (count($requests)!==2 || $requests[0]['billId']!=='B1' || $requests[1]['kind']!=='nonwoven'
    || $requests[1]['entity']!=='BRM' || $store!==$before) throw new RuntimeException('Shared approval queue regression.');
if (tt_pending_director_rate_approvals([])!==[]) throw new RuntimeException('Empty queue regression.');
echo "Director approvals: shared rate queue, current PO rate, held quantities and exclusions passed.\n";
