<?php
declare(strict_types=1);
/** Immutable native-format journals: expected totals are independent of reporting helpers. */
function withholding_categories_fixture():array {
 $tax=static fn(string $head,float $dr,float $cr,array $extra=[]):array=>['account'=>$head,'debit'=>$dr,'credit'=>$cr]+$extra;
 $journal=static function(string $id,string $source,array $line,array $meta=[],string $date='2026-10-01'):array {
  $dr=(float)$line['debit'];$cr=(float)$line['credit'];
  return ['id'=>$id,'entity'=>'TTI','date'=>$date,'sourceType'=>$source,'status'=>'Posted','reference'=>'REF-'.$id,'narration'=>'Original '.$id,'lines'=>[$line,['account'=>'1110','debit'=>$cr,'credit'=>$dr]],'totalDebit'=>max($dr,$cr),'totalCredit'=>max($dr,$cr),'meta'=>$meta];
 };
 $s=['revision'=>17,'journals'=>[
  'BILL'=>$journal('BILL','COMMODITY_BILL_VERIFIED',$tax('2300',0,15),['broker'=>'Broker fixture','brokerageWithholding'=>15]),
  'DEPOSIT'=>$journal('DEPOSIT','BROKERAGE_WHT_DEPOSIT',$tax('2300',10,0,['broker'=>'Broker fixture'])),
  'PAYMENT'=>$journal('PAYMENT','SUPPLIER_PAYMENT',$tax('2300',0,8),['allocations'=>[['brokerageWht'=>5],['brokerageWht'=>3]]]),
  'CHEQUE'=>$journal('CHEQUE','SUPPLIER_CHEQUE_ISSUED',$tax('2300',0,6),['allocations'=>[['brokerageWht'=>6]]]),
  'CHEQUE-REV'=>$journal('CHEQUE-REV','SUPPLIER_CHEQUE_PAYABLE_REOPENED',$tax('2300',6,0),['reversalOf'=>'CHEQUE']),
  'EXPORT'=>$journal('EXPORT','EXPORT_REALIZATION_RECEIPT',$tax('1260',20,0),[], '2026-09-30'),
  'EXPORT-REV'=>$journal('EXPORT-REV','RECEIPT_AMENDMENT_REVERSAL',$tax('1260',0,20))+['reversalOf'=>'EXPORT'],
  'SAVING'=>$journal('SAVING','BANK_RECON_DIRECT_ENTRY',$tax('1260',11,0),['bankDirectType'=>'SAVING_PROFIT']),
  'SAVING-REV'=>$journal('SAVING-REV','POST_DELETION',$tax('1260',0,11))+['reversalOf'=>'SAVING'],
  'SERVICE'=>$journal('SERVICE','EXPORT_REALIZATION_RECEIPT',$tax('1260',9,0,['taxCategory'=>'SERVICES'])),
  'SERVICE-REV'=>$journal('SERVICE-REV','POST_DELETION',$tax('1260',0,9))+['reversalOf'=>'SERVICE'],
  'OTHER'=>$journal('OTHER','JV',$tax('1260',7,0)),
  'UNPROVEN'=>$journal('UNPROVEN','SUPPLIER_PAYMENT',$tax('2300',0,2),['allocations'=>[['brokerageWht'=>1]]]),
 ]];
 return $s;
}
