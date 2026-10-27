<?php
declare(strict_types=1);
require_once __DIR__.'/operations_scope.php';
function mgp_id(array $row,string $key):string{return (string)($row['id']??($key==='tt35localsales'?($row['soda']??''):($row['ref']??'')));}
function mgp_load_id(array $row,int $index):string{return (string)($row['id']??$row['bridgeId']??$row['gatePassRef']??($row['container']??(($row['c']??'')!=='BREAK BULK'?($row['c']??''):'')?:'legacy-'.$index));}
/** Resolve committed rows only. Browser-supplied parties, weights and titles are never accepted. */
function mgp_document(array $values,array $user,string $key,string $recordId,string $loadId):array {
    $titles=['tt30ship'=>'Export Gate Pass','tt35localsales'=>'Local Sale Gate Pass','tt35exportersale'=>'Local Sale to Exporter Gate Pass'];
    if(!isset($titles[$key]))throw new InvalidArgumentException('Choose a supported gate pass.');
    $icon=$key==='tt30ship'?'export':'local';
    if(!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill',$icon,'Print'))throw new DomainException('Gate pass Print permission is required.');
    $rows=json_decode((string)($values[$key]??'[]'),true,512,JSON_THROW_ON_ERROR);$matches=array_values(array_filter($rows,static fn($r)=>is_array($r)&&mgp_id($r,$key)===$recordId));
    if(count($matches)!==1)throw new DomainException('Reopen the saved loading record.');$r=$matches[0];
    $scopeRow=$r;if(!isset($scopeRow['entity'])&&isset($scopeRow['accountEntity']))$scopeRow['entity']=$scopeRow['accountEntity'];
    if(!os_scope($user,$scopeRow,os_root($values),true,$key==='tt30ship'))throw new DomainException('This loading is outside your company or mill access.');
    $loads=(array)($r[$key==='tt35localsales'?'loads':'containers']??[]);$found=[];foreach($loads as $i=>$load)if(is_array($load)&&mgp_load_id($load,$i)===$loadId)$found[]=$load;
    if(count($found)!==1)throw new DomainException('Reopen the saved loading row.');$c=$found[0];
    $net=(float)($c['weight']??$c['kg']??$c['net']??0);$truck=(string)($c['truck']??$c['t']??'');
    if($net<=0||$truck===''||($key==='tt30ship'&&(isset($c['loadingComplete'])&&!$c['loadingComplete']||empty($c['seal'])||empty($c['container']))))throw new DomainException('Complete and save loading details before printing a gate pass.');
    if($key==='tt35localsales'&&empty($c['printed']))throw new DomainException('Issue the local sale gate pass before printing.');
    $entity=strtoupper((string)($r['entity']??$r['accountEntity']??$r['exporter']??'TTI'));
    $mill=(string)($r['millName']??$r['locationName']??$r['mill']??'TTI Rice Mills');$own=$entity==='TTI'&&in_array(strtolower($mill),['tti rice mills','tti rice mill','trans trade rice mill','transtrade rice mill'],true);
    $contract=os_contract(os_root($values),(string)($r['contractRef']??''))??[];$packing=(string)($r['packing']??$r['bagType']??'');if($packing===''&&!empty($r['bagSize']))$packing=trim($r['bagSize'].' '.($contract['packingUnit']??'kg').' '.($r['packingType']??''));
    return ['identity'=>json_encode([$key,$recordId,$loadId]),'title'=>$titles[$key],'entity'=>$entity,'company'=>$own?'TRANS TRADE RICE MILL':($entity==='BRM'?'BUKSH RICE MILLS':$mill),'address'=>$own?'S-94/1, Estate Avenue, S.I.T.E., Karachi-Pakistan':'','contact'=>$own?'Tel: 92-21-32594858 · Fax: 92-21-32594588':'','issuedFrom'=>$mill,
        'reference'=>(string)($r['soda']??$r['contractRef']??$r['ref']??$recordId),'lot'=>(string)($r['lotRef']??$r['ref']??''),'legacyReference'=>(string)($c['gatePassRef']??$c['gate']??''),'date'=>(string)($c['date']??$r['date']??''),'party'=>(string)($r['party']??$r['buyer']??$r['customer']??($key==='tt30ship'?($entity==='BRM'?'Buksh Rice Mills':'Transtrade International'):'')),'exporter'=>$key==='tt35exportersale'?(string)($r['buyer']??''):($entity==='BRM'?'Buksh Rice Mills':'Transtrade International'),
        'container'=>(string)($c['container']??$c['c']??''),'seal'=>(string)($c['seal']??''),'truck'=>$truck,'driver'=>(string)($c['driver']??''),'phone'=>(string)($c['phone']??''),'product'=>(string)($r['displayName']??$r['product']??$r['variety']??$r['commodity']??''),'brand'=>(string)($r['brand']??''),'packing'=>$packing,'bags'=>(float)($c['bags']??$c['b']??0),'emptyBags'=>(float)($c['empty']??0),'grossKg'=>(float)($c['weighbridgeKg']??$c['g']??$net),'deductionKg'=>(float)($c['packingKg']??$c['tare']??0),'netKg'=>$net,'vehicleTareKg'=>(float)($c['containerTareKg']??0),'remarks'=>(string)($c['remarks']??'')];
}
/** One server-wide sequence, atomic durable replacement, and stable retry/reprint identities. */
function mgp_issue(string $path,array $document,array $user):array {
    $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Gate pass register unavailable.');$temp=null;
    try{
        $store=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['sequence'=>0,'issued'=>[]];
        if(!is_array($store)||!is_int($store['sequence']??null)||!is_array($store['issued']??null))throw new RuntimeException('Invalid gate pass register.');
        $id=hash('sha256',$document['identity']);$old=$store['issued'][$id]??null;
        if(!$old){$serial='GP-'.str_pad((string)++$store['sequence'],6,'0',STR_PAD_LEFT);$old=['serial'=>$serial,'identity'=>$document['identity'],'issuedAt'=>gmdate('c'),'issuedBy'=>(string)($user['username']??$user['id']??''),'initialDocument'=>$document];$store['issued'][$id]=$old;
            $temp=tempnam(dirname($path),'.tmp-gate-register-');if(!$temp)throw new RuntimeException('Gate pass register could not be saved.');$json=json_encode($store,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($temp,$json)!==strlen($json)||!rename($temp,$path))throw new RuntimeException('Gate pass register could not be saved.');$temp=null;
        }
        unset($document['identity']);return $document+['serial'=>$old['serial'],'issuedAt'=>$old['issuedAt']];
    }finally{if($temp&&is_file($temp))unlink($temp);flock($lock,LOCK_UN);fclose($lock);}
}
