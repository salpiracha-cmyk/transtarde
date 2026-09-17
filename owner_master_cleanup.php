<?php
declare(strict_types=1);

/**
 * Owner-requested one-time master-data cleanup.
 *
 * Operational modules are still under testing. The Super Admin remains the
 * final authority over Master Console records and may add, edit or delete them
 * regardless of module lock status. This cleanup clears only the current
 * Parties master once; it does not touch customers, companies, products,
 * locations, users, permissions or operational transactions.
 */
function tt_apply_owner_master_cleanup(): array {
    $marker = 'clear_parties_20260917';
    $store = tt_read_store();
    $migrations = is_array($store['owner_master_migrations'] ?? null) ? $store['owner_master_migrations'] : [];
    if (!empty($migrations[$marker])) {
        return ['changed'=>false,'deleted'=>0];
    }

    return tt_mutate_store(function (&$data) use ($marker): array {
        if (!isset($data['owner_master_migrations']) || !is_array($data['owner_master_migrations'])) {
            $data['owner_master_migrations'] = [];
        }
        if (!empty($data['owner_master_migrations'][$marker])) {
            return ['changed'=>false,'deleted'=>0];
        }

        if (!isset($data['masters']) || !is_array($data['masters'])) $data['masters'] = [];
        $deleted = count((array)($data['masters']['parties'] ?? []));
        $data['masters']['parties'] = [];
        $data['owner_master_migrations'][$marker] = [
            'applied_at'=>gmdate('c'),
            'deleted_records'=>$deleted,
            'scope'=>'parties',
        ];
        if (!isset($data['audit']) || !is_array($data['audit'])) $data['audit'] = [];
        array_unshift($data['audit'],[
            'user_id'=>null,
            'username'=>'Super Admin',
            'action'=>'Cleared Parties master test data by owner instruction',
            'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'=>gmdate('c'),
        ]);
        return ['changed'=>true,'deleted'=>$deleted];
    });
}
