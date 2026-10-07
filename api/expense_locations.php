<?php
declare(strict_types=1);

/** Bill responsibility belongs to the location master, never to a free-text supplier name. */
function ev1_owned_locations(string $entity): array {
    $out=[];
    foreach(tt_active_location_masters() as $row){
        $v=array_values((array)($row['values']??[]));$type=tt_normalize_location_type((string)($v[2]??''));
        if(in_array($type,['External Mill','Reprocessing Mill'],true))continue;
        $owner=strtoupper(trim((string)($v[7]??'')));
        // The two original group locations have stable master identities.
        if($owner===''&&($row['id']??'')==='mills-1'&&tt_location_identity((string)($v[0]??''))===tt_location_identity('TTI Rice Mills'))$owner='TTI';
        if($owner===''&&($row['id']??'')==='mills-2'&&tt_location_identity((string)($v[0]??''))===tt_location_identity('Karachi Office'))$owner='TTI';
        if($owner!==$entity)continue;
        $out[]=['id'=>(string)$row['id'],'name'=>(string)($v[0]??''),'type'=>$type,'location'=>$type==='Own Mill'?'MILL':($type==='Office'?'OFFICE':'OTHER'),'entity'=>$owner];
    }
    return $out;
}

function ev1_bill_location(array $body,string $entity): array {
    $id=trim((string)($body['locationId']??''));$name=trim((string)($body['locationName']??''));
    foreach(ev1_owned_locations($entity) as $location){
        if(($id!==''&&$id===$location['id'])||($id===''&&tt_location_identity($name)===tt_location_identity($location['name'])))return $location;
    }
    throw new InvalidArgumentException('Select a location whose bills are paid by this company in Mills & Locations Master.');
}

function ev1_reading_period(array $body,bool $required): array {
    $from=trim((string)($body['readFrom']??''));$to=trim((string)($body['readTo']??''));
    if(!$required&&$from===''&&$to==='')return [];
    $from=ev1_date($from,'Previous reading date');$to=ev1_date($to,'Current reading date');
    if($to<$from)throw new InvalidArgumentException('Current reading date cannot be before the previous reading date.');
    $previous=$body['previousReading']??'';$current=$body['currentReading']??'';
    if(($previous==='' xor $current==='')||($previous!==''&&(!is_numeric($previous)||!is_numeric($current)||!is_finite((float)$previous)||!is_finite((float)$current)||(float)$previous<0||(float)$current<(float)$previous)))throw new InvalidArgumentException('Enter both meter readings, with the current reading at least the previous reading.');
    return ['readFrom'=>$from,'readTo'=>$to,'previousReading'=>$previous===''?null:(float)$previous,'currentReading'=>$current===''?null:(float)$current,'units'=>$previous===''?null:round((float)$current-(float)$previous,3)];
}
