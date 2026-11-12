<?php
declare(strict_types=1);
require __DIR__.'/../../api/production_order_links.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$white=pol_parse_identity('IRRI-6 White Rice 5% double polish');
check($white['displayName']==='IRRI-6 White Rice','Canonical white identity');
check(pol_parse_identity('IRRI-6 Parboiled Rice')['riceType']==='Parboiled / Sella','Parboiled identity');
check(pol_parse_identity('IRRI-6 White / C-9 White Rice')===null,'Mixed varieties cannot be guessed');
check(pol_parse_identity('IRRI-6 White or Parboiled Rice')===null,'Mixed types cannot be guessed');
check(pol_parse_identity('5% double polish')===null,'Quality is not a variety');
$scope=['entity'=>'TTI','millName'=>'TTI Rice Mills'];$order=['id'=>'HK','brand'=>'emrooz','product'=>'5% double polish'];
$run=['entity'=>'TTI','millName'=>'TTI Rice Mills','variety'=>'IRRI-6 White Rice','rows'=>[['product'=>'Ready Rice — emrooz','displayName'=>'IRRI-6 White Rice','exporterDealId'=>'HK']]];
$values=['tt30prod'=>json_encode([$run])];
check(pol_order_identity($order,$values,$scope)['stockName']==='IRRI-6 White Rice — emrooz','Historical production gives exact physical stock');
check(pol_order_identity($order,$values,['entity'=>'BRM','millName'=>'TTI Rice Mills'])===null,'Other entity excluded');
check(pol_order_identity($order,$values,['entity'=>'TTI','millName'=>'Other Mill'])===null,'Other mill excluded');
$other=$run;$other['rows'][0]['displayName']='C-9 White Rice';$other['rows'][0]['exporterDealId']='OTHER';$values=['tt30prod'=>json_encode([$run,$other])];
check(pol_order_identity($order,$values,$scope)['variety']==='IRRI-6 White Rice','Exact SODA beats same-brand unrelated variety');
check(pol_order_identity(array_replace($order,['id'=>'UNKNOWN']),$values,$scope)===null,'Ambiguous historical brand refuses guess');
check(pol_order_identity(array_replace($order,['productionVariety'=>'IRRI-6 Parboil Rice']),$values,$scope)['riceType']==='Parboiled / Sella','Explicit instruction controls future output');
$before=serialize($values);$enriched=pol_enrich_order($order,$values,$scope);check($enriched['specification']==='5% double polish','Specification preserved separately');check(serialize($values)===$before,'Read projection never rewrites history');
echo "PASS production order identities: explicit, historical, same-brand varieties, ambiguity, scope and unchanged history\n";

check(pol_order_identity(['product'=>'IRRI-6 White Rice','brand'=>''],[],$scope)['stockName']==='IRRI-6 White Rice','Unbranded stock has no invented suffix');
check(pol_enrich_order($order+['fullSpecification'=>'Old full specification','specificationMode'=>'BRIEF'],$values,$scope)['specification']==='5% double polish','Brief mode ignores inactive full text');
check(pol_enrich_order($order+['fullSpecification'=>'Full active specification','specificationMode'=>'FULL'],$values,$scope)['specification']==='Full active specification','Full mode uses active full text');
echo "PASS unbranded stock and active brief/full specification\n";
