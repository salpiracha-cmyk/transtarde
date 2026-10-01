<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_reviews_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function ar_out(array $v,int $code=200):never{http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))ar_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
 $b=json_decode(file_get_contents('php://input')?:'{}',true);if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')ar_out(['ok'=>false,'error'=>'Use POST to discard a review.'],405);
 if(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??'')))ar_out(['ok'=>false,'error'=>'Refresh your session and try again.'],419);
 $e=strtoupper((string)($b['entity']??''));if(!in_array($e,['TTI','BRM','TG'],true))ar_out(['ok'=>false,'error'=>'Select company books.'],422);
 $permissions=$u['permissions']['Accounts']??[];$write=$permissions==='all';if(is_array($permissions)){if(array_intersect(['Create','Edit','Approve'],array_values($permissions)))$write=true;foreach($permissions as$rights)if(is_array($rights)&&array_intersect(['Create','Edit','Approve'],array_values($rights)))$write=true;}
 if(($u['role']??'')!=='Super Admin'&&!$write)ar_out(['ok'=>false,'error'=>'Accounts review permission required.'],403);
 if(($u['role']??'')!=='Super Admin'&&(!tt_user_can_access_entity($u,$e,'View')||!(tt_user_can_access_entity($u,$e,'Edit')||tt_user_can_access_entity($u,$e,'Approve'))))ar_out(['ok'=>false,'error'=>'Accounts review permission required for this company.'],403);
 if(($b['action']??'')!=='discard')ar_out(['ok'=>false,'error'=>'Choose Discard or open the approval form.'],422);
 tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/accounts.json','c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
 try{rewind($h);$raw=stream_get_contents($h);$s=$raw?json_decode($raw,true):[];if(!is_array($s))throw new RuntimeException('Accounts storage invalid.');$match=null;foreach(ar_items($s,$e)as$item)if($item['id']===($b['id']??'')&&$item['fingerprint']===($b['fingerprint']??'')){$match=$item;break;}
 if(!$match)ar_out(['ok'=>false,'error'=>'This review changed or has already been removed. Refresh the list.'],409);
 $s['accountsReviewDismissals'][$match['id']]=['entity'=>$e,'fingerprint'=>$match['fingerprint'],'discardedAt'=>gmdate('c'),'discardedBy'=>(string)($u['full_name']??$u['username']??'User')];$s['revision']=(int)($s['revision']??0)+1;
 $s['workflowAudit'][]=['at'=>gmdate('c'),'type'=>'ACCOUNTS_REVIEW','id'=>$match['id'],'action'=>'DISCARD','user'=>(string)($u['username']??'User'),'before'=>$match,'after'=>[]];
 $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Could not save review.');
 }finally{flock($h,LOCK_UN);fclose($h);}ar_out(['ok'=>true]);
}catch(Throwable $x){error_log('Accounts review: '.$x->getMessage());ar_out(['ok'=>false,'error'=>'Could not save this review. Try again.'],500);}
