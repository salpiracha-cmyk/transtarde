<?php
declare(strict_types=1);
define('TT_SETTLEMENTS_FUNCTIONS_ONLY', true);
require dirname(__DIR__,2).'/api/supplier_settlements.php';
$catalog=ss_catalog();
$bank=['id'=>'bank-tti','bankName'=>'Test Bank','accountTitle'=>'TTI QA','currency'=>'PKR'];
$issue=['lines'=>[
    ss_line('2120',100,0,$catalog),
    ss_line('2300',0,15,$catalog),
    ss_line('2180',0,85,$catalog),
]];
$clear=ss_cheque_bank_lines(85,$bank,$catalog);
$returned=ss_cheque_bank_lines(85,$bank,$catalog,true);
$reopened=ss_cheque_reversal_lines($issue);
function cheque_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function cheque_balances(array $groups):array{
    $balances=[];
    foreach($groups as $group)foreach($group as $line){$account=(string)$line['account'];$balances[$account]=round(($balances[$account]??0)+(float)$line['debit']-(float)$line['credit'],2);}
    return $balances;
}
$pending=cheque_balances([$issue['lines']]);
cheque_assert(!isset($pending['1110'])&&($pending['2180']??0)===-85.0,'Issued cheque must not reduce the bank.');
cheque_assert(($clear[1]['bankAccountId']??'')==='bank-tti'&&($clear[1]['credit']??0)===85.0,'Clearance must credit the selected bank.');
$cleared=cheque_balances([$issue['lines'],$clear]);
cheque_assert(($cleared['2180']??0)===0.0&&($cleared['1110']??0)===-85.0,'Clearance must settle the issued cheque liability.');
$cancelled=cheque_balances([$issue['lines'],$reopened]);
foreach($cancelled as $amount)cheque_assert(abs($amount)<.005,'Cancellation must restore every original payable and tax line.');
$bounced=cheque_balances([$issue['lines'],$clear,$returned,$reopened]);
foreach($bounced as $amount)cheque_assert(abs($amount)<.005,'Returned cheque must restore the payable and the bank balance.');
echo "Supplier cheque accounting lifecycle fixture passed.\n";
