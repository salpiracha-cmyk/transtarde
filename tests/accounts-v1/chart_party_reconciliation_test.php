<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_chart_word_core.php';
function cp_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$masters=['business_parties'=>[['values'=>['Transgrains Trading LLC','TG']]]];
$store=['exportCandidates'=>['INV'=>['entity'=>'TTI','counterparty'=>'TG']],'journals'=>[]];
$invoice=['id'=>'INV-J','entity'=>'TTI','meta'=>['candidateId'=>'INV'],'lines'=>[['account'=>'1240','debit'=>100,'credit'=>0]]];
$receipt=['id'=>'REC-J','entity'=>'TTI','meta'=>['remitter'=>'TG'],'lines'=>[['account'=>'1240','debit'=>0,'credit'=>100,'candidateId'=>'INV']]];
cp_check(acw_party($invoice['lines'][0],$invoice,$store,$masters)==='Transgrains Trading LLC','Invoice alias must resolve to the exact master full name');
cp_check(acw_party($receipt['lines'][0],$receipt,$store,$masters)==='Transgrains Trading LLC','Receipt must resolve its own line candidate, not leave invoice uncredited');
$store['journals']['INV-J']=$invoice;
$reverse=['id'=>'REV','entity'=>'TTI','reversalOf'=>'INV-J','lines'=>[['account'=>'1240','debit'=>0,'credit'=>100]]];
cp_check(acw_party($reverse['lines'][0],$reverse,$store,$masters)==='Transgrains Trading LLC','Proven reversal must retain original party');
$reverse['entity']='BRM';cp_check(acw_party($reverse['lines'][0],$reverse,$store,$masters)==='','Never inherit a different company reversal');
$foreign=$receipt;$foreign['entity']='BRM';cp_check(acw_party($foreign['lines'][0],$foreign,$store,$masters)==='','Never inherit a foreign company candidate');
$explicit=$receipt['lines'][0]+['customer'=>'Another buyer'];cp_check(acw_party($explicit,$receipt,$store,$masters)==='Another buyer','Saved line party must win');
$masters['export_customers']=[['values'=>['Different TG','TG']]];cp_check(acw_party($receipt['lines'][0],$receipt,$store,$masters)==='TG','Ambiguous alias must not be guessed');
$store['commodityBills']['CB']=['entity'=>'TTI','relationshipName'=>'INDUS','broker'=>'JJ','supplier'=>'INDUS'];
$billJournal=['entity'=>'TTI','meta'=>['billId'=>'CB','broker'=>'JJ']];
foreach(['2110'=>'INDUS','2120'=>'JJ','2210'=>'INDUS'] as $code=>$party)cp_check(acw_party(['account'=>$code],$billJournal,$store,[])===$party,'Commodity supplier/broker head identity: '.$code);
foreach(['TTI','BRM'] as $entity){$chart=sac_entity_chart([],$entity);cp_check(str_contains($chart['1240']['name'],'Customer / Export')&&str_contains($chart['4500']['name'],'Export Sales'),'Pakistan overseas balances must display as customer trade');}
cp_check(sac_entity_chart([],'TG')['1240']['name']===sac_chart()['1240']['name'],'Do not relabel unrelated TG controls');
echo "PASS chart receipt/invoice identity, full-name aliases, explicit ownership, reversal lineage and company isolation\n";
