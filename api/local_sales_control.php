<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_LS_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';
const TT_LS_MASTER_FILE = __DIR__ . '/../accounts/accounting_master_v1.json';
const TT_LS_POLICY_FILE = __DIR__ . '/../accounts/local_sales_policy_v1.json';

function ls_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function ls_json(string $path): array {
    $raw = is_file($path) ? file_get_contents($path) : false;
    $v = $raw ? json_decode($raw, true) : null;
    if (!is_array($v)) throw new RuntimeException('Required configuration is unavailable.');
    return $v;
}
function ls_module_write(array $user, string $module): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $p = $user['permissions'][$module] ?? null;
    if ($p === 'all') return true;
    if (!is_array($p)) return false;
    if (in_array('Create', $p, true) || in_array('Edit', $p, true) || in_array('Approve', $p, true)) return true;
    foreach ($p as $a) if (is_array($a) && (in_array('Create',$a,true) || in_array('Edit',$a,true) || in_array('Approve',$a,true))) return true;
    return false;
}
function ls_default_store(): array {
    return ['revision'=>0,'journals'=>[],'events'=>[],'reminders'=>[],'masters'=>[],'commodityBills'=>[],'exportCandidates'=>[],'localSalesCandidates'=>[]];
}
function ls_read(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_LS_ACCOUNTS_FILE)) return ls_default_store();
    $h=fopen(TT_LS_ACCOUNTS_FILE,'r');
    if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(ls_default_store(),$s):ls_default_store();
}
function ls_valid_entity(string $entity): string {
    $entity=strtoupper(trim($entity));
    if(!in_array($entity,['TTI','BRM'],true))ls_respond(['ok'=>false,'error'=>'Local sale must belong to an authorized Pakistan entity.'],422);
    return $entity;
}
function ls_date(string $v): string {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))ls_respond(['ok'=>false,'error'=>'Valid sale date required.'],422);
    return $v;
}
function ls_positive(mixed $v,string $label): float {
    $n=round((float)$v,2);if($n<=0)ls_respond(['ok'=>false,'error'=>$label.' must be greater than zero.'],422);return $n;
}
function ls_fy_bounds(string $date,array $policy): array {
    $d=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Karachi'));
    [$m,$day]=array_map('intval',explode('-',str_pad((string)($policy['financialYearEnd']??'06-30'),5,'0',STR_PAD_LEFT)));
    $year=(int)$d->format('Y');
    $end=DateTimeImmutable::createFromFormat('!Y-n-j',$year.'-'.$m.'-'.$day,new DateTimeZone('Asia/Karachi'));
    if(!$end)throw new RuntimeException('Financial year configuration is invalid.');
    if($d>$end)$end=$end->modify('+1 year');
    $start=$end->modify('-1 year')->modify('+1 day');
    return [$start->format('Y-m-d'),$end->format('Y-m-d')];
}
function ls_revenue_sum(array $store,array $entities,string $account,string $from,string $to): float {
    $sum=0.0;
    foreach((array)($store['journals']??[]) as $j){
        if(!is_array($j)||($j['status']??'')!=='Posted')continue;
        if(!in_array((string)($j['entity']??''),$entities,true))continue;
        $date=(string)($j['date']??'');if($date<$from||$date>$to)continue;
        foreach((array)($j['lines']??[]) as $line){
            if(!is_array($line)||(string)($line['account']??'')!==$account)continue;
            $sum += (float)($line['credit']??0) - (float)($line['debit']??0);
        }
    }
    return round($sum,2);
}
function ls_summary(array $store,string $asOf,array $policy,float $pendingAdd=0): array {
    [$from,$to]=ls_fy_bounds($asOf,$policy);
    $scope=(array)($policy['scope']??[]);
    $localEntities=array_values((array)($scope['localSaleEntities']??['TTI','BRM']));
    $exportEntities=array_values((array)($scope['exportSaleEntities']??['TTI','BRM']));
    $localAccount=(string)($scope['localRevenueAccount']??'4200');
    $exportAccount=(string)($scope['exportRevenueAccount']??'4100');
    $local=ls_revenue_sum($store,$localEntities,$localAccount,$from,$to);
    $export=ls_revenue_sum($store,$exportEntities,$exportAccount,$from,$to);
    $ratio=$export>0?round(($local/$export)*100,4):($local>0?INF:0.0);
    $projectedLocal=round($local+$pendingAdd,2);
    $projected=$export>0?round(($projectedLocal/$export)*100,4):($projectedLocal>0?INF:0.0);
    $warning=(float)($policy['warningPercent']??4.8);$limit=(float)($policy['hardLimitPercent']??5.0);
    $limitAmount=round($export*$limit/100,2);$headroom=max(0,round($limitAmount-$local,2));
    $byProduct=[];
    foreach((array)($store['journals']??[]) as $j){
        if(!is_array($j)||($j['status']??'')!=='Posted'||!in_array((string)($j['entity']??''),$localEntities,true))continue;
        $date=(string)($j['date']??'');if($date<$from||$date>$to)continue;
        $hasLocal=false;$amt=0.0;
        foreach((array)($j['lines']??[]) as $line)if(is_array($line)&&(string)($line['account']??'')===$localAccount){$v=(float)($line['credit']??0)-(float)($line['debit']??0);if($v!==0){$hasLocal=true;$amt+=$v;}}
        if(!$hasLocal)continue;
        $meta=is_array($j['meta']??null)?$j['meta']:[];$p=trim((string)($meta['product']??$meta['byProduct']??'Unspecified'))?:'Unspecified';
        $byProduct[$p]=round(($byProduct[$p]??0)+$amt,2);
    }
    arsort($byProduct);
    $status='OK';if($ratio>=$limit)$status='LIMIT_REACHED';elseif($ratio>=$warning)$status='WARNING';
    return ['financialYearFrom'=>$from,'financialYearTo'=>$to,'asOf'=>$asOf,'localSales'=>$local,'exportSales'=>$export,'ratioPercent'=>is_finite($ratio)?$ratio:null,'projectedLocalSales'=>$projectedLocal,'projectedRatioPercent'=>is_finite($projected)?$projected:null,'warningPercent'=>$warning,'hardLimitPercent'=>$limit,'limitAmount'=>$limitAmount,'remainingHeadroom'=>$headroom,'status'=>$status,'byProduct'=>$byProduct];
}
function ls_account_names(): array {
    $m=ls_json(TT_LS_MASTER_FILE);$out=[];foreach((array)($m['chart']??[]) as $a)if(is_array($a)&&isset($a['code']))$out[(string)$a['code']]=(string)($a['name']??$a['code']);return $out;
}
function ls_line(string $account,float $dr,float $cr,array $names): array {
    if(!isset($names[$account]))ls_respond(['ok'=>false,'error'=>'Approved account '.$account.' is missing from Chart of Accounts.'],422);
    return ['account'=>$account,'accountName'=>$names[$account],'debit'=>round($dr,2),'credit'=>round($cr,2)];
}
function ls_next_id(array $items,string $prefix): string {$n=count($items)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);$n++;}while(isset($items[$id]));return $id;}

try{
    $user=tt_require_login();$policy=ls_json(TT_LS_POLICY_FILE);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(!tt_user_can_open_module($user,'Accounts'))ls_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);
        $asOf=(string)($_GET['asOf']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'));$asOf=ls_date($asOf);
        $s=ls_read();$summary=ls_summary($s,$asOf,$policy);
        $pending=array_values(array_filter((array)$s['localSalesCandidates'],static fn($x)=>is_array($x)&&($x['status']??'')==='Pending Accounts Approval'));
        usort($pending,static fn($a,$b)=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));
        ls_respond(['ok'=>true,'summary'=>$summary,'pending'=>$pending,'policy'=>$policy,'serverNow'=>gmdate('c')]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')ls_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input')?:'',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))ls_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');

    if($action==='preflight'){
        if(!ls_module_write($user,'Mill')&&!ls_module_write($user,'Accounts'))ls_respond(['ok'=>false,'error'=>'Mill or Accounts permission required.'],403);
        $date=ls_date((string)($body['saleDate']??''));$amount=ls_positive($body['amount']??0,'Local sale amount');$s=ls_read();$summary=ls_summary($s,$date,$policy,$amount);
        $projected=$summary['projectedRatioPercent'];$limit=(float)$summary['hardLimitPercent'];$warning=(float)$summary['warningPercent'];
        $blocked=$projected===null||$projected>$limit; $warn=!$blocked&&$projected!==null&&$projected>=$warning;
        ls_respond(['ok'=>true,'blocked'=>$blocked,'warning'=>$warn,'summary'=>$summary,'message'=>$blocked?'Projected local sales exceed the Transtrade 5% control. Accounts approval will be blocked.':($warn?'Projected local sales are at or above the 4.8% warning level.':'Within current Transtrade local-sales control.')]);
    }

    if($action==='queue_candidate'){
        if(!ls_module_write($user,'Mill'))ls_respond(['ok'=>false,'error'=>'Mill Create or Edit permission required.'],403);
        $entity=ls_valid_entity((string)($body['entity']??'TTI'));$date=ls_date((string)($body['saleDate']??''));$amount=ls_positive($body['amount']??0,'Local sale amount');
        $sourceKey=trim((string)($body['sourceKey']??''));if($sourceKey===''||strlen($sourceKey)>180)ls_respond(['ok'=>false,'error'=>'Stable Local Sale reference required.'],422);
        $product=trim((string)($body['product']??''));if($product==='')ls_respond(['ok'=>false,'error'=>'By-product / product is required for Local Sales.'],422);
        $party=trim((string)($body['party']??''));$soda=trim((string)($body['soda']??''));$kg=round((float)($body['loadedKg']??0),3);$rate=round((float)($body['ratePerKg']??0),4);
        tt_ensure_data_dir();$h=fopen(TT_LS_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
        try{rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=ls_default_store();$store=array_replace_recursive(ls_default_store(),$store);
            $id=$entity.'|LOCAL_SALE|'.$sourceKey;
            if(!isset($store['localSalesCandidates'][$id]))$store['localSalesCandidates'][$id]=['id'=>$id,'entity'=>$entity,'sourceKey'=>$sourceKey,'soda'=>$soda,'saleDate'=>$date,'party'=>$party,'product'=>$product,'loadedKg'=>$kg,'ratePerKg'=>$rate,'amount'=>$amount,'status'=>'Pending Accounts Approval','journalId'=>null,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Mill Staff')];
            $store['revision']=(int)($store['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);$candidate=$store['localSalesCandidates'][$id];
        }finally{flock($h,LOCK_UN);fclose($h);}ls_respond(['ok'=>true,'candidate'=>$candidate]);
    }

    if($action==='approve_candidate'||$action==='reject_candidate'){
        if(!ls_module_write($user,'Accounts'))ls_respond(['ok'=>false,'error'=>'Accounts approval permission required.'],403);
        $id=trim((string)($body['candidateId']??''));if($id==='')ls_respond(['ok'=>false,'error'=>'Local Sale candidate is required.'],422);
        tt_ensure_data_dir();$h=fopen(TT_LS_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
        try{rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=ls_default_store();$store=array_replace_recursive(ls_default_store(),$store);$c=$store['localSalesCandidates'][$id]??null;if(!is_array($c))ls_respond(['ok'=>false,'error'=>'Local Sale candidate not found.'],404);if(($c['status']??'')!=='Pending Accounts Approval')ls_respond(['ok'=>false,'error'=>'This Local Sale candidate is no longer pending.'],409);
            if($action==='reject_candidate'){$store['localSalesCandidates'][$id]['status']='Returned / Rejected by Accounts';$store['localSalesCandidates'][$id]['reviewNote']=trim((string)($body['note']??''));$store['localSalesCandidates'][$id]['reviewedAt']=gmdate('c');$store['localSalesCandidates'][$id]['reviewedBy']=(string)($user['full_name']??$user['username']??'Accounts');$journal=null;}
            else{$summary=ls_summary($store,(string)$c['saleDate'],$policy,(float)$c['amount']);$projected=$summary['projectedRatioPercent'];$limit=(float)$summary['hardLimitPercent'];if($projected===null||$projected>$limit)ls_respond(['ok'=>false,'error'=>'Approval blocked: this Local Sale would exceed the Transtrade 5% local-sales control based on current recognized export sales.','summary'=>$summary],422);
                $names=ls_account_names();$amount=(float)$c['amount'];$lines=[ls_line('1220',$amount,0,$names),ls_line('4200',0,$amount,$names)];$jid=ls_next_id((array)$store['journals'],'AUTO');$store['journals'][$jid]=['id'=>$jid,'entity'=>(string)$c['entity'],'date'=>(string)$c['saleDate'],'sourceType'=>'LOCAL_SALE_RECOGNIZED','reference'=>(string)($c['soda']?:$c['sourceKey']),'narration'=>'Local sale recognized — '.(string)$c['product'].' — '.(string)$c['party'],'lines'=>$lines,'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>['candidateId'=>$id,'sourceKey'=>$c['sourceKey'],'soda'=>$c['soda'],'party'=>$c['party'],'product'=>$c['product'],'byProduct'=>$c['product'],'loadedKg'=>$c['loadedKg'],'ratePerKg'=>$c['ratePerKg'],'localSalesControl'=>['ratioBefore'=>$summary['ratioPercent'],'projectedRatio'=>$summary['projectedRatioPercent'],'warningPercent'=>$summary['warningPercent'],'hardLimitPercent'=>$summary['hardLimitPercent']]],'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts'),'userId'=>(int)($user['id']??0),'reversalOf'=>null];$store['localSalesCandidates'][$id]['status']='Accounts Approved / Posted';$store['localSalesCandidates'][$id]['journalId']=$jid;$store['localSalesCandidates'][$id]['approvedAt']=gmdate('c');$store['localSalesCandidates'][$id]['approvedBy']=(string)($user['full_name']??$user['username']??'Accounts');$store['localSalesCandidates'][$id]['controlSnapshot']=$summary;$journal=$store['journals'][$jid];}
            $store['revision']=(int)($store['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);$candidate=$store['localSalesCandidates'][$id];
        }finally{flock($h,LOCK_UN);fclose($h);}ls_respond(['ok'=>true,'candidate'=>$candidate,'journal'=>$journal??null]);
    }
    ls_respond(['ok'=>false,'error'=>'Unknown Local Sales control action.'],422);
}catch(Throwable $e){ls_respond(['ok'=>false,'error'=>'The Local Sales control update could not be completed.'],500);}
