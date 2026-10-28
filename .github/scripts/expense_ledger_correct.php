<?php
declare(strict_types=1);

function elc_hash(array $record):string {return hash('sha256',json_encode($record,JSON_THROW_ON_ERROR));}
function elc_actor():array {return ['id'=>0,'username'=>'codex-owner-authorised-maintenance','full_name'=>'Codex maintenance authorised by Salman'];}
function elc_totals(array $store,bool $payments=false,bool $daily=false):array {
    $out=[];$store=alv_amendment_books($store);
    foreach((array)($store['journals']??[]) as $j){
        if(($j['status']??'')!=='Posted')continue;
        foreach((array)($j['lines']??[]) as $l){
            $code=(string)($l['account']??'');if($payments&&!in_array($code,['1110','1120','2400'],true))continue;
            $key=(string)($j['entity']??'').'|'.$code;
            if($daily)$key.='|'.(string)($j['date']??'');
            if($payments)$key.='|'.(string)($j['date']??'').'|'.(string)($l['bankAccountId']??$l['cashAccountId']??$l['paymentAccountId']??$l['cardMasterId']??'');
            $out[$key]=($out[$key]??0)+(int)round(100*((float)($l['debit']??0)-(float)($l['credit']??0)));
        }
    }
    $out=array_filter($out,static fn($n)=>$n!==0);ksort($out);return $out;
}
function elc_named(array &$s,string $entity,string $name,array $actor):string {
    $matches=[];foreach(sac_accounts($s,$entity) as $a)if(sac_normal((string)$a['name'])===sac_normal($name))$matches[]=$a;
    if(count($matches)>1)throw new RuntimeException('Named ledger is ambiguous.');
    if($matches){$a=$matches[0];if($a['parentCode']!=='6700'||empty($a['active']))throw new RuntimeException('Named professional ledger needs review.');return (string)$a['id'];}
    return sac_master($s,$entity,['operation'=>'add','name'=>$name,'parentCode'=>'6700','parentId'=>$entity.'-EXPENSE-TYPE-PROFESSIONAL','taxCategory'=>'NONE','expenseClassification'=>'OFFICE'],$actor)['subaccountId'];
}
function elc_archive(array &$s,array $actor):array {
    $archived=[];$protected=[];$chart=sac_chart();
    foreach(array_replace(sm_seed_masters(),(array)($s['salaryMasters']??[])) as $master)$protected[($master['entity']??'').'|'.sac_normal((string)($master['name']??''))]=true;
    // Any historical balance-account identity is protected, including settled/reversed accounts.
    foreach((array)($s['journals']??[]) as $j)foreach((array)($j['lines']??[]) as $l){
        $code=(string)($l['account']??'');$class=$chart[$code]['class']??'';
        if(in_array($code,['1110','1120'],true)||!(in_array($class,['Asset','Liability','Equity'],true)||str_starts_with($code,'FAM-')||str_starts_with($code,'REIMB-')))continue;
        foreach(['counterparty','party','person','supplier','vendor','customer','broker','subledger'] as $field){$name=(string)($l[$field]??$j['meta'][$field]??'');if($name!=='')$protected[($j['entity']??'').'|'.sac_normal($name)]=true;}
    }
    foreach(['TTI','BRM'] as $entity){
        $history=sac_expense_history_only($s,$entity);$named=[];foreach(sac_accounts($s,$entity) as $a)if(!empty($a['active']))$named[sac_normal((string)$a['name'])]=true;
        foreach((array)($s['paymentPayees']??[]) as $key=>$p){
            $name=sac_normal((string)($p['name']??''));
            if(($p['entity']??'')!==$entity||($p['kind']??'')!=='EXPENSE'||empty($p['active'])||!isset($history[$name])||isset($protected[$entity.'|'.$name])||!empty($p['subaccountId'])||isset($named[$name]))continue;
            if(sac_family_person((string)$p['name'])!==''||str_starts_with($name,'mrs')||str_contains($name,'tanvir')||str_contains($name,'market'))continue;
            $next=$p;$next['active']=false;$next['retiredReason']='Recipient-only payment history; original transactions and recipient references retained';$next['retiredAt']=gmdate('c');$next['retiredBy']=$actor['username'];
            $s['paymentPayees'][$key]=$next;$s['paymentPayeeAudit'][]=['entity'=>$entity,'operation'=>'ARCHIVE_EXPENSE_HISTORY','before'=>$p,'after'=>$next,'at'=>gmdate('c'),'by'=>$actor['username']];
            $archived[]=['id'=>$p['id'],'name'=>$p['name'],'entity'=>$entity];
        }
    }
    return $archived;
}
function elc_split_home(array $row,array $allocations,string $entity):array {
    if(($row['accountCode']??'')!=='6910'||($row['category']??'')!=='HOME'||($row['personalTreatment']??'')!=='COMPANY'||count($allocations)!==3)throw new RuntimeException('Only an explicit three-household company-borne Home split is supported.');
    $cents=(int)round(100*(float)$row['amount']);$sum=0;$seen=[];$pieces=[];$remainders=[];$allocated=0;
    foreach($allocations as $i=>$a){$person=$a['expenseFor']??'';$percent=$a['percent']??null;if(!in_array($person,['FAM-TALHA','FAM-TAYYAB','FAM-SALMAN'],true)||isset($seen[$person])||!is_int($percent)||$percent<1||$percent>100)throw new RuntimeException('Invalid household percentage allocation.');$seen[$person]=true;$sum+=$percent;$value=intdiv($cents*$percent,100);$allocated+=$value;$remainders[$i]=($cents*$percent)%100;$pieces[$i]=array_replace($row,['amount'=>$value/100,'expenseFor'=>$person,'expenseArea'=>'HOME','subaccountId'=>sac_home_id($entity,$person)]);}
    if($sum!==100)throw new RuntimeException('Household percentages must total exactly 100.');
    arsort($remainders,SORT_NUMERIC);$remaining=$cents-$allocated;foreach(array_keys($remainders) as $i){if($remaining<=0)break;$pieces[$i]['amount']=round($pieces[$i]['amount']+.01,2);$remaining--;}
    foreach($pieces as $piece)if($piece['amount']<=0)throw new RuntimeException('Each household allocation must be positive.');
    if($remaining!==0||array_sum(array_map(static fn($p)=>(int)round($p['amount']*100),$pieces))!==$cents)throw new RuntimeException('Household split total changed.');
    return array_values($pieces);
}
function elc_home_detail(array $s,string $entity,string $key):string {
    if(!in_array($key,['WATER_TANKER','GAS_CYLINDER','SOLAR_CLEANER'],true))throw new RuntimeException('Unapproved permanent household purpose.');
    $id=$entity.'-HOME-DETAIL-'.$key;$a=sac_resolve($s,$entity,$id);if(($a['homeExpenseDetail']??'')!==$key||$a['parentCode']!=='6910')throw new RuntimeException('Permanent household purpose is not deployed.');return $id;
}
function elc_advances(array $s):array {
    $out=[];foreach((array)($s['salaryAdvances']??[]) as $a){$row=[];foreach(['entity','masterId','person','date','recoveryFromMonth','amount','remaining','months','monthsRemaining','adjustments'] as $field){$v=$a[$field]??null;$row[$field]=is_int($v)||is_float($v)?number_format((float)$v,4,'.',''):$v;}$out[]=json_encode($row,JSON_THROW_ON_ERROR);}sort($out);return $out;
}
function elc_split_purposes(array $s,array $row,array $parts,string $entity):array {
    if(($row['accountCode']??'')!=='6910'||($row['personalTreatment']??'')!=='COMPANY'||count($parts)<2||count($parts)>4)throw new RuntimeException('Only company-borne household rows support purpose splits.');
    $weight=0;foreach($parts as $part){if(!is_int($part['weight']??null)||$part['weight']<1||$part['weight']>100)throw new RuntimeException('Invalid split weight.');$weight+=$part['weight'];}
    $cents=(int)round($row['amount']*100);$out=[];$remainders=[];$allocated=0;
    foreach($parts as $i=>$part){$category=$part['category']??'';$person=$part['expenseFor']??'';if(!in_array($category,['HOME','ARP'],true)||!in_array($person,['SHARED','FAM-SALMAN','FAM-TALHA','FAM-TAYYAB','FAM-ABU'],true)||($category==='ARP')!==($person==='FAM-ABU'))throw new RuntimeException('Invalid purpose allocation.');$value=intdiv($cents*$part['weight'],$weight);$allocated+=$value;$remainders[$i]=($cents*$part['weight'])%$weight;$sub=$category==='ARP'?$entity.'-EXPENSE-TYPE-ARP':(!empty($part['homeExpenseDetail'])?elc_home_detail($s,$entity,$part['homeExpenseDetail']):sac_home_id($entity,$person));$purpose=trim((string)($part['purposePrefix']??''));if(strlen($purpose)>80)throw new RuntimeException('Invalid purpose label.');$out[$i]=array_replace($row,['amount'=>$value/100,'category'=>$category,'expenseFor'=>$person,'expenseArea'=>'HOME','subaccountId'=>$sub,'purpose'=>$purpose!==''?$purpose.' — '.$row['purpose']:$row['purpose']]);}
    arsort($remainders,SORT_NUMERIC);foreach(array_keys($remainders) as $i){if($allocated>=$cents)break;$out[$i]['amount']=round($out[$i]['amount']+.01,2);$allocated++;}if($allocated!==$cents||min(array_column($out,'amount'))<=0)throw new RuntimeException('Invalid purpose split total.');return array_values($out);
}
function elc_apply(array &$s,array $manifest,string $batch):array {
    if(isset($s['expenseLedgerMaintenance'][$batch]))return $s['expenseLedgerMaintenance'][$batch]+['duplicate'=>true];
    if(($manifest['version']??0)!==1||empty($manifest['rows'])||count($manifest['rows'])>100)throw new RuntimeException('Invalid correction manifest.');
    $before=$s;$payments=elc_totals($s,true);$expected=elc_totals($s);$daily=elc_totals($s,false,true);$actor=elc_actor();$names=ev1_names();$groups=[];$allowedAdvances=[];
    foreach($manifest['rows'] as $entry){$fingerprint=(string)($entry['fingerprint']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))throw new RuntimeException('Invalid source fingerprint.');$groups[$fingerprint][]=$entry;}
    $results=[];
    foreach($groups as $fingerprint=>$entries){
        $matches=[];foreach((array)($s['generalExpenses']??[]) as $id=>$r)if(is_array($r)&&($r['status']??'Posted')==='Posted'&&elc_hash($r)===$fingerprint)$matches[(string)$id]=$r;
        if(count($matches)!==1)throw new RuntimeException('A reviewed expense changed or is unavailable. No batch was saved.');
        $id=(string)array_key_first($matches);$old=$matches[$id];$entity=(string)$old['entity'];$original=$s['journals'][$old['journalId']]??null;
        if(!in_array($entity,['TTI','BRM'],true)||($old['expenseType']??'')!=='DIRECT'||!is_array($original)||!empty($old['cardStatementId'])||!empty($original['reversedByPostId'])||!empty($original['amendedByPostId']))throw new RuntimeException('Expense requires its original linked workflow.');
        $public=tt_accounts_public_post($s,$original);if(str_ends_with($public,'-03046'))throw new RuntimeException('Protected posting is outside this maintenance batch.');
        $rows=(array)$old['expenseLines'];foreach($rows as &$row)$row['accountCode']=(string)$row['account'];unset($row);
        foreach((array)($s['expensePersonalLinks']['EXPENSE|'.$id]['rows']??[]) as $recovery){if(!empty($recovery['advanceId']))$allowedAdvances[$recovery['advanceId']]=true;$advance=$s['salaryAdvances'][$recovery['advanceId']??'']??[];if(!empty($recovery['cashRecovered'])||!empty($recovery['periods'])||!empty($advance['adjustments']))throw new RuntimeException('Settled personal recovery requires separate review.');}

        $monthly=false;$reasons=[];$narrationOverride=null;$splits=[];$purposeSplits=[];
        foreach($entries as $entry){
            if(array_key_exists('narration',$entry)){if(!is_string($entry['narration'])||strlen($entry['narration'])>900||($narrationOverride!==null&&$narrationOverride!==$entry['narration']))throw new RuntimeException('Invalid or conflicting owner-approved narration.');$narrationOverride=$entry['narration'];}
            $index=$entry['row']??null;if(!is_int($index)||!isset($rows[$index]))throw new RuntimeException('Reviewed expense row is unavailable.');
            if(($rows[$index]['personalTreatment']??'COMPANY')!=='COMPANY')throw new RuntimeException('Personal allocation is outside this correction batch.');
            $change=(array)($entry['changes']??[]);$allowed=['account','category','expenseFor','personalTreatment','expenseArea','assetId','namedSubledger','monthlyFee','homeExpenseDetail'];
            if(array_diff(array_keys($change),$allowed)||($change['personalTreatment']??'COMPANY')!=='COMPANY')throw new RuntimeException('Unexpected reclassification fields.');
            $from=(string)$rows[$index]['account'];$to=(string)($change['account']??$from);$amount=(int)round(100*(float)$rows[$index]['amount']);
            if(!in_array($to,['6910','5550','6400','6410','6610','6900','6700','7210'],true))throw new RuntimeException('Posting head is outside the owner-approved rules.');
            $expected[$entity.'|'.$from]=($expected[$entity.'|'.$from]??0)-$amount;$expected[$entity.'|'.$to]=($expected[$entity.'|'.$to]??0)+$amount;
            $date=(string)$original['date'];$daily[$entity.'|'.$from.'|'.$date]=($daily[$entity.'|'.$from.'|'.$date]??0)-$amount;$daily[$entity.'|'.$to.'|'.$date]=($daily[$entity.'|'.$to.'|'.$date]??0)+$amount;
            $monthly=$monthly||!empty($change['monthlyFee']);$named=(string)($change['namedSubledger']??'');$homeDetail=(string)($change['homeExpenseDetail']??'');unset($change['monthlyFee'],$change['namedSubledger'],$change['homeExpenseDetail']);
            $rows[$index]=array_replace($rows[$index],$change,['accountCode'=>$to,'subaccountId'=>'']);
            $category=(string)$rows[$index]['category'];$person=(string)($rows[$index]['expenseFor']??'SHARED');
            if($homeDetail!==''){if($to!=='6910'||$category!=='HOME')throw new RuntimeException('Household purpose requires Home Expense.');$rows[$index]['subaccountId']=elc_home_detail($s,$entity,$homeDetail);}
            elseif($named!=='')$rows[$index]['subaccountId']=elc_named($s,$entity,$named,$actor);
            elseif($category==='HOME')$rows[$index]['subaccountId']=sac_home_id($entity,$person);
            elseif($category==='ARP')$rows[$index]['subaccountId']=$entity.'-EXPENSE-TYPE-ARP';
            else foreach(sac_accounts($s,$entity) as $a)if(($a['builtinCategory']??'')===$category&&$a['parentCode']===$to){$rows[$index]['subaccountId']=$a['id'];break;}
            if(array_key_exists('allocations',$entry)){if(!is_array($entry['allocations'])||isset($splits[$index]))throw new RuntimeException('Invalid or duplicate household split.');$splits[$index]=$entry['allocations'];}
            if(array_key_exists('parts',$entry)){if(!is_array($entry['parts'])||isset($purposeSplits[$index])||isset($splits[$index]))throw new RuntimeException('Invalid duplicate purpose split.');$purposeSplits[$index]=$entry['parts'];}
            $reasons[]=(string)($entry['reason']??'Owner-approved expense classification');
        }
        $splitIndexes=array_unique(array_merge(array_keys($splits),array_keys($purposeSplits)));rsort($splitIndexes);foreach($splitIndexes as $index)array_splice($rows,$index,1,isset($purposeSplits[$index])?elc_split_purposes($s,$rows[$index],$purposeSplits[$index],$entity):elc_split_home($rows[$index],$splits[$index],$entity));
        $narration=(string)($old['paymentNarration']??$old['description']??'');
        if($monthly&&!str_contains(strtolower($narration),'monthly fee'))$narration='Monthly fee — Paid to '.(string)$old['payee'].($narration!==''?' — '.$narration:'');
        if($narrationOverride!==null)$narration=$narrationOverride;
        $tracking=[];foreach(['bankPaymentMethod','bankReference','chequeNo','chequeDate','bankPaymentDate','paymentNarration'] as $key){if(array_key_exists($key,$old))$tracking[$key]=$old[$key];elseif(array_key_exists($key,(array)($original['meta']??[])))$tracking[$key]=$original['meta'][$key];}
        $tracking['paymentNarration']=$narration;
        $body=['id'=>$id,'recipientInNarration'=>true,'payee'=>$old['payee'],'paymentDate'=>$old['paymentDate'],'paymentAccountId'=>$old['paymentAccountId'],'reference'=>$old['reference']??'','amount'=>$old['amount'],'expenseLines'=>$rows,'paymentNarration'=>$narration,'reason'=>substr('Owner-approved reclassification: '.implode('; ',array_unique($reasons)),0,490)];
        $priorIds=array_keys($s['journals']);$result=dex_amend($s,$entity,$body,$actor,$names,$tracking);tt_accounts_track_bank_payment($s,$priorIds,$tracking);
        foreach(array_diff(array_keys($s['journals']),$priorIds) as $jid){$reversed=(string)($s['journals'][$jid]['reversalOf']??'');if($reversed!==''&&isset($s['journals'][$reversed]))$s['journals'][$jid]['date']=$s['journals'][$reversed]['date'];}
        $new=$s['generalExpenses'][$result['generalExpenseId']];
        foreach((array)($s['expensePersonalLinks']['EXPENSE|'.$new['id']]['rows']??[]) as $recovery)if(!empty($recovery['advanceId']))$allowedAdvances[$recovery['advanceId']]=true;
        if((int)round(100*(float)$new['amount'])!==(int)round(100*(float)$old['amount']))throw new RuntimeException('Original expense amount changed. No batch was saved.');
        foreach(['paymentDate','paymentAccountId','reference','payeeId'] as $key)if(($key!=='payeeId'||!empty($old[$key]))&&(($new[$key]??'')!==($old[$key]??'')))throw new RuntimeException('Original expense/payment identity changed: '.$key.'. No batch was saved.');
        if(($new['publicPostId']??'')!==$public)throw new RuntimeException('Original public posting identity changed.');
        $results[]=['publicPostId'=>$public,'originalExpenseId'=>$id,'replacementExpenseId'=>$new['id'],'date'=>$new['paymentDate'],'amount'=>$new['amount'],'recipient'=>$new['payee'],'changes'=>$entries,'journalId'=>$result['journalId']];
    }
    $archived=!empty($manifest['archiveExpenseOnlyRecipients'])?elc_archive($s,$actor):[];
    if(elc_totals($s,true)!==$payments)throw new RuntimeException('Bank/cash/card balances or dates changed. No batch was saved.');
    $expected=array_filter($expected,static fn($n)=>$n!==0);ksort($expected);if(elc_totals($s)!==$expected)throw new RuntimeException('Ledger changes differ from the reviewed reclassification.');
    $daily=array_filter($daily,static fn($n)=>$n!==0);ksort($daily);if(elc_totals($s,false,true)!==$daily)throw new RuntimeException('Historical daily ledger balances changed outside the approved classification.');
    foreach(array_unique(array_merge(array_keys((array)($before['salaryAdvances']??[])),array_keys((array)($s['salaryAdvances']??[])))) as $advanceId)if(!isset($allowedAdvances[$advanceId])&&serialize($before['salaryAdvances'][$advanceId]??null)!==serialize($s['salaryAdvances'][$advanceId]??null))throw new RuntimeException('An unrelated remuneration advance changed.');
    if(elc_advances($s)!==elc_advances($before))throw new RuntimeException('Remuneration recovery amounts, months or outstanding balances changed.');
    foreach($before as $key=>$value)if(!in_array($key,['salaryAdvances','journals','generalExpenses','expenseAudit','expensePersonalLinks','paymentPayees','paymentPayeeAudit','accountSubaccounts','subaccountAudit','expenseLedgerMaintenance','revision'],true)&&serialize($s[$key]??null)!==serialize($value))throw new RuntimeException('An unrelated source register changed.');
    foreach(['TTI','BRM'] as $entity)pc_assert_balances($s,$entity);
    $result=['ok'=>true,'batch'=>$batch,'corrections'=>$results,'archivedRecipients'=>$archived,'historicalDailyBalancesPreserved'=>true,'remunerationRecoveryBalancesUnchanged'=>true,'bankCashCardBalancesUnchanged'=>true,'originalAmountsDatesSourcesPreserved'=>true,'authorisedBy'=>'Salman','performedBy'=>$actor['username'],'completedAt'=>gmdate('c')];
    $s['expenseLedgerMaintenance'][$batch]=$result;return $result;
}

if(defined('TT_EXPENSE_CORRECTION_LIBRARY'))return;
try{
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only.');$root=(string)getenv('TT_EXPENSE_REVIEW_ROOT');
    if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$root))throw new RuntimeException('Unexpected correction target.');
    $public=base64_decode((string)getenv('TT_EXPENSE_REVIEW_PUBLIC_KEY_B64'),true);if($public===false||openssl_pkey_get_public($public)===false)throw new RuntimeException('Result encryption key unavailable.');
    $manifestRaw=base64_decode((string)getenv('TT_EXPENSE_CORRECTION_MANIFEST_B64'),true);if($manifestRaw===false)throw new RuntimeException('Manifest unavailable.');
    $batch=hash('sha256',$manifestRaw);$mode=(string)getenv('TT_EXPENSE_CORRECTION_MODE');if(!in_array($mode,['PREVIEW','APPLY'],true))throw new RuntimeException('Explicit mode is required.');
    if($mode==='APPLY'&&!hash_equals($batch,(string)getenv('TT_EXPENSE_CORRECTION_APPROVED_SHA256')))throw new RuntimeException('Approved manifest fingerprint is required.');
    define('TT_EXPENSES_FUNCTIONS_ONLY',true);require $root.'/api/expenses_v1.php';
    if(!function_exists('elr_encrypt'))throw new RuntimeException('Verified encrypted-result bootstrap unavailable.');
    $_SERVER['REQUEST_METHOD']='POST';$GLOBALS['TT_AUTHORIZED_API_BODY']=['preview'=>$mode==='PREVIEW'];
    $manifest=json_decode($manifestRaw,true,512,JSON_THROW_ON_ERROR);$path=TT_DATA_DIR.'/accounts.json';$handle=fopen($path,$mode==='APPLY'?'r+':'rb');
    if(!$handle||!flock($handle,$mode==='APPLY'?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{
        $raw=(string)stream_get_contents($handle);$s=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($s)||!isset($s['journals']))throw new RuntimeException('Invalid Accounts source.');
        $result=elc_apply($s,$manifest,$batch);$result['mode']=$mode;$result['businessFilesWritten']=0;$result['beforeSourceSha256']=hash('sha256',$raw);
        if($mode==='APPLY'&&empty($result['duplicate'])){
            $dir=TT_DATA_DIR.'/expense-maintenance-safety';if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('Private safety workspace unavailable.');
            if(is_link($dir)||count(glob($dir.'/before-*.json.gz')?:[])>=7)throw new RuntimeException('Safety copies require review before another batch.');
            $backup=$dir.'/before-'.$batch.'.json.gz';$compressed=gzencode($raw,6);if($compressed===false||hash('sha256',gzdecode($compressed))!==hash('sha256',$raw))throw new RuntimeException('Safety snapshot verification failed.');
            $backupHandle=fopen($backup,'x');if(!$backupHandle)throw new RuntimeException('Safety snapshot already exists; review before retrying.');try{if(fwrite($backupHandle,$compressed)!==strlen($compressed)||!fflush($backupHandle))throw new RuntimeException('Safety snapshot failed.');}finally{fclose($backupHandle);}chmod($backup,0600);
            if(hash('sha256',(string)gzdecode((string)file_get_contents($backup)))!==hash('sha256',$raw))throw new RuntimeException('Saved safety snapshot verification failed.');
            $s['revision']=(int)($s['revision']??0)+1;$encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            try{rewind($handle);if(!ftruncate($handle,0)||fwrite($handle,$encoded)!==strlen($encoded)||!fflush($handle))throw new RuntimeException('Save failed.');rewind($handle);if(hash('sha256',(string)stream_get_contents($handle))!==hash('sha256',$encoded))throw new RuntimeException('Saved Accounts verification failed.');}
            catch(Throwable $writeError){rewind($handle);ftruncate($handle,0);if(fwrite($handle,$raw)!==strlen($raw)||!fflush($handle))throw new RuntimeException('Safety snapshot retained; automatic rollback needs attention.');throw $writeError;}
            $result['businessFilesWritten']=1;$result['revision']=$s['revision'];$result['verifiedSafetyCopy']=true;
        }
    }finally{flock($handle,LOCK_UN);fclose($handle);}
    $public=base64_decode((string)getenv('TT_EXPENSE_REVIEW_PUBLIC_KEY_B64'),true);if($public===false)throw new RuntimeException('Result encryption key unavailable.');
    $result['afterReview']=elr_collect($s,sac_chart());
    echo json_encode(elr_encrypt($result,$public),JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){
    // Detailed errors are encrypted too; logs never contain financial records.
    if(function_exists('elr_encrypt')&&($public=base64_decode((string)getenv('TT_EXPENSE_REVIEW_PUBLIC_KEY_B64'),true))!==false)echo json_encode(elr_encrypt(['ok'=>false,'error'=>$error->getMessage(),'mode'=>$mode??'','batch'=>$batch??''],$public),JSON_THROW_ON_ERROR).PHP_EOL;
    fwrite(STDERR,"Expense correction did not complete; inspect the encrypted result.\n");exit(1);
}
