<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_PAYABLES_FILE = TT_DATA_DIR . '/accounts.json';

function pp_respond(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function pp_default_store(): array {
    return ['revision'=>0,'journals'=>[],'events'=>[],'commodityBills'=>[],'supplierBills'=>[],'supplierSettlements'=>[],'payableHolds'=>[]];
}
function pp_read(): array {
    tt_ensure_data_dir();
    if(!is_file(TT_PAYABLES_FILE))return pp_default_store();
    $h=fopen(TT_PAYABLES_FILE,'r');
    if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(pp_default_store(),$s):pp_default_store();
}
function pp_can_accounts_write(array $user): bool {
    if(($user['role']??'')==='Super Admin')return true;
    $p=$user['permissions']['Accounts']??null;
    if($p==='all')return true;
    if(!is_array($p))return false;
    if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true))return true;
    foreach($p as $a)if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)||in_array('Approve',$a,true)))return true;
    return false;
}
function pp_date(string $v,string $label='Date'): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);$e=DateTimeImmutable::getLastErrors();
    if(!$d||($e!==false&&(($e['warning_count']??0)>0||($e['error_count']??0)>0))||$d->format('Y-m-d')!==$v)pp_respond(['ok'=>false,'error'=>$label.' is invalid.'],422);
    return $v;
}
function pp_entity(string $v): string {
    $v=strtoupper(trim($v));
    if(!in_array($v,['TTI','BRM'],true))pp_respond(['ok'=>false,'error'=>'Payables planning is available for TTI or BRM.'],422);
    return $v;
}
function pp_commodity(string $v,bool $optional=true): string {
    $v=strtoupper(trim($v));
    if($v===''&&$optional)return '';
    if($v==='MAIZE')$v='CORN';
    if(!in_array($v,['RICE','CORN','SESAME'],true))pp_respond(['ok'=>false,'error'=>'Payment commodity must be Rice, Corn / Maize or Sesame.'],422);
    return $v;
}
function pp_commodity_from(array $event,array $meta): string {
    $saved=strtoupper(trim((string)($event['commodity']??$meta['commodity']??'')));
    if(in_array($saved,['RICE','CORN','SESAME'],true))return $saved;
    $v=strtoupper((string)($meta['variety']??''));
    if(str_contains($v,'SESAME'))return 'SESAME';
    return (str_contains($v,'CORN')||str_contains($v,'MAIZE'))?'CORN':'RICE';
}
function pp_money(mixed $v): float {return max(0,round((float)$v,2));}
function pp_days_between(string $from,string $to): int {
    $a=new DateTimeImmutable($from.' 00:00:00',new DateTimeZone('Asia/Karachi'));
    $b=new DateTimeImmutable($to.' 00:00:00',new DateTimeZone('Asia/Karachi'));
    return (int)$a->diff($b)->format('%r%a');
}
function pp_settlement_allocated(array $store,string $entity,string $sourceKey,string $billId): float {
    $paid=0.0;
    foreach((array)($store['supplierSettlements']??[]) as $s){
        if(!is_array($s)||($s['entity']??'')!==$entity)continue;
        if(!in_array((string)($s['status']??''),['Posted','Approved / Posted','Approved'],true))continue;
        foreach((array)($s['allocations']??[]) as $a){
            if(!is_array($a))continue;
            $matchesSource=$sourceKey!==''&&(string)($a['sourceKey']??'')===$sourceKey;
            $matchesBill=$sourceKey===''&&$billId!==''&&(string)($a['billId']??'')===$billId;
            if($matchesSource||$matchesBill)$paid+=pp_money($a['amount']??0);
        }
    }
    return round($paid,2);
}
function pp_hold(array $store,string $entity,string $sourceKey): ?array {
    $key=$entity.'|'.$sourceKey;
    $h=$store['payableHolds'][$key]??null;
    return is_array($h)&&!empty($h['active'])?$h:null;
}
function pp_rows(array $store,string $entity,string $commodity=''): array {
    $rows=[];
    foreach((array)($store['commodityBills']??[]) as $bill){
        if(!is_array($bill)||($bill['entity']??'')!==$entity)continue;
        if(!in_array((string)($bill['status']??''),['Verified / Posted','Posted','Outstanding'],true))continue;
        $billCommodity=strtoupper(trim((string)($bill['commodity']??'RICE')));
        if($billCommodity==='MAIZE')$billCommodity='CORN';
        if($commodity!==''&&$billCommodity!==$commodity)continue;
        $billId=(string)($bill['id']??'');$billNo=(string)($bill['billNo']??'');$broker=(string)($bill['broker']??'');
        $soda=(string)(($bill['sodas'][0]??'')?:'');
        $allocs=is_array($bill['receiptAllocations']??null)?$bill['receiptAllocations']:[];
        if(!$allocs){
            $gross=pp_money($bill['supplierPayableTotal']??((float)($bill['finalCommodityValue']??0)+max(0,(float)($bill['brokerageGross']??0)-(float)($bill['brokerageWithholding']??0))));
            $allocs=[['sourceKey'=>'','pohanch'=>'','truck'=>'','receiptDate'=>(string)($bill['billDate']??''),'dueDate'=>(string)($bill['dueDateTo']??$bill['billDate']??''),'creditDays'=>(int)($bill['creditDays']??0),'supplierPayableShare'=>$gross]];
        }
        foreach($allocs as $a){
            if(!is_array($a))continue;
            $sourceKey=(string)($a['sourceKey']??'');
            $gross=pp_money($a['supplierPayableShare']??0);
            $paid=pp_settlement_allocated($store,$entity,$sourceKey,$billId);
            $out=max(0,round($gross-$paid,2));
            if($out<=0.005)continue;
            $due=(string)($a['dueDate']??'');if($due==='')$due=(string)($bill['dueDateTo']??$bill['billDate']??'');
            $hold=pp_hold($store,$entity,$sourceKey!==''?$sourceKey:'BILL|'.$billId);
            $rows[]=[
                'entity'=>$entity,'commodity'=>$billCommodity,'billId'=>$billId,'billNo'=>$billNo,'broker'=>$broker,'soda'=>$soda,
                'sourceKey'=>$sourceKey,'pohanch'=>(string)($a['pohanch']??''),'truck'=>(string)($a['truck']??''),
                'receiptDate'=>(string)($a['receiptDate']??$bill['billDate']??''),'creditDays'=>(int)($a['creditDays']??$bill['creditDays']??0),
                'dueDate'=>$due,'grossPayable'=>$gross,'settled'=>$paid,'outstanding'=>$out,
                'held'=>$hold!==null,'holdReason'=>(string)($hold['reason']??''),'holdAt'=>(string)($hold['createdAt']??'')
            ];
        }
    }
    if($commodity==='')foreach((array)($store['supplierBills']??[]) as $bill){
        if(!is_array($bill)||($bill['entity']??'')!==$entity)continue;
        $billId=(string)($bill['id']??'');$gross=pp_money($bill['supplierPayableTotal']??0);$paid=pp_settlement_allocated($store,$entity,'',$billId);$out=max(0,round($gross-$paid,2));if($out<=.005)continue;
        $allocation=(array)(($bill['receiptAllocations'][0]??[]));$sourceKey=(string)($allocation['sourceKey']??'');$hold=pp_hold($store,$entity,$sourceKey!==''?$sourceKey:'BILL|'.$billId);
        $rows[]=['entity'=>$entity,'commodity'=>(string)($bill['category']??'SERVICE'),'billId'=>$billId,'billNo'=>(string)($bill['billNo']??''),'broker'=>(string)($bill['vendor']??''),'soda'=>'','sourceKey'=>$sourceKey,'pohanch'=>(string)($allocation['reference']??''),'truck'=>'','receiptDate'=>(string)($bill['billDate']??''),'creditDays'=>0,'dueDate'=>(string)($bill['dueDate']??$bill['billDate']??''),'grossPayable'=>$gross,'settled'=>$paid,'outstanding'=>$out,'held'=>$hold!==null,'holdReason'=>(string)($hold['reason']??''),'holdAt'=>(string)($hold['createdAt']??'')];
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['dueDate'],(string)$b['dueDate'])?:strcmp((string)$a['broker'],(string)$b['broker'])?:strcmp((string)$a['soda'],(string)$b['soda'])?:strcmp((string)$a['truck'],(string)$b['truck']));
    return $rows;
}
function pp_unposted(array $store,string $entity,string $asOf,string $commodity=''): array {
    $rows=[];
    foreach((array)($store['events']??[]) as $ev){
        if(!is_array($ev)||($ev['eventType']??'')!=='COMMODITY_RECEIPT_ACCEPTED'||($ev['entity']??'')!==$entity||!empty($ev['billId']))continue;
        $j=$store['journals'][$ev['journalId']??'']??null;if(!is_array($j))continue;
        $meta=is_array($j['meta']??null)?$j['meta']:[];$date=(string)($j['date']??'');
        $rowCommodity=pp_commodity_from($ev,$meta);if($commodity!==''&&$rowCommodity!==$commodity)continue;
        $age=$date!==''?max(0,pp_days_between($date,$asOf)):0;
        $rows[]=['sourceKey'=>(string)($ev['sourceKey']??''),'commodity'=>$rowCommodity,'date'=>$date,'ageDays'=>$age,'broker'=>(string)($meta['broker']??''),'soda'=>(string)($meta['soda']??''),'pohanch'=>(string)($meta['pohanch']??$j['reference']??''),'truck'=>(string)($meta['truck']??''),'variety'=>(string)($meta['variety']??''),'provisionalAmount'=>pp_money($j['totalDebit']??0),'status'=>'Received / Bill Not Posted'];
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date']));
    return $rows;
}
function pp_ladder(array $rows,float $funds): array {
    $daily=[];
    foreach($rows as $r){$d=(string)$r['dueDate'];if($d==='')continue;if(!isset($daily[$d]))$daily[$d]=['dueDate'=>$d,'dueThatDay'=>0.0,'heldThatDay'=>0.0,'eligibleCount'=>0,'heldCount'=>0];if(!empty($r['held'])){$daily[$d]['heldThatDay']+=pp_money($r['outstanding']);$daily[$d]['heldCount']++;}else{$daily[$d]['dueThatDay']+=pp_money($r['outstanding']);$daily[$d]['eligibleCount']++;}}
    ksort($daily);$cum=0.0;$out=[];$clearThrough=null;$next=null;
    foreach($daily as $d=>$x){$previous=$cum;$cum=round($cum+$x['dueThatDay'],2);$row=$x;$row['previousUnpaid']=$previous;$row['cumulativeThroughDate']=$cum;$row['remainingAfterFunds']=max(0,round($cum-$funds,2));if($funds>0&&$cum<=$funds+0.005)$clearThrough=$d;elseif($funds>0&&$next===null&&$cum>$funds+0.005)$next=['dueDate'=>$d,'previousUnpaid'=>$previous,'dueThatDay'=>$x['dueThatDay'],'cumulativeThroughDate'=>$cum,'amountAvailableForThisDay'=>max(0,round($funds-$previous,2)),'shortfallToClearThroughDate'=>round($cum-$funds,2)];$out[]=$row;}
    return ['rows'=>$out,'totalEligibleOutstanding'=>$cum,'clearThroughDate'=>$clearThrough,'nextDate'=>$next,'funds'=>$funds,'fundsUnallocated'=>max(0,round($funds-($clearThrough!==null?min($funds,$cum):0),2))];
}
function pp_ageing(array $rows,string $asOf): array {
    $b=['Not Due'=>0.0,'Due Today'=>0.0,'1–7 Days Overdue'=>0.0,'8–15 Days Overdue'=>0.0,'16–30 Days Overdue'=>0.0,'31–45 Days Overdue'=>0.0,'46+ Days Overdue'=>0.0,'Held / Issue'=>0.0];
    foreach($rows as $r){$amt=pp_money($r['outstanding']);if(!empty($r['held'])){$b['Held / Issue']+=$amt;continue;}$n=pp_days_between((string)$r['dueDate'],$asOf);if($n<0)$b['Not Due']+=$amt;elseif($n===0)$b['Due Today']+=$amt;elseif($n<=7)$b['1–7 Days Overdue']+=$amt;elseif($n<=15)$b['8–15 Days Overdue']+=$amt;elseif($n<=30)$b['16–30 Days Overdue']+=$amt;elseif($n<=45)$b['31–45 Days Overdue']+=$amt;else$b['46+ Days Overdue']+=$amt;}
    foreach($b as $k=>$v)$b[$k]=round($v,2);return $b;
}
function pp_brokers(array $rows): array {
    $b=[];foreach($rows as $r){$k=trim((string)$r['broker'])?:'Unspecified';if(!isset($b[$k]))$b[$k]=['broker'=>$k,'eligibleOutstanding'=>0.0,'heldOutstanding'=>0.0,'trucks'=>0];if(!empty($r['held']))$b[$k]['heldOutstanding']+=pp_money($r['outstanding']);else$b[$k]['eligibleOutstanding']+=pp_money($r['outstanding']);$b[$k]['trucks']++;}
    usort($b,static fn($a,$c)=>strcmp((string)$a['broker'],(string)$c['broker']));return array_values($b);
}

try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts'))pp_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $entity=pp_entity((string)($_GET['entity']??'TTI'));if(!tt_user_can_access_entity($user,$entity,'View'))pp_respond(['ok'=>false,'error'=>'You do not have permission for this legal entity.'],403);
        $asOf=pp_date((string)($_GET['asOf']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d')),'As-of date');
        $commodity=pp_commodity((string)($_GET['commodity']??''));
        $funds=pp_money($_GET['funds']??0);$store=pp_read();$rows=pp_rows($store,$entity,$commodity);$ladder=pp_ladder($rows,$funds);
        pp_respond(['ok'=>true,'entity'=>$entity,'commodity'=>$commodity,'asOf'=>$asOf,'funds'=>$funds,'ladder'=>$ladder,'ageing'=>pp_ageing($rows,$asOf),'brokers'=>pp_brokers($rows),'payables'=>$rows,'unpostedReceipts'=>pp_unposted($store,$entity,$asOf,$commodity),'serverNow'=>gmdate('c')]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')pp_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!pp_can_accounts_write($user))pp_respond(['ok'=>false,'error'=>'Accounts approval / edit permission required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))pp_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');
    if($action!=='set_hold')pp_respond(['ok'=>false,'error'=>'Unknown Payables action.'],422);
    $entity=pp_entity((string)($body['entity']??''));if(!tt_user_can_access_entity($user,$entity,'Edit'))pp_respond(['ok'=>false,'error'=>'You do not have permission for this legal entity.'],403);$sourceKey=trim((string)($body['sourceKey']??''));if($sourceKey==='')pp_respond(['ok'=>false,'error'=>'Truck / payable reference is required.'],422);
    $active=(bool)($body['active']??false);$reason=trim((string)($body['reason']??''));if($active&&$reason==='')pp_respond(['ok'=>false,'error'=>'Reason is required when a payable is put on hold.'],422);
    tt_ensure_data_dir();$h=fopen(TT_PAYABLES_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=pp_default_store();$store=array_replace_recursive(pp_default_store(),$store);$key=$entity.'|'.$sourceKey;$store['payableHolds'][$key]=['entity'=>$entity,'sourceKey'=>$sourceKey,'active'=>$active,'reason'=>$reason,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts')];$store['revision']=(int)($store['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);}finally{flock($h,LOCK_UN);fclose($h);}pp_respond(['ok'=>true,'hold'=>$store['payableHolds'][$key],'revision'=>$store['revision']]);
}catch(Throwable $e){pp_respond(['ok'=>false,'error'=>'Payables planning could not be completed.'],500);}
