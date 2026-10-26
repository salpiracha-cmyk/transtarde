<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-office-jobs-'.bin2hex(random_bytes(8));mkdir($root.'/app',0700,true);mkdir($root.'/private',0700);
define('TT_DATA_DIR',$root.'/app/../private');define('TT_STORE_FILE',TT_DATA_DIR.'/auth.json');
function tt_ensure_data_dir():void{}
require __DIR__.'/../../backup_lib.php';require __DIR__.'/../../office_backup_jobs.php';
function ensure_job(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function clean_job(string $p):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')clean_job($p.'/'.$n);rmdir($p);}else unlink($p);}
$password='test-password-123';
function job_fixture(string $path,string $password,bool $tampered=false):array{
 $z=new TT_SimpleZipWriter($path);$salt=random_bytes(16);$key=tt_backup_derive_key($password,$salt,200000);$map=[];
 foreach(['System_Recovery/private/auth.json'=>'{"users":[]}','System_Recovery/private/fixture.json'=>str_repeat('original fixture ',10000),'Documents/fixture.pdf'=>random_bytes(1000)]as$name=>$plain){
  $e=tt_backup_encrypt($plain,$key,true);$stored='Encrypted/'.str_pad((string)(count($map)+1),6,'0',STR_PAD_LEFT).'.bin';$cipher=$e['cipher'];unset($e['cipher']);
  if($tampered&&count($map)===2)$cipher[0]=chr(ord($cipher[0])^1);
  $z->addString($stored,$cipher,false);$e['stored']=$stored;$map[$name]=$e;
 }
 $m=['schema'=>3,'application'=>'Transtrade','type'=>'full_recovery','createdAt'=>gmdate('c'),'encryption'=>['algorithm'=>'AES-256-GCM','salt'=>base64_encode($salt),'iterations'=>200000],'encryptedEntries'=>$map];$z->addString('backup-manifest.json',json_encode($m));$z->close();return ['path'=>$path];
}
try{
 $lock=tt_office_job_lock(LOCK_EX);ensure_job(is_resource($lock)&&tt_office_job_lock(LOCK_EX)===null,'no duplicate worker');
 $m=['id'=>str_repeat('a',32),'client'=>str_repeat('b',64),'state'=>'preparing','stage'=>'building','createdAt'=>time()];tt_office_job_write($m);
 ensure_job(tt_office_job_matches($m,$m['id'],$m['client']),'matching identity');ensure_job(!tt_office_job_matches($m,'../escape',$m['client']),'reject traversal');ensure_job(!tt_office_job_matches($m,$m['id'],str_repeat('c',64)),'reject another client');
 $build=static fn($pw)=>job_fixture($root.'/candidate.zip',$pw);
 // An old complete transfer must survive until every batch of the replacement verifies.
 $target=tt_office_job_dir().'/transfer.zip';file_put_contents($target,'previous verified archive sentinel');
 $staged=tt_office_job_generate($m,$password,$build);ensure_job($staged['state']==='preparing'&&$staged['stage']==='verifying','generation leaves bounded verification for status polls');
 ensure_job(file_get_contents($target)==='previous verified archive sentinel','unverified candidate cannot displace previous');
 $first=tt_office_job_verify_step($staged,$password,1);ensure_job($first['verifiedFiles']===1&&$first['state']==='preparing','exact cursor after one fully checked file');
 ensure_job(file_get_contents($target)==='previous verified archive sentinel','first batch cannot publish partial ZIP');
 $second=tt_office_job_verify_step($first,$password,1);ensure_job($second['verifiedFiles']===2&&$second['state']==='preparing','second batch resumes');
 $ready=tt_office_job_verify_step($second,$password,1);ensure_job($ready['state']==='ready'&&$ready['verifiedFiles']===3,'all files checked before atomic promotion');
 $payload=file_get_contents($target);ensure_job(tt_office_job_chunk($ready,0,100)===substr($payload,0,100),'exact bounded chunk');
 ensure_job(tt_backup_verify_full($target,$password)['application']==='Transtrade','published archive passes independent complete decrypt/hash verification');
 foreach([[-1,1],[0,4194305],[strlen($payload),1]]as[$off,$len]){$failed=false;try{tt_office_job_chunk($ready,$off,$len);}catch(InvalidArgumentException){$failed=true;}ensure_job($failed,'reject invalid ranges');}
 ensure_job(!array_key_exists('client',tt_office_job_public($ready))&&!array_key_exists('identity',tt_office_job_public($ready)),'public metadata excludes secrets/paths');
 $staged=tt_office_job_generate($m,$password,static fn($pw)=>job_fixture($root.'/bad.zip',$pw,true));
 $partial=tt_office_job_verify_step($staged,$password,2);ensure_job($partial['state']==='preparing','tamper in a later batch must not be skipped');
 $failed=tt_office_job_verify_step($partial,$password,1);ensure_job($failed['state']==='failed'&&file_get_contents($target)===$payload,'late authentication failure preserves previous archive');
 $staged=tt_office_job_generate($m,$password,$build);$candidate=tt_office_job_dir().'/candidate.zip';file_put_contents($candidate,'changed');
 ensure_job(tt_office_job_verify_step($staged,$password)['state']==='failed'&&file_get_contents($target)===$payload,'changed candidate is refused');
 $staged=tt_office_job_generate($m,$password,$build);ensure_job(tt_office_job_verify_step($staged,'wrong-password-123')['state']==='failed','wrong password refused');
 ensure_job(!is_file($root.'/candidate.zip')&&!is_file(tt_office_job_dir().'/incoming.tmp'),'cleanup own generation work');
 file_put_contents($target,'changed');$failed=false;try{tt_office_job_chunk($ready,0,10);}catch(RuntimeException){$failed=true;}ensure_job($failed,'refuse changed transfer');
 flock($lock,LOCK_UN);fclose($lock);echo "OFFICE_BACKGROUND_BATCH_VERIFICATION_OK\n";
}finally{clean_job($root);}
