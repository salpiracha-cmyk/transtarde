<?php
declare(strict_types=1);
require __DIR__.'/mill_liabilities_test.php';
require_once dirname(__DIR__,2).'/api/maize_labour_core.php';
require_once dirname(__DIR__,2).'/api/mill_source_amend_core.php';
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_module_action($u,$m,$i,$a){return true;}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
$base=['id'=>'MAIZE1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-10-18','from'=>'2026-10-01','till'=>'2026-10-18','billNo'=>'LB-M1','ref'=>'LB-M1','contractor'=>'Labour Co','party'=>'Labour Co','commodity'=>'Makai','maizeLabourVersion'=>1,'maizeRatesConfirmed'=>true];
$line=['scenario'=>'DIRECT','key'=>'maize-direct-load','label'=>'Direct container loading','qty'=>2,'basis'=>'Per container','rate'=>123.45,'workDate'=>'2026-10-12','workReference'=>'TRUCK / CONT-1-2','auto'=>false];
$record=$base+['lines'=>[$line],'amount'=>246.9];$unchanged=serialize($record);maize_labour_validate($record);verify(serialize($record)===$unchanged,'Validation changed entered work');
$values=['tt38labourbills'=>json_encode([$record])];$source=ml_source($values,$masters,'LABOUR','MAIZE1','TTI');verify($source['commodity']==='Makai'&&$source['workLines'][0]['scenario']==='DIRECT','Review lost Maize route/reference');
$books=['journals'=>[],'supplierBills'=>[]];ml_post($books,$source,$user,$source['fingerprint'],'MAIZE-POST','2026-10-18');
verify($books['journals']['MAIZE-POST']['lines'][0]['debit']===246.9&&$books['journals']['MAIZE-POST']['lines'][1]['account']==='2190','Wrong Maize labour accrual');
verify(!$books['journals']['MAIZE-POST']['meta']['productionCostEligible'],'Maize became Rice conversion cost');
$posted=serialize($books);verify(ml_post($books,$source,$user,$source['fingerprint'],'RETRY','later')['duplicate']&&serialize($books)===$posted,'Maize retry duplicated payable');
foreach ([['rate'=>null],['rate'=>0],['rate'=>-1],['qty'=>-2],['qty'=>1.5],['basis'=>'Per bag'],['key'=>'milling'],['scenario'=>'DIRECT','key'=>'maize-unload'],['workDate'=>'2026-09-30'],['workReference'=>''],['deduction'=>true],['auto'=>true]] as $patch){$bad=$record;$bad['lines'][0]=array_replace($line,$patch);denied(fn()=>maize_labour_validate($bad),'Invalid Maize line accepted: '.json_encode($patch));}
$bad=$record;$bad['maizeRatesConfirmed']=false;denied(fn()=>maize_labour_validate($bad),'Unconfirmed rates accepted');
$bad=$record;$bad['amount']=250;denied(fn()=>maize_labour_validate($bad),'Mismatched total accepted');
$bad=$record;$bad['lines'][]=$line;$bad['amount']*=2;denied(fn()=>maize_labour_validate($bad),'Duplicate work charged twice');
$wh=array_replace($line,['scenario'=>'WAREHOUSE_BULK','key'=>'maize-unload','label'=>'Unload incoming bags','qty'=>600,'rate'=>2,'basis'=>'Per bag']);
$load=array_replace($line,['scenario'=>'WAREHOUSE_BULK','key'=>'maize-container-load']);
$bulk=$base+['lines'=>[$wh,$load],'amount'=>1446.9];maize_labour_validate($bulk);
$packed=$base+['lines'=>[array_replace($wh,['scenario'=>'WAREHOUSE_PACKED']),array_replace($load,['scenario'=>'WAREHOUSE_PACKED']),array_replace($line,['scenario'=>'WAREHOUSE_PACKED','key'=>'maize-paltai50','qty'=>100,'rate'=>3,'basis'=>'Per bag']),array_replace($line,['scenario'=>'WAREHOUSE_PACKED','key'=>'maize-paltai25','qty'=>200,'rate'=>1.5,'basis'=>'Per bag']),array_replace($line,['scenario'=>'WAREHOUSE_PACKED','key'=>'maize-extra-1','qty'=>1,'rate'=>10,'basis'=>'Per task'])],'amount'=>2056.9];maize_labour_validate($packed);
$u=['role'=>'Super Admin','location'=>''];verify(msa_guard_append([],'tt38labourbills',json_encode([$record]),$u)===json_encode([$record]),'Valid append refused');
$bad=$record;$bad['lines'][0]['rate']=null;denied(fn()=>msa_guard_append([],'tt38labourbills',json_encode([$bad]),$u),'Shared collection bypassed blank-rate guard');
// Original sources cannot be rewritten when a new maize bill is added.
denied(fn()=>msa_guard_append($values,'tt38labourbills',json_encode([array_replace($record,['amount'=>999])]),$u),'Shared writer amended original Maize');
echo "PASS Maize routes, separate bases, blank/unconfirmed/negative-rate and Rice-key guards, additions only, whole counts, dated work references, exact totals, append protection and idempotent payable\n";
