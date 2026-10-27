<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/document_filenames.php';
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/carry_forward_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function cf_out(array $v,int $status=200):never {http_response_code($status);echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function cf_read():array {$path=TT_DATA_DIR.'/accounts.json';if(!is_file($path))return []; $h=fopen($path,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{$raw=stream_get_contents($h);return json_decode($raw?:'{}',true,512,JSON_THROW_ON_ERROR);}finally{flock($h,LOCK_UN);fclose($h);}}
try{
 $u=tt_require_login();$entities=cf_entities($u);if(!$entities)cf_out(['ok'=>false,'error'=>'Accounts or authorised management access is required.'],403);
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $entity=strtoupper((string)($_GET['entity']??$entities[0]));if(!in_array($entity,$entities,true))cf_out(['ok'=>false,'error'=>'Company access required.'],403);$s=cf_read();$id=cf_text($_GET['id']??'');$rows=[];
  foreach((array)($s['carryForwardShipments']??[]) as $r)if(($r['entity']===$entity||($entity==='TG'&&$r['tgPack']))&&($r['status']==='Ready for Accounts'||cf_management($u,$r['entity']))&&($id===''||$id===$r['id'])){$projected=cf_projection($r,$s,$u,$entity);foreach($projected['bills'] as &$bill)$bill['canPost']=false;unset($bill);$rows[]=$projected;}
  if(isset($_GET['file'])){$fid=cf_text($_GET['file']);$file=null;foreach($rows as $r)foreach((array)($r['files']??[]) as $x)if($x['id']===$fid){$file=$x;$fileOwner=$r;}if(!$file)cf_out(['ok'=>false,'error'=>'Document not found.'],404);$path=TT_DATA_DIR.'/carry-forward-files/'.$file['id'];if(!is_file($path))throw new RuntimeException('Document unavailable.');header('Content-Type: '.$file['mime']);header('X-Content-Type-Options: nosniff');tt_document_disposition(tt_document_name(['party'=>$fileOwner['buyer']??$fileOwner['customer']??'','company'=>$entity,'title'=>$file['type']??$file['name']??'Shipment Document','reference'=>$fileOwner['buyerBalance']['invoiceNo']??$fileOwner['invoiceNo']??$fileOwner['contractRef']??$fileOwner['id']??'','extension'=>$file['mime']==='application/pdf'?'pdf':($file['mime']==='image/png'?'png':'jpg')]),true);readfile($path);exit;}
  $masters=tt_list_masters();$parties=[];foreach((array)($masters['business_parties']??[]) as $p)if(strcasecmp((string)($p['values'][10]??'Active'),'Inactive')!==0)$parties[]=['id'=>$p['id'],'name'=>$p['values'][0],'roles'=>tt_business_party_categories($p['values'][2]??'')];$customers=[];foreach((array)($masters['export_customers']??[]) as $p)if(strcasecmp((string)($p['values'][10]??'Active'),'Inactive')!==0)$customers[]=['id'=>$p['id'],'name'=>$p['values'][0]];
  cf_out(['ok'=>true,'entity'=>$entity,'entities'=>$entities,'retired'=>true,'canManage'=>false,'canManageTG'=>false,'canManagePakistan'=>[],'rows'=>$rows,'customers'=>$customers,'parties'=>$parties,'currencies'=>(array)(tt_master_options()['currencies']??[]),'openingLines'=>[],'cutoff'=>CF_CUTOFF,'billKinds'=>['FREIGHT','TRANSPORT','CLEARING','FUMIGATION','INSPECTION','BAGS','OTHER'],'canAddBills'=>[]]);
 }
 header('Allow: GET');cf_out(['ok'=>false,'error'=>'Shipment B/F entry has been retired. Existing records remain available for Accounts and document reads.'],410);
}catch(CfAccessException $e){cf_out(['ok'=>false,'error'=>$e->getMessage()],403);}catch(DomainException $e){cf_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Carry-forward shipments: '.$e->getMessage());cf_out(['ok'=>false,'error'=>'The operation could not be completed. Reopen and review before retrying.'],500);}
