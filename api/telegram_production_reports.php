<?php
declare(strict_types=1);
require_once __DIR__.'/mill_recovery_core.php';

/** Return reports only for completed Export lots with explicitly assigned, completed shifts. */
function tt_telegram_production_reports(array $root, array $runs, int $since, ?array $stockValues=null): iterable {
    $byLot = [];
    $unlinked = [];
    foreach ($runs as $run) {
        $id = (string)($run['shipmentLotId'] ?? '');
        if ($id !== '') {
            $byLot[$id][] = $run;
            continue;
        }
        foreach (mr_output_rows($run) as $row) {
            if ($row['kind'] === 'finished') $unlinked[$row['contractRef']][$run['entity'] ?? 'TTI'][$row['brand']] = true;
        }
    }
    $lotsByContract = [];
    foreach ($root['shipments'] ?? [] as $lot) {
        if (($lot['kind'] ?? '') === 'lot' && empty($lot['cancelled']) && !empty($lot['id']) && !empty($lot['contractRef'])) {
            $lotsByContract[$lot['contractRef']][] = $lot;
        }
    }
    foreach ($root['contracts'] ?? [] as $contract) {
        $ref = (string)($contract['ref'] ?? '');
        $lots = $lotsByContract[$ref] ?? [];
        if (!$lots || !empty($contract['cancelled'])) continue;
        $qualified = [];
        $latest = 0;
        foreach ($lots as $lot) {
            if (($lot['completed'] ?? false) !== true) continue;
            $completed = strtotime((string)($lot['completedAt'] ?? '')) ?: 0;
            $latest = max($latest, $completed);
            $linked = $byLot[$lot['id']] ?? [];
            if (!$linked) continue;
            foreach ($linked as &$run) {
                if (mr_is_calculated($run) && empty($run['shiftEntriesComplete'])) {
                    unset($run);
                    continue 2;
                }
                $run['outputLotRef'] = (string)($lot['lotId'] ?? $lot['id']);
            }
            unset($run);
            $qualified[(string)$lot['id']] = $linked;
            if ($completed < $since) continue;
            foreach (['TTI','BRM'] as $entity) {
                $report = tt_telegram_production_report($linked, $entity, $ref, stockValues:$stockValues);
                if ($report !== null) {
                    yield ['key' => ['lot',$entity,$lot['id']], 'title' => 'Lot Production Recovery',
                        'ref' => $ref.' - '.($lot['lotId'] ?? $lot['id']), 'report' => $report];
                }
            }
        }
        if ($latest < $since || ($contract['status'] ?? '') !== 'Completed' || count($qualified) !== count($lots)) continue;
        $combined = array_merge(...array_values($qualified));
        foreach (['TTI','BRM'] as $entity) {
            $whole = tt_telegram_production_report($combined, $entity, $ref, stockValues:$stockValues);
            foreach ($whole['brands'] ?? [] as $brand) {
                if ($brand === '' || isset($unlinked[$ref][$entity][$brand])) continue;
                $report = tt_telegram_production_report($combined, $entity, $ref, $brand, $stockValues);
                if ($report !== null) {
                    yield ['key' => ['brand',$entity,$contract['id'] ?? $ref,$brand], 'title' => 'Combined Brand Production Recovery',
                        'ref' => $ref.' - '.$brand, 'report' => $report];
                }
            }
        }
    }
}

function tt_telegram_production_report(array $runs, string $entity, string $contract, string $brand = '', ?array $stockValues=null): ?array {
    $report = mr_report($runs, ['entity'=>$entity, 'mode'=>'daily', 'contract'=>$contract, 'brand'=>$brand],stockValues:$stockValues);
    if (($report['totals']['readyKg'] ?? 0) <= 0) return null;
    $dates = array_column($report['groups'], 'key');
    $report['filters']['from'] = min($dates);
    $report['filters']['to'] = max($dates);
    return $report;
}
