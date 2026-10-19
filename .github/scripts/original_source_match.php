<?php
declare(strict_types=1);
// Read-only CLI source acceptance. Never includes deployed application code.
function os_match(string $target, array $manifests, bool $fixture=false): array {
    if (!$fixture && !preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~D', $target)) throw new RuntimeException('UNEXPECTED_TARGET');
    if (is_link($target) || !is_dir($target) || realpath($target)!==$target) throw new RuntimeException('UNSAFE_TARGET');
    if (!$manifests || count($manifests)>2) throw new RuntimeException('INVALID_MANIFESTS');
    $keys=null; $actual=[];
    foreach ($manifests as $sha=>$manifest) {
        if (!preg_match('/^[a-f0-9]{40}$/D',(string)$sha) || !is_array($manifest) || count($manifest)<4 || count($manifest)>2000) throw new RuntimeException('INVALID_MANIFEST');
        foreach (['login.php','auth_store.php','session_store.php','exports/app.js'] as $required) if (!isset($manifest[$required])) throw new RuntimeException('INCOMPLETE_MANIFEST');
        $names=array_keys($manifest); sort($names);
        if ($keys!==null && $keys!==$names) throw new RuntimeException('DIFFERENT_FILE_SETS');
        $keys=$names;
        foreach ($manifest as $name=>$hash) {
            if (!is_string($name) || !preg_match('~^[A-Za-z0-9_. /-]+$~D',$name) || str_starts_with($name,'/') || array_intersect(explode('/',$name),['','..','.']) || in_array(explode('/',$name)[0],['.git','.github','tests','docs','data','uploads','storage','cache','logs','transtrade_private'],true)) throw new RuntimeException('UNSAFE_MANIFEST_PATH');
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new RuntimeException('INVALID_HASH');
        }
    }
    foreach ($keys as $name) {
        $path=$target.'/'.$name;
        // Reject symlink files and parent directories, even when their target is inside root.
        $resolved=realpath($path);
        $actual[$name]=!is_link($path) && is_file($path) && $resolved===$path ? hash_file('sha256',$path) : false;
    }
    $failures=[];
    foreach ($manifests as $sha=>$manifest) {
        $missing=[];
        foreach ($manifest as $name=>$hash) if (!is_string($actual[$name]) || !hash_equals($hash,$actual[$name])) $missing[]=$name;
        if (!$missing) return ['matchedSource'=>$sha,'verifiedApplicationFiles'=>count($manifest),'businessFilesWritten'=>0];
        $failures[$sha]=array_slice($missing,0,12);
    }
    throw new RuntimeException('SOURCE_MISMATCH '.json_encode($failures,JSON_THROW_ON_ERROR));
}
function os_match_main(string $target,array $manifests): void {
    if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
    try {echo json_encode(os_match($target,$manifests),JSON_THROW_ON_ERROR)."\n";}
    catch(Throwable $error) {fwrite(STDERR,$error->getMessage()."\n");exit(1);}
}
