<?php
declare(strict_types=1);

/** A file name is a locator; permission comes from its canonical financial record. */
function tt_accounts_document_view(array $user,array $icons): bool {
    foreach($icons as $icon)if(tt_user_can_module_action($user,'Accounts',$icon,'View'))return true;
    return false;
}
function tt_accounts_document_owner(array $user,string $token,string $collection,string $field,string $requestedEntity='',?array &$documentRecord=null): string {
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/D',$token)||str_contains($token,'..'))tt_api_json_error(422,'A valid file reference is required.');
    $path=TT_DATA_DIR.'/accounts.json';$h=fopen($path,'rb');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage is unavailable.');
    try{$raw=stream_get_contents($h);$store=json_decode($raw===false?'':$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($store))throw new RuntimeException('Accounts storage is invalid.');}finally{flock($h,LOCK_UN);fclose($h);}
    $owners=[];$matches=[];foreach((array)($store[$collection]??[]) as $record)if(is_array($record)&&($record[$field]??'')===$token){$owners[(string)($record['entity']??'')]=true;$matches[]=$record;}
    if(count($owners)!==1)tt_api_json_error(404,'Document reference is unavailable or requires reconciliation.');
    $entity=(string)array_key_first($owners);
    if(!in_array($entity,['TTI','BRM'],true)||($requestedEntity!==''&&$entity!==$requestedEntity)||!tt_user_can_access_entity($user,$entity,'View'))tt_api_json_error(403,'Document company View permission required.');
    $documentRecord=count($matches)===1?$matches[0]:null;return $entity;
}
