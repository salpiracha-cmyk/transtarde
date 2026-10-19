<?php
declare(strict_types=1);
require __DIR__.'/login_rollback_inventory.php';
$root=sys_get_temp_dir().'/tti-rollback-inventory-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/backups');mkdir($root.'/source');mkdir($root.'/source/exports');
try{
    foreach(['.htaccess','auth_store.php','login.php','module.php','exports/app.js']as$name)file_put_contents($root.'/source/'.$name,'code fixture');
    for($i=1;$i<=52;$i++){
        $name='before-'.str_pad(dechex($i),40,'0',STR_PAD_LEFT).'-20261019T120000Z.tgz';
        $pipes=[];$p=proc_open(['tar','-czf',$root.'/backups/'.$name,'-C',$root.'/source','.'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);foreach($pipes as$pipe)fclose($pipe);if(proc_close($p)!==0)throw new RuntimeException('fixture tar failed');touch($root.'/backups/'.$name,1000+$i);
    }
    file_put_contents($root.'/backups/unknown.txt','retain');symlink($root.'/backups/unknown.txt',$root.'/backups/symlink.tgz');
    $before=[];foreach(glob($root.'/backups/*')as$p)if(is_file($p))$before[basename($p)]=hash_file('sha256',$p);
    $out=ri_inventory($root.'/backups');
    if(!$out['proposalVerified']||$out['proposedOldestCount']!==20||$out['retainedKnownCodeBackups']!==32||$out['unknownEntriesRetained']!==2||$out['filesDeleted']!==0)throw new RuntimeException('bounded proposal mismatch');
    foreach($before as$name=>$hash)if(hash_file('sha256',$root.'/backups/'.$name)!==$hash)throw new RuntimeException('inventory mutated original');
    file_put_contents($root.'/source/data-secret','fixture');mkdir($root.'/source/data');file_put_contents($root.'/source/data/accounts.json','private fixture');
    $pipes=[];$p=proc_open(['tar','-czf',$root.'/unsafe.tgz','-C',$root.'/source','.'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);foreach($pipes as$pipe)fclose($pipe);proc_close($p);
    if(ri_archive($root.'/unsafe.tgz'))throw new RuntimeException('Archive containing business data must not be proposed');
    echo "PASS read-only exact oldest-20 proposal, retained newest baselines, gzip/tar completeness, source markers, private-data exclusion, unknown/symlink retention and unchanged original hashes\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$item){if($item->isLink()||!$item->isDir())unlink($item->getPathname());else rmdir($item->getPathname());}rmdir($root);}
