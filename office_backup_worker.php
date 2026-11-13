<?php
declare(strict_types=1);

// Deployment alone does not switch existing installations. Enable only after
// the CLI worker has been scheduled and observed running on the hosting account.
function tt_office_worker_control(?array $value = null): array {
    tt_backup_ensure_dirs();
    $path = tt_backup_dir().'/.office-worker.json';
    if (is_link($path)) throw new RuntimeException('Unsafe worker control.');
    if ($value === null) {
        if (!is_file($path)) return [];
        return json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }
    $temp = tempnam(tt_backup_dir(), '.worker-');
    if ($temp === false) throw new RuntimeException('Cannot stage worker control.');
    try {
        chmod($temp, 0600);
        $raw = json_encode($value, JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $raw) !== strlen($raw) || !rename($temp, $path)) throw new RuntimeException('Cannot save worker control.');
    } finally { if (is_file($temp)) unlink($temp); }
    return $value;
}

function tt_office_worker_enabled(): bool {
    return (tt_office_worker_control()['enabled'] ?? false) === true;
}

function tt_office_worker_tick(string $password, int $seconds = 40): bool {
    // This global lock covers initialization, building AND verification. Other
    // archive jobs cannot overlap a worker. Never wait on a web request lock.
    $work = tt_backup_work_lock();
    if (!$work) return false;
    $previousScope = $GLOBALS['ttOfficeBackupScope'] ?? 'full';
    try {
        $control = tt_office_worker_control();
        $control['heartbeatAt'] = time();
        tt_office_worker_control($control);
        if (empty($control['enabled'])) return true;
        $deadline = microtime(true) + max(1, min(40, $seconds));
        foreach (['data', 'software'] as $scope) {
            $GLOBALS['ttOfficeBackupScope'] = $scope;
            $job = tt_office_job_lock(LOCK_EX);
            if (!$job) continue;
            try {
                $m = tt_office_job_read();
                if (($m['state'] ?? '') !== 'preparing' || empty($m['worker'])) continue;
                if (($m['stage'] ?? '') === 'queued') {
                    $m = tt_office_job_generate($m, $password);
                }
                while (($m['state'] ?? '') === 'preparing' && microtime(true) < $deadline) {
                    if (($m['stage'] ?? '') === 'building') $m = tt_office_job_build_step($m, $password, 4194304);
                    elseif (($m['stage'] ?? '') === 'verifying') $m = tt_office_job_verify_step($m, $password, 8);
                    else break;
                    // Deliberately yield between batches. This is a workload
                    // reduction, not a promise of an absolute hosting I/O cap.
                    usleep(2000000);
                }
                if (microtime(true) >= $deadline) break;
            } finally { flock($job, LOCK_UN); fclose($job); }
        }
        return true;
    } finally {
        $GLOBALS['ttOfficeBackupScope'] = $previousScope;
        flock($work, LOCK_UN); fclose($work);
    }
}
