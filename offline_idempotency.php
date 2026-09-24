<?php
declare(strict_types=1);

/**
 * Server acknowledgement and idempotency ledger for explicit offline-recovery
 * retries. The ledger is private data, outside public_html, and never replaces
 * an application's own validation or permission checks.
 */
function tt_offline_header(string $name): string {
    $key='HTTP_'.strtoupper(str_replace('-','_',$name));
    return trim((string)($_SERVER[$key]??''));
}

function tt_offline_store_file(): string {
    return rtrim((string)TT_DATA_DIR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'offline_transactions.json';
}

function tt_offline_resource_key(string $path,array $body): string {
    $entity=strtoupper(trim((string)($_GET['entity']??$body['entity']??'')));
    return $path.'|'.$entity;
}

function tt_offline_read_store_locked($handle): array {
    rewind($handle);
    $raw=stream_get_contents($handle);
    $data=$raw?json_decode($raw,true):null;
    return is_array($data)?array_replace(['transactions'=>[],'resources'=>[]],$data):['transactions'=>[],'resources'=>[]];
}

function tt_offline_write_store_locked($handle,array $data): void {
    rewind($handle);
    if(!ftruncate($handle,0)) throw new RuntimeException('Recovery transaction store could not be updated.');
    $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if(fwrite($handle,$json)===false) throw new RuntimeException('Recovery transaction store could not be written.');
    fflush($handle);
}

function tt_offline_json_exit(array $payload,int $status): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function tt_offline_transaction_status(array $user,array $ids): array {
    tt_ensure_data_dir();
    $file=tt_offline_store_file();
    if(!is_file($file)) return [];
    $handle=fopen($file,'r');
    if($handle===false||!flock($handle,LOCK_SH)) return [];
    try{$data=tt_offline_read_store_locked($handle);}finally{flock($handle,LOCK_UN);fclose($handle);}
    $userId=(string)($user['id']??$user['username']??'0');
    $out=[];
    foreach(array_unique(array_filter(array_map('strval',$ids))) as $id){
        if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/',$id)) continue;
        $row=$data['transactions'][$userId.'|'.$id]??null;
        if(is_array($row)) $out[$id]=['state'=>(string)($row['state']??'unknown'),'time'=>(int)($row['time']??0),'resourceVersion'=>(int)($row['resourceVersion']??0)];
    }
    return $out;
}

function tt_offline_request_guard(array $user): void {
    static $started=false;
    if($started) return;
    $started=true;
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if(!str_starts_with($path,'/api/')) return;

    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $write=in_array($method,['POST','PUT','PATCH','DELETE'],true);
    $raw=$write?(file_get_contents('php://input')?:''):'';
    $body=[];
    if($raw!==''&&str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){
        $decoded=json_decode($raw,true);
        if(is_array($decoded)) $body=$decoded;
    }
    $resource=tt_offline_resource_key($path,$body);
    tt_ensure_data_dir();
    $file=tt_offline_store_file();
    $handle=fopen($file,'c+');
    if($handle===false||!flock($handle,LOCK_EX)) return;
    try{
        $data=tt_offline_read_store_locked($handle);
        $version=max(0,(int)($data['resources'][$resource]??0));
    }finally{flock($handle,LOCK_UN);fclose($handle);}
    header('X-TT-Resource-Version: '.$version);
    if(!$write) return;

    $tx=tt_offline_header('X-TT-Transaction-ID');
    if($tx===''){
        // Normal online actions continue to work. Successful mutations still
        // advance the conflict version used by future recovered submissions.
        register_shutdown_function(static function()use($file,$resource):void{
            $status=http_response_code()?:200;
            if($status<200||$status>=300) return;
            $handle=fopen($file,'c+');
            if($handle===false||!flock($handle,LOCK_EX)) return;
            try{$data=tt_offline_read_store_locked($handle);$data['resources'][$resource]=max(0,(int)($data['resources'][$resource]??0))+1;tt_offline_write_store_locked($handle,$data);}finally{flock($handle,LOCK_UN);fclose($handle);}
        });
        return;
    }
    if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/',$tx)) tt_offline_json_exit(['ok'=>false,'error'=>'Invalid transaction identifier.'],422);

    $userId=(string)($user['id']??$user['username']??'0');
    $txKey=$userId.'|'.$tx;
    $requestHash=hash('sha256',$method."\n".(string)($_SERVER['REQUEST_URI']??$path)."\n".$raw);
    $baseHeader=tt_offline_header('X-TT-Base-Resource-Version');
    $handle=fopen($file,'c+');
    if($handle===false||!flock($handle,LOCK_EX)) tt_offline_json_exit(['ok'=>false,'error'=>'Recovery transaction protection is unavailable.'],503);
    try{
        $data=tt_offline_read_store_locked($handle);
        $now=time();
        foreach((array)($data['transactions']??[]) as $key=>$row) if($now-(int)($row['time']??0)>1209600) unset($data['transactions'][$key]);
        $existing=$data['transactions'][$txKey]??null;
        if(is_array($existing)){
            if(!hash_equals((string)($existing['requestHash']??''),$requestHash)){
                tt_offline_write_store_locked($handle,$data);flock($handle,LOCK_UN);fclose($handle);$handle=null;
                tt_offline_json_exit(['ok'=>false,'conflict'=>true,'error'=>'This transaction identifier belongs to a different request.'],409);
            }
            if(($existing['state']??'')==='complete'){
                $status=(int)($existing['status']??200);$response=(string)($existing['response']??'');$type=(string)($existing['contentType']??'application/json; charset=UTF-8');$storedVersion=(int)($existing['resourceVersion']??$version);
                flock($handle,LOCK_UN);fclose($handle);$handle=null;
                http_response_code($status);header('Content-Type: '.$type);header('X-TT-Resource-Version: '.$storedVersion);header('X-TT-Idempotent-Replay: 1');echo $response;exit;
            }
            tt_offline_write_store_locked($handle,$data);flock($handle,LOCK_UN);fclose($handle);$handle=null;
            tt_offline_json_exit(['ok'=>false,'conflict'=>true,'error'=>'The original transaction may still be processing. It was not repeated.'],409);
        }
        $current=max(0,(int)($data['resources'][$resource]??0));
        if($baseHeader!==''&&ctype_digit($baseHeader)&&(int)$baseHeader!==$current){
            flock($handle,LOCK_UN);fclose($handle);$handle=null;
            tt_offline_json_exit(['ok'=>false,'conflict'=>true,'error'=>'Newer server data exists. Review it before uploading the saved entry.','resourceVersion'=>$current],409);
        }
        $data['transactions'][$txKey]=['state'=>'pending','requestHash'=>$requestHash,'resource'=>$resource,'time'=>$now];
        tt_offline_write_store_locked($handle,$data);
    }finally{if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}

    ob_start();
    $bufferLevel=ob_get_level();
    register_shutdown_function(static function()use($file,$txKey,$requestHash,$resource,$bufferLevel):void{
        $chunks=[];
        while(ob_get_level()>=$bufferLevel){$chunk=ob_get_clean();if($chunk!==false)$chunks[]=$chunk;}
        $response=implode('',array_reverse($chunks));
        $status=http_response_code()?:200;
        $type='application/json; charset=UTF-8';
        foreach(headers_list() as $header) if(stripos($header,'Content-Type:')===0) $type=trim(substr($header,13));
        $decoded=str_contains(strtolower($type),'json')?json_decode($response,true):null;
        $acknowledged=$status>=200&&$status<300&&(!str_contains(strtolower($type),'json')||(is_array($decoded)&&($decoded['ok']??false)===true));
        $handle=fopen($file,'c+');$resourceVersion=0;
        if($handle!==false&&flock($handle,LOCK_EX)){
            try{
                $data=tt_offline_read_store_locked($handle);$row=$data['transactions'][$txKey]??null;
                if(is_array($row)&&hash_equals((string)($row['requestHash']??''),$requestHash)){
                    if($acknowledged){$resourceVersion=max(0,(int)($data['resources'][$resource]??0))+1;$data['resources'][$resource]=$resourceVersion;$data['transactions'][$txKey]=['state'=>'complete','requestHash'=>$requestHash,'resource'=>$resource,'time'=>time(),'status'=>$status,'contentType'=>$type,'response'=>$response,'resourceVersion'=>$resourceVersion];}
                    else unset($data['transactions'][$txKey]);
                    tt_offline_write_store_locked($handle,$data);
                }
            }finally{flock($handle,LOCK_UN);fclose($handle);}
        }
        if($resourceVersion>0&&!headers_sent()) header('X-TT-Resource-Version: '.$resourceVersion);
        echo $response;
    });
}
