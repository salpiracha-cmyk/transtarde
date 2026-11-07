<?php
declare(strict_types=1);
$root=getenv('TT_OFFICE_ASYNC_ROOT')?:'';
if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$root)||is_link($root)||realpath($root)!==$root)throw new RuntimeException('Unexpected target');
$private=dirname($root).'/transtrade_private';$work=dirname($root).'/.transtrade-deploy/office-background-backup';
if(is_link($private)||!is_dir($private)||is_link(dirname($work))||is_link($work)||(!is_dir($work)&&!mkdir($work,0700)))throw new RuntimeException('Unsafe deploy directories');
$package=json_decode(base64_decode('__ASYNC_PACKAGE__',true)?:'',true,512,JSON_THROW_ON_ERROR);
$expected=['api/office_backup.php'=>'3d576c323c7e308ee373518583f101cd7059c572c7865e2ae7a38408fe8cfeb4','office_backup_jobs.php'=>null];
if(array_keys($package)!==array_keys($expected))throw new RuntimeException('Unexpected package');
if(!hash_equals('b774c1b6551dad0426a7d43fc843caad6eaa0863f8c362f0f2cf7090ef4f8414',hash_file('sha256',$root.'/backup_lib.php')))throw new RuntimeException('Backup library changed');
foreach($package as$rel=>$entry){$p=$root.'/'.$rel;$source=base64_decode($entry['source'],true);if(!is_string($source)||!hash_equals($entry['sha256'],hash('sha256',$source))||is_link($p)||realpath(dirname($p))!==dirname($p))throw new RuntimeException('Invalid package');if(file_exists($p)&&!in_array(hash_file('sha256',$p),array_filter([$expected[$rel],$entry['sha256']]),true))throw new RuntimeException('Installed code changed');}
$installed=[];$canary='';$token=bin2hex(random_bytes(32));$password=bin2hex(random_bytes(24));
function async_http(string $url,string $token,array $body):array {
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept-Encoding: identity\r\nX-TT-Backup-Token: ".$token."\r\n",'content'=>json_encode($body,JSON_THROW_ON_ERROR),'timeout'=>30,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
 $start=microtime(true);$raw=file_get_contents($url,false,$ctx,0,4194305);$headers=$http_response_header??[];
 if($raw===false)throw new RuntimeException('HTTPS transfer failed');
 preg_match('~HTTP/\S+ (\d+)~',$headers[0]??'',$match);$status=(int)($match[1]??0);
 $out=[];foreach($headers as$h)if(str_contains($h,':')){[$k,$v]=explode(':',$h,2);$out[strtolower(trim($k))]=trim($v);}
 return [$status,$out,$raw,microtime(true)-$start];
}
try{
 foreach($package as$rel=>$entry){$live=$root.'/'.$rel;if(is_file($live)&&hash_equals(hash_file('sha256',$live),$entry['sha256']))continue;
  $old=is_file($live)?$work.'/previous-'.str_replace('/','-',$rel):null;if($old!==null){if(is_link($old)||!copy($live,$old))throw new RuntimeException('Cannot preserve code');chmod($old,0600);}
  $tmp=tempnam(dirname($live),'.office-async-');if(!$tmp)throw new RuntimeException('Cannot stage code');try{$source=base64_decode($entry['source'],true);if(file_put_contents($tmp,$source)!==strlen($source)||!hash_equals($entry['sha256'],hash_file('sha256',$tmp)))throw new RuntimeException('Cannot verify code');chmod($tmp,0644);if(!rename($tmp,$live))throw new RuntimeException('Cannot install code');$installed[$rel]=$old;}finally{if(is_file($tmp))unlink($tmp);}
 }
 // Expiring random canary tests the actual API/controller and real encrypted
 // business archive through HTTPS without rotating the office's saved token.
 $canary=$root.'/.office-async-check-'.bin2hex(random_bytes(16)).'.php';
 $api=base64_decode($package['api/office_backup.php']['source'],true);
 $validation='function tt_office_backup_token_valid(string $value):bool{return time()<'.(time()+1200).' && hash_equals('.var_export(hash('sha256',$token),true).',hash("sha256",$value));}';
 $api=str_replace("require dirname(__DIR__) . '/office_backup_auth.php';",$validation,$api);
 $api=str_replace('dirname(__DIR__)',var_export($root,true),$api);
 $h=fopen($canary,'x');if(!$h)throw new RuntimeException('Cannot create expiring canary');try{if(fwrite($h,$api)!==strlen($api))throw new RuntimeException('Cannot write canary');}finally{fclose($h);}chmod($canary,0644);
 $url='https://app.transtradeinternational.com/'.basename($canary);$body=['password'=>$password];
 [$status,$headers,$raw,$seconds]=async_http($url.'?action=prepare',$token,$body);$m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 if(!in_array($status,[200,202],true)||!($m['ok']??false)||$seconds>=15)throw new RuntimeException('Preparation did not respond promptly');
 echo 'ASYNC_PREPARATION_ACCEPTED seconds='.round($seconds,2)."\n";flush();
 $deadline=time()+900;$started=time();
 while(($m['state']??'')==='preparing'){
  if(time()>$deadline)throw new RuntimeException('Background preparation timed out');sleep(5);
  [$status,$headers,$raw]=async_http($url.'?action=status',$token,$body+['job'=>$m['job']]);$m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
  if($status!==200||!($m['ok']??false))throw new RuntimeException('Background status failed');
 }
 if(($m['state']??'')!=='ready')throw new RuntimeException('Background archive verification failed');
 echo 'ASYNC_RECOVERY_READY seconds='.(time()-$started).' bytes='.$m['length']."\n";flush();
 $digest=hash_init('sha256');$received=0;
 while($received<$m['length']){
  $length=min(4194304,$m['length']-$received);
  [$status,$headers,$raw]=async_http($url.'?action=chunk',$token,$body+['job'=>$m['job'],'offset'=>$received,'length'=>$length]);
  if($status!==200||strlen($raw)!==$length||(int)($headers['content-length']??0)!==$length||($headers['content-encoding']??'identity')!=='identity'||!hash_equals((string)($headers['x-backup-chunk-sha256']??''),hash('sha256',$raw)))throw new RuntimeException('HTTPS piece verification failed');
  hash_update($digest,$raw);$received+=strlen($raw);
 }
 if(!hash_equals($m['sha256'],hash_final($digest)))throw new RuntimeException('Full HTTPS transfer checksum mismatch');
 [$status,$headers,$raw]=async_http($url.'?action=complete',$token,$body+['job'=>$m['job'],'sha256'=>$m['sha256']]);$ack=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 if($status!==200||!($ack['ok']??false))throw new RuntimeException('Transfer cleanup acknowledgement failed');
 echo 'ASYNC_HTTPS_RECOVERY_TRANSFER_VERIFIED bytes='.$received.' sha256='.$m['sha256']." restores=0 live_files_deleted=0\n";
}catch(Throwable $error){foreach(array_reverse($installed,true)as$rel=>$old){$p=$root.'/'.$rel;if($old===null){if(is_file($p))unlink($p);}else{$tmp=tempnam(dirname($p),'.async-rollback-');if($tmp&&copy($old,$tmp)){chmod($tmp,0644);rename($tmp,$p);}if($tmp&&is_file($tmp))unlink($tmp);}}throw $error;}
finally{if($canary!==''&&is_file($canary))unlink($canary);}
