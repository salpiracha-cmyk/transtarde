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

function tt_product_type(string $type): string {
    $type=trim((string)preg_replace('/\s+/',' ',$type));
    if ($type==='') return '';
    if (preg_match('/\bPARBOIL(?:ED)?\b|\bSELLA\b/i',$type)) return 'Parboiled / Sella';
    if (preg_match('/\bSTEAM(?:ED)?\b/i',$type)) return 'Steam';
    if (preg_match('/\bWHITE\b/i',$type)) return 'White';
    return trim((string)preg_replace('/\s+RICE$/i','',$type));
}

function tt_product_type_from_name(string $name): string {
    if (preg_match('/\bPARBOIL(?:ED)?\b|\bSELLA\b/i',$name)) return 'Parboiled / Sella';
    if (preg_match('/\bSTEAM(?:ED)?\b/i',$name)) return 'Steam';
    if (preg_match('/\bWHITE\b/i',$name)) return 'White';
    return '';
}

function tt_product_base(string $name): string {
    $name=trim((string)preg_replace('/\s+/',' ',$name));
    $name=(string)preg_replace('/\s+(?:RAW|READY|FINAL|FINISHED)\s+RICE$/i','',$name);
    // “Ready Rice — X” is the historical name for own-mill finished stock.
    $name=(string)preg_replace('/^READY\s+RICE\s*[—-]\s*/i','',$name);
    $name=(string)preg_replace('/\s+(?:WHITE|STEAM(?:ED)?)(?:\s+RICE)?$/i','',$name);
    $name=(string)preg_replace('/\s+(?:PARBOIL(?:ED)?)(?:\s*\/\s*SELLA)?(?:\s+RICE)?$/i','',$name);
    $name=(string)preg_replace('/\s+SELLA(?:\s+RICE)?$/i','',$name);
    return trim($name);
}

function tt_product_display(string $commodity,string $base,string $stage,string $type=''): string {
    $commodity=strtoupper(trim($commodity));$base=tt_product_base($base);$stage=tt_product_stage($stage);$type=tt_product_type($type);
    if($commodity==='RICE'){
        $identity=trim($base.($type!==''?' '.$type:''));
        if($stage==='RAW')return trim($identity.' Raw Rice');
        if($stage==='READY')return trim($identity.' Ready Rice');
        return trim($identity.($type!==''?' Rice':''));
    }
    if($commodity==='SESAME'){
        $base=(string)preg_replace('/\s+(?:RAW|READY)\s+SESAME$/i','',$base);
        if(strcasecmp($base,'Sesame')===0||strcasecmp($base,'Sesame Seed')===0)$base='';
        return trim(($base!==''?$base.' ':'').($stage==='READY'?'READY':'RAW').' SESAME');
    }
    return $base;
}

function tt_product_identity(string $commodity,string $name,string $stageHint='',string $typeHint=''): array {
    $commodity=strtoupper(trim($commodity));$source=trim($name);$stage=tt_product_stage($stageHint);$type=tt_product_type($typeHint)?:tt_product_type_from_name($source);$inferred=false;
    if($stage===''){
        if(preg_match('/\s+RAW\s+RICE$/i',$source))$stage='RAW';
        elseif(preg_match('/\s+READY\s+RICE$/i',$source))$stage='READY';
        elseif(preg_match('/^READY\s+RICE\s*[—-]/i',$source))$stage='FINISHED';
        elseif($commodity==='RICE'){$stage='RAW';$inferred=true;}
        elseif($commodity==='SESAME'&&stripos($source,'ready')!==false)$stage='READY';
        else{$stage='RAW';$inferred=true;}
    }
    $base=tt_product_base($source);
    return ['commodity'=>$commodity,'baseVariety'=>$base,'riceType'=>$type,'productStage'=>$stage,'displayName'=>tt_product_display($commodity,$base,$stage,$type),'stageInferred'=>$inferred];
}

/** Convert old purchase rows to the canonical layout, with an optional broken grade. */
function tt_purchase_product_values(array $values): array {
    $v=array_values($values);while(count($v)<11)$v[]='';
    if (in_array(tt_product_stage((string)$v[2]),['RAW','READY','FINISHED'],true)) {
        $legacy=$v;
        $v=[
            strtoupper((string)$legacy[0]),tt_product_base((string)$legacy[1]),
            strtoupper((string)$legacy[0])==='RICE'?(tt_product_type_from_name((string)$legacy[1])?:'White'):'',
            tt_product_stage((string)$legacy[2])?:'RAW',(string)$legacy[3],(string)$legacy[4],
            (string)$legacy[6],(string)$legacy[7],(string)$legacy[8],(string)$legacy[9],
        ];
    }
    while(count($v)<11)$v[]='';
    $v[0]=strtoupper(trim((string)$v[0]));$v[1]=tt_product_base((string)$v[1]);
    $v[2]=$v[0]==='RICE'?tt_product_type((string)$v[2]):trim((string)$v[2]);
    $v[3]=tt_product_stage((string)$v[3])?:'RAW';
    return array_slice($v,0,11);
}

function tt_purchase_product_profiles(?array $masters=null): array {
    if($masters===null&&function_exists('tt_list_masters'))$masters=tt_list_masters();
    $rows=(array)($masters['purchase_products']??[]);$out=[];
    foreach($rows as $row){
        if(!is_array($row))continue;$v=tt_purchase_product_values((array)($row['values']??[]));
        $stage=tt_product_stage((string)$v[3]);if($stage===''||strcasecmp((string)$v[8],'Inactive')===0)continue;
        $grade=trim((string)$v[10]);$label=tt_product_display((string)$v[0],(string)$v[1],$stage,(string)$v[2]);
        if($grade!==''&&$v[0]==='RICE')$label=trim((string)preg_replace('/\s+(Raw|Ready) Rice$/i','',$label)).' '.$grade.' '.ucfirst(strtolower($stage)).' Rice';
        $out[]=['id'=>(string)($row['id']??''),'commodity'=>(string)$v[0],'baseVariety'=>(string)$v[1],'riceType'=>(string)$v[2],'brokenGrade'=>$grade,'productStage'=>$stage,'purchaseClassification'=>$stage,'displayName'=>$label,'purchaseUnit'=>(string)$v[4],'katProfile'=>(string)$v[5],'status'=>(string)$v[8],'notes'=>(string)$v[9]];
    }
    return $out;
}

function tt_find_purchase_product(string $id,array $profiles): ?array {
    foreach($profiles as $profile)if(is_array($profile)&&(string)($profile['id']??'')===$id)return $profile;
    return null;
}
