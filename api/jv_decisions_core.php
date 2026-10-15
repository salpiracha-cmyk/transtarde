<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_reference.php';

function jvd_direct_post(array $user,string $entity):bool {
    return ($user['role']??'')==='Super Admin'
        && tt_user_can_module_action($user,'Accounts','jv','Approve')
        && tt_user_can_access_entity($user,$entity,'Approve');
}
function jvd_notice(array &$store,array $draft,string $status,string $postId=''):int {
    $recipients=array_values(array_unique(array_filter([(int)($draft['createdByUserId']??0),(int)($draft['submittedByUserId']??0)],static fn(int $id):bool=>$id>0)));
    if(!$recipients)return 0;
    $id=(int)($store['jvNoticeSequence']??0)+1;$store['jvNoticeSequence']=$id;
    $journal=$store['journals'][$postId]??[];
    $store['jvNotices'][]=['id'=>$id,'recipientIds'=>$recipients,'entity'=>(string)$draft['entity'],'jvId'=>(string)$draft['id'],'status'=>$status,'postId'=>$postId,'publicPostId'=>$postId!==''?tt_accounts_public_post($store,$journal):'','reason'=>$status==='Rejected'?(string)($draft['rejectionReason']??''):'','decidedAt'=>gmdate('c')];
    return $id;
}
function jvd_feed(array $store,array $user,?int $after):array {
    $latest=(int)($store['jvNoticeSequence']??0);$items=[];$uid=(int)($user['id']??0);
    if($after!==null&&$uid>0)foreach((array)($store['jvNotices']??[]) as $notice){
        if(!is_array($notice)||(int)($notice['id']??0)<=$after||!in_array($uid,(array)($notice['recipientIds']??[]),true)
            ||!tt_user_can_module_action($user,'Accounts','jv','View')||!tt_user_can_access_entity($user,(string)($notice['entity']??''),'View'))continue;
        unset($notice['recipientIds']);$items[]=$notice;
    }
    return ['ok'=>true,'cursor'=>$latest,'notices'=>$items];
}
