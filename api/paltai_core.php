<?php
declare(strict_types=1);

/** Validate the physical transfer before either stock or its labour source is written. */
function pt_prepare(array $values,array $row,?array $before,array $accepted):array{
    if($before){if($row!=$before)throw new DomainException('Saved Paltai transfers cannot be rewritten. Review the original movement.');return $before;}
    if(($row['paltaiVersion']??0)!==1||!in_array($row['entity']??'', ['TTI','BRM'],true)||trim((string)($row['millName']??''))===''||!mr_valid_date((string)($row['date']??'')))throw new InvalidArgumentException('Paltai needs its company, mill, date and current save format.');
    foreach(['weightKg','sourceDeductionKg','sourceBagSize','sourceBags','currentBrandBags','outputBagSize','sweepingKg','rawRiceKg','labourRate']as$key){if(!isset($row[$key])||!is_numeric($row[$key])||!is_finite((float)$row[$key])||(float)$row[$key]<0)throw new InvalidArgumentException('Paltai quantities and labour rate must be finite and non-negative.');}
    $kg=(float)$row['weightKg'];$sweep=(float)$row['sweepingKg'];$deduct=(float)$row['sourceDeductionKg'];
    if($kg<=0||(float)$row['sourceBagSize']<=0||(float)$row['outputBagSize']<=0||(float)$row['currentBrandBags']<=0||abs($deduct-$kg-$sweep)>.001||abs((float)$row['rawRiceKg']-$sweep)>.001||abs((float)$row['currentBrandBags']*(float)$row['outputBagSize']-$kg)>.001||abs((float)$row['sourceBags']*(float)$row['sourceBagSize']-$deduct)>.001)throw new InvalidArgumentException('Paltai source, output bags and sweeping quantities must balance.');
    $from=trim((string)($row['from']??''));$to=trim((string)($row['to']??''));$raw=trim((string)($row['rawStockName']??''));
    $ready=static fn(string $s):bool=>(bool)preg_match('/^(?:Ready Rice|B2) — |(?:Rice — | Ready Rice$)/i',$s)&&!preg_match('/Raw Rice/i',$s);
    if(!$ready($from)||!$ready($to)||!preg_match('/Raw Rice$/i',$raw))throw new InvalidArgumentException('Select the exact ready/broken source, destination and Raw Rice identity.');
    $current=$values;$current['tt32stockadj']=tt_inv_json($accepted);$stock=tt_inv_stock($current,$row);
    if(!array_key_exists($from,$stock)||$deduct>(float)$stock[$from]+.001)throw new InvalidArgumentException('Paltai exceeds the saved source stock at this company and mill. Refresh the stock position.');
    return $row;
}

/** Derived labour is committed in the same transaction as the transfer, once per identity. */
function pt_effects(array $values):array{
    $feed=tt_inv_rows($values,'tt32labourfeed');$bySource=[];foreach($feed as$i=>$row)if(!empty($row['paltaiSourceId']))$bySource[(string)$row['paltaiSourceId']]=$i;
    $changed=false;
    foreach(tt_inv_rows($values,'tt32stockadj')as$row){if(($row['type']??'')!=='Paltai Transfer'||($row['paltaiVersion']??0)!==1)continue;
        $id=(string)$row['id'];$labour=tt_inv_scope($row)+['id'=>'PALTAI|'.$id,'paltaiSourceId'=>$id,'serverManaged'=>true,'date'=>$row['date'],'brand'=>$row['to'],'particular'=>'Paltai '.$row['outputBagSize'].'kg','quantity'=>$row['currentBrandBags'],'rate'=>$row['labourRate'],'status'=>'Pending'];
        if(isset($bySource[$id])){if($feed[$bySource[$id]]!=$labour){$feed[$bySource[$id]]=$labour;$changed=true;}}else{$feed[]=$labour;$changed=true;}
    }
    if($changed)$values['tt32labourfeed']=tt_inv_json($feed);return $values;
}

function pt_guard_labour(array $values,string $json):string{
    $rows=json_decode($json,true);if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Labour sources must be an array.');
    $saved=[];foreach(tt_inv_rows($values,'tt32labourfeed')as$r)if(!empty($r['paltaiSourceId']))$saved[(string)$r['id']]=$r;
    $seen=[];foreach($rows as$r){$id=(string)($r['id']??'');if(isset($saved[$id])){if(isset($seen[$id])||$r!=$saved[$id])throw new DomainException('Paltai labour is maintained by the saved stock transfer.');$seen[$id]=true;}elseif(!empty($r['paltaiSourceId']))throw new DomainException('Paltai labour must originate from a saved stock transfer.');}
    if(array_diff_key($saved,$seen))throw new DomainException('Paltai labour cannot be removed.');return $json;
}
