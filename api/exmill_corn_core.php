<?php
declare(strict_types=1);
require_once __DIR__.'/commodity_weights_core.php';

function tt_exmill_is_corn(array $row): bool {
    if(in_array(strtoupper(trim((string)($row['commodity']??''))),['RICE','SESAME'],true))return false;
    return preg_match('/\b(?:CORN|MAIZE|MAKAI)\b/i',implode(' ',array_map(static fn($k)=>(string)($row[$k]??''),['commodity','baseVariety','product','displayName','commercialProduct'])))===1;
}
function tt_exmill_bulk(array $row): bool {
    return tt_exmill_is_corn($row)&&(($row['looseBulk']??false)===true||preg_match('/\b(?:loose|bulk)\b/i',(string)($row['packingType']??$row['packing']??''))===1);
}
/** A new Corn liability needs an issued Export allocation, not an arbitrary browser link. */
function tt_exmill_corn_authorization(array $soda,array $body,array $root): array {
    if(!empty($body['reversed'])||in_array($soda['status']??$soda['manualStatus']??'',['Cancelled','Completed','Short Closed','Manual Closed'],true))throw new InvalidArgumentException('The Corn purchase Soda is closed or the lifting needs an Accounts correction.');
    $norm=static fn($v)=>strtolower(trim((string)preg_replace('/\s+/',' ',(string)$v)));
    $shipmentId=trim((string)($body['shipmentId']??''));$contractRef=trim((string)($body['contractRef']??''));$lotRef=trim((string)($body['lotRef']??''));$key=trim((string)($body['instructionKey']??''));
    $contract=null;$shipment=null;$allocation=null;
    foreach((array)($root['contracts']??[]) as $row)if(($row['ref']??'')===$contractRef)$contract=$row;
    foreach((array)($root['shipments']??[]) as $row)if(($row['id']??'')===$shipmentId&&($row['contractRef']??'')===$contractRef&&($row['lotId']??'')===$lotRef)$shipment=$row;
    if(!$contract||!$shipment||!tt_exmill_is_corn($contract)||!empty($contract['cancelled'])||in_array($contract['status']??'',['Cancelled','Closed'],true)||in_array($shipment['status']??'',['Cancelled','Completed','Closed'],true))throw new InvalidArgumentException('The issued Corn shipment instruction is unavailable or closed.');
    $seller=strtoupper((string)($shipment['seller']??$contract['seller']??'TTI'));$entity=$seller==='TG'?strtoupper((string)($shipment['customs']['exporter']??'TTI')):$seller;
    if($entity!==strtoupper((string)($soda['entity']??'')))throw new InvalidArgumentException('The Corn shipment and purchase Soda must belong to the same Pakistan company.');
    $base=$norm($contract['variety']??$contract['product']??'');
    if($base===''||$base!==$norm($soda['baseVariety']??$soda['displayName']??''))throw new InvalidArgumentException('The Corn shipment product does not match the purchase Soda.');
    foreach((array)($root['millSync']['exportLoading']??[]) as $handoff){
        if(($handoff['shipmentId']??'')!==$shipmentId||($handoff['contractRef']??'')!==$contractRef||($handoff['lotId']??'')!==$lotRef)continue;
        foreach((array)($handoff['plan']['allocations']??[]) as $i=>$row){
            if($key!==$shipmentId.'|'.$contractRef.'|'.$lotRef.'|'.$i)continue;
            if(!preg_match('/external|ex-mill/i',(string)($row['type']??''))||$norm($row['name']??'')!==$norm($soda['locationName']??$soda['location']??''))continue;
            $allocation=$row;
        }
    }
    if(!$allocation)throw new InvalidArgumentException('Select the issued outside-mill Corn allocation linked to this Soda location.');
    $packing=$contract['packings'][(int)($allocation['packIndex']??0)]??null;
    if(!is_array($packing))throw new InvalidArgumentException('The Corn instruction packing requires review.');
    $bulk=tt_exmill_bulk(['commodity'=>'CORN','looseBulk'=>$packing['looseBulk']??false,'packingType'=>$packing['type']??'']);
    $bags=$body['bags']??0;if(!is_numeric($bags)||!is_finite((float)$bags)||(float)$bags<0||floor((float)$bags)!==(float)$bags||($bulk?(float)$bags!==0.:(float)$bags<=0))throw new InvalidArgumentException('Loose bulk has zero bags; packed Corn requires a positive whole bag count.');
    if(($body['qualityStatus']??'')!=='OK'||trim((string)($body['seal']??''))===''||!preg_match('/^[A-Z]+-?[0-9]+$/',(string)($body['truck']??''))||!preg_match('/^[A-Z]{4}[0-9]{6}-?[0-9]$/',(string)($body['container']??'')))throw new InvalidArgumentException('Save valid container, truck, seal and approved quality before posting Corn loading.');
    $weights=tt_corn_weights($body);
    if(abs($weights['netKg']-(float)($body['kg']??0))>.001)throw new InvalidArgumentException('Corn net weight does not reconcile with its weighbridge reading and deductions.');
    $count=(int)ceil((float)($allocation['containers']??0));$quantity=(float)($allocation['containers']??0)*(float)($allocation['weightPer']??0)*1000;
    if($count<1||$quantity<=0)throw new InvalidArgumentException('The Corn instruction needs a positive container count and quantity.');
    return $weights+['instructionKey'=>$key,'looseBulk'=>$bulk,'packingType'=>(string)($packing['type']??''),'totalContainers'=>$count,'quantityKg'=>$quantity];
}
/** Shared completion uses the instruction packing; a client flag cannot waive bags. */
function tt_exmill_corn_loads(string $loadsJson,string $instructionsJson,?string $previous=null): void {
    $loads=json_decode($loadsJson,true,512,JSON_THROW_ON_ERROR);$instructions=json_decode($instructionsJson?:'[]',true,512,JSON_THROW_ON_ERROR);$prior=json_decode($previous?:'[]',true,512,JSON_THROW_ON_ERROR);$old=[];
    foreach((array)$prior as $row)$old[(string)($row['id']??'')]=$row;
    foreach((array)$loads as $row){
        $instruction=null;foreach((array)$instructions as $candidate)if((string)($candidate['id']??'')===(string)($row['instructionId']??'')){$instruction=$candidate;break;}
        if(!$instruction||!tt_exmill_is_corn($instruction)||(float)($row['kg']??0)<=0)continue;
        $historical=$old[(string)($row['id']??'')]??null;if($row===$historical)continue;
        if(empty($row['weightBasis'])&&is_array($historical)){ $unchanged=true;foreach(['kg','netKg','weighbridgeKg','silicaKg','kraftKg','bagTareKg','containerTareKg'] as $key)if(($row[$key]??null)!==($historical[$key]??null))$unchanged=false;if($unchanged)continue; }
        $calc=tt_corn_weights($row);foreach($calc as $field=>$value){$saved=$field==='weight'?($row['kg']??null):($row[$field]??null);if(!is_numeric($saved)||abs((float)$saved-$value)>.001)throw new InvalidArgumentException('Corn container '.$field.' does not reconcile with its weighbridge reading.');}
        if(tt_exmill_bulk($instruction)?(float)($row['bags']??0)!==0.:(float)($row['bags']??0)<=0)throw new InvalidArgumentException('Corn bag quantity must match the issued packing.');
    }
}
