<?php
declare(strict_types=1);
require __DIR__.'/original_source_match.php';
$root=sys_get_temp_dir().'/tt-source-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/exports');
$a=str_repeat('a',40);$b=str_repeat('b',40);
$files=['login.php'=>'login','auth_store.php'=>'old-auth','session_store.php'=>'old-session','exports/app.js'=>'exports'];
$hashes=[];foreach($files as$name=>$bytes){file_put_contents($root.'/'.$name,$bytes);$hashes[$name]=hash('sha256',$bytes);}
$next=$hashes;$next['auth_store.php']=hash('sha256','new-auth');$next['session_store.php']=hash('sha256','new-session');
function check(bool $value,string $label): void {if(!$value)throw new RuntimeException($label);}
function fails(callable $fn,string $kind): void {try{$fn();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$kind),'wrong failure: '.$e->getMessage());return;}throw new RuntimeException('missing '.$kind);}
try{
    check(os_match($root,[$a=>$next,$b=>$hashes],true)['matchedSource']===$b,'whole old baseline');
    file_put_contents($root.'/auth_store.php','new-auth');
    fails(fn()=>os_match($root,[$a=>$next,$b=>$hashes],true),'SOURCE_MISMATCH');
    file_put_contents($root.'/session_store.php','new-session');
    check(os_match($root,[$a=>$next,$b=>$hashes],true)['matchedSource']===$a,'whole new baseline');
    $before=[];foreach($files as$name=>$unused)$before[$name]=hash_file('sha256',$root.'/'.$name);
    os_match($root,[$a=>$next],true);
    foreach($before as$name=>$hash)check(hash_file('sha256',$root.'/'.$name)===$hash,'unchanged bytes');
    unlink($root.'/exports/app.js');symlink($root.'/login.php',$root.'/exports/app.js');
    fails(fn()=>os_match($root,[$a=>$next],true),'SOURCE_MISMATCH');
    unlink($root.'/exports/app.js');rmdir($root.'/exports');mkdir($root.'/actual');file_put_contents($root.'/actual/app.js','exports');symlink($root.'/actual',$root.'/exports');
    fails(fn()=>os_match($root,[$a=>$next],true),'SOURCE_MISMATCH');
    $unsafe=$next;$unsafe['../private.json']=str_repeat('f',64);
    fails(fn()=>os_match($root,[$a=>$unsafe],true),'UNSAFE_MANIFEST_PATH');
    $unsafe=$next;$unsafe['data/ledger.json']=str_repeat('f',64);
    fails(fn()=>os_match($root,[$a=>$unsafe],true),'UNSAFE_MANIFEST_PATH');
    $bad=$next;$bad['login.php']='bad';fails(fn()=>os_match($root,[$a=>$bad],true),'INVALID_HASH');
    fails(fn()=>os_match($root,[$a=>[]],true),'INVALID_MANIFEST');
    fails(fn()=>os_match($root,[$a=>$next,$b=>array_merge($next,['extra.php'=>str_repeat('a',64)])],true),'DIFFERENT_FILE_SETS');
    fails(fn()=>os_match($root,[$a=>$next]),'UNEXPECTED_TARGET');
    echo "PASS exact complete old/new source, mixed-source hold, file/parent symlinks, private/traversal/hash/empty/set rejection and zero-write source bytes\n";
}finally{
    if(is_link($root.'/exports'))unlink($root.'/exports');else{if(is_file($root.'/exports/app.js')||is_link($root.'/exports/app.js'))unlink($root.'/exports/app.js');rmdir($root.'/exports');}
    if(is_dir($root.'/actual')){unlink($root.'/actual/app.js');rmdir($root.'/actual');}
    foreach(['login.php','auth_store.php','session_store.php']as$name)unlink($root.'/'.$name);rmdir($root);
}
