<?php
declare(strict_types=1);
/** Production instructions are issued in Accounts/Directors; physical facts stay in Milling. */
function dt_production_choices(array $s,string $entity,string $date):array {
    require_once __DIR__.'/group_daily_core.php';$ops=gd_read_operations(TT_DATA_DIR.'/operations.json');$source=gd_sources($s,$ops,$date);$mills=[];
    $add=static function(string $id,string $name)use(&$mills):void {if(trim($name)!=='')$mills[strtoupper(trim($name))]=['id'=>$id,'name'=>trim($name)];};
    foreach((array)(tt_list_masters()['mills']??[]) as $r){$v=$r['values']??[];if(($v[7]??'')===$entity&&strcasecmp((string)($v[5]??'Active'),'Inactive')!==0&&preg_match('/own|default/i',(string)($v[2]??'')))$add((string)$r['id'],(string)($v[0]??''));}
    foreach(gd_rows($ops['values']??[],'tt30mills') as $r)if(($r['entity']??'')===$entity&&preg_match('/own|default/i',(string)($r['type']??'')))if(!isset($mills[strtoupper(trim((string)$r['name']))]))$add('OP|'.$r['id'],(string)$r['name']);
    $stocks=[];foreach($source['stocks'] as $r)if($r['entity']===$entity){$key=strtoupper(trim($r['millName']));if(!isset($mills[$key]))$add('STOCK|'.substr(hash('sha256',$entity.'|'.$r['millName']),0,20),$r['millName']);$stocks[]=['millName'=>$r['millName'],'name'=>$r['product'],'role'=>$r['role'],'quantityMT'=>$r['quantityMT'],'known'=>$r['known']];}
    return ['mills'=>array_values($mills),'stocks'=>$stocks];
}
function dt_production_instruction(array $d,array $b,array $choices,array $nativeSales,string $date):array {
    if($d['direction']!=='SALE')throw new DomainException('Mill production instructions apply to goods supplied by us.');
    $mill=null;foreach($choices['mills'] as $r)if($r['id']===($b['productionMillId']??''))$mill=$r;if(!$mill)throw new DomainException('Choose the mill that will produce and dispatch this SODA.');
    $brand=gm_text($b['brand']??'',200);$stock=gm_text($b['productionStockName']??'',300);if($stock==='')throw new DomainException('Specify the exact finished output / stock name for this SODA.');
    $mode=dt_enum($b['specificationMode']??'BRIEF',['BRIEF','FULL'],'brief or full mill specification');$full=gm_text($b['fullSpecification']??'',12000);if($mode==='FULL'&&$full==='')throw new DomainException('Enter the full specification to send to the mill.');
    if(trim((string)($d['product']??''))==='')throw new DomainException('Save the product / brief specification on this SODA first.');
    $native=dt_native_state($d,$nativeSales,$date);if($native['loadedMT']>0){foreach($nativeSales as $r)if(dt_native_parent_matches($d,$r)&&!empty($r['containers']))if(($r['stockSourceName']??$r['displayName']??'')!==$stock||strcasecmp(trim((string)($r['millName']??'')),$mill['name'])!==0||($r['brand']??$stock)!==($brand!==''?$brand:$stock))throw new DomainException('Dispatched goods retain their original mill and stock source. Use a separate SODA for a new production route.');}
    $tare=gm_number($b['productionBagTareGrams']??100,'empty bag weight in grams');if($tare>10000)throw new DomainException('Review empty bag weight in grams.');
    return array_replace($d,['productionReady'=>true,'productionMillId'=>$mill['id'],'productionMillName'=>$mill['name'],'productionStockName'=>$stock,'brand'=>$brand,'specificationMode'=>$mode,'fullSpecification'=>$full,'productionNotes'=>gm_text($b['productionNotes']??'',5000),'productionBagTareGrams'=>$tare]);
}
