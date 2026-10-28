<?php
declare(strict_types=1);
define('TT_EXPENSE_REVIEW_LIBRARY',true);
require __DIR__.'/expense_ledger_review.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$s=['revision'=>17,'generalExpenses'=>['G1'=>['entity'=>'TTI','payee'=>'Fixture tanker','amount'=>15000,'status'=>'Posted'],'G2'=>['entity'=>'TTI','status'=>'Amended'],'G3'=>['entity'=>'TG','status'=>'Posted']],'supplierBills'=>['B1'=>['entity'=>'TTI','expenseInvoice'=>true,'status'=>'Posted'],'B2'=>['entity'=>'TTI','category'=>'FREIGHT','status'=>'Posted']],'journals'=>['J1'=>['entity'=>'TTI','status'=>'Posted','lines'=>[['account'=>'6900','debit'=>15000,'subaccountId'=>'S1','expenseRecipientId'=>'P1']]],'OLD'=>['entity'=>'TTI','status'=>'Reversed','lines'=>[['account'=>'6900','subaccountId'=>'S1']]],'TG'=>['entity'=>'TG','lines'=>[['account'=>'6900']]]],'accountSubaccounts'=>['S1'=>['entity'=>'TTI','name'=>'Fixture recipient']],'paymentPayees'=>['TTI|P1'=>['entity'=>'TTI','id'=>'P1','name'=>'Fixture tanker']]];
$before=serialize($s);$p=elr_collect($s,['6900'=>['class'=>'Expense','name'=>'Office']]);
check(serialize($s)===$before,'Projection must not mutate source');
check($p['readOnly']&&$p['revision']===17&&count($p['records'])===2,'Only active native expense records in Pakistan books');
check(count($p['journals'])===2,'Retain expense history, exclude other company');
check($p['accountSubaccounts']['S1']['historicalJournalReferences']===2,'Retired history still prevents unsafe ledger deletion');
check($p['paymentPayees']['TTI|P1']['historicalJournalReferences']===1,'Retain recipient reference count');
$private=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$public=openssl_pkey_get_details($private)['key'];$e=elr_encrypt($p,$public);
check(!str_contains(json_encode($e),'Fixture tanker'),'No plaintext financial records in output');
check(openssl_private_decrypt(base64_decode($e['wrappedKey']),$key,$private,OPENSSL_PKCS1_OAEP_PADDING),'Unwrap local-only encryption key');
$plain=openssl_decrypt(base64_decode($e['ciphertext']),'aes-256-gcm',$key,OPENSSL_RAW_DATA,base64_decode($e['iv']),base64_decode($e['tag']));
check($plain!==false&&hash('sha256',$plain)===$e['sha256']&&json_decode($plain,true)===$p,'Encrypted report round trip');
echo "PASS read-only review: active scope, immutable input, historical references and authenticated encryption round trip.\n";
