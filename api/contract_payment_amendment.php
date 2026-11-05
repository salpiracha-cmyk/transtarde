<?php
declare(strict_types=1);

/** Apply one issued contract's payment wording to the already locked Exports root. */
function contract_payment_amendment(string $stored, array $body, array $user, int $base, int $version): array {
    $id=$body['id']??null;
    $request=$body['requestId']??null;
    $changes=$body['changes']??null;
    $strings=['paymentSectionOverride','paymentWordingOverride','balanceAdditionalText','paymentText'];
    if(!is_string($id)||$id===''||strlen($id)>180||!is_string($request)||!preg_match('/^[a-f0-9-]{36}$/D',$request)||!is_array($changes)||!$changes)throw new InvalidArgumentException('Invalid contract payment amendment.');
    foreach($changes as $key=>$value){
        if($key==='balanceAdditionalEnabled'&&is_bool($value))continue;
        if(!in_array($key,$strings,true)||!is_string($value)||strlen($value)>65536)throw new InvalidArgumentException('Only sales contract payment wording can be changed here.');
    }
    if(!os_owner($user)){
        ep_require($user,'contracts','View');
        ep_require($user,'contracts','Edit');
    }
    $root=json_decode($stored?:'{}',false,512,JSON_THROW_ON_ERROR);
    $matches=array_values(array_filter((array)($root->contracts??[]),static fn($row)=>($row->id??null)===$id));
    $contract=count($matches)===1?$matches[0]:null;
    if(!$contract||!os_scope($user,(array)$contract,[],false)||empty($contract->issued)||!empty($contract->cancelled)||in_array(strtolower($contract->status??''),['draft','closed','completed','cancelled'],true))throw new DomainException('Select an active issued contract in your company.');
    $hash=hash('sha256',tt_inv_json($changes));
    $versions=(array)($contract->versions??[]);
    $last=end($versions);
    $replayed=$version===$base+1&&($last->paymentRequest??'')===$request&&($last->paymentHash??'')===$hash&&($last->userId??null)===(int)($user['id']??0);
    if($version!==$base&&!$replayed)return ['conflict'=>true];
    if($replayed){
        $entry=(array)$last;unset($entry['snapshot']);
        return ['ack'=>['id'=>$id,'requestId'=>$request,'versionEntry'=>$entry,'audit'=>($root->audits??[])[0]??null,'amendedAt'=>$contract->lastAmendedAfterReceiptAt??null]];
    }

    $at=gmdate('c');
    $snapshot=clone $contract;
    $snapshot->versions=[];
    $entry=(object)['at'=>$at,'snapshot'=>$snapshot,'paymentRequest'=>$request,'paymentHash'=>$hash,'userId'=>(int)($user['id']??0)];
    foreach($changes as $key=>$value)$contract->$key=$value;
    $contract->versions=[...$versions,$entry];
    if(!empty($contract->received))$contract->lastAmendedAfterReceiptAt=$at;
    $audit=(object)['id'=>'AUD-'.$request,'at'=>$at,'user'=>(string)($user['full_name']??$user['username']??'Staff'),'area'=>'Sales Contract','action'=>'Amended payment wording','detail'=>(string)$contract->ref,'contractRef'=>(string)$contract->ref];
    $root->audits=[$audit,...(array)($root->audits??[])];
    $metadata=(array)$entry;unset($metadata['snapshot']);
    return ['value'=>tt_inv_json($root),'ack'=>['id'=>$id,'requestId'=>$request,'versionEntry'=>$metadata,'audit'=>$audit,'amendedAt'=>$contract->lastAmendedAfterReceiptAt??null]];
}
