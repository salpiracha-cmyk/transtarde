<?php
// Read-only, standalone inspection: never include application/bootstrap code.
declare(strict_types=1);
$root = getenv('TT_OFFICE_BACKUP_DIAGNOSTIC_ROOT') ?: '';
if (getenv('TT_OFFICE_BACKUP_FIXTURE') !== '1' && !preg_match('~^/home/[a-zA-Z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D', $root)) throw new RuntimeException('Unexpected target');
if (!is_dir($root) || is_link($root) || realpath($root) !== $root) throw new RuntimeException('Unsafe target');
$private = dirname($root) . '/transtrade_private';
if (!is_dir($private) || is_link($private) || realpath($private) !== $private) throw new RuntimeException('Unsafe private root');
$out = ['mode'=>'read_only', 'utc'=>gmdate('c'), 'phpVersion'=>PHP_VERSION, 'zipAesAvailable'=>class_exists('ZipArchive')&&defined('ZipArchive::EM_AES_256'), 'cliLimits'=>[], 'installedCode'=>[], 'sourceGroups'=>[], 'temporaryArchives'=>['count'=>0,'bytes'=>0], 'logSignals'=>[], 'webOverrides'=>[]];
foreach (['memory_limit','max_execution_time','output_buffering','zlib.output_compression'] as $key) $out['cliLimits'][$key] = ini_get($key);
foreach (['api/office_backup.php','backup_lib.php'] as $path) if (is_file($root.'/'.$path) && !is_link($root.'/'.$path)) $out['installedCode'][$path] = hash_file('sha256', $root.'/'.$path);
$out['temporaryFreeBytes'] = disk_free_space(sys_get_temp_dir());
$out['privateFreeBytes'] = disk_free_space($private);
foreach (['private'=>$private,'application'=>$root] as $scope=>$folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $rel = str_replace('\\','/',substr($file->getPathname(),strlen($folder)+1));
        if ($scope==='private' && (preg_match('~^(backups|sessions)/~',$rel) || $rel==='backup_state.json' || str_ends_with($rel,'.lock') || str_contains($rel,'.tmp-') || str_contains($rel,'.restore-'))) continue;
        if ($scope==='application' && (preg_match('~^(\.git|node_modules)/|(^|/)(cache|tmp)/~i',$rel) || preg_match('/\.(log|tmp)$/i',$rel))) continue;
        $group = $scope.'_'.(preg_match('/\.(json|txt|csv|sql|log)([.-]|$)/i',$rel)?'text':(preg_match('/\.(png|jpe?g|webp|pdf|docx|xlsx)$/i',$rel)?'documents':'other'));
        if (!isset($out['sourceGroups'][$group])) $out['sourceGroups'][$group] = ['files'=>0,'bytes'=>0,'largestBytes'=>0];
        $out['sourceGroups'][$group]['files']++;
        $out['sourceGroups'][$group]['bytes'] += $file->getSize();
        $out['sourceGroups'][$group]['largestBytes'] = max($out['sourceGroups'][$group]['largestBytes'],$file->getSize());
    }
}
foreach (glob(sys_get_temp_dir().'/tt-backup-*') ?: [] as $path) if (is_file($path) && !is_link($path)) {
    $out['temporaryArchives']['count']++;
    $out['temporaryArchives']['bytes'] += filesize($path);
}
$patterns = ['memory_exhausted'=>'/allowed memory size.*exhausted/i', 'execution_timeout'=>'/maximum execution time|request.*timeout|gateway timeout/i', 'disk_full'=>'/no space left on device|not enough.*space/i', 'backup_generation_failure'=>'/Transtrade office backup failed/i', 'zip_limit'=>'/ZIP.*(limit|large|overflow)/i'];
$logs = array_merge([$root.'/error_log',$root.'/error.log'],glob(dirname($root).'/logs/*error*') ?: [],glob(dirname($root).'/logs/*php*') ?: []);
$seen = 0;
foreach (array_unique($logs) as $path) {
    if (++$seen > 12) break;
    if (!is_file($path) || is_link($path)) continue;
    $handle = fopen($path,'rb'); if ($handle===false) continue;
    try { $size = filesize($path); if ($size>262144) fseek($handle,$size-262144); $tail = stream_get_contents($handle,262144); }
    finally { fclose($handle); }
    foreach ($patterns as $key=>$pattern) $out['logSignals'][$key] = ($out['logSignals'][$key]??0) + preg_match_all($pattern,$tail ?: '');
}
foreach ([$root.'/.user.ini', $root.'/.htaccess', $root.'/api/.user.ini'] as $path) {
    if (!is_file($path) || is_link($path)) continue;
    $raw = file_get_contents($path,false,null,0,65536) ?: '';
    preg_match_all('/^\s*(?:php_value\s+)?(memory_limit|max_execution_time|output_buffering|zlib\.output_compression)\s*[=\s]\s*["\x27]?([0-9A-Za-z_-]+)\s*["\x27]?\s*$/m',$raw,$matches,PREG_SET_ORDER);
    foreach ($matches as $match) $out['webOverrides'][$match[1]] = $match[2];
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
