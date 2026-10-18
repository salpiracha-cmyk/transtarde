<?php
declare(strict_types=1);

/** Authentication alone does not grant access to shared application records. */
function operations_can_read(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    foreach (['Mill', 'Exports', 'Accounts', 'Directors'] as $module) {
        if (tt_user_can_open_module($user, $module)) return true;
    }
    return false;
}
