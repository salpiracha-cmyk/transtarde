<?php
declare(strict_types=1);

/** Commercial responsibility only. A selection never receives bags or posts money. */
function ba_catalog(array $books,string $entity):array {
    $out=[];foreach(['OUR'=>'Our Bags','FELLOW'=>"Fellow Exporter’s Bags",'SHARED'=>'Shared Arrangement','BULK'=>'No Bags / Bulk'] as $id=>$name)$out[$id]=['id'=>$id,'name'=>$name,'basis'=>$id,'version'=>1,'builtin'=>true,'active'=>true];
    foreach((array)($books['bagArrangements']??[]) as $id=>$row)if(is_array($row)&&($row['entity']??'')===$entity)$out[(string)$id]=$row;
    return $out;
}
function ba_text(mixed $value,int $limit):string {if(!is_scalar($value)&&$value!==null)throw new DomainException('Invalid bag responsibility detail.');$s=trim((string)$value);if(strlen($s)>$limit)throw new DomainException('Bag responsibility detail is too long.');return $s;}
function ba_choice(mixed $value,array $allowed,string $label):string {$s=ba_text($value,100);if(!in_array($s,$allowed,true))throw new DomainException('Select '.$label.'.');return $s;}
function ba_number(mixed $value,float $maximum,string $label):float {if(is_bool($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>$maximum)throw new DomainException('Enter a valid '.$label.'.');return round((float)$value,4);}
function ba_projection(array $deal):array {return is_array($deal['bagTerms']??null)?$deal['bagTerms']:['status'=>'PENDING_CONFIRMATION','arrangementId'=>'','arrangementName'=>'Pending Confirmation','financialEffect'=>'NONE_UNTIL_ACTUAL_POSTING'];}
function ba_terms(array $books,string $entity,array $input,array $suppliers):array {
    $id=ba_text($input['arrangementId']??'',120);if($id==='')return ['status'=>'PENDING_CONFIRMATION','arrangementId'=>'','arrangementName'=>'Pending Confirmation','financialEffect'=>'NONE_UNTIL_ACTUAL_POSTING'];
    $choice=ba_catalog($books,$entity)[$id]??null;if(!$choice||empty($choice['active']))throw new DomainException('Choose an active bag arrangement in these company books.');
    $basis=$choice['basis'];$out=['status'=>'CONFIRMED','arrangementId'=>$id,'arrangementName'=>$choice['name'],'arrangementVersion'=>(int)$choice['version'],'basis'=>$basis,'financialEffect'=>'NONE_UNTIL_ACTUAL_POSTING'];
    if($basis==='BULK')return $out+['owner'=>'NONE','costBearer'=>'NONE','settlement'=>'NONE','costBasis'=>'NONE','supplierId'=>'','supplierName'=>'','ourSharePct'=>0.,'fellowSharePct'=>0.,'ratePKRPerBag'=>0.];
    $owner=ba_choice($input['owner']??'', ['OUR','FELLOW'],'the actual bag owner');$bearer=ba_choice($input['costBearer']??'', ['OUR','FELLOW','SHARED'],'who bears the bag cost');
    if(($basis==='OUR'&&$owner!=='OUR')||($basis==='FELLOW'&&$owner!=='FELLOW'))throw new DomainException('Bag ownership conflicts with the selected arrangement.');
    if($basis==='SHARED'&&$bearer!=='SHARED')throw new DomainException('Shared arrangement requires both parties’ cost shares.');
    $supplierId=ba_text($input['supplierId']??'',120);$supplier=$suppliers[$supplierId]??null;if(!$supplier)throw new DomainException('Select the bag supplier from the active party master.');
    $settlement=ba_choice($input['settlement']??'', ['INCLUDED','REIMBURSEMENT','SEPARATE'],'whether bags are included, reimbursable or separately borne');$costBasis=ba_choice($input['costBasis']??'', ['ACTUAL','PER_BAG'],'actual bag cost or an agreed rate per bag');
    $share=$bearer==='SHARED'?ba_number($input['ourSharePct']??null,100,'our bag-cost share percentage'):($bearer==='OUR'?100.:0.);if($bearer==='SHARED'&&($share<=0||$share>=100))throw new DomainException('Shared bag cost needs a positive share below 100% for each party.');
    $rate=$costBasis==='PER_BAG'?ba_number($input['ratePKRPerBag']??null,100000000,'PKR rate per bag'):0.;if($costBasis==='PER_BAG'&&$rate<=0)throw new DomainException('Enter the positive agreed PKR rate per bag.');
    return $out+['owner'=>$owner,'costBearer'=>$bearer,'supplierId'=>$supplierId,'supplierName'=>$supplier,'settlement'=>$settlement,'costBasis'=>$costBasis,'ourSharePct'=>$share,'fellowSharePct'=>round(100-$share,4),'ratePKRPerBag'=>$rate];
}
function ba_suppliers(array $masters,array $parties=[]):array {$out=[];foreach((array)($masters['business_parties']??[]) as $row){$v=(array)($row['values']??[]);if(strcasecmp((string)($v[10]??'Active'),'Inactive')===0)continue;$name=trim((string)($v[0]??''));if($name!=='')$out[(string)$row['id']]=$name;}foreach($parties as $party)if(!empty($party['id'])&&!empty($party['name']))$out[(string)$party['id']]=(string)$party['name'];return $out;}
function ba_manage(array &$books,string $entity,array $input,array $user):array {
    if(!in_array($user['role']??'', ['Super Admin','Director'],true))throw new DomainException('A Director or Super Admin must manage bag arrangements.');
    $id=ba_text($input['arrangementId']??'',120);$old=$id!==''?($books['bagArrangements'][$id]??null):null;
    if($id!==''&&(!$old||($old['entity']??'')!==$entity))throw new DomainException('Select a custom arrangement in these company books.');
    if($old&&(int)($input['version']??-1)!==(int)$old['version'])throw new DomainException('Arrangement changed. Reopen before editing.');
    $name=ba_text($input['name']??'',100);if($name==='')throw new DomainException('Enter the arrangement name.');$basis=ba_choice($input['basis']??'', ['OUR','FELLOW','SHARED','BULK'],'the underlying bag arrangement');
    foreach(ba_catalog($books,$entity) as $key=>$row)if($key!==$id&&strcasecmp($row['name'],$name)===0)throw new DomainException('This bag arrangement name already exists.');
    $reason=ba_text($input['reason']??'',500);if($old&&strlen($reason)<5)throw new DomainException('Enter an amendment reason of at least five characters.');
    if($id==='')$id='BAGARR-'.bin2hex(random_bytes(9));
    $row=['id'=>$id,'entity'=>$entity,'name'=>$name,'basis'=>$basis,'active'=>($input['active']??true)!==false,'builtin'=>false,'version'=>(int)($old['version']??0)+1,'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['username']??'')];
    $books['bagArrangements'][$id]=$row;$books['bagArrangementAudit'][]=['before'=>$old,'after'=>$row,'reason'=>$reason,'by'=>$user['username']??'','at'=>gmdate('c')];return ['arrangementId'=>$id];
}

/** Only the physical owner's identity is projected into Mill; commercial rates stay private. */
function ba_physical_responsibility(array $deal):array {$t=ba_projection($deal);return array_intersect_key($t,array_flip(['status','arrangementName','basis','owner']));}
