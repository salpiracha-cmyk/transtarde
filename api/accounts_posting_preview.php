<?php
declare(strict_types=1);
/** Only controllers that load this preview contract can simulate Post IDs. */
function tt_posting_preview_mode():bool {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return false;
    $raw=function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input');
    $request=is_string($raw)?json_decode($raw,true):null;
    return is_array($request)&&!empty($request['preview']);
}
function tt_preview_request_body(array $request): array {unset($request['preview']);return $request;}

/** Controllers run their usual authorised validation on their locked in-memory
 * store. A preview exits before any persisted data or follow-up effects. */
function tt_posting_preview_exit(array $request,array $store,array $before,array $extra=[]): void {
    if(empty($request['preview']))return;
    $journals=[];
    foreach((array)($store['journals']??[]) as $id=>$journal){
        if(in_array((string)$id,$before,true)||!is_array($journal)||($journal['status']??'')!=='Posted')continue;
        $journal['id']='PREVIEW';$journals[]=$journal;
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>true,'previews'=>$journals]+$extra,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
