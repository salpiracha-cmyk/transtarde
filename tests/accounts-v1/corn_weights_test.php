<?php
declare(strict_types=1);
require __DIR__.'/../../api/commodity_weights_core.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$net=tt_corn_weights(['weightBasis'=>'COMMODITY_NET','weighbridgeKg'=>27500]);check($net['netKg']===27500.,'Direct commodity net');
$packed=['weightBasis'=>'PACKED_CARGO','weighbridgeKg'=>27500,'silicaKg'=>10,'kraftKg'=>15,'bagTareKg'=>5];$r=tt_corn_weights($packed);check($r['netKg']===27470.&&$r['cargoGrossKg']===27500.,'Packing deducted once');
$vehicle=$packed; $vehicle['weightBasis']='VEHICLE_GROSS';$vehicle['weighbridgeKg']=31500;$vehicle['containerTareKg']=4000;check(tt_corn_weights($vehicle)['netKg']===27470.,'Vehicle/container tare deducted only when included');
tt_validate_corn_loading(json_encode([['commodity'=>'MAIZE','containers'=>[$packed+$r]]]));
foreach([
 ['weightBasis'=>'COMMODITY_NET','weighbridgeKg'=>27500,'silicaKg'=>10],
 ['weightBasis'=>'PACKED_CARGO','weighbridgeKg'=>10,'kraftKg'=>15],
 ['weightBasis'=>'PACKED_CARGO','weighbridgeKg'=>27500,'containerTareKg'=>4000],
 ['weightBasis'=>'PACKED_CARGO','weighbridgeKg'=>'bad']
] as $bad){$failed=false;try{tt_corn_weights($bad);}catch(DomainException $e){$failed=true;}check($failed,'Reject inconsistent or invalid deductions');}
$bad=$packed+$r;$bad['weight']=27500;$failed=false;try{tt_validate_corn_loading(json_encode([['containers'=>[$bad]]]));}catch(DomainException $e){$failed=true;}check($failed,'Tampered net weight rejected at server save');
echo "PASS corn weighbridge bases, packing/vehicle deductions and server reconciliation\n";

$historical=[['id'=>10,'commodity'=>'MAIZE','containers'=>[['id'=>1,'weight'=>27000]]]];
tt_validate_corn_loading(json_encode($historical),json_encode($historical));
$changed=$historical;$changed[0]['containers'][0]['weight']=27500;
foreach([[$changed,$historical],[$historical,[]]] as [$next,$old]){$failed=false;try{tt_validate_corn_loading(json_encode($next),json_encode($old));}catch(DomainException $e){$failed=true;}check($failed,'New/corrected Corn weights require a basis while original history remains intact');}
echo "PASS explicit weighbridge basis for new/corrected Corn; original historical readings retained\n";
