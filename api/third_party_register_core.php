<?php
declare(strict_types=1);
/** Informational register only. Never loads or posts accounting/operational records. */
function tpr_permission(array $user,string $action):bool {
    return tt_user_can_open_module($user,'Accounts')&&(tt_user_can_module_action($user,'Accounts','supplier',$action)||($action==='View'&&tt_user_can_module_action($user,'Accounts','reports','View')));
}
function tpr_text(mixed $value,string $label,int $limit,bool $required=false):string {
    if(!is_string($value)||strlen(trim($value))>$limit)throw new InvalidArgumentException($label.' must be text within '.$limit.' characters.');$text=trim($value);if($required&&$text==='')throw new InvalidArgumentException($label.' is required.');return $text;
}
function tpr_date(mixed $value):string {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new InvalidArgumentException('Enter a valid payment date.');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Enter a valid payment date.');return $value;
}
function tpr_values(array $body):array {
    $date=tpr_date($body['date']??null);$amount=$body['amount']??null;if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||round((float)$amount,2)<=0||(float)$amount>999999999999.99)throw new InvalidArgumentException('Enter a positive amount.');
    $currency=strtoupper(tpr_text($body['currency']??'','Currency',3,true));if(!in_array($currency,['PKR','USD','AED','EUR','GBP'],true))throw new InvalidArgumentException('Choose a supported currency.');
    $category=tpr_text($body['category']??'Other','Paid-to category',30,true);if(!in_array($category,['Supplier','Broker','Freight Forwarder','Other'],true))throw new InvalidArgumentException('Choose a paid-to category.');
    return ['date'=>$date,'amount'=>round((float)$amount,2),'currency'=>$currency,'receivedFrom'=>tpr_text($body['receivedFrom']??'','Received from',200,true),'paidTo'=>tpr_text($body['paidTo']??'','Paid to',200,true),'category'=>$category,'reference'=>tpr_text($body['reference']??'','Cheque / reference',180),'notes'=>tpr_text($body['notes']??'','Notes',1200)];
}
function tpr_change(array $store,array $body,array $user,string $entity):array {
    $action=(string)($body['action']??'');$allowed=['action','csrf','entity','id','version','requestKey','reason','date','amount','currency','receivedFrom','paidTo','category','reference','notes'];if(array_diff(array_keys($body),$allowed))throw new InvalidArgumentException('Only register details can be saved here.');
    $rows=(array)($store['rows']??[]);$actor=(string)($user['full_name']??$user['username']??'Staff');$now=gmdate('c');
    if($action==='create'){
        $key=tpr_text($body['requestKey']??'','Save identity',100,true);if(!preg_match('/^[A-Za-z0-9._:-]{16,100}$/D',$key))throw new InvalidArgumentException('Reopen the new record before saving.');$values=tpr_values($body);$fingerprint=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
        foreach($rows as $row)if(($row['entity']??'')===$entity&&($row['requestKey']??'')===$key){if(($row['requestFingerprint']??'')!==$fingerprint)throw new InvalidArgumentException('This save was already used for different details. Reopen the saved record.');return ['store'=>$store,'record'=>$row,'duplicate'=>true];}
        $id='TPR-'.bin2hex(random_bytes(10));$row=$values+['id'=>$id,'entity'=>$entity,'version'=>1,'status'=>'Active','createdAt'=>$now,'createdBy'=>$actor,'requestKey'=>$key,'requestFingerprint'=>$fingerprint,'history'=>[]];
    }elseif(in_array($action,['edit','delete'],true)){
        $id=tpr_text($body['id']??'','Record',100,true);$old=$rows[$id]??null;if(!$old||($old['entity']??'')!==$entity||($old['status']??'')!=='Active')throw new InvalidArgumentException('Register record not found in these company records.');if((int)($body['version']??0)!==(int)$old['version'])throw new InvalidArgumentException('This record changed. Reopen and compare before saving.');
        $reason=tpr_text($body['reason']??'','Change reason',500,true);$history=(array)($old['history']??[]);$history[]=['action'=>$action,'at'=>$now,'by'=>$actor,'reason'=>$reason,'before'=>array_diff_key($old,['history'=>true])];
        $row=array_replace($old,$action==='edit'?tpr_values($body):[],['version'=>$old['version']+1,'status'=>$action==='delete'?'Deleted':'Active','updatedAt'=>$now,'updatedBy'=>$actor,'history'=>$history]);
    }else throw new InvalidArgumentException('Choose a valid register action.');
    $store['rows'][$id]=$row;$store['revision']=(int)($store['revision']??0)+1;return ['store'=>$store,'record'=>$row,'duplicate'=>false];
}
function tpr_public(array $store,string $entity):array {
    $rows=[];foreach((array)($store['rows']??[]) as $row)if(($row['entity']??'')===$entity&&($row['status']??'')==='Active'){$row=array_diff_key($row,array_flip(['requestKey','requestFingerprint','history']));$rows[]=$row;}usort($rows,static fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcmp($b['id'],$a['id']));return $rows;
}
