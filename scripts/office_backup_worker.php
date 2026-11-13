<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/backup_lib.php';
require dirname(__DIR__).'/office_backup_auth.php';
require dirname(__DIR__).'/office_backup_jobs.php';
require dirname(__DIR__).'/office_backup_worker.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    $password = tt_backup_password_get();
    if ($password === null) throw new RuntimeException('Set the managed recovery password before enabling the worker.');
    if (($argv[1] ?? '') === '--enable') {
        $control = tt_office_worker_control();
        if (time() - (int)($control['heartbeatAt'] ?? 0) > 180) throw new RuntimeException('Observe a scheduled worker heartbeat before enabling.');
        $lock = tt_backup_work_lock();
        if (!$lock) throw new RuntimeException('A backup is active; enable after it finishes.');
        try {
            $locks = [];
            foreach (['full','data','software'] as $scope) {
                $GLOBALS['ttOfficeBackupScope'] = $scope;
                $job = tt_office_job_lock(LOCK_EX);
                if (!$job) throw new RuntimeException('A backup request is active.');
                $locks[] = $job;
                if ((tt_office_job_read()['state'] ?? '') === 'preparing') throw new RuntimeException('Finish the existing backup before switching.');
            }
            $control['enabled'] = true;
            tt_office_worker_control($control);
        } finally {
            foreach ($locks ?? [] as $job) { flock($job, LOCK_UN); fclose($job); }
            flock($lock, LOCK_UN); fclose($lock);
        }
        echo "OFFICE_WORKER_ENABLED\n";
    } elseif (($argv[1] ?? '') === '--run') {
        tt_office_worker_tick($password);
        echo "OFFICE_WORKER_CHECK_COMPLETE\n";
    } else throw new RuntimeException('Use --run or --enable.');
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
