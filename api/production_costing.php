<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__.'/production_costing_core.php';

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) pc_out(['ok' => false, 'error' => 'Accounts permission required.'], 403);

    $entity = strtoupper(trim((string)($_GET['entity'] ?? 'TTI')));
    if ($entity === '') $entity = 'TTI';
    if (!in_array($entity, ['TTI', 'BRM'], true)) {
        pc_out(['ok' => true, 'entity' => $entity, 'groups' => [], 'sourceCosts' => [], 'message' => 'Production stock costing is not used for TG.']);
    }

    pc_require_entity($user,$entity,false);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $store = pc_read_accounts();
        $ops = pc_read_operations();
        $calc = pc_calculate($store, $ops, $entity);
        pc_out(['ok' => true, 'entity' => $entity] + $calc + ['serverNow' => gmdate('c')]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') pc_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    if (!pc_can_write($user)) pc_out(['ok' => false, 'error' => 'Accounts approval permission required.'], 403);

    $body = json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input')) ?: '{}', true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) pc_out(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    $action = (string)($body['action'] ?? '');

    tt_ensure_data_dir();
    $h = fopen(TT_PC_ACCOUNTS, 'c+');
    if ($h === false || !flock($h, LOCK_EX)) throw new RuntimeException('Accounts storage unavailable.');
    try {
        rewind($h);
        $raw = stream_get_contents($h);
        $store=mr_decode_store((string)$raw,pc_accounts_default());
        $store = array_replace_recursive(pc_accounts_default(), $store);
        $ops = pc_read_operations();

        if ($action === 'save_byproduct_value') {
            $entity = pc_entity((string)($body['entity'] ?? ''));
            pc_require_entity($user,$entity,true);
            $product = trim((string)($body['product'] ?? ''));
            if ($product === '') pc_out(['ok' => false, 'error' => 'By-product is required.'], 422);
            $effectiveFrom = pc_date((string)($body['effectiveFrom'] ?? ''));
            $cost = round((float)($body['costPerKg'] ?? 0), 6);
            if ($cost < 0) pc_out(['ok' => false, 'error' => 'By-product value cannot be negative.'], 422);
            $id = pc_next((array)$store['inventoryCostRates'], 'ICR');
            $store['inventoryCostRates'][$id] = [
                'id' => $id,
                'entity' => $entity,
                'product' => $product,
                'effectiveFrom' => $effectiveFrom,
                'costPerKg' => $cost,
                'inventoryAccount' => '1330',
                'status' => 'Active',
                'source' => 'ACCOUNTS_BYPRODUCT_VALUATION',
                'notes' => 'Accounts by-product stock value used by automatic production costing.',
                'createdAt' => gmdate('c'),
                'createdBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Accounts'),
            ];
            $result = ['rateId' => $id];
        } elseif ($action === 'approve_rate') {
            $entity = pc_entity((string)($body['entity'] ?? ''));
            pc_require_entity($user,$entity,true);
            if (($body['costsReviewed'] ?? false) !== true) pc_out(['ok' => false, 'error' => 'Confirm that Accounts checked the purchase and production-cost figures first.'], 422);
            $groupId = trim((string)($body['groupId'] ?? ''));
            $calc = pc_calculate($store, $ops, $entity);
            $group = null;
            foreach ($calc['groups'] as $candidate) {
                if (($candidate['id'] ?? '') === $groupId) { $group = $candidate; break; }
            }
            if (!$group) pc_out(['ok' => false, 'error' => 'Production cost calculation was not found.'], 404);
            if (empty($group['readyToApprove'])) pc_out(['ok' => false, 'error' => (string)($group['status'] ?? 'This cost is not ready to approve.')], 422);
            if (isset($store['productionCostRuns'][$groupId])) pc_out(['ok' => false, 'error' => 'This production cost has already been approved.'], 409);

            if(!hash_equals((string)$group['reviewFingerprint'],(string)($body['expectedCostFingerprint']??'')))pc_out(['ok'=>false,'error'=>'Production quantities or source costs changed. Reopen and review this calculation before approval.'],409);
            $costPerKg = round((float)$group['suggestedReadyRiceCostPerKg'], 6);
            if ($costPerKg <= 0) pc_out(['ok' => false, 'error' => 'Calculated Ready Rice cost is invalid.'], 422);
            $rateId = pc_next((array)$store['inventoryCostRates'], 'ICR');
            $aliases = [];
            foreach ((array)$group['outputs'] as $output) if (!empty($output['ready'])) $aliases[] = (string)$output['product'];
            $calculation = [
                'groupId' => $groupId,'quantityBasis'=>'ACTUAL_INPUT','costFingerprint'=>$group['reviewFingerprint'],'productionFingerprint'=>$group['productionFingerprint'],'runIds'=>$group['runIds'],'byProducts'=>$group['byProducts'],'conversionSources'=>$group['conversionSources'],'conversionCostPerKg'=>$group['conversionCostPerKg'],
                'rawAverageCostPerKg' => $group['rawAverageCostPerKg'],
                'rawCost' => $group['rawCost'],
                'conversionCost' => $group['conversionCost'],
                'byProductValue' => $group['byProductValue'],
                'readyRiceCost' => $group['readyRiceCost'],
                'inputKg' => $group['inputKg'],
                'readyKg' => $group['readyKg'],
                'usesProvisionalPurchaseCost' => false,
            ];
            $store['inventoryCostRates'][$rateId] = [
                'id' => $rateId,
                'entity' => $entity,
                'product' => (string)$group['variety'],
                'aliases' => array_values(array_unique($aliases)),
                'effectiveFrom' => (string)$group['date'],
                'costPerKg' => $costPerKg,
                'inventoryAccount' => '1320',
                'status' => 'Active',
                'source' => 'AUTO_PRODUCTION_COST',
                'notes' => 'Automatically calculated from finalized purchase stock cost plus saved production costs, less saved by-product values.',
                'calculation' => $calculation,
                'createdAt' => gmdate('c'),
                'createdBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Accounts'),
            ];
            $store['productionCostRuns'][$groupId] = [
                'id' => $groupId,
                'entity' => $entity,
                'date' => $group['date'],
                'variety' => $group['variety'],
                'rateId' => $rateId,
                'costPerKg' => $costPerKg,
                'calculation' => $calculation,
                'costsReviewed' => true,
                'approvedAt' => gmdate('c'),
                'approvedBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Accounts'),
            ];
            $result = ['rateId' => $rateId, 'costPerKg' => $costPerKg];
        } else {
            pc_out(['ok' => false, 'error' => 'Unknown production costing action.'], 422);
        }

        $store['revision'] = (int)($store['revision'] ?? 0) + 1;
        $encoded = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        rewind($h);
        if (!ftruncate($h, 0)) throw new RuntimeException('Accounts storage could not be updated.');
        if (fwrite($h, $encoded) === false) throw new RuntimeException('Accounts storage could not be updated.');
        fflush($h);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }

    pc_out(['ok' => true, 'result' => $result, 'revision' => $store['revision']]);
} catch (Throwable $e) {
    pc_out(['ok' => false, 'error' => 'Production stock cost could not be calculated.'], 500);
}

