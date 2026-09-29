<?php
declare(strict_types=1);

function tt_office_backup_token_file(): string { return TT_DATA_DIR . '/office-backup-token.sha256'; }
function tt_office_backup_enabled(): bool { return is_file(tt_office_backup_token_file()); }

function tt_office_backup_issue_token(): string {
    tt_ensure_data_dir();
    $token = bin2hex(random_bytes(32));
    $path = tt_office_backup_token_file();
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    try {
        if (file_put_contents($temp, hash('sha256', $token), LOCK_EX) !== 64) throw new RuntimeException('The office backup credential could not be saved.');
        @chmod($temp, 0600);
        if (!rename($temp, $path)) throw new RuntimeException('The office backup credential could not be activated.');
    } finally { if (is_file($temp)) @unlink($temp); }
    return $token;
}

function tt_office_backup_revoke_token(): void {
    $path = tt_office_backup_token_file();
    if (is_file($path) && !unlink($path)) throw new RuntimeException('The office backup credential could not be revoked.');
}

function tt_office_backup_token_valid(string $token): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $stored = @file_get_contents(tt_office_backup_token_file());
    return is_string($stored) && preg_match('/^[a-f0-9]{64}$/D', $stored) === 1
        && hash_equals($stored, hash('sha256', $token));
}
