<?php
declare(strict_types=1);
// Inventory only. No deletion, extraction, application includes or private-store reads.
function ri_archive(string $path): bool {
    $pipes=[];$process=proc_open(['tar','-tzf',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))return false;
    $names=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if($exit!==0||strlen($names)>4194304)return false;
    $seen=[];
    foreach(explode("\n",trim($names))as$name){
        if(str_starts_with($name,'/'))return false;
        while(str_starts_with($name,'./'))$name=substr($name,2);
        if($name==='')continue;
        if(in_array('..',explode('/',$name),true)||in_array(explode('/',$name)[0],['data','uploads','storage','cache','logs','transtrade_private'],true))return false;
        $seen[rtrim($name,'/')]=true;
    }
    foreach(['.htaccess','auth_store.php','login.php','module.php','exports/app.js']as$name)if(!isset($seen[$name]))return false;
    return true;
}
function ri_inventory(string $directory): array {
    if(!is_dir($directory)||is_link($directory)||realpath($directory)!==$directory)throw new RuntimeException('UNSAFE_BACKUP_DIRECTORY');
    $rows=[];$ignored=0;
    foreach(new DirectoryIterator($directory)as$item){
        if($item->isDot())continue;
        if($item->isLink()||!$item->isFile()||!preg_match('/^before-[a-f0-9]{40}-[0-9]{8}T[0-9]{6}Z\.tgz$/D',$item->getFilename())){$ignored++;continue;}
        $rows[]=['name'=>$item->getFilename(),'bytes'=>$item->getSize(),'modifiedAt'=>$item->getMTime()];
    }
    usort($rows,static fn($a,$b)=>$a['modifiedAt']<=>$b['modifiedAt']?:strcmp($a['name'],$b['name']));
    $inspect=static function(array $row)use($directory):array{$row['sha256']=hash_file('sha256',$directory.'/'.$row['name']);$row['completeCodeArchive']=ri_archive($directory.'/'.$row['name']);return $row;};
    $candidates=array_map($inspect,array_slice($rows,0,min(20,max(0,count($rows)-30))));
    $baselines=array_map($inspect,array_reverse(array_slice($rows,-3)));
    $valid=$candidates!==[]&&count($baselines)===3;
    foreach(array_merge($candidates,$baselines)as$row)if(!$row['completeCodeArchive'])$valid=false;
    return ['readOnly'=>true,'knownCodeBackups'=>count($rows),'totalBytes'=>array_sum(array_column($rows,'bytes')),'unknownEntriesRetained'=>$ignored,'proposedOldestCount'=>count($candidates),'proposedBytes'=>array_sum(array_column($candidates,'bytes')),'retainedKnownCodeBackups'=>count($rows)-count($candidates),'verifiedLatestBaselines'=>$baselines,'candidates'=>$candidates,'candidateSetSha256'=>hash('sha256',json_encode($candidates,JSON_THROW_ON_ERROR)),'proposalVerified'=>$valid,'filesDeleted'=>0];
}
