<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/inventory_reconciliation.php';

const OSB_DATE='2026-07-01';
const OSB_PRODUCTS=['B3','CSR','B2 CSR','Sweeping'];
function osb_authorized(array $user):bool{return in_array($user['role']??'',['Super Admin','Director'],true)&&tt_user_can_access_entity($user,'TTI','Approve');}
function osb_scope():array{return ['entity'=>'TTI','millName'=>'TTI Rice Mills','baseVariety'=>'IRRI-6','commodity'=>'RICE'];}
function osb_rows(string $json):array{
    $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||!array_is_list($rows)||count(array_filter($rows,'is_array'))!==count($rows))throw new RuntimeException('Invalid stock adjustment source.');
    return $rows;
}
function osb_existing(string $json):array{
    return array_values(array_filter(osb_rows($json),static fn($r)=>tt_inv_same_scope($r,osb_scope())&&($r['date']??'')===OSB_DATE&&(!empty($r['openingStock'])||stripos((string)($r['type']??''),'opening')!==false)));
}
/** Append one dated quantity batch; retrying cannot add the same opening twice. */
function osb_apply(string $json,array $input,array $user,string $now):string{
    if(!osb_authorized($user))throw new DomainException('Opening stock requires management access to TTI.');
    if(($input['date']??'')!==OSB_DATE)throw new InvalidArgumentException('Opening date must be 1 July 2026.');
    $quantities=[];foreach(OSB_PRODUCTS as $name){$n=tt_inv_kg($input['tonnes'][$name]??null,false);if($n<=0||$n>100000)throw new InvalidArgumentException('Opening quantity is too large.');$quantities[$name]=round($n*1000,3);}
    $prior=osb_existing($json);
    if($prior){
        if(count($prior)===1&&($prior[0]['baseVariety']??'')==='IRRI-6'&&($prior[0]['byProducts']??[])==$quantities)return $json;
        throw new DomainException('Opening stock already exists for this date and mill. Review the saved opening before changing it.');
    }
    if(!hash_equals(hash('sha256',$json),(string)($input['revision']??'')))throw new RuntimeException('Stock changed. Reopen the opening stock page before saving.',409);
    $rows=osb_rows($json);
    foreach($rows as $r)if(($r['id']??'')==='OPENSTOCK-TTI-IRRI6-20260701')throw new DomainException('An opening record already uses this reference. Review it before changing stock.');
    $rows[]=osb_scope()+['id'=>'OPENSTOCK-TTI-IRRI6-20260701','date'=>OSB_DATE,'type'=>'Opening stock','openingStock'=>true,'managementOnly'=>true,'byProducts'=>$quantities,'ref'=>'Opening by-product quantities as at 1 July 2026','valuationStatus'=>'Quantity only — no monetary valuation posted','createdAt'=>$now,'createdBy'=>(string)($user['full_name']??$user['username']??''),'createdByUserId'=>(int)$user['id']];
    return tt_inv_json($rows);
}
