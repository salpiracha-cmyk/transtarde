<?php
declare(strict_types=1);
require_once __DIR__.'/mill_recovery_core.php';
require_once __DIR__.'/telegram_alerts_core.php';
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
        $report=mr_report(tg_rows($ops,'tt30prod'),['entity'=>'TTI','mode'=>'daily','from'=>$from,'to'=>$to]);
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
            (string)($audit['contractRef']??''),(string)($audit['detail']??''),(string)($audit['at']??''),
        ]))];
    }
    foreach(tg_rows($ops,'tt30prod') as $run) {
        if(empty($run['id']))continue;
        $key='production|'.($run['entity']??'TTI').'|'.$run['id'];
        $hash=tg_hash($run);
        if(!isset($state['observed'][$key])&&($run['date']??'')<$today)continue;
        if(($state['observed'][$key]??null)===$hash)continue;
        $report=mr_report([$run],['entity'=>$run['entity']??'TTI','mode'=>'daily','from'=>$run['date'],'to'=>$run['date']]);
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
            $details=[$label.' saved'];
            foreach(['entity'=>'Company','date'=>'Date','millName'=>'Mill','vehicle'=>'Truck','truck'=>'Truck','slip'=>'Pohanch','brand'=>'Brand','party'=>'Party','payee'=>'Paid to','ref'=>'Reference','voucher'=>'Voucher','bags'=>'Bags','qty'=>'Quantity','weight'=>'Weight kg','netWeight'=>'Net kg','amount'=>'Amount','type'=>'Expense','narration'=>'Narration','notes'=>'Notes','createdBy'=>'Saved by'] as $field=>$name) {
                if(isset($record[$field])&&is_scalar($record[$field])&&(string)$record[$field]!=='')$details[]=$name.' '.$record[$field];
            }
            yield ['key'=>$key,'hash'=>$hash,'text'=>implode("\n",$details)];
        }
    }
    foreach(tt_telegram_production_reports($root,tg_rows($ops,'tt30prod'),$state['since']) as $job) {
        $report=$job['report'];$report['title']=$job['title'];$report['reference']=$job['ref'];
        yield ['key'=>'completed|'.tg_hash($job['key']),'hash'=>'1','report'=>$report,'caption'=>$job['title'].' | '.$job['ref']];
    }
}

function tg_mill_event_sources(): array {
    return ['tt30slips'=>'Mill Pohanch','tt33pettyexp'=>'Mill petty cash expense',
        'tt37processingexpenses'=>'Mill processing expense','tt38labourbills'=>'Mill labour bill',
        'tt35localsales'=>'Mill local sale','tt33bagreceipts'=>'Mill bag receipt'];
}
function tg_pdf_detail_rows(array $record, string $prefix=''): array {
    $rows=[];
    foreach($record as $key=>$value) {
        $label=trim($prefix.' '.preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string)$key));
        if(is_array($value)) {
            $rows=array_merge($rows,tg_pdf_detail_rows($value,$label));
        } else {
            $text=$value===null?'Unavailable':(is_bool($value)?($value?'Yes':'No'):(string)$value);
            $rows[]=[ucfirst($label),$text];
        }
    }
    return $rows;
}
function tg_morning_pdf(string $date): array {
    require_once __DIR__.'/business_reports_core.php';
    require_once __DIR__.'/shipment_profitability_core.php';
    $books=sp_read_books(TT_DATA_DIR.'/accounts.json');$ops=br_operations();$root=br_root($ops);
    $data=br_arp($books,$ops,$root,$date,dcd_group_rates($books,$date));
    $tables=[];
    $tables[]=['title'=>'Group totals','headers'=>['Measure','Value'],'widths'=>[350,420],
        'rows'=>tg_pdf_detail_rows($data['totals'])];
    foreach(['companies'=>'Company breakdown','banks'=>'Bank positions','purchases'=>'Purchases','purchaseReceipts'=>'Purchase receipts','sales'=>'Sales and shipments','fellowSales'=>'Fellow exporter sales','loading'=>'Loading position','receivables'=>'Receivables','holds'=>'Items requiring attention'] as $key=>$title) {
        foreach((array)($data[$key]??[]) as $i=>$record) {
            if(!is_array($record))continue;
            $tables[]=['title'=>$title.' | '.($i+1),'headers'=>['Detail','Saved report value'],'widths'=>[250,520],
                'rows'=>tg_pdf_detail_rows($record)];
        }
    }
    return ['entity'=>'TTI + BRM + TG','title'=>'Directors Morning Update','reference'=>'As of '.$date.' | Pakistan time','filters'=>['from'=>$date,'to'=>$date],'tables'=>$tables];
}

function tg_state_save(string $path,array $state): void {
    $temp=tempnam(TT_DATA_DIR,'.telegram-state-');
    if($temp===false)throw new RuntimeException('Alert state unavailable.');
    try {
        $json=json_encode($state,JSON_THROW_ON_ERROR);
        if(!chmod($temp,0600)||file_put_contents($temp,$json)!==strlen($json)||!rename($temp,$path))throw new RuntimeException('Alert state could not be saved.');
    } finally { if(is_file($temp))unlink($temp); }
}

function tg_dispatch(bool $initialize=false): array {
    if(!is_file(TT_DATA_DIR.'/telegram-alerts.json'))throw new RuntimeException('Save the Telegram connection first.');
    $path=TT_DATA_DIR.'/telegram-dispatch.json';$lock=fopen($path.'.lock','c');
    if(!$lock)throw new RuntimeException('Alert lock unavailable.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['busy'=>true];}
    try {
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi'));
        $ops=mr_read_operations_source(TT_DATA_DIR.'/operations.json',array_merge(['tt30queue','tt30prod','tt30ship','tt35exload','tt40exinstructions','tt35exportersale','transtrade_export_v3_operational'],array_keys(tg_mill_event_sources())));
        if(!isset($ops['values']['transtrade_export_v3_operational']))throw new RuntimeException('Committed Exports source unavailable.');
        $state=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):null;
        if(!$state) {
            if(!$initialize)throw new RuntimeException('Activate the automatic alerts first.');
            $state=['since'=>$now->setTime(0,0)->getTimestamp(),'sent'=>[],'observed'=>[],'enabled'=>true,'repairRecoveryDownload'=>true];
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
        if(isset($state['sourceBackend'])&&$state['sourceBackend']!==($ops['sourceBackend']??'file'))throw new RuntimeException('The worker source backend differs from the activated source.');
        $state['sourceBackend']=$ops['sourceBackend']??'file';
        $today=$now->format('Y-m-d');
        if($now->format('H:i')>='10:00'&&!isset($state['summaryRows'][$today])) {
            $state['summaryRows']=[$today=>array_values(array_filter(tg_rows($ops,'tt30queue'),static fn($r)=>($r['date']??'')===$today&&empty($r['deleted'])))];
            tg_state_save($path,$state);
        }
        $delivered=0;$errors=0;
        foreach(tg_notification_jobs($ops,$state,$now) as $job) {
            if(($state['sent'][$job['key']]['hash']??null)===$job['hash'])continue;
            if($delivered>=20)break;
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
                if(isset($job['morning'])){$job['report']=tg_morning_pdf($job['morning']);$job['caption']='Directors Morning Update | '.$job['morning'];}
                if(isset($job['report'])) {
                    $r=$job['report'];$name=tt_document_name(['company'=>$r['entity'],'title'=>$r['title']??'Production Recovery','reference'=>$r['reference']??'','from'=>$r['filters']['from']??'','to'=>$r['filters']['to']??'','extension'=>'pdf']);
                    tt_telegram_send_pdf($r,$name,$job['caption']);$delivered++;
                } elseif(isset($job['text'])) {
                    $text=mb_substr($job['text'],0,4000);
                    tt_telegram_deliver('sendMessage',['text'=>$text]);$delivered++;
                }
                unset($state['pending'][$job['key']]);
                $state['sent'][$job['key']]=['hash'=>$job['hash'],'at'=>$now->format(DATE_ATOM)];
                if($job['key']==='recovery-download-repair')unset($state['repairRecoveryDownload']);
                if(str_starts_with($job['key'],'production|')||str_starts_with($job['key'],'mill-entry|'))$state['observed'][$job['key']]=$job['hash'];
                $state['lastSuccess']=$now->format(DATE_ATOM);
                tg_state_save($path,$state);
            } catch(Throwable $error) {
                if(!$error instanceof TelegramDeliveryUncertain)unset($state['pending'][$job['key']]);
                $state['lastError']='A Telegram delivery could not be confirmed.';
                $state['lastErrorAt']=$now->format(DATE_ATOM);tg_state_save($path,$state);$errors++;
                // Keep a multi-part arrival summary pending until every part is confirmed.
                if(str_starts_with($job['key'],'arrivals-summary|'))break;
            }
        }
        if(isset($state['sent']['arrivals-summary|'.$today]))unset($state['summaryRows'][$today]);
        $state['lastRun']=$now->format(DATE_ATOM);$state['lastRunErrors']=$errors;tg_state_save($path,$state);
        return ['delivered'=>$delivered,'errors'=>$errors,'sourceBackend'=>$ops['sourceBackend']??'file','at'=>$state['lastRun']];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
