<?php
declare(strict_types=1);
function ps_location(array $masters,array $run): ?array {
    $scope=(array)($run['bagAllocationScope']??[]);$id=(string)(($run['locationId']??'')?:($scope['locationId']??''));$name=(string)($run['millName']??$run['mill']??'');$matches=[];
    foreach((array)($masters['mills']??[]) as $row){$v=array_values((array)($row['values']??[]));if($id!==''?(string)($row['id']??'')!==$id:ps_norm($v[0]??'')!==ps_norm($name))continue;$matches[]=['locationId'=>(string)$row['id'],'millName'=>(string)($v[0]??''),'status'=>(string)($v[5]??'')];}
    return count($matches)===1?$matches[0]:null;
}
function ps_norm(mixed $value): string {return strtolower(trim((string)preg_replace('/\\s+/',' ',(string)$value)));}
function ps_access(array $user,string $entity,array $location,string $action): void {
    if(!in_array($entity,['TTI','BRM'],true)||!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill','production',$action))throw new DomainException('Production '.$action.' permission is required.');
    $assigned=ps_norm($user['location']??'');if(($user['role']??'')!=='Super Admin'&&!in_array($assigned,['','all locations','all authorized locations'],true)&&$assigned!==ps_norm($location['millName']))throw new DomainException('This production belongs to another assigned location.');
    foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,(array)($user['permissions']['Accounts']??[]))){if(!tt_user_can_access_entity($user,$entity,$action))throw new DomainException('Production company permission is required.');break;}
}


/** Derived packing history and amendment evidence share the production transaction. */
function ps_effects(array $before,array $after,array $user,string $now): array {
    $old=[];foreach(tt_inv_rows($before,'tt30prod') as $r)$old[(string)$r['id']]=$r;
    $issues=tt_inv_rows($before,'tt36bagissues');$audit=tt_inv_rows($before,'tt30prodaudit');$changed=false;
    foreach(tt_inv_rows($after,'tt30prod') as $r){
        $id=(string)$r['id'];$prior=$old[$id]??null;$request=(string)($r['clientSaveRequestId']??'');
        if($request===''||($prior&&$prior===$r))continue;
        if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$request))throw new DomainException('Production requires a stable save identity.');
        if($prior&&($prior['clientSaveRequestId']??'')===$request){$a=$r;$b=$prior;foreach(['shiftEntriesComplete','updatedAt','createdAt','serverSequence'] as $field){unset($a[$field],$b[$field]);}if($a==$b)continue;throw new DomainException('This production save identity already belongs to different saved facts.');}
        $masters=tt_list_masters();$location=ps_location($masters,$r);if(!$location)throw new DomainException('Resolve the production location in Mills & Locations.');
        ps_access($user,(string)($r['entity']??''),$location,$prior?'Edit':'Create');
        if($prior){$previousLocation=ps_location($masters,$prior);if(!$previousLocation)throw new DomainException('Resolve the original production location before amending.');ps_access($user,(string)($prior['entity']??''),$previousLocation,'Edit');}
        $usage=[];foreach((array)($r['rows']??[]) as $row){if(!is_array($row)||tt_inv_is_private_row($row)||!empty($row['noStockPost']))continue;
            if(!preg_match('/^(?:Ready Rice|B2)\s*—\s*(.+)$/u',(string)($row['product']??''),$m))continue;$brand=trim($m[1]);if(in_array(strtoupper($brand),['LOCAL','LOCAL BAGS'],true))continue;
            $qty=(float)($row['actualBagCount']??$row['bags']??0);if(!is_finite($qty)||$qty<0)throw new DomainException('Production bag usage must be a valid actual quantity.');if($qty>0)$usage[$brand]=($usage[$brand]??0)+$qty;
        }
        $issues=array_values(array_filter($issues,static fn($i)=>(string)($i['productionId']??'')!==$id));
        $issues[]=['productionId'=>$r['id'],'date'=>$r['date'],'shift'=>$r['shift'],'entity'=>$r['entity'],'millId'=>$r['millId']??null,'millName'=>$location['millName'],'locationId'=>$location['locationId'],'usage'=>$usage,'time'=>$now,'userId'=>(int)$user['id'],'requestKey'=>$request,'serverManaged'=>true];
        if($prior)$audit[]=['id'=>'PA-'.substr(hash('sha256',$id.'|'.$request),0,24),'productionId'=>$r['id'],'user'=>(string)($user['full_name']??$user['username']??''),'userId'=>(int)$user['id'],'time'=>$now,'directorAlert'=>true,'changes'=>['Production report amended; original and replacement retained.'],'before'=>$prior,'after'=>$r,'requestKey'=>$request,'serverManaged'=>true];
        $changed=true;
    }
    if($changed){$after['tt36bagissues']=tt_inv_json($issues);$after['tt30prodaudit']=tt_inv_json($audit);}return $after;
}
