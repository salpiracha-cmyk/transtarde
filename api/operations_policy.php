<?php
declare(strict_types=1);
require_once __DIR__.'/export_permission_policy.php';

// Source permissions and target ownership are separate checks. Both storage
// backends enforce this policy before a backup or any durable write.
function operations_validate_key_module(array $user, string $module, string $key): void {
    $module = $module === 'Milling' ? 'Mill' : $module;
    if ($module === 'Super Admin' && ($user['role'] ?? '') !== 'Super Admin') throw new DomainException('Only Super Admin may use this save route.');
    if (in_array($key, ['tt34ghati','tt34nilqueue','tt32processingrecon','tt40exportreceipts','tt41bagtransfers'], true)) throw new DomainException('This record is maintained by the server.');
    if (($user['role'] ?? '') === 'Super Admin') return;
    if ($key === 'transtrade_export_v3_operational') return;
    $exportKeys = ['tt30bags','tt30prodinst','tt30ship','tt35exmill','tt40exinstructions','tt39bridgequarantine','tt39accountsoutbox','tt32exportsync','tt35exload'];
    $millKeys = [
        'tt30arrivaldefaults','tt30bags','tt30directoralerts','tt30mills','tt30petty','tt30prod','tt30prodaudit','tt30prodinst','tt30queue','tt30ship','tt30slips',
        'tt32exportsync','tt32labourfeed','tt32stockadj','tt33bagreceipts','tt33pettyexp','tt35brandmeta','tt35exload','tt35exmill','tt35exportersale','tt35localsales','tt36bagissues',
        'tt37arrivalaudit','tt37processingexpenses','tt37usedbags','tt37users','tt38accountsfeed','tt38costmaster','tt38kebills','tt38labourbills','tt38labourrates','tt38reprocessbills',
        'tt39accountsoutbox','tt39bridgequarantine','tt39physicalconfirmations','tt39rentmaster','tt39rentpayments','tt39salaryadvances','tt39salarymaster','tt40exinstructions','tt40instructionseen',
    ];
    $allowed = $module === 'Mill' ? $millKeys : ($module === 'Exports' ? $exportKeys : []);
    if (!in_array($key, $allowed, true)) throw new DomainException('This module cannot change the selected record.');
    $icons=$module==='Mill'?[
        'tt30queue'=>'queue','tt30slips'=>'arrival','tt37arrivalaudit'=>'arrival',
        'tt30prod'=>'production','tt30prodaudit'=>'production','tt35brandmeta'=>'production','tt36bagissues'=>'production',
        'tt32stockadj'=>'production','tt32labourfeed'=>'production','tt33bagreceipts'=>'newbags',
        'tt37usedbags'=>'oldbags','tt37processingexpenses'=>'labour','tt38kebills'=>'labour','tt38labourbills'=>'labour',
        'tt39rentpayments'=>'labour','tt39salaryadvances'=>'labour','tt38reprocessbills'=>'reprocessbill',
        'tt35exportersale'=>'local','tt35localsales'=>'local','tt30ship'=>'export','tt35exload'=>'export','tt40exinstructions'=>'export',
    ]:[ 'tt30bags'=>'bags','tt30prodinst'=>'production','tt30ship'=>'loading','tt40exinstructions'=>'loading' ];
    if(isset($icons[$key])&&!operations_icon_write($user,$module,$icons[$key])){
        $labels=['queue'=>'Arrival List','arrival'=>'Arrival / Pohanch','production'=>'Production','newbags'=>'New Export Bags','oldbags'=>'Used Bags','labour'=>'Processing Expenses','reprocessbill'=>'Reprocessing Bills','local'=>'Local Sales','export'=>'Export Loading','bags'=>'Bag Orders','loading'=>'Loading Instructions'];
        throw new DomainException('Write permission for '.($labels[$icons[$key]]??ucfirst($icons[$key])).' is required.');
    }
    if($module==='Mill'&&$key==='tt37users')throw new DomainException('Only Super Admin may change user settings.');
    if(in_array($key,['tt38accountsfeed','tt39accountsoutbox'],true))throw new DomainException('Use the canonical Accounts source workflow.');
    $setup=['tt30arrivaldefaults'=>'purchase_products','tt30mills'=>'mills','tt38costmaster'=>'reference_lists','tt38labourrates'=>'reference_lists','tt39rentmaster'=>'reference_lists','tt39salarymaster'=>'salary_staff'];
    if($module==='Mill'&&isset($setup[$key])){
        if(!tt_user_can_master($user,$setup[$key],'Create')&&!tt_user_can_master($user,$setup[$key],'Edit'))throw new DomainException('Master Create or Edit permission is required.');
        return;
    }
    $missing=$module==='Mill'?[
        'tt30bags'=>['newbags','production'],'tt30directoralerts'=>['queue','arrival'],'tt30prodinst'=>['instructions','production','export'],
        'tt32exportsync'=>['export'],'tt35exmill'=>['export'],'tt39bridgequarantine'=>['export'],
        'tt39physicalconfirmations'=>['stock','export'],
    ]:['tt35exmill'=>['loading'],'tt39bridgequarantine'=>['loading'],'tt32exportsync'=>['loading'],'tt35exload'=>['loading']];
    if($key==='tt40instructionseen'&&$module==='Mill'){
        if(!tt_user_can_module_action($user,'Mill','instructions','View')&&!tt_user_can_module_action($user,'Mill','export','View'))throw new DomainException('Instruction View permission is required.');
        return;
    }
    if(isset($missing[$key])){
        foreach($missing[$key] as $icon)if(operations_icon_write($user,$module,$icon))return;
        throw new DomainException('Write permission for this workflow is required.');
    }

    $cashIcons=['tt30petty'=>['petty','oldbags'],'tt33pettyexp'=>['petty','labour']];
    if($module==='Mill'&&isset($cashIcons[$key])){
        foreach($cashIcons[$key] as $icon) foreach(['Create','Edit'] as $action)
            if(tt_user_can_module_action($user,'Mill',$icon,$action))return;
        throw new DomainException('Permission for the cash entry workflow is required.');
    }
    if(!isset($icons[$key]))throw new DomainException('This record has no authorized shared write workflow.');
}

function operations_icon_write(array $user,string $module,string $icon): bool {
    return tt_user_can_module_action($user,$module,$icon,'Create')||tt_user_can_module_action($user,$module,$icon,'Edit');
}

/** Production may add an empty brand placeholder, never receive or revise bags. */
function operations_validate_brand_bags(array $user,string $module,string $key,string $oldJson,string $incomingJson): void {
    if(!in_array($module,['Mill','Milling'],true)||$key!=='tt30bags'||operations_icon_write($user,'Mill','newbags'))return;
    if(!operations_icon_write($user,'Mill','production'))throw new DomainException('New Export Bags write permission is required.');
    $old=$oldJson===''?[]:json_decode($oldJson,true,512,JSON_THROW_ON_ERROR);$incoming=json_decode($incomingJson,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($old)||!array_is_list($old)||!is_array($incoming)||!array_is_list($incoming))throw new DomainException('Bag records must be a list.');
    $existing=[];foreach($old as $row){if(!is_array($row)||!isset($row['id'])||isset($existing[(string)$row['id']]))throw new DomainException('Existing bag identities need review.');$existing[(string)$row['id']]=$row;}
    $seen=[];
    foreach($incoming as $row){
        if(!is_array($row)||!isset($row['id'])||(string)$row['id']===''||isset($seen[(string)$row['id']]))throw new DomainException('Bag identities must be unique.');
        $id=(string)$row['id'];$seen[$id]=true;
        if(isset($existing[$id])){if(!operations_same_value($existing[$id],$row))throw new DomainException('New Export Bags Edit permission is required.');continue;}
        $fields=['id','brand','size','tare','supplier','ordered','mill','received','quality'];
        if(array_diff(array_keys($row),$fields)||!operations_same_value($row['ordered']??null,0)||!operations_same_value($row['received']??null,0)||trim((string)($row['brand']??''))==='')throw new DomainException('Production can add only an empty brand placeholder.');
    }
    if(array_diff_key($existing,$seen))throw new DomainException('New Export Bags Edit permission is required to remove bags.');
}

/** A linked sale/expense grant permits its derived cash row, not the cash ledger. */
function operations_validate_mill_cash(array $user,string $module,string $key,string $oldJson,string $incomingJson,array $values): void {
    if(!in_array($module,['Mill','Milling'],true)||!in_array($key,['tt30petty','tt33pettyexp'],true)||($user['role']??'')==='Super Admin')return;
    $old=$oldJson===''?[]:json_decode($oldJson,true,512,JSON_THROW_ON_ERROR);
    $incoming=json_decode($incomingJson,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($old)||!array_is_list($old)||!is_array($incoming)||!array_is_list($incoming))throw new DomainException('Cash records must be a list.');
    $index=[];
    foreach($old as $row){if(!is_array($row)||!isset($row['id'])||(!is_string($row['id'])&&!is_int($row['id'])))throw new DomainException('Existing cash identities need review.');$id=(string)$row['id'];if($id===''||isset($index[$id]))throw new DomainException('Existing cash identities need review.');$index[$id]=$row;}
    $create=tt_user_can_module_action($user,'Mill','petty','Create');
    $edit=tt_user_can_module_action($user,'Mill','petty','Edit');
    $sourceKey=$key==='tt30petty'?'tt37usedbags':'tt37processingexpenses';
    $sourceIcon=$key==='tt30petty'?'oldbags':'labour';
    $linkedCreate=tt_user_can_module_action($user,'Mill',$sourceIcon,'Create');
    $sources=$linkedCreate&&!$create?json_decode((string)($values[$sourceKey]??'[]'),true,512,JSON_THROW_ON_ERROR):[];
    if(!is_array($sources)||!array_is_list($sources))throw new DomainException('The source cash workflow needs review.');
    $seen=[];$vouchers=[];
    foreach($old as $row)if(isset($row['voucher']))$vouchers[(string)$row['voucher']]=true;
    foreach($incoming as $row){
        if(!is_array($row)||!isset($row['id'])||(!is_string($row['id'])&&!is_int($row['id'])))throw new DomainException('Each cash row needs a stable identity.');
        $id=(string)$row['id'];if($id===''||isset($seen[$id]))throw new DomainException('Cash identities must be unique.');$seen[$id]=true;
        if(isset($index[$id])){if(!operations_same_value($row,$index[$id])&&!$edit)throw new DomainException('Petty Cash Edit permission is required.');continue;}
        if($create)continue;
        if(!$linkedCreate||isset($vouchers[(string)($row['voucher']??'')]))throw new DomainException('Petty Cash Create permission is required.');
        $matched=false;
        foreach($sources as $source){
            if(!is_array($source)||!isset($source['id']))continue;
            if($key==='tt30petty'){
                if(($source['movement']??'')!=='Outward'||($source['type']??'')!=='Sale'||!is_numeric($source['qty']??null)||!is_numeric($source['rate']??null)||(float)$source['qty']<=0||(float)$source['rate']<=0)continue;
                $expected=['id'=>$row['id'],'date'=>$source['date']??'','credit'=>(float)$source['qty']*(float)$source['rate'],'ref'=>'Cash Received from Sale of Used Bags — '.(($source['source']??'')==='arrival'?'Arrival / Pohanch Stock':'Outside-Source Stock'),'voucher'=>'UB-'.substr((string)$source['id'],-6)];
            }else{
                if(($source['payment']??'')!=='Petty Cash'||!is_numeric($source['amount']??null)||(float)$source['amount']<=0)continue;
                $expected=['id'=>$row['id'],'date'=>$source['date']??'','amount'=>$source['amount'],'type'=>($source['type']??'')==='Plant Expense'?'Plant Expense':'Milling Expense','ref'=>(string)($source['ref']??'').' — '.((string)($source['party']??'')!==''?$source['party']:($source['type']??'')),'voucher'=>'PE-'.substr((string)$source['id'],-6)];
            }
            if(operations_same_value($row,$expected)){$matched=true;break;}
        }
        if(!$matched)throw new DomainException('Save the matching source sale or expense before its cash entry.');
        $vouchers[(string)$row['voucher']]=true;
    }
    if(!$edit)foreach($index as $id=>$row)if(!isset($seen[$id]))throw new DomainException('Petty Cash Edit permission is required to remove a cash row.');
}

// JSON booleans must not compare equal to positive stock quantities. Accept
// equivalent JSON numbers and object key ordering without coercing other types.
function operations_same_value(mixed $left, mixed $right): bool {
    if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) return $left == $right;
    if (!is_array($left) || !is_array($right)) return $left === $right;
    if (count($left) !== count($right)) return false;
    foreach ($left as $key => $value) if (!array_key_exists($key, $right) || !operations_same_value($value, $right[$key])) return false;
    return true;
}

function operations_export_row_deleted(array $row, string|Closure $rootJson): bool {
    if($rootJson instanceof Closure)$rootJson=$rootJson();
    $root=json_decode($rootJson,true);if(!is_array($root))return false;
    $ref=(string)($row['contractRef']??$row['_ttContractRef']??'');
    if($ref==='')return false;
    foreach((array)($root['shipments']??[]) as $shipment) if(is_array($shipment)&&($shipment['contractRef']??'')===$ref)return false;
    foreach((array)($root['deletedShipments']??[]) as $deleted) if(is_array($deleted)&&empty($deleted['restoredAt'])&&($deleted['contractRef']??'')===$ref&&!empty($deleted['deletedAt']))return true;
    return false;
}

// Exports revises instructions. Milling owns receipts, production, allocation
// links and containers. Check existing rows under the version/write lock.
function operations_validate_export_bridge(string $key, string $oldJson, string $incomingJson, string $module, string|Closure $rootJson = ''): void {
    if ($module !== 'Exports') return;
    // Loading feeds are readable by Exports, but only an acknowledged shipment
    // deletion may remove their rows. They are never editable or creatable here.
    if (in_array($key, ['tt32exportsync','tt35exload'], true)) {
        $old=$oldJson===''?[]:json_decode($oldJson,true);$incoming=json_decode($incomingJson,true);
        if(!is_array($old)||!array_is_list($old)||!is_array($incoming)||!array_is_list($incoming))throw new DomainException('Loading records must be a list.');
        foreach($incoming as $row){$found=false;foreach($old as $i=>$previous)if(operations_same_value($row,$previous)){$found=true;unset($old[$i]);break;}if(!$found)throw new DomainException('Exports cannot change Milling loading records.');}
        foreach($old as $row)if(!is_array($row)||!operations_export_row_deleted($row,$rootJson))throw new DomainException('Save the shipment deletion before removing loading records.');
        return;
    }
    $fields = [
        'tt30bags' => 'id _ttBridge _ttBridgeId contractRef poNo supplier mill artworkName artworkData status poLineKey isMasterBag brand size tare ordered',
        'tt30prodinst' => 'id _ttBridge _ttBridgeId contractRef ref brand variety productIdentityCode baseVariety riceType productStage displayName quality requiredMT packing requiredBy broken moisture chalky damage foreign polish special bagSupplier inspection dpp sentAt',
        'tt30ship' => 'id _ttBridge _ttBridgeId _ttBridgeIndex _ttShipmentId _ttLotId contractRef ref entity commodity looseBulk packingType productIdentityCode baseVariety riceType productStage displayName contractContainers brand shipping mill totalContainers bagsPerContainer bagSize dryon craft inspection dpp emptyRequired sentAt reportedBrandKg',
        'tt40exinstructions' => 'id _ttBridge _ttBridgeId _ttBridgeIndex _ttShipmentId legacySodaId entity commodity looseBulk packingType mill commercialProduct productIdentityCode baseVariety riceType productStage displayName brand instructionQtyKg quality packing special bagTare dryon craft inspection dpp lotRef shipmentId contractContainers totalContainers contractRef sentAt brokenGrade',
        'tt35exmill' => '', // Only retirement of old pseudo-SODAs is supported.
    ];
    if (!array_key_exists($key, $fields)) return;
    $old = $oldJson === '' ? [] : json_decode($oldJson, true); $incoming = json_decode($incomingJson, true);
    if (!is_array($old) || !array_is_list($old) || !is_array($incoming) || !array_is_list($incoming)) throw new DomainException('Instruction records must be a list.');
    $defaults = [
        'tt30bags' => ['received'=>0], 'tt30prodinst' => ['producedMT'=>0],
        'tt30ship' => ['containers'=>[],'audit'=>[],'status'=>'Open','recovery'=>0.60,'byProducts'=>['B2'=>0.32,'CSR'=>0.03,'Powder'=>0.03,'Other'=>0.02]],
        'tt40exinstructions' => ['sourceSodaId'=>'','loadingComplete'=>false,'completedAt'=>''], 'tt35exmill' => [],
    ][$key];
    $allowed = $fields[$key] === '' ? [] : explode(' ', $fields[$key]); $existing = [];
    foreach ($old as $row) {
        if (!is_array($row) || !isset($row['id']) || isset($existing[(string)$row['id']])) throw new DomainException('Existing instruction identity needs review.');
        $existing[(string)$row['id']] = $row;
    }
    $seen = [];
    foreach ($incoming as $row) {
        if (!is_array($row) || !isset($row['id']) || (string)$row['id'] === '' || isset($seen[(string)$row['id']])) throw new DomainException('Instruction identity must be unique.');
        $id = (string)$row['id']; $seen[$id] = true; $previous = $existing[$id] ?? null;
        if ($previous !== null && ($previous['_ttBridge'] ?? '') !== 'exports') {
            if (!operations_same_value($row, $previous)) throw new DomainException('Exports cannot change a Milling record.');
            continue;
        }
        if ($key === 'tt35exmill') {
            if ($previous === null || !operations_same_value($row, $previous)) throw new DomainException('Exports cannot create or change a Milling SODA.');
            continue;
        }
        if (($row['_ttBridge'] ?? '') !== 'exports') throw new DomainException('Exports may only write its own instructions.');
        foreach (array_unique(array_merge(array_keys($row), array_keys($previous ?? []))) as $field) {
            if (in_array($field, $allowed, true)) continue;
            $expected = $previous !== null && array_key_exists($field,$previous) ? $previous[$field] : ($defaults[$field] ?? null);
            if (!array_key_exists($field, $row) || !operations_same_value($row[$field], $expected) || ($previous === null && !array_key_exists($field, $defaults))) throw new DomainException('Saved Milling details cannot be changed by Exports.');
        }
    }
    foreach ($existing as $id => $row) {
        if (isset($seen[$id])) continue;
        if (operations_export_row_deleted($row,$rootJson)) continue;
        if (($row['_ttBridge'] ?? '') !== 'exports') throw new DomainException('Exports cannot remove a Milling record.');
        if ($key !== 'tt35exmill' && (!empty($row['containers']) || !empty($row['received']) || !empty($row['producedMT']) || !empty($row['loadingComplete']) || !empty($row['sourceSodaId']))) throw new DomainException('An instruction with saved Milling activity cannot be removed here.');
    }
}

/** The stored target decides Master Create/Edit; a module write never grants setup authority. */
function operations_validate_setup_actions(array $user,string $module,string $key,string $oldJson,string $nextJson): void {
    if(!in_array($module,['Mill','Milling'],true)||($user['role']??'')==='Super Admin'||operations_same_value(json_decode($oldJson,true),json_decode($nextJson,true)))return;
    $setup=['tt30arrivaldefaults'=>'purchase_products','tt30mills'=>'mills','tt38costmaster'=>'reference_lists','tt38labourrates'=>'reference_lists','tt39rentmaster'=>'reference_lists','tt39salarymaster'=>'salary_staff'];
    if(!isset($setup[$key]))return;
    $old=$oldJson===''?[]:json_decode($oldJson,true,512,JSON_THROW_ON_ERROR);$next=json_decode($nextJson,true,512,JSON_THROW_ON_ERROR);
    $require=static function(string $action)use($user,$setup,$key){if(!tt_user_can_master($user,$setup[$key],$action))throw new DomainException('Master '.$action.' permission is required.');
        if(in_array($key,['tt38costmaster','tt39rentmaster','tt39salarymaster'],true)&&(!tt_user_can_module_action($user,'Accounts','expenses',$action)||!tt_user_can_access_entity($user,'TTI',$action)))throw new DomainException('Accounts Expenses and company permission are required for financial setup.');};
    if(array_is_list($next)&&array_is_list($old)){
        $index=[];foreach($old as $r)if(is_array($r)&&isset($r['id']))$index[(string)$r['id']]=$r;
        $seen=[];foreach($next as $r){if(!is_array($r)||!isset($r['id'])||isset($seen[(string)$r['id']]))throw new DomainException('Setup identities must be unique.');$id=(string)$r['id'];$seen[$id]=true;if(!isset($index[$id]))$require('Create');elseif(!operations_same_value($index[$id],$r))$require('Edit');}
        if(array_diff_key($index,$seen))$require('Edit');
    }else $require($oldJson===''?'Create':'Edit');
    if($key==='tt30mills'){
        $canonical=[];foreach((array)(tt_list_masters()['mills']??[]) as $r)$canonical[(string)($r['id']??'')]=(string)($r['values'][0]??'');
        foreach($next as $r){if(isset($index[(string)$r['id']])&&operations_same_value($index[(string)$r['id']],$r))continue;
            if(($r['id']??null)===1&&($r['name']??'')==='TTI Rice Mills'&&($r['type']??'')==='Own / Default')continue;
            $master=(string)($r['masterId']??'');if($master===''||!isset($canonical[$master])||$canonical[$master]!==($r['name']??'')||array_diff(array_keys($r),['id','masterId','name','type']))throw new DomainException('Save this Mill in the canonical Master Records first.');
        }
    }
}

/** Read acknowledgements belong only to the authenticated operator and visible instructions. */
function operations_instruction_ack(array $user,string $key,string $oldJson,string $nextJson,array $values): string {
    if($key!=='tt40instructionseen')return $nextJson;
    $old=$oldJson===''?[]:json_decode($oldJson,true,512,JSON_THROW_ON_ERROR);$next=json_decode($nextJson,true,512,JSON_THROW_ON_ERROR);
    $name=strtolower(trim((string)($user['full_name']??$user['username']??'')));if($name==='')throw new DomainException('An authenticated operator is required.');
    foreach(array_unique(array_merge(array_keys($old),array_keys($next))) as $person)if($person!==$name&&!operations_same_value($old[$person]??null,$next[$person]??null))throw new DomainException('Only your own instruction acknowledgements may change.');
    $visible=os_project($user,$values)['values'];$keys=[];
    foreach(['tt30prodinst'=>'P|','tt30ship'=>'L|','tt40exinstructions'=>'L|'] as $source=>$prefix)foreach(json_decode((string)($visible[$source]??'[]'),true) as $r)if(($r['_ttBridge']??'')==='exports')$keys[$prefix.(string)($r['_ttBridgeId']??$r['id']??'')]=true;
    foreach((array)($next[$name]??[]) as $id=>$stamp){if(operations_same_value($old[$name][$id]??null,$stamp))continue;if(!isset($keys[$id])||!is_string($stamp)||($time=strtotime($stamp))===false||abs(time()-$time)>300)throw new DomainException('A current visible instruction acknowledgement is required.');$next[$name][$id]=gmdate('c');}
    return json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function operations_trip_identity(array $r): string {
    return strtoupper(trim((string)($r['entity']??'TTI'))).'|'.strtolower(trim((string)($r['millName']??$r['locationName']??'TTI Rice Mills'))).'|'.(string)($r['date']??'').'|'.strtoupper((string)preg_replace('/[^A-Za-z0-9]/','',(string)($r['vehicle']??$r['truck']??'')));
}
/** An operator requests a decision; only an actual Director/SA can decide it. */
function operations_duplicate_prepare(array $user,string $key,string $oldJson,string $nextJson,array $values): string {
    if($key!=='tt30directoralerts')return $nextJson;
    $old=$oldJson===''?[]:json_decode($oldJson,true,512,JSON_THROW_ON_ERROR);$next=json_decode($nextJson,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($old)||!array_is_list($old)||!is_array($next)||!array_is_list($next))throw new DomainException('Duplicate-truck requests must be a list.');
    $index=[];foreach($old as $r)$index[(string)($r['id']??'')]=$r;$seen=[];$out=[];
    foreach($next as $r){$id=(string)($r['id']??'');if($id===''||isset($seen[$id]))throw new DomainException('Duplicate-truck request identities must be unique.');$seen[$id]=true;$before=$index[$id]??null;
        if($before&&operations_same_value($before,$r)){$out[]=$before;continue;}
        if($before){
            if(!in_array($user['role']??'',['Director','Super Admin'],true)||($before['status']??'')!=='Pending Director Approval'||!in_array($r['status']??'',['Approved Once','Rejected'],true))throw new DomainException('A pending request requires Director or Super Admin approval.');
            $plain=$r;$plainBefore=$before;unset($plain['status'],$plain['decisionReason'],$plainBefore['status'],$plainBefore['decisionReason']);
            $reason=trim((string)($r['decisionReason']??''));if(!operations_same_value($plain,$plainBefore)||strlen($reason)<5||strlen($reason)>500)throw new DomainException('Review the unchanged request and enter a decision reason.');
            $decision=$r['status'];$r=$before;$r['status']=$decision;$r['decisionReason']=$reason;$r['approvedBy']=(string)($user['full_name']??$user['username']??'');$r['approvedByUser']=(int)($user['id']??0);$r['approvedAt']=gmdate('c');
        }else{
            if(!tt_user_can_module_action($user,'Mill','queue','Create')&&!tt_user_can_module_action($user,'Mill','arrival','Create'))throw new DomainException('Arrival request Create permission is required.');
            if(($r['status']??'')!=='Pending Director Approval'||array_diff(array_keys($r),['id','entity','millId','millName','locationId','locationName','date','truck','reason','status']))throw new DomainException('New requests must await a Director decision.');
            $match=null;foreach(json_decode((string)($values['tt30queue']??'[]'),true) as $q)if(operations_trip_identity($q)===operations_trip_identity($r)&&os_scope($user,$q,os_root($values))){$match=$q;break;}
            if(!$match)throw new DomainException('A duplicate request must identify the existing visible truck and date.');
            $r=array_replace($r,['entity'=>$match['entity']??'TTI','locationName'=>$match['millName']??$match['locationName']??'TTI Rice Mills','requestedBy'=>(string)($user['full_name']??$user['username']??''),'requestedByUser'=>(int)($user['id']??0),'requestedAt'=>gmdate('c')]);
        }
        $out[]=$r;
    }
    if(array_diff_key($index,$seen))throw new DomainException('Duplicate-truck approval history cannot be removed.');
    return json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
/** Consume the one-time decision atomically with the accepted second trip. */
function operations_duplicate_effects(array $before,array $next,array $user,string $key): array {
    if($key!=='tt30queue')return $next;
    $old=json_decode((string)($before[$key]??'[]'),true);$rows=json_decode((string)($next[$key]??'[]'),true);$alerts=json_decode((string)($before['tt30directoralerts']??'[]'),true);$oldById=[];$trips=[];$changed=false;
    foreach($old as $r){$oldById[(string)($r['id']??'')]=$r;$trips[operations_trip_identity($r)]=true;}
    foreach($rows as $r){$prior=$oldById[(string)($r['id']??'')]??null;$trip=operations_trip_identity($r);if($prior&&operations_trip_identity($prior)===$trip)continue;
        if(isset($trips[$trip])){$found=false;foreach($alerts as &$a)if(($a['status']??'')==='Approved Once'&&operations_trip_identity($a)===$trip){$a['status']='Approval Used';$a['usedAt']=gmdate('c');$a['usedByUser']=(int)($user['id']??0);$a['usedQueueId']=(string)($r['id']??'');$found=$changed=true;break;}unset($a);if(!$found)throw new DomainException('This truck already has a trip on that date. Refresh and obtain a one-time Director approval.');}
        $trips[$trip]=true;
    }
    $audit=json_decode((string)($before['tt37arrivalaudit']??'[]'),true);$auditChanged=false;
    $slips=json_decode((string)($before['tt30slips']??'[]'),true);
    foreach($rows as$r){$prior=$oldById[(string)($r['id']??'')]??null;if(!$prior||operations_same_value($prior,$r))continue;
        $linked=array_values(array_filter($slips,static fn($slip)=>(string)($slip['queueId']??'')===(string)$r['id']));if(!$linked)continue;
        $audit[]=['id'=>'QUEUE|'.$r['id'].'|'.hash('sha256',json_encode($r)), 'queueId'=>$r['id'],'time'=>gmdate('c'),'before'=>$prior,'after'=>$r,'linkedPohanch'=>array_column($linked,'slip'),'completedHistoryPreserved'=>true,'directorAlert'=>true,'userId'=>(int)($user['id']??0)];$auditChanged=true;
    }
    if($auditChanged)$next['tt37arrivalaudit']=json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($changed)$next['tt30directoralerts']=json_encode($alerts,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return $next;
}

/** Mill consumes Export instructions; a Mill icon cannot rewrite their canonical terms. */
function operations_validate_instruction_owner(string $module,string $key,string $oldJson,string $nextJson): void {
    if(in_array($module,['Mill','Milling'],true)&&$key==='tt30prodinst'&&!operations_same_value(json_decode($oldJson?:'[]',true),json_decode($nextJson,true)))throw new DomainException('Revise production instructions in the Exports Production workspace.');
}

/** Existing rows require Edit, while new rows require Create on their actual workflow. */
function operations_validate_row_actions(array $user,string $module,string $key,string $oldJson,string $nextJson): void {
    if(!in_array($module,['Mill','Milling'],true)||($user['role']??'')==='Super Admin')return;
    if($key==='tt30bags'&&!operations_icon_write($user,'Mill','newbags'))return; // Production's empty-placeholder-only exception is checked separately.
    $icons=['tt30bags'=>'newbags','tt30queue'=>'queue','tt30slips'=>'arrival','tt37arrivalaudit'=>'arrival','tt30prod'=>'production','tt30prodaudit'=>'production','tt35brandmeta'=>'production','tt36bagissues'=>'production','tt32stockadj'=>'production','tt32labourfeed'=>'production','tt33bagreceipts'=>'newbags','tt37usedbags'=>'oldbags','tt37processingexpenses'=>'labour','tt38kebills'=>'labour','tt38labourbills'=>'labour','tt39rentpayments'=>'labour','tt39salaryadvances'=>'labour','tt38reprocessbills'=>'reprocessbill','tt35exportersale'=>'local','tt35localsales'=>'local','tt30ship'=>'export','tt35exload'=>'export','tt40exinstructions'=>'export','tt32exportsync'=>'export','tt35exmill'=>'export'];
    if(!isset($icons[$key]))return;
    $public=tt_inv_public_values([$key=>$oldJson?:'[]'])['values'];
    $old=json_decode($public[$key]??'[]',true,512,JSON_THROW_ON_ERROR);$next=json_decode($nextJson,true,512,JSON_THROW_ON_ERROR);if(operations_same_value($old,$next))return;
    $require=static function(string $action,array $row)use($user,$icons,$key){if(!tt_user_can_module_action($user,'Mill',$icons[$key],$action))throw new DomainException('This workflow requires '.$action.' permission.');
        foreach(['entity-tti','entity-brm','entity-tg'] as $e)if(array_key_exists($e,(array)($user['permissions']['Accounts']??[]))){if(!tt_user_can_access_entity($user,(string)($row['entity']??'TTI'),$action))throw new DomainException('Company '.$action.' permission is required.');break;}};
    if(!is_array($old)||!is_array($next)||!array_is_list($old)||!array_is_list($next))throw new DomainException('Operational rows must retain their list shape.');
    $index=[];$seen=[];foreach($old as $r){$id=os_identity($r);if(isset($index[$id]))throw new DomainException('Existing operational identities need review.');$index[$id]=$r;}
    foreach($next as $r){if(!is_array($r))throw new DomainException('Operational rows must be records.');$id=os_identity($r);if(isset($seen[$id]))throw new DomainException('Operational identities must be unique.');$seen[$id]=true;if(!isset($index[$id]))$require('Create',$r);elseif(!operations_same_value($index[$id],$r)){$require('Edit',$index[$id]);$require('Edit',$r);}}
    foreach(array_diff_key($index,$seen) as $r)$require('Edit',$r);
}

/** Legacy ambiguity evidence copies canonical physical facts and never accepts invented containers. */
function operations_validate_quarantine(array $user,string $key,string $oldJson,string $nextJson,array $values): void {
    if($key!=='tt39bridgequarantine')return;
    $old=json_decode($oldJson?:'[]',true,512,JSON_THROW_ON_ERROR);$next=json_decode($nextJson,true,512,JSON_THROW_ON_ERROR);$index=[];$seen=[];
    foreach($old as $r)$index[(string)($r['key']??'')]=$r;
    foreach($next as $r){$id=(string)($r['key']??'');if($id===''||isset($seen[$id]))throw new DomainException('Quarantine evidence identities must be unique.');$seen[$id]=true;
        if(isset($index[$id])){if(!operations_same_value($index[$id],$r))throw new DomainException('Saved quarantine evidence cannot be changed.');continue;}
        if(!in_array($user['role']??'',['Director','Super Admin'],true))throw new DomainException('Legacy loading identity needs Director or Super Admin review.');
        if(array_diff(array_keys($r),['key','at','reason','contractRef','lotRef','containers']))throw new DomainException('Quarantine evidence must copy the original physical source.');
        $matched=false;
        foreach(json_decode((string)($values['tt30ship']??'[]'),true) as $source){$expected='MILL|'.($source['id']??'').'|'.($source['_ttBridgeId']??'').'|'.($source['contractRef']??'').'|'.($source['_ttLotId']??$source['ref']??'');if($id===$expected&&operations_same_value($r['containers']??[], $source['containers']??[])&&($r['contractRef']??'')===($source['contractRef']??'')&&($r['lotRef']??'')===($source['_ttLotId']??$source['ref']??''))$matched=true;}
        foreach(json_decode((string)($values['tt35exload']??'[]'),true) as $source){$expected='EXMILL|'.($source['id']??'').'|'.($source['sodaId']??'').'|'.($source['container']??'');if($id===$expected&&operations_same_value($r['containers']??[],[$source])&&($r['contractRef']??'')===($source['contractRef']??'')&&($r['lotRef']??'')===($source['lotRef']??$source['shipment']??''))$matched=true;}
        if(!$matched)throw new DomainException('Quarantine evidence does not match a canonical physical source.');
    }
    if(array_diff_key($index,$seen))throw new DomainException('Saved quarantine evidence cannot be removed.');
}
