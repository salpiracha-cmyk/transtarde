<?php
declare(strict_types=1);

/**
 * Retired September test-data migration. Kept as a harmless compatibility entry
 * point for older callers; never replay a destructive cleanup after a restore.
 * Existing owner_master_migrations and audit history remain untouched.
 */
function tt_apply_owner_master_cleanup(): array {
    return ['changed'=>false,'deleted'=>0];
}
