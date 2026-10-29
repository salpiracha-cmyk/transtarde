<?php
declare(strict_types=1);

/** Dedicated Directors master. Legacy IDs are retained so existing SODA accounts stay intact. */
function fe_parties(array $s,string $entity):array {
    $out=[];$used=[];$snapshots=[];
    foreach((array)($s['exporterDeals']??[]) as $d)if(($d['entity']??'')===$entity){$used[(string)$d['partyId']]=true;$snapshots[(string)$d['partyId']]=(string)$d['party'];}
    foreach(dt_legacy_parties() as $p)if(!empty($p['isExporter'])||isset($used[$p['id']]))$out[$p['id']]=$p+['entity'=>$entity,'version'=>0,'active'=>true,'contact'=>'','notes'=>'','legacy'=>true];
    foreach($snapshots as $id=>$name)if(!isset($out[$id]))$out[$id]=['id'=>$id,'entity'=>$entity,'name'=>$name,'isExporter'=>true,'version'=>0,'active'=>true,'contact'=>'','notes'=>'','legacy'=>true];
    foreach((array)($s['fellowExporterParties']??[]) as $p)if(($p['entity']??'')===$entity){if(empty($p['active']))unset($out[$p['id']]);else $out[$p['id']]=$p+['isExporter'=>true,'legacy'=>false];}
    $out=array_values($out);usort($out,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
}
function fe_save_party(array &$s,string $entity,array $b,array $u,bool $deleting=false):array {
    $id=gm_text($b['partyId']??'',120);$old=null;
    foreach(fe_parties($s,$entity) as $p)if($p['id']===$id)$old=$p;
    if($id!==''&&!$old)throw new DomainException('Choose an active fellow exporter in these company books.');
    if($old&&(int)($b['version']??-1)!==(int)$old['version'])throw new DomainException('Fellow exporter changed. Reopen the master before saving.');
    if($deleting){
        if(!$old)throw new DomainException('Choose the fellow exporter to delete.');
        foreach((array)($s['exporterDeals']??[]) as $d)if(($d['entity']??'')===$entity&&($d['partyId']??'')===$id&&($d['status']??'')!=='Deleted')throw new DomainException('This exporter has an active SODA. Delete an unused SODA first; linked trading history stays protected.');
        $next=array_replace($old,['active'=>false]);
    }else{
        $name=gm_text($b['name']??'',180);if($name==='')throw new DomainException('Enter the fellow exporter name.');
        foreach(fe_parties($s,$entity) as $p)if($p['id']!==$id&&sac_normal($p['name'])===sac_normal($name))throw new DomainException('This fellow exporter already exists. Select or edit its original master.');
        if($id===''){$matches=array_values(array_filter(dt_legacy_parties(),static fn($p)=>sac_normal($p['name'])===sac_normal($name)));if(count($matches)>1)throw new DomainException('More than one original business identity has this name. Resolve the duplicate before adding a fellow exporter.');$id=$matches[0]['id']??dt_id((array)($s['fellowExporterParties']??[]),'FEXP');}
        $code=strtoupper(gm_text($b['code']??($old['code']??''),30));if($code!==''&&!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/D',$code))throw new DomainException('Use letters, numbers, dash, dot or underscore in the short code.');foreach(fe_parties($s,$entity)as$p)if($p['id']!==$id&&$code!==''&&strcasecmp((string)($p['code']??''),$code)===0)throw new DomainException('This fellow exporter short code already exists.');
        $next=['id'=>$id,'entity'=>$entity,'name'=>$name,'code'=>$code,'contact'=>gm_text($b['contact']??'',250),'notes'=>gm_text($b['notes']??'',1000),'active'=>true,'isExporter'=>true];
    }
    $reason=gm_text($b['reason']??'',500);if($old&&strlen($reason)<5)throw new DomainException('Enter a reason for changing or deleting this fellow exporter.');
    $next['version']=(int)($old['version']??0)+1;$next['updatedAt']=gmdate('c');$next['updatedBy']=$u['username']??'';
    $s['fellowExporterParties'][$entity.'|'.$id]=$next;$s['fellowExporterAudit'][]=['action'=>$deleting?'DELETE':'SAVE','entity'=>$entity,'id'=>$id,'before'=>$old,'after'=>$next,'reason'=>$reason,'by'=>$u['username']??'','at'=>gmdate('c')];
    return ['partyId'=>$id];
}
function fe_delete_issue(array $s,array $d,array $nativeSales):string {
    if(($d['status']??'')==='Deleted')return 'This SODA has already been deleted.';
    if(!empty($d['loadings'])||!empty($d['settlementPosts'])||!empty($d['payments']))return 'This SODA has loading or financial history. Correct its linked loading, settlement or receipt through the original workflow first.';
    if(!empty($d['millSaleRef']))return 'This SODA is linked to an original Mill record. Correct that link before deleting it.';
    foreach($nativeSales as $r)if(dt_native_parent_matches($d,$r))return 'A Mill loading or dispatch record is linked to this SODA. Correct the original Mill record first.';
    foreach(['purchaseSodas','purchaseSodasV2'] as $key)foreach((array)($s[$key]??[]) as $purchase)foreach((array)($purchase['buyingForAllocations']??[]) as $allocation)if(($allocation['targetId']??'')==='EXPORTER|'.$d['id'])return 'A purchase is allocated to this SODA. Remove or correct that original buying-for allocation first.';
    foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$d['entity']&&($j['meta']['exporterDealId']??'')===$d['id'])return 'This SODA has accounting history. Use the original posting correction workflow first.';
    return '';
}
function fe_delete_deal(array &$s,array $d,array $b,array $u,array $nativeSales):array {
    $issue=fe_delete_issue($s,$d,$nativeSales);if($issue!=='')throw new DomainException($issue);
    $reason=gm_text($b['reason']??'',500);if(strlen($reason)<5)throw new DomainException('Enter a deletion reason of at least five characters.');
    $before=$d;$d['status']='Deleted';$d['version']++;$d['deletedAt']=gmdate('c');$d['deletedBy']=$u['username']??'';$d['deletionReason']=$reason;
    $d['history'][]=['action'=>'delete_deal','before'=>array_diff_key($before,['history'=>true]),'reason'=>$reason,'by'=>$u['username']??'','at'=>gmdate('c')];$s['exporterDeals'][$d['id']]=$d;
    return ['id'=>$d['id'],'deleted'=>true];
}
