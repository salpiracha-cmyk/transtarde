<?php
declare(strict_types=1);

const FAR_CUTOFF = '2026-07-01';
function far_permission(array $u,string $module,string $action):bool {
    if(($u['role']??'')==='Super Admin')return true;
    $p=$u['permissions'][$module]??[];
    if($p==='all')return true;
    if(!is_array($p))return false;
    if($module==='Directors')return in_array($action,(array)($p['assets']??[]),true)||in_array($action,$p,true);
    if(in_array($action,$p,true))return true;
    // Icon-scoped users require Assets rights; unrelated Accounts icons do not grant access.
    return in_array($action,(array)($p['assets']??[]),true);
}
function far_text(mixed $v,int $limit=500):string {
    if(!is_scalar($v)&&$v!==null)throw new DomainException('Enter valid text.');
    $s=trim((string)$v);if(strlen($s)>$limit)throw new DomainException('Entered text is too long.');
    return function_exists('mb_strtoupper')?mb_strtoupper($s,'UTF-8'):strtoupper($s);
}
function far_date(mixed $v,string $label='Date',bool $future=false):string {
    $s=(string)$v;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
    if(!$d||$d->format('Y-m-d')!==$s)throw new DomainException($label.' must be a valid date.');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    if(!$future&&$s>$today)throw new DomainException($label.' cannot be in the future.');
    return $s;
}
function far_cents(mixed $v,bool $zero=false):int {
    if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>100000000000)throw new DomainException('Enter a valid amount.');
    $n=(int)round((float)$v*100);if(!$zero&&$n===0)throw new DomainException('Amount must be greater than zero.');return $n;
}
function far_next(array $rows,string $prefix):string {
    $n=count($rows)+1;do{$id=$prefix.'-'.date('Y').'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);$n++;}while(isset($rows[$id]));return $id;
}
function far_locations(array $s):array {
    return $s['assetLocations']??['countries'=>['PK'=>['id'=>'PK','name'=>'PAKISTAN','active'=>true],'AE'=>['id'=>'AE','name'=>'UNITED ARAB EMIRATES','active'=>true]],'cities'=>['KHI'=>['id'=>'KHI','countryId'=>'PK','name'=>'KARACHI','active'=>true],'LHE'=>['id'=>'LHE','countryId'=>'PK','name'=>'LAHORE','active'=>true],'DXB'=>['id'=>'DXB','countryId'=>'AE','name'=>'DUBAI','active'=>true],'AUH'=>['id'=>'AUH','countryId'=>'AE','name'=>'ABU DHABI','active'=>true],'SHJ'=>['id'=>'SHJ','countryId'=>'AE','name'=>'SHARJAH','active'=>true]]];
}
function far_totals(array $a):array {
    $paid=0;foreach($a['payments'] as $p)$paid+=(int)$p['amountCents'];
    return ['paidCents'=>$paid,'balanceCents'=>max(0,$a['costCents']-$paid),'fullyPaid'=>$paid===$a['costCents']];
}
function far_schedule(array $a):array {
    $rows=[];$paid=max(0,far_totals($a)['paidCents']-(int)($a['schedulePaidBaselineCents']??0));
    foreach($a['schedule'] as $r){$applied=min($paid,$r['amountCents']);$paid-=$applied;$rows[]=$r+['paidCents'=>$applied,'balanceCents'=>$r['amountCents']-$applied];}return $rows;
}
function far_visible(array $a,bool $director):bool {return $director||!far_totals($a)['fullyPaid']||!empty($a['reopened']);}
function far_view(array $a,bool $director):array {
    $out=$a+far_totals($a);$out['schedule']=far_schedule($a);
    if(!$director){unset($out['privateNotes'],$out['documentReferences'],$out['reopenReason']);}
    return $out;
}
function far_payload(array $s,string $e,bool $director):array {
    $rows=[];foreach((array)($s['managedAssets']??[]) as $a)if($a['entity']===$e&&far_visible($a,$director))$rows[]=far_view($a,$director);
    usort($rows,static fn($a,$b)=>strcmp($b['id'],$a['id']));
    // Accounts receipts deliberately contain no property name, amount, location or ownership data.
    $posts=array_values(array_filter((array)($s['assetRegistrationPosts']??[]),static fn($p)=>$p['entity']===$e));
    usort($posts,static fn($a,$b)=>strcmp($b['id'],$a['id']));
    return ['ok'=>true,'assets'=>$rows,'posts'=>$posts,'locations'=>far_locations($s),'cutoff'=>FAR_CUTOFF,'currency'=>$e==='TG'?'AED':'PKR'];
}
function far_names():array {
    $m=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true,512,JSON_THROW_ON_ERROR);$names=[];
    foreach((array)$m['chart'] as $r)$names[(string)$r['code']]=$r['name'];return $names;
}
function far_line(string $account,int $dr,int $cr,array $extra=[]):array {
    $names=far_names();if(!isset($names[$account]))throw new RuntimeException('Asset account is not configured.');
    return ['account'=>$account,'accountName'=>$names[$account],'debit'=>$dr/100,'credit'=>$cr/100]+$extra;
}
function far_journal(array &$s,string $e,string $date,string $source,array $lines,array $u,array $tracking=[]):string {
    $id=far_next((array)($s['journals']??[]),'AUTO');$dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);
    if(abs($dr-$cr)>.005)throw new RuntimeException('Asset journal did not balance.');
    // Details stay in the restricted registry, never in searchable journal narration or metadata.
    $s['journals'][$id]=['id'=>$id,'entity'=>$e,'date'=>$date,'sourceType'=>$source,'reference'=>$id,'narration'=>$source==='ASSET_PURCHASE'?'ASSET PURCHASE POSTED':'ASSET PAYMENT POSTED','lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>$tracking,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts'),'userId'=>(int)($u['id']??0)];return $id;
}
function far_banks(array $s,string $e):array {
    $out=[];foreach((array)(tt_list_masters()['banks']??[]) as $r){$v=array_values((array)($r['values']??[]));$link=strtoupper((string)($v[1]??''));
        $owner=preg_match('/(^|\W)TG($|\W)/',$link)||str_contains($link,'TRANS GRAINS')?'TG':(str_contains($link,'BUKSH')||preg_match('/(^|\W)BRM($|\W)/',$link)?'BRM':(str_contains($link,'TRANSTRADE INTERNATIONAL')||preg_match('/(^|\W)TTI($|\W)/',$link)?'TTI':''));
        $id=(string)($r['id']??'');if($owner!==$e||($v[0]??'')!=='Company Account'||!tt_bank_can_transact($id))continue;
        $set=$s['bankAccountSettings'][$id]??[];$out[]=['id'=>$id,'label'=>trim((string)($v[4]??'')).' · '.trim((string)($v[3]??'')).' · '.(string)($v[7]??''),'currency'=>strtoupper((string)($v[7]??'')),'isDefault'=>!empty($set['defaultPaymentAccount'])?2:(!empty($set['defaultReceiptAccount'])?1:(!empty($v[11])&&in_array(strtolower((string)$v[11]),['yes','true','1','default'],true)?1:0))];
    }usort($out,static fn($a,$b)=>$b['isDefault']<=>$a['isDefault']);return $out;
}
function far_payment(array &$s,array &$a,array $b,array $u):array {
    $date=far_date($b['date']??'','Payment date');if($date<$a['purchaseDate'])throw new DomainException('Payment date cannot be before the purchase/agreement date.');
    $amount=far_cents($b['amount']??null);if($amount>far_totals($a)['balanceCents'])throw new DomainException('Payment exceeds the remaining asset balance.');
    $number=far_text($b['instalmentNo']??'',60);if($number==='')throw new DomainException('Enter the instalment number or payment label.');
    foreach($a['payments'] as $old)if($old['instalmentNo']===$number)throw new DomainException('This instalment number is already recorded. Use a separate label for a part payment.');
    $source=far_text($b['source']??'',160);$ref=far_text($b['reference']??'',180);$jid='';$historical=$date<FAR_CUTOFF;
    if(!$historical){
        $mode=(string)($b['mode']??'POST');
        if($mode==='EXISTING_POST'){
            $jid=trim((string)($b['existingPostId']??''));$j=$s['journals'][$jid]??null;
            if(!is_array($j)||($j['status']??'')!=='Posted'||$j['entity']!==$a['entity']||$j['date']!==$date)throw new DomainException('Select the original posted payment in these company books on the same date.');
            $movement=0;foreach((array)$j['lines'] as $l)if(in_array((string)$l['account'],['1110','1120'],true))$movement+=round(((float)$l['credit']-(float)$l['debit'])*100);
            if($movement!==$amount)throw new DomainException('The original Post ID cash/bank payment must match this instalment amount.');
            foreach((array)($s['managedAssets']??[]) as $other)foreach($other['payments'] as $p)if(($p['journalId']??'')===$jid)throw new DomainException('This Post ID is already linked to an asset payment.');
            foreach($a['payments'] as $p)if(($p['journalId']??'')===$jid)throw new DomainException('This Post ID is already linked to an asset payment.');
        }elseif($mode==='POST'){
            $base=$a['entity']==='TG'?'AED':'PKR';if($a['currency']!==$base)throw new DomainException('Use '.$base.' for payments in these company books. Record foreign historical amounts separately or use the correct company books.');
            $cash=far_cents($b['cashAmount']??0,true);$bank=far_cents($b['bankAmount']??0,true);if($cash+$bank!==$amount)throw new DomainException('Cash and bank amounts must equal the payment amount.');
            $tracking=[];$lines=[far_line($a['ownerType']==='PERSONAL'?'3200':'2140',$amount,0)];
            if($cash>0)$lines[]=far_line('1120',0,$cash,['paymentAccountId'=>'CASH|'.$a['entity']]);
            if($bank>0){$id=(string)($b['paymentAccountId']??'');$found=null;foreach(far_banks($s,$a['entity']) as $x)if($x['id']===$id)$found=$x;
                if(!$found||$found['currency']!==$base)throw new DomainException('Choose an active '.$base.' company bank account.');
                $tracking=tt_accounts_bank_payment_details($b+['date'=>$date]);
                // Free text references remain private. Cheque number is retained for bank reconciliation.
                $publicTracking=$tracking;unset($publicTracking['paymentNarration'],$publicTracking['bankReference']);
                $lines[]=far_line('1110',0,$bank,['bankAccountId'=>$id,'currency'=>$base,'bankDebit'=>0,'bankCredit'=>$bank]+$publicTracking);
            }
            $publicTracking=$tracking;unset($publicTracking['paymentNarration'],$publicTracking['bankReference']);
            $jid=far_journal($s,$a['entity'],$date,'ASSET_PAYMENT',$lines,$u,$publicTracking);
        }else throw new DomainException('Choose Post payment or Link existing Post ID.');
    }
    $id=far_next($a['payments'],'AP');$p=['id'=>$id,'instalmentNo'=>$number,'date'=>$date,'amountCents'=>$amount,'currency'=>$a['currency'],'source'=>$source,'reference'=>$ref,'journalId'=>$jid,'historicalOnly'=>$historical,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts')];
    if(!$historical){$p['bankPaymentMethod']=(string)($b['bankPaymentMethod']??'');$p['chequeNo']=far_text($b['chequeNo']??'',180);$p['bankReference']=far_text($b['bankReference']??'',180);$p['cashCents']=far_cents($b['cashAmount']??0,true);$p['bankCents']=far_cents($b['bankAmount']??0,true);}
    $a['payments'][$id]=$p;$a['version']++;$a['reopened']=false;return $p;
}
function far_register(array &$s,string $e,array $b,array $u):array {
    $type=(string)($b['type']??'PROPERTY');if(!in_array($type,['PROPERTY','VEHICLE','MOTORCYCLE','MACHINERY','FURNITURE','COMPUTER'],true))throw new DomainException('Choose a valid asset type.');
    $name=far_text($b['name']??'',180);$tag=far_text($b['assetTag']??'',100);$owner=far_text($b['ownerName']??'',180);$ownerType=(string)($b['ownerType']??'');
    if($name===''||$tag===''||$owner===''||!in_array($ownerType,['COMPANY','PERSONAL'],true))throw new DomainException('Enter asset name, unique reference and legal owner.');
    foreach((array)($s['managedAssets']??[]) as $old)if($old['entity']===$e&&$old['assetTag']===$tag)throw new DomainException('This asset reference is already registered.');
    $loc=far_locations($s);$country=$loc['countries'][(string)($b['countryId']??'')]??null;$city=$loc['cities'][(string)($b['cityId']??'')]??null;
    if(!$country||!$city||!$country['active']||!$city['active']||$city['countryId']!==$country['id'])throw new DomainException('Choose an active country and a city belonging to it.');
    $date=far_date($b['purchaseDate']??'','Purchase/agreement date');$cost=far_cents($b['cost']??null);$currency=(string)($b['currency']??'');if(!in_array($currency,['PKR','AED','USD'],true))throw new DomainException('Choose PKR, AED or USD.');
    $address=far_text($b['address']??'',700);$unit=far_text($b['unitNo']??'',100);if($type==='PROPERTY'&&($address===''||$unit===''))throw new DomainException('Enter the property address/project and plot/unit number.');
    $purpose=(string)($b['purpose']??'');if(!in_array($purpose,['PERSONAL_USE','BUSINESS_USE','RENTAL','RESALE','OTHER'],true))throw new DomainException('Choose the asset purpose.');
    $propertyType=(string)($b['propertyType']??'OTHER');if(!in_array($propertyType,['PLOT','LAND','HOUSE','APARTMENT','COMMERCIAL','AGRICULTURAL','OTHER'],true))throw new DomainException('Choose a valid property type.');
    $tenure=(string)($b['tenure']??'FREEHOLD');if(!in_array($tenure,['FREEHOLD','LEASEHOLD','OTHER'],true))throw new DomainException('Choose a valid ownership tenure.');
    $treatment=(string)($b['accountingMode']??'');if(!in_array($treatment,['REGISTER_ONLY','ALREADY_IN_BOOKS','NEW_PURCHASE'],true))throw new DomainException('Choose registration only, already in opening books or a new purchase.');
    if($ownerType==='PERSONAL'&&$treatment!=='REGISTER_ONLY')throw new DomainException('A personally owned asset must use Registration only; company-funded payments are personal allocations.');
    if($ownerType==='COMPANY'&&$treatment==='REGISTER_ONLY')throw new DomainException('For a company asset, choose Already in books or New purchase.');
    if($treatment==='ALREADY_IN_BOOKS'&&empty($b['openingBooksConfirmed']))throw new DomainException('Confirm the asset and remaining payable are already included in the company opening books.');
    if($treatment==='NEW_PURCHASE'&&($date<FAR_CUTOFF||$currency!==($e==='TG'?'AED':'PKR')))throw new DomainException('New purchase posting must be from 1 July 2026 and in the company book currency.');
    $id=far_next((array)($s['managedAssets']??[]),'AR');
    $a=['id'=>$id,'entity'=>$e,'version'=>1,'type'=>$type,'name'=>$name,'assetTag'=>$tag,'ownerType'=>$ownerType,'ownerName'=>$owner,'countryId'=>$country['id'],'country'=>$country['name'],'cityId'=>$city['id'],'city'=>$city['name'],'address'=>$address,'unitNo'=>$unit,'tenure'=>$tenure,'purpose'=>$purpose,'purchaseDate'=>$date,'costCents'=>$cost,'currency'=>$currency,'seller'=>far_text($b['seller']??'',180),'registrationNo'=>far_text($b['registrationNo']??'',100),'chassisNo'=>far_text($b['chassisNo']??'',100),'engineNo'=>far_text($b['engineNo']??'',100),'privateNotes'=>far_text($b['privateNotes']??'',2000),'documentReferences'=>far_text($b['documentReferences']??'',2000),'accountingMode'=>$treatment,'payments'=>[],'schedule'=>[],'reopened'=>false,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts')];
    $a['propertyType']=$propertyType;$a['area']=far_text($b['area']??'',100);$a['sellerAccountRef']=far_text($b['sellerAccountRef']??'',180);$a['paymentInstructions']=far_text($b['paymentInstructions']??'',900);
    if($treatment==='NEW_PURCHASE'){
        $acct=match($type){'PROPERTY'=>'1550','VEHICLE','MOTORCYCLE'=>'1520','MACHINERY'=>'1510','FURNITURE'=>'1530','COMPUTER'=>'1540'};
        $a['purchaseJournalId']=far_journal($s,$e,$date,'ASSET_PURCHASE',[far_line($acct,$cost,0),far_line('2140',0,$cost)],$u);
    }
    $history=$b['payments']??[];if(!is_array($history)||count($history)>300)throw new DomainException('A maximum of 300 payment history rows is allowed.');
    foreach($history as $payment){if(!is_array($payment))throw new DomainException('Invalid payment history row.');far_payment($s,$a,$payment,$u);}
    $pattern=(string)($b['paymentPattern']??'FLEXIBLE');if(!in_array($pattern,['FLEXIBLE','MONTHLY','FULLY_PAID'],true))throw new DomainException('Choose flexible, monthly or fully paid.');
    $a['paymentPattern']=$pattern;$balance=far_totals($a)['balanceCents'];
    if($pattern==='FULLY_PAID'&&$balance!==0)throw new DomainException('Fully paid assets require payment history equal to the purchase cost.');
    if($pattern==='MONTHLY'&&$balance>0){$start=far_date($b['firstDueDate']??'','First remaining instalment date',true);$monthly=far_cents($b['monthlyAmount']??null);$count=(int)($b['instalmentCount']??0);if($count<1||$count>600||$monthly*$count<$balance)throw new DomainException('Enter enough monthly instalments to cover the remaining balance (maximum 600).');
        $day=(int)substr($start,8);$month=new DateTimeImmutable(substr($start,0,7).'-01');$left=$balance;
        for($i=1;$i<=$count&&$left>0;$i++){$due=$month->format('Y-m-').str_pad((string)min($day,(int)$month->format('t')),2,'0',STR_PAD_LEFT);$amount=min($monthly,$left);$a['schedule'][]=['number'=>count($a['payments'])+$i,'dueDate'=>$due,'amountCents'=>$amount];$left-=$amount;$month=$month->modify('+1 month');}
        // The schedule covers the remaining balance, with prior history accounted for separately.
        $a['schedulePaidBaselineCents']=far_totals($a)['paidCents'];
    }
    $s['managedAssets'][$id]=$a;$s['assetRegistrationPosts'][$id]=['id'=>$id,'entity'=>$e,'message'=>$type==='PROPERTY'?'PROPERTY RECORD POSTED':'ASSET RECORD POSTED','date'=>gmdate('Y-m-d'),'createdBy'=>$a['createdBy']];return ['postId'=>$id,'message'=>$s['assetRegistrationPosts'][$id]['message']];
}
function far_location_action(array &$s,array $b):array {
    $loc=far_locations($s);$kind=(string)($b['kind']??'');if(!in_array($kind,['countries','cities'],true))throw new DomainException('Choose country or city.');
    if(($b['operation']??'')==='add'){$name=far_text($b['name']??'',100);if($name==='')throw new DomainException('Enter a name.');$parent=(string)($b['countryId']??'');if($kind==='cities'&&empty($loc['countries'][$parent]['active']))throw new DomainException('Choose an active country.');
        foreach($loc[$kind] as $id=>$r)if($r['name']===$name&&($kind==='countries'||$r['countryId']===$parent)){$loc[$kind][$id]['active']=true;$s['assetLocations']=$loc;return ['id'=>$id];}
        $id=far_next($loc[$kind],$kind==='countries'?'COUNTRY':'CITY');$loc[$kind][$id]=['id'=>$id,'name'=>$name,'active'=>true]+($kind==='cities'?['countryId'=>$parent]:[]);
    }elseif(($b['operation']??'')==='delete'){$id=(string)($b['id']??'');if(!isset($loc[$kind][$id]))throw new DomainException('Location was not found.');$loc[$kind][$id]['active']=false;if($kind==='countries')foreach($loc['cities'] as &$city)if($city['countryId']===$id)$city['active']=false;unset($city);
    }else throw new DomainException('Choose Add or Delete.');$s['assetLocations']=$loc;return ['id'=>$id];
}
