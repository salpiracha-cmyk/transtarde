<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_shipment_source.php';

function oel_text(mixed $value,int $max):string {
    if(!is_string($value)||mb_strlen(trim($value))>$max)throw new DomainException('Check the length of the entered details.');
    return trim($value);
}

function oel_right(array $user,string $module,string $entity,string $action):bool {
    return tt_user_can_open_module($user,$module)
        && tt_user_can_module_action($user,$module,$module==='Mill'?'export':'loading',$action)
        && tt_user_business_entity($user,$entity,$action);
}

function oel_company(array $lot,array $contract):string {
    $seller=$lot['seller']??$contract['seller']??'';
    return $seller==='TG'?(string)($lot['customs']['exporter']??$contract['customsExporter']??''):(string)$seller;
}

function oel_rows(array $root,array $user,string $module):array {
    $contracts=array_column($root['contracts']??[],null,'ref');
    $rows=[];
    foreach($root['shipments']??[] as $lot){
        if(($lot['kind']??'')!=='lot'||empty($lot['loadingPlan']['outsideDirect'])||!empty($lot['cancelled']))continue;
        $contract=$contracts[$lot['contractRef']]??[];
        $entity=oel_company($lot,$contract);
        $assignment=$lot['outsideLoading']??[];
        if(!in_array($entity,['TTI','BRM'],true)||!oel_right($user,$module,$entity,'View'))continue;
        if($module==='Mill'&&(($assignment['route']??'')!=='MILL'||(int)($assignment['assigneeId']??0)!==(int)$user['id']))continue;
        $allocations=$lot['loadingPlan']['allocations']??[];
        if(count($allocations)!==1||($allocations[0]['type']??'')!=='Ex-Mill'||trim((string)($allocations[0]['name']??''))==='')continue;
        $pack=$contract['packings'][$allocations[0]['packIndex']??-1]??[];
        $rows[]=['id'=>$lot['id'],'contract'=>$lot['contractRef'],'lot'=>$lot['lotId'],'entity'=>$entity,
            'site'=>$allocations[0]['name']??'','plannedDate'=>$lot['loadingPlan']['loadingDate']??'',
            'expected'=>(int)($lot['containers']??0),'brand'=>$pack['brand']??'','packing'=>($pack['size']??'').' '.($contract['packingUnit']??'KG'),
            'quality'=>$contract['quality']??'','specifications'=>array_values(array_map(static fn($spec)=>['name'=>$spec['name']??'','value'=>$spec['value']??''],array_filter((array)($contract['specRows']??$contract['specifications']??[]),'is_array'))),
            'assignment'=>array_intersect_key($assignment,array_flip(['dealId','supplier','route','assigneeId','assignee','version'])),
            'actuals'=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['number','seal','truck','bags','netKg','grossKg','loadingDate','evidence','evidenceFile','inspection','enteredBy'])),
                array_values(array_filter($lot['millActuals']??[],static fn($row)=>!empty($row['outsideDealId'])))),
            'complete'=>!empty($lot['millLoadingCompleted']),'closed'=>!empty($lot['completed']),'canEdit'=>oel_right($user,$module,$entity,'Edit')];
    }
    return $rows;
}

function oel_change(array $root,array $user,string $module,array $body,array $deals,string $today,array $file=[]):array {
    $visible=array_column(oel_rows($root,$user,$module),null,'id');
    $lotId=oel_text($body['lotId']??'',160);
    $row=$visible[$lotId]??null;
    if(!$row||!$row['canEdit']||$row['closed'])throw new DomainException('Open an authorized, active outside-loading lot.');
    $index=array_search($lotId,array_column($root['shipments'],'id'),true);
    if($index===false||($root['shipments'][$index]['id']??'')!==$lotId)throw new DomainException('Shipment identity is unavailable.');
    $lot=&$root['shipments'][$index];
    $action=$body['action']??'';
    if($action==='container'&&$module==='Exports'&&empty($lot['outsideLoading'])&&isset($body['dealId'])){
        $root=oel_change($root,$user,$module,[
            'action'=>'assign','lotId'=>$lotId,'version'=>$body['version']??-1,
            'dealId'=>$body['dealId'],'route'=>'EXPORTS',
        ],$deals,$today);
        $body['version']=$root['shipments'][$index]['outsideLoading']['version'];
        unset($body['dealId']);
        return oel_change($root,$user,$module,$body,$deals,$today,$file);
    }
    $old=$lot['outsideLoading']??[];
    if((int)($body['version']??-1)!==(int)($old['version']??0))throw new DomainException('This loading changed. Refresh and review the saved record.');
    $by=(string)($user['full_name']??$user['username']??'');
    if(!empty($lot['commercial']['saved'])||!empty($lot['bl']['draftSaved']))throw new DomainException('Reconcile the saved shipment documents before changing loading.');
    if($action==='assign'){
        if($module!=='Exports'||!empty($lot['millActuals']))throw new DomainException('Exports can assign a lot before any containers are saved.');
        $dealId=oel_text($body['dealId']??'',160);
        $deal=$deals[$dealId]??null;
        if(!$deal||($deal['entity']??'')!==$row['entity']||($deal['direction']??'')!=='PURCHASE'||($deal['contractRef']??'')!==$row['contract']||
            in_array(strtolower((string)($deal['status']??'')),['deleted','cancelled','closed','completed'],true))throw new DomainException('Choose an active purchase SODA linked to this sales contract.');
        $route=$body['route']??'';
        if(!in_array($route,['EXPORTS','MILL'],true))throw new DomainException('Choose who enters the containers.');
        $assignee=$route==='MILL'?tt_find_user_by_id((int)($body['assigneeId']??0)):$user;
        if(!$assignee||tt_managed_qa_write_blocked($assignee)||empty($assignee['active'])||!oel_right($assignee,$route==='MILL'?'Mill':'Exports',$row['entity'],'View')||
            !oel_right($assignee,$route==='MILL'?'Mill':'Exports',$row['entity'],'Edit'))throw new DomainException('Choose an active user with loading access to these company books.');
        $lot['outsideLoading']=['dealId'=>$dealId,'supplier'=>$deal['party'],'route'=>$route,'assigneeId'=>(int)$assignee['id'],
            'assignee'=>(string)($assignee['full_name']??$assignee['username']??'')];
    }elseif($action==='container'){
        if(!$old||($old['route']??'')!==strtoupper($module)||(int)$old['assigneeId']!==(int)$user['id']||$row['complete'])throw new DomainException('Only the assigned user can record an open loading.');
        $deal=$deals[$old['dealId']]??null;
        if(!$deal||($deal['entity']??'')!==$row['entity']||($deal['contractRef']??'')!==$row['contract']||($deal['direction']??'')!=='PURCHASE'||
            in_array(strtolower((string)($deal['status']??'')),['deleted','cancelled','closed','completed'],true))throw new DomainException('Review the linked purchase SODA before loading.');
        $number=strtoupper(preg_replace('/[-\s]/','',oel_text($body['number']??'',24)));
        if(!preg_match('/^[A-Z]{4}\d{7}$/D',$number))throw new DomainException('Enter a container number with four letters and seven digits.');
        $seal=oel_text($body['seal']??'',40);
        $truck=oel_text($body['truck']??'',40);
        $evidence=oel_text($body['evidence']??'',500);
        $inspection=oel_text($body['inspection']??'',2000);
        $date=oel_text($body['date']??'',10);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||$date>$today||$date<($deal['date']??''))throw new DomainException('Enter an actual loading date within the linked agreement.');
        $bags=filter_var($body['bags']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        $net=$body['netKg']??null;$gross=$body['grossKg']??null;
        if(!$bags||!is_numeric($net)||!is_numeric($gross)||!is_finite((float)$net)||!is_finite((float)$gross)||
            (float)$net<.001||(float)$gross<(float)$net||(float)$gross>100000||$seal===''||$truck===''||$evidence===''||
            ($module==='Mill'&&($inspection===''||($body['qualityAccepted']??'')!=='yes')))throw new DomainException('Complete truck, seal, bags, net and gross weights, evidence and any required inspection.');
        $existing=null;
        foreach($root['shipments'] as $other){
            if(!empty($other['cancelled']))continue;
            foreach($other['millActuals']??[] as $i=>$actual){
                if(strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($actual['number']??$actual['container']??$actual['containerNo']??'')))!==$number)continue;
                if($other['id']!==$lotId||($actual['outsideDealId']??'')!==$old['dealId'])throw new DomainException('This container already belongs to another loading.');
                $existing=$i;
            }
        }
        $actuals=$lot['millActuals']??[];
        if($existing===null&&count($actuals)>=$row['expected'])throw new DomainException('All planned containers are already recorded.');
        $net=round((float)$net,3);$gross=round((float)$gross,3);
        $dealKg=$net;
        foreach($root['shipments'] as $other)if(empty($other['cancelled']))foreach($other['millActuals']??[] as $i=>$actual){
            if(($actual['outsideDealId']??'')===$old['dealId']&&($other['id']!==$lotId||$i!==$existing))$dealKg+=(float)$actual['netKg'];
        }
        if($dealKg>(float)$deal['quantityMT']*1000+.0001)throw new DomainException('Actual weights exceed this SODA’s agreed tonnes.');
        $actual=['number'=>$number,'seal'=>$seal,'truck'=>$truck,'bags'=>$bags,'netKg'=>$net,'grossKg'=>$gross,'tareKg'=>round($gross-$net,3),
            'packIndex'=>(int)$lot['loadingPlan']['allocations'][0]['packIndex'],'brand'=>$row['brand'],'packing'=>$row['packing'],'location'=>$row['site'],
            'loadingDate'=>$date,'date'=>$date,'evidence'=>$evidence,'inspection'=>$inspection,'enteredBy'=>$by,'enteredAt'=>gmdate('c'),
            'outsideDealId'=>$old['dealId'],'outsideSupplier'=>$old['supplier']];
        $attachment=$file?:($actuals[$existing]['evidenceFile']??[]);
        if($attachment)$actual['evidenceFile']=$attachment;
        $lot['millActuals'][$existing??count($actuals)]=$actual;
        if($module==='Exports'&&($body['confirmComplete']??'')==='yes'){
            if($row['expected']<1||count($lot['millActuals'])!==$row['expected'])throw new DomainException('Save every planned container before confirming loading.');
            $lot['millLoadingCompleted']=true;
            $lot['outsideLoading']['completedAt']=gmdate('c');
        }
    }elseif($action==='complete'){
        if(!$old||($old['route']??'')!==strtoupper($module)||(int)$old['assigneeId']!==(int)$user['id']||$row['complete']||
            $row['expected']<1||count($row['actuals'])!==$row['expected'])throw new DomainException('The assigned user must save all planned containers before completing this loading.');
        $lot['millLoadingCompleted']=true;
        $lot['outsideLoading']['completedAt']=gmdate('c');
    }else throw new DomainException('Choose a supported loading action.');
    $lot['outsideLoading']['version']=(int)($old['version']??0)+1;
    $root['audits'][]=['id'=>'AUD-OUTSIDE-'.bin2hex(random_bytes(12)),'contractRef'=>$row['contract'],'area'=>'OUTSIDE LOADING',
        'at'=>gmdate('c'),'by'=>$by,'type'=>'OUTSIDE LOADING','action'=>$action,'detail'=>$row['lot'].' · '.$row['site'],
        'before'=>$action==='container'&&$existing!==null?$actuals[$existing]:$old,
        'after'=>$action==='container'?$actual+['loadingComplete'=>!empty($lot['millLoadingCompleted'])]:$lot['outsideLoading']];
    return $root;
}
