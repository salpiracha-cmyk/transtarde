<?php
declare(strict_types=1);
define('TT_LCR_FUNCTIONS_ONLY',true);
require dirname(__DIR__,2).'/api/local_customer_receipts.php';
$names=lcr_names();$bank=['id'=>'bank-brm','bankName'=>'Fixture Bank','accountTitle'=>'BRM'];
$issue=[lcr_line('1130',85,0,$names),lcr_line('1220',0,85,$names,['soda'=>'LS-1','party'=>'Buyer'])];
$clear=lcr_cheque_bank_lines(85,$names,$bank);
$return=lcr_cheque_bank_lines(85,$names,$bank,true);
$reopen=[lcr_line('1220',85,0,$names,['soda'=>'LS-1','party'=>'Buyer']),lcr_line('1130',0,85,$names)];
function check_receipt(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function balance_receipt(array $groups):array{$totals=[];foreach($groups as $group)foreach($group as $line){$account=(string)$line['account'];$totals[$account]=round(($totals[$account]??0)+(float)$line['debit']-(float)$line['credit'],2);}return $totals;}
$issued=balance_receipt([$issue]);check_receipt(!isset($issued['1110'])&&($issued['1130']??0)===85.0,'Cheque in hand cannot increase the bank.');
$cleared=balance_receipt([$issue,$clear]);check_receipt(($cleared['1130']??0)===0.0&&($cleared['1110']??0)===85.0&&($clear[0]['bankAccountId']??'')==='bank-brm','Clearance must move the cheque to the selected company bank.');
foreach(balance_receipt([$issue,$reopen]) as $amount)check_receipt(abs($amount)<.005,'Cancellation must reopen the local receivable.');
foreach(balance_receipt([$issue,$clear,$return,$reopen]) as $amount)check_receipt(abs($amount)<.005,'Bounce must reverse bank and restore local receivable.');
echo "Local customer cheque accounting lifecycle fixture passed.\n";
