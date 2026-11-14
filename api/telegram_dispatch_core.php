<?php
declare(strict_types=1);
require_once __DIR__.'/mill_recovery_core.php';
require_once __DIR__.'/telegram_alerts_core.php';
require_once __DIR__.'/discord_alerts_core.php';
require_once __DIR__.'/social_alerts_reports.php';
require_once __DIR__.'/telegram_report_files.php';
require_once __DIR__.'/telegram_production_reports.php';
require_once dirname(__DIR__).'/document_filenames.php';

function tg_hash(mixed $value): string { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR)); }
function tg_rows(array $ops, string $key): array {
    $rows=json_decode($ops['values'][$key]??'[]',true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('Invalid alert source.');
    return $rows;
}
function tg_arrival_text(array $r): string {
    $parts=['Truck '.($r['vehicle']??$r['truck']??'Unspecified'),($r['entity']??'TTI').' | '.($r['millName']??$r['locationName']??$r['mill']??'TTI Rice Mills')];
    foreach(['party'=>'Supplier','broker'=>'Broker','variety'=>'Variety','bags'=>'Bags','weight'=>'Loading weight kg','netWeight'=>'Net weight kg','broken'=>'Broken %','chalky'=>'Chalky %','moisture'=>'Moisture %','damage'=>'Damage %','paddy'=>'Paddy','other'=>'Notes'] as $field=>$label) {
        if(isset($r[$field])&&$r[$field]!=='')$parts[]=$label.' '.$r[$field];
    }
    $parts[]='Status '.(!empty($r['rejected'])?'Rejected':'Accepted arrival');
    return implode("\n",$parts);
}
function tg_loading_sources(array $ops): array {
    $shipments=tg_rows($ops,'tt30ship');
    $instructions=array_column(tg_rows($ops,'tt40exinstructions'),null,'id');
    $outside=[];
    foreach(tg_rows($ops,'tt35exload') as $load) {
        $id=(string)($load['instructionId']??'');
        if($id===''||!isset($instructions[$id]))continue;
        $instruction=$instructions[$id];
        if(!isset($outside[$id]))$outside[$id]=['id'=>'EXMILL|'.$id,'contractRef'=>$instruction['contractRef']??'',
            'lotRef'=>$instruction['lotRef']??'','brand'=>$instruction['brand']??'',
            'totalContainers'=>$instruction['totalContainers']??null,'containers'=>[]];
        $outside[$id]['containers'][]=array_replace($load,['weight'=>$load['kg']??0,
            'loadingComplete'=>!empty($load['serverPosted'])&&($load['issue']??'')==='OK'&&($load['kg']??0)>0]);
    }
    foreach(tg_rows($ops,'tt35exportersale') as $sale) {
        $containers=[];
        foreach($sale['containers']??[] as $c)$containers[]=array_replace($c,[
            'container'=>$c['c']??'','truck'=>$c['t']??'','bags'=>$c['b']??0,'weight'=>$c['net']??0,
            'loadingComplete'=>($c['net']??0)>0&&($c['b']??0)>0&&!empty($c['c'])&&$c['c']!=='BREAK BULK']);
        $shipments[]=array_replace($sale,['id'=>'FELLOW|'.($sale['id']??$sale['ref']??''),
            'totalContainers'=>$sale['totalContainers']??$sale['plannedContainers']??null,'containers'=>$containers]);
    }
    return array_merge($shipments,array_values($outside));
}
function tg_notification_jobs(array $ops, array $state, DateTimeImmutable $now): iterable {
    $today=$now->format('Y-m-d');$clock=$now->format('H:i');
    $arrivals=array_values(array_filter(tg_rows($ops,'tt30queue'),static fn($r)=>($r['date']??'')===$today&&empty($r['deleted'])));
    usort($arrivals,static fn($a,$b)=>strcmp((string)($a['id']??''),(string)($b['id']??'')));
    if($clock>='10:00') {
        $summaryKey='arrivals-summary|'.$today;
        if(!isset($state['sent'][$summaryKey])) {
            $chunks=[];$chunk="TRANSTRADE INTERNATIONAL\nArrival report | $today | Pakistan time";
            foreach($state['summaryRows'][$today]??$arrivals as $r) {
                $text="\n\n".tg_arrival_text($r);
                if(mb_strlen($chunk.$text)>3300){$chunks[]=$chunk;$chunk='Arrival report continued | '.$today;}
                $chunk.=$text;
            }
            $chunks[]=$chunk;
            foreach($chunks as $i=>$text)yield ['key'=>$summaryKey.'|'.$i,'hash'=>'1','text'=>$text."\n\nTotal trucks today: ".count($state['summaryRows'][$today]??$arrivals)];
            yield ['key'=>$summaryKey,'hash'=>'1','markOnly'=>true];
        } else {
            foreach($arrivals as $r)yield ['key'=>'arrival|'.$today.'|'.$r['id'],'hash'=>tg_hash($r),
                'text'=>"Mill arrival saved\n".tg_arrival_text($r)."\n\nTotal trucks today now: ".count($arrivals)];
        }
        // The summary covers these exact versions, so they must not be sent again as new trucks.
        if(!isset($state['sent'][$summaryKey]))foreach($state['summaryRows'][$today]??$arrivals as $r)yield ['key'=>'arrival|'.$today.'|'.$r['id'],'hash'=>tg_hash($r),'markOnly'=>true];
    }
    if($clock>='11:00')yield ['key'=>'directors|'.$today,'hash'=>'1','morning'=>$today];
    if(!empty($state['repairRecoveryDownload'])) {
        $from=$now->modify('-7 days')->format('Y-m-d');
        $to=$now->modify('-1 day')->format('Y-m-d');
        $report=mr_report(tg_rows($ops,'tt30prod'),['entity'=>'TTI','mode'=>'daily','from'=>$from,'to'=>$to],stockValues:$ops['values']??[]);
        if($report['groups']) {
            $report['title']='Daily Recovery';
            yield ['key'=>'recovery-download-repair','hash'=>'1','report'=>$report,'caption'=>'Daily Recovery | '.$from.' to '.$to.' | new copy with direct download'];
        }
    }
    $root=json_decode($ops['values']['transtrade_export_v3_operational']??'{}',true,512,JSON_THROW_ON_ERROR);
    $audits=array_reverse((array)($root['audits']??[]));
    foreach($audits as $audit) {
        if((strtotime((string)($audit['at']??''))?:0)<($state['since']??PHP_INT_MAX))continue;
        $id=(string)($audit['id']??tg_hash($audit));
        yield ['key'=>'export|'.$id,'hash'=>'1','text'=>"Exports update\n".implode("\n",array_filter([
            (string)($audit['user']??'Exports user'),trim(($audit['area']??'').' | '.($audit['action']??'')),
            (string)($audit['contractRef']??''),(string)(($audit['changeSummary']??'')?:($audit['detail']??'')),(string)($audit['at']??''),
        ]))];
    }
    foreach(tg_rows($ops,'tt30prod') as $run) {
        if(empty($run['id']))continue;
        $key='production|'.($run['entity']??'TTI').'|'.$run['id'];
        if (mr_is_calculated($run) && empty($run['shiftEntriesComplete'])) continue;
        $hash=tg_hash($run);
        $savedAt=strtotime((string)($run['updatedAt']??$run['createdAt']??''));
        if (!isset($state['observed'][$key]) && ($run['date']??'')<$today
            && ($savedAt===false || $savedAt<($state['since']??PHP_INT_MAX))) continue;
        if(($state['observed'][$key]??null)===$hash)continue;
        $report=mr_report([$run],['entity'=>$run['entity']??'TTI','mode'=>'daily','from'=>$run['date'],'to'=>$run['date']],stockValues:$ops['values']??[]);
        if(!$report['groups'])continue;
        $report['title']='Saved Shift Production and Recovery';
        $report['reference']=($run['date']??'').' | '.($run['shift']??'').' | '.($run['millName']??'');
        yield ['key'=>$key,'hash'=>$hash,'report'=>$report,'caption'=>$report['title'].' | '.$report['reference']];
    }
    foreach(tg_loading_sources($ops) as $shipment) {
        $containers=(array)($shipment['containers']??[]);
        $sid=(string)($shipment['_ttShipmentId']??$shipment['id']??'');
        if($sid===''||!$containers)continue;
        $identity=($shipment['contractRef']??'').' | '.($shipment['lotRef']??$shipment['lotId']??'').' | '.($shipment['brand']??'');
        $loaded=array_values(array_filter($containers,static fn($c)=>($c['loadingComplete']??false)===true||(!array_key_exists('loadingComplete',$c)&&!empty($c['container'])&&!empty($c['seal'])&&!empty($c['truck'])&&($c['weight']??0)>0)));
        $progress=count($loaded).' containers loaded out of '.($shipment['totalContainers']??'?').' planned in this lot';
        yield ['key'=>'loading-start|'.$sid,'hash'=>'1','text'=>"Mill loading started\n".$identity."\n".$progress];
        foreach($loaded as $c) {
            $number=(string)($c['container']??'');if($number==='')continue;
            yield ['key'=>'container|'.$sid.'|'.$number,'hash'=>tg_hash($c),'text'=>"Mill container out\n".$identity."\nContainer ".$number."\nBags ".($c['bags']??'—')." | Net kg ".($c['weight']??'—')."\nTruck ".($c['truck']??'—')." | Seal ".($c['seal']??'—')."\n".$progress];
        }
    }
    foreach(tg_mill_event_sources() as $source=>$label) {
        foreach(tg_rows($ops,$source) as $record) {
            if(empty($record['id']))continue;
            $key='mill-entry|'.$source.'|'.$record['id'];
            $hash=tg_hash($record);
            if(($state['observed'][$key]??null)===$hash)continue;
            if($source==='tt35localsales'){
                yield ['key'=>$key,'hash'=>$hash,'text'=>tg_local_sale_text($record),'fullTextFile'=>'Mill Local Sale.txt'];
                continue;
            }
            $details=[$label.' saved'];
            foreach(['entity'=>'Company','date'=>'Date','millName'=>'Mill','vehicle'=>'Truck','truck'=>'Truck','slip'=>'Pohanch','brand'=>'Brand','party'=>'Party','payee'=>'Paid to','ref'=>'Reference','voucher'=>'Voucher','bags'=>'Bags','qty'=>'Quantity','weight'=>'Weight kg','netWeight'=>'Net kg','amount'=>'Amount','type'=>'Expense','narration'=>'Narration','notes'=>'Notes','createdBy'=>'Saved by'] as $field=>$name) {
                if(isset($record[$field])&&is_scalar($record[$field])&&(string)$record[$field]!=='')$details[]=$name.' '.$record[$field];
            }
            yield ['key'=>$key,'hash'=>$hash,'text'=>implode("\n",$details)];
        }
    }
    foreach(tt_telegram_production_reports($root,tg_rows($ops,'tt30prod'),$state['since'],$ops['values']??[]) as $job) {
        $report=$job['report'];$report['title']=$job['title'];$report['reference']=$job['ref'];
        yield ['key'=>'completed|'.tg_hash($job['key']),'hash'=>'1','report'=>$report,'caption'=>$job['title'].' | '.$job['ref']];
    }
}

/** Render committed Mill facts. Payment locations are the masked names saved on the Soda. */
function tg_local_sale_text(array $sale): string {
    $kg=static fn(float $value):string=>rtrim(rtrim(number_format($value,3,'.',','),'0'),'.').' kg';
    $money=static fn(float $value):string=>'PKR '.number_format($value,2);
    $rate=(float)($sale['rate']??0);
    $quantity=(float)($sale['tons']??0)*1000;
    $product=(string)($sale['displayName']??'');
    if($product==='')$product=implode(' · ',array_filter([$sale['baseVariety']??'',$sale['riceType']??'',$sale['product']??'']));
    $lines=['Mill local sale saved','Soda '.($sale['soda']??$sale['id']),
        ($sale['accountEntity']??$sale['entity']??'TTI').' | '.($sale['millName']??'TTI Rice Mills'),
        'Date '.($sale['date']??'—'),'Buyer '.($sale['party']??'—')];
    if(!empty($sale['broker']))$lines[]='Broker '.$sale['broker'];
    $lines[]='Sold: '.($product?:'—');
    $lines[]='Agreed quantity: '.$kg($quantity).' | Rate: '.rtrim(rtrim(number_format($rate,4,'.',','),'0'),'.').' PKR/kg';
    $lines[]='Agreed value: '.$money($quantity*$rate);
    $loaded=0.;$billable=0.;$loadLines=[];
    foreach((array)($sale['loads']??[]) as $load){
        $weight=(float)($load['weighbridgeGrossKg']??$load['kg']??0);
        $billed=$weight;
        if(empty($sale['byProductSale'])&&($sale['billingWeightBasis']??'')==='NET')$billed-=(float)($load['emptyTareKg']??0);
        $loaded+=$weight;$billable+=$billed;
        $loadLines[]=implode(' | ',array_filter([$load['date']??'',!empty($load['truck'])?'Truck '.$load['truck']:'',
            !empty($load['container'])?'Container '.$load['container']:'',
            $kg($weight),!empty($load['bags'])?$load['bags'].' bags':'',!empty($load['printed'])?($load['gatePassRef']??'Gate pass issued'):'']));
    }
    $lines[]=$loadLines?'Loaded: '.$kg($loaded).' | Billable: '.$kg($billable).' | Loaded value: '.$money($billable*$rate):'Loading: none recorded';
    array_push($lines,...$loadLines);
    $payments=(array)($sale['pay']??[]);$paid=0.;$paymentLines=[];
    foreach($payments as $payment){
        $amount=(float)($payment['amount']??0);$paid+=$amount;
        $method=(string)($payment['type']??'');
        $where=trim((string)($payment['where']??''));
        $destination=match($method){
            'Transtrade Bank'=>'Transtrade bank: '.($where?:'account not recorded'),
            'Third-Party Account'=>'Third-party bank: '.($where?:'name not recorded'),
            'Other Adjustment'=>'Adjustment from Soda '.($payment['sourceSoda']??'—'),
            'Cash'=>'Cash'.($where!==''?' | Reference: '.$where:''),
            default=>$method.($where!==''?' | '.$where:''),
        };
        $paymentLines[]=implode(' | ',array_filter([$payment['date']??'',$money($amount),$destination]));
    }
    $lines[]=$paymentLines?'Payments recorded by Mill: '.$money($paid):'Payments: none recorded';
    array_push($lines,...$paymentLines);
    if($paymentLines)$lines[]='Payment posting is subject to Accounts approval.';
    $lines[]=!empty($sale['closed'])?'Mill status: closed':'Mill status: open';
    if(!empty($sale['doneBy']))$lines[]='Soda done by '.$sale['doneBy'];
    return implode("\n",$lines);
}

function tg_mill_event_sources(): array {
    return ['tt30slips'=>'Mill Pohanch','tt33pettyexp'=>'Mill petty cash expense',
        'tt37processingexpenses'=>'Mill processing expense','tt38labourbills'=>'Mill labour bill',
        'tt35localsales'=>'Mill local sale','tt33bagreceipts'=>'Mill bag receipt'];
}
function tg_arp_tables(array $data): array {
    $tables=[];
    $add=static function(string $title,array $headers,array $rows)use(&$tables):void {
        $widths=array_fill(0,count($headers),intdiv(770,count($headers)));
        $widths[count($headers)-1]+=770-array_sum($widths);
        $tables[]=['title'=>$title,'headers'=>$headers,'widths'=>$widths,'rows'=>$rows?:[['No matching records',...array_fill(1,count($headers)-1,'')]]];
    };
    $num=static fn($v,int $decimals=2)=>$v===null?'Review required':number_format((float)$v,$decimals);
    $mt=static fn($v)=>$num($v,3);
    $currencies=static fn($v)=>implode(' / ',array_map(static fn($currency,$amount)=>$currency.' '.$num($amount),array_keys($v??[]),array_values($v??[])))?:'0.00';
    $t=$data['totals'];
    $add('Group totals',['Measure','Total'],[
        ['Bank balances','PKR '.$num($t['bankPKR']).' / '.$currencies($t['banks'])],
        ['Importer receivables','PKR '.$num($t['receivablePKR']).' / '.$currencies($t['receivables'])],
        ['Purchased - all recorded SODAs',$mt($t['purchasedMinMT']).' - '.$mt($t['purchasedMaxMT']).' MT'],
        ['Received against recorded SODAs',$mt($t['receivedMT']).' MT'],
        ['Remaining purchases',$mt($t['purchaseBalanceMinMT']).' - '.$mt($t['purchaseBalanceMaxMT']).' MT'],
        ['Contracted export sales',$mt($t['exportContractedMT']).' MT'],
        ['Loaded against export sales',$mt($t['exportLoadedMT']).' MT'],
        ['Pending export sales',$mt($t['exportPendingMT']).' MT'],
        ['Additional fellow-exporter sales',$mt($t['fellowContractedMT']).' MT'],
        ['Loaded against additional fellow-exporter sales',$mt($t['fellowLoadedMT']).' MT'],
        ['Pending fellow-exporter sales',$mt($t['fellowPendingMT']).' MT'],
        ['Total sales - counted once',$mt($t['contractedMT']).' MT'],
        ['Total pending sales',$mt($t['pendingMT']).' MT'],
    ]);
    $add('Company breakdown',['Company','Bank balances','Bank PKR','Importer receivables','Receivable PKR','Purchase SODAs','Sales'],array_map(static fn($r)=>[$r['entity'],$currencies($r['banks']),$num($r['bankPKR']),$currencies($r['receivables']),$num($r['receivablePKR']),$r['purchaseCount'],$r['salesCount']],$data['companies']));
    $add('Bank balances',['Company','Bank / account','Currency','Balance','PKR rate','PKR equivalent'],array_map(static function($r)use($data,$num){
        $rate=$r['currency']==='PKR'?1:($data['rates'][$r['currency']]??null);
        return [$r['entity'],$r['label'],$r['currency'],$num($r['amount']),$rate?$num($rate,6):'Required',$rate&&$r['amount']!==null?$num($r['amount']*$rate):'Required'];
    },$data['banks']));
    $purchaseRows=static fn($rows)=>array_map(static fn($r)=>[$r['entity'],$r['number'],$r['product'],$mt($r['contractedMinMT']).' - '.$mt($r['contractedMaxMT']),$r['receivedKnown']?$mt($r['receivedMT']):'Review required',$mt($r['undeliveredMinMT']).' - '.$mt($r['undeliveredMaxMT']),$r['status']],$rows);
    $purchaseHeaders=['Company','SODA','Product','Purchased MT','Received MT','Remaining MT','Status'];
    $add('Remaining purchase SODAs',$purchaseHeaders,$purchaseRows(array_filter($data['purchases'],static fn($r)=>!$r['closed']&&($r['undeliveredMaxMT']===null||$r['undeliveredMaxMT']>0))));
    $add('Closed purchase deliveries',$purchaseHeaders,$purchaseRows(array_filter($data['purchases'],static fn($r)=>$r['closed'])));
    $add('Original purchase receipts',['Company','SODA','Date','Truck / container','Product','Source','Received MT'],array_map(static fn($r)=>[$r['entity'],$r['soda'],$r['date'],$r['reference'],$r['product'],$r['source'],$mt($r['quantityMT'])],$data['purchaseReceipts']??[]));
    $salesRows=static fn($rows)=>array_map(static fn($r)=>[$r['entity'],isset($r['exporterDealId'])?'Fellow exporter':'Export',$r['party']??'',$r['ref'],$r['product'],$mt($r['contractedMT']),$r['loadedKnown']?$mt($r['loadedMT']):'Review required',$mt($r['outstandingMT'])],$rows);
    $salesHeaders=['Company','Sale type','Party','Contract / SODA','Product','Agreed MT','Loaded MT','Pending MT'];
    $add('Pending sales - export and fellow exporter',$salesHeaders,$salesRows(array_filter($data['sales'],static fn($r)=>!$r['closed']&&($r['outstandingMT']===null||$r['outstandingMT']>0))));
    $add('Completed export and fellow-exporter sales',$salesHeaders,$salesRows(array_filter($data['sales'],static fn($r)=>$r['closed'])));
    $fellow=array_values(array_filter($data['fellowSales']??[],static fn($r)=>!$r['closed']));
    $add('Fellow-exporter sales and purchase coverage',['Company','Exporter / SODA','Product / brand','Agreed MT','Loaded MT','Pending MT','Sales calculation'],array_map(static fn($r)=>[$r['entity'],$r['party'].' / '.$r['reference'],trim($r['product'].' / '.$r['brand']),$mt($r['quantityMT']),$mt($r['loadedMT']),$mt($r['remainingMT']),$r['salesBasis']],$fellow));
    $add('Fellow-exporter requirement calculations',['Exporter / SODA','Pending final goods MT','Saved recovery %','Raw required MT','Allocated purchases MT','Received purchases MT','Remaining purchases MT'],array_map(static fn($r)=>[$r['party'].' / '.$r['reference'],$mt($r['remainingMT']),$r['recoveryPct']??'-',$r['rawRequiredMT']===null?'Recovery not set':$mt($r['rawRequiredMT']),$r['purchases']?$mt($r['allocatedPurchaseMT']):'No specific allocation',$r['purchases']?$mt($r['receivedPurchaseMT']):'-',$r['purchases']?$mt($r['remainingPurchaseMT']):'-'],$fellow));
    $purchases=[];
    foreach($fellow as $sale)foreach($sale['purchases'] as $r)$purchases[]=[$sale['entity'],$sale['party'].' / '.$sale['reference'],$r['soda'],$r['product'],$r['stage'],$r['allocationBasis'],$mt($r['allocatedMT']),$mt($r['receivedMT']),$mt($r['remainingMT'])];
    $add('Purchases against fellow-exporter sales',['Company','Exporter / SODA','Purchase SODA','Product','Raw / ready','Allocation','Allocated MT','Received MT','Remaining MT'],$purchases);
    $add('Shipments being loaded',['Company','Contract / lot','Location','Planned containers','Loaded containers','Loaded MT','Status'],array_map(static fn($r)=>[$r['entity'],trim($r['contractRef'].' / '.$r['lot']),$r['location'],$r['plannedContainers']??'Review required',$r['loadedContainers']??'Review required',$mt($r['loadedMT']),$r['status']],$data['loading']));
    $add('Importer receivables - issued final documents',['Company','Importer','Contract / invoice','Invoice date','Currency','Invoice value','Allocated receipts / advances','Receivable','Status'],array_map(static fn($r)=>[$r['entity'],$r['label'],$r['contractRef'].' / '.$r['reference'],$r['date'],$r['currency'],$num($r['invoiceAmount']),$num($r['received']),$num($r['amount']),$r['status']],$data['receivables']));
    $add('Items requiring review',['Detail'],array_map(static fn($r)=>[is_array($r)?($r['message']??'Review required'):(string)$r],$data['holds']));
    return $tables;
}
function tg_morning_pdf(string $date): array {
    require_once __DIR__.'/business_reports_core.php';
    require_once __DIR__.'/shipment_profitability_core.php';
    $books=sp_read_books(TT_DATA_DIR.'/accounts.json');$ops=br_operations();$root=br_root($ops);
    $data=br_arp($books,$ops,$root,$date,dcd_group_rates($books,$date));
    return ['entity'=>'TTI + BRM + TG','title'=>'Directors Morning Update','reference'=>'As of '.$date.' | Pakistan time','filters'=>['from'=>$date,'to'=>$date],'tables'=>tg_arp_tables($data)];
}

function tg_state_save(string $path,array $state): void {
    $temp=tempnam(TT_DATA_DIR,'.telegram-state-');
    if($temp===false)throw new RuntimeException('Alert state unavailable.');
    try {
        $json=json_encode($state,JSON_THROW_ON_ERROR);
        if(!chmod($temp,0600)||file_put_contents($temp,$json)!==strlen($json)||!rename($temp,$path))throw new RuntimeException('Alert state could not be saved.');
    } finally { if(is_file($temp))unlink($temp); }
}

function tg_dispatch(bool $initialize=false,string $platform='telegram'): array {
    $config=tt_social_config($platform);
    if(!tt_social_enabled($platform,$config))return ['disabled'=>true];
    $path=tt_social_file($platform,'dispatch');$lock=fopen($path.'.lock','c');
    if(!$lock)throw new RuntimeException('Alert lock unavailable.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['busy'=>true];}
    try {
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi'));
        $ops=mr_read_operations_source(TT_DATA_DIR.'/operations.json',array_merge(['tt30queue','tt30slips','tt30prod','tt30ship','tt32stockadj','tt35localsales','tt35exload','tt40exinstructions','tt35exportersale','transtrade_export_v3_operational'],array_keys(tg_mill_event_sources())));
        if(!isset($ops['values']['transtrade_export_v3_operational']))throw new RuntimeException('Committed Exports source unavailable.');
        $state=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):null;
        $destination=$platform==='discord'?(string)$config['channelId']:null;
        if(!$state || ($destination!==null && ($state['destination']??null)!==$destination)) {
            if(!$initialize)throw new RuntimeException('Activate the automatic alerts first.');
            $state=['since'=>($platform==='telegram'?$now->setTime(0,0):$now)->getTimestamp(),'sent'=>[],'observed'=>[],'enabled'=>true,'repairRecoveryDownload'=>$platform==='telegram'];
            if($destination!==null)$state['destination']=$destination;
            foreach(tg_rows($ops,'tt30prod') as $run)$state['observed']['production|'.($run['entity']??'TTI').'|'.$run['id']]=tg_hash($run);
            foreach(tg_mill_event_sources() as $source=>$label)foreach(tg_rows($ops,$source) as $record)if(!empty($record['id']))$state['observed']['mill-entry|'.$source.'|'.$record['id']]=tg_hash($record);
            // Loading history becomes the baseline. Upcoming saved containers still produce alerts.
            foreach(tg_loading_sources($ops) as $shipment) {
                $sid=(string)($shipment['_ttShipmentId']??$shipment['id']??'');
                if(!empty($shipment['containers']))$state['sent']['loading-start|'.$sid]=['hash'=>'1','at'=>$now->format(DATE_ATOM)];
                foreach($shipment['containers']??[] as $c)if(!empty($c['container'])&&(($c['loadingComplete']??false)===true||(!array_key_exists('loadingComplete',$c)&&!empty($c['seal'])&&!empty($c['truck'])&&($c['weight']??0)>0)))
                    $state['sent']['container|'.$sid.'|'.$c['container']]=['hash'=>tg_hash($c),'at'=>$now->format(DATE_ATOM)];
            }
            tg_state_save($path,$state);
        }
        if(($state['retryAfter']??0)>time())return ['rateLimited'=>true];
        unset($state['retryAfter']);
        if(isset($state['sourceBackend'])&&$state['sourceBackend']!==($ops['sourceBackend']??'file'))throw new RuntimeException('The worker source backend differs from the activated source.');
        $state['sourceBackend']=$ops['sourceBackend']??'file';
        $today=$now->format('Y-m-d');
        if($now->format('H:i')>='10:00'&&!isset($state['summaryRows'][$today])) {
            $state['summaryRows']=[$today=>array_values(array_filter(tg_rows($ops,'tt30queue'),static fn($r)=>($r['date']??'')===$today&&empty($r['deleted'])))];
            tg_state_save($path,$state);
        }
        $delivered=0;$errors=0;$morning=null;
        $jobs=(static function()use($ops,$state,$now,$config): iterable {
            yield from tg_notification_jobs($ops,$state,$now);
            yield from tt_social_optional_jobs($config['reports']??[],$now);
        })();
        foreach($jobs as $job) {
            if(($state['sent'][$job['key']]['hash']??null)===$job['hash'])continue;
            if($delivered>=20||tt_social_config($platform)!==$config)break;
            if(isset($state['pending'][$job['key']])) {
                if(str_starts_with($job['key'],'arrivals-summary|'))break;
                continue;
            }

            try {
                // Persist an in-flight identity before sending. An uncertain result needs review.
                if(empty($job['markOnly'])) {
                    $state['pending'][$job['key']]=['hash'=>$job['hash'],'at'=>$now->format(DATE_ATOM)];
                    tg_state_save($path,$state);
                }
                if(isset($job['morning'])){$morning??=tg_morning_pdf($job['morning']);$job['report']=$morning;$job['caption']='Directors Morning Update | '.$job['morning'];}
                if(isset($job['optionalReport'])) {
                    $job['report']=tt_social_optional_report($job['optionalReport'],$now,$ops,$morning);
                    $job['caption']=$job['report']['title'].' | '.$job['report']['reference'];
                }
                if(isset($job['report'])) {
                    $r=$job['report'];$name=tt_document_name(['company'=>$r['entity'],'title'=>$r['title']??'Production Recovery','reference'=>$r['reference']??'','from'=>$r['filters']['from']??'','to'=>$r['filters']['to']??'','extension'=>'pdf']);
                    if($platform==='discord')tt_discord_send_pdf($r,$name,$job['caption'],$destination);
                    else tt_telegram_send_pdf($r,$name,$job['caption']);
                    $delivered++;
                } elseif(isset($job['text'])) {
                    $text=mb_substr($job['text'],0,4000);
                    if($platform==='discord')tt_discord_deliver($job['text'],expectedChannel:$destination);
                    elseif(isset($job['fullTextFile'])&&mb_strlen($job['text'],'UTF-8')>4000){
                        tt_telegram_send_text_file($job['text'],$job['fullTextFile']);
                    }else tt_telegram_deliver('sendMessage',['text'=>$text]);
                    $delivered++;
                }
                unset($state['pending'][$job['key']]);
                $state['sent'][$job['key']]=['hash'=>$job['hash'],'at'=>$now->format(DATE_ATOM)];
                if($job['key']==='recovery-download-repair')unset($state['repairRecoveryDownload']);
                if(str_starts_with($job['key'],'production|')||str_starts_with($job['key'],'mill-entry|'))$state['observed'][$job['key']]=$job['hash'];
                $state['lastSuccess']=$now->format(DATE_ATOM);
                tg_state_save($path,$state);
            } catch(Throwable $error) {
                if(!$error instanceof TelegramDeliveryUncertain && !$error instanceof DiscordDeliveryUncertain)unset($state['pending'][$job['key']]);
                $state['lastError']='A '.tt_social_platforms()[$platform].' delivery could not be confirmed.';
                $state['lastErrorAt']=$now->format(DATE_ATOM);
                if($error instanceof DiscordDeliveryRateLimited)$state['retryAfter']=time()+$error->seconds;
                tg_state_save($path,$state);$errors++;
                // Keep a multi-part arrival summary pending until every part is confirmed.
                if($error instanceof DiscordDeliveryRateLimited || str_starts_with($job['key'],'arrivals-summary|'))break;
            }
        }
        if(isset($state['sent']['arrivals-summary|'.$today]))unset($state['summaryRows'][$today]);
        $state['lastRun']=$now->format(DATE_ATOM);$state['lastRunErrors']=$errors;tg_state_save($path,$state);
        return ['delivered'=>$delivered,'errors'=>$errors,'sourceBackend'=>$ops['sourceBackend']??'file','at'=>$state['lastRun']];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}


function tt_social_dispatch_all(bool $initialize=false): array {
    $results=[];$errors=0;
    foreach(tt_social_platforms() as $platform=>$label) {
        try {
            $results[$platform]=tg_dispatch($initialize||$platform==='discord',$platform);
            $errors+=(int)($results[$platform]['errors']??0);
        } catch(Throwable) {
            $results[$platform]=['errors'=>1];
            $errors++;
        }
    }
    return ['platforms'=>$results,'errors'=>$errors];
}
