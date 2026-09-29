<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/auth_store.php';

function qa_read_only_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}

$qa=['username'=>'qa.assistant','system_qa'=>true,'test_data_only'=>true];
$ordinary=['username'=>'staff.user'];

foreach(['GET','HEAD','OPTIONS'] as $method){
    $_SERVER['REQUEST_METHOD']=$method;
    qa_read_only_assert(!tt_managed_qa_write_blocked($qa),$method.' must remain available to the production QA account');
}

foreach(['POST','PUT','PATCH','DELETE'] as $method){
    $_SERVER['REQUEST_METHOD']=$method;
    qa_read_only_assert(tt_managed_qa_write_blocked($qa),$method.' must be blocked for the production QA account');
    qa_read_only_assert(!tt_managed_qa_write_blocked($ordinary),$method.' must continue through the normal permission path for ordinary users');
}

echo "PASS production QA account is read-only\n";
