<?php
declare(strict_types=1);
require __DIR__.'/../../api/group_management_core.php';
function near(float $a,float $b,string $label):void {if(abs($a-$b)>.0001)throw new RuntimeException($label.': '.$a.' != '.$b);}
function reject(callable $fn,string $label):void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Expected rejection: '.$label);}
near(gm_property_value(['valuationBasis'=>'PREMIUM_PLUS_PAID','marketValue'=>7000000,'instalmentsPaid'=>3000000]),10000000.,'Owner premium plus actual instalments');
near(gm_property_value(['valuationBasis'=>'FULL_VALUE','marketValue'=>7000000,'instalmentsPaid'=>3000000]),7000000.,'Full valuation does not add paid amount again');
$sections=['financials'=>['rows'=>[['id'=>'FIN','label'=>'Net financials','amount'=>-1000000]]],'properties'=>['rows'=>[['id'=>'PROPERTY1','label'=>'Trading property','type'=>'PROPERTY','valuationBasis'=>'PREMIUM_PLUS_PAID','marketValue'=>7000000,'instalmentsPaid'=>3000000]]],'gold'=>['rows'=>[['id'=>'GOLD1','label'=>'Gold holder 1','type'=>'GOLD','holder'=>'Wife 1','purity'=>'22K','grams'=>100,'ratePerGram'=>10000]]]];
$z=gm_zakat($sections,[],50000.,400000.);near($z['netWealthPKR'],10000000.,'Negative financials reduce combined wealth');near($z['assessmentPKR'],250000.,'Assessment at 2.5%');near($z['balancePKR'],-100000.,'Excess payment carries forward as credit');near($z['advanceCreditPKR'],100000.,'Excess not discarded');
$dup=$sections;$dup['duplicate']=$sections['gold'];reject(fn()=>gm_zakat($dup,[],0.,0.),'Cross-section duplicate');reject(fn()=>gm_total([['id'=>'TG','label'=>'TG AED','amount'=>100,'currency'=>'AED']],[]),'Missing FX cannot silently become 1');near(gm_total([['id'=>'TG','label'=>'TG AED','amount'=>100,'currency'=>'AED']],['AED'=>76])['totalPKR'],7600.,'Explicit historical FX');
$raw=gm_raw_position(['rawOnHandMT'=>100,'undeliveredPurchaseMT'=>20,'outstandingSalesMT'=>100,'usableFinishedMT'=>10,'recoveryPct'=>60]);near($raw['rawRequiredMT'],150.,'Finished stock offsets finished requirement before recovery');near($raw['positionMT'],-30.,'SHORT raw requirement');
$corn=['commodity'=>'CORN','fx'=>280,'saleUSDPerMT'=>250,'purchaseRate'=>2600,'purchaseUnit'=>'MAUND','shipmentPKRPerMT'=>2500,'outputs'=>[['product'=>'CORN','kind'=>'finished','recoveryPct'=>100]]];$cost=gm_costing($corn);near($cost['purchasePKRPerRawMT'],65000.,'40 kg maunds convert by 25');near($cost['effectiveFX'],276.22,'1.35% proportional FX');near($cost['whtPKRPerMT'],875.,'Separate 1.25% WHT');near($cost['bankDeductionPKRPerMT'],70.,'Separate 0.1% bank deduction');near($cost['profitPKRPerMT'],1555.,'Corn direct cost');near($cost['watakPurchaseRate'],2662.2,'Corn maximum purchase maund rate');
$rice=['commodity'=>'RICE','fx'=>280,'saleUSDPerMT'=>600,'freightUSDPerMT'=>10,'purchaseRate'=>100,'purchaseUnit'=>'KG','conversionPKRPerRawMT'=>3000,'shipmentPKRPerMT'=>1800,'outputs'=>[['product'=>'5% READY','kind'=>'finished','recoveryPct'=>60],['product'=>'B2CSR','kind'=>'byproduct','recoveryPct'=>35,'ratePKRPerKg'=>50],['product'=>'B3','kind'=>'byproduct','recoveryPct'=>5,'ratePKRPerKg'=>50]]];$r=gm_costing($rice);near($r['byproductCreditPKRPerRawMT'],20000.,'B2CSR and B3 have explicit credits');near($r['costPKRPerReadyMT'],142933.3333,'Currencies and raw/ready units separated');near($r['watakPurchaseRate'],113.6792,'Rice Watak reverses sale and recovery calculation');
$bad=$rice;$bad['outputs'][]=['product'=>'WASTE','kind'=>'waste','recoveryPct'=>1,'ratePKRPerKg'=>95];reject(fn()=>gm_costing($bad),'Waste has no sale credit');$bad=$corn;$bad['conversionPKRPerRawMT']=100;reject(fn()=>gm_costing($bad),'No milling charge for corn');
$p=gm_tg_profit([['postId'=>'POST1','adviceFX'=>280,'matchedMT'=>40,'tgUSDPerMT'=>400,'pakistanUSDPerMT'=>380,'bankCreditPKR'=>4000000],['postId'=>'POST2','adviceFX'=>290,'matchedMT'=>60,'tgUSDPerMT'=>400,'pakistanUSDPerMT'=>380,'bankCreditPKR'=>6000000]],9000000.);near($p['internalMarginPKR'],572000.,'Partial advice slices retain their individual FX');near($p['actualBankPKR'],10000000.,'Internal margin is not another bank receipt');near($p['profitPKR'],1572000.,'Shipment costs deducted once');
echo "PASS group management property/gold, signed Zakat carry-forward, raw requirement, Rice/Corn units, deductions, Watak and TG partial-advice calculations\n";

$books=['journals'=>[
 'PAID'=>['id'=>'PAID','entity'=>'TTI','status'=>'Posted','date'=>'2026-10-01','meta'=>['bankAccountId'=>'BANK-1'],'lines'=>[['account'=>'1110','debit'=>1000,'credit'=>0],['account'=>'1210','counterparty'=>'Buyer A','debit'=>0,'credit'=>1000]]],
 'UNBILLED'=>['id'=>'UNBILLED','entity'=>'TTI','status'=>'Posted / Bill Pending','date'=>'2026-10-01','lines'=>[['account'=>'1310','debit'=>500,'credit'=>0],['account'=>'2210','debit'=>0,'credit'=>500]]],
 'FUTURE'=>['id'=>'FUTURE','entity'=>'TTI','status'=>'Posted','date'=>'2026-11-01','lines'=>[['account'=>'1110','debit'=>100,'credit'=>0]]],
 'FIXED'=>['id'=>'FIXED','entity'=>'TTI','status'=>'Posted','date'=>'2026-10-01','lines'=>[['account'=>'1510','debit'=>900,'credit'=>0]]],
 'IC'=>['id'=>'IC','entity'=>'TTI','status'=>'Posted','date'=>'2026-10-01','lines'=>[['account'=>'1240','debit'=>700,'credit'=>0]]]
]];
$before=serialize($books);$source=gm_financial_source($books,'2026-10-17');$by=[];foreach($source['rows'] as $row)$by[$row['account']]=$row;
near($by['1110']['amount'],1000.,'Report cutoff retains only dated actual cash');near($by['2210']['amount'],-500.,'Accepted unbilled liability cannot disappear');if(!str_contains($by['1210']['label'],'Buyer A'))throw new RuntimeException('Counterparty omitted');if(isset($by['1510']))throw new RuntimeException('Operating fixed assets in cashflow');if(!$by['1240']['excluded']||!$source['warnings'])throw new RuntimeException('Unreconciled intercompany elimination must be explicit');if($before!==serialize($books))throw new RuntimeException('Source report mutated original books');
echo "PASS financial source uses party and bank identities, accepted unbilled liabilities, date cutoff and explicit provisional eliminations; reads preserve books\n";

$partial=['id'=>'ZAKAT|2026-03-01','kind'=>'ZAKAT','date'=>'2026-03-01','sections'=>['header'=>['data'=>['rates'=>[], 'hijriYear'=>'1447','ramadanDateConfirmed'=>true]],'financials'=>['complete'=>true,'rows'=>[['id'=>'F1','label'=>'Eligible financial wealth','amount'=>10000]]]]];
$summary=gm_assessment_summary($partial,[],'2026-10-17');
if($summary['progress']['complete']||$summary['progress']['missingSections']!==['properties','gold','shares','adjustments'])throw new RuntimeException('Partial assessment presented as complete');
near($summary['assessmentPKR'],250.,'Partial completed sections remain inspectable');
$next=['id'=>'ZAKAT|2027-02-18','kind'=>'ZAKAT','date'=>'2027-02-18','sections'=>[]];
reject(fn()=>gm_record_summary($next,['groupManagementRecords'=>[$partial]],'2027-10-01'),'Unfinished prior year must not silently seed carry-forward');
$complete=$partial;foreach(['properties','gold','shares','adjustments'] as $name)$complete['sections'][$name]=['complete'=>true,'rows'=>[]];
$summary=gm_record_summary($next,['groupManagementRecords'=>[$complete]],'2027-10-01');near($summary['openingCarryPKR'],250.,'Completed prior assessment carries exact signed obligation');
$detail=gm_post_detail($books,'PAID','2026-10-17');near($detail['lines'][0]['debit'],1000.,'Source drilldown retains original amount');
reject(fn()=>gm_post_detail($books,'FUTURE','2026-10-17'),'Future posting cannot enter historical drilldown');
reject(fn()=>gm_post_detail($books,'MISSING','2026-10-17'),'Unknown source posting');
echo "PASS partial assessment status, completed-only annual carry, and immutable cutoff-aware source drilldown\n";
