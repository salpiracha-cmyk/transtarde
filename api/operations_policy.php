<?php
declare(strict_types=1);

// Source permissions and target ownership are separate checks. Both storage
// backends enforce this policy before a backup or any durable write.
function operations_validate_key_module(array $user, string $module, string $key): void {
    $module = $module === 'Milling' ? 'Mill' : $module;
    if ($module === 'Super Admin' && ($user['role'] ?? '') !== 'Super Admin') throw new DomainException('Only Super Admin may use this save route.');
    if (in_array($key, ['tt34ghati','tt34nilqueue','tt32processingrecon','tt40exportreceipts'], true)) throw new DomainException('This record is maintained by the server.');
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
    if(isset($icons[$key])&&!operations_icon_write($user,$module,$icons[$key]))throw new DomainException('Write permission for the selected workflow is required.');
    if($module==='Mill'&&$key==='tt37users')throw new DomainException('Only Super Admin may change user settings.');
    $cashIcons=['tt30petty'=>['petty','oldbags'],'tt33pettyexp'=>['petty','labour']];
    if($module==='Mill'&&isset($cashIcons[$key])){
        foreach($cashIcons[$key] as $icon) foreach(['Create','Edit'] as $action)
            if(tt_user_can_module_action($user,'Mill',$icon,$action))return;
        throw new DomainException('Permission for the cash entry workflow is required.');
    }
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
        'tt30ship' => 'id _ttBridge _ttBridgeId _ttBridgeIndex _ttShipmentId _ttLotId contractRef ref productIdentityCode baseVariety riceType productStage displayName contractContainers brand shipping mill totalContainers bagsPerContainer bagSize dryon craft inspection dpp emptyRequired sentAt reportedBrandKg',
        'tt40exinstructions' => 'id _ttBridge _ttBridgeId _ttBridgeIndex _ttShipmentId legacySodaId entity mill commercialProduct productIdentityCode baseVariety riceType productStage displayName brand instructionQtyKg quality packing special bagTare dryon craft inspection dpp lotRef shipmentId contractContainers totalContainers contractRef sentAt brokenGrade',
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
