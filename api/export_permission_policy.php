<?php
declare(strict_types=1);

function ep_can(array $user,string $icon,string $action): bool {
    return tt_user_can_module_action($user,'Exports',$icon,$action)||($icon==='contracts'&&tt_user_can_module_action($user,'Exports','contract',$action));
}
function ep_require(array $user,string $icon,string $action='Edit'): void {
    if(!ep_can($user,$icon,$action))throw new DomainException('Exports '.$icon.' '.$action.' permission is required.');
}
function ep_document_icon(string $category): string {
    $category=trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($category)),'-');
    if(preg_match('/bag-mark|bag-art|bag-order/',$category))return 'bags';
    if(preg_match('/sales-contract|signed-contract/',$category))return 'contracts';
    if(preg_match('/\btg\b|relationship-letter/',$category))return 'tg';
    if(preg_match('/goods-declaration|\bgd\b|customs/',$category))return 'customs';
    if(preg_match('/bill-of-lading|\bb-?l\b/',$category))return 'bl';
    if(preg_match('/certificate-of-origin|\bcoo\b/',$category))return 'coo';
    if(preg_match('/\bl-?c\b|letter-of-credit/',$category))return 'lcdraft';
    if(preg_match('/commercial|packing-list/',$category))return 'commercial';
    if(preg_match('/bank-cover|covering|dispatch/',$category))return 'cover';
    if(preg_match('/\btg\b|relationship-letter/',$category))return 'tg';
    if(preg_match('/phyto|fumig|insurance|inspect|certificat|quality-weight/',$category))return 'certs';
    return 'print';
}
function ep_empty(mixed $value): bool {
    if(is_array($value)){foreach($value as $child)if(!ep_empty($child))return false;return true;}
    return $value===null||$value===''||$value===false||$value===0;
}
function ep_equal(mixed $a,mixed $b): bool {
    if((is_int($a)||is_float($a))&&(is_int($b)||is_float($b)))return $a==$b;
    if(is_array($a)&&is_array($b)){if(count($a)!==count($b))return false;foreach($a as $key=>$value)if(!array_key_exists($key,$b)||!ep_equal($value,$b[$key]))return false;return true;}return $a===$b;
}
function ep_record_changed(array $before,array $after): bool {
    foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $field)if(!ep_equal($before[$field]??null,$after[$field]??null)&&!(ep_empty($before[$field]??null)&&ep_empty($after[$field]??null)))return true;
    return false;
}
function ep_index(array $rows,string $identity='id'): array {
    $out=[];foreach($rows as $row){if(!is_array($row)||trim((string)($row[$identity]??''))===''||isset($out[(string)$row[$identity]]))throw new DomainException('Export record identities must be unique.');$out[(string)$row[$identity]]=$row;}return $out;
}
function ep_collection(array $user,array $old,array $next,string $icon,string $identity='id'): void {
    $before=ep_index($old,$identity);$after=ep_index($next,$identity);
    foreach($after as $id=>$row)if(!isset($before[$id])||!ep_equal($before[$id],$row))ep_require($user,$icon,isset($before[$id])?'Edit':'Create');
    // Omissions are retained by the server merge; tombstones govern removal.
}

function ep_child_documents(array $user,array $old,array $next,string $icon,string $identity): void {
    ep_collection($user,$old,$next,$icon,$identity);
    if(array_diff_key(ep_index($old,$identity),ep_index($next,$identity)))ep_require($user,$icon,'Edit');
}
function ep_document_field(array $user,string $field,string $icon,mixed $old,mixed $next): void {
    if(in_array($field,['bagOrders','certs'],true)){ep_child_documents($user,(array)$old,(array)$next,$icon,$field==='bagOrders'?'poNo':'id');return;}
    if($field==='loading'&&is_array($old)&&is_array($next)&&isset($old['lots'],$next['lots'])){
        ep_child_documents($user,(array)$old['lots'],(array)$next['lots'],$icon,'lotRecordId');$before=$old;$after=$next;unset($before['lots'],$before['draft'],$after['lots'],$after['draft']);
        ep_require($user,$icon,!ep_equal($before,$after)?'Edit':ep_workspace_action($user,$icon,false));return;
    }
    ep_require($user,$icon,ep_workspace_action($user,$icon,ep_document_saved($field,$old)));
}
function ep_sync_rows(array $user,string $field,string $icon,array $old,array $next): void {
    $identify=static function(array $rows)use($field):array{foreach($rows as &$row){$row['_permissionId']=implode('|',$field==='newExportBags'?[$row['contractRef']??'',$row['poNo']??'',$row['line']??''] : ($field==='exportLoading'?[$row['contractRef']??'',$row['shipmentId']??$row['lotId']??'']:[$row['contractRef']??'']));}unset($row);return $rows;};
    ep_child_documents($user,$identify($old),$identify($next),$icon,'_permissionId');
}

function ep_document_saved(string $field,mixed $value): bool {
    if(!is_array($value))return !ep_empty($value);
    if(in_array($field,['bagOrders','certs'],true))return count($value)>0;
    if($field==='bl')return !empty($value['draftSaved'])||!empty($value['finalized'])||!empty($value['finalDocument']);
    if($field==='production')return !empty($value['sentToMill']);
    if($field==='loadingPlan')return !ep_empty($value);
    if(in_array($field,['loading','loadingProgrammeNo'],true))return !ep_empty($value['lots']??[])||!empty($value['sentToMill'])||!empty($value['issuedAt']);
    return !empty($value['saved'])||!empty($value['finalDocument'])||!empty($value['frozen']);
}
function ep_workspace_action(array $user,string $icon,bool $saved): string {
    if($saved)return 'Edit';
    // An Edit grant preserves the established ability to finish a draft.
    return ep_can($user,$icon,'Create')?'Create':'Edit';
}

function ep_validate_upload_rows(array $user,mixed $before,mixed $after): void {
    $previous=ep_index(is_array($before)?$before:[]);$next=ep_index(is_array($after)?$after:[]);
    foreach($next as $id=>$document)if(!isset($previous[$id])||!ep_equal($previous[$id],$document)){
        $category=(string)($document['finalDocument']['category']??$document['type']??$document['name']??$document['category']??'');$icon=ep_document_icon($category);
        $replacement=isset($previous[$id]);foreach($previous as $existingDocument)if(strcasecmp((string)($existingDocument['name']??$existingDocument['type']??''),(string)($document['name']??$document['type']??''))===0)$replacement=true;
        ep_require($user,$icon,$replacement?'Edit':ep_workspace_action($user,$icon,false));
    }
    foreach(array_diff_key($previous,$next) as $document)ep_require($user,ep_document_icon((string)($document['finalDocument']['category']??$document['type']??$document['name']??$document['category']??'')),'Edit');
}

/** Refreshes may copy committed Customer Master identity, but cannot invent one. */
function ep_customer_master_projection(array $old,array $next): array {
    $masters=[];foreach((array)(tt_list_masters()['export_customers']??[]) as $row){$v=array_pad(array_values((array)($row['values']??[])),16,'');$truth=static fn($x)=>in_array(strtolower(trim((string)$x)),['yes','true','1','on'],true);$masters[(string)$row['id']]=['masterId'=>(string)$row['id'],'name'=>(string)$v[0],'code'=>(string)$v[1],'address'=>(string)$v[3],'country'=>(string)$v[4],'email'=>(string)$v[5],'phone'=>(string)$v[6],'tax'=>(string)$v[7],'notifies'=>$v[9]===''?[]:(array)json_decode((string)$v[9],true),'packingDefault'=>(string)($v[8]?:'KG'),'showCountry'=>$truth($v[12]),'showEmail'=>$truth($v[13]),'showPhone'=>$truth($v[14]),'showTax'=>$truth($v[15]),'inactive'=>in_array(strtolower((string)$v[10]),['inactive','archived'],true)];}
    $customers=ep_index((array)($old['customers']??[]));$allowed=[];
    foreach((array)($next['customers']??[]) as $customer){$id=(string)($customer['id']??'');$master=$masters[(string)($customer['masterId']??'')]??null;if(!$master)continue;$prior=$customers[$id]??['id'=>'M-'.$master['masterId'],'nextSeq'=>1];$candidate=array_replace($prior,$master);if(ep_equal($candidate,$customer)){$customers[$id]=$candidate;$allowed[$id]=$master;}}
    $old['customers']=array_values($customers);$incomingContracts=ep_index((array)($next['contracts']??[]));$incomingShipments=ep_index((array)($next['shipments']??[]));
    foreach($old['contracts']??[] as $index=>$contract){$master=$allowed[(string)($contract['customerId']??'')]??null;if(!$master)continue;$details=(array)($contract['buyerDetails']??[]);foreach(['address','country','email','phone','tax','showCountry','showEmail','showPhone','showTax'] as $key)$details[$key]=$master[$key];$incomingContract=$incomingContracts[(string)($contract['id']??'')]??null;if($incomingContract&&ep_equal($details,$incomingContract['buyerDetails']??null))$old['contracts'][$index]['buyerDetails']=$details;}
    $contracts=ep_index((array)($old['contracts']??[]),'ref');
    foreach($old['shipments']??[] as $index=>$lot){if(!empty($lot['completed'])||!empty($lot['cancelled'])||preg_match('/^(completed|closed|archived|cancelled)$/i',(string)($lot['status']??'')))continue;$contract=$contracts[(string)($lot['contractRef']??'')]??[];$master=$allowed[(string)($contract['customerId']??'')]??null;if(!$master)continue;$submitted=$incomingShipments[(string)($lot['id']??'')]??null;if(!$submitted)continue;if(($submitted['buyer']??'')===$master['name'])$old['shipments'][$index]['buyer']=$master['name'];
        $partyValues=[$master['name']];foreach(array_merge([$master],(array)$master['notifies']) as $party)foreach([', ',' — ',"\n"] as $separator)$partyValues[]=implode($separator,array_filter([(string)($party['name']??''),(string)($party['address']??'')]));
        foreach(['bl'=>['consignee','notify'],'commercial'=>['notifyParty','packingConsignee','packingNotify']] as $holder=>$keys)foreach($keys as $key){$value=$submitted[$holder][$key]??null;if(is_string($value)&&in_array($value,$partyValues,true))$old['shipments'][$index][$holder][$key]=$value;}
    }
    return $old;
}

/** Compare actual fields under the shared write lock, not a caller-supplied icon. */
function operations_validate_export_permissions(array $user,string $module,string $oldJson,string $incomingJson): void {
    $old=$oldJson===''?[]:json_decode($oldJson,true,512,JSON_THROW_ON_ERROR);$next=json_decode($incomingJson,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($old)||!is_array($next))throw new DomainException('Export records must be an object.');
    if(($user['role']??'')==='Super Admin'){if(in_array($module,['Exports','Super Admin'],true)){$closed=ep_index((array)($old['shipments']??[]));foreach((array)($next['shipments']??[]) as $lot){$prior=$closed[(string)($lot['id']??'')]??null;if($prior&&($prior['kind']??'')==='lot'&&!empty($prior['completed'])&&!empty($lot['completed'])&&ep_record_changed($prior,$lot))throw new DomainException('Reopen the completed lot before changing its documents.');}}return;}
    if(in_array($module,['Mill','Milling'],true)){
        $lots=ep_index((array)($old['shipments']??[]));foreach((array)($next['shipments']??[]) as $lot){$prior=$lots[(string)($lot['id']??'')]??null;if($prior&&!ep_equal($prior['millActuals']??[],$lot['millActuals']??[])){if(!tt_user_can_module_action($user,'Mill','export','Create')&&!tt_user_can_module_action($user,'Mill','export','Edit'))throw new DomainException('Mill Export Loading write permission is required.');}}return;
    }
    if($module!=='Exports')return; // Accounts has its own constrained merge route.
    $old=ep_customer_master_projection($old,$next);
    foreach(['suppliers'=>'bags'] as $field=>$icon)ep_collection($user,(array)($old[$field]??[]),(array)($next[$field]??[]),$icon);
    $priorContracts=ep_index((array)($old['contracts']??[]));
    foreach(ep_index((array)($next['contracts']??[])) as $contractId=>$contract){$priorContract=$priorContracts[$contractId]??null;if(ep_equal($priorContract,$contract))continue;$draft=$priorContract&&(($priorContract['issued']??null)===false||($priorContract['status']??'')==='Draft');ep_require($user,'contracts',$priorContract&&!$draft?'Edit':ep_workspace_action($user,'contracts',false));}
    if(!ep_equal(ep_index((array)($old['customers']??[])),ep_index((array)($next['customers']??[])))){
        $derived=false;
        if(!ep_equal($old['contracts']??[],$next['contracts']??[])&&(ep_can($user,'contracts','Create')||ep_can($user,'contracts','Edit'))){
            $customers=ep_index((array)($old['customers']??[]));$incomingCustomers=ep_index((array)($next['customers']??[]));$derived=count($customers)===count($incomingCustomers);
            foreach($incomingCustomers as $customerId=>$customer){$priorCustomer=$customers[$customerId]??null;unset($customer['nextSeq']);if(!is_array($priorCustomer)){$derived=false;break;}unset($priorCustomer['nextSeq']);if(!ep_equal($priorCustomer,$customer)){$derived=false;break;}}
        }
        if(!$derived&&!tt_user_can_master($user,'export_customers','Edit')&&!tt_user_can_master($user,'export_customers','Create'))throw new DomainException('Export Customer Master write permission is required.');
    }
    $existingFi=ep_index((array)($old['fi']??[]));
    foreach(ep_index((array)($next['fi']??[])) as $fiId=>$fi){$priorFi=$existingFi[$fiId]??null;if(ep_equal($priorFi,$fi))continue;if($priorFi){$plainBefore=$priorFi;$plainNext=$fi;unset($plainBefore['allocations'],$plainNext['allocations']);if(ep_equal($plainBefore,$plainNext)){ep_require($user,'customs',ep_workspace_action($user,'customs',!ep_empty($priorFi['allocations']??[])));continue;}}ep_require($user,'fi',$priorFi?'Edit':'Create');}
    if(!ep_equal($old['accountsReceipts']??[],$next['accountsReceipts']??[]))throw new DomainException('Accounts owns receipt records.');
    $before=ep_index((array)($old['shipments']??[]));$contracts=ep_index((array)($next['contracts']??[]),'ref');$oldContracts=ep_index((array)($old['contracts']??[]),'ref');
    $fields=['bagOrders'=>'bags','production'=>'production','loading'=>'loading','loadingPlan'=>'loading','loadingProgrammeNo'=>'loading','customs'=>'customs','bl'=>'bl','commercial'=>'commercial','coo'=>'coo','certs'=>'certs','covering'=>'cover','tgdocs'=>'tg','lc'=>'lcdraft','lcId'=>'lcdraft','tg'=>'tg','tgDocs'=>'tg','tgInvoice'=>'tg','tgPacking'=>'tg','tgRelationship'=>'tg','uploadedDocuments'=>'print','finalUploads'=>'print'];
    foreach(ep_index((array)($next['shipments']??[])) as $id=>$lot){
        $prior=$before[$id]??null;
        if($prior===null){
            $icon=($lot['kind']??'')==='lot'?'loading':'contracts';ep_require($user,$icon,'Create');
            foreach(['customs','commercial','coo','covering'] as $field)if(!empty($lot[$field]['saved']))throw new DomainException('Save new shipment documents in their assigned workspaces.');
            if(!empty($lot['bl']['draftSaved'])||!empty($lot['bl']['finalized'])||!empty($lot['completed'])||!empty($lot['cancelled'])||!ep_empty($lot['millActuals']??[])||!ep_empty($lot['bagOrders']??[])||!ep_empty($lot['uploadedDocuments']??[])||!ep_empty($lot['certs']??[]))throw new DomainException('New shipments cannot include completed documents or Milling activity.');
            continue;
        }
        if(($prior['kind']??'')==='lot'&&!empty($prior['completed'])&&ep_record_changed($prior,$lot))throw new DomainException('Completed lots are read-only. Super Admin must reopen the lot first.');
        $contract=$contracts[(string)($lot['contractRef']??'')]??[];$oldContract=$oldContracts[(string)($prior['contractRef']??'')]??[];
        $contractChanged=$contract&&$oldContract&&!ep_equal($contract,$oldContract);
        $documentChange=false;
        foreach(['uploadedDocuments','finalUploads'] as $uploadField)if(!ep_equal($prior[$uploadField]??[],$lot[$uploadField]??[])){ep_validate_upload_rows($user,$prior[$uploadField]??[],$lot[$uploadField]??[]);$documentChange=true;}
        foreach($fields as $documentField=>$icon)if(!in_array($documentField,['uploadedDocuments','finalUploads'],true)&&!ep_equal($prior[$documentField]??null,$lot[$documentField]??null)&&!(!array_key_exists($documentField,$prior)&&ep_empty($lot[$documentField]??null))){
            ep_document_field($user,$documentField,$icon,$prior[$documentField]??null,$lot[$documentField]??null);$documentChange=true;
        }
        foreach(array_unique(array_merge(array_keys($prior),array_keys($lot))) as $field){
            $a=$prior[$field]??null;$b=$lot[$field]??null;if(ep_equal($a,$b)||(!array_key_exists($field,$prior)&&ep_empty($b)))continue;
            if($field==='millActuals')throw new DomainException('Milling owns container actuals.');
            if(in_array($field,['history','versions','documentActivity'],true)){if(!$documentChange&&!$contractChanged)ep_require($user,'history','Edit');continue;}
            if(in_array($field,['next','status'],true)&&($documentChange||$contractChanged))continue;
            if(in_array($field,['completed','completedAt','completedBy','completionSnapshot','documentParties','partySnapshot','companySnapshot','buyerSnapshot','notifySnapshot','next','status'],true)){if(!ep_can($user,'print',ep_workspace_action($user,'print',!empty($prior['completed'])))&&!ep_can($user,'active','Edit')&&!($contractChanged&&ep_can($user,'contracts','Edit')))ep_require($user,'print','Edit');continue;}
            if(in_array($field,['cancelled','cancelReason','cancelledAt','cancelledBy'],true)){ep_require($user,'cancelled','Edit');continue;}
            if(in_array($field,['id','kind','parentProcessId','lotId'],true))throw new DomainException('Saved shipment identity cannot be changed.');
            if(isset($fields[$field])){
                if(in_array($field,['uploadedDocuments','finalUploads'],true)){
                    ep_validate_upload_rows($user,$a,$b);
                }else ep_document_field($user,$field,$fields[$field],$a,$b);
            }else {if($contractChanged&&in_array($field,['contractRef','buyer','seller','shipmentMode','plannedQty','containers'],true))continue;ep_require($user,'active','Edit');}
        }
    }
    foreach(['newExportBags'=>'bags','productionInstructions'=>'production','exportLoading'=>'loading'] as $field=>$icon)if(!ep_equal($old['millSync'][$field]??[],$next['millSync'][$field]??[]))ep_sync_rows($user,$field,$icon,(array)($old['millSync'][$field]??[]),(array)($next['millSync'][$field]??[]));
    if(!ep_equal($old['deletedShipments']??[],$next['deletedShipments']??[]))ep_require($user,'active','Edit');
    if(!ep_equal($old['cancelledContracts']??[],$next['cancelledContracts']??[]))ep_require($user,'contracts','Edit');
    $oldAudits=ep_index((array)($old['audits']??[]));
    foreach(ep_index((array)($next['audits']??[])) as $auditId=>$audit){if(isset($oldAudits[$auditId])){if(!ep_equal($oldAudits[$auditId],$audit))throw new DomainException('Saved export audit records cannot be amended.');continue;}if(stripos((string)($audit['action']??''),'cancel')!==false&&($audit['area']??'')==='Sales Contract')ep_require($user,'contracts','Edit');}
    $rootFields=['version','customers','suppliers','fi','contracts','shipments','accountsReceipts','millSync','audits','alerts','settings','deletedShipments','cancelledContracts'];
    foreach(array_unique(array_merge(array_keys($old),array_keys($next))) as $field)if(!in_array($field,$rootFields,true)&&!ep_equal($old[$field]??null,$next[$field]??null))throw new DomainException('Unknown export storage field cannot be changed.');
    if(!ep_equal($old['settings']??[],$next['settings']??[]))ep_require($user,'contracts','Edit');
}

function ep_operations_root(): array {
    $env=static function(string $key):string{$name='TT_'.$key;return defined($name)?(string)constant($name):(string)(getenv($name)?:'');};
    if($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!==''){
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=?');$q->execute(['transtrade_export_v3_operational']);$raw=$q->fetchColumn();
    }else{
        $path=TT_DATA_DIR.'/operations.json';if(!is_file($path))return [];$handle=fopen($path,'r');if(!$handle||!flock($handle,LOCK_SH))throw new RuntimeException('Export storage unavailable.');try{$store=json_decode((string)stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);}finally{flock($handle,LOCK_UN);fclose($handle);}$raw=$store['values']['transtrade_export_v3_operational']??'';
    }
    return is_string($raw)&&$raw!==''?(array)json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];
}

function ep_require_document_target(array $user,string $contractRef,string $lotRef,string $category,string $action): void {
    $root=ep_operations_root();$contract=null;$lot=null;foreach((array)($root['contracts']??[]) as $row)if(($row['ref']??'')===$contractRef)$contract=$row;
    if(!$contract)throw new DomainException('Select an existing export contract.');
    if($lotRef!==''){foreach((array)($root['shipments']??[]) as $row)if(($row['contractRef']??'')===$contractRef&&(($row['lotId']??'')===$lotRef||($row['id']??'')===$lotRef))$lot=$row;if(!$lot)throw new DomainException('Select an existing shipment lot.');}
    if($action!=='View'&&($lot&&(!empty($lot['completed'])||!empty($lot['cancelled']))))throw new DomainException('Reopen this lot before changing its documents.');
    $icon=ep_document_icon($category);
    if($action==='View'&&tt_user_can_open_module($user,'Accounts')){if(!tt_user_can_access_entity($user,strtoupper((string)($contract['seller']??'TTI')),'View'))throw new DomainException('Company document access is required.');return;}
    ep_require($user,$icon,$action==='Create'?ep_workspace_action($user,$icon,false):$action);
}
