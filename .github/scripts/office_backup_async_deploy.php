<?php
declare(strict_types=1);
$root=getenv('TT_OFFICE_ASYNC_ROOT')?:'';
if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\\.transtradeinternational\\.com/public_html$~D',$root)||is_link($root)||realpath($root)!==$root)throw new RuntimeException('Unexpected target');
$probe=$root.'/.office-capability-'.bin2hex(random_bytes(16)).'.php';
$source='<?php header("Content-Type: application/json"); header("Cache-Control: no-store"); echo json_encode(["sapi"=>PHP_SAPI,"fastcgiFinish"=>function_exists("fastcgi_finish_request"),"litespeedFinish"=>function_exists("litespeed_finish_request"),"outputBuffering"=>ini_get("output_buffering")]);';
$h=fopen($probe,'x');if(!$h)throw new RuntimeException('Cannot prepare capability probe');
try{
 if(fwrite($h,$source)!==strlen($source))throw new RuntimeException('Probe write failed');fclose($h);$h=null;chmod($probe,0644);
 $ctx=stream_context_create(['http'=>['timeout'=>20],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
 $raw=file_get_contents('https://app.transtradeinternational.com/'.basename($probe),false,$ctx,0,4096);
 $result=json_decode($raw?:'',true,512,JSON_THROW_ON_ERROR);if(!is_array($result)||!isset($result['sapi']))throw new RuntimeException('Invalid capability result');
 echo 'OFFICE_WEB_CAPABILITIES '.json_encode($result,JSON_THROW_ON_ERROR)."\n";
}finally{if(is_resource($h))fclose($h);if(is_file($probe))unlink($probe);}
