<?php
declare(strict_types=1);
// Exercise the real reader and atomic writer using disposable files, without
// starting a session or touching the application's configured private store.
$root=sys_get_temp_dir().'/tt-read-cache-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
define('TT_STORE_FILE',$root.'/auth.json');
define('TT_STORE_LOCK_FILE',$root.'/auth.lock');
define('TT_DATA_DIR',$root);
$GLOBALS['ttRequestTiming']=['store_wait'=>0,'store_read'=>0,'store_decode'=>0,'store_reads'=>0];
function tt_ensure_data_dir():void {}
function tt_empty_store():array {return [];}
function tt_decode_store(string $raw):array {return json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
function assert_cache(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$source=file_get_contents(__DIR__.'/../../auth_store.php');
foreach(['tt_open_store_lock','tt_write_store_atomic','tt_read_store'] as $name){
    $start=strpos($source,'function '.$name.'(');
    $end=strpos($source,"\nfunction ",$start+1);
    eval(substr($source,$start,$end-$start));
}
try{
    tt_write_store_atomic(['version'=>1,'rows'=>str_repeat('x',3000000)]);
    $start=hrtime(true);$first=tt_read_store();$miss=(hrtime(true)-$start)/1e6;
    $start=hrtime(true);$second=tt_read_store();$hit=(hrtime(true)-$start)/1e6;
    assert_cache($first===$second,'Cached reads differ.');
    assert_cache($GLOBALS['ttRequestTiming']['store_reads']===1,'An unchanged request decodes once.');
    $copy=$second;$copy['version']=100;
    assert_cache(tt_read_store()['version']===1,'A caller modified the cached array.');
    tt_write_store_atomic(['version'=>2,'rows'=>str_repeat('x',3000000)]);
    assert_cache(tt_read_store()['version']===2,'A same-request write was not observed.');
    // Simulate another worker replacing a same-size store within the same second.
    $external=$root.'/external';
    file_put_contents($external,json_encode(['version'=>3,'rows'=>str_repeat('x',3000000)],JSON_PRETTY_PRINT));
    rename($external,TT_STORE_FILE);
    assert_cache(tt_read_store()['version']===3,'A concurrent atomic replacement was not observed.');
    unlink(TT_STORE_FILE);
    assert_cache(tt_read_store()===[],'A deleted store returned stale identities.');
    file_put_contents(TT_STORE_FILE,'damaged');
    $failed=false;try{tt_read_store();}catch(JsonException $e){$failed=true;}
    assert_cache($failed,'Damaged storage returned a cached identity.');
    echo 'PASS request-scoped store reads, fresh writes, concurrent replacement, deletion and damage; 3 MB first read '.round($miss,2).' ms, repeat '.round($hit,2)." ms\n";
}finally{foreach(glob($root.'/*')?:[] as $file)unlink($file);rmdir($root);}

