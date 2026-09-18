<?php
declare(strict_types=1);

function tt_gemini_config_value(string $name): string {
    foreach (['TT_'.$name,$name] as $envName) {
        $value=getenv($envName);
        if($value!==false&&trim((string)$value)!=='') return trim((string)$value);
    }
    foreach ([dirname(__DIR__).'/private/env.php',dirname(__DIR__).'/data/private/env.php'] as $path) {
        if(!is_file($path)) continue;
        $config=require $path;
        if(is_array($config)&&isset($config[$name])&&trim((string)$config[$name])!=='') return trim((string)$config[$name]);
    }
    if($name==='GEMINI_API_KEY'&&defined('TT_DATA_DIR')) {
        $path=rtrim((string)TT_DATA_DIR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'gemini.key';
        if(is_file($path)&&is_readable($path)) {
            $value=trim((string)file_get_contents($path));
            if($value!=='') return $value;
        }
    }
    return '';
}

function tt_gemini_json_request(string $url,string $key,?array $payload=null,int $timeout=30): array {
    $ch=curl_init($url);
    if($ch===false) throw new RuntimeException('Gemini connection could not be initialized.');
    $options=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER=>['Accept: application/json','x-goog-api-key: '.$key],
    ];
    if($payload!==null) {
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_HTTPHEADER]=['Accept: application/json','Content-Type: application/json','x-goog-api-key: '.$key];
        $options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch,$options);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    if($raw===false||$error!=='') throw new RuntimeException('Gemini request failed: '.$error);
    $response=json_decode((string)$raw,true);
    return ['status'=>$status,'response'=>is_array($response)?$response:[],'raw'=>(string)$raw,'invalidJson'=>!is_array($response)];
}

function tt_gemini_available_models(string $key): array {
    $result=tt_gemini_json_request('https://generativelanguage.googleapis.com/v1beta/models?pageSize=100',$key,null,30);
    if(($result['invalidJson']??false)===true||($result['status']??0)<200||($result['status']??0)>=300) {
        $snippet=preg_replace('/\s+/',' ',trim((string)($result['raw']??'')))??'';
        error_log('Gemini model discovery failed HTTP '.(int)($result['status']??0).': '.mb_substr($snippet,0,2000));
        return [];
    }
    $models=[];
    foreach((array)($result['response']['models']??[]) as $row) {
        if(!in_array('generateContent',(array)($row['supportedGenerationMethods']??[]),true)) continue;
        $name=preg_replace('#^models/#','',trim((string)($row['name']??'')))??'';
        if($name!==''&&preg_match('/^[A-Za-z0-9._-]{3,80}$/',$name)) $models[]=$name;
    }
    return array_values(array_unique($models));
}

function tt_gemini_resolve_model(string $key,string $configured): array {
    $configured=trim($configured);
    if($configured!==''&&!preg_match('/^[A-Za-z0-9._-]{3,80}$/',$configured)) $configured='';
    $available=tt_gemini_available_models($key);
    if(!$available) {
        $fallback=$configured!==''?$configured:'gemini-2.5-flash';
        return ['model'=>$fallback,'candidates'=>[$fallback],'configured'=>$configured,'verified'=>false,'available'=>[]];
    }
    $candidates=[];
    if($configured!==''&&in_array($configured,$available,true)) $candidates[]=$configured;
    foreach(['gemini-3.6-flash','gemini-3.7-flash','gemini-3.5-flash','gemini-3.5-flash-lite','gemini-2.5-flash','gemini-2.5-flash-lite'] as $candidate) {
        if(in_array($candidate,$available,true)&&!in_array($candidate,$candidates,true)) $candidates[]=$candidate;
    }
    foreach($available as $candidate) {
        if(str_contains($candidate,'flash')&&!preg_match('/(?:image|live|tts|audio|transcribe)/i',$candidate)&&!in_array($candidate,$candidates,true)) $candidates[]=$candidate;
    }
    if($candidates) return ['model'=>$candidates[0],'candidates'=>$candidates,'configured'=>$configured,'verified'=>true,'available'=>$available];
    throw new RuntimeException('No Gemini document-capable model is available for the configured API key.');
}
