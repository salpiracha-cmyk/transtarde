<?php
declare(strict_types=1);
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
