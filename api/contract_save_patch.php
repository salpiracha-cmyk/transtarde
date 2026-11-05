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
    foreach($changes as $change){
        $path=$change->path??null;$action=$change->action??null;
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
                if(!property_exists($cursor,$segment)&&in_array($action,['append','prepend'],true)&&($depth===0||($depth===1&&$path[0]==='millSync')))$cursor->$segment=$path[0]==='millSync'&&$depth===0?(object)[]:[];
                if(!property_exists($cursor,$segment))throw new InvalidArgumentException('A linked record changed. Reload Exports before saving the contract.');
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
    return tt_inv_json($document);
}
