<?php
declare(strict_types=1);
function tt_user_can_access_entity($user,$entity,$action){return in_array($user['role']??'',['Super Admin','Director'],true);}
require __DIR__.'/../../api/opening_stock_core.php';
$user=['id'=>1,'role'=>'Super Admin','username'=>'qa'];$date='2026-10-27T10:00:00Z';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function denied($fn){try{$fn();}catch(DomainException|InvalidArgumentException|RuntimeException|JsonException $e){return;}throw new RuntimeException('Expected rejection');}
$other=['id'=>'other','entity'=>'BRM','millName'=>'TTI Rice Mills','date'=>'2026-06-30','byProducts'=>['B3'=>42]];
$original=tt_inv_json([$other]);$input=['date'=>OSB_DATE,'revision'=>hash('sha256',$original),'tonnes'=>['B3'=>9,'CSR'=>4,'B2 CSR'=>3,'Sweeping'=>2]];
$saved=osb_apply($original,$input,$user,$date);$rows=osb_rows($saved);
check(count($rows)===2&&$rows[0]===$other,'Preserve previous stock');
check(osb_apply($saved,$input,$user,$date)===$saved,'Original request replay is idempotent');
$movements=[];$stock=tt_inv_stock(['tt32stockadj'=>$saved],osb_scope(),function($r)use(&$movements){$movements[]=$r;});
check($stock===['B3'=>9000.0,'CSR'=>4000.0,'B2 CSR'=>3000.0,'Sweeping'=>2000.0],'Four opening movements with correct tonne conversion');
check(array_sum($stock)===18000.0,'Total 18 synthetic tonnes');
check(count($movements)===4&&!array_filter($movements,fn($r)=>$r['date']!==OSB_DATE||$r['kind']!=='Opening stock'),'Every ledger movement has opening date and label');
check(tt_inv_stock(['tt32stockadj'=>$saved],['entity'=>'BRM','millName'=>'TTI Rice Mills'])===['B3'=>42.0],'Other company unchanged');
foreach(['B3','CSR','B2 CSR','Sweeping'] as $p){$bad=$input;$bad['tonnes'][$p]=-1;denied(fn()=>osb_apply($original,$bad,$user,$date));}
denied(fn()=>osb_apply($original,array_replace($input,['revision'=>'old']),$user,$date));
denied(fn()=>osb_apply($original,array_replace($input,['date'=>'2025-07-01']),$user,$date));
denied(fn()=>osb_apply($original,$input,['role'=>'Mill Operator'],$date));
denied(fn()=>osb_apply('{broken',$input,$user,$date));
$bad=$input;$bad['tonnes']['B3']=6;denied(fn()=>osb_apply($saved,$bad,$user,$date));
$collision=tt_inv_json([array_replace($other,['id'=>'OPENSTOCK-TTI-IRRI6-20260701'])]);$collisionInput=array_replace($input,['revision'=>hash('sha256',$collision)]);denied(fn()=>osb_apply($collision,$collisionInput,$user,$date));
echo "PASS opening quantities, dated ledger movements, company isolation, idempotence, stale/conflicting input and permissions\n";
