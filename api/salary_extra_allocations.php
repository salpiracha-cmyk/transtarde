<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_subaccounts_core.php';
/** Canonical choices only: extra payments never change monthly entitlement. */
function sea_choices(array $s,string $e,array $u):array {
    $expenses=[];$capital=[];
    foreach(sac_accounts($s,$e) as $id=>$unused){try{$a=sac_resolve($s,$e,(string)$id);if($a['class']==='Expense'&&$a['expenseClassification']!=='MILL')$expenses[]=['id'=>$id,'name'=>$a['name'],'parentCode'=>$a['parentCode']];}catch(DomainException $ignore){}}
    usort($expenses,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    if(far_permission($u,'Accounts','Create')){
        foreach((array)($s['managedAssets']??[]) as $a)if(($a['entity']??'')===$e&&empty($a['trackingOnly'])&&($a['currency']??'')===($e==='TG'?'AED':'PKR')&&far_totals($a)['balanceCents']>0)$capital[]=['id'=>'ASSET_PAYMENT|'.$a['id'],'name'=>$a['name'],'version'=>(int)$a['version']];
        foreach((array)($s['investmentBrokers']??[]) as $a)if(($a['entity']??'')===$e&&!empty($a['active']))$capital[]=['id'=>'BROKER_FUND|'.$a['id'],'name'=>$a['name']];
        $capital[]=['id'=>'COMPANY_HELD','name'=>'Company funds held for onward transfer'];
        $capital[]=['id'=>'BROKER_RECEIVED','name'=>'Company broker · routed receipt confirmed'];
    }
    return ['allocationExpenses'=>$expenses,'allocationCapital'=>$capital,'allocationVehicles'=>array_values(array_map(static fn($a)=>['id'=>$a['id'],'name'=>$a['name'].' · '.($a['registrationNo']??'')],array_filter((array)($s['managedAssets']??[]),static fn($a)=>($a['entity']??'')===$e&&in_array($a['type']??'',['VEHICLE','MOTORCYCLE'],true))))];
}
function sea_validate(array $s,string $e,array $a,array $u,string $date):array {
    if($a['type']==='EXPENSE'){$sub=sac_resolve($s,$e,(string)($a['subaccountId']??''));if($sub['class']!=='Expense'||$sub['expenseClassification']==='MILL')throw new DomainException('Choose an expense subledger; production costs use the Milling workflow.');
        $extra=[];if(in_array($sub['parentCode'],['6410','6610','6620'],true)){$vehicle=$s['managedAssets'][$a['assetId']??'']??null;if(!$vehicle||($vehicle['entity']??'')!==$e||!in_array($vehicle['type']??'',['VEHICLE','MOTORCYCLE'],true))throw new DomainException('Choose the vehicle and registration number.');$extra=['assetId'=>$vehicle['id'],'assetName'=>$vehicle['name'],'registrationNo'=>$vehicle['registrationNo']??''];}
        if($sub['parentCode']==='6620'){$from=rsv2_date((string)($a['periodFrom']??''));$to=rsv2_date((string)($a['periodTo']??''));if($to<$from)throw new DomainException('Tax period end must follow its start.');$extra+=['periodFrom'=>$from,'periodTo'=>$to,'challanReference'=>$a['challanReference']??''];}
        return $sub+['paymentExtra'=>$extra];}
    sfw_company_access($u);
    if($a['type']==='BROKER_FUND'){
        if(trim((string)($a['reference']??''))==='')throw new DomainException('Enter the broker funding reference.');
        return inv_broker($s,$e,(string)($a['brokerId']??''));
    }
    $asset=$s['managedAssets'][$a['assetId']??'']??null;
    if(!$asset||($asset['entity']??'')!==$e||!empty($asset['trackingOnly'])||($asset['currency']??'')!==($e==='TG'?'AED':'PKR'))throw new DomainException('Choose an existing asset in these company books.');
    if((int)($a['assetVersion']??-1)!==(int)$asset['version'])throw new DomainException('The asset changed. Reopen the payment and review its balance.');
    if($date<($asset['purchaseDate']??'')||$date<FAR_CUTOFF)throw new DomainException('Asset payment date must be on or after its agreement and the accounting start date.');
    if((int)round($a['amount']*100)>far_totals($asset)['balanceCents'])throw new DomainException('Payment exceeds the remaining asset balance.');
    $label=far_text($a['instalmentNo']??'',60);if($label==='')throw new DomainException('Enter the asset instalment number or payment label.');
    foreach($asset['payments'] as $p)if($p['instalmentNo']===$label)throw new DomainException('This asset instalment is already recorded.');
    return $asset;
}
/** Direct capital funding follows the same company-bank rule as Assets. */
function sea_source(array $s,string $e,array $t):void {
    if(!array_filter($t['allocations'],static fn($a)=>in_array($a['type'],['BROKER_FUND','ASSET_PAYMENT'],true)))return;
    if($t['paymentAccountId']==='CASH|'.$e)return;
    foreach(far_banks($s,$e) as $bank)if($bank['id']===$t['paymentAccountId']&&$bank['currency']===($e==='TG'?'AED':'PKR'))return;
    throw new DomainException('Capital payments require a company bank in the book currency or company cash.');
}
function sea_asset_links(array &$s,array $allocations,array $t,string $jid,array $u):void {
    foreach($allocations as $a)if($a['type']==='ASSET_PAYMENT'){
        $asset=&$s['managedAssets'][$a['assetId']];$id=far_next($asset['payments'],'AP');$cash=str_starts_with($t['paymentAccountId'],'CASH|');$cents=(int)round($a['amount']*100);
        $asset['payments'][$id]=['id'=>$id,'instalmentNo'=>far_text($a['instalmentNo'],60),'date'=>$t['date'],'amountCents'=>$cents,'currency'=>$asset['currency'],'source'=>$t['paymentAccountId'],'reference'=>$a['purpose'],'journalId'=>$jid,'historicalOnly'=>false,'salaryAllocation'=>true,'bankPaymentMethod'=>$t['bankPaymentMethod']??'','chequeNo'=>$t['chequeNo']??'','bankReference'=>$t['bankReference']??'','cashCents'=>$cash?$cents:0,'bankCents'=>$cash?0:$cents,'createdAt'=>gmdate('c'),'createdBy'=>$u['username']??'Accounts'];
        $asset['version']++;$asset['reopened']=false;unset($asset);
    }
}
function sea_reverse_assets(array &$s,string $jid,string $rid,string $reason,array $u):void {
    foreach($s['managedAssets']??[] as $aid=>$asset)foreach($asset['payments'] as $pid=>$p)if(($p['journalId']??'')===$jid&&!empty($p['salaryAllocation'])){
        sfw_company_access($u,'Edit');
        $s['managedAssets'][$aid]['paymentCorrections'][]=['payment'=>$p,'reversalJournalId'=>$rid,'reason'=>$reason,'at'=>gmdate('c'),'by'=>$u['username']??'Accounts'];
        unset($s['managedAssets'][$aid]['payments'][$pid]);$s['managedAssets'][$aid]['version']++;
    }
}

/** All charges against a monthly entitlement, including expense portions borne by a director. */
function sea_charges(array $a):array {
    $out=$a['type']==='SALARY'?[['masterId'=>$a['masterId'],'amount'=>$a['amount'],'internal'=>false]]:[];
    if(($a['remunerationAmount']??0)>.005)$out[]=['masterId'=>$a['remunerationMasterId'],'amount'=>$a['remunerationAmount'],'internal'=>true];
    return $out;
}
function sea_remuneration_balances(array $s,string $e):array {
    $out=[];$asOf=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$hidden=alv_hidden($s,$asOf);
    foreach($s['salaryMasters']??[] as $id=>$m)if(($m['entity']??'')===$e&&($m['category']??'')==='DIRECTOR_REMUNERATION')$out[$id]=['masterId'=>$id,'name'=>$m['name'],'debit'=>0,'credit'=>0,'asOf'=>$asOf];
    foreach($s['journals']??[] as $id=>$j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted'&&($j['date']??'')<=$asOf&&!isset($hidden[$id]))foreach($j['lines']??[] as $l){$mid=$l['salaryMasterId']??$j['meta']['salaryMasterId']??'';if(isset($out[$mid])&&in_array($l['account']??'',['1230','2140'],true)){$out[$mid]['debit']+=(float)$l['debit'];$out[$mid]['credit']+=(float)$l['credit'];}}
    foreach($out as &$v){$v['receivableByDirector']=round(max(0,$v['credit']-$v['debit']),2);$v['recoverableFromDirector']=round(max(0,$v['debit']-$v['credit']),2);}unset($v);return array_values($out);
}
function sea_home_meta(array $s,string $e,array $row):array {
    $person=sac_home_person($row['name']);$sub=sac_resolve($s,$e,sac_home_id($e,$person));return sac_extra($sub)+['expenseFor'=>$person,'expenseArea'=>'HOME','companyHomeExpense'=>true];
}
