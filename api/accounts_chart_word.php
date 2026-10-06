<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_chart_word_core.php';
header('Cache-Control: no-store');
$u=tt_require_login();
if(!tt_user_can_open_module($u,'Accounts')){http_response_code(403);exit('Accounts access required.');}
$entities=array_values(array_filter(['TTI','BRM','TG'],static fn($e)=>tt_user_can_access_entity($u,$e,'View')));
$requested=strtoupper((string)($_GET['entity']??'ALL'));
if($requested!=='ALL')$entities=in_array($requested,$entities,true)?[$requested]:[];
if(!$entities){http_response_code(403);exit('Company access required.');}
$date=(string)($_GET['to']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'));
$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
if(!$d||$d->format('Y-m-d')!==$date){http_response_code(422);exit('Select a valid balance date.');}
$file=TT_DATA_DIR.'/accounts.json';$store=[];
if(is_file($file)){
 $h=fopen($file,'r');if(!$h||!flock($h,LOCK_SH)){http_response_code(503);exit('Accounts storage unavailable.');}
 try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
 $store=json_decode((string)$raw,true);if(!is_array($store)){http_response_code(503);exit('Accounts storage unavailable.');}
}
$master=json_decode((string)file_get_contents(dirname(__DIR__).'/accounts/accounting_master_v1.json'),true);
$doc=acw_document($master,$store,tt_list_masters(),$entities,$date);
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="Transtrade_Chart_Accounts_Balances_'.$date.'.docx"');
header('Content-Length: '.strlen($doc));echo $doc;
