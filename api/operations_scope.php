<?php
declare(strict_types=1);

/** Shared operational reads and partial saves use the same actor-owned projection. */
function os_any(array $u,string $module,array $icons,string $action='View'): bool {
    foreach($icons as $icon)if(tt_user_can_module_action($u,$module,$icon,$action))return true;
    return false;
}
function os_owner(array $u): bool { return in_array($u['role']??'',['Super Admin','Director'],true); }
function os_entity(array $u,string $entity): bool {
    $entity=strtoupper(trim($entity));if(!in_array($entity,['TTI','BRM','TG'],true))return false;
    if(os_owner($u))return true;
    $p=(array)($u['permissions']['Accounts']??[]);
    foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$p))return tt_user_can_access_entity($u,$entity,'View');
    return true; // Mill/Exports staff do not acquire an Accounts module requirement.
}
function os_location(array $u,string $location): bool {
    if(os_owner($u))return true;
    $norm=static fn($s)=>strtolower(trim((string)preg_replace('/\s+/',' ',(string)$s)));
    $assigned=$norm($u['location']??'');
    return in_array($assigned,['','all locations','all authorized locations'],true)||$assigned===$norm($location);
}
function os_root(array $values): array {
    $v=json_decode((string)($values['transtrade_export_v3_operational']??'{}'),true);
    return is_array($v)?$v:[];
}
function os_contract(array $root,string $ref): ?array {
    foreach((array)($root['contracts']??[]) as $row)if(is_array($row)&&(string)($row['ref']??'')===$ref)return $row;
    return null;
}
function os_scope(array $u,array $row,array $root,bool $physical=true): bool {
    $ref=trim((string)($row['contractRef']??$row['_ttContractRef']??''));$contract=$ref!==''?os_contract($root,$ref):null;
    $entity=strtoupper(trim((string)($row['entity']??$row['seller']??$row['exporter']??$contract['seller']??'TTI')));
    if($contract&&isset($row['entity'])&&strtoupper((string)($contract['seller']??'TTI'))!==$entity)return false;
    if(!os_entity($u,$entity))return false;
    if(!$physical)return true;
    return os_location($u,(string)($row['millName']??$row['locationName']??$row['mill']??$row['stockLocation']??$row['deliverTo']??'TTI Rice Mills'));
}
function os_key_grants(): array {
    return [
        'tt30arrivaldefaults'=>['queue','arrival'], 'tt30directoralerts'=>['queue','arrival'],
        'tt30mills'=>['stock','queue','arrival','newbags','instructions','production','export','local','petty','labour','reprocessbill','oldbags','reports'],
        'tt30queue'=>['queue','arrival','reports'], 'tt30slips'=>['arrival','queue','stock','production','labour','reprocessbill','reports'],
        'tt37arrivalaudit'=>['arrival','queue','reports'],
        'tt30bags'=>['newbags','production','stock','export','instructions','reports'],
        'tt33bagreceipts'=>['newbags','production','stock','reports'], 'tt36bagissues'=>['production','newbags','stock','reports'],
        'tt30prodinst'=>['instructions','production','export','stock','reports'],
        'tt30prod'=>['production','stock','labour','reprocessbill','export','reports'],
        'tt30prodaudit'=>['production','reports'], 'tt35brandmeta'=>['production','stock','export','reports'],
        'tt32stockadj'=>['stock','production','reports'], 'tt32labourfeed'=>['production','labour','reports'],
        'tt30ship'=>['export','stock','production','labour','reports'],
        'tt32exportsync'=>['export','reports'], 'tt35exmill'=>['export','reports'],
        'tt35exload'=>['export','reports'], 'tt40exinstructions'=>['export','instructions','reports'],
        'tt40instructionseen'=>['instructions','export'], 'tt39bridgequarantine'=>['export','reports'],
        'tt35exportersale'=>['local','stock','reports'], 'tt35localsales'=>['local','stock','reports'],
        'tt37usedbags'=>['oldbags','reports'], 'tt30petty'=>['petty','oldbags'], 'tt33pettyexp'=>['petty','labour'],
        'tt37processingexpenses'=>['labour','reports'], 'tt38kebills'=>['labour','reports'],
        'tt38labourbills'=>['labour','reports'], 'tt38reprocessbills'=>['reprocessbill','reports'],
        'tt38costmaster'=>['labour','reprocessbill'], 'tt38labourrates'=>['labour'],
        'tt39rentmaster'=>['labour'], 'tt39rentpayments'=>['labour'],
        'tt39salarymaster'=>['labour'], 'tt39salaryadvances'=>['labour'],
        'tt39physicalconfirmations'=>['stock','export','reports'],
    ];
}
function os_export_key_visible(array $u,string $key): bool {
    $exports=[
        'tt30bags'=>['bags','loading','production'], 'tt33bagreceipts'=>['bags'],
        'tt30prodinst'=>['production','loading'], 'tt30ship'=>['loading','active','commercial','customs','print'],
        'tt32exportsync'=>['loading','active','commercial','customs','print'],
        'tt35exmill'=>['loading'], 'tt35exload'=>['loading','active','commercial','customs','print'],
        'tt40exinstructions'=>['loading','active'], 'tt39bridgequarantine'=>['loading','history'],
    ];
    return isset($exports[$key])&&os_any($u,'Exports',$exports[$key]);
}
function os_key_visible(array $u,string $key): bool {
    if(($u['role']??'')==='Super Admin')return true;
    if(in_array($key,['tt37users','tt39accountsoutbox','tt38accountsfeed'],true))return false;
    $map=os_key_grants();if(!isset($map[$key]))return false;
    if(os_owner($u))return true;
    $setup=['tt39salarymaster'=>'salary_staff','tt39rentmaster'=>'reference_lists','tt38costmaster'=>'reference_lists','tt38labourrates'=>'reference_lists'];
    if(isset($setup[$key])&&!tt_user_can_master($u,$setup[$key],'Use')&&!tt_user_can_master($u,$setup[$key],'View'))return false;
    if(os_any($u,'Mill',$map[$key]))return true;
    return os_export_key_visible($u,$key);
}
function os_fields(array $row,string $fields): array { return array_intersect_key($row,array_fill_keys(explode(' ',$fields),true)); }
function os_visible_allocations(array $u,array $rows): array {
    $out=[];foreach($rows as $i=>$row)if(is_array($row)&&os_location($u,(string)($row['name']??'TTI Rice Mills'))){$row['_ttAllocationIndex']=$i;$out[]=$row;}return $out;
}
/** Co-loaded containers share identity; each worker receives only its own physical contributions. */
function os_actual_contributions(array $row): array {
    if(!empty($row['contributions'])&&is_array($row['contributions']))return array_values($row['contributions']);
    return [array_replace(os_fields($row,'location brand packing bags netKg millNetKg tareKg grossKg truck gatePass loadedDate'),['id'=>'legacy'])];
}
function os_actual_totals(array $row,array $parts,bool $corn): array {
    $row['contributions']=$parts;
    foreach(['bags'=>'bags','millNetKg'=>'millNetKg','tareKg'=>'tareKg'] as $field=>$source)$row[$field]=array_sum(array_map(static fn($p)=>(float)($p[$source]??($source==='millNetKg'?($p['netKg']??0):0)),$parts));
    if($corn)$row['netKg']=$row['documentNetKg']=$row['millNetKg'];
    $row['grossKg']=(float)($row['netKg']??0)+$row['tareKg'];
    foreach(['location','brand','packing','truck','gatePass','loadedDate'] as $field)$row[$field]=implode(' + ',array_unique(array_values(array_filter(array_map(static fn($p)=>(string)($p[$field]??''),$parts),static fn($v)=>$v!==''))));
    return $row;
}
function os_actual_projection(array $u,array $row,bool $allocated,bool $corn): ?array {
    $all=os_actual_contributions($row);$own=array_values(array_filter($all,static fn($p)=>is_array($p)&&os_location($u,(string)($p['location']??'TTI Rice Mills'))));
    if(!$own&&!$allocated)return null;
    if(count($all)===count($own))return $row;
    // Identity and seal are shared by the allocated physical container; other mills' facts remain hidden.
    $shown=$own?$row:os_fields($row,'number container containerNo id seal shipmentId contractRef lotRef source packSize unit');
    $shown=os_actual_totals($shown,$own,$corn);$shown['_ttPartialActual']=true;
    return $shown;
}
function os_corn(array $contract): bool { return preg_match('/corn|maize/i',(string)($contract['commodity']??$contract['product']??''))===1; }
function os_activity_icon(string $area): string {
    $v=strtolower($area);
    foreach(['production'=>'production','loading'=>'loading','bag'=>'bags','sales contract'=>'contracts','custom'=>'customs','commercial'=>'commercial','b/l'=>'bl','bl'=>'bl','origin'=>'coo','coo'=>'coo','cover'=>'cover','dispatch'=>'cover','l/c'=>'lcdraft','lc'=>'lcdraft','tg'=>'tg','cert'=>'certs','upload'=>'print'] as $term=>$icon)if($v===$term||str_contains($v,$term))return $icon;
    return 'history';
}
function os_physical_packing(array $row): array {
    $out=os_fields($row,'type size unit brand tare containers weightPer extraBagPct looseBulk');
    if(isset($row['masterBag']))$out['masterBag']=os_fields((array)$row['masterBag'],'enabled bagsPerMaster quantity weight tare qty size');
    return $out;
}
function os_mill_sync(string $field,array $row): array {
    if($field==='newExportBags')return os_fields($row,'contractRef poNo line lineId brand size unit orderedTare totalOrdered requiredBags supplier deliverTo artworkName artworkData artworkDocument status masterBag sentAt entity');
    $out=os_fields($row,'contractRef shipmentId lotId sentAt');
    if($field==='productionInstructions'){
        $out['packings']=array_map('os_physical_packing',(array)($row['packings']??[]));
        $out['production']=os_fields((array)($row['production']??[]),'qualityNotes dryOn craftPaper inspection dpp requiredBy');
        if(isset($row['specifications']))$out['specifications']=$row['specifications'];
    }else{$out['production']=os_fields((array)($row['production']??[]),'qualityNotes dryOn craftPaper inspection dpp requiredBy');$out['plan']['allocations']=array_map(static fn($a)=>os_fields((array)$a,'type name packIndex containers weightPer dryOn craftPaper inspection dpp emptyBags _ttAllocationIndex'),(array)($row['plan']['allocations']??[]));}
    return $out;
}
/** Selector Use does not expose an unrelated payroll or banking Master record. */
function os_bootstrap_masters(array $u): array {
    $out=[];
    foreach(tt_list_masters() as $type=>$rows){
        $view=tt_user_can_master($u,$type,'View');$use=tt_user_can_master($u,$type,'Use');
        if(!$view&&!$use)continue;
        $out[$type]=[];
        foreach((array)$rows as $row){
            if(!is_array($row))continue;
            if(!$view&&in_array($type,['banks','salary_staff'],true)){
                $v=array_values((array)($row['values']??[]));$clean=array_fill(0,count($v),'');
                $fields=$type==='banks'?[0,1,3,4,7,13]:[0,1,2];
                foreach($fields as $i)if(isset($v[$i]))$clean[$i]=$v[$i];
                $row=['id'=>$row['id']??'','values'=>$clean];
            }
            if(!$view&&$type==='companies'){
                $v=array_values((array)($row['values']??[]));if(array_key_exists(13,$v))$v[13]='[]';
                $row=['id'=>$row['id']??'','values'=>$v];
            }
            $out[$type][]=$row;
        }
    }
    return $out;
}
function os_root_projection(array $u,array $root): ?array {
    if(os_owner($u))return $root;
    $exports=os_any($u,'Exports',['home','active','contracts','contract','bags','production','loading','fi','customs','bl','commercial','coo','certs','cover','tg','lcdraft','print','history','cancelled']);
    $mill=os_any($u,'Mill',['instructions','newbags','production','export','reports']);
    if(!$exports&&!$mill)return null;
    $flat=$u['permissions']['Exports']??[];
    $full=$flat==='all'||(is_array($flat)&&in_array('View',$flat,true));
    $out=[];$contracts=[];$refs=[];
    $commercial=$full||os_any($u,'Exports',['contracts','contract','commercial','customs','print','fi','cover','tg','lcdraft']);
    foreach((array)($root['contracts']??[]) as $row){
        if(!is_array($row)||!os_scope($u,$row,$root,false))continue;
        if(!$exports&&!$full&&!os_location($u,'TTI Rice Mills')){
            $matched=false;
            foreach((array)($root['millSync']['exportLoading']??[]) as $sync)if(($sync['contractRef']??'')===($row['ref']??''))foreach((array)($sync['plan']['allocations']??[]) as $allocation)if(os_location($u,(string)($allocation['name']??'')))$matched=true;
            foreach((array)($root['millSync']['newExportBags']??[]) as $sync)if(($sync['contractRef']??'')===($row['ref']??'')&&os_location($u,(string)($sync['deliverTo']??'TTI Rice Mills')))$matched=true;
            if(!$matched)continue;
        }
        $refs[(string)($row['ref']??'')]=true;
        $clean=$commercial?$row:os_fields($row,'id ref seller customerId status cancelled commodity product variety productIdentityCode baseVariety riceType productStage brokenText brokenGrade quality packings packingUnit inspection shippingLine');
        if(!$commercial&&isset($clean['packings']))$clean['packings']=array_map('os_physical_packing',(array)$clean['packings']);
        $contracts[]=$clean;
    }
    $loadExport=os_any($u,'Exports',['loading','active','commercial','customs','print']);
    $out['contracts']=$contracts;$shipments=[];
    $documentFields=['bagOrders'=>'bags','production'=>'production','loading'=>'loading','loadingPlan'=>'loading','loadingProgrammeNo'=>'loading','customs'=>'customs','bl'=>'bl','commercial'=>'commercial','coo'=>'coo','certs'=>'certs','covering'=>'cover','tgdocs'=>'tg','lc'=>'lcdraft','lcId'=>'lcdraft','tg'=>'tg','tgDocs'=>'tg','tgInvoice'=>'tg','tgPacking'=>'tg','tgRelationship'=>'tg'];
    foreach((array)($root['shipments']??[]) as $row){
        if(!is_array($row)||!isset($refs[(string)($row['contractRef']??'')]))continue;
        if($full){$shipments[]=$row;continue;}
        $clean=os_fields($row,'id kind contractRef parentProcessId lotId status completed cancelled seller buyer containers plannedQty shipmentMode lotDate shipmentDate millLoadingCompleted millLoadingCompletedAt');
        foreach($documentFields as $field=>$icon)if($exports&&os_any($u,'Exports',[$icon,'print'])&&array_key_exists($field,$row))$clean[$field]=$row[$field];
        if($exports&&os_any($u,'Exports',['history']))foreach(['history','versions','documentActivity'] as $field)if(isset($row[$field]))$clean[$field]=$row[$field];
        if($exports&&isset($row['documentActivity'])&&!isset($clean['documentActivity']))$clean['documentActivity']=array_values(array_filter((array)$row['documentActivity'],static fn($r)=>is_array($r)&&os_any($u,'Exports',[os_activity_icon((string)($r['document']??'')),'print'])));
        if($exports&&os_any($u,'Exports',['commercial','bl','coo','cover','print'])&&isset($row['bl'])&&!isset($clean['bl']))$clean['bl']=os_fields((array)$row['bl'],'consignee notify');
        if($exports&&os_any($u,'Exports',['print','active']))foreach(['completedAt','completedBy','completionSnapshot','documentParties','partySnapshot','companySnapshot','buyerSnapshot','notifySnapshot','next','reopenedAt','reopenReason'] as $f)if(array_key_exists($f,$row))$clean[$f]=$row[$f];
        if($exports)foreach(['uploadedDocuments','finalUploads'] as $field)if(isset($row[$field]))$clean[$field]=array_values(array_filter((array)$row[$field],static fn($doc)=>is_array($doc)&&os_any($u,'Exports',[ep_document_icon((string)($doc['finalDocument']['category']??$doc['type']??$doc['name']??'')),'print'])));
        if($mill||($exports&&os_any($u,'Exports',['loading','commercial','customs','print']))){
            $clean['millActuals']=[];$allocated=false;foreach((array)($row['loadingPlan']['allocations']??[]) as $a)if(os_location($u,(string)($a['name']??'')))$allocated=true;
            $corn=os_corn(os_contract($root,(string)($row['contractRef']??''))??[]);
            foreach((array)($row['millActuals']??[]) as $actual)if(is_array($actual)){$shown=$loadExport?$actual:os_actual_projection($u,$actual,$allocated,$corn);if($shown!==null)$clean['millActuals'][]=$shown;}
            if(isset($row['loadingPlan'])){$clean['loadingPlan']=$row['loadingPlan'];if(!$loadExport)$clean['loadingPlan']['allocations']=os_visible_allocations($u,(array)($row['loadingPlan']['allocations']??[]));}
            if(!$exports&&isset($row['customs']['exporter']))$clean['customs']=['exporter'=>$row['customs']['exporter']];
        }
        $shipments[]=$clean;
    }
    $out['shipments']=$shipments;
    foreach(['newExportBags'=>['bags',['newbags','production']],'productionInstructions'=>['production',['instructions','production']],'exportLoading'=>['loading',['export']]] as $field=>[$icon,$millIcons]){
        if(!array_key_exists($field,(array)($root['millSync']??[])))continue;
        if(!$full&&!os_any($u,'Exports',[$icon])&&!os_any($u,'Mill',$millIcons))continue;
        $rows=[];
        foreach((array)($root['millSync'][$field]??[]) as $r){if(!is_array($r)||!isset($refs[(string)($r['contractRef']??'')]))continue;
            if(!os_any($u,'Exports',[$icon])){if($field==='exportLoading'){$r['plan']['allocations']=os_visible_allocations($u,(array)($r['plan']['allocations']??[]));if(!$r['plan']['allocations'])continue;}elseif(!os_scope($u,$r,$root))continue;}
            $rows[]=($full||($field==='newExportBags'&&os_any($u,'Exports',[$icon])))?$r:os_mill_sync($field,$r);
        }
        $out['millSync'][$field]=$rows;
    }
    foreach(['customers'=>['contracts','contract','commercial','bl','coo','cover','print'],'suppliers'=>['bags'],'fi'=>['fi','customs'],'audits'=>['history'],'alerts'=>['home','active','history'],'settings'=>['contracts','contract','commercial','customs','print'],'deletedShipments'=>['active'],'cancelledContracts'=>['contracts','contract']] as $field=>$icons){
        if(($full||($exports&&os_any($u,'Exports',$icons)))&&array_key_exists($field,$root))$out[$field]=$root[$field];
    }
    if($exports&&!isset($out['audits']))$out['audits']=array_values(array_filter((array)($root['audits']??[]),static fn($r)=>is_array($r)&&os_any($u,'Exports',[os_activity_icon((string)($r['area']??''))])));
    // Historical party details follow the authorized contracts/orders, unless Master access grants the selector itself.
    if(isset($out['customers'])&&!tt_user_can_master($u,'export_customers','View')&&!tt_user_can_master($u,'export_customers','Use')){
        $ids=[];foreach($contracts as $c)if(isset($c['customerId']))$ids[(string)$c['customerId']]=true;
        $out['customers']=array_values(array_filter((array)$out['customers'],static fn($r)=>is_array($r)&&isset($ids[(string)($r['id']??'')])));
    }
    if(isset($out['suppliers'])&&!tt_user_can_master($u,'business_parties','View')&&!tt_user_can_master($u,'business_parties','Use')){
        $ids=[];foreach((array)($root['millSync']['newExportBags']??[]) as $r)if(isset($refs[(string)($r['contractRef']??'')]))foreach(['supplier','supplierId'] as $f)if(isset($r[$f]))$ids[(string)$r[$f]]=true;
        $out['suppliers']=array_values(array_filter((array)$out['suppliers'],static fn($r)=>is_array($r)&&(isset($ids[(string)($r['id']??'')])||isset($ids[(string)($r['name']??'')]))));
    }
    // Root-wide financial/history collections need a full company scope until a row carries a provable owner.
    foreach(['fi','audits','alerts','deletedShipments','cancelledContracts'] as $field)if(isset($out[$field])&&!os_owner($u))$out[$field]=array_values(array_filter((array)$out[$field],static function($r)use($u,$refs,$root){if(!is_array($r))return false;$ref=(string)($r['contractRef']??'');if($ref!=='')return isset($refs[$ref]);if(isset($r['entity'])||isset($r['exporter'])||isset($r['seller']))return os_scope($u,$r,$root,false);return os_entity($u,'TTI')&&os_entity($u,'BRM')&&os_entity($u,'TG');}));
    if($mill&&!$exports)$out['alerts']=array_values(array_filter((array)($root['alerts']??[]),static fn($r)=>is_array($r)&&($r['area']??'')==='MILL ACTUALS'&&isset($refs[(string)($r['contractRef']??'')])&&(!isset($r['userId'])||(int)$r['userId']===(int)($u['id']??0))));
    if(isset($root['version']))$out['version']=$root['version'];
    return $out;
}
function os_project(array $u,array $values,array $meta=[]): array {
    $public=tt_inv_public_values($values,$meta);$out=[];$restricted=$public['restrictedKeys'];$root=os_root($values);
    foreach($public['values'] as $key=>$json){
        if($key==='transtrade_export_v3_operational'){$next=os_root_projection($u,$root);if($next!==null)$out[$key]=tt_inv_json($next);else $restricted[]=$key;continue;}
        if(!os_key_visible($u,$key)){$restricted[]=$key;continue;}
        $data=json_decode((string)$json,true);if(!is_array($data)){$restricted[]=$key;continue;}
        if($key==='tt40instructionseen'&&!os_owner($u)){$name=strtolower(trim((string)($u['full_name']??$u['username']??'')));$data=array_intersect_key($data,[$name=>true]);}
        elseif(array_is_list($data)){
            $data=array_values(array_filter($data,static fn($r)=>is_array($r)&&os_scope($u,$r,$root,!os_export_key_visible($u,$key))));
            if(in_array($key,['tt35localsales','tt35exportersale'],true)&&!os_any($u,'Mill',['local','reports'])&&!os_owner($u))$data=array_map(static fn($r)=>os_fields($r,'id entity mill millName locationName commodity product variety brand date bags kg qty quantity loads'),$data);
            if(in_array($key,['tt30petty','tt33pettyexp'],true)&&!os_any($u,'Mill',['petty'])&&!os_owner($u)){
                $source=$key==='tt30petty'?'tt37usedbags':'tt37processingexpenses';$prefix=$key==='tt30petty'?'UB-':'PE-';$vouchers=[];
                foreach(tt_inv_rows($values,$source) as $r)if(os_scope($u,$r,$root))$vouchers[$prefix.substr((string)($r['id']??''),-6)]=true;
                $data=array_values(array_filter($data,static fn($r)=>isset($vouchers[(string)($r['voucher']??'')])));
            }
        }elseif(!os_scope($u,$data,$root,!os_export_key_visible($u,$key))){$restricted[]=$key;continue;}
        $out[$key]=tt_inv_json($data);
    }
    return ['values'=>$out,'meta'=>array_intersect_key($public['meta'],$out),'restrictedKeys'=>array_values(array_unique($restricted))];
}

function os_identity(mixed $row): string {
    if(is_array($row)&&isset($row['poNo'])&&(isset($row['line'])||isset($row['lineId'])))return 'po|'.($row['contractRef']??'').'|'.$row['poNo'].'|'.($row['line']??$row['lineId']);
    if(is_array($row)&&isset($row['contractRef'],$row['plan']))return 'loading|'.$row['contractRef'].'|'.($row['shipmentId']??$row['lotId']??'');
    if(is_array($row)&&isset($row['contractRef'],$row['packings'],$row['production']))return 'production|'.$row['contractRef'];
    if(is_array($row))foreach(['id','key','ref','lotRecordId','productionId','sourceRecordId','poNo','number'] as $field)if(isset($row[$field])&&(string)$row[$field]!=='')return $field.'|'.(string)$row[$field];
    if(is_array($row)&&isset($row['type'],$row['name'],$row['packIndex']))return 'allocation|'.$row['type'].'|'.$row['name'].'|'.$row['packIndex'];
    return 'value|'.hash('sha256',tt_inv_json($row));
}
/** Rebuild only omitted protected facts; visible omissions retain existing deletion semantics. */
function os_rebuild(mixed $old,mixed $visible,mixed $incoming): mixed {
    if(!is_array($old)||!is_array($visible)||!is_array($incoming))return $incoming;
    if(array_is_list($old)&&array_is_list($visible)&&array_is_list($incoming)){
        if(operations_same_value($old,$visible))return $incoming;
        if(count($old)===count($visible)&&count($old)===count($incoming)&&!array_filter($old,static fn($r)=>!str_starts_with(os_identity($r),'value|'))){
            $out=[];foreach($incoming as $i=>$row)$out[]=os_rebuild($old[$i],$visible[$i],$row);return $out;
        }
        $before=$seen=$submitted=[];
        foreach($old as $row){$id=os_identity($row);if(isset($before[$id]))throw new DomainException('Shared record identities need review.');$before[$id]=$row;}
        foreach($visible as $row)$seen[os_identity($row)]=$row;
        foreach($incoming as $row){$id=os_identity($row);if(isset($submitted[$id]))throw new DomainException('Shared record identities must be unique.');if(isset($before[$id])&&!isset($seen[$id]))throw new DomainException('A hidden shared record cannot be submitted.');$submitted[$id]=$row;}
        if(count($old)===count($visible)&&operations_same_value($old,$visible))return $incoming;
        $out=[];foreach($old as $row){$id=os_identity($row);if(!isset($seen[$id]))$out[]=$row;elseif(isset($submitted[$id])){$out[]=os_rebuild($row,$seen[$id],$submitted[$id]);unset($submitted[$id]);}}
        foreach($submitted as $row)$out[]=$row;return $out;
    }
    if($old!==[]&&array_is_list($old)!==array_is_list($incoming)&&$incoming!==[])throw new DomainException('Shared record shape cannot change.');
    $out=$incoming;
    foreach($old as $key=>$value){
        if(!array_key_exists($key,$visible)){if(array_key_exists($key,$incoming)&&!ep_empty($incoming[$key])&&!operations_same_value($value,$incoming[$key]))throw new DomainException('A hidden shared field cannot be changed.');$out[$key]=$value;}
        elseif(array_key_exists($key,$incoming))$out[$key]=os_rebuild($value,$visible[$key],$incoming[$key]);
    }
    return $out;
}
function os_prepare_write(array $u,string $key,string $incomingJson,array $values): string {
    $public=tt_inv_public_values($values)['values'];$visible=os_project($u,$values)['values'];
    if(!array_key_exists($key,$visible)&&isset($values[$key]))throw new DomainException('This workflow is outside your shared record access.');
    if($key!=='transtrade_export_v3_operational'&&!os_key_visible($u,$key))throw new DomainException('This workflow is outside your shared record access.');
    $incoming=json_decode($incomingJson,true,512,JSON_THROW_ON_ERROR);if(!is_array($incoming))throw new InvalidArgumentException('Shared records must be JSON objects or lists.');
    // Physical confirmation baselines are server-computed; legacy clients may still send a stale display snapshot.
    if($key===TT_INV_CONFIRMATIONS)$incoming=json_decode(tt_inv_public_values([$key=>$incomingJson])['values'][$key],true,512,JSON_THROW_ON_ERROR);
    $old=json_decode((string)($public[$key]??($key==='transtrade_export_v3_operational'?'{}':'[]')),true);
    $shown=json_decode((string)($visible[$key]??'[]'),true);
    $partial=[];
    if($key==='transtrade_export_v3_operational'){
        foreach((array)($shown['shipments']??[]) as $lot)foreach((array)($lot['millActuals']??[]) as $actual)if(!empty($actual['_ttPartialActual']))$partial[(string)($lot['id']??'')][os_identity($actual)]=true;
        foreach($old['shipments']??[] as $i=>$lot)foreach($lot['millActuals']??[] as $j=>$actual)if(isset($partial[(string)($lot['id']??'')][os_identity($actual)]))$old['shipments'][$i]['millActuals'][$j]['contributions']=os_actual_contributions($actual);
    }
    $rebuilt=os_rebuild($old,$shown,$incoming);
    foreach($rebuilt['shipments']??[] as $i=>$lot)foreach($lot['millActuals']??[] as $j=>$actual)if(isset($partial[(string)($lot['id']??'')][os_identity($actual)]))$rebuilt['shipments'][$i]['millActuals'][$j]=os_actual_totals($actual,(array)($actual['contributions']??[]),os_corn(os_contract($rebuilt,(string)($lot['contractRef']??''))??[]));
    $next=$values;$next[$key]=tt_inv_json($rebuilt);
    $projected=os_project($u,$next)['values'][$key]??null;
    if($projected===null)throw new DomainException('The submitted shared records are outside your company or location.');
    // Every nonempty submitted fact must survive its authorization projection.
    $check=static function($input,$allowed)use(&$check):bool{
        if(!is_array($input))return operations_same_value($input,$allowed);
        if(!is_array($allowed))return ep_empty($input);
        if(array_is_list($input)){
            $index=[];foreach($allowed as $row)$index[os_identity($row)]=$row;
            foreach($input as $row){$id=os_identity($row);if(!array_key_exists($id,$index)||!$check($row,$index[$id]))return false;}return true;
        }
        foreach($input as $field=>$value)if(!array_key_exists($field,$allowed)?!ep_empty($value):!$check($value,$allowed[$field]))return false;
        return true;
    };
    if(!$check($incoming,json_decode($projected,true)))throw new DomainException('The submitted shared records include unauthorized facts.');
    if($key==='transtrade_export_v3_operational'){
        $strip=static function($v)use(&$strip){if(!is_array($v))return $v;unset($v['_ttAllocationIndex'],$v['_ttPartialActual']);return array_map($strip,$v);};
        return tt_inv_json($strip($rebuilt));
    }
    return $next[$key];
}
