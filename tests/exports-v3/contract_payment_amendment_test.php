<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/contract_payment_amendment.php';
require dirname(__DIR__,2).'/api/export_permission_policy.php';
require dirname(__DIR__,2).'/api/operations_scope.php';
require dirname(__DIR__,2).'/inventory_reconciliation.php';

function tt_user_can_module_action($user,$module,$icon,$action){return in_array($action,$user['actions'],true);}
function tt_user_business_entity($user,$entity,$action){return $entity===$user['entity'];}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}

$root=(object)['contracts'=>[(object)['id'=>'QA-1','ref'=>'QA/1','seller'=>'BRM','issued'=>true,'received'=>true,'paymentSectionOverride'=>'Old'],(object)['id'=>'QA-2','ref'=>'QA/2','issued'=>true,'paymentText'=>'Untouched']],
    'shipments'=>[(object)['id'=>'QA-S','contractRef'=>'QA/1','artwork'=>str_repeat('a',17*1024*1024),'customs'=>(object)[]]],'fi'=>[(object)['id'=>'FI-1']],'millSync'=>(object)[]];
$stored=tt_inv_json($root);
$user=['id'=>1,'full_name'=>'Operator','entity'=>'BRM','actions'=>['View','Edit']];
$request=['id'=>'QA-1','requestId'=>'dbe71234-004a-41ea-abef-1234567890ab','changes'=>['paymentSectionOverride'=>'New wording','paymentText'=>'New wording']];
$result=contract_payment_amendment($stored,$request,$user,5,5);
$saved=json_decode($result['value']);
check(strlen(tt_inv_json($result['ack']))<1024,'Acknowledgement included unrelated data');
check($saved->contracts[0]->paymentSectionOverride==='New wording'&&$saved->contracts[0]->versions[0]->snapshot->paymentSectionOverride==='Old','Amendment or prior revision missing');
check($saved->contracts[0]->lastAmendedAfterReceiptAt===$result['ack']['amendedAt'],'Receipt amendment marker missing');
foreach(['shipments','fi','millSync'] as $key)check(tt_inv_json($saved->$key)===tt_inv_json($root->$key),'Unrelated records changed');
check(tt_inv_json($saved->contracts[1])===tt_inv_json($root->contracts[1]),'Second contract changed');
$retry=contract_payment_amendment($result['value'],$request,$user,5,6);
check(!isset($retry['value'])&&tt_inv_json($retry['ack'])===tt_inv_json($result['ack']),'Retry created a duplicate or returned another acknowledgement');
check(contract_payment_amendment($result['value'],$request,$user,4,6)===['conflict'=>true],'Stale save overwrote newer data');
foreach([['actions'=>['View']],['entity'=>'TTI']] as $override){
    try{contract_payment_amendment($stored,$request,array_replace($user,$override),5,5);throw new RuntimeException('Unauthorized amendment accepted');}catch(DomainException){}
}
try{contract_payment_amendment($stored,array_replace($request,['changes'=>['seller'=>'TG']]),$user,5,5);throw new RuntimeException('Unrelated update accepted');}catch(InvalidArgumentException){}
echo "PASS contract payment amendment on an oversized workspace, exact retry, authorization and untouched shipment data\n";
