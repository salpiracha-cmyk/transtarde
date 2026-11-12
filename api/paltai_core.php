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

/** Internal return of existing sweeping; no purchase, finished output or labour is created. */
function pt_return_prepare(array $values,array $row,?array $before,array $accepted,string $now):array {
    if($before){if($row!=$before)throw new DomainException('This return is already saved. Review the original stock movement.');return $before;}
    $date=(string)($row['date']??'');$today=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');
    if(($row['returnVersion']??0)!==1||!in_array($row['entity']??'',['TTI','BRM'],true)||trim((string)($row['millName']??''))==='')throw new InvalidArgumentException('Reopen the mill and company before saving this return.');
    if(!mr_valid_date($date)||$date>$today)throw new InvalidArgumentException('Choose today or the actual past return date.');
    $from=(string)($row['from']??'');$to=(string)($row['to']??'');$kg=$row['weightKg']??null;
    if(!is_numeric($kg)||!is_finite((float)$kg)||(float)$kg<=0||abs((float)$kg-round((float)$kg,3))>.0000001)throw new InvalidArgumentException('Enter a positive return weight in kg, using up to three decimal places.');$kg=(float)$kg;
    if(!preg_match('/^Sweeping$/i',$from)||!preg_match('/^.+ Raw Rice$/i',$to)||strlen($to)>300)throw new InvalidArgumentException('Choose Sweeping as the source and a raw rice variety as the destination.');
    if(($row['rawStockName']??'')!==$to||!is_numeric($row['rawRiceKg']??null)||abs((float)$row['rawRiceKg']-$kg)>.0000001||($row['byProducts']??[])!=[$from=>-$kg])throw new InvalidArgumentException('Sweeping removed and raw rice added must be the same weight.');
    foreach(['readyRiceKg','arrivalKg','openingStock','paltaiVersion','sweepingKg'] as $key)if(!empty($row[$key]))throw new InvalidArgumentException('A return cannot add finished rice, purchases or another stock movement.');
    $current=$values;$current['tt32stockadj']=tt_inv_json($accepted);$atDate=0.;
    $stock=tt_inv_stock($current,$row,static function($event)use(&$atDate,$date,$from){if($event['product']===$from&&($event['date']===''||$event['date']<=$date))$atDate+=(float)$event['kg'];});
    $available=max(0,min((float)($stock[$from]??0),$atDate));
    if($kg>$available+.001)throw new InvalidArgumentException('Only '.number_format($available,3).' kg of Sweeping is available for this mill, company and return date. Check the weight or record the missing stock first.');
    return $row;
}
