<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_subaccounts_core.php';
$journal=static fn($id,$date,$lines,$extra=[])=>['id'=>$id,'entity'=>'TTI','status'=>'Posted','date'=>$date,'lines'=>$lines]+$extra;
$tax=static fn($debit,$credit,$category='EXPORT')=>['account'=>'1260','debit'=>$debit,'credit'=>$credit,'taxCategory'=>$category];
$state=['journals'=>[
 'OLD'=>$journal('OLD','2025-12-31',[$tax(500,0)]),
 'A'=>$journal('A','2026-01-01',[$tax(100,0),['account'=>'1110','debit'=>0,'credit'=>100]],['reference'=>'ADVICE1']),
 'R'=>$journal('R','2026-10-18',[$tax(0,20)],['reversalOf'=>'OLD']),
 'PAYABLE'=>$journal('PAYABLE','2026-10-18',[['account'=>'2300','debit'=>0,'credit'=>30,'taxCategory'=>'SERVICES']]),
 'FUTURE'=>$journal('FUTURE','2026-10-19',[$tax(600,0)]),
 'INVALID'=>$journal('INVALID','2026-02-30',[$tax(700,0)]),
 'UNDATED'=>$journal('UNDATED','',[$tax(800,0)]),
 'DRAFT'=>array_replace($journal('DRAFT','2026-10-18',[$tax(900,0)]),['status'=>'Draft']),
 'TG'=>array_replace($journal('TG','2026-10-18',[$tax(45,0)]),['entity'=>'TG'])
]];
$before=serialize($state);$rows=sac_tax_entries($state,'TTI','2026-01-01','2026-10-18');
if(count($rows)!==3)throw new RuntimeException('Cutoff/status/company filtering failed');
$p=sac_payload($state,'TTI','2026-01-01','2026-10-18');$by=array_column($p['taxReport'],null,'category');
if($by['EXPORT']['debit']!==100.||$by['EXPORT']['credit']!==20.||$by['SERVICES']['credit']!==30.)throw new RuntimeException('Summary does not reconcile to actual tax lines');
if($p['taxEntries']!==$rows||$p['taxFilters']!==['from'=>'2026-01-01','to'=>'2026-10-18']||$p['currency']!=='PKR')throw new RuntimeException('Export snapshot missing');
if(sac_tax_entries($state,'TG','2026-01-01','2026-10-18')[0]['currency']!=='AED')throw new RuntimeException('TG book currency lost');
if($rows[0]['reference']!=='ADVICE1'||$before!==serialize($state))throw new RuntimeException('Source identity lost or report mutated books');
require __DIR__.'/withholding_categories_fixture.php';
$native=withholding_categories_fixture();$nativeBefore=serialize($native);$nativePayload=sac_payload($native,'TTI','2026-10-01','2026-10-31');
$expected=['BROKERAGE'=>[16.,29.],'EXPORT'=>[0.,20.],'SAVINGS'=>[11.,11.],'SERVICES'=>[9.,9.],'OTHER'=>[7.,2.]];
$summary=[];foreach($nativePayload['taxReport'] as $r){$c=$r['category'];$summary[$c]??=['debit'=>0.,'credit'=>0.];$summary[$c]['debit']+=$r['debit'];$summary[$c]['credit']+=$r['credit'];}
foreach($expected as $category=>[$dr,$cr])if(($summary[$category]['debit']??null)!==$dr||($summary[$category]['credit']??null)!==$cr)throw new RuntimeException('Native WHT category totals disagree: '.$category);
$byId=array_column($nativePayload['taxEntries'],null,'journalId');
foreach(['BILL','DEPOSIT','PAYMENT','CHEQUE','CHEQUE-REV'] as $id)if($byId[$id]['category']!=='BROKERAGE')throw new RuntimeException('Brokerage source lost: '.$id);
foreach(['EXPORT-REV'=>'EXPORT','SAVING-REV'=>'SAVINGS','SERVICE-REV'=>'SERVICES','UNPROVEN'=>'OTHER'] as $id=>$category)if($byId[$id]['category']!==$category)throw new RuntimeException('Reversal/source classification lost: '.$id);
if(isset($byId['EXPORT'])||$byId['EXPORT-REV']['credit']!==20.)throw new RuntimeException('Period reversal must retain its own actual date and amount');
if($nativeBefore!==serialize($native))throw new RuntimeException('Tax classification mutated immutable journal bytes');
// An explicit line category wins over a generic EXPORT source and current masters.
$native['accountSubaccounts']['historical']=['id'=>'historical','entity'=>'TTI','name'=>'Changed now','parentCode'=>'1260','taxCategory'=>'OTHER','active'=>true];
if(sac_tax_entries($native,'TTI','2026-10-01','2026-10-31')!==$nativePayload['taxEntries'])throw new RuntimeException('Current masters changed historic WHT treatment');
$probe=$native['journals']['EXPORT-REV'];
$foreign=$native;$foreign['journals']['EXPORT']['entity']='BRM';if(sac_tax_category($foreign,$probe,$probe['lines'][0])!=='OTHER')throw new RuntimeException('Cross-company reversal inherited a tax category');
$missing=$native;unset($missing['journals']['EXPORT']);if(sac_tax_category($missing,$probe,$probe['lines'][0])!=='OTHER')throw new RuntimeException('Missing original must not invent a tax category');
$mismatch=$probe;$mismatch['lines'][0]['credit']=19.;if(sac_tax_category($native,$mismatch,$mismatch['lines'][0])!=='OTHER')throw new RuntimeException('Mismatched reversal inherited original tax category');
$ambiguous=$native;$ambiguous['journals']['EXPORT']['lines'][]=['account'=>'1260','debit'=>20.,'credit'=>0.,'taxCategory'=>'SERVICES'];if(sac_tax_category($ambiguous,$probe,$probe['lines'][0])!=='OTHER')throw new RuntimeException('Ambiguous tax original was guessed');
$cyclic=$native;$cyclic['journals']['EXPORT']['reversalOf']='EXPORT-REV';if(sac_tax_category($cyclic,$probe,$probe['lines'][0])!=='OTHER')throw new RuntimeException('Cyclic reversal did not stop');
foreach(['BRM'=>'PKR','TG'=>'AED'] as $company=>$currency){$copy=withholding_categories_fixture();foreach($copy['journals'] as &$j)$j['entity']=$company;unset($j);$p=sac_payload($copy,$company,'2026-10-01','2026-10-31');if($p['currency']!==$currency||$p['taxReport']!==$nativePayload['taxReport'])throw new RuntimeException('Company tax source/currency mismatch');}
if(in_array('--payload',$argv??[],true)){echo json_encode($nativePayload,JSON_THROW_ON_ERROR);return;}
echo "PASS WHT source transactions reconcile to summary; actual dates, inclusive cutoffs, currency, companies, reversals, source references and read-only books\n";
echo "PASS native brokerage bills/deposits/settlements/cheques, export/savings/services reversals, period cutoffs, explicit saved categories and ambiguous/missing/foreign/cyclic original refusal\n";
