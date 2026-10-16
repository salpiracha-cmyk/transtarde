<?php
declare(strict_types=1);

function mr_decode_store(string $raw,array $default=[]):array {
    if($raw==='')return $default;$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('Invalid source store.');return $v;
}
function mr_valid_date(string $date):bool {
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m))return false;
    return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
}
function mr_fingerprint(array $runs):string {
    $facts=[];foreach($runs as $run){$input=mr_input_rows($run);$output=mr_output_rows($run);usort($input,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));usort($output,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$facts[]=['id'=>(string)($run['id']??''),'entity'=>$run['entity']??'TTI','date'=>$run['date']??'','shift'=>$run['shift']??'','variety'=>$run['sourceVariety']??$run['variety']??'','inputStock'=>$run['inputStockName']??'','input'=>$input,'output'=>$output];}
    usort($facts,static fn($a,$b)=>strcmp($a['id'],$b['id']));return hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR));
}
function mr_number(mixed $v):float {return is_numeric($v)&&is_finite((float)$v)?(float)$v:0.0;}
function mr_operational(array $r):bool {return empty($r['managedReconciliation'])&&empty($r['managementOnly'])&&empty($r['noStockPost'])&&!( !empty($r['systemFixed'])&&!empty($r['noStockPost']));}
function mr_input_rows(array $run):array {
    $out=[];foreach((array)($run['rawInputs']??[]) as $r){if(!is_array($r))continue;$gross=mr_number($r['grossKg']??0);$less=mr_number($r['lessKg']??0);if($gross<=0||$less<0||$less>$gross)continue;$out[]=['lotRef'=>(string)($r['lotRef']??''),'product'=>(string)($r['product']??$run['variety']??''),'bags'=>mr_number($r['bags']??0),'grossKg'=>$gross,'lessKg'=>$less,'kg'=>round($gross-$less,3),'brand'=>(string)($r['brand']??$run['recoveryBrand']??''),'contractRef'=>(string)($r['contractRef']??$run['recoveryContractRef']??'')];}
    if(!$out&&mr_number($run['actualInputKg']??0)>0)$out[]=['lotRef'=>(string)($run['rawLotRef']??''),'product'=>(string)($run['variety']??''),'bags'=>0,'grossKg'=>mr_number($run['actualInputKg']),'lessKg'=>0,'kg'=>mr_number($run['actualInputKg']),'brand'=>(string)($run['recoveryBrand']??''),'contractRef'=>(string)($run['recoveryContractRef']??'')];
    return $out;
}
function mr_actual_input(array $run):?float {$rows=mr_input_rows($run);return $rows?round(array_sum(array_column($rows,'kg')),3):null;}
function mr_output_kg(array $r):float {
    $gross=mr_number($r['systemKg']??0);if($gross<=0)$gross=mr_number($r['bags']??0)*mr_number($r['bagWeight']??0)+mr_number($r['loadingAdjustmentKg']??0);
    return round($gross-mr_number($r['lessKg']??0),3);
}
function mr_output_rows(array $run):array {
    $out=[];foreach((array)($run['rows']??[]) as $r){if(!is_array($r)||!mr_operational($r))continue;$gross=mr_number($r['systemKg']??0);if($gross<=0)$gross=mr_number($r['bags']??0)*mr_number($r['bagWeight']??0)+mr_number($r['loadingAdjustmentKg']??0);$less=mr_number($r['lessKg']??0);$kg=mr_output_kg($r);if($kg<=0)continue;$name=(string)($r['product']??'Unknown');$finished=str_starts_with(strtoupper($name),'READY RICE');$context=(array)($run['recoveryOutputContexts'][(string)($r['id']??'')]??[]);$brand=$finished?(preg_match('/^Ready Rice\s*[—-]\s*(.+)$/iu',$name,$match)?trim($match[1]):(string)($run['recoveryBrand']??'')):(string)($context['brand']??$r['brand']??$run['recoveryBrand']??'');$contract=(string)($context['contractRef']??$r['contractRef']??$run['recoveryContractRef']??'');$out[]=['product'=>$name,'bags'=>mr_number($r['bags']??0),'grossKg'=>$gross,'lessKg'=>$less,'kg'=>$kg,'kind'=>$finished?'finished':'byproduct','brand'=>$brand,'contractRef'=>$contract,'lotRef'=>(string)($r['lotRef']??$run['outputLotRef']??'')];}return $out;
}
function mr_validate_inputs(array $run,bool $required=false):void {
    $required=$required||!empty($run['recoveryVersion']);
    $inputs=$run['rawInputs']??[];if(!is_array($inputs)||!array_is_list($inputs))throw new DomainException('Raw inputs must be a list of actual lots.');
    foreach($inputs as $r){
        if(!is_array($r))throw new DomainException('Invalid raw lot.');
        foreach(['grossKg','lessKg','bags'] as $field){$v=$r[$field]??0;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new DomainException('Raw lot quantities must be valid non-negative numbers.');}
        if((float)$r['grossKg']<=0||(float)($r['lessKg']??0)>=(float)$r['grossKg'])throw new DomainException('Each raw input needs actual gross kilograms and a smaller deduction.');
    }
    if($required)foreach((array)($run['rows']??[]) as $row){if(!is_array($row)||!mr_operational($row))continue;foreach(['bags','bagWeight','lessKg','systemKg'] as $field)if(isset($row[$field])&&(!is_numeric($row[$field])||!is_finite((float)$row[$field])||(float)$row[$field]<0))throw new DomainException('Production output quantities must be non-negative numbers.');if(isset($row['loadingAdjustmentKg'])&&(!is_numeric($row['loadingAdjustmentKg'])||!is_finite((float)$row['loadingAdjustmentKg'])))throw new DomainException('Output adjustment must be a valid number.');if(mr_output_kg($row)<0)throw new DomainException('Output deductions cannot exceed its recorded weight.');}
    $outputs=mr_output_rows($run);$actual=mr_actual_input($run);
    if($required&&$outputs&&!($actual>0))throw new DomainException('Actual raw input kilograms are required before saving production.');
    if($required&&array_sum(array_column($outputs,'kg'))>($actual??0)+.001&&strlen(trim((string)($run['recoveryVarianceReason']??'')))<3)throw new DomainException('Explain why recorded output exceeds the actual input weight.');
}
function mr_report(array $runs,array $filters=[],array $accounts=[]):array {
    $entity=(string)($filters['entity']??'TTI');$mode=(string)($filters['mode']??'daily');$groups=[];$brands=[];$contracts=[];$missing=[];$varieties=[];$mills=[];
    foreach($runs as $run){if(!is_array($run)||strtoupper((string)($run['entity']??'TTI'))!==$entity)continue;$date=(string)($run['date']??'');if(!mr_valid_date($date))continue;$inputs=mr_input_rows($run);$outputs=mr_output_rows($run);if(!$inputs&&!$outputs)continue;$varieties[(string)($run['sourceVariety']??$run['variety']??'')]=true;$mills[(string)($run['millName']??$run['locationName']??'TTI Rice Mills')]=true;foreach(array_merge($inputs,$outputs) as $r){if($r['brand']!=='')$brands[$r['brand']]=true;if($r['contractRef']!=='')$contracts[$r['contractRef']]=true;}
        if((!empty($filters['from'])&&$date<$filters['from'])||(!empty($filters['to'])&&$date>$filters['to'])||(!empty($filters['shift'])&&($run['shift']??'')!==$filters['shift'])||(!empty($filters['variety'])&&($run['sourceVariety']??$run['variety']??'')!==$filters['variety'])||(!empty($filters['mill'])&&($run['millName']??$run['locationName']??'TTI Rice Mills')!==$filters['mill']))continue;
        $id=(string)($run['id']??$date.'|'.($run['shift']??''));
        foreach(['raw'=>$inputs,'output'=>$outputs] as $kind=>$rows)foreach($rows as $row){if((!empty($filters['brand'])&&$row['brand']!==$filters['brand'])||(!empty($filters['contract'])&&$row['contractRef']!==$filters['contract']))continue;$key=$mode==='brand'?($row['brand']?:'Unassigned brand'):($mode==='contract'?($row['contractRef']?:'Unassigned contract'):$date);if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'inputKg'=>0.0,'readyKg'=>0.0,'byProductKg'=>0.0,'outputKg'=>0.0,'inputComplete'=>true,'raw'=>[],'finished'=>[],'byproducts'=>[],'runs'=>[],'costRates'=>[],'inputByRun'=>[],'outputByRun'=>[]];$g=&$groups[$key];$g['runs'][$id]=['id'=>$id,'date'=>$date,'shift'=>(string)($run['shift']??''),'variety'=>(string)($run['sourceVariety']??$run['variety']??''),'mill'=>(string)($run['millName']??$run['locationName']??'TTI Rice Mills'),'varianceReason'=>(string)($run['recoveryVarianceReason']??'')];if(!$inputs){$g['inputComplete']=false;$missing[$id]=true;}$row+=['date'=>$date,'shift'=>(string)($run['shift']??''),'sourceRunId'=>$id];if($kind==='raw'){$g['inputKg']+=$row['kg'];$g['inputByRun'][$id]=($g['inputByRun'][$id]??0)+$row['kg'];$g['raw'][]=$row;}else{$g['outputKg']+=$row['kg'];$g['outputByRun'][$id]=true;if($row['kind']==='finished'){$g['readyKg']+=$row['kg'];$g['finished'][]=$row;}else{$g['byProductKg']+=$row['kg'];$g['byproducts'][]=$row;}}unset($g);}
    }
    if(!empty($filters['search'])){$needle=strtolower((string)$filters['search']);$groups=array_filter($groups,static fn($g)=>str_contains(strtolower(json_encode([$g['key'],$g['raw'],$g['finished'],$g['byproducts'],$g['runs']],JSON_UNESCAPED_UNICODE)),$needle));$visibleRuns=[];foreach($groups as $g)foreach($g['runs'] as $id=>$unused)$visibleRuns[$id]=true;$missing=array_intersect_key($missing,$visibleRuns);}ksort($groups);$totals=['inputKg'=>0.,'readyKg'=>0.,'byProductKg'=>0.,'outputKg'=>0.,'inputComplete'=>true];
    foreach($groups as &$g){foreach(['inputKg','readyKg','byProductKg','outputKg'] as $k){$g[$k]=round($g[$k],3);$totals[$k]+=$g[$k];}foreach($g['outputByRun'] as $runId=>$unused)if(($g['inputByRun'][$runId]??0)<=0)$g['inputComplete']=false;unset($g['inputByRun'],$g['outputByRun']);if($g['inputKg']<=0&&$g['outputKg']>0)$g['inputComplete']=false;$g['inputComplete']=$g['inputComplete']&&$g['raw']!==[];$totals['inputComplete']=$totals['inputComplete']&&$g['inputComplete'];$g['recoveryPct']=$g['inputComplete']&&$g['inputKg']>0?round($g['outputKg']/$g['inputKg']*100,4):null;$g['readyRecoveryPct']=$g['inputComplete']&&$g['inputKg']>0?round($g['readyKg']/$g['inputKg']*100,4):null;$g['differenceKg']=$g['inputComplete']?round($g['inputKg']-$g['outputKg'],3):null;$g['status']=!$g['inputComplete']?'Actual input / allocation required':($g['differenceKg']<-.001?'Output exceeds input — reconcile':'Actual quantities recorded');if(str_starts_with($g['key'],'Unassigned'))$g['status']='Brand / contract allocation required';foreach(['raw','finished','byproducts'] as $section)foreach($g[$section] as &$row)$row['recoveryPct']=$section!=='raw'&&$g['inputComplete']&&$g['inputKg']>0?round($row['kg']/$g['inputKg']*100,4):null;unset($row);$g['runs']=array_values($g['runs']);}
    unset($g);foreach(['inputKg','readyKg','byProductKg','outputKg'] as $k)$totals[$k]=round($totals[$k],3);$totals['recoveryPct']=$totals['inputComplete']&&$totals['inputKg']>0?round($totals['outputKg']/$totals['inputKg']*100,4):null;$totals['differenceKg']=$totals['inputComplete']?round($totals['inputKg']-$totals['outputKg'],3):null;
    $brandNames=array_keys($brands);$contractNames=array_keys($contracts);sort($brandNames);sort($contractNames);
    return ['ok'=>true,'entity'=>$entity,'mode'=>$mode,'filters'=>$filters,'groups'=>array_values($groups),'totals'=>$totals,'brands'=>$brandNames,'contracts'=>$contractNames,'varieties'=>array_values(array_filter(array_keys($varieties))),'mills'=>array_keys($mills),'missingInputRuns'=>count($missing),'note'=>'Recovery uses independently recorded input and output. Missing historical quantities need the original production records. Shared by-products require actual brand/contract allocation.'];
}

/** Read the same committed backend used by Milling; never fall back to a stale file when SQL is configured. */
function mr_read_operations_source(string $path):array {
    $config=static fn(string $name):string=>(string)(defined('TT_DB_'.$name)?constant('TT_DB_'.$name):(getenv('TT_DB_'.$name)?:''));
    if($config('HOST')!==''&&$config('NAME')!==''&&$config('USER')!==''){
        $db=new PDO('mysql:host='.$config('HOST').';dbname='.$config('NAME').';charset=utf8mb4',$config('USER'),$config('PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $keys=['tt30prod','tt38kebills','tt38labourbills','tt37processingexpenses','tt35localsales','tt35exportersale'];
        $db->beginTransaction();try{$q=$db->prepare('SELECT storage_key,payload,version FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);$values=[];$revision=0;foreach($q->fetchAll() as $r){$values[(string)$r['storage_key']]=(string)$r['payload'];$revision=max($revision,(int)$r['version']);}$db->commit();return ['values'=>$values,'revision'=>$revision,'sourceBackend'=>'mysql'];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    if(!is_file($path))return ['values'=>[],'revision'=>0,'sourceBackend'=>'file'];
    $h=fopen($path,'rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Operational source unavailable.');try{$v=mr_decode_store((string)stream_get_contents($h));if(!is_array($v['values']??null))throw new RuntimeException('Invalid operational values.');$v['sourceBackend']='file';return $v;}finally{flock($h,LOCK_UN);fclose($h);}
}
