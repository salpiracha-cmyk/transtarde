<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/master_store.php';

$values=array_fill(0,19,'');
$values[0]='Transtrade International';
$values[1]='TTI';
$values[13]=json_encode([[
    'id'=>'legacy-personal',
    'accountType'=>'Personal Account',
    'personalOwner'=>'Old owner field',
    'accountTitle'=>'Actual Account Title',
    'bankName'=>'Fixture Bank',
    'iban'=>'PK00ONLY',
]],JSON_THROW_ON_ERROR);
$rows=tt_company_bank_legacy_rows([['id'=>'tti','values'=>$values]]);
$bank=$rows[0]['values']??[];
if(($bank[0]??'')!=='Proprietor / Owner Account'||($bank[2]??'')!=='Actual Account Title'||($bank[9]??'')!=='PK00ONLY'){
    throw new RuntimeException('Legacy personal account was not merged without losing its title or IBAN.');
}
echo "Legacy personal bank type, title and IBAN preserved\n";
