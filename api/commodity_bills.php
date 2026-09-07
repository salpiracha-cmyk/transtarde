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
    if (in_array('Create', $p, true) || in_array('Edit', $p, true)) return true;
    foreach ($p as $a) if (is_array($a) && (in_array('Create',$a,true) || in_array('Edit',$a,true))) return true;
    return false;
}
function cb_master(): array {
    $raw = is_file(TT_COMMODITY_MASTER_FILE) ? file_get_contents(TT_COMMODITY_MASTER_FILE) : false;
    $m = $raw ? json_decode($raw, true) : null;
    if (!is_array($m)) throw new RuntimeException('Accounts master is unavailable.');
    return $m;
}
function cb_default_store(): array {
    return ['revision'=>0,'journals'=>[],'events'=>[],'reminders'=>[],'masters'=>[],'commodityBills'=>[]];
}
function cb_read(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_COMMODITY_ACCOUNTS_FILE)) return cb_default_store();
    $h=fopen(TT_COMMODITY_ACCOUNTS_FILE,'r');
    if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(cb_default_store(),$s):cb_default_store();
}
function cb_account_names(): array {
    $out=[];foreach((array)(cb_master()['chart']??[]) as $a)if(is_array($a)&&isset($a['code']))$out[(string)$a['code']]=(string)($a['name']??$a['code']);return $out;
}
function cb_line(string $account,float $debit,float $credit,array $names): array {
    if(!isset($names[$account]))cb_respond(['ok'=>false,'error'=>'Approved account '.$account.' is missing from Chart of Accounts.'],422);
    return ['account'=>$account,'accountName'=>$names[$account],'debit'=>round($debit,2),'credit'=>round($credit,2)];
}
function cb_next_id(array $items,string $prefix): string {
    $n=count($items)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);$n++;}while(isset($items[$id]));return $id;
}
function cb_receipts(array $store,?string $entity=null): array {
    $rows=[];
    foreach((array)$store['events'] as $event){
        if(!is_array($event)||($event['eventType']??'')!=='COMMODITY_RECEIPT_ACCEPTED')continue;
        if($entity!==null&&($event['entity']??'')!==$entity)continue;
        if(!empty($event['billId']))continue;
        $j=$store['journals'][$event['journalId']??'']??null;if(!is_array($j))continue;
        $meta=is_array($j['meta']??null)?$j['meta']:[];
        $rows[]=[
            'eventId'=>(string)($event['id']??''),'sourceKey'=>(string)($event['sourceKey']??''),'entity'=>(string)($event['entity']??''),
            'journalId'=>(string)($event['journalId']??''),'date'=>(string)($j['date']??''),'reference'=>(string)($j['reference']??''),
            'provisionalAmount'=>(float)($j['totalDebit']??0),'soda'=>(string)($meta['soda']??''),'pohanch'=>(string)($meta['pohanch']??$j['reference']??''),
            'truck'=>(string)($meta['truck']??''),'broker'=>(string)($meta['broker']??''),'party'=>(string)($meta['party']??''),'variety'=>(string)($meta['variety']??''),
            'payableWeightKg'=>(float)($meta['payableWeightKg']??0),'grossRatePerKg'=>(float)($meta['grossRatePerKg']??0),
            'katPaisaPerKg'=>(float)($meta['katPaisaPerKg']??0),'provisionalNetRatePerKg'=>(float)($meta['provisionalNetRatePerKg']??0),
        ];
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:strcmp((string)$a['pohanch'],(string)$b['pohanch']));
    return $rows;
}
function cb_date(string $v): string {if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))cb_respond(['ok'=>false,'error'=>'Valid bill date required.'],422);return $v;}
function cb_money(mixed $v,string $label,bool $allowZero=false): float {$n=round((float)$v,2);if($n<0||(!$allowZero&&$n<=0))cb_respond(['ok'=>false,'error'=>$label.' is invalid.'],422);return $n;}

try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts'))cb_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);

    if($_SERVER['REQUEST_METHOD']==='GET'){
        $entity=strtoupper(trim((string)($_GET['entity']??'')));if($entity!==''&&!in_array($entity,['TTI','BRM','TG'],true))cb_respond(['ok'=>false,'error'=>'Invalid entity.'],422);
        $s=cb_read();cb_respond(['ok'=>true,'receipts'=>cb_receipts($s,$entity?:null),'bills'=>array_values((array)$s['commodityBills']),'serverNow'=>gmdate('c')]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')cb_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!cb_can_write($user))cb_respond(['ok'=>false,'error'=>'Accounts Create or Edit permission is required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))cb_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    if(($body['action']??'')!=='verify_bill')cb_respond(['ok'=>false,'error'=>'Unknown commodity bill action.'],422);

    $entity=strtoupper(trim((string)($body['entity']??'')));if(!in_array($entity,['TTI','BRM'],true))cb_respond(['ok'=>false,'error'=>'Commodity purchase bill must be posted to an authorized Pakistan entity.'],422);
    $date=cb_date((string)($body['billDate']??''));
    $sourceKeys=array_values(array_unique(array_filter(array_map('strval',(array)($body['sourceKeys']??[])))));
    if(!$sourceKeys||count($sourceKeys)>100)cb_respond(['ok'=>false,'error'=>'Select at least one unbilled Pohanch receipt.'],422);
    $finalValue=cb_money($body['finalCommodityValue']??0,'Final commodity value');
    $brokerageGross=cb_money($body['brokerageGross']??0,'Brokerage',true);
    $brokerageWithholding=cb_money($body['brokerageWithholding']??0,'Brokerage withholding',true);
    if($brokerageWithholding>$brokerageGross)cb_respond(['ok'=>false,'error'=>'Brokerage withholding cannot exceed gross brokerage.'],422);
    $billNo=trim((string)($body['billNo']??''));$broker=trim((string)($body['broker']??''));$remarks=trim((string)($body['remarks']??''));
    $adjustments=is_array($body['adjustments']??null)?$body['adjustments']:[];
    $names=cb_account_names();

    tt_ensure_data_dir();$h=fopen(TT_COMMODITY_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=cb_default_store();$store=array_replace_recursive(cb_default_store(),$store);
        $events=[];$provisional=0.0;$sodas=[];$commodities=[];
        foreach($sourceKeys as $key){
            $eventId=$entity.'|COMMODITY_RECEIPT_ACCEPTED|'.$key;$ev=$store['events'][$eventId]??null;
            if(!is_array($ev))cb_respond(['ok'=>false,'error'=>'Receipt event not found for '.$key.'.'],422);
            if(!empty($ev['billId']))cb_respond(['ok'=>false,'error'=>'Receipt '.$key.' is already included in bill '.$ev['billId'].'.'],409);
            $j=$store['journals'][$ev['journalId']??'']??null;if(!is_array($j))cb_respond(['ok'=>false,'error'=>'Receipt journal is missing for '.$key.'.'],422);
            $meta=is_array($j['meta']??null)?$j['meta']:[];$provisional+=round((float)($j['totalDebit']??0),2);$sodas[]=(string)($meta['soda']??'');$commodities[]=(string)($meta['variety']??'');$events[$eventId]=&$store['events'][$eventId];
        }
        $provisional=round($provisional,2);$delta=round($finalValue-$provisional,2);
        $lines=[cb_line('2210',$provisional,0,$names)];
        if($delta>0)$lines[]=cb_line('1310',$delta,0,$names);elseif($delta<0)$lines[]=cb_line('1310',0,abs($delta),$names);
        $lines[]=cb_line('2110',0,$finalValue,$names);
        if($brokerageGross>0){
            $lines[]=cb_line('1310',$brokerageGross,0,$names);
            $netBroker=round($brokerageGross-$brokerageWithholding,2);if($netBroker>0)$lines[]=cb_line('2120',0,$netBroker,$names);
            if($brokerageWithholding>0)$lines[]=cb_line('2300',0,$brokerageWithholding,$names);
        }
        $dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);if(abs($dr-$cr)>.005)throw new RuntimeException('Commodity bill journal did not balance.');
        $billId=cb_next_id((array)$store['commodityBills'],'CB');$journalId=cb_next_id((array)$store['journals'],'AUTO');
        $meta=['billId'=>$billId,'sourceKeys'=>$sourceKeys,'sodas'=>array_values(array_unique(array_filter($sodas))),'broker'=>$broker,'adjustments'=>$adjustments,'provisionalValue'=>$provisional,'finalCommodityValue'=>$finalValue,'brokerageGross'=>$brokerageGross,'brokerageWithholding'=>$brokerageWithholding];
        $store['journals'][$journalId]=['id'=>$journalId,'entity'=>$entity,'date'=>$date,'sourceType'=>'COMMODITY_BILL_VERIFIED','reference'=>$billNo?:$billId,'narration'=>'Commodity purchase bill '.($billNo?:$billId).($broker?' — '.$broker:''),'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Staff'),'userId'=>(int)($user['id']??0),'reversalOf'=>null];
        $store['commodityBills'][$billId]=['id'=>$billId,'entity'=>$entity,'billDate'=>$date,'billNo'=>$billNo,'broker'=>$broker,'sourceKeys'=>$sourceKeys,'sodas'=>$meta['sodas'],'provisionalValue'=>$provisional,'finalCommodityValue'=>$finalValue,'brokerageGross'=>$brokerageGross,'brokerageWithholding'=>$brokerageWithholding,'journalId'=>$journalId,'adjustments'=>$adjustments,'remarks'=>$remarks,'status'=>'Verified / Posted','createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Staff')];
        foreach(array_keys($events) as $eventId)$store['events'][$eventId]['billId']=$billId;
        $store['revision']=(int)($store['revision']??0)+1;
        rewind($h);if(!ftruncate($h,0))throw new RuntimeException('Accounts storage could not be updated.');$enc=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(fwrite($h,$enc)===false)throw new RuntimeException('Accounts storage could not be written.');fflush($h);
    }finally{flock($h,LOCK_UN);fclose($h);}
    cb_respond(['ok'=>true,'bill'=>$store['commodityBills'][$billId],'journal'=>$store['journals'][$journalId],'revision'=>(int)$store['revision']]);
}catch(Throwable $e){cb_respond(['ok'=>false,'error'=>'The commodity bill could not be completed.'],500);}
