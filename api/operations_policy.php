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

function operations_export_row_deleted(array $row, string $rootJson): bool {
    $root=json_decode($rootJson,true);if(!is_array($root))return false;
    $ref=(string)($row['contractRef']??$row['_ttContractRef']??'');
    if($ref==='')return false;
    foreach((array)($root['shipments']??[]) as $shipment) if(is_array($shipment)&&($shipment['contractRef']??'')===$ref)return false;
    foreach((array)($root['deletedShipments']??[]) as $deleted) if(is_array($deleted)&&empty($deleted['restoredAt'])&&($deleted['contractRef']??'')===$ref&&!empty($deleted['deletedAt']))return true;
    return false;
}

// Exports revises instructions. Milling owns receipts, production, allocation
// links and containers. Check existing rows under the version/write lock.
function operations_validate_export_bridge(string $key, string $oldJson, string $incomingJson, string $module, string $rootJson = ''): void {
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
