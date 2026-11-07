<?php
// Empty, gated loopback probes only. No application, session or business files.
declare(strict_types=1);
umask(0077);
$work=sys_get_temp_dir().'/tt-empty-loopback-'.bin2hex(random_bytes(8));
if(!mkdir($work,0700))throw new RuntimeException('Temporary probe unavailable');
$process=null;$pipes=[];
$stop=static function()use(&$process,&$pipes):void{
 if(is_resource($process)){proc_terminate($process);foreach($pipes as$p)if(is_resource($p))fclose($p);proc_close($process);}
 $process=null;$pipes=[];
};
register_shutdown_function(static function()use($work,$stop):void{
 $stop();foreach(['router.php','server.log']as$name)if(is_file($work.'/'.$name))unlink($work.'/'.$name);rmdir($work);
 echo "EMPTY_LOOPBACK_CLEANUP ".json_encode(['removed'=>!file_exists($work),'applicationFilesRead'=>0,'applicationFilesWritten'=>0])."\n";
});
file_put_contents($work.'/router.php',"<?php if(!hash_equals((string)getenv('TT_EMPTY_GATE'),(string)(\$_SERVER['HTTP_X_TT_EMPTY']??''))){http_response_code(404);exit;} echo '{}';");
$selfServer=@stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
$selfConnect=false;$selfError=0;$portAssigned=false;
if($selfServer){$address=stream_socket_get_name($selfServer,false);$portAssigned=(int)substr(strrchr($address,':'),1)>0;$self=@stream_socket_client('tcp://'.$address,$selfError,$message,1);$selfConnect=(bool)$self;if($self)fclose($self);fclose($selfServer);}
echo 'EMPTY_LOOPBACK_PARENT '.json_encode(['bind'=>(bool)$selfServer,'portAssigned'=>$portAssigned,'connectToOwnSocket'=>$selfConnect,'socketError'=>(int)$selfError,'processCreationAvailable'=>function_exists('proc_open'),'applicationFilesRead'=>0,'applicationFilesWritten'=>0])."\n";
if(!function_exists('proc_open')){echo "EMPTY_LOOPBACK_CHILD {\"status\":\"process_creation_unavailable\",\"browserAcceptance\":false}\n";if(in_array('--require-connect',$argv??[],true))exit(1);return;}
$allConnected=true;
foreach(['configured','no-ini']as$mode){
 $reserved=@stream_socket_server('tcp://127.0.0.1:0',$errno,$message);if(!$reserved)throw new RuntimeException('Temporary port unavailable');
 $address=stream_socket_get_name($reserved,false);fclose($reserved);
 $gate=bin2hex(random_bytes(32));$env=getenv();$env['TT_EMPTY_GATE']=$gate;unset($env['PHP_CLI_SERVER_WORKERS']);
 $command=[PHP_BINARY];if($mode==='no-ini')$command[]='-n';array_push($command,'-S',$address,'-t',$work,$work.'/router.php');
 $process=proc_open($command,[0=>['pipe','r'],1=>['file',$work.'/server.log','w'],2=>['file',$work.'/server.log','a']],$pipes,$work,$env);
 if(!is_resource($process))throw new RuntimeException('Empty server process unavailable');
 fclose($pipes[0]);$pipes=[];$http='000';$error=0;
 for($attempt=0;$attempt<10;$attempt++){
  usleep(200000);$socket=@stream_socket_client('tcp://'.$address,$error,$message,.5);
  if(!$socket)continue;stream_set_timeout($socket,1);fwrite($socket,"GET /probe HTTP/1.1\r\nHost: 127.0.0.1\r\nX-TT-Empty: $gate\r\nConnection: close\r\n\r\n");$line=(string)fgets($socket);fclose($socket);
  if(preg_match('~^HTTP/1\.[01] ([0-9]{3})~',$line,$matches))$http=$matches[1];if($http==='200')break;
 }
 $wrongGate='not-reached';if($http==='200'){$socket=@stream_socket_client('tcp://'.$address,$error,$message,.5);if($socket){stream_set_timeout($socket,1);fwrite($socket,"GET /probe HTTP/1.1\r\nHost: 127.0.0.1\r\nX-TT-Empty: incorrect\r\nConnection: close\r\n\r\n");$line=(string)fgets($socket);fclose($socket);$wrongGate=preg_match('~^HTTP/1\.[01] ([0-9]{3})~',$line,$matches)?$matches[1]:'000';}if($wrongGate!=='404')throw new RuntimeException('Empty request gate failed');}
 $status=proc_get_status($process);$parentNamespace=@readlink('/proc/self/ns/net');$childNamespace=@readlink('/proc/'.$status['pid'].'/ns/net');
 $namespace=(!$parentNamespace||!$childNamespace)?'unavailable':($parentNamespace===$childNamespace?'same':'different');
 $log=(string)file_get_contents($work.'/server.log');$category=preg_match('/Development Server .*started/i',$log)?'server_started':'unclassified';
 foreach(['listen_failed'=>'/Failed to listen/i','runtime_crash'=>'/Segmentation fault|core dumped|Aborted/i','command_missing'=>'/Could not open input file|No such file or directory/i','startup_failure'=>'/Fatal error|PHP Warning:.*Startup/i']as$label=>$pattern)if(preg_match($pattern,$log))$category=$label;
 echo 'EMPTY_LOOPBACK_CHILD '.json_encode(['mode'=>$mode,'running'=>(bool)$status['running'],'httpStatus'=>$http,'wrongGateStatus'=>$wrongGate,'socketError'=>(int)$error,'networkNamespace'=>$namespace,'serverCategory'=>$category,'applicationFilesRead'=>0,'applicationFilesWritten'=>0])."\n";
 $allConnected=$allConnected&&$http==='200';$stop();
}
if(in_array('--require-connect',$argv??[],true)&&!$allConnected)exit(1);
