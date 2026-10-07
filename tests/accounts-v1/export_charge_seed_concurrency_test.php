<?php
declare(strict_types=1);
const TT_EXPORT_REALIZATION_MASTER_TYPE='export_realization_charges';
$root=$argv[2]??(sys_get_temp_dir().'/tt-charge-seed-'.bin2hex(random_bytes(8)));
$worker=($argv[1]??'')==='--worker';
if(!$worker)mkdir($root,0700,true);
function tt_list_masters():array {
    global $root,$worker;
    $h=fopen($root.'/store','c+');flock($h,LOCK_SH);$data=json_decode(stream_get_contents($h)?:'{}',true)?:[];flock($h,LOCK_UN);fclose($h);
    static $first=true;
    if($worker&&$first){
        $first=false;$barrier=fopen($root.'/barrier','c+');flock($barrier,LOCK_EX);$count=(int)stream_get_contents($barrier)+1;rewind($barrier);ftruncate($barrier,0);fwrite($barrier,(string)$count);flock($barrier,LOCK_UN);fclose($barrier);
        $until=microtime(true)+15;do{clearstatcache(true,$root.'/barrier');$count=(int)file_get_contents($root.'/barrier');if($count>=8)break;usleep(10000);}while(microtime(true)<$until);
        if($count<8)throw new RuntimeException('Worker barrier timed out.');
    }
    return $data['masters']??[];
}
function tt_mutate_store(callable $callback):mixed {
    global $root;
    $h=fopen($root.'/store','c+');flock($h,LOCK_EX);
    try{
        $data=json_decode(stream_get_contents($h)?:'{}',true)?:[];
        $result=$callback($data);$json=json_encode($data,JSON_THROW_ON_ERROR);
        rewind($h);ftruncate($h,0);fwrite($h,$json);fflush($h);return $result;
    }finally{flock($h,LOCK_UN);fclose($h);}
}
$source=file_get_contents(__DIR__.'/../../api/export_realization_master.php');
$start=strpos($source,'function erm_defaults(');$end=strpos($source,'function erm_clean_values(',$start);
eval(substr($source,$start,$end-$start));
if($worker){erm_seed_if_needed(['id'=>0,'username'=>'QA test']);exit;}
function seed_assert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
try{
    file_put_contents($root.'/store',json_encode(['masters'=>[TT_EXPORT_REALIZATION_MASTER_TYPE=>[]],'audit'=>[]]));
    $processes=[];
    for($i=0;$i<8;$i++){
        $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        seed_assert(is_resource($p),'Could not start a seeding worker.');fclose($pipes[0]);$processes[]=[$p,$pipes];
    }
    foreach($processes as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);seed_assert(proc_close($p)===0,'Seeding worker failed: '.$error);}
    $rows=tt_list_masters()[TT_EXPORT_REALIZATION_MASTER_TYPE];
    seed_assert(count($rows)===count(erm_defaults()),'Concurrent first reads created duplicate defaults.');
    seed_assert(count(array_unique(array_column(array_column($rows,'values'),1)))===count($rows),'Duplicate codes were created.');
    $before=file_get_contents($root.'/store');erm_seed_if_needed(['id'=>0,'username'=>'QA test']);
    seed_assert(file_get_contents($root.'/store')===$before,'Unchanged reads rewrote the master.');
    tt_mutate_store(function(&$data):void{
        $rows=&$data['masters'][TT_EXPORT_REALIZATION_MASTER_TYPE];
        $rows[0]['values'][5]='7';$rows[]=$rows[0];$rows[count($rows)-1]['id']='historical-copy';
    });
    $before=tt_list_masters()[TT_EXPORT_REALIZATION_MASTER_TYPE];
    $after=erm_seed_if_needed(['id'=>0,'username'=>'QA test']);
    seed_assert($after===$before,'Existing rates, duplicate historical rows or IDs were removed/changed.');
    echo "PASS eight concurrent default seeders, no-op reads, custom rates and preserved historical rows.\n";
}finally{foreach(glob($root.'/*')?:[]as$file)unlink($file);rmdir($root);}

