<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_subaccounts_core.php';
function verify(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$store=['supplierBills'=>['CL'=>['entity'=>'TTI','billNo'=>'C-99','sourceRecord'=>['shipmentSections'=>[['containerCount'=>2,'portOfDischarge'=>'Manila','dischargeCountry'=>'Philippines'],['containerCount'=>3,'portOfDischarge'=>'Manila','dischargeCountry'=>'Philippines']]]]],'bagSupplierBills'=>['B'=>['entity'=>'TTI','sellerInvoice'=>'B-42','brand'=>'GOLD','bags'=>1500,'packingSize'=>'50 KG']]];
$journal=['entity'=>'TTI','reference'=>'C-99','narration'=>'Clearing bill','meta'=>['supplierBillId'=>'CL']];$original=$store;
$text=sac_journal_narration($store,$journal);foreach(['Bill No. C-99','Containers 5','Discharge port Manila','Discharge country Philippines'] as $piece)verify(str_contains($text,$piece),$piece);verify(substr_count($text,'Manila')===1,'Port must not repeat for multiple shipments');verify(sac_journal_narration($store,array_replace($journal,['narration'=>$text]))===$text,'Details must not duplicate');
$bag=sac_journal_narration($store,['entity'=>'TTI','narration'=>'Bag purchase','meta'=>['bagBillId'=>'B']]);foreach(['Supplier Bill No. B-42','Brand GOLD','Bags 1,500'] as $piece)verify(str_contains($bag,$piece),$piece);
verify(sac_journal_narration($store,array_replace($journal,['entity'=>'BRM']))==='Clearing bill','Bill details must not cross companies');verify($store===$original,'Presentation must not change records');
$snapshot=sac_journal_narration($store,array_replace($journal,['meta'=>['supplierBillId'=>'CL','shipmentSections'=>[['containerCount'=>1,'portOfDischarge'=>'Dubai','dischargeCountry'=>'UAE']]]]));verify(str_contains($snapshot,'Containers 1')&&!str_contains($snapshot,'Manila'),'Committed journal snapshots win over later bill amendments');
echo "PASS bill narration, combined containers, discharge details, bag brand, immutable snapshots and entity scope\n";

$chart=sac_chart();if(($chart['6810']['class']??'')!=='Expense'||($chart['6820']['class']??'')!=='Expense'||($chart['7200']['name']??'')!=='Donations')throw new RuntimeException('Policy bank charges must appear in the chart without overwriting existing donations');
echo "PASS policy account names/types and existing chart identities\n";
