<?php
declare(strict_types=1);

/** Rebuild the user's scoped input inside the existing versioned save transaction. */
function operations_contract_input(array $body,string $key,string $module): ?string {
    if(!array_key_exists('contractChanges',$body))return null;
    if($key!=='transtrade_export_v3_operational'||$module!=='Exports'||!is_string($body['contractChanges']))throw new InvalidArgumentException('Invalid sales contract save.');
    return $body['contractChanges'];
}

function operations_expand_contract_changes(string $patch,array $values,array $user): string {
    $key='transtrade_export_v3_operational';
    $visible=os_root_projection($user,os_root($values));
    if($visible===null)throw new DomainException('Sales contracts are outside your Exports access.');
    $document=json_decode(tt_inv_json((object)$visible),false,512,JSON_THROW_ON_ERROR);
    $changes=json_decode(tt_inv_restore_artwork($patch,tt_inv_public_values($values)['values'],16*1024*1024),false,512,JSON_THROW_ON_ERROR);
    if(!is_array($changes)||count($changes)>10000)throw new InvalidArgumentException('Invalid sales contract changes.');
    $bagOrders=[];
    foreach($changes as $change){
        $path=$change->path??null;$action=$change->action??null;
        if($action==='replaceBagPoLines'){
            if($path!==['millSync','newExportBags']||!is_string($change->contractRef??null)||$change->contractRef===''||!is_string($change->poNo??null)||$change->poNo===''||!is_string($change->shipmentId??null)||$change->shipmentId===''||!is_array($change->value??null)||!$change->value)throw new InvalidArgumentException('Invalid Bag Order handoff.');
            $lines=[];
            foreach($change->value as $row){
                if(!is_object($row)||($row->contractRef??null)!==$change->contractRef||($row->poNo??null)!==$change->poNo||!is_int($row->line??null)||$row->line<1||$row->line>count($change->value)||isset($lines[$row->line]))throw new InvalidArgumentException('Bag Order lines must have unique line numbers.');
                $lines[$row->line]=$row;
            }
            if(!isset($document->millSync)||$document->millSync===[])$document->millSync=(object)[];
            $document->millSync->newExportBags=array_values(array_filter((array)($document->millSync->newExportBags??[]),static fn($row)=>($row->contractRef??null)!==$change->contractRef||($row->poNo??null)!==$change->poNo));
            array_push($document->millSync->newExportBags,...array_values($lines));
            $bagOrders[]=$change;
            continue;
        }
        if(!is_array($path)||count($path)<1||count($path)>24||!is_string($path[0])||!in_array($action,['set','remove','append','prepend','order'],true))throw new InvalidArgumentException('Invalid sales contract change path.');
        $cursor=&$document;$last=array_pop($path);
        foreach($path as $depth=>$segment){
            if(is_string($segment)&&$cursor===[])$cursor=(object)[];
            if(is_object($segment)&&isset($segment->id)&&is_array($cursor)){
                $indices=array_keys(array_filter($cursor,static fn($row)=>is_object($row)&&($row->id??null)===$segment->id));
                if(count($indices)!==1)throw new InvalidArgumentException('A linked record changed. Reload Exports before saving the contract.');
                $cursor=&$cursor[$indices[0]];
            }elseif(is_int($segment)&&is_array($cursor)&&array_key_exists($segment,$cursor)){
                $cursor=&$cursor[$segment];
            }elseif(is_string($segment)&&$cursor instanceof stdClass){
                if(!property_exists($cursor,$segment)){
                    $next=$path[$depth+1]??$last;
                    if($next===null&&in_array($action,['append','prepend'],true))$cursor->$segment=[];
                    elseif(is_string($next)&&$action!=='remove')$cursor->$segment=(object)[];
                    else throw new InvalidArgumentException('A linked record changed. Reload Exports before saving the contract.');
                }
                $cursor=&$cursor->$segment;
            }else throw new InvalidArgumentException('Invalid sales contract change path.');
        }
        if(is_string($last)&&$cursor===[])$cursor=(object)[];
        if(in_array($action,['append','prepend','order'],true)){
            if($last!==null||!is_array($cursor)||!isset($change->value)||!is_array($change->value))throw new InvalidArgumentException('Invalid sales contract list change.');
            if($action==='append')$cursor=[...$cursor,...$change->value];
            elseif($action==='prepend')$cursor=[...$change->value,...$cursor];
            else{
                $indexed=[];
                foreach($cursor as $row){
                    if(!is_object($row)||(!is_string($row->id??null)&&!is_int($row->id??null))||isset($indexed[gettype($row->id).':'.$row->id]))throw new InvalidArgumentException('Sales contract list identities changed.');
                    $indexed[gettype($row->id).':'.$row->id]=$row;
                }
                $ordered=[];
                foreach($change->value as $id){
                    if(!is_string($id)&&!is_int($id))throw new InvalidArgumentException('Invalid sales contract list identity.');
                    $identity=gettype($id).':'.$id;
                    if(!isset($indexed[$identity]))throw new InvalidArgumentException('Sales contract list identities changed.');
                    $ordered[]=$indexed[$identity];unset($indexed[$identity]);
                }
                $cursor=$ordered;
            }
        }elseif(is_string($last)&&$cursor instanceof stdClass){
            if($action==='remove')unset($cursor->$last);
            else $cursor->$last=$change->value??null;
        }elseif($action==='set'&&is_int($last)&&is_array($cursor)&&array_key_exists($last,$cursor)){
            $cursor[$last]=$change->value??null;
        }else throw new InvalidArgumentException('Invalid sales contract field change.');
        unset($cursor);
    }
    foreach($bagOrders as $change){
        $matched=false;
        foreach((array)($document->shipments??[]) as $shipment){
            if(($shipment->id??null)!==$change->shipmentId||($shipment->contractRef??null)!==$change->contractRef)continue;
            foreach((array)($shipment->bagOrders??[]) as $order){
                if(($order->poNo??null)===$change->poNo&&empty($order->cancelled)&&count((array)($order->lines??[]))===count($change->value))$matched=true;
            }
        }
        if(!$matched)throw new InvalidArgumentException('The Bag Order and its Mill handoff do not match.');
    }
    return tt_inv_json($document);
}

/** The editor identifies the contract and its audit event; generic Exports writes keep their version gate. */
function operations_contract_request(array $body,?string $patch): ?array {
    if(!array_key_exists('contractSave',$body))return null;
    $request=$body['contractSave'];
    if($patch===null||!is_array($request)||!is_string($request['id']??null)||$request['id']===''||strlen($request['id'])>180||
        !is_string($request['auditId']??null)||!preg_match('/^AUD-[a-zA-Z0-9_-]{1,120}$/D',$request['auditId'])||(!is_null($request['processId']??null)&&(!is_string($request['processId'])||strlen($request['processId'])>180))||!is_array($request['guards']??null))throw new InvalidArgumentException('Invalid sales contract save identity.');
    $request['fingerprint']=hash('sha256',$patch);
    return $request;
}

function operations_contract_receipt(array $root,array $request): ?array {
    foreach((array)($root['audits']??[]) as $row){
        if(($row['id']??null)!==$request['auditId'])continue;
        if(($row['saveFingerprint']??null)!==$request['fingerprint']||($row['saveContractId']??null)!==$request['id'])return null;
        foreach((array)($root['contracts']??[]) as $contract)if(($contract['id']??null)===$request['id']){
            $processId=$request['processId']??null;
            if($processId!==null&&!array_filter((array)($root['shipments']??[]),static fn($row)=>($row['id']??null)===$processId&&($row['contractRef']??null)===$contract['ref']))return null;
            return ['auditId'=>$request['auditId'],'id'=>$request['id']];
        }
    }
    return null;
}

function operations_contract_can_rebase(string $patch,array $request,array $visible): bool {
    $rows=['contracts','shipments','customers','fi','deletedShipments'];
    $lookup=static function(array $items,mixed $id): ?array {
        foreach($items as $row)if(is_array($row)&&($row['id']??null)===$id)return $row;
        return null;
    };
    $root=$visible;
    foreach(json_decode($patch,true,512,JSON_THROW_ON_ERROR) as $change){
        $path=$change['path']??[];$collection=$path[0]??null;$selector=$path[1]??null;
        if(!in_array($collection,[...$rows,'audits'],true))return false;
        if(count($path)===2&&$selector===null&&in_array($change['action']??null,['append','prepend'],true)){
            foreach((array)($change['value']??[]) as $row){
                $id=$row['id']??null;
                if(!is_string($id)||$id===''||$lookup((array)($root[$collection]??[]),$id))return false;
            }
            continue;
        }
        $id=is_array($selector)?($selector['id']??null):null;
        if(count($path)<3||!is_string($id)||!in_array($collection,$rows,true))return false;
        $expected=$request['guards'][$collection][$id]??null;
        $current=$lookup((array)($root[$collection]??[]),$id);
        if(!$expected||!$current||!operations_same_value($expected,json_decode(tt_inv_artwork_json(tt_inv_json($current)),true,512,JSON_THROW_ON_ERROR)))return false;
    }
    return true;
}

function operations_contract_candidate(string $patch,array $request,array $values,array $user): string {
    foreach((array)(os_root($values)['audits']??[]) as $row)if(($row['id']??null)===$request['auditId'])throw new InvalidArgumentException('This sales contract save identity is already in use.');
    $root=json_decode(operations_expand_contract_changes($patch,$values,$user),true,512,JSON_THROW_ON_ERROR);
    $contract=null;
    foreach((array)($root['contracts']??[]) as $row)if(($row['id']??null)===$request['id'])$contract=$row;
    if(!$contract)throw new InvalidArgumentException('The sales contract was not found in this save.');
    $ref=(string)($contract['ref']??'');
    if($ref==='')throw new InvalidArgumentException('Enter a sales contract reference.');
    foreach($root['contracts'] as $row)if(($row['id']??null)!==$request['id']&&strcasecmp((string)($row['ref']??''),$ref)===0)throw new InvalidArgumentException('This sales contract reference already exists.');
    foreach((array)($root['audits']??[]) as $index=>$row){
        if(($row['id']??null)!==$request['auditId'])continue;
        if(($row['area']??null)!=='Sales Contract'||($row['contractRef']??null)!==$ref)break;
        $root['audits'][$index]['saveFingerprint']=$request['fingerprint'];
        $root['audits'][$index]['saveContractId']=$request['id'];
        return tt_inv_json($root);
    }
    throw new InvalidArgumentException('The sales contract save audit is missing.');
}

function operations_replaced_bag_pos(?string $patch): array {
    if($patch===null)return [];
    $keys=[];
    foreach(json_decode($patch,true,512,JSON_THROW_ON_ERROR) as $change)
        if(($change['action']??null)==='replaceBagPoLines')$keys[$change['contractRef']][$change['poNo']]=true;
    return $keys;
}
