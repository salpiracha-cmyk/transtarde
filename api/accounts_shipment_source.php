<?php
declare(strict_types=1);
function tt_accounts_exports_db():?PDO{
    $config=static fn($name)=>(string)(defined('TT_'.$name)?constant('TT_'.$name):(getenv('TT_'.$name)?:''));
    if($config('DB_HOST')!==''&&$config('DB_NAME')!==''&&$config('DB_USER')!==''){
        return new PDO('mysql:host='.$config('DB_HOST').';dbname='.$config('DB_NAME').';charset=utf8mb4',$config('DB_USER'),$config('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    return null;
}
function tt_accounts_exports_root():array{
    $key='transtrade_export_v3_operational';$db=tt_accounts_exports_db();
    if($db){
        $stmt=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LIMIT 1');$stmt->execute([$key]);$raw=$stmt->fetchColumn();
    }else{
        $file=TT_DATA_DIR.'/operations.json';$data=is_file($file)?json_decode((string)file_get_contents($file),true):[];$raw=$data['values'][$key]??'';
    }
    $value=is_string($raw)?json_decode($raw,true):null;return is_array($value)?$value:[];
}
// Narrow, versioned metadata update. Never changes quantities, allocations or invoices.
function tt_accounts_freight_metadata(array $root,array $agreement,array $user):array{
    $original=$root;
    $entity=(string)$agreement['entity'];$id=(string)$agreement['shipmentId'];$ref=(string)$agreement['contractRef'];
    $rows=tt_accounts_shipment_rows($root,$entity);$source=null;
    foreach($rows as $row)if($id!==''&&$row['id']===$id)$source=$row;
    $contract=null;foreach((array)($root['contracts']??[]) as $row)if(($row['ref']??'')===$ref)$contract=$row;
    if(!$contract||($id!==''&&(!$source||$source['contract']!==$ref)))throw new InvalidArgumentException('The selected contract or lot is no longer available in these company books.');
    $seller=strtoupper((string)($contract['seller']??''));$assigned=strtoupper(trim((string)($contract['customsExporter']??'')));
    if($id===''&&$seller!==$entity&&($seller!=='TG'||($assigned!==''&&$assigned!==$entity)))throw new InvalidArgumentException('Select a contract in these company books.');
    $port=(string)($source['portOfDischarge']??$contract['podPort']??'');
    if(strcasecmp(trim($port),trim((string)$agreement['destinationPort']))!==0)throw new InvalidArgumentException('Discharge port must match the selected contract / lot. Amend the destination in Exports first.');
    $programme=trim((string)$agreement['loadingProgrammeNo']);
    if($programme!=='')foreach($rows as $row)if($row['id']!==$id&&strcasecmp($row['loadingProgramme'],$programme)===0&&strcasecmp(trim($row['portOfDischarge']),trim($port))!==0)throw new InvalidArgumentException('One Loading Programme can cover multiple shipments only to the same discharge port.');
    $fields=array_filter(['loadingProgrammeNo'=>$programme,'shippingLine'=>(string)$agreement['shippingLine'],'portOfLoading'=>(string)$agreement['fromPort']],static fn($v)=>$v!=='');
    $changed=false;$old=[];
    foreach($root['shipments'] as &$shipment){
        if(($shipment['id']??'')===$id&&$id!==''){
            $old=['loadingProgrammeNo'=>$shipment['loadingProgrammeNo']??'','loadingPlan'=>$shipment['loadingPlan']??[],'bl'=>$shipment['bl']??[]];
            $shipment['loadingPlan']=array_replace((array)($shipment['loadingPlan']??[]),$fields);
            $shipment['bl']=array_replace((array)($shipment['bl']??[]),array_intersect_key($fields,array_flip(['shippingLine','portOfLoading'])));
            if($programme!==''){$shipment['loadingProgrammeNo']=$programme;$shipment['bl']['bookingNumber']=$programme;}
            $changed=true;
        }
        if(($shipment['kind']??'')!=='lot'&&($shipment['contractRef']??'')===$ref){
            if($id===''&&is_array($shipment['loading']['draft']??null)){$shipment['loading']['draft']=array_replace($shipment['loading']['draft'],$fields);$changed=true;}
            foreach((array)($shipment['loading']['lots']??[]) as $i=>$summary)if($id!==''&&($summary['lotRecordId']??'')===$id){$summary=array_replace($summary,$fields);$summary['plan']=array_replace((array)($summary['plan']??[]),$fields);$shipment['loading']['lots'][$i]=$summary;}
        }
    }unset($shipment);
    foreach((array)($root['millSync']['exportLoading']??[]) as $i=>$handoff)if($id!==''&&($handoff['shipmentId']??'')===$id){$handoff=array_replace($handoff,$fields);$handoff['plan']=array_replace((array)($handoff['plan']??[]),$fields);$root['millSync']['exportLoading'][$i]=$handoff;}
    if($root!==$original)$root['audits'][]=['at'=>gmdate('c'),'by'=>(string)($user['full_name']??$user['username']??'Accounts'),'type'=>'FREIGHT AGREEMENT','action'=>'Updated loading metadata from Accounts','detail'=>$ref.' · '.$id.' · '.$agreement['id'],'before'=>$old,'after'=>$fields];
    return $root;
}
function tt_accounts_sync_freight(array $agreement,array $user):void{
    $key='transtrade_export_v3_operational';$db=tt_accounts_exports_db();
    if($db){
        $db->beginTransaction();
        try{
            $stmt=$db->prepare('SELECT payload,version FROM tt_operation_records WHERE storage_key=? FOR UPDATE');$stmt->execute([$key]);$record=$stmt->fetch();
            if(!$record)throw new RuntimeException('Exports record unavailable.');
            $root=json_decode((string)$record['payload'],true,512,JSON_THROW_ON_ERROR);$next=tt_accounts_freight_metadata($root,$agreement,$user);
            if($next!==$root){$raw=json_encode($next,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$version=(int)$record['version']+1;$at=gmdate('Y-m-d H:i:s');$by=(string)($user['full_name']??$user['username']??'Accounts');$uid=(int)($user['id']??0);
                $stmt=$db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=?,updated_by_module=? WHERE storage_key=?');$stmt->execute([$raw,$version,$at,$by,$uid,'Accounts',$key]);
                $stmt=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$key,$version,hash('sha256',$raw),$at,$by,$uid,'Accounts']);
            }$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }else{
        $h=fopen(TT_DATA_DIR.'/operations.json','c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Exports storage unavailable.');
        try{$store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);$root=json_decode((string)($store['values'][$key]??''),true,512,JSON_THROW_ON_ERROR);$next=tt_accounts_freight_metadata($root,$agreement,$user);
            if($next!==$root){$store['values'][$key]=json_encode($next,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$store['revision']=(int)($store['revision']??0)+1;$store['meta'][$key]=['updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Accounts'),'userId'=>(int)($user['id']??0)];$raw=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$raw)!==strlen($raw)||!fflush($h))throw new RuntimeException('Could not save Exports metadata.');}
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
}
function tt_accounts_shipment_rows(array $root,string $entity,array $store=[]):array{
    $contracts=[];$customers=[];$rows=[];
    foreach((array)($root['customers']??[]) as $customer)if(is_array($customer))$customers[(string)($customer['id']??'')]=(string)($customer['name']??'');
    foreach((array)($root['contracts']??[]) as $contract)if(is_array($contract))$contracts[(string)($contract['ref']??'')]=$contract;
    foreach((array)($root['shipments']??[]) as $shipment){
        if(!is_array($shipment)||($shipment['kind']??'')!=='lot'||!empty($shipment['cancelled']))continue;
        $ref=(string)($shipment['contractRef']??'');$contract=$contracts[$ref]??[];
        $seller=strtoupper((string)($shipment['seller']??$contract['seller']??''));
        $exporter=$seller==='TG'?strtoupper(trim((string)($shipment['customs']['exporter']??$contract['customsExporter']??''))):$seller;
        if($seller!=='TG'&&$exporter!==$entity||$seller==='TG'&&$exporter!==''&&$exporter!==$entity)continue;
        $process=[];foreach((array)($root['shipments']??[]) as $candidate)if(is_array($candidate)&&($candidate['kind']??'')!=='lot'&&($candidate['contractRef']??'')===$ref){$process=$candidate;break;}
        $loading=(array)($shipment['loadingPlan']??$shipment['loading']??$process['loading']['draft']??[]);
        $numbers=[];foreach((array)($shipment['millActuals']??[]) as $actual)if(is_array($actual))$numbers[]=(string)($actual['number']??$actual['containerNo']??'');
        $po=array_values(array_filter(array_map(static fn($row)=>is_array($row)?(string)($row['poNo']??''):'',(array)($process['bagOrders']??[]))));
        $programme='';foreach([$shipment['loadingProgrammeNo']??null,$loading['loadingProgrammeNo']??null,$shipment['bl']['bookingNumber']??null,$loading['bookingNumber']??null,$loading['bookingNo']??null] as $candidate){$programme=trim((string)($candidate??''));if($programme!=='')break;}
        $row=['id'=>(string)($shipment['id']??''),'kind'=>'lot','seller'=>$seller,'pakistanExporter'=>$exporter,'lot'=>(string)($shipment['lotId']??''),'contract'=>$ref,'customer'=>(string)($customers[(string)($contract['customerId']??'')]??$contract['customer']??$shipment['buyer']??''),'commercialInvoice'=>(string)($shipment['commercial']['invoiceNo']??''),'customsInvoice'=>(string)($shipment['customs']['invoiceNo']??''),'bl'=>(string)($shipment['bl']['blNo']??''),'containers'=>array_values(array_unique(array_filter(array_map('trim',$numbers)))),'loadingProgramme'=>$programme,'shippingLine'=>(string)(trim((string)($shipment['bl']['shippingLine']??''))!==''?$shipment['bl']['shippingLine']:($loading['shippingLine']??'')),'forwarder'=>(string)($shipment['forwarder']??$loading['forwarder']??''),'vessel'=>(string)($shipment['bl']['vessel']??$loading['intendedVessel']??''),'voyage'=>(string)($shipment['bl']['voyage']??$loading['voyage']??''),'portOfLoading'=>(string)($shipment['bl']['portOfLoading']??$loading['portOfLoading']??$contract['pol']??''),'portOfDischarge'=>(string)($contract['podPort']??''),'brand'=>implode(', ',array_values(array_filter(array_map(static fn($pack)=>is_array($pack)?(string)($pack['brand']??''):'',(array)($contract['packings']??[]))))),'po'=>implode(', ',$po),'gd'=>implode(', ',array_map(static fn($gd)=>is_array($gd)?(string)($gd['number']??''):(string)$gd,(array)($shipment['customs']['gdRefs']??[]))),'fi'=>implode(', ',array_map(static fn($fi)=>is_array($fi)?(string)($fi['number']??''):(string)$fi,(array)($shipment['customs']['fiAllocations']??[])))];
        $rows[]=$row;
    }
    foreach((array)($store['carryForwardShipments']??[]) as $carry){
        if(($carry['status']??'')!=='Ready for Accounts'||($carry['entity']??'')!==$entity)continue;
        if(!empty($carry['operationalShipmentId'])){$found=false;foreach($rows as &$row)if($row['id']===$carry['operationalShipmentId']){$row['carryForwardShipmentId']=$carry['id'];$found=true;}unset($row);if($found)continue;}
        $rows[]=['id'=>$carry['id'],'kind'=>'lot','carryForward'=>true,'seller'=>$carry['tgPack']?'TG':$entity,'pakistanExporter'=>$entity,'lot'=>$carry['lotRef'],'contract'=>$carry['contractRef'],'customer'=>$carry['customer'],'commercialInvoice'=>$carry['buyerBalance']['invoiceNo']??'','customsInvoice'=>$carry['pakistanBalance']['invoiceNo']??$carry['buyerBalance']['invoiceNo']??'','bl'=>$carry['blNo'],'containers'=>$carry['containers'],'containerCount'=>$carry['containerCount'],'loadingProgramme'=>$carry['loadingProgrammeNo'],'shippingLine'=>$carry['shippingLine'],'forwarder'=>$carry['forwarder'],'vessel'=>$carry['vessel'],'voyage'=>$carry['voyage'],'portOfLoading'=>$carry['portOfLoading'],'portOfDischarge'=>$carry['portOfDischarge'],'brand'=>$carry['brand'],'po'=>$carry['bagPoNo'],'gd'=>implode(', ',array_column($carry['gdRefs'],'number')),'fi'=>implode(', ',array_column($carry['fiRefs'],'number'))];
    }
    return $rows;
}
