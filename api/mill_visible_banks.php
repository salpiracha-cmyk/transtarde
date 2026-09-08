<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
const TT_MVB_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';
function mvb_out(array $d,int $s=200): never {http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function mvb_entity(string $linked): string {$u=strtoupper($linked);if(str_contains($u,'BRM')||str_contains($u,'BUKSH RICE'))return 'BRM';if(str_contains($u,'TTI')||str_contains($u,'TRANSTRADE INTERNATIONAL'))return 'TTI';return '';}
function mvb_read_settings(): array {tt_ensure_data_dir();if(!is_file(TT_MVB_ACCOUNTS_FILE))return [];$h=fopen(TT_MVB_ACCOUNTS_FILE,'r');if($h===false||!flock($h,LOCK_SH))return [];try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}$s=$raw?json_decode($raw,true):null;return is_array($s)?(array)($s['bankAccountSettings']??[]):[];}
try{
 $u=tt_require_login();
 if(($u['role']??'')!=='Super Admin'&&!tt_user_can_open_module($u,'Mill')&&!tt_user_can_open_module($u,'Accounts'))mvb_out(['ok'=>false,'error'=>'You do not have access to Mill bank choices.'],403);
 $settings=mvb_read_settings();$masters=tt_list_masters();$rows=[];
 foreach((array)($masters['banks']??[]) as $r){if(!is_array($r))continue;$v=array_values((array)($r['values']??[]));while(count($v)<14)$v[]='';$id=(string)($r['id']??'');$entity=mvb_entity((string)$v[1]);if(!in_array($entity,['TTI','BRM'],true))continue;if(trim((string)$v[0])!=='Company Account')continue;if(strtoupper(trim((string)$v[7]))!=='PKR')continue;$s=is_array($settings[$id]??null)?$settings[$id]:[];if(empty($s['active'])||empty($s['allowReceipts'])||empty($s['visibleToMill']))continue;$raw=preg_replace('/\W+/','',trim((string)($v[8]?:$v[9])))??'';$last5=$raw!==''?substr($raw,-5):'';$bank=trim((string)$v[4])?:trim((string)$v[3]);$display=$bank.($last5!==''?' — •••••'.$last5:'');$rows[]=['id'=>$id,'entity'=>$entity,'display'=>$display,'bankName'=>$bank,'currency'=>'PKR'];}
 usort($rows,static fn($a,$b)=>strcmp($a['entity'],$b['entity'])?:strcmp($a['display'],$b['display']));
 mvb_out(['ok'=>true,'banks'=>$rows,'serverNow'=>gmdate('c')]);
}catch(Throwable $e){mvb_out(['ok'=>false,'error'=>'Mill bank choices could not be loaded.'],500);}
