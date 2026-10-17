<?php
declare(strict_types=1);
require_once __DIR__.'/mill_recovery_core.php';
require_once __DIR__.'/accounts_subaccounts_core.php';

/** Management calculations. All amounts are explicit; no financial posting side effects. */
function gm_number(mixed $value,string $label,bool $signed=false):float {
    if(!is_numeric($value)||!is_finite((float)$value)||abs((float)$value)>1e14||(!$signed&&(float)$value<0))throw new DomainException('Enter a valid '.$label.'.');
    return (float)$value;
}
function gm_text(mixed $value,int $limit=500):string {
    if(!is_scalar($value)&&$value!==null)throw new DomainException('Enter valid text.');
    $text=trim((string)$value);if(strlen($text)>$limit)throw new DomainException('Text is too long.');return $text;
}
function gm_date(mixed $value):string {$date=gm_text($value,10);if(!mr_valid_date($date))throw new DomainException('Enter a valid report date.');return $date;}
function gm_fx(array $rates,string $currency):float {
    if($currency==='PKR')return 1.;$rate=gm_number($rates[$currency]??null,$currency.' to PKR rate');if($rate<=0)throw new DomainException('Enter the '.$currency.' to PKR rate.');return $rate;
}
function gm_property_value(array $row):float {
    $value=gm_number($row['marketValue']??null,'property market value');
    return match($row['valuationBasis']??''){
        'FULL_VALUE'=>$value,
        'PREMIUM_PLUS_PAID'=>$value+gm_number($row['instalmentsPaid']??null,'instalments actually paid'),
        default=>throw new DomainException('Select full property value or premium above instalments paid.')
    };
}
function gm_row(array $row):array {
    $id=gm_text($row['id']??'',160);$label=gm_text($row['label']??'',300);if($id===''||$label==='')throw new DomainException('Each row needs a stable ID and description.');
    $type=gm_text($row['type']??'FINANCIAL',30);if(!in_array($type,['FINANCIAL','PROPERTY','GOLD','SHARES','ADJUSTMENT'],true))throw new DomainException('Select a row type.');
    $currency=strtoupper(gm_text($row['currency']??'PKR',3));if(!in_array($currency,['PKR','USD','AED'],true))throw new DomainException('Select PKR, USD or AED.');
    $amount=match($type){
        'PROPERTY'=>gm_property_value($row),
        'GOLD'=>gm_number($row['grams']??null,'gold weight')*gm_number($row['ratePerGram']??null,'gold rate per gram for the selected purity'),
        'SHARES'=>gm_number($row['quantity']??null,'share quantity')*gm_number($row['marketRate']??null,'share market rate'),
        default=>gm_number($row['amount']??null,'row amount',true)
    };
    if($type==='GOLD'&&(gm_text($row['holder']??'')===''||gm_text($row['purity']??'')===''))throw new DomainException('Enter the gold holder and purity; use a market rate for that same purity.');
    $excluded=!empty($row['excluded']);$reason=gm_text($row['reason']??'',1000);if($excluded&&$reason==='')throw new DomainException('Enter a reason for excluding a row.');
    $entity=strtoupper(gm_text($row['entity']??'GROUP',5));if(!in_array($entity,['TTI','BRM','TG','GROUP'],true))throw new DomainException('Select the row company or Group.');
    $out=['id'=>$id,'label'=>$label,'type'=>$type,'entity'=>$entity,'currency'=>$currency,'amount'=>round($amount,2),'excluded'=>$excluded,'reason'=>$reason,'source'=>gm_text($row['source']??'Manual',2000),'basis'=>gm_text($row['basis']??'Manual review',200)];
    foreach(['marketValue','valuationBasis','instalmentsPaid','grams','ratePerGram','holder','purity','quantity','marketRate','sourceDate','sourceFingerprint','account','subaccountId'] as $key)if(isset($row[$key]))$out[$key]=is_numeric($row[$key])?(float)$row[$key]:gm_text($row[$key]);
    if(isset($row['postIds'])){if(!is_array($row['postIds'])||count($row['postIds'])>20000)throw new DomainException('Invalid source Post IDs.');$out['postIds']=array_map(static fn($v)=>gm_text($v,120),$row['postIds']);}
    return $out;
}
function gm_rows(array $rows):array {
    if(!array_is_list($rows)||count($rows)>3000)throw new DomainException('Invalid report rows.');$out=[];$seen=[];
    foreach($rows as $r){if(!is_array($r))throw new DomainException('Invalid report row.');$r=gm_row($r);if(isset($seen[$r['id']]))throw new DomainException('Duplicate source row.');$seen[$r['id']]=true;$out[]=$r;}return $out;
}
function gm_total(array $rows,array $rates):array {
    $total=0.;$included=[];$excluded=[];
    foreach($rows as $row){$row=gm_row($row);if($row['excluded']){$excluded[]=$row;continue;}$row['amountPKR']=round($row['amount']*gm_fx($rates,$row['currency']),2);$total+=$row['amountPKR'];$included[]=$row;}
    return ['totalPKR'=>round($total,2),'rows'=>$included,'excluded'=>$excluded];
}
function gm_zakat(array $sections,array $rates,float $openingCarry,float $paid):array {
    $net=0.;$parts=[];$ids=[];
    foreach($sections as $key=>$section){
        $rows=(array)($section['rows']??[]);foreach($rows as $r){if(isset($ids[$r['id']]))throw new DomainException('A row is counted in more than one assessment section.');$ids[$r['id']]=true;}
        $parts[$key]=gm_total($rows,$rates);$net+=$parts[$key]['totalPKR'];
    }
    // Negative financial sections reduce the combined assessment, never clamp each section separately.
    $assessment=round(max(0,$net)*.025,2);$balance=round($openingCarry+$assessment-$paid,2);
    return ['entity'=>'TTI','netWealthPKR'=>round($net,2),'assessmentPKR'=>$assessment,'openingCarryPKR'=>$openingCarry,'paidPKR'=>$paid,'balancePKR'=>$balance,'outstandingPKR'=>max(0,$balance),'advanceCreditPKR'=>max(0,-$balance),'sections'=>$parts];
}
function gm_raw_position(array $input):array {
    $raw=gm_number($input['rawOnHandMT']??null,'raw stock');$purchase=gm_number($input['undeliveredPurchaseMT']??null,'undelivered purchase quantity');$sales=gm_number($input['outstandingSalesMT']??null,'outstanding sales quantity');$finished=gm_number($input['usableFinishedMT']??0,'usable finished stock');
    $recovery=gm_number($input['recoveryPct']??null,'recovery percentage');if($recovery<=0||$recovery>100)throw new DomainException('Recovery must be greater than zero and at most 100%.');
    $rawRequired=max(0,$sales-$finished)/($recovery/100);$balance=$raw+$purchase-$rawRequired;
    return ['rawRequiredMT'=>round($rawRequired,6),'positionMT'=>round($balance,6),'status'=>$balance<-.000001?'SHORT':($balance>.000001?'LONG':'BALANCED')];
}
function gm_costing(array $input):array {
    $commodity=strtoupper(gm_text($input['commodity']??'RICE'));$corn=in_array($commodity,['CORN','MAIZE','MAKAI'],true);
    $fx=gm_number($input['fx']??null,'PKR exchange rate');if($fx<=0)throw new DomainException('Exchange rate must be positive.');
    $sale=gm_number($input['saleUSDPerMT']??null,'sale price');$freight=gm_number($input['freightUSDPerMT']??0,'freight');$otherUSD=gm_number($input['otherUSDPerMT']??0,'foreign expenses');
    $purchase=gm_number($input['purchaseRate']??null,'purchase rate');$unit=(string)($input['purchaseUnit']??'KG');$factor=match($unit){'KG'=>1000.,'MAUND'=>25.,'MT'=>1.,default=>throw new DomainException('Select purchase unit.')};
    $conversion=gm_number($input['conversionPKRPerRawMT']??0,'conversion cost');if($corn&&$conversion>0)throw new DomainException('Corn has no milling conversion step. Record direct loading costs under shipment expenses.');
    $shipment=gm_number($input['shipmentPKRPerMT']??0,'shipment expenses');$margin=gm_number($input['targetMarginPKRPerMT']??0,'target margin');
    $outputs=(array)($input['outputs']??[]);$sum=0.;$ready=0.;$credit=0.;$used=[];
    foreach($outputs as $row){$name=strtoupper(gm_text($row['product']??''));if($name===''||isset($used[$name]))throw new DomainException('Each output product must be unique.');$used[$name]=true;$pct=gm_number($row['recoveryPct']??null,'output recovery');$sum+=$pct;$kind=(string)($row['kind']??'byproduct');
        if($kind==='finished')$ready+=$pct/100;
        elseif($kind==='waste'){if((float)($row['ratePKRPerKg']??0)!==0.)throw new DomainException('Unrecoverable waste cannot receive a sale credit.');}
        elseif($kind==='byproduct')$credit+=10*$pct*gm_number($row['ratePKRPerKg']??null,'by-product rate');
        else throw new DomainException('Select finished, by-product or waste output.');
    }
    if(abs($sum-100)>.00001||$ready<=0)throw new DomainException('Finished output, by-products and waste must total 100% with positive finished recovery.');
    if($corn&&array_filter($outputs,static fn($r)=>($r['kind']??'')==='byproduct'&&(float)($r['recoveryPct']??0)>0))throw new DomainException('Direct-loading Corn has no milling by-products.');
    $grossPKR=$sale*$fx;$wht=$grossPKR*.0125;$bank=$grossPKR*.001;$net=$grossPKR-$wht-$bank;
    $shipmentTotal=$shipment+($freight+$otherUSD)*$fx;$rawCost=$purchase*$factor;
    $readyCost=($rawCost+$conversion-$credit)/$ready+$shipmentTotal;$maxRaw=($net-$shipmentTotal-$margin)*$ready+$credit-$conversion;
    return ['purchasePKRPerRawMT'=>round($rawCost,4),'readyRecoveryPct'=>round($ready*100,6),'byproductCreditPKRPerRawMT'=>round($credit,4),'grossSalesPKRPerMT'=>round($grossPKR,4),'whtPKRPerMT'=>round($wht,4),'bankDeductionPKRPerMT'=>round($bank,4),'effectiveFX'=>$fx*.9865,'netSalesPKRPerMT'=>round($net,4),'costPKRPerReadyMT'=>round($readyCost,4),'profitPKRPerMT'=>round($net-$readyCost,4),'watakPurchaseRate'=>round($maxRaw/$factor,6),'watakPurchaseUnit'=>$unit];
}
function gm_tg_profit(array $receipts,float $costs):array {
    $bank=0.;$margin=0.;$working=[];
    foreach($receipts as $row){$fx=gm_number($row['adviceFX']??null,'credit-advice exchange rate');if($fx<=0)throw new DomainException('Each settled slice requires its original credit-advice rate.');$qty=gm_number($row['matchedMT']??null,'matched tonnes');$tg=gm_number($row['tgUSDPerMT']??null,'TG rate');$pk=gm_number($row['pakistanUSDPerMT']??null,'Pakistan rate');$cash=gm_number($row['bankCreditPKR']??null,'actual bank credit');$extra=($tg-$pk)*$qty*$fx;$bank+=$cash;$margin+=$extra;$working[]=['postId'=>gm_text($row['postId']??''),'matchedMT'=>$qty,'differenceUSDPerMT'=>$tg-$pk,'adviceFX'=>$fx,'internalMarginPKR'=>round($extra,2)];}
    return ['actualBankPKR'=>round($bank,2),'internalMarginPKR'=>round($margin,2),'managementRealizationPKR'=>round($bank+$margin,2),'shipmentCostsPKR'=>$costs,'profitPKR'=>round($bank+$margin-$costs,2),'working'=>$working];
}

function gm_permission(array $user,string $kind,string $action='View',string $origin='Accounts'):bool {
    if(($user['role']??'')==='Super Admin')return true;
    if(!in_array($origin,['Accounts','Directors'],true))return false;
    $icon=$kind==='ZAKAT'?'zakat':($kind==='COSTING'?'costing':'group-reports');
    if(!tt_user_can_module_action($user,$origin,$icon,$action))return false;
    // Directors group permissions are explicit. Accounts also retains every company restriction.
    foreach(['TTI','BRM','TG'] as $entity)if(!tt_user_can_access_entity($user,$entity,'View'))return false;
    return true;
}
function gm_financial_source(array $state,string $date):array {
    $chart=sac_chart();$names=[];$classes=[];
    foreach($chart as $r){$names[(string)$r['code']]=$r['name'];$classes[(string)$r['code']]=$r['class'];}
    $rows=[];$unclassified=[];$intercompany=[];
    foreach((array)($state['journals']??[]) as $postId=>$journal){
        if(!is_array($journal)||!in_array(($journal['status']??''),['Posted','Posted / Bill Pending'],true))continue;
        $entity=(string)($journal['entity']??'');if(!in_array($entity,['TTI','BRM','TG'],true))continue;
        $on=(string)($journal['date']??'');if(!mr_valid_date($on)){ $unclassified[]='Undated Post '.(string)($journal['id']??$postId);continue;}if($on>$date)continue;
        foreach((array)($journal['lines']??[]) as $index=>$line){
            $code=(string)($line['account']??'');$class=$classes[$code]??'';$name=(string)($line['accountName']??$names[$code]??$code);
            if(!in_array($class,['Asset','Liability'],true)){if($class==='')$unclassified[]='Unclassified '.$entity.' account '.$code;continue;}
            if(preg_match('/^(15|16)/',$code))continue; // Fixed assets and shares have separate market-valuation sections.
            $party=(string)($line['counterparty']??$line['party']??$line['partyName']??$line['supplier']??$line['broker']??$line['customer']??'');$bank=(string)($line['bankAccountId']??$line['paymentAccountId']??($code==='1110'?($journal['meta']['bankAccountId']??$journal['meta']['paymentAccountId']??''):''));
            $subaccount=(string)($line['subaccountId']??'');$identity=$entity.'|'.$code.'|'.$bank.'|'.$party.'|'.$subaccount;$id='BOOK-'.substr(hash('sha256',$identity),0,24);
            if(!isset($rows[$id]))$rows[$id]=['id'=>$id,'type'=>'FINANCIAL','entity'=>$entity,'label'=>trim($name.($party!==''?' — '.$party:'').($bank!==''?' — '.$bank:'')),'account'=>$code,'subaccountId'=>$subaccount,'currency'=>$entity==='TG'?'AED':'PKR','amount'=>0.,'source'=>'Accounts journals','postIds'=>[],'basis'=>str_starts_with($code,'13')?'Book inventory value — market valuation review required':'Posted book balance','excluded'=>false,'reason'=>''];
            $rows[$id]['amount']+=gm_number($line['debit']??0,'journal debit')-gm_number($line['credit']??0,'journal credit');$rows[$id]['postIds'][(string)($journal['id']??$postId)]=true;
            if(in_array($code,['1240','2500'],true)){$rows[$id]['excluded']=true;$rows[$id]['reason']='Group intercompany elimination; reconcile counterpart balances and exchange differences.';$intercompany[$id]=true;}
        }
    }
    if($intercompany)$unclassified[]='Intercompany balances are excluded provisionally. Reconcile both sides and exchange differences before completing the group report.';
    $out=[];foreach($rows as $row){$row['amount']=round($row['amount'],2);if(abs($row['amount'])<.005)continue;$row['postIds']=array_keys($row['postIds']);$row['sourceFingerprint']=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));$out[]=$row;}
    usort($out,static fn($a,$b)=>(str_contains(strtolower($a['label']),'sales tax')||str_contains(strtolower($a['label']),'gst'))<=>(str_contains(strtolower($b['label']),'sales tax')||str_contains(strtolower($b['label']),'gst'))?:strcmp($a['entity'].'|'.$a['account'].'|'.$a['label'],$b['entity'].'|'.$b['account'].'|'.$b['label']));
    return ['rows'=>$out,'warnings'=>array_values(array_unique($unclassified)),'accountsRevision'=>(int)($state['revision']??0),'asOf'=>$date,'basis'=>'Committed journals through the selected date. Excludes fixed assets and share holdings. Inventory is book cost until reviewed at market value. Unposted operational liabilities and shipment estimates require separate review.'];
}
function gm_zakat_payments(array $state,string $from,string $to):array {
    $rows=[];$total=0.;
    foreach((array)($state['journals']??[]) as $id=>$j){if(!is_array($j)||($j['entity']??'')!=='TTI'||($j['status']??'')!=='Posted')continue;$date=(string)($j['date']??'');if(!mr_valid_date($date)||$date<$from||$date>$to)continue;
        $amount=0.;foreach((array)($j['lines']??[]) as $line)if((string)($line['account']??'')==='7210')$amount+=(float)($line['debit']??0)-(float)($line['credit']??0);
        if(abs($amount)<.005)continue;$cash=0.;foreach((array)($j['lines']??[]) as $line)if(in_array((string)($line['account']??''),['1110','1120'],true))$cash+=(float)($line['credit']??0)-(float)($line['debit']??0);if(($amount>0&&$cash+.005<$amount)||($amount<0&&$cash-.005>$amount))continue;$amount=round($amount,2);$total+=$amount;$rows[]=['postId'=>(string)($j['id']??$id),'date'=>$date,'amountPKR'=>$amount,'reference'=>(string)($j['reference']??''),'reversalOf'=>$j['reversalOf']??null];
    }
    usort($rows,static fn($a,$b)=>strcmp($a['date'].'|'.$a['postId'],$b['date'].'|'.$b['postId']));return ['totalPKR'=>round($total,2),'rows'=>$rows];
}
function gm_assessment_summary(array $record,array $state,string $through,?float $opening=null):array {
    $rates=(array)($record['sections']['header']['data']['rates']??[]);$sections=array_intersect_key((array)($record['sections']??[]),array_flip(['financials','properties','gold','shares','adjustments']));$posted=[];
    foreach($sections as $key=>$section)if(!empty($section['complete']))$posted[$key]=$section;
    $payments=gm_zakat_payments($state,$record['date'],$through);$carry=$opening??gm_number($record['sections']['header']['data']['openingCarryPKR']??0,'opening Zakat carry-forward',true);
    return gm_zakat($posted,$rates,$carry,$payments['totalPKR'])+['payments'=>$payments['rows'],'through'=>$through,'assessmentOnly'=>true,'expenseBasis'=>'Only actual TTI Zakat payment postings in account 7210. Assessment section completion does not post another expense.'];
}
function gm_record_summary(array $record,array $state,string $through):array {
    if($record['kind']==='ZAKAT'){
        $next=array_values(array_filter((array)($state['groupManagementRecords']??[]),static fn($r)=>($r['kind']??'')==='ZAKAT'&&($r['date']??'')>$record['date']));usort($next,static fn($a,$b)=>strcmp($a['date'],$b['date']));if($next)$through=min($through,(new DateTimeImmutable($next[0]['date']))->modify('-1 day')->format('Y-m-d'));
        $prior=array_values(array_filter((array)($state['groupManagementRecords']??[]),static fn($r)=>($r['kind']??'')==='ZAKAT'&&($r['date']??'')<$record['date']));usort($prior,static fn($a,$b)=>strcmp($a['date'],$b['date']));$opening=null;
        foreach($prior as $r){$end=(new DateTimeImmutable($record['date']))->modify('-1 day')->format('Y-m-d');$next=array_values(array_filter($prior,static fn($x)=>$x['date']>$r['date']));if($next)$end=(new DateTimeImmutable($next[0]['date']))->modify('-1 day')->format('Y-m-d');$opening=gm_assessment_summary($r,$state,$end,$opening)['balancePKR'];}
        return gm_assessment_summary($record,$state,$through,$opening);
    }
    $rates=(array)($record['sections']['header']['data']['rates']??[]);$rows=[];foreach((array)($record['sections']??[]) as $key=>$section)if($key!=='header')$rows=array_merge($rows,(array)($section['rows']??[]));return gm_total($rows,$rates);
}
