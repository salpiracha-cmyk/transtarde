<?php
declare(strict_types=1);

/**
 * Canonical product-stage identity shared by Super Admin, Accounts and Mill.
 * Existing records are never renamed in storage; callers can use the inferred
 * flag to identify records that still need an owner review.
 */
function tt_product_stage(string $stage): string {
    $stage=strtoupper(trim($stage));
    return in_array($stage,['RAW','READY','FINISHED'],true)?$stage:'';
}

function tt_product_base(string $name): string {
    $name=trim((string)preg_replace('/\s+/',' ',$name));
    $name=(string)preg_replace('/\s+(?:RAW|READY|FINISHED)\s+RICE$/i','',$name);
    // “Ready Rice — X” is the historical name for own-mill finished stock.
    $name=(string)preg_replace('/^READY\s+RICE\s*[—-]\s*/i','',$name);
    return trim($name);
}

function tt_product_display(string $commodity,string $base,string $stage): string {
    $commodity=strtoupper(trim($commodity));$base=tt_product_base($base);$stage=tt_product_stage($stage);
    if($commodity==='RICE'){
        if($stage==='RAW')return trim($base.' RAW RICE');
        if($stage==='READY')return trim($base.' READY RICE');
        return $base;
    }
    if($commodity==='SESAME')return trim($base.' '.($stage==='READY'?'READY':'RAW').' SESAME');
    return $base;
}

function tt_product_identity(string $commodity,string $name,string $stageHint=''): array {
    $commodity=strtoupper(trim($commodity));$source=trim($name);$stage=tt_product_stage($stageHint);$inferred=false;
    if($stage===''){
        if(preg_match('/\s+RAW\s+RICE$/i',$source))$stage='RAW';
        elseif(preg_match('/\s+READY\s+RICE$/i',$source))$stage='READY';
        elseif(preg_match('/^READY\s+RICE\s*[—-]/i',$source))$stage='FINISHED';
        elseif($commodity==='RICE'){$stage='RAW';$inferred=true;}
        elseif($commodity==='SESAME'&&stripos($source,'ready')!==false)$stage='READY';
        else{$stage='RAW';$inferred=true;}
    }
    $base=tt_product_base($source);
    return ['commodity'=>$commodity,'baseVariety'=>$base,'productStage'=>$stage,'displayName'=>tt_product_display($commodity,$base,$stage),'stageInferred'=>$inferred];
}

function tt_purchase_product_profiles(?array $masters=null): array {
    if($masters===null&&function_exists('tt_list_masters'))$masters=tt_list_masters();
    $rows=(array)($masters['purchase_products']??[]);$out=[];
    foreach($rows as $row){
        if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<10)$v[]='';
        $stage=tt_product_stage((string)$v[2]);if($stage===''||strcasecmp((string)$v[8],'Inactive')===0)continue;
        $out[]=['id'=>(string)($row['id']??''),'commodity'=>strtoupper((string)$v[0]),'baseVariety'=>(string)$v[1],'productStage'=>$stage,'displayName'=>tt_product_display((string)$v[0],(string)$v[1],$stage),'purchaseUnit'=>(string)$v[3],'katProfile'=>(string)$v[4],'katTreatment'=>(string)$v[5],'brokerageRule'=>(string)$v[6],'inventoryAccount'=>(string)$v[7],'status'=>(string)$v[8],'notes'=>(string)$v[9]];
    }
    return $out;
}

function tt_find_purchase_product(string $id,array $profiles): ?array {
    foreach($profiles as $profile)if(is_array($profile)&&(string)($profile['id']??'')===$id)return $profile;
    return null;
}
