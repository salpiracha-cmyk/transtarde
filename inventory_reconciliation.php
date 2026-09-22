<?php
declare(strict_types=1);
require_once __DIR__ . '/product_stage.php';

/** Server-owned reconciliation. Operational staff submit facts, never a Ghati balance. */
const TT_INV_PRIVATE_KEYS = ['tt34ghati','tt34nilqueue','tt32processingrecon'];
const TT_INV_CONFIRMATIONS = 'tt39physicalconfirmations';
const TT_INV_SOURCE_KEYS = ['tt30prod','tt30ship','tt30slips','tt32stockadj','tt35localsales','tt35exportersale',TT_INV_CONFIRMATIONS];

function tt_inv_json(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function tt_inv_rows(array $values,string $key): array {
    $rows=json_decode((string)($values[$key]??'[]'),true);
    return is_array($rows)?array_values(array_filter($rows,'is_array')):[];
}
function tt_inv_norm(string $value): string { return strtolower((string)preg_replace('/[^a-z0-9]+/i','',trim($value))); }
function tt_inv_entity(array $row): string { return strtoupper(trim((string)($row['entity']??'TTI'))); }
function tt_inv_location(array $row): string {
    return tt_inv_norm((string)($row['millName']??$row['locationName']??$row['mill']??$row['stockLocation']??'TTI Rice Mills'));
}
function tt_inv_same_scope(array $a,array $b): bool {
    return tt_inv_entity($a)===tt_inv_entity($b)&&tt_inv_location($a)===tt_inv_location($b);
}
function tt_inv_scope(array $row): array {
    return ['entity'=>tt_inv_entity($row),'millId'=>$row['millId']??'','locationId'=>$row['locationId']??'',
        'millName'=>(string)($row['millName']??$row['locationName']??$row['mill']??'TTI Rice Mills')];
}
function tt_inv_has_action(array $user,string $module,array $icons,string $action='View'): bool {
    if (($user['role']??'')==='Super Admin') return true;
    $p=$user['permissions'][$module]??[];
    if ($p==='all') return true;
    if (!is_array($p)) return false;
    $allowed=$action==='View'?['View','Create','Edit','Approve']:[$action];
    foreach ($allowed as $a) if (in_array($a,$p,true)) return true;
    foreach ($icons as $icon) foreach ($allowed as $a) if (in_array($a,(array)($p[$icon]??[]),true)) return true;
    return false;
}
function tt_inv_can_report(array $user,string $entity=''): bool {
    if (($user['role']??'')==='Super Admin') return true;
    if (tt_inv_has_action($user,'Directors',['reports','stock-reconciliation','ghati'])) return true;
    return tt_inv_has_action($user,'Accounts',['reports','stock-reconciliation']) &&
        ($entity!=='' ? tt_user_can_access_entity($user,$entity,'View') : count(tt_user_accounts_entities($user))>0);
}
function tt_inv_is_private_row(array $row): bool {
    return !empty($row['managedReconciliation'])||!empty($row['managementOnly'])||
        (!empty($row['systemFixed'])&&!empty($row['noStockPost']));
}
function tt_inv_public_adjustment(array $row): array {
    $keys=['id','entity','millId','millName','locationId','locationName','brand','stockName','rawStockName',
        'readyRiceKg','rawRiceKg','arrivalKg','byProducts','managedReconciliation','date','time','ref'];
    $out=array_intersect_key($row,array_flip($keys));
    $out['type']='Physical stock confirmation';
    return $out;
}
/** Never return management stores through the operational shared-state route. */
function tt_inv_public_values(array $values,array $meta=[]): array {
    foreach(TT_INV_PRIVATE_KEYS as $key){unset($values[$key],$meta[$key]);}
    if(isset($values['tt30prod'])){
        $rows=tt_inv_rows($values,'tt30prod');
        foreach($rows as &$r){$r['rows']=array_values(array_filter((array)($r['rows']??[]),static fn($x)=>!is_array($x)||!tt_inv_is_private_row($x)));}
        unset($r);$values['tt30prod']=tt_inv_json($rows);
    }
    if(isset($values['tt30prodaudit'])){
        $rows=tt_inv_rows($values,'tt30prodaudit');
        foreach($rows as&$r)foreach(['before','after']as$side)if(isset($r[$side]['rows']))$r[$side]['rows']=array_values(array_filter((array)$r[$side]['rows'],static fn($x)=>!is_array($x)||!tt_inv_is_private_row($x)));
        unset($r);$values['tt30prodaudit']=tt_inv_json($rows);
    }
    if(isset($values['tt32stockadj'])){
        $rows=tt_inv_rows($values,'tt32stockadj');
        foreach($rows as &$r){if(tt_inv_is_private_row($r)||str_contains((string)($r['type']??''),'Physical Nil'))$r=tt_inv_public_adjustment($r);}
        unset($r);$values['tt32stockadj']=tt_inv_json($rows);
    }
    if(isset($values[TT_INV_CONFIRMATIONS])){
        $values[TT_INV_CONFIRMATIONS]=tt_inv_json(array_map(static fn($r)=>array_intersect_key($r,array_flip(
            ['id','entity','millId','millName','locationId','stockName','physicalKg','date','shift','shiftDate','status','createdAt','sourceShipmentId']
        )),tt_inv_rows($values,TT_INV_CONFIRMATIONS)));
    }
    return ['values'=>$values,'meta'=>$meta,'restrictedKeys'=>TT_INV_PRIVATE_KEYS];
}
function tt_inv_date(string $value): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$e=DateTimeImmutable::getLastErrors();
    if(!$d||($e!==false&&($e['warning_count']||$e['error_count']))||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('A valid calendar date is required.');
    return $value;
}
function tt_inv_kg(mixed $value,bool $zero=true): float {
    if(!(is_int($value)||is_float($value)||(is_string($value)&&trim($value)!==''&&is_numeric($value))))throw new InvalidArgumentException('A numeric weight is required.');
    $n=(float)$value;if(!is_finite($n)||$n<0||(!$zero&&$n===0.0))throw new InvalidArgumentException('A valid non-negative weight is required.');
    return round($n,3);
}
function tt_inv_shift_name(string $value): string {
    return match(strtolower(trim($value))){'day','morning'=>'Day','night'=>'Night',default=>''};
}
function tt_inv_shift_at(string $now): array {
    $d=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'));
    $hour=(int)$d->format('G');$shift=$hour>=6&&$hour<18?'Day':($hour>=20||$hour<6?'Night':'');
    return ['date'=>($hour<6?$d->modify('-1 day'):$d)->format('Y-m-d'),'shift'=>$shift];
}
function tt_inv_product(array $row,string $stage='FINISHED'): string {
    return tt_product_display('RICE',(string)($row['baseVariety']??$row['variety']??$row['displayName']??''),$stage,(string)($row['riceType']??''));
}
function tt_inv_output_name(array $production,array $row): string {
    $name=(string)($row['product']??'Unknown');
    if(preg_match('/^READY RICE\s*[—-]\s*/iu',$name)){
        $brand=trim((string)preg_replace('/^READY RICE\s*[—-]\s*/iu','',$name));
        return (string)($row['displayName']??tt_inv_product($production)).($brand!==''?' — '.$brand:'');
    }
    return $name;
}
function tt_inv_output_kg(array $production,string $stockName): float {
    $kg=0.;foreach((array)($production['rows']??[])as$r){
        if(!is_array($r)||tt_inv_is_private_row($r)||!empty($r['noStockPost']))continue;
        if(tt_inv_norm(tt_inv_output_name($production,$r))===tt_inv_norm($stockName))$kg+=(float)($r['bags']??0)*(float)($r['bagWeight']??0)+(float)($r['loadingAdjustmentKg']??0);
    }return round($kg,3);
}
function tt_inv_resolve_stock(array $map,array $shipment): string {
    $explicit=trim((string)($shipment['stockSourceName']??''));if($explicit!=='')return $explicit;
    $brand=(string)($shipment['brand']??'');$finished=tt_inv_product($shipment).($brand!==''?' — '.$brand:'');
    if(array_key_exists($finished,$map))return $finished;
    $ready=tt_inv_product($shipment,'READY');
    if(array_key_exists($ready,$map))return $ready;
    $matches=array_keys(array_filter($map,static fn($kg,$name)=>$brand!==''&&str_ends_with(strtoupper($name),' — '.strtoupper($brand)),ARRAY_FILTER_USE_BOTH));
    if(empty($shipment['baseVariety'])&&empty($shipment['riceType'])&&count($matches)===1)return $matches[0];
    return $finished;
}
/** Same quantity ledger as Milling; management-only rows never become production. */
function tt_inv_stock(array $values,array $scope): array {
    $m=[];$add=static function(string $n,float $kg)use(&$m):void{$m[$n]=round(($m[$n]??0)+$kg,3);};
    foreach(tt_inv_rows($values,'tt30slips')as$r)if(tt_inv_same_scope($scope,$r))$add((string)($r['displayName']??tt_inv_product($r,(string)($r['productStage']??'RAW'))),(float)($r['payableWeight']??0));
    foreach(tt_inv_rows($values,'tt30prod')as$p){if(!tt_inv_same_scope($scope,$p))continue;$input=0.;
        foreach((array)($p['rows']??[])as$r){if(!is_array($r)||tt_inv_is_private_row($r)||!empty($r['noStockPost']))continue;$kg=(float)($r['bags']??0)*(float)($r['bagWeight']??0)+(float)($r['loadingAdjustmentKg']??0);$add(tt_inv_output_name($p,$r),$kg);$input+=$kg;}
        $add((string)($p['inputStockName']??tt_inv_product($p,(string)($p['inputStage']??'RAW'))),-$input);
    }
    foreach(tt_inv_rows($values,'tt35localsales')as$r)if(tt_inv_same_scope($scope,$r)){
        $kg=array_sum(array_map(static fn($x)=>(float)($x['kg']??0),(array)($r['loads']??[])));
        $name=($r['product']??'')==='Ready Rice'?tt_inv_resolve_stock($m,$r+['brand'=>'Local Bags']):(string)($r['product']??'Unknown');$add($name,-$kg);
    }
    foreach(tt_inv_rows($values,'tt35exportersale')as$r)if(tt_inv_same_scope($scope,$r)){
        $kg=array_sum(array_map(static fn($x)=>(float)($x['net']??0),(array)($r['containers']??[])));
        $add(($r['product']??'')==='Ready Rice'?tt_inv_resolve_stock($m,$r):(string)($r['brand']??$r['product']??'Unknown'),-$kg);
    }
    foreach(tt_inv_rows($values,'tt30ship')as$r)if(tt_inv_same_scope($scope,$r)){
        $kg=array_sum(array_map(static fn($x)=>(float)($x['weight']??0),(array)($r['containers']??[])));$add(tt_inv_resolve_stock($m,$r),-$kg);
    }
    foreach(tt_inv_rows($values,'tt32stockadj')as$r){if(!tt_inv_same_scope($scope,$r))continue;
        if(($r['type']??'')==='Paltai Transfer'){$add((string)$r['from'],-(float)($r['sourceDeductionKg']??$r['weightKg']??0));$add((string)$r['to'],(float)($r['weightKg']??0));$add((string)($r['rawStockName']??'RAW RICE'),(float)($r['sweepingKg']??0));continue;}
        if(!empty($r['readyRiceKg']))$add((string)($r['stockName']??$r['brand']??'Unknown'),(float)$r['readyRiceKg']);
        if(!empty($r['rawRiceKg']))$add((string)($r['rawStockName']??'RAW RICE'),(float)$r['rawRiceKg']);
        if(!empty($r['arrivalKg']))$add((string)($r['rawStockName']??'RAW RICE'),(float)$r['arrivalKg']);
        foreach((array)($r['byProducts']??[])as$n=>$kg)$add((string)$n,(float)$kg);
    }return $m;
}
function tt_inv_shift_reports(array $values,array $confirmation): array {
    return array_values(array_filter(tt_inv_rows($values,'tt30prod'),static fn($p)=>tt_inv_same_scope($p,$confirmation)&&
        ($p['date']??'')===($confirmation['shiftDate']??'')&&tt_inv_shift_name((string)($p['shift']??''))===($confirmation['shift']??'')&&!empty($confirmation['shift'])));
}
function tt_inv_quantities(array $reports,string $stockName): array {
    $out=[];foreach($reports as$p)$out[(string)($p['id']??'')]=tt_inv_output_kg($p,$stockName);ksort($out);return $out;
}
/** Preserve server-only portions when a filtered browser saves its operational projection. */
function tt_inv_prepare_write(array $values,string $key,string $json,array $user,string $now): string {
    if(in_array($key,TT_INV_PRIVATE_KEYS,true))throw new DomainException('Reconciliation records are server-owned. Use Accounts or Directors reports.');
    if(!in_array($key,['tt30prod','tt30ship','tt32stockadj',TT_INV_CONFIRMATIONS],true))return $json;
    $incoming=json_decode($json,true);if(!is_array($incoming)||!array_is_list($incoming))throw new InvalidArgumentException('Operational rows must be an array.');
    $old=tt_inv_rows($values,$key);$index=[];$sequence=0;foreach($old as$r){$index[(string)($r['id']??'')]=$r;$sequence=max($sequence,(int)($r['serverSequence']??0));}
    if($key==='tt30prod'&&!tt_inv_has_action($user,'Mill',['production'],'Create')&&!tt_inv_has_action($user,'Mill',['production'],'Edit'))throw new DomainException('Production entry permission is required.');
    $seen=[];$out=[];
    foreach($incoming as$r){if(!is_array($r))throw new InvalidArgumentException('Invalid operational row.');$id=(string)($r['id']??'');
        if($id===''||isset($seen[$id]))throw new InvalidArgumentException('Each operational row needs a unique stable identity.');$seen[$id]=true;$before=$index[$id]??null;
        if($key===TT_INV_CONFIRMATIONS){
            if($before){$out[]=$before;continue;}
            $scope=tt_inv_scope($r);if(!in_array($scope['entity'],['TTI','BRM'],true)||trim((string)($r['millName']??''))==='')throw new InvalidArgumentException('Stock company and mill are required.');
            $name=trim((string)($r['stockName']??''));$stocks=tt_inv_stock($values,$scope);
            if($name===''||!array_key_exists($name,$stocks))throw new InvalidArgumentException('Select the exact saved stock product. Refresh the stock position.');
            $shift=tt_inv_shift_at($now);
            $lastLoading=null;
            foreach(tt_inv_rows($values,'tt30ship')as$shipment){
                if(!tt_inv_same_scope($scope,$shipment)||tt_inv_resolve_stock($stocks,$shipment)!==$name)continue;
                if(!empty($r['sourceShipmentId'])&&(string)($shipment['id']??'')!==(string)$r['sourceShipmentId'])continue;
                foreach((array)($shipment['containers']??[])as$container)if((float)($container['weight']??0)>0&&!empty($container['loadingAt'])&&($lastLoading===null||$container['loadingAt']>$lastLoading['loadingAt']))$lastLoading=$container;
            }
            if($lastLoading&&!empty($lastLoading['productionShift']))$shift=['date'=>$lastLoading['productionShiftDate'],'shift'=>$lastLoading['productionShift']];
            $desired=tt_inv_kg($r['physicalKg']??0);$raw=(bool)preg_match('/\bRAW RICE$/i',$name);
            if($raw&&abs($stocks[$name])>=50000)throw new InvalidArgumentException('Raw Rice can be confirmed physically NIL only below 50 MT.');
            foreach($old as$other)if(tt_inv_same_scope($other,$scope)&&($other['stockName']??'')===$name&&($other['status']??'')==='Awaiting current-shift production')throw new InvalidArgumentException('This physical confirmation is already waiting for production.');
            $r=$scope+['id'=>$id,'stockName'=>$name,'physicalKg'=>$desired,'date'=>(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d'),'shiftDate'=>$shift['date'],'shift'=>$shift['shift'],
                'snapshotKg'=>$stocks[$name],'isRaw'=>$raw,'createdAt'=>$now,'createdBy'=>(string)($user['username']??''),'status'=>$raw?'Pending':'Awaiting current-shift production',
                'sourceShipmentId'=>(string)($r['sourceShipmentId']??'')];
            $r['baseline']=tt_inv_quantities(tt_inv_shift_reports($values,$r),$name);
        }elseif($key==='tt30ship'){
            $containers=[];foreach((array)($before['containers']??[])as$container)$containers[(string)($container['id']??'')]=$container;
            foreach((array)($r['containers']??[])as$i=>$container){
                $prior=$containers[(string)($container['id']??'')]??[];
                if((float)($container['weight']??0)>0){
                    if(isset($prior['loadingAt'])){foreach(['loadingAt','productionShiftDate','productionShift']as$field)$container[$field]=$prior[$field]??'';}
                    else{$shift=tt_inv_shift_at($now);$container['loadingAt']=$now;$container['productionShiftDate']=$shift['date'];$container['productionShift']=$shift['shift'];}
                }
                $r['containers'][$i]=$container;
            }
        }elseif($key==='tt32stockadj'){
            if((tt_inv_is_private_row($r)||str_contains((string)($r['type']??''),'Physical Nil'))&&!$before)throw new DomainException('A client cannot create a management stock adjustment.');
            if($before&&(tt_inv_is_private_row($before)||str_contains((string)($before['type']??''),'Physical Nil'))){
                if(tt_inv_public_adjustment($before)!==tt_inv_public_adjustment($r))throw new DomainException('Reconciled stock adjustments cannot be changed from Milling.');
                $r=$before;
            }
        }elseif($key==='tt30prod'){
            $hidden=array_values(array_filter((array)($before['rows']??[]),static fn($x)=>is_array($x)&&tt_inv_is_private_row($x)));
            foreach((array)($r['rows']??[])as$x)if(is_array($x)&&tt_inv_is_private_row($x))throw new DomainException('Reconciliation rows cannot be entered as production.');
            $r['rows']=array_merge((array)($r['rows']??[]),$hidden);
            $r['createdAt']=$before['createdAt']??$now;
            $r['serverSequence']=$before['serverSequence']??++$sequence;
            $compare=$r;$prior=$before;unset($compare['createdAt'],$compare['updatedAt'],$compare['serverSequence']);if($prior)unset($prior['createdAt'],$prior['updatedAt'],$prior['serverSequence']);
            $r['updatedAt']=$compare===$prior?($before['updatedAt']??$now):$now;
            if(array_key_exists('shiftEntriesComplete',$r)){$r['shiftEntriesComplete']=$r['shiftEntriesComplete']===true;$r['date']=tt_inv_date((string)($r['date']??''));if(tt_inv_shift_name((string)($r['shift']??''))==='')throw new InvalidArgumentException('Production shift is required.');}
        }
        $out[]=$r;
    }
    // Filtered data must never delete private historical adjustments or confirmations.
    if($key===TT_INV_CONFIRMATIONS||$key==='tt32stockadj')foreach($old as$r)if(!isset($seen[(string)($r['id']??'')])&&($key===TT_INV_CONFIRMATIONS||tt_inv_is_private_row($r)||str_contains((string)($r['type']??''),'Physical Nil')))$out[]=$r;
    if($key==='tt30prod'){
        // Fixed management rows cannot be lost by deleting their linked production report.
        foreach($old as$p)if(!isset($seen[(string)$p['id']])){
            $hidden=array_filter((array)($p['rows']??[]),static fn($r)=>is_array($r)&&tt_inv_is_private_row($r));
            $attached=array_filter(tt_inv_rows($values,'tt34nilqueue'),static fn($r)=>(string)($r['productionId']??'')===(string)$p['id']);
            if($hidden||$attached)throw new DomainException('This report has a fixed reconciliation reference. Accounts/Directors review is required before reversal.');
        }
        // A final-entry tick belongs to a complete shift, not to one partial lot.
        $changed=[];foreach($out as$r){$b=$index[(string)$r['id']]??null;$a=$r;unset($a['createdAt'],$a['updatedAt'],$a['serverSequence']);if($b){unset($b['createdAt'],$b['updatedAt'],$b['serverSequence']);}if($a!==$b)$changed[]=$r;}
        foreach($changed as$r)foreach($out as&$other)if((string)$other['id']!==(string)$r['id']&&tt_inv_same_scope($r,$other)&&($r['date']??'')===($other['date']??'')&&tt_inv_shift_name((string)($r['shift']??''))===tt_inv_shift_name((string)($other['shift']??'')))$other['shiftEntriesComplete']=false;unset($other);
    }
    return tt_inv_json($out);
}
/** Pure transform, called inside the same lock/transaction as the source save. */
function tt_inv_reconcile(array $values,string $now): array {
    $confirmations=tt_inv_rows($values,TT_INV_CONFIRMATIONS);$adjustments=tt_inv_rows($values,'tt32stockadj');
    $events=tt_inv_rows($values,'tt34ghati');$queue=tt_inv_rows($values,'tt34nilqueue');$productions=tt_inv_rows($values,'tt30prod');
    $eventsByRef=[];foreach($events as$e)$eventsByRef[(string)($e['ref']??'')]=true;
    foreach($confirmations as&$c){
        $reports=tt_inv_shift_reports($values,$c);$quantities=tt_inv_quantities($reports,(string)$c['stockName']);
        $completed=(bool)array_filter($reports,static fn($p)=>!empty($p['shiftEntriesComplete']));
        if(($c['status']??'')==='Resolved'){
            if(empty($c['isRaw'])&&tt_inv_json($c['resolvedQuantities']??[])!==tt_inv_json($quantities))$c['reviewRequired']='Production quantities changed after reconciliation; Accounts/Directors review required.';
            continue;
        }
        if(empty($c['isRaw'])&&!$completed)continue;
        $delta=empty($c['isRaw'])?array_sum($quantities)-array_sum((array)($c['baseline']??[])):0;
        $balance=round((float)$c['snapshotKg']+$delta,3);$adjust=round((float)$c['physicalKg']-$balance,3);
        $c['resolvedAt']=$now;$c['resolvedQuantities']=$quantities;$c['adjustmentKg']=$adjust;$c['status']='Resolved';
        if(abs($adjust)<.0005)continue; // Current shift explains it: no gain, no loss, no adjustment.
        $ref='STOCK-CONFIRMATION|'.$c['id'];$scope=tt_inv_scope($c);$rawName=tt_product_identity('RICE',explode(' — ',(string)$c['stockName'])[0],'RAW')['displayName'];
        $adjustments[]=$scope+['id'=>'RECON-'.$c['id'],'ref'=>$ref,'date'=>$c['date'],'time'=>$now,'stockName'=>$c['stockName'],'brand'=>$c['stockName'],
            'rawStockName'=>!empty($c['isRaw'])?$c['stockName']:$rawName,'rawRiceKg'=>!empty($c['isRaw'])?$adjust:max(0,-$adjust),
            'readyRiceKg'=>!empty($c['isRaw'])?0:$adjust,'type'=>'Physical stock confirmation','managedReconciliation'=>true];
        if(!isset($eventsByRef[$ref])){$events[]=$scope+['id'=>'GHATI-'.$c['id'],'ref'=>$ref,'date'=>$c['date'],'time'=>$now,'kind'=>$adjust>0?'gain':'shortage','kg'=>abs($adjust),
            'product'=>$c['stockName'],'narration'=>$adjust>0?'Last loading excess after current-shift production':'Physical NIL stock shortage',
            'managementOnly'=>true];$eventsByRef[$ref]=true;}
        if($adjust>0&&empty($c['isRaw']))$queue[]=$scope+['id'=>'ROW-'.$c['id'],'ref'=>$ref,'date'=>$c['date'],'reconciledAt'=>$now,'existingProductionIds'=>array_map(static fn($p)=>(string)$p['id'],$productions),'product'=>$c['stockName'],'kg'=>$adjust,
            'systemFixed'=>true,'noStockPost'=>true,'managementOnly'=>true,'status'=>'Pending Production Report','narration'=>'Last loading excess — fixed reconciliation row; no stock, raw consumption or journal posting.'];
    }unset($c);
    foreach($queue as&$row){if(empty($row['managementOnly'])||($row['status']??'')!=='Pending Production Report')continue;
        $next=array_values(array_filter($productions,static fn($p)=>tt_inv_same_scope($p,$row)&&
            (isset($row['existingProductionIds'])?!in_array((string)$p['id'],$row['existingProductionIds'],true):isset($p['createdAt'])&&$p['createdAt']>(string)($row['reconciledAt']??''))));
        usort($next,static fn($a,$b)=>(int)($a['serverSequence']??0)<=>(int)($b['serverSequence']??0)?:strcmp((string)($a['createdAt']??''),(string)($b['createdAt']??''))?:strcmp((string)$a['id'],(string)$b['id']));
        if($next){$row['productionId']=$next[0]['id'];$row['appliedDate']=$next[0]['date'];$row['appliedShift']=$next[0]['shift'];$row['status']='Applied to Production';}
    }unset($row);
    foreach([TT_INV_CONFIRMATIONS=>$confirmations,'tt32stockadj'=>$adjustments,'tt34ghati'=>$events,'tt34nilqueue'=>$queue]as$key=>$rows){if(isset($values[$key])||$rows)$values[$key]=tt_inv_json($rows);}
    return $values;
}
function tt_inv_statement(array $values,string $entity): array {
    $events=[];$ghati=0.;$gain=0.;
    foreach(tt_inv_rows($values,'tt34ghati')as$e){if(tt_inv_entity($e)!==$entity)continue;$kg=max(0,(float)($e['kg']??0));
        if(($e['kind']??'')==='gain'){$offset=min($ghati,$kg);$ghati-=$offset;$gain+=$kg-$offset;}
        elseif(($e['kind']??'')==='shortage'){$offset=min($gain,$kg);$gain-=$offset;$ghati+=$kg-$offset;}else continue;
        $e['ghatiAfter']=round($ghati,3);$e['gainAfter']=round($gain,3);$events[]=$e;
    }
    return ['entity'=>$entity,'ghatiKg'=>round($ghati,3),'carryForwardGainKg'=>round($gain,3),'events'=>$events,
        'confirmations'=>array_values(array_filter(tt_inv_rows($values,TT_INV_CONFIRMATIONS),static fn($r)=>tt_inv_entity($r)===$entity)),
        'fixedProductionRows'=>array_values(array_filter(tt_inv_rows($values,'tt34nilqueue'),static fn($r)=>tt_inv_entity($r)===$entity)),
        'informationalOnly'=>true];
}

function tt_inv_read_values(): array {
    $env=static function(string $key):string{$name='TT_DB_'.$key;return defined($name)?(string)constant($name):(string)(getenv($name)?:'');};
    if($env('HOST')!==''&&$env('NAME')!==''&&$env('USER')!==''){
        $db=new PDO('mysql:host='.$env('HOST').';dbname='.$env('NAME').';charset=utf8mb4',$env('USER'),$env('PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $values=[];foreach($db->query('SELECT storage_key,payload FROM tt_operation_records')->fetchAll()as$row)$values[(string)$row['storage_key']]=(string)$row['payload'];return $values;
    }
    $path=TT_DATA_DIR.'/operations.json';if(!is_file($path))return [];
    $h=fopen($path,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Operational storage is unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $store=json_decode($raw?:'{}',true,512,JSON_THROW_ON_ERROR);return (array)($store['values']??[]);
}
function tt_inv_rate(array $values,string $entity,string $asOf): ?float {
    $end=new DateTimeImmutable(tt_inv_date($asOf));$cut=$end->modify('-9 days')->format('Y-m-d');$qty=0.;$value=0.;
    foreach(tt_inv_rows($values,'tt30slips')as$r){
        if(tt_inv_entity($r)!==$entity||($r['productStage']??'RAW')!=='RAW')continue;
        $date=(string)($r['unloadingDate']??$r['arrivalDate']??'');$kg=(float)($r['payableWeight']??0);$rate=(float)($r['purchaseRate']??0);
        if($date>=$cut&&$date<=$asOf&&$kg>0&&$rate>0){$qty+=$kg;$value+=$kg*$rate;}
    }return $qty>0?round($value/$qty,6):null;
}
