<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_COMMODITY_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';
const TT_COMMODITY_MASTER_FILE = __DIR__ . '/../accounts/accounting_master_v1.json';

function cb_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function cb_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $p = $user['permissions']['Accounts'] ?? null;
    if ($p === 'all') return true;
    if (!is_array($p)) return false;
    if (in_array('Create',$p,true) || in_array('Edit',$p,true)) return true;
    foreach($p as $a) if(is_array($a) && (in_array('Create',$a,true)||in_array('Edit',$a,true))) return true;
    return false;
}
function cb_master(): array {
    $raw=is_file(TT_COMMODITY_MASTER_FILE)?file_get_contents(TT_COMMODITY_MASTER_FILE):false;
    $m=$raw?json_decode($raw,true):null;
    if(!is_array($m)) throw new RuntimeException('Accounts master is unavailable.');
    return $m;
}
function cb_default_store(): array {
    return [
        'revision'=>0,'journals'=>[],'events'=>[],'reminders'=>[],'masters'=>[],
        'commodityBills'=>[],'supplierSettlements'=>[],'payableHolds'=>[]
    ];
}
function cb_read(): array {
    tt_ensure_data_dir();
    if(!is_file(TT_COMMODITY_ACCOUNTS_FILE)) return cb_default_store();
    $h=fopen(TT_COMMODITY_ACCOUNTS_FILE,'r');
    if($h===false||!flock($h,LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(cb_default_store(),$s):cb_default_store();
}
function cb_account_names(): array {
    $out=[];
    foreach((array)(cb_master()['chart']??[]) as $a) if(is_array($a)&&isset($a['code'])) $out[(string)$a['code']]=(string)($a['name']??$a['code']);
    return $out;
}
function cb_line(string $account,float $debit,float $credit,array $names): array {
    if(!isset($names[$account])) cb_respond(['ok'=>false,'error'=>'Approved account '.$account.' is missing from Chart of Accounts.'],422);
    return ['account'=>$account,'accountName'=>$names[$account],'debit'=>round($debit,2),'credit'=>round($credit,2)];
}
function cb_next_id(array $items,string $prefix): string {
    $n=count($items)+1;
    do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);$n++;}while(isset($items[$id]));
    return $id;
}
function cb_commodity(array $event,array $meta): string {
    $saved=strtoupper(trim((string)($event['commodity']??$meta['commodity']??'')));
    if(in_array($saved,['RICE','CORN','SESAME'],true)) return $saved;
    $v=strtoupper((string)($meta['variety']??''));
    if(str_contains($v,'CORN')||str_contains($v,'MAIZE')) return 'CORN';
    if(str_contains($v,'SESAME')) return 'SESAME';
    return 'RICE';
}
function cb_receipts(array $store,?string $entity=null): array {
    $rows=[];
    foreach((array)($store['events']??[]) as $event){
        if(!is_array($event)||($event['eventType']??'')!=='COMMODITY_RECEIPT_ACCEPTED') continue;
        if($entity!==null&&($event['entity']??'')!==$entity) continue;
        if(!empty($event['billId'])) continue;
        $j=$store['journals'][$event['journalId']??'']??null;
        if(!is_array($j)) continue;
        $meta=is_array($j['meta']??null)?$j['meta']:[];
        $rows[]=[
            'eventId'=>(string)($event['id']??''),'sourceKey'=>(string)($event['sourceKey']??''),'entity'=>(string)($event['entity']??''),
            'journalId'=>(string)($event['journalId']??''),'date'=>(string)($j['date']??''),'reference'=>(string)($j['reference']??''),
            'provisionalAmount'=>(float)($j['totalDebit']??0),'commodity'=>cb_commodity($event,$meta),'soda'=>(string)($meta['soda']??''),'pohanch'=>(string)($meta['pohanch']??$j['reference']??''),
            'truck'=>(string)($meta['truck']??''),'broker'=>(string)($meta['broker']??''),'party'=>(string)($meta['party']??''),'variety'=>(string)($meta['variety']??''),
            'payableWeightKg'=>(float)($meta['payableWeightKg']??0),'grossRatePerKg'=>(float)($meta['grossRatePerKg']??0),
            'katPaisaPerKg'=>(float)($meta['katPaisaPerKg']??0),'provisionalNetRatePerKg'=>(float)($meta['provisionalNetRatePerKg']??0)
        ];
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:strcmp((string)$a['pohanch'],(string)$b['pohanch']));
    return $rows;
}
function cb_date(string $v): string {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)) cb_respond(['ok'=>false,'error'=>'Valid bill date required.'],422);
    return $v;
}
function cb_money(mixed $v,string $label,bool $allowZero=false): float {
    $n=round((float)$v,2);
    if($n<0||(!$allowZero&&$n<=0)) cb_respond(['ok'=>false,'error'=>$label.' is invalid.'],422);
    return $n;
}
function cb_credit_days(mixed $v): int {
    if($v===''||$v===null||!is_numeric($v)) cb_respond(['ok'=>false,'error'=>'Soda credit days are required for payment planning.'],422);
    $n=(int)$v;
    if($n<0||$n>120) cb_respond(['ok'=>false,'error'=>'Soda credit days must be between 0 and 120.'],422);
    return $n;
}
function cb_add_days(string $date,int $days): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Karachi'));
    if(!$d) cb_respond(['ok'=>false,'error'=>'Invalid unloading date found while calculating payment due date.'],422);
    return $d->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d');
}
function cb_allocate_payable(array $receiptRows,float $total,int $creditDays): array {
    $weights=[];$base=0.0;
    foreach($receiptRows as $r){$v=max(0,(float)($r['provisionalAmount']??0));$weights[]=$v;$base+=$v;}
    if($base<=0){$weights=array_fill(0,count($receiptRows),1.0);$base=(float)count($receiptRows);}
    $alloc=[];$used=0.0;$last=count($receiptRows)-1;
    foreach($receiptRows as $i=>$r){
        $share=$i===$last?round($total-$used,2):round($total*($weights[$i]/$base),2);
        $used=round($used+$share,2);
        $receiptDate=(string)($r['date']??'');
        $alloc[]=[
            'sourceKey'=>(string)($r['sourceKey']??''),'eventId'=>(string)($r['eventId']??''),
            'commodity'=>(string)($r['commodity']??''),'pohanch'=>(string)($r['pohanch']??''),'truck'=>(string)($r['truck']??''),'receiptDate'=>$receiptDate,
            'dueDate'=>cb_add_days($receiptDate,$creditDays),'creditDays'=>$creditDays,
            'supplierPayableShare'=>$share,'provisionalAmount'=>round((float)($r['provisionalAmount']??0),2),
            'status'=>'Outstanding'
        ];
    }
    return $alloc;
}

try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts')) cb_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);

    if($_SERVER['REQUEST_METHOD']==='GET'){
        $entity=strtoupper(trim((string)($_GET['entity']??'')));
        if($entity!==''&&!in_array($entity,['TTI','BRM','TG'],true)) cb_respond(['ok'=>false,'error'=>'Invalid entity.'],422);
        $s=cb_read();
        cb_respond(['ok'=>true,'receipts'=>cb_receipts($s,$entity?:null),'bills'=>array_values((array)$s['commodityBills']),'serverNow'=>gmdate('c')]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST') cb_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!cb_can_write($user)) cb_respond(['ok'=>false,'error'=>'Accounts Create or Edit permission is required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??''))) cb_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    if(($body['action']??'')!=='verify_bill') cb_respond(['ok'=>false,'error'=>'Unknown commodity bill action.'],422);

    $entity=strtoupper(trim((string)($body['entity']??'')));
    if(!in_array($entity,['TTI','BRM'],true)) cb_respond(['ok'=>false,'error'=>'Commodity purchase bill must be posted to an authorized Pakistan entity.'],422);
    $date=cb_date((string)($body['billDate']??''));
    $creditDays=cb_credit_days($body['creditDays']??null);
    $sourceKeys=array_values(array_unique(array_filter(array_map('strval',(array)($body['sourceKeys']??[])))));
    if(!$sourceKeys||count($sourceKeys)>100) cb_respond(['ok'=>false,'error'=>'Select at least one unbilled Pohanch receipt.'],422);
    $finalValue=cb_money($body['finalCommodityValue']??0,'Final commodity value');
    $brokerageGross=cb_money($body['brokerageGross']??0,'Brokerage',true);
    $brokerageWithholding=cb_money($body['brokerageWithholding']??0,'Brokerage withholding',true);
    if($brokerageWithholding>$brokerageGross) cb_respond(['ok'=>false,'error'=>'Brokerage withholding cannot exceed gross brokerage.'],422);
    $billNo=trim((string)($body['billNo']??''));
    $brokerInput=trim((string)($body['broker']??''));
    $remarks=trim((string)($body['remarks']??''));
    $adjustments=is_array($body['adjustments']??null)?$body['adjustments']:[];
    $names=cb_account_names();

    tt_ensure_data_dir();
    $h=fopen(TT_COMMODITY_ACCOUNTS_FILE,'c+');
    if($h===false||!flock($h,LOCK_EX)) throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;
        if(!is_array($store))$store=cb_default_store();
        $store=array_replace_recursive(cb_default_store(),$store);

        $events=[];$receiptRows=[];$provisional=0.0;$sodas=[];$brokers=[];$commodities=[];
        foreach($sourceKeys as $key){
            $eventId=$entity.'|COMMODITY_RECEIPT_ACCEPTED|'.$key;
            $ev=$store['events'][$eventId]??null;
            if(!is_array($ev)) cb_respond(['ok'=>false,'error'=>'Receipt event not found for '.$key.'.'],422);
            if(!empty($ev['billId'])) cb_respond(['ok'=>false,'error'=>'Receipt '.$key.' is already included in bill '.$ev['billId'].'.'],409);
            $j=$store['journals'][$ev['journalId']??'']??null;
            if(!is_array($j)) cb_respond(['ok'=>false,'error'=>'Receipt journal is missing for '.$key.'.'],422);
            $meta=is_array($j['meta']??null)?$j['meta']:[];
            $commodity=cb_commodity($ev,$meta);
            $row=[
                'eventId'=>$eventId,'sourceKey'=>$key,'date'=>(string)($j['date']??''),'provisionalAmount'=>round((float)($j['totalDebit']??0),2),
                'commodity'=>$commodity,'soda'=>(string)($meta['soda']??''),'pohanch'=>(string)($meta['pohanch']??$j['reference']??''),
                'truck'=>(string)($meta['truck']??''),'broker'=>(string)($meta['broker']??''),'variety'=>(string)($meta['variety']??'')
            ];
            $provisional+=$row['provisionalAmount'];$sodas[]=$row['soda'];$brokers[]=$row['broker'];$commodities[]=$commodity;
            $receiptRows[]=$row;$events[$eventId]=&$store['events'][$eventId];
        }
        $uniqueSodas=array_values(array_unique(array_filter(array_map('trim',$sodas))));
        if(count($uniqueSodas)!==1) cb_respond(['ok'=>false,'error'=>'One commodity bill must contain Pohanch receipts from one Soda only.'],422);
        $uniqueCommodities=array_values(array_unique(array_filter(array_map('trim',$commodities))));
        if(count($uniqueCommodities)!==1) cb_respond(['ok'=>false,'error'=>'One purchase bill cannot mix Rice and Corn / Maize receipts.'],422);
        $commodity=$uniqueCommodities[0];
        $uniqueBrokers=array_values(array_unique(array_filter(array_map('trim',$brokers))));
        if(count($uniqueBrokers)>1) cb_respond(['ok'=>false,'error'=>'One commodity bill cannot mix different brokers / payees.'],422);
        $broker=$brokerInput!==''?$brokerInput:($uniqueBrokers[0]??'');
        if($brokerInput!==''&&$uniqueBrokers&&strcasecmp($brokerInput,$uniqueBrokers[0])!==0) cb_respond(['ok'=>false,'error'=>'Selected broker does not match the Pohanch receipts.'],422);

        $provisional=round($provisional,2);
        $delta=round($finalValue-$provisional,2);
        $lines=[cb_line('2210',$provisional,0,$names)];
        if($delta>0)$lines[]=cb_line('1310',$delta,0,$names);elseif($delta<0)$lines[]=cb_line('1310',0,abs($delta),$names);
        $lines[]=cb_line('2110',0,$finalValue,$names);
        $netBrokerage=round($brokerageGross-$brokerageWithholding,2);
        if($brokerageGross>0){
            $lines[]=cb_line('1310',$brokerageGross,0,$names);
            if($netBrokerage>0)$lines[]=cb_line('2120',0,$netBrokerage,$names);
            if($brokerageWithholding>0)$lines[]=cb_line('2300',0,$brokerageWithholding,$names);
        }
        $dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);
        if(abs($dr-$cr)>.005) throw new RuntimeException('Commodity bill journal did not balance.');

        $supplierPayableTotal=round($finalValue+$netBrokerage,2);
        $receiptAllocations=cb_allocate_payable($receiptRows,$supplierPayableTotal,$creditDays);
        $billId=cb_next_id((array)$store['commodityBills'],'CB');
        $journalId=cb_next_id((array)$store['journals'],'AUTO');
        $dueDates=array_column($receiptAllocations,'dueDate');sort($dueDates);
        $label=$commodity==='CORN'?'Corn / Maize':($commodity==='RICE'?'Rice':ucfirst(strtolower($commodity)));
        $meta=[
            'billId'=>$billId,'commodity'=>$commodity,'sourceKeys'=>$sourceKeys,'sodas'=>$uniqueSodas,'broker'=>$broker,'adjustments'=>$adjustments,
            'provisionalValue'=>$provisional,'finalCommodityValue'=>$finalValue,'brokerageGross'=>$brokerageGross,
            'brokerageWithholding'=>$brokerageWithholding,'supplierPayableTotal'=>$supplierPayableTotal,
            'creditDays'=>$creditDays,'dueBasis'=>'Unloading Date','receiptAllocations'=>$receiptAllocations
        ];
        $store['journals'][$journalId]=[
            'id'=>$journalId,'entity'=>$entity,'date'=>$date,'sourceType'=>'COMMODITY_BILL_VERIFIED','reference'=>$billNo?:$billId,
            'narration'=>$label.' purchase bill '.($billNo?:$billId).($broker?' — '.$broker:''),'lines'=>$lines,
            'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),
            'createdBy'=>(string)($user['full_name']??$user['username']??'Staff'),'userId'=>(int)($user['id']??0),'reversalOf'=>null
        ];
        $store['commodityBills'][$billId]=[
            'id'=>$billId,'entity'=>$entity,'commodity'=>$commodity,'billDate'=>$date,'billNo'=>$billNo,'broker'=>$broker,'sourceKeys'=>$sourceKeys,
            'sodas'=>$uniqueSodas,'provisionalValue'=>$provisional,'finalCommodityValue'=>$finalValue,'brokerageGross'=>$brokerageGross,
            'brokerageWithholding'=>$brokerageWithholding,'supplierPayableTotal'=>$supplierPayableTotal,'journalId'=>$journalId,
            'adjustments'=>$adjustments,'remarks'=>$remarks,'creditDays'=>$creditDays,'dueBasis'=>'Unloading Date',
            'dueDateFrom'=>$dueDates[0]??$date,'dueDateTo'=>$dueDates[count($dueDates)-1]??$date,
            'receiptAllocations'=>$receiptAllocations,'paymentStatus'=>'Outstanding','status'=>'Verified / Posted',
            'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Staff')
        ];
        foreach($receiptAllocations as $a){
            $eventId=(string)$a['eventId'];
            $store['events'][$eventId]['billId']=$billId;
            $store['events'][$eventId]['commodity']=$commodity;
            $store['events'][$eventId]['creditDays']=$creditDays;
            $store['events'][$eventId]['dueDate']=$a['dueDate'];
            $store['events'][$eventId]['supplierPayableShare']=$a['supplierPayableShare'];
        }
        $store['revision']=(int)($store['revision']??0)+1;
        rewind($h);if(!ftruncate($h,0))throw new RuntimeException('Accounts storage could not be updated.');
        $enc=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(fwrite($h,$enc)===false)throw new RuntimeException('Accounts storage could not be written.');
        fflush($h);
    }finally{flock($h,LOCK_UN);fclose($h);}
    cb_respond(['ok'=>true,'bill'=>$store['commodityBills'][$billId],'journal'=>$store['journals'][$journalId],'revision'=>(int)$store['revision']]);
}catch(Throwable $e){
    cb_respond(['ok'=>false,'error'=>'The commodity bill could not be completed.'],500);
}
