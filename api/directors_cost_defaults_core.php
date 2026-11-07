<?php
declare(strict_types=1);
require_once __DIR__.'/directors_trading_core.php';
function dcd_rates(array $state,string $date):?array {
    $best=null;foreach((array)($state['directorMarketRates']??[]) as $r)if(($r['date']??'')<=$date&&(!$best||$r['date']>$best['date']))$best=$r;return $best;
}
function dcd_packings(array $state,string $date):array {
    $items=[];foreach(['50 kg PP'=>0,'25 kg PP'=>3,'5 kg'=>null,'10 kg'=>null,'20 kg'=>null,'Jute / cloth'=>null,'Custom'=>null] as $name=>$cost)$items[$name]=['name'=>$name,'amount'=>$cost,'currency'=>'USD','masterBagAmount'=>null,'version'=>0];
    foreach((array)($state['directorPackingDefaults']??[]) as $name=>$versions){$best=null;foreach($versions as $r)if($r['date']<=$date&&(!$best||$r['date']>$best['date']||($r['date']===$best['date']&&$r['version']>$best['version'])))$best=$r;if($best)$items[$name]=$best;}
    return array_values($items);
}
function dcd_save_rates(array &$s,array $b,array $u,string $today):array {
    $date=gm_date($b['date']??'');if($date>$today)throw new DomainException('Daily rates cannot be dated in the future.');$old=$s['directorMarketRates'][$date]??null;if((int)($b['version']??-1)!==(int)($old['version']??0))throw new DomainException('Daily rates changed. Refresh and compare before saving.',409);
    $r=['date'=>$date,'purchaseUnit'=>dt_enum($b['purchaseUnit']??'KG',['KG','MAUND','MT'],'IRRI purchase unit'),'brokenUnit'=>dt_enum($b['brokenUnit']??'KG',['KG','MAUND','MT'],'broken purchase unit')];$present=false;
    foreach(['fx','cashPrice','creditPrice','brokenPrice'] as $k){$v=$b[$k]??null;$r[$k]=$v===null||$v===''?null:dt_positive($v,'daily '.str_replace('Price',' rate',$k));if($r[$k]!==null)$present=true;}if(!$present)throw new DomainException('Enter at least one daily rate.');
    $r+=['version'=>(int)($old['version']??0)+1,'savedBy'=>$u['full_name']??$u['username']??'','savedAt'=>gmdate('c'),'history'=>$old?array_merge($old['history']??[],[array_diff_key($old,['history'=>true])]):[]];$s['directorMarketRates'][$date]=$r;return $r;
}
function dcd_save_packing(array &$s,array $b,array $u,string $today):array {
    $name=gm_text($b['name']??'',100);if($name==='')throw new DomainException('Enter a packing name.');$date=gm_date($b['date']??'');if($date>$today)throw new DomainException('Packing defaults cannot be dated in the future.');
    foreach(array_keys((array)($s['directorPackingDefaults']??[])) as $existing)if(strcasecmp($existing,$name)===0)$name=$existing;
    $current=null;foreach(dcd_packings($s,$date) as $r)if(strcasecmp($r['name'],$name)===0){$current=$r;$name=$r['name'];break;}
    if((int)($b['version']??-1)!==(int)($current['version']??0))throw new DomainException('This packing default changed. Refresh and compare before saving.',409);$amount=gm_number($b['amount']??null,'packing difference per MT');if($name==='50 kg PP'&&$amount!=0)throw new DomainException('50 kg PP is included in FOB costs; its packing difference is zero.');
    $master=$b['masterBagAmount']??null;$r=['name'=>$name,'amount'=>$amount,'currency'=>'USD','masterBagAmount'=>$master===null||$master===''?null:gm_number($master,'master bag addition per MT'),'date'=>$date,'version'=>(int)($current['version']??0)+1,'savedBy'=>$u['full_name']??$u['username']??'','savedAt'=>gmdate('c')];$s['directorPackingDefaults'][$name][]=$r;return $r;
}

/** Estimate conversion only, using the explicitly saved TG USD/AED control. */
function dcd_group_rates(array $state,string $date):array {
    $r=dcd_rates($state,$date);if(!$r||empty($r['fx']))return [];$out=['USD'=>(float)$r['fx']];$best=null;
    foreach((array)(tt_list_masters()['tg_currency_rates']??[]) as $row){$v=(array)($row['values']??[]);if(($v[10]??'')==='Active'&&($v[6]??'')<=$date&&(($v[7]??'')===''||$v[7]>=$date)&&is_numeric($v[3]??null)&&(float)$v[3]>0&&(!$best||($v[6]??'')>($best[6]??'')))$best=$v;}
    if($best)$out['AED']=$out['USD']/(float)$best[3];return $out;
}
