<?php
declare(strict_types=1);
if(!defined('TT_SALES_TAX_FUNCTIONS_ONLY'))define('TT_SALES_TAX_FUNCTIONS_ONLY',true);
require_once __DIR__.'/accounts_sales_tax.php';
require_once __DIR__.'/monthly_tax_core.php';
function mt_access(array $u,string $e):void {if(!in_array($e,['TTI','BRM'],true)||!tt_user_can_open_module($u,'Accounts')||!tt_user_can_access_entity($u,$e,'View')||!tt_user_can_module_action($u,'Accounts','reports','View'))ast_out(['ok'=>false,'error'=>'Sales Tax reports and company View permission required.'],403);}
function mt_banks():array {$out=[];foreach(tt_list_masters()['banks']??[] as $r){$v=$r['values']??[];$out[(string)$r['id']]=['name'=>$v[4]??'Bank','number'=>$v[8]??$v[9]??''];}return $out;}
function mt_meta():array {return ast_read_json(TT_DATA_DIR.'/monthly-tax-files.json');}
function mt_rows(string $e,string $m):array {return mt_projection(ast_accounts(),ast_root(),mt_meta(),mt_banks(),$e,$m);}
function mt_invoice_source(array $root,string $e,string $id):array {
 $s=null;$c=null;foreach($root['shipments']??[] as $r)if(($r['id']??'')===$id)$s=$r;
 foreach($root['contracts']??[] as $r)if(($r['ref']??'')===($s['contractRef']??null))$c=$r;
 if(!$s||!$c||!empty($s['cancelled'])||!empty($c['cancelled'])||($c['status']??'')==='Cancelled'||ast_exporter($s,$c)!==$e)throw new DomainException('The selected Pakistan invoice is unavailable in these company books.');
 $tg=($s['seller']??$c['seller']??'')==='TG';if($tg?empty($s['tgdocs']['saved']):(empty($s['commercial']['saved'])||($s['commercial']['status']??'')!=='Final'))throw new DomainException('Issue the final Pakistan commercial invoice in Exports first.');
 $customers=array_values(array_filter((array)($root['customers']??[]),static fn($r)=>($r['id']??'')===($c['customerId']??'')));
 $fiIds=array_column((array)($s['customs']['fiAllocations']??[]),'fiId');
 $fi=array_values(array_filter((array)($root['fi']??[]),static fn($r)=>in_array($r['id']??'', $fiIds,true)));
 $receipts=array_values(array_filter((array)($root['accountsReceipts']??[]),static fn($r)=>($r['contractRef']??'')===($s['contractRef']??'')&&(!empty($r['lotId'])?($r['lotId']===$s['id']):(($r['lotRef']??$s['lotId']??'')===($s['lotId']??'')))));
 return ['contracts'=>[$c],'shipments'=>[$s],'customers'=>$customers,'fi'=>$fi,'accountsReceipts'=>$receipts,'settings'=>array_intersect_key((array)($root['settings']??[]),array_flip(['customCurrencies','customPackingTypes']))];
}
