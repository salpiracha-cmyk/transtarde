<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
function mvb_out(array $d,int $s=200): never {http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function mvb_entity(string $linked): string {$u=strtoupper($linked);if(str_contains($u,'BRM')||str_contains($u,'BUKSH RICE'))return 'BRM';if(str_contains($u,'TTI')||str_contains($u,'TRANSTRADE INTERNATIONAL'))return 'TTI';return '';}
try{
 $u=tt_require_login();
 if(($u['role']??'')!=='Super Admin'&&!tt_user_can_open_module($u,'Mill')&&!tt_user_can_open_module($u,'Accounts'))mvb_out(['ok'=>false,'error'=>'You do not have access to Mill bank choices.'],403);
 $rows=[];
 foreach((array)(tt_list_masters()['banks']??[]) as $row){
  $v=(array)($row['values']??[]);$id=(string)($row['id']??'');
  $entity=mvb_entity((string)($v[1]??''));
  if(!in_array($entity,['TTI','BRM'],true)||!tt_user_business_entity($u,$entity,'View')
      ||trim((string)($v[0]??''))!=='Company Account'||strtoupper(trim((string)($v[7]??'')))!=='PKR'
      ||!tt_bank_can_transact($id))continue;
  $number=trim((string)($v[8]??''))?:trim((string)($v[9]??''));
  $digits=preg_replace('/[^0-9]/','',$number);
  $bank=trim((string)($v[4]??''))?:trim((string)($v[3]??''));
  if($bank===''||strlen($digits)<4)continue;
  $rows[]=['id'=>$id,'entity'=>$entity,'display'=>$bank.' — ••••'.substr($digits,-4),'bankName'=>$bank,'currency'=>'PKR'];
 }
 usort($rows,static fn($a,$b)=>strcmp($a['entity'],$b['entity'])?:strcmp($a['display'],$b['display']));
 mvb_out(['ok'=>true,'banks'=>$rows,'serverNow'=>gmdate('c')]);
}catch(Throwable $e){mvb_out(['ok'=>false,'error'=>'Mill bank choices could not be loaded.'],500);}

