<?php
declare(strict_types=1);
require_once __DIR__.'/expense_locations.php';

function mcl_locations(string $entity): array {
    return array_values(array_filter(ev1_owned_locations($entity),static fn($r)=>$r['location']==='MILL'));
}
function mcl_location(string $entity,string $id): array {
    foreach(mcl_locations($entity) as $row)if($row['id']===$id)return $row;
    throw new DomainException('Choose an active company mill from Mills & Locations Master.');
}
function mcl_rows(array $state,string $entity,string $locationId): array {
    $rows=[];
    foreach((array)($state['journals']??[]) as $j){
        if(($j['entity']??'')!==$entity||($j['status']??'')!=='Posted'||!empty($j['reversalOf'])||!empty($j['reversedByPostId'])||!empty($j['amendedByPostId']))continue;
        foreach((array)($j['lines']??[]) as $i=>$line){
            if(($line['account']??'')!=='1120'||($line['millLocationId']??'')!==$locationId)continue;
            $rows[]=['id'=>'ACCOUNTS|'.$j['id'].'|'.$i,'postId'=>$j['meta']['publicPostId']??$j['id'],'date'=>$j['date'],'ref'=>$j['narration']??'','voucher'=>$j['meta']['publicPostId']??$j['id'],'debit'=>(float)($line['debit']??0),'credit'=>(float)($line['credit']??0),'type'=>(float)($line['debit']??0)>0?'PETTY CASH RECEIVED':'Accounts Cash Payment','source'=>'Accounts'];
        }
    }
    usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['id'],$b['id']));
    return $rows;
}
