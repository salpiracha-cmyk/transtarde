<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';

$apply=in_array('--apply',$argv,true);
$store=tt_read_store();
$preview=$store;
$changes=tt_migrate_legacy_master_permissions($preview);

if (!$apply) {
    echo json_encode(['ok'=>true,'mode'=>'preview','changedUserCount'=>count($changes)],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

if (is_file(TT_STORE_FILE)) {
    $backupDir=TT_DATA_DIR.'/security-migrations';
    if (!is_dir($backupDir) && !mkdir($backupDir,0700,true) && !is_dir($backupDir)) throw new RuntimeException('Migration backup folder could not be created.');
    $backup=$backupDir.'/auth-before-master-permissions-'.gmdate('Ymd\THis\Z').'.json';
    if (!copy(TT_STORE_FILE,$backup)) throw new RuntimeException('The pre-migration access backup could not be created.');
    @chmod($backup,0600);
}

$applied=tt_mutate_store(static function (&$data): array {
    if ((int)($data['settings']['master_permission_schema'] ?? 0)>=2) return [];
    $changed=tt_migrate_legacy_master_permissions($data);
    if ($changed) {
        if (!isset($data['audit']) || !is_array($data['audit'])) $data['audit']=[];
        array_unshift($data['audit'],[
            'user_id'=>null,
            'username'=>'System',
            'action'=>'Preserved existing staff Master Records access as explicit permission matrices for '.count($changed).' account(s)',
            'ip_address'=>'Production deployment',
            'created_at'=>gmdate('c'),
        ]);
    }
    return $changed;
});

echo json_encode(['ok'=>true,'mode'=>'applied','changedUserCount'=>count($applied)],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
