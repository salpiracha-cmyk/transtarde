<?php
declare(strict_types=1);

/** Mirror the entry calculation at the shared-save boundary. No inferred historical deductions. */
function tt_corn_weights(array $row): array {
    $read=static function(string $key)use($row):float {
        $v=$row[$key]??0;
        if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new DomainException('Enter valid non-negative '.$key.'.');
        return (float)$v;
    };
    $basis=(string)($row['weightBasis']??'');
    if(!in_array($basis,['COMMODITY_NET','PACKED_CARGO','VEHICLE_GROSS'],true))throw new DomainException('Select the weighbridge weight basis.');
    $bridge=$read('weighbridgeKg');$silica=$read('silicaKg');$kraft=$read('kraftKg');$bag=$read('bagTareKg');$tare=$read('containerTareKg');
    if($basis!=='VEHICLE_GROSS'&&$tare>0)throw new DomainException('Vehicle tare is only allowed when included in the weighbridge reading.');
    $packing=$silica+$kraft+$bag;
    if($basis==='COMMODITY_NET'&&$packing>0)throw new DomainException('Net commodity weight must not include another packing deduction.');
    $gross=$bridge-($basis==='VEHICLE_GROSS'?$tare:0);$net=$gross-($basis==='COMMODITY_NET'?0:$packing);
    if($net<=0)throw new DomainException('Net commodity weight must be greater than zero after deductions.');
    return ['weight'=>$net,'netKg'=>$net,'cargoGrossKg'=>$gross,'packingKg'=>$packing];
}
function tt_validate_corn_loading(string $json):void {
    $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows))throw new DomainException('Invalid loading instructions.');
    foreach($rows as $shipment){
        foreach((array)($shipment['containers']??[]) as $row){
            if(empty($row['weightBasis']))continue; // Historical readings retain their original facts.
            $calc=tt_corn_weights($row);
            foreach($calc as $key=>$value)if(!isset($row[$key])||!is_numeric($row[$key])||abs((float)$row[$key]-$value)>.001)throw new DomainException('Container '.$key.' does not reconcile with its weighbridge reading and deductions.');
        }
    }
}
