#!/usr/bin/env bash
# Source reads only; every write/server/control action stays in a private temporary copy.
set -euo pipefail
umask 077
source_app=$1
port=$2
case "$source_app" in /home/*/domains/app.transtradeinternational.com/public_html) ;; *) exit 1 ;; esac
[[ "$port" =~ ^[0-9]{4,5}$ ]]
[[ "${TT_REHEARSAL_GATE_HEX:-}" =~ ^[a-f0-9]{64}$ ]]
task_work=$(mktemp -d /tmp/tt-audit-rehearsal-XXXXXXXX)
server_pid=''
cleanup(){
  if [ -n "$server_pid" ]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  case "$task_work" in /tmp/tt-audit-rehearsal-????????) rm -rf -- "$task_work" ;; *) exit 1 ;; esac
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM
export TT_REHEARSAL_WORK="$task_work"
mkdir -m 700 "$task_work/app" "$task_work/transtrade_private" "$task_work/preimage"
printf '%s\n' 'PRIVATE_REHEARSAL_STAGE app_copy_started'
cp -a "$source_app/." "$task_work/app/"
printf '%s\n' 'PRIVATE_REHEARSAL_STAGE app_copy_complete'
# Reject app links rather than exposing any source path through the test server.
if [ -n "$(find "$task_work/app" -type l -print -quit)" ]; then exit 1; fi
printf '%s' "$TT_REHEARSAL_GATE_HEX" > "$task_work/gate"
unset TT_REHEARSAL_GATE_HEX
export TT_REHEARSAL_SOURCE_APP="$source_app"
printf '%s\n' 'PRIVATE_REHEARSAL_STAGE private_copy_started'
php <<'PHP'
<?php
$source=dirname(getenv('TT_REHEARSAL_SOURCE_APP')).'/transtrade_private';$work=getenv('TT_REHEARSAL_WORK');$target=$work.'/transtrade_private';
if(realpath($target)!==$target||realpath($source)===$target)exit(1);
try{
 $excluded=['backups','sessions','security-migrations','arrival-recovery'];$paths=[];$bytes=0;
 $dir=new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS);
 $filter=new RecursiveCallbackFilterIterator($dir,static function($file)use($source,$excluded){
  $rel=substr($file->getPathname(),strlen($source)+1);$top=explode('/',$rel)[0];
  if(in_array($top,$excluded,true))return false;
  return !preg_match('/(?:\.lock$|\.tmp(?:-|$)|\.restore-)/',$rel);
 });
 foreach(new RecursiveIteratorIterator($filter) as $file){
  if($file->isLink())throw new RuntimeException('Link');if(!$file->isFile())continue;
  $rel=substr($file->getPathname(),strlen($source)+1);$bytes+=$file->getSize();$paths[$rel]=$file->getPathname();
  if($bytes>1073741824||count($paths)>20000)throw new RuntimeException('Bound');
 }
 if(disk_free_space($work)<$bytes+67108864)throw new RuntimeException('Capacity');
 ksort($paths);$manifest=[];
 foreach($paths as $rel=>$path){
  $to=$target.'/'.$rel;if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);
  $in=fopen($path,'rb');if(!$in||!flock($in,LOCK_SH|LOCK_NB))throw new RuntimeException('Busy');
  try{$out=fopen($to,'xb');if(!$out)throw new RuntimeException('Copy');$copied=stream_copy_to_stream($in,$out);fflush($out);fclose($out);chmod($to,0600);
   $hash=hash_file('sha256',$to);if($copied!==filesize($to)||!hash_equals($hash,hash_file('sha256',$path)))throw new RuntimeException('Changed');$manifest[$rel]=['size'=>$copied,'sha256'=>$hash];
  }finally{flock($in,LOCK_UN);fclose($in);}
 }
 foreach($manifest as $rel=>$row)if(!hash_equals($row['sha256'],hash_file('sha256',$source.'/'.$rel)))throw new RuntimeException('Changed');
 foreach(['auth.json','accounts.json','operations.json'] as $name){
  if(!isset($manifest[$name]))throw new RuntimeException('Missing');
  json_decode(file_get_contents($target.'/'.$name),true,512,JSON_THROW_ON_ERROR);copy($target.'/'.$name,$work.'/preimage/'.$name);
 }
 file_put_contents($work.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
 file_put_contents($work.'/ready.json',json_encode(['files'=>count($manifest),'bytes'=>$bytes,'manifestDigest'=>hash('sha256',json_encode($manifest,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR));
}catch(Throwable $error){fwrite(STDERR,"Private rehearsal copy failed its source-consistency checks.\n");exit(1);}
PHP
printf '%s\n' 'PRIVATE_REHEARSAL_STAGE private_copy_complete'
cat > "$task_work/router.php" <<'PHP'
<?php
$work=__DIR__;$provided=(string)($_SERVER['HTTP_X_TT_REHEARSAL']??'');
if(!hash_equals(file_get_contents($work.'/gate'),$provided)){http_response_code(404);exit;}
$path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if($path==='/__audit/ready'){header('Content-Type: application/json');readfile($work.'/ready.json');exit;}
if($path==='/__audit/finish'&&$_SERVER['REQUEST_METHOD']==='POST'){touch($work.'/stop');echo '{}';exit;}
if($path==='/__audit/rollback'&&$_SERVER['REQUEST_METHOD']==='POST'){
 try{
  require $work.'/app/auth_store.php';require $work.'/app/backup_lib.php';$staged=[];
  foreach(['auth.json','accounts.json','operations.json'] as $name){file_put_contents(TT_DATA_DIR.'/'.$name,'{}');$temp=$work.'/stage-'.$name;copy($work.'/preimage/'.$name,$temp);$staged[$name]=$temp;}
  $count=tt_backup_commit_staged($staged);
  foreach(array_keys($staged)as$name)if(!hash_equals(hash_file('sha256',$work.'/preimage/'.$name),hash_file('sha256',TT_DATA_DIR.'/'.$name)))throw new RuntimeException('Rollback');
  header('Content-Type: application/json');echo json_encode(['ok'=>true,'storesRestored'=>$count]);
 }catch(Throwable $error){http_response_code(500);echo '{"ok":false}';}exit;
}
if(str_starts_with((string)$path,'/__audit/')){http_response_code(404);exit;}
return false;
PHP
# Never inherit any live database connection into the disposable target.
env -u TT_DB_HOST -u TT_DB_NAME -u TT_DB_USER -u TT_DB_PASS php -S "127.0.0.1:$port" -t "$task_work/app" "$task_work/router.php" > "$task_work/server.log" 2>&1 &
server_pid=$!
printf '%s\n' 'PRIVATE_REHEARSAL_TARGET_STARTED'
host_status=000
for attempt in 1 2 3 4 5; do
  host_status=$(curl --noproxy 127.0.0.1 --silent --output /dev/null --write-out '%{http_code}' --max-time 2 -H "X-TT-Rehearsal: $(cat "$task_work/gate")" "http://127.0.0.1:$port/__audit/ready") || true
  [ "$host_status" = 200 ] && break
  sleep 1
done
case "$host_status" in [0-9][0-9][0-9]) printf 'PRIVATE_REHEARSAL_HOST_HTTP status=%s\n' "$host_status" ;; esac
# TTL also removes the copy if the CI/SSH connection unexpectedly disappears.
deadline=$(( $(date +%s) + 1200 ))
while [ ! -e "$task_work/stop" ] && [ "$(date +%s)" -lt "$deadline" ]; do
  kill -0 "$server_pid" 2>/dev/null || exit 1
  sleep 2
done
