<?php
declare(strict_types=1);

/** Private replay protection. Admission is reserved before any business mutation. */
const TT_OFFLINE_STORE_MAX_BYTES=8388608;
const TT_OFFLINE_USER_MAX_BYTES=2097152;
const TT_OFFLINE_RESPONSE_MAX_BYTES=16384;
const TT_OFFLINE_MAX_TRANSACTIONS=4096;
const TT_OFFLINE_USER_MAX_TRANSACTIONS=512;
const TT_OFFLINE_MAX_RESOURCES=512;
const TT_OFFLINE_RETENTION_SECONDS=1209600;
const TT_OFFLINE_PENDING_RESERVATION=106496; // Six-byte JSON escaping plus bounded metadata.
function tt_offline_header(string $name): string {return trim((string)($_SERVER['HTTP_'.strtoupper(str_replace('-','_',$name))]??''));}
function tt_offline_store_file(): string {return rtrim((string)TT_DATA_DIR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'offline_transactions.json';}
function tt_offline_resource_key(string $path,array $body): string {
    $entity=strtoupper(trim((string)($_GET['entity']??$body['entity']??'')));
    if(strlen($path)>256||!preg_match('#^/api/[A-Za-z0-9_.-]+\.php$#D',$path)||str_contains($path,'..')||strlen($entity)>64||str_contains($entity,"\0"))tt_offline_json_exit(['ok'=>false,'error'=>'Invalid recovery resource.'],422);
    return $path.'|'.$entity;
}
function tt_offline_open_store(int $lock=LOCK_EX) {
    $file=tt_offline_store_file();$new=!is_file($file);$h=fopen($file,'c+b');
    if($h===false||!flock($h,$lock))throw new RuntimeException('Recovery protection is unavailable.');
    if($new&&(fstat($h)['size']??-1)===0)tt_offline_write_store_locked($h,['transactions'=>[],'resources'=>[]]);
    return $h;
}
function tt_offline_read_store_locked($handle): array {
    $size=fstat($handle)['size']??-1;
    if($size<=0||$size>TT_OFFLINE_STORE_MAX_BYTES)throw new RuntimeException('Recovery protection needs maintenance; existing acknowledgements were preserved.');
    rewind($handle);$raw=stream_get_contents($handle,TT_OFFLINE_STORE_MAX_BYTES+1);
    if($raw===false||strlen($raw)>TT_OFFLINE_STORE_MAX_BYTES)throw new RuntimeException('Recovery protection is unavailable.');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data)||!is_array($data['transactions']??null)||!is_array($data['resources']??null))throw new RuntimeException('Recovery protection is invalid.');
    foreach($data['transactions'] as $key=>$row)if(!is_string($key)||strlen($key)>300||!is_array($row)||!is_string($row['requestHash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['requestHash'])||!is_int($row['time']??null))throw new RuntimeException('Recovery acknowledgement is invalid.');
    foreach($data['resources'] as $key=>$version)if(!is_string($key)||strlen($key)>322||!is_int($version)||$version<0)throw new RuntimeException('Recovery resource is invalid.');
    return $data;
}
function tt_offline_write_store_locked($handle,array $data): void {
    $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if(strlen($json)>TT_OFFLINE_STORE_MAX_BYTES)throw new RuntimeException('Recovery protection has reached its capacity.');
    rewind($handle);if(!ftruncate($handle,0)||fwrite($handle,$json)!==strlen($json)||!fflush($handle))throw new RuntimeException('Recovery protection could not be saved.');
}
function tt_offline_json_exit(array $payload,int $status): never {http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function tt_offline_admit(array &$data,string $resource,?string $txKey=null): void {
    if(!array_key_exists($resource,$data['resources'])){
        if(count($data['resources'])>=TT_OFFLINE_MAX_RESOURCES)throw new RuntimeException('Recovery resource capacity is full.');
        $data['resources'][$resource]=0;
    }
    $userPrefix=$txKey===null?'':substr($txKey,0,strpos($txKey,'|')+1);$userBytes=0;$userCount=0;$reserved=0;
    foreach($data['transactions'] as $key=>$row){
        $bytes=strlen(json_encode([$key=>$row],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $extra=($row['state']??'')==='pending'?max(0,TT_OFFLINE_PENDING_RESERVATION-$bytes):0;$reserved+=$extra;
        if($txKey!==null&&str_starts_with($key,$userPrefix)){$userBytes+=$bytes+$extra;$userCount++;}
    }
    $encodedBytes=strlen(json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    if($txKey===null){if($encodedBytes+$reserved>TT_OFFLINE_STORE_MAX_BYTES)throw new RuntimeException('Recovery capacity is reserved by pending entries.');return;}
    if(count($data['transactions'])>=TT_OFFLINE_MAX_TRANSACTIONS||$userCount>=TT_OFFLINE_USER_MAX_TRANSACTIONS||$userBytes+TT_OFFLINE_PENDING_RESERVATION>TT_OFFLINE_USER_MAX_BYTES||$encodedBytes+$reserved+TT_OFFLINE_PENDING_RESERVATION>TT_OFFLINE_STORE_MAX_BYTES)throw new RuntimeException('Recovery capacity is full. No entry was submitted. Existing acknowledgements remain protected.');
}
function tt_offline_transaction_status(array $user,array $ids): array {
    tt_ensure_data_dir();if(!is_file(tt_offline_store_file()))return [];$h=null;
    try{$h=tt_offline_open_store();$data=tt_offline_read_store_locked($h);}
    catch(Throwable){tt_offline_json_exit(['ok'=>false,'error'=>'Recovery status is unavailable; do not repeat an uncertain entry.'],503);}
    finally{if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
    $userId=(string)($user['id']??$user['username']??'0');$out=[];
    foreach(array_slice(array_unique(array_filter(array_map('strval',$ids))),0,TT_OFFLINE_USER_MAX_TRANSACTIONS) as $id){
        if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$id))continue;$row=$data['transactions'][$userId.'|'.$id]??null;
        if(is_array($row))$out[$id]=['state'=>(string)($row['state']??'unknown'),'time'=>(int)$row['time'],'resourceVersion'=>(int)($row['resourceVersion']??0)];
    }return $out;
}
function tt_offline_request_guard(array $user): void {
    static $started=false;if($started)return;$started=true;
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);if(!str_starts_with($path,'/api/'))return;
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$write=in_array($method,['POST','PUT','PATCH','DELETE'],true);
    $raw=$write?(file_get_contents('php://input')?:''):'';$body=[];
    if($raw!==''&&str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){$decoded=json_decode($raw,true);if(is_array($decoded))$body=$decoded;}
    $resource=tt_offline_resource_key($path,$body);tt_ensure_data_dir();$file=tt_offline_store_file();$tx=$write?tt_offline_header('X-TT-Transaction-ID'):'';
    if($tx!==''&&!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$tx))tt_offline_json_exit(['ok'=>false,'error'=>'Invalid transaction identifier.'],422);
    $userId=(string)($user['id']??$user['username']??'0');if(strlen($userId)>128||str_contains($userId,'|'))tt_offline_json_exit(['ok'=>false,'error'=>'Invalid recovery identity.'],422);
    $txKey=$userId.'|'.$tx;$requestHash=hash('sha256',$method."\n".(string)($_SERVER['REQUEST_URI']??$path)."\n".$raw);$h=null;$version=0;
    try{
        if(!$write&&!is_file($file)){header('X-TT-Resource-Version: 0');return;}
        $h=tt_offline_open_store();$data=tt_offline_read_store_locked($h);$version=(int)($data['resources'][$resource]??0);header('X-TT-Resource-Version: '.$version);
        if(!$write)return;
        $now=time();foreach($data['transactions'] as $key=>$row)if($now-$row['time']>TT_OFFLINE_RETENTION_SECONDS)unset($data['transactions'][$key]);
        $existing=$tx!==''?($data['transactions'][$txKey]??null):null;
        if(is_array($existing)){
            if(!hash_equals($existing['requestHash'],$requestHash))tt_offline_json_exit(['ok'=>false,'conflict'=>true,'error'=>'This transaction identifier belongs to a different request.'],409);
            if(($existing['state']??'')==='complete'&&array_key_exists('response',$existing)){
                http_response_code((int)($existing['status']??200));header('Content-Type: '.(string)($existing['contentType']??'application/json; charset=UTF-8'));header('X-TT-Resource-Version: '.(int)($existing['resourceVersion']??$version));header('X-TT-Idempotent-Replay: 1');echo $existing['response'];exit;
            }
            tt_offline_json_exit(['ok'=>false,'conflict'=>true,'error'=>'The original entry may already have posted. Review its Post ID; it was not repeated.','reviewRequired'=>true],409);
        }
        if($tx!==''){$base=tt_offline_header('X-TT-Base-Resource-Version');if($base!==''&&ctype_digit($base)&&(int)$base!==$version)tt_offline_json_exit(['ok'=>false,'conflict'=>true,'error'=>'Newer server data exists. Review it before uploading the saved entry.','resourceVersion'=>$version],409);}
        tt_offline_admit($data,$resource,$tx!==''?$txKey:null);
        if($tx!=='')$data['transactions'][$txKey]=['state'=>'pending','requestHash'=>$requestHash,'resource'=>$resource,'time'=>$now];
        tt_offline_write_store_locked($h,$data);
    }catch(Throwable){tt_offline_json_exit(['ok'=>false,'error'=>'Recovery protection is unavailable or full. No new entry was submitted; review any earlier uncertain entry.'],503);}
    finally{if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
    if($tx===''){
        register_shutdown_function(static function()use($resource):void{
            $status=http_response_code()?:200;if($status<200||$status>=300)return;$h=null;
            try{$h=tt_offline_open_store();$data=tt_offline_read_store_locked($h);$data['resources'][$resource]=max(0,(int)($data['resources'][$resource]??0))+1;tt_offline_write_store_locked($h,$data);}
            catch(Throwable){error_log('Recovery resource acknowledgement could not be saved.');}
            finally{if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
        });return;
    }
    $captured='';$overflow=false;
    ob_start(static function(string $chunk)use(&$captured,&$overflow):string{
        if($overflow)return $chunk;
        if(strlen($captured)+strlen($chunk)>TT_OFFLINE_RESPONSE_MAX_BYTES){$overflow=true;$out=$captured.$chunk;$captured='';return $out;}
        $captured.=$chunk;return '';
    },8192);$bufferLevel=ob_get_level();
    register_shutdown_function(static function()use($txKey,$requestHash,$resource,$bufferLevel,&$captured,&$overflow):void{
        while(ob_get_level()>=$bufferLevel)ob_end_flush();
        $status=http_response_code()?:200;$type='application/json; charset=UTF-8';foreach(headers_list() as $header)if(stripos($header,'Content-Type:')===0)$type=trim(substr($header,13));
        $decoded=!$overflow&&str_contains(strtolower($type),'json')?json_decode($captured,true):null;
        $ack=$status>=200&&$status<300&&($overflow||!str_contains(strtolower($type),'json')||(is_array($decoded)&&($decoded['ok']??false)===true));$h=null;$resourceVersion=0;
        try{
            $h=tt_offline_open_store();$data=tt_offline_read_store_locked($h);$row=$data['transactions'][$txKey]??null;
            if(is_array($row)&&hash_equals($row['requestHash'],$requestHash)){
                if($ack)$resourceVersion=++$data['resources'][$resource];
                if($ack&&!$overflow&&strlen($type)<=512){$data['transactions'][$txKey]=['state'=>'complete','requestHash'=>$requestHash,'resource'=>$resource,'time'=>time(),'status'=>$status,'contentType'=>$type,'response'=>$captured,'resourceVersion'=>$resourceVersion];}
                elseif(!$overflow&&$status>=400&&$status<500&&is_array($decoded)&&($decoded['ok']??null)===false)unset($data['transactions'][$txKey]);
                else $data['transactions'][$txKey]=['state'=>'review_required','requestHash'=>$requestHash,'resource'=>$resource,'time'=>time(),'status'=>$status,'resourceVersion'=>$resourceVersion];
                tt_offline_write_store_locked($h,$data);
            }
        }catch(Throwable){error_log('Recovery transaction acknowledgement needs review; the entry must not be repeated automatically.');}
        finally{if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
        if($resourceVersion>0&&!headers_sent())header('X-TT-Resource-Version: '.$resourceVersion);
        if(!$overflow)echo $captured;
    });
}
