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
    $amount=gm_number($amount,'calculated row amount',true);
    $excluded=!empty($row['excluded']);$reason=gm_text($row['reason']??'',1000);if($excluded&&$reason==='')throw new DomainException('Enter a reason for excluding a row.');
    $entity=strtoupper(gm_text($row['entity']??'GROUP',5));if(!in_array($entity,['TTI','BRM','TG','GROUP'],true))throw new DomainException('Select the row company or Group.');
    $out=['id'=>$id,'label'=>$label,'type'=>$type,'entity'=>$entity,'currency'=>$currency,'amount'=>round($amount,2),'excluded'=>$excluded,'reason'=>$reason,'source'=>gm_text($row['source']??'Manual',2000),'basis'=>gm_text($row['basis']??'Manual review',200)];
    foreach(['marketValue','instalmentsPaid','grams','ratePerGram','quantity','marketRate','originalAmount'] as $key)if(isset($row[$key]))$out[$key]=gm_number($row[$key],$key,true);
    foreach(['valuationBasis','holder','purity','sourceDate','sourceFingerprint','account','subaccountId','originalCurrency'] as $key)if(isset($row[$key]))$out[$key]=gm_text($row[$key]);
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
    $commodity=strtoupper(gm_text($input['commodity']??'RICE'));if(!in_array($commodity,['RICE','CORN','MAIZE','MAKAI'],true))throw new DomainException('Select Rice or Corn.');$corn=in_array($commodity,['CORN','MAIZE','MAKAI'],true);
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
    $freightProceeds=gm_number($input['freightRealizationUSDPerMT']??0,'separate freight realization');$grossPKR=($sale+$freightProceeds)*$fx;$wht=$grossPKR*.0125;$bank=$grossPKR*.001;$net=$grossPKR-$wht-$bank;
    $shipmentTotal=$shipment+($freight+$otherUSD)*$fx;$rawCost=$purchase*$factor;
    $readyCost=($rawCost+$conversion-$credit)/$ready+$shipmentTotal;$maxRaw=($net-$shipmentTotal-$margin)*$ready+$credit-$conversion;
    return ['purchasePKRPerRawMT'=>round($rawCost,4),'readyRecoveryPct'=>round($ready*100,6),'byproductCreditPKRPerRawMT'=>round($credit,4),'grossSalesPKRPerMT'=>round($grossPKR,4),'whtPKRPerMT'=>round($wht,4),'bankDeductionPKRPerMT'=>round($bank,4),'effectiveFX'=>$fx*.9865,'netSalesPKRPerMT'=>round($net,4),'productionCostPKRPerReadyMT'=>round(($rawCost+$conversion-$credit)/$ready,4),'freightRealizationUSDPerMT'=>$freightProceeds,'costPKRPerReadyMT'=>round($readyCost,4),'profitPKRPerMT'=>round($net-$readyCost,4),'watakPurchaseRate'=>round($maxRaw/$factor,6),'watakPurchaseUnit'=>$unit];
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
/** Pending TG bank confirmations explain a timing hold, never another settled payment. */
function gm_pending_tg_remittances(array $state,string $date):array {
    $rows=[];$totals=[];$warnings=[];$seen=[];
    foreach((array)($state['tgRemittanceDrafts']??[]) as $key=>$draft){
        if(!is_array($draft)||($draft['status']??'')!=='Pending')continue;
        $id=gm_text($draft['id']??$key,160);$on=(string)($draft['date']??'');if($id===''||isset($seen[$id])){$warnings[]='Reconcile missing or repeated pending TG remittance identities.';continue;}$seen[$id]=true;
        if(!mr_valid_date($on)){$warnings[]='Confirm the original date of pending TG remittance '.$id.'.';continue;}if($on>$date)continue;
        $currency=strtoupper((string)($draft['currency']??''));$amount=$draft['amountNative']??null;
        if(!in_array($currency,['USD','AED','PKR'],true)||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||(float)$amount>1e14){$warnings[]='Reconcile the original currency and amount of pending TG remittance '.$id.'.';continue;}
        $posts=[];$valid=true;foreach((array)($draft['pakJournalIds']??[]) as $postId){$post=$state['journals'][(string)$postId]??null;if(!is_array($post)||($post['status']??'')!=='Posted'||!in_array($post['entity']??'',['TTI','BRM'],true)||!mr_valid_date((string)($post['date']??''))||$post['date']>$date){$valid=false;break;}$posts[]=(string)$postId;}
        if(!$valid||!$posts){$warnings[]='Reconcile the original Pakistan advice posting linked to TG remittance '.$id.'.';continue;}
        $rows[]=['id'=>$id,'date'=>$on,'currency'=>$currency,'amountNative'=>round((float)$amount,2),'pakJournalIds'=>array_values(array_unique($posts)),'status'=>'Awaiting TG bank confirmation'];$totals[$currency]=round(($totals[$currency]??0)+(float)$amount,2);
    }
    usort($rows,static fn($a,$b)=>strcmp($a['date'].'|'.$a['id'],$b['date'].'|'.$b['id']));
    return ['rows'=>$rows,'totals'=>$totals,'warnings'=>array_values(array_unique($warnings)),'basis'=>'Pakistan credit advices already posted; TG remittances await their separate original-bank review. Pending amounts do not settle TG payables or reduce its bank balances.'];
}
/** Display identity only: never substitute a master ID for a journal's bank identity. */
function gm_source_bank_label(array $line,string $bank,string $entity,array $masters):string {
    foreach($masters as $master){
        if(!is_array($master)||(string)($master['id']??'')!==$bank)continue;
        $v=array_values((array)($master['values']??[]));$linked=strtoupper((string)($v[1]??''));
        $company=str_contains($linked,'BRM')||str_contains($linked,'BUKSH RICE')?'BRM':(str_contains($linked,'TTI')||str_contains($linked,'TRANSTRADE INTERNATIONAL')?'TTI':(str_contains($linked,'TG')||str_contains($linked,'TRANS GRAINS')?'TG':''));
        if($company!==$entity)continue;
        $line=['bankName'=>$v[4]??'','accountTitle'=>$v[3]??'','bankCurrency'=>$v[7]??'','accountNumber'=>$v[8]??'','iban'=>$v[9]??''];break;
    }
    $number=trim((string)($line['accountNumber']??''));if($number==='')$number=trim((string)($line['iban']??''));
    $parts=array_values(array_unique(array_filter(array_map('trim',[(string)($line['bankName']??''),(string)($line['accountTitle']??''),(string)($line['bankCurrency']??$line['currency']??''),$number]),static fn($part)=>$part!=='')));
    return $parts?implode(' · ',$parts):'Unresolved bank — '.$bank;
}
function gm_financial_source(array $state,string $date,?array $bankMasters=null,bool $keepZero=false):array {
    $bankMasters??=function_exists('tt_list_masters')?(array)(tt_list_masters()['banks']??[]):[];
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
            if(!isset($rows[$id]))$rows[$id]=['id'=>$id,'type'=>'FINANCIAL','entity'=>$entity,'label'=>trim($name.($party!==''?' — '.$party:'').($bank!==''?' — '.($code==='1110'?gm_source_bank_label($line,$bank,$entity,$bankMasters):$bank):'')),'account'=>$code,'party'=>$party,'bankAccountId'=>$code==='1110'?$bank:'','bankLabel'=>$code==='1110'&&$bank!==''?gm_source_bank_label($line,$bank,$entity,$bankMasters):'','subaccountId'=>$subaccount,'currency'=>$entity==='TG'?'AED':'PKR','amount'=>0.,'source'=>'Accounts journals','postIds'=>[],'basis'=>str_starts_with($code,'13')?'Book inventory value — market valuation review required':'Posted book balance','excluded'=>false,'reason'=>''];
            // Match the ledger/balance sheet's per-line two-decimal book amounts.
            $rows[$id]['amount']+=round(gm_number($line['debit']??0,'journal debit'),2)-round(gm_number($line['credit']??0,'journal credit'),2);$rows[$id]['postIds'][(string)($journal['id']??$postId)]=true;
            if(in_array($code,['1240','2500'],true)){$rows[$id]['excluded']=true;$rows[$id]['reason']='Group intercompany elimination; reconcile counterpart balances and exchange differences.';$intercompany[$id]=true;}
        }
    }
    if($intercompany)$unclassified[]='Intercompany balances are excluded provisionally. Reconcile both sides and exchange differences before completing the group report.';$pending=gm_pending_tg_remittances($state,$date);$unclassified=array_merge($unclassified,$pending['warnings']);if($pending['rows'])$unclassified[]='TG bank confirmation is pending for '.count($pending['rows']).' saved remittances. Review the original TG remittance and Pakistan advice references below; no payment has been assumed.';
    $out=[];foreach($rows as $row){$row['amount']=round($row['amount'],2);if(!$keepZero&&abs($row['amount'])<.005)continue;$row['postIds']=array_keys($row['postIds']);$row['originalAmount']=$row['amount'];$row['originalCurrency']=$row['currency'];$row['sourceDate']=$date;$row['sourceFingerprint']=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));$out[]=$row;}
    usort($out,static fn($a,$b)=>(str_contains(strtolower($a['label']),'sales tax')||str_contains(strtolower($a['label']),'gst'))<=>(str_contains(strtolower($b['label']),'sales tax')||str_contains(strtolower($b['label']),'gst'))?:strcmp($a['entity'].'|'.$a['account'].'|'.$a['label'],$b['entity'].'|'.$b['account'].'|'.$b['label']));
    return ['rows'=>$out,'pendingTgRemittances'=>$pending,'warnings'=>array_values(array_unique($unclassified)),'accountsRevision'=>(int)($state['revision']??0),'asOf'=>$date,'basis'=>'Committed journals through the selected date. Excludes fixed assets and share holdings. Inventory is book cost until reviewed at market value. Unposted operational liabilities and shipment estimates require separate review.'];
}

/** Owner cash-flow scope; presentation only, never a journal or trial-balance change. */
function gm_cashflow_source(array $state,string $date,?array $bankMasters=null):array {
    require_once __DIR__.'/cashflow_native_source.php';
    $bankMasters??=function_exists('tt_list_masters')?(array)(tt_list_masters()['banks']??[]):[];
    require_once __DIR__.'/cashflow_party_core.php';
    $masters=function_exists('tt_list_masters')?(array)tt_list_masters():[];$state=cfp_books($state,$masters);
    return cfp_rows(cfn_source(gm_cashflow_scope(gm_financial_source($state,$date,$bankMasters,true)),$state,$date,$bankMasters));
}
function gm_cashflow_scope(array $source):array {
    foreach($source['rows'] as &$row){
        $code=(string)$row['account'];$label=(string)$row['label'];$reason='';
        if(in_array($code,['1240','2500'],true))$reason='Intercompany balance already embedded; excluded from consolidated cash flow.';
        elseif(in_array($code,['1250','2110','2120','2130','2140','2190','2510'],true)&&preg_match('/(?:\bTTI\b|\bBRM\b|\bTG\b|TRANSTRADE INTERNATIONAL|TRANS GRAINS|BUKSH RICE)/i',$label))$reason='Internal group party; excluded from consolidated cash flow.';
        elseif($code==='1120')$reason='Petty cash excluded from this management cash-flow view by owner instruction.';
        elseif($code==='2230')$reason='Personal, staff, transfer-clearing or investment control; excluded from management cash flow. Salary forecast follows its due date separately.';
        elseif($code==='1230'&&preg_match('/\b(?:RAMZAN|HASSAN|BALOCH|BALOUCH)\b/i',$label)&&!preg_match('/NASEEM|ZAFAR/i',$label))$reason='Staff advance balance excluded from management cash flow; original payroll and bank entries retained.';
        elseif($code==='1230'&&preg_match('/TG OWN|OWN HABIB|CURRENCY TRANSFER CLEARING/i',$label))$reason='Own-bank transfer clearing; excluded from cash flow.';
        elseif(in_array($code,['1230','2140','2190'],true)&&preg_match('/(?:\bTRP\b|\bSRP\b|\bARP\b|\bTALHA\b|\bTAYYAB\b|\bSALMAN\b|\bMRS\b)/i',$label))$reason='Family or remuneration balance; excluded from management cash flow.';
        if($reason!==''){$row['excluded']=true;$row['reason']=$reason;}
    }unset($row);
    $source['warnings']=array_values(array_filter($source['warnings']??[],static fn($w)=>!str_starts_with($w,'Intercompany balances are excluded provisionally.')));
    $source['basis'].=' Management cash flow excludes group intercompany, petty cash, staff/personal and remuneration control balances. Company ledgers, trial balances and balance sheets retain their original postings.';return $source;
}

function gm_cashflow_offset_suppliers(array $source):array {
    // An equal supplier advance and payable are one funded position, not a
    // future receipt plus another supplier payment. Preserve both book controls.
    $offsets=[];foreach($source['rows'] as $i=>$r){$party=strtoupper(trim((string)($r['party']??'')));if($party===''||!empty($r['excluded'])||!in_array($r['account'],['1250','2110','2130','2140'],true))continue;$k=($r['entity']??'').'|'.($r['currency']??'').'|'.$party;$offsets[$k][]=$i;}
    foreach($offsets as $ids){$advance=0.;$payable=0.;foreach($ids as $i){$r=$source['rows'][$i];if($r['account']==='1250'&&$r['amount']>0)$advance+=$r['amount'];elseif($r['account']!=='1250'&&$r['amount']<0)$payable-=$r['amount'];}if($advance>0&&abs($advance-$payable)<.005)foreach($ids as $i){$source['rows'][$i]['excluded']=true;$source['rows'][$i]['reason']='Matching supplier advance and payable offset in full; no net external cash requirement. Original invoice allocations remain in Accounts.';}}
    return $source;
}

/** A partial assessment must never silently become next year's opening balance. */
function gm_assessment_progress(array $record):array {
    $required=['header','financials','properties','gold','shares','adjustments'];$missing=[];
    foreach($required as $name){$section=$record['sections'][$name]??[];
        if($name==='header'){if(empty($section['data']['ramadanDateConfirmed'])||empty($section['data']['hijriYear']))$missing[]=$name;}
        elseif(empty($section['complete']))$missing[]=$name;
    }
    return ['complete'=>!$missing,'missingSections'=>$missing,'status'=>$missing?'PARTIAL':'COMPLETE'];
}
function gm_post_detail(array $state,string $id,string $date):array {
    $post=$state['journals'][$id]??null;
    if(!is_array($post)||!in_array($post['entity']??'',['TTI','BRM','TG'],true)||!in_array($post['status']??'',['Posted','Posted / Bill Pending'],true)||!mr_valid_date((string)($post['date']??''))||$post['date']>$date)throw new DomainException('The source posting is unavailable at this report date.');
    $lines=[];foreach((array)($post['lines']??[]) as $line)$lines[]=['account'=>(string)($line['account']??''),'accountName'=>(string)($line['accountName']??''),'debit'=>(float)($line['debit']??0),'credit'=>(float)($line['credit']??0),'party'=>(string)($line['counterparty']??$line['subledger']??$line['partyName']??''),'bankAccountId'=>(string)($line['bankAccountId']??'')];
    return ['id'=>(string)($post['id']??$id),'entity'=>$post['entity'],'currency'=>$post['entity']==='TG'?'AED':'PKR','date'=>$post['date'],'reference'=>(string)($post['reference']??''),'narration'=>(string)($post['narration']??''),'lines'=>$lines];
}
/** Annual debit and cash credits in the TTI assessment control; never a second expense journal. */
function gm_zakat_control(array $record,array $summary,array $payments,bool $complete):array {
    $balance=0.;$rows=[];
    $append=static function(string $date,string $reference,string $description,float $amount)use(&$balance,&$rows):void{
        $balance=round($balance+$amount,2);
        $rows[]=['date'=>$date,'reference'=>$reference,'description'=>$description,'debitPKR'=>max(0,$amount),'creditPKR'=>max(0,-$amount),'balancePKR'=>$balance];
    };
    $append($record['date'],'OPENING','Prior Zakat payable / excess carried forward',(float)$summary['openingCarryPKR']);
    if($complete)$append($record['date'],$record['id'],'Yearly assessed Zakat · '.($record['sections']['header']['data']['hijriYear']??'').' AH',(float)$summary['assessmentPKR']);
    foreach($payments as $payment)$append($payment['date'],$payment['postId'],$payment['amountPKR']<0?'Zakat payment reversed / refunded':'Actual TTI Zakat payment',-(float)$payment['amountPKR']);
    return ['entity'=>'TTI','currency'=>'PKR','assessmentRecorded'=>$complete,'annualDebitPKR'=>$complete?$summary['assessmentPKR']:null,'paidExpensePKR'=>$summary['paidPKR'],'balancePKR'=>$balance,'rows'=>$rows,'basis'=>'Assessment control: debit increases Zakat due; credit reduces it. Complete every annual section to record the yearly debit. Actual payments alone are expense; no duplicate company journal is created by the assessment.'];
}
function gm_assessment_summary(array $record,array $state,string $through,?float $opening=null):array {
    $rates=(array)($record['sections']['header']['data']['rates']??[]);$sections=array_intersect_key((array)($record['sections']??[]),array_flip(['financials','properties','gold','shares','adjustments']));$posted=[];
    foreach($sections as $key=>$section)if(!empty($section['complete']))$posted[$key]=$section;
    $payments=gm_zakat_payments($state,$record['date'],$through);$carry=$opening??gm_number($record['sections']['header']['data']['openingCarryPKR']??0,'opening Zakat carry-forward',true);
    $progress=gm_assessment_progress($record);if($payments['warnings']){$progress['complete']=false;$progress['status']='PARTIAL';$progress['missingSections'][]='payment allocation review';}
    $summary=gm_zakat($posted,$rates,$carry,$payments['totalPKR']);
    return $summary+['controlLedger'=>gm_zakat_control($record,$summary,$payments['rows'],gm_assessment_progress($record)['complete']),'payments'=>$payments['rows'],'through'=>$through,'assessmentOnly'=>true,'progress'=>$progress,'paymentWarnings'=>$payments['warnings'],'expenseBasis'=>'Only actual TTI Zakat payment postings in account 7210. Assessment section completion does not post another expense.'];
}
function gm_record_summary(array $record,array $state,string $through):array {
    if($record['kind']==='ZAKAT'){
        $next=array_values(array_filter((array)($state['groupManagementRecords']??[]),static fn($r)=>($r['kind']??'')==='ZAKAT'&&($r['date']??'')>$record['date']));usort($next,static fn($a,$b)=>strcmp($a['date'],$b['date']));if($next)$through=min($through,(new DateTimeImmutable($next[0]['date']))->modify('-1 day')->format('Y-m-d'));
        $prior=array_values(array_filter((array)($state['groupManagementRecords']??[]),static fn($r)=>($r['kind']??'')==='ZAKAT'&&($r['date']??'')<$record['date']));usort($prior,static fn($a,$b)=>strcmp($a['date'],$b['date']));$opening=null;
        foreach($prior as $r){if(!gm_assessment_progress($r)['complete'])throw new DomainException('Complete the prior Zakat assessment dated '.$r['date'].' before carrying its balance forward.');$end=(new DateTimeImmutable($record['date']))->modify('-1 day')->format('Y-m-d');$next=array_values(array_filter($prior,static fn($x)=>$x['date']>$r['date']));if($next)$end=(new DateTimeImmutable($next[0]['date']))->modify('-1 day')->format('Y-m-d');$priorSummary=gm_assessment_summary($r,$state,$end,$opening);if(!$priorSummary['progress']['complete'])throw new DomainException('Review the original payment allocations for the prior Zakat assessment dated '.$r['date'].' before carrying its balance forward.');$opening=$priorSummary['balancePKR'];}
        return gm_assessment_summary($record,$state,$through,$opening);
    }
    $rates=(array)($record['sections']['header']['data']['rates']??[]);$rows=[];foreach((array)($record['sections']??[]) as $key=>$section)if($key!=='header')$rows=array_merge($rows,(array)($section['rows']??[]));return gm_total($rows,$rates);
}

/** Compare only the selected actual Rice runs; production cost excludes shipment expenses. */
function gm_actual_comparison(array $selection,array $ops,array $books,array $calculation,string $commodity):array {
 if(strtoupper($commodity)!=='RICE')throw new DomainException('Actual Corn comparison requires its direct loading and purchase sources; do not substitute Rice milling.');
 $entity=strtoupper(gm_text($selection['entity']??'',3));if(!in_array($entity,['TTI','BRM'],true))throw new DomainException('Select the actual production company.');
 $from=gm_date($selection['from']??'');$to=gm_date($selection['to']??'');if($from>$to)throw new DomainException('Actual period From cannot follow To.');
 $mill=gm_text($selection['mill']??'',200);$variety=gm_text($selection['variety']??'',200);if($mill===''||$variety==='')throw new DomainException('Select the exact actual mill and source variety.');
 if(preg_match('/\b(CORN|MAIZE|MAKAI)\b/i',$variety))throw new DomainException('Choose Rice production; Corn uses direct loading sources.');
 $raw=$ops['values']['tt30prod']??'[]';if(!is_string($raw))throw new DomainException('Actual production source is unreadable.');$runs=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($runs)||!array_is_list($runs))throw new DomainException('Actual production source is unreadable.');
 // Do not borrow a legacy missing company or mill from the report's defaults.
 $runs=array_values(array_filter($runs,static fn($r)=>is_array($r)&&($r['entity']??'')===$entity&&($r['millName']??$r['locationName']??'')===$mill));
 $report=mr_report($runs,['entity'=>$entity,'from'=>$from,'to'=>$to,'mill'=>$mill,'variety'=>$variety,'mode'=>'daily']);
 $total=$report['totals'];$ids=[];foreach($report['groups'] as $group)foreach($group['runs'] as $run)$ids[$run['id']]=true;
 $available=$total['inputComplete']&&$total['inputKg']>0&&$total['readyKg']>0;$recovery=$available?round($total['readyKg']/$total['inputKg']*100,6):null;
 $cost=null;$costBasis=[];$costStatus='Actual production cost unavailable';
 if($available){require_once __DIR__.'/mill_recovery_cost.php';$costed=mr_attach_costs($report,$runs,mr_cost_sources($books,$ops,$entity));$costBasis=$costed['costTotals']['basis'];
  // Management estimates and incomplete overhead allocations cannot be labelled actual costs.
  if($costed['costTotals']['available']&&$costBasis&&count(array_filter($costBasis,static fn($basis)=>$basis!=='Approved Accounts cost'))===0){$cost=round($costed['costTotals']['costPerKg']*1000,4);$costStatus='Approved Accounts production cost';}
  elseif($costBasis)$costStatus=implode('; ',$costBasis);
 }
 return ['selection'=>['entity'=>$entity,'from'=>$from,'to'=>$to,'mill'=>$mill,'variety'=>$variety],'sourceRunIds'=>array_keys($ids),'operationsRevision'=>(int)($ops['revision']??0),'accountsRevision'=>(int)($books['revision']??0),'sourceFingerprint'=>hash('sha256',json_encode([$runs,$books['productionCostRates']??[],$books['revision']??0],JSON_THROW_ON_ERROR)),'quantityAvailable'=>$available,'status'=>$available?($total['differenceKg']<-.001?'Actual output exceeds input — reconcile':'Actual quantities recorded'):'Complete actual input and finished output required','inputKg'=>$total['inputKg'],'readyKg'=>$total['readyKg'],'readyRecoveryPct'=>$recovery,'estimatedReadyRecoveryPct'=>$calculation['readyRecoveryPct'],'recoveryVariancePoints'=>$recovery===null?null:round($recovery-$calculation['readyRecoveryPct'],6),'productionCostPKRPerMT'=>$cost,'estimatedProductionCostPKRPerMT'=>$calculation['productionCostPKRPerReadyMT'],'productionCostVariancePKRPerMT'=>$cost===null?null:round($cost-$calculation['productionCostPKRPerReadyMT'],4),'costStatus'=>$costStatus,'costBasis'=>$costBasis];
}
