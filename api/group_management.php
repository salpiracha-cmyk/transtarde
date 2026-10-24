<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/group_management_core.php';
require_once __DIR__.'/directors_cost_defaults_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function gm_out(array $data,int $status=200):never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $user=tt_require_login();$origin=($_GET['origin']??'accounts')==='directors'?'Directors':'Accounts';$write=$_SERVER['REQUEST_METHOD']==='POST';
    if(!$write&&$_SERVER['REQUEST_METHOD']!=='GET')gm_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=$write?json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true,512,JSON_THROW_ON_ERROR):[];
    if(!is_array($body))throw new DomainException('Invalid request.');$kind=strtoupper(gm_text($body['kind']??$_GET['kind']??'CASHFLOW',20));
    if(!in_array($kind,['CASHFLOW','ZAKAT','DAILY','COSTING'],true))throw new DomainException('Select a report.');
    if($kind==='DAILY')require_once __DIR__.'/group_daily_core.php';
    if(in_array($kind,['DAILY','CASHFLOW'],true))require_once __DIR__.'/group_cashflow_comparison.php';
    if(!gm_permission($user,$kind,'View',$origin))gm_out(['ok'=>false,'error'=>'Shared group report permission and all company books are required.'],403);
    if($write){if(!tt_verify_csrf((string)($body['csrf']??'')))gm_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);if(!gm_permission($user,$kind,'Create',$origin)&&!gm_permission($user,$kind,'Edit',$origin))gm_out(['ok'=>false,'error'=>'Report Create or Edit permission is required.'],403);}
    $date=gm_date($body['date']??$_GET['date']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'));
    $id=$kind.'|'.$date;$section=gm_text($body['section']??'',40);$action=gm_text($body['action']??'',40);
    if(!$write)tt_release_read_session();
    // Assessments have their own store. Saving report adjustments never rewrites company journals.
    $file=TT_DATA_DIR.'/accounts.json';if(!is_file($file))throw new RuntimeException('Accounts source has not been initialized.');
    $booksHandle=fopen($file,'rb');if(!$booksHandle||!flock($booksHandle,LOCK_SH))throw new RuntimeException('Accounts source unavailable.');
    try{$raw=(string)stream_get_contents($booksHandle);if($raw==='')throw new RuntimeException('Empty Accounts source.');$state=mr_decode_store($raw);$booksRevision=(int)($state['revision']??0);}finally{flock($booksHandle,LOCK_UN);fclose($booksHandle);}
    if(!$write&&isset($_GET['postId']))gm_out(['ok'=>true,'post'=>gm_post_detail($state,gm_text($_GET['postId'],120),$date)]);
    $reportFile=TT_DATA_DIR.'/group-management.json';$lockFile=$reportFile.'.lock';$h=null;
    if($write||is_file($lockFile)){$h=fopen($lockFile,$write?'c+':'rb');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Report storage unavailable.');}
    try {
        $reportState=is_file($reportFile)?mr_decode_store((string)file_get_contents($reportFile)):[];
        $state['groupManagementRecords']=(array)($reportState['groupManagementRecords']??[]);$state['groupManagementRequests']=(array)($reportState['groupManagementRequests']??[]);$state['revision']=(int)($reportState['revision']??0);
        $record=$state['groupManagementRecords'][$id]??['id'=>$id,'kind'=>$kind,'date'=>$date,'sections'=>[],'history'=>[],'createdAt'=>gmdate('c')];$result=[];
        if($write){
            if($action!=='save-section')throw new DomainException('Select a valid report action.');
            $allowed=$kind==='ZAKAT'?['header','financials','properties','gold','shares','adjustments']:($kind==='COSTING'?['header','costing']:($kind==='DAILY'?['header','daily','adjustments']:['header','financials','adjustments']));
            if(!in_array($section,$allowed,true))throw new DomainException('Select a valid section.');
            $needed=isset($record['sections'][$section])?'Edit':'Create';if(!gm_permission($user,$kind,$needed,$origin))gm_out(['ok'=>false,'error'=>$needed.' permission is required for this section.'],403);
            $old=$record['sections'][$section]??['version'=>0];$key=gm_text($body['requestKey']??'',128);if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key))throw new DomainException('Reopen the section before saving.');
            $requestId=$id.'|'.(string)($user['id']??0).'|'.$key;$fingerprint=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));$prior=$state['groupManagementRequests'][$requestId]??null;
            if($prior){if(!hash_equals($prior['fingerprint'],$fingerprint))gm_out(['ok'=>false,'error'=>'This save key was used for different data. Reopen before saving.'],409);$result=['duplicate'=>true];}
            else {
                if((int)($body['version']??-1)!==(int)$old['version'])gm_out(['ok'=>false,'error'=>'Another user changed this section. Your entry has not been overwritten. Refresh deliberately and compare before saving.'],409);
                $reason=gm_text($body['reason']??'',1000);if(!empty($old['complete'])&&$reason==='')throw new DomainException('Enter a reason to amend a completed section.');
                $data=[];$rows=[];$calculation=null;
                if($section==='header'){
                    $rates=(array)($body['data']['rates']??[]);$clean=['PKR'=>1.];foreach(['USD','AED'] as $currency)if(isset($rates[$currency])&&$rates[$currency]!=='')$clean[$currency]=gm_fx($rates,$currency);
                    $data=['rates'=>$clean,'notes'=>gm_text($body['data']['notes']??'',2000)];
                    if($kind==='ZAKAT'){$year=gm_text($body['data']['hijriYear']??'',4);if(!preg_match('/^1[34][0-9]{2}$/',$year)||empty($body['data']['ramadanDateConfirmed']))throw new DomainException('Enter the Hijri year and confirm this date is 1 Ramadan.');foreach((array)($state['groupManagementRecords']??[]) as $other)if(($other['kind']??'')==='ZAKAT'&&($other['id']??'')!==$id&&($other['sections']['header']['data']['hijriYear']??'')===$year)throw new DomainException('An assessment already exists for that Hijri year. Open its original date.');$data+=['hijriYear'=>$year,'ramadanDateConfirmed'=>true,'openingCarryPKR'=>gm_number($body['data']['openingCarryPKR']??0,'opening signed carry-forward',true)];}
                    foreach($record['sections'] as $name=>&$existing)if($name!=='header'&&!empty($existing['complete'])){if($reason==='')throw new DomainException('Enter a reason to change the header after section completion.');$existing['complete']=false;$existing['version']++;}unset($existing);
                }elseif($section==='daily'){
                    $fresh=gd_sources(array_replace($state,['revision'=>$booksRevision]),gd_read_operations(TT_DATA_DIR.'/operations.json'),$date);
                    if(!hash_equals($fresh['fingerprint'],(string)($body['sourceFingerprint']??'')))gm_out(['ok'=>false,'error'=>'Daily sources changed. Your draft is retained. Refresh deliberately and review the changed sources before saving.'],409);
                    $config=gd_clean_config((array)($body['data']['config']??[]));$calculation=gd_positions($fresh,$config);
                    if(!empty($body['complete'])&&(!$calculation['complete']||empty($body['reviewConfirmed'])))throw new DomainException('Review every source hold and unmapped commitment, and confirm the product specifications, before completing the Daily section.');
                    if(!empty($body['complete'])){if(!isset($record['sections']['header']))throw new DomainException('Save the Daily date and exchange-rate header first.');gm_total(array_merge($fresh['financials']['rows'],(array)($record['sections']['adjustments']['rows']??[])),(array)($record['sections']['header']['data']['rates']??[]));}
                    $data=['config'=>$config,'source'=>$fresh];
                }elseif($section==='costing'){$data=(array)($body['data']??[]);$calculation=gm_costing($data);unset($data['actualComparison']);if(!empty($data['actualSelection']['enabled'])){$ops=mr_read_operations_source(TT_DATA_DIR.'/operations.json');$data['actualComparison']=gm_actual_comparison((array)$data['actualSelection'],$ops,array_replace($state,['revision'=>$booksRevision]),$calculation,(string)($data['commodity']??'RICE'));}}
                else {
                    $rows=gm_rows((array)($body['rows']??[]));
                    if($kind==='DAILY')foreach($rows as $row)if(str_starts_with($row['id'],'BOOK-'))throw new DomainException('Daily book balances are included in the saved source snapshot. Add only a separately identified adjustment.');
                    $canonical=[];foreach(gm_financial_source($state,$date)['rows'] as $sourceRow)$canonical[$sourceRow['id']]=$sourceRow;$previousRows=[];foreach((array)($old['rows']??[]) as $previousRow)$previousRows[$previousRow['id']]=$previousRow;
                    foreach($rows as &$row)if(str_starts_with($row['id'],'BOOK-')){$original=$previousRows[$row['id']]??$canonical[$row['id']]??null;if(!$original)throw new DomainException('This original book row is unavailable. Reload its source before adding it.');$row['originalAmount']=$original['originalAmount']??$original['amount'];$row['originalCurrency']=$original['originalCurrency']??$original['currency'];foreach(['source','postIds','sourceDate','sourceFingerprint','account','subaccountId'] as $field)if(isset($original[$field]))$row[$field]=$original[$field];}unset($row);

                    if($kind!=='ZAKAT')foreach($rows as $row)if(in_array($row['type'],['PROPERTY','GOLD','SHARES'],true))throw new DomainException('Cashflow does not include properties, gold or shares.');
                    if(!empty($body['complete'])){if($kind==='ZAKAT'&&$section==='gold'){ $holders=[];foreach($rows as $row)if($row['type']==='GOLD')$holders[gm_text($row['holder']??'')]=true;if(isset($holders[''])||count($holders)<3)throw new DomainException('Record gold separately for all three wives, including an explicit zero where applicable.');}if(empty($body['reviewConfirmed']))throw new DomainException('Confirm source, estimates, omissions and exclusions have been reviewed.');$rates=(array)($record['sections']['header']['data']['rates']??[]);$calculation=gm_total($rows,$rates);if($kind==='ZAKAT'&&!isset($record['sections']['header']))throw new DomainException('Save and confirm the 1 Ramadan assessment header first.');}
                }
                $saved=['version'=>(int)$old['version']+1,'rows'=>$rows,'data'=>$data,'calculation'=>$calculation,'complete'=>!empty($body['complete']),'savedAt'=>gmdate('c'),'savedBy'=>(string)($user['full_name']??$user['username']??''),'userId'=>(int)($user['id']??0),'reason'=>$reason];
                $record['history'][]=['section'=>$section,'before'=>$old,'savedAt'=>$saved['savedAt'],'savedBy'=>$saved['savedBy'],'reason'=>$reason];$record['sections'][$section]=$saved;
                if($kind!=='COSTING'){ // Duplicate source IDs are never permitted across the saved assessment sections.
                    $seen=[];foreach($record['sections'] as $part)foreach((array)($part['rows']??[]) as $r){if(isset($seen[$r['id']]))throw new DomainException('A source row already exists in another section.');$seen[$r['id']]=true;}
                }
                if(in_array($kind,['CASHFLOW','DAILY'],true)){try{$capture=gcf_capture($record,$state);$comparison=gcf_comparison($record,$state,$capture);$record['cashflowSnapshotHistory'][]=['before'=>isset($record['cashflowSnapshot'])?array_intersect_key($record['cashflowSnapshot'],array_flip(['date','totalPKR','cashPKR','capturedAt'])):null,'savedAt'=>gmdate('c'),'savedBy'=>$user['username']??''];$record['cashflowSnapshot']=$capture;$record['cashflowComparison']=$comparison;}catch(DomainException $e){unset($record['cashflowSnapshot']);$record['cashflowComparison']=['available'=>false,'message'=>$e->getMessage()];}}
                $state['groupManagementRecords'][$id]=$record;$state['groupManagementRequests'][$requestId]=['fingerprint'=>$fingerprint,'sectionVersion'=>$saved['version']];$state['revision']=(int)($state['revision']??0)+1;
                $reportState=['groupManagementRecords'=>$state['groupManagementRecords'],'groupManagementRequests'=>$state['groupManagementRequests'],'revision'=>$state['revision']];
                $encoded=json_encode($reportState,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$temp=tempnam(TT_DATA_DIR,'gm-save-');if($temp===false)throw new RuntimeException('Report save unavailable.');
                try{if(file_put_contents($temp,$encoded)!==strlen($encoded)||!rename($temp,$reportFile))throw new RuntimeException('Report save failed.');}finally{if(is_file($temp))unlink($temp);}
                $result=['sectionVersion'=>$saved['version'],'message'=>!empty($saved['complete'])?('Section completed.'.($kind==='ZAKAT'?' Assessment control updated; no new expense posted.':'')):'Section saved.'];
            }
        }
        $source=gm_financial_source($state,$date);$source['accountsRevision']=$booksRevision;$summary=null;$summaryError='';
        try{$through=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$summary=$kind==='DAILY'?gd_summary($record):gm_record_summary($record,$state,$through);}catch(DomainException $e){$summaryError=$e->getMessage();}
        $records=[];foreach((array)($state['groupManagementRecords']??[]) as $r)if(($r['kind']??'')===$kind)$records[]=['id'=>$r['id'],'date'=>$r['date'],'hijriYear'=>$r['sections']['header']['data']['hijriYear']??''];
        $dailySource=$kind==='DAILY'?gd_sources(array_replace($state,['revision'=>$booksRevision]),gd_read_operations(TT_DATA_DIR.'/operations.json'),$date):null;
        $payload=['dailyRateDefaults'=>dcd_group_rates($state,$date),'dailySource'=>$dailySource,'ok'=>true,'record'=>$record,'source'=>$source,'summary'=>$summary,'summaryError'=>$summaryError,'records'=>$records,'result'=>$result,'revision'=>(int)($state['revision']??0),'canCreate'=>gm_permission($user,$kind,'Create',$origin),'canEdit'=>gm_permission($user,$kind,'Edit',$origin),'canWrite'=>gm_permission($user,$kind,'Create',$origin)||gm_permission($user,$kind,'Edit',$origin)];
    }finally{if($h){flock($h,LOCK_UN);fclose($h);}}
    gm_out($payload);
}catch(DomainException|JsonException $e){gm_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Group management: '.$e->getMessage());gm_out(['ok'=>false,'error'=>'Group report could not be loaded or saved. No success has been confirmed.'],500);}
