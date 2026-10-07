<?php
declare(strict_types=1);
require_once __DIR__.'/assets_registry_core.php';
require_once __DIR__.'/accounts_bank_payment.php';

function inv_names():array { $m=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true,512,JSON_THROW_ON_ERROR);$out=[];foreach(array_merge($m['chart'],$m['peopleSubledgers']??[]) as $r)$out[(string)$r['code']]=$r['name'];return $out; }
function inv_normal(string $s):string {return strtolower((string)preg_replace('/[^\pL\pN]/u','',$s));}
function inv_line(string $account,int $debit,int $credit,array $extra=[]):array {$names=inv_names();if(!isset($names[$account]))throw new RuntimeException('Investment accounting head unavailable.');return ['account'=>$account,'accountName'=>$names[$account],'debit'=>$debit/100,'credit'=>$credit/100]+$extra;}
function inv_banks(array $s,string $e):array { $banks=far_banks($s,$e);$base=$e==='TG'?'AED':'PKR';return array_values(array_filter($banks,static fn($b)=>$b['currency']===$base)); }
function inv_bank(array $s,string $e,array $b,int $amount,bool $incoming=false):array {
    $id=(string)($b['paymentAccountId']??'');$bank=null;foreach(inv_banks($s,$e) as $r)if($r['id']===$id)$bank=$r;
    if(!$bank)throw new DomainException('Select an active company bank in the company book currency.');
    $tracking=$incoming?[]:tt_accounts_bank_payment_details($b);
    if(!$incoming&&($tracking['bankPaymentMethod']??'')==='CHEQUE'){
        $number=strtoupper($tracking['chequeNo']);
        foreach((array)($s['journals']??[]) as $j){if(($j['entity']??'')!==$e||($j['status']??'')!=='Posted'||!empty($j['reversalOf'])||!empty($j['reversedByPostId']))continue;
            foreach((array)($j['lines']??[]) as $l)if(($l['account']??'')==='1110'&&($l['bankAccountId']??'')===$id&&($l['credit']??0)>0&&strtoupper((string)($l['chequeNo']??$j['meta']['chequeNo']??''))===$number)throw new DomainException('This bank cheque is already posted.');
        }
        foreach((array)($s['issuedCheques']??[]) as $c)if(($c['entity']??'')===$e&&($c['bankAccountId']??$c['paymentAccountId']??'')===$id&&strtoupper((string)($c['chequeNo']??''))===$number&&!in_array($c['status']??'',['Cancelled','Voided','Reversed'],true))throw new DomainException('This cheque is already issued.');
    }
    return inv_line('1110',$incoming?$amount:0,$incoming?0:$amount,['bankAccountId'=>$id,'bankName'=>$bank['label'],'currency'=>$bank['currency'],'bankCurrency'=>$bank['currency'],'bankDebit'=>$incoming?$amount/100:0,'bankCredit'=>$incoming?0:$amount/100,'exchangeRate'=>1]+$tracking);
}
function inv_balance(array $s,string $e,string $code,string $field,string $id):int {
    $n=0;foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted')foreach((array)($j['lines']??[]) as $l)if(($l['account']??'')===$code&&($l[$field]??'')===$id)$n+=(int)round(((float)($l['debit']??0)-(float)($l['credit']??0))*100);return $n;
}
function inv_broker(array $s,string $e,string $id,bool $active=true):array {$r=$s['investmentBrokers'][$id]??null;if(!is_array($r)||$r['entity']!==$e||($active&&!$r['active']))throw new DomainException('Select an active broker in these company books.');return $r;}
function inv_route(array $s,string $e,string $id,bool $active=true):array {$r=$s['investmentRoutes'][$id]??null;if(!is_array($r)||$r['entity']!==$e||($active&&!$r['active']))throw new DomainException('Select an active routing person in these company books.');return $r;}
function inv_holdings(array $s,string $e):array {
    $rows=[];$events=array_values((array)($s['investmentTransactions']??[]));usort($events,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:($a['sequence']<=>$b['sequence']));
    foreach($events as $t){if($t['entity']!==$e||$t['status']!=='Posted'||!in_array($t['type'],['BUY','SELL'],true))continue;$k=$t['brokerId'].'|'.$t['symbol'];$h=$rows[$k]??['brokerId'=>$t['brokerId'],'symbol'=>$t['symbol'],'quantity'=>0,'costCents'=>0];
        if($t['type']==='BUY'){$h['quantity']+=$t['quantity'];$h['costCents']+=$t['grossCents'];}
        else {if($t['quantity']>$h['quantity'])throw new DomainException('This would leave a share sale without enough earlier purchases.');$cost=$t['quantity']===$h['quantity']?$h['costCents']:(int)round($h['costCents']*$t['quantity']/$h['quantity']);if($cost!==$t['costCents'])throw new DomainException('Later share-sale costs depend on this transaction. Reverse those sales first.');$h['quantity']-=$t['quantity'];$h['costCents']-=$cost;}
        $rows[$k]=$h;
    }return $rows;
}
function inv_validate_timeline(array $s,string $e):void {
    $broker=[];$route=[];$events=array_values((array)($s['investmentTransactions']??[]));usort($events,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:($a['sequence']<=>$b['sequence']));
    foreach($events as $t){if($t['entity']!==$e||$t['status']!=='Posted')continue;foreach((array)$t['brokerDeltas'] as $id=>$n){$broker[$id]=($broker[$id]??0)+$n;if($broker[$id]<0)throw new DomainException('Broker funds would be insufficient on a transaction date. Review the linked transactions first.');}foreach((array)$t['routeDeltas'] as $id=>$n){$route[$id]=($route[$id]??0)+$n;if($route[$id]<0)throw new DomainException('Routed funds would be insufficient on a transaction date. Review the onward transfers first.');}}
    inv_holdings($s,$e);
}
function inv_journal(array &$s,string $e,string $date,string $type,string $reference,array $lines,array $u,array $meta):array {
    $dr=0;$cr=0;foreach($lines as $l){$dr+=(int)round($l['debit']*100);$cr+=(int)round($l['credit']*100);}if($dr!==$cr||$dr<=0)throw new RuntimeException('Investment journal does not balance.');
    $id=tt_next_post_id((array)($s['journals']??[]),'Accounts','Journal',$date);return $s['journals'][$id]=['id'=>$id,'entity'=>$e,'date'=>$date,'sourceType'=>'COMPANY_INVESTMENT_'.$type,'reference'=>$reference,'narration'=>str_replace('_',' ',$type).' — '.($meta['brokerName']??$meta['routeName']??'COMPANY INVESTMENT'),'lines'=>$lines,'totalDebit'=>$dr/100,'totalCredit'=>$cr/100,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>far_text($u['full_name']??$u['username']??'Accounts'),'userId'=>$u['id']??0,'reversalOf'=>null];
}
function inv_master(array &$s,string $e,array $b,array $u):array {
    $kind=(string)($b['kind']??'');if(!in_array($kind,['broker','route'],true))throw new DomainException('Select broker or routing person.');$collection=$kind==='broker'?'investmentBrokers':'investmentRoutes';$operation=(string)($b['operation']??'add');$id=(string)($b['id']??'');$old=$s[$collection][$id]??null;
    if(!in_array($operation,['add','edit','delete'],true))throw new DomainException('Select Add, Edit or Delete.');
    if($operation!=='add'&&(!is_array($old)||$old['entity']!==$e))throw new DomainException('Record was not found in these company books.');
    if($operation==='delete'){
        if($kind==='broker'&&(inv_balance($s,$e,'1430','brokerId',$id)!==0||array_filter(inv_holdings($s,$e),static fn($h)=>$h['brokerId']===$id&&$h['quantity']>0)))throw new DomainException('Withdraw broker funds and close holdings before deleting this broker.');
        if($kind==='route'&&inv_balance($s,$e,'1440','routeId',$id)!==0)throw new DomainException('Complete the routing transfers before deleting this person.');$s[$collection][$id]['active']=false;
    }else{
        $name=far_text($b['name']??'',180);if($name===''||inv_normal($name)==='')throw new DomainException('Enter a name.');$account=far_text($b['accountReference']??'',180);
        foreach((array)($s[$collection]??[]) as $r)if($r['entity']===$e&&$r['id']!==$id&&inv_normal($r['name'])===inv_normal($name))throw new DomainException('This name already exists, including an archived record. Edit that record instead.');
        if($operation==='add')$id=far_next((array)($s[$collection]??[]),$kind==='broker'?'BROKER':'ROUTE');
        $s[$collection][$id]=['id'=>$id,'entity'=>$e,'name'=>$name,'accountReference'=>$account,'active'=>true,'version'=>(int)($old['version']??0)+1];
    }
    $s['investmentAudit'][]=['action'=>'MASTER_'.$operation,'kind'=>$kind,'id'=>$id,'before'=>$old,'at'=>gmdate('c'),'by'=>$u['id']??0];return ['message'=>'RECORD SAVED','id'=>$id];
}
function inv_salary_allowed(array $u,string $right='View'):bool {
    return ($u['role']??'')==='Super Admin'||(function_exists('tt_user_can_module_action')?tt_user_can_module_action($u,'Accounts','expenses',$right):true);
}
function inv_salary_periods(array $s,string $e,array $u):array {
    if(!inv_salary_allowed($u))return [];$out=[];foreach((array)($s['salaryPeriods']??[]) as $id=>$r)if(($r['entity']??'')===$e&&($r['outstanding']??0)>.005)$out[]=['id'=>$id,'name'=>$r['name'],'month'=>$r['month'],'outstanding'=>$r['outstanding']];return $out;
}
function inv_post(array &$s,string $e,array $b,array $u):array {
    $type=(string)($b['type']??'');$date=far_date($b['date']??'');if($date<FAR_CUTOFF)throw new DomainException('Use an opening balance workflow for dates before 01-07-2026.');
    if(!in_array($type,['FUND_DIRECT','FUND_ROUTE','ROUTE_TO_BROKER','BUY','SELL','WITHDRAW_BANK','WITHDRAW_ROUTE','ROUTE_RETURN'],true))throw new DomainException('Select an investment transaction.');
    $ref=far_text($b['reference']??'',180);if($ref==='')throw new DomainException('Enter the bank transfer or broker contract-note reference.');
    foreach((array)($s['investmentTransactions']??[]) as $t)if($t['entity']===$e&&$t['status']==='Posted'&&$t['type']===$type&&$t['reference']===$ref&&$t['brokerId']===(string)($b['brokerId']??'')&&$t['routeId']===(string)($b['routeId']??''))throw new DomainException('This transfer or contract-note reference is already recorded.');
    $broker=null;$route=null;$bid='';$rid='';if(!in_array($type,['FUND_ROUTE','ROUTE_RETURN'],true)){$bid=(string)($b['brokerId']??'');$broker=inv_broker($s,$e,$bid);}
    if(in_array($type,['FUND_ROUTE','ROUTE_TO_BROKER','WITHDRAW_ROUTE','ROUTE_RETURN'],true)){$rid=(string)($b['routeId']??'');$route=inv_route($s,$e,$rid);}
    foreach((array)($s['investmentTransactions']??[]) as $previous)if($previous['entity']===$e&&$previous['status']==='Posted'&&($b['paymentAccountId']??'')!==''&&($previous['paymentAccountId']??'')===$b['paymentAccountId']&&$previous['reference']===$ref)throw new DomainException('This company bank transfer reference is already recorded.');
    $bx=['brokerId'=>$bid,'counterparty'=>$broker['name']??'','subledger'=>$broker['name']??''];$rx=['routeId'=>$rid,'counterparty'=>$route['name']??'','subledger'=>'COMPANY FUNDS VIA '.($route['name']??'')];$amount=0;$gross=0;$fee=0;$cost=0;$quantity=0;$symbol='';$bd=[];$rd=[];$lines=[];
    if(in_array($type,['BUY','SELL'],true)){
        $q=$b['quantity']??null;if(!is_numeric($q)||(float)$q!==(float)(int)$q||(int)$q<1||(int)$q>1000000000)throw new DomainException('Enter a positive whole share quantity.');$quantity=(int)$q;
        $price=$b['price']??null;if(!is_numeric($price)||!is_finite((float)$price)||(float)$price<=0||(float)$price>1000000000)throw new DomainException('Enter a valid cost / sale price per share.');
        $symbol=far_text($b['symbol']??'',80);if(!preg_match('/^[A-Z0-9][A-Z0-9 ._-]{0,79}$/',$symbol))throw new DomainException('Enter the share symbol / company.');
        $gross=far_cents($quantity*(float)$price);$fee=far_cents($b['fees']??0,true);$sx=$bx+['symbol'=>$symbol,'quantity'=>$quantity,'unitPrice'=>(float)$price];
        if($type==='BUY'){$amount=$gross+$fee;$bd[$bid]=-$amount;$lines[] =inv_line('1610',$gross,0,$sx);if($fee)$lines[]=inv_line('6800',$fee,0,$bx+['chargeType'=>'STOCKBROKER_FEES']);$lines[]=inv_line('1430',0,$amount,$bx);}
        else {$hold=inv_holdings($s,$e)[$bid.'|'.$symbol]??null;if(!$hold||$hold['quantity']<$quantity)throw new DomainException('Sale exceeds shares held with this broker.');$cost=$quantity===$hold['quantity']?$hold['costCents']:(int)round($hold['costCents']*$quantity/$hold['quantity']);if($fee>=$gross)throw new DomainException('Fees must be below the gross sale proceeds.');$amount=$gross-$fee;$bd[$bid]=$amount;$lines[]=inv_line('1430',$amount,0,$bx);if($fee)$lines[]=inv_line('6800',$fee,0,$bx+['chargeType'=>'STOCKBROKER_FEES']);$lines[]=inv_line('1610',0,$cost,$sx);$gain=$gross-$cost;if($gain>0)$lines[]=inv_line('4450',0,$gain,$sx);elseif($gain<0)$lines[]=inv_line('6850',-$gain,0,$sx);}
    }else{
        $amount=far_cents($b['amount']??null);
        if($type==='FUND_ROUTE'){$rem=far_cents($b['remuneration']??0,true);$rd[$rid]=$amount;$lines[]=inv_line('1440',$amount,0,$rx);if($rem){$treatment=(string)($b['remunerationTreatment']??'');$account=match($treatment){'NEW_EXPENSE'=>'6210','PAYABLE'=>'2140','FAMILY'=>'3200',default=>throw new DomainException('Choose how the remuneration portion was recorded.')};
            $salaryMeta=[];
            if($treatment==='PAYABLE'){
                if(!inv_salary_allowed($u,'Create'))throw new DomainException('Expense payment permission is required to settle prepared remuneration.');
                $salaryPeriodId=(string)($b['salaryPeriodId']??'');$sp=$s['salaryPeriods'][$salaryPeriodId]??null;
                if(!is_array($sp)||($sp['entity']??'')!==$e||inv_normal((string)$sp['name'])!==inv_normal($route['name'])||far_cents($sp['outstanding']??0,true)<$rem)throw new DomainException('Select an outstanding prepared salary period for the routing person, with enough remuneration due.');
                $salaryMeta=['salaryMasterId'=>$sp['salaryMasterId'],'month'=>$sp['month'],'person'=>$sp['name'],'party'=>$sp['name']];
            }
            $lines[]=inv_line($account,$rem,0,['counterparty'=>$route['name'],'subledger'=>$route['name'],'remunerationTreatment'=>$treatment]+$salaryMeta);}$lines[]=inv_bank($s,$e,$b,$amount+$rem);}
        elseif($type==='FUND_DIRECT'){$bd[$bid]=$amount;$lines=[inv_line('1430',$amount,0,$bx),inv_bank($s,$e,$b,$amount)];}
        elseif($type==='ROUTE_TO_BROKER'){$bd[$bid]=$amount;$rd[$rid]=-$amount;$lines=[inv_line('1430',$amount,0,$bx),inv_line('1440',0,$amount,$rx)];}
        elseif($type==='WITHDRAW_BANK'){$bd[$bid]=-$amount;$lines=[inv_bank($s,$e,$b,$amount,true),inv_line('1430',0,$amount,$bx)];}
        elseif($type==='WITHDRAW_ROUTE'){$bd[$bid]=-$amount;$rd[$rid]=$amount;$lines=[inv_line('1440',$amount,0,$rx),inv_line('1430',0,$amount,$bx)];}
        else {$rd[$rid]=-$amount;$lines=[inv_bank($s,$e,$b,$amount,true),inv_line('1440',0,$amount,$rx)];}
    }
    $id=far_next((array)($s['investmentTransactions']??[]),'INV');$meta=['investmentTransactionId'=>$id,'brokerId'=>$bid,'brokerName'=>$broker['name']??'','routeId'=>$rid,'routeName'=>$route['name']??'','companyOwned'=>true,'reference'=>$ref,'symbol'=>$symbol,'paymentAccountId'=>$b['paymentAccountId']??'','salaryPeriodId'=>$salaryPeriodId??''];$j=inv_journal($s,$e,$date,$type,$ref,$lines,$u,$meta);
    $s['investmentTransactions'][$id]=$meta+['id'=>$id,'entity'=>$e,'date'=>$date,'type'=>$type,'amountCents'=>$amount,'grossCents'=>$gross,'feesCents'=>$fee,'costCents'=>$cost,'quantity'=>$quantity,'price'=>$b['price']??0,'remunerationCents'=>isset($rem)?$rem:0,'remunerationTreatment'=>$b['remunerationTreatment']??'','brokerDeltas'=>$bd,'routeDeltas'=>$rd,'journalId'=>$j['id'],'status'=>'Posted','sequence'=>count((array)($s['investmentTransactions']??[]))+1,'createdBy'=>$j['createdBy']];
    if(!empty($salaryPeriodId)){
        $s['salaryPeriods'][$salaryPeriodId]['paidAfterPrepare']=round((float)($sp['paidAfterPrepare']??0)+$rem/100,2);
        $s['salaryPeriods'][$salaryPeriodId]['outstanding']=round((float)$sp['outstanding']-$rem/100,2);
        $s['salaryPeriods'][$salaryPeriodId]['status']=$s['salaryPeriods'][$salaryPeriodId]['outstanding']>.005?'Part Paid':'Paid / Cleared';
        $s['rentSalaryPayments']['INV|'.$id]=['id'=>'INV|'.$id,'type'=>'SALARY','entity'=>$e,'masterId'=>$sp['salaryMasterId'],'month'=>$sp['month'],'date'=>$date,'amount'=>$rem/100,'journalId'=>$j['id'],'paymentAccountId'=>$b['paymentAccountId'],'investmentTransactionId'=>$id];
    }
    inv_validate_timeline($s,$e);return ['message'=>'POSTED','transactionId'=>$id,'journalId'=>$j['id'],'voucher'=>$j];
}
function inv_reverse(array &$s,string $e,array $b,array $u):array {
    $id=(string)($b['transactionId']??'');$t=$s['investmentTransactions'][$id]??null;if(!is_array($t)||$t['entity']!==$e||$t['status']!=='Posted')throw new DomainException('Select a current transaction to reverse.');
    $reason=far_text($b['reason']??'',500);if(strlen($reason)<5)throw new DomainException('Enter a reversal reason of at least five characters.');$date=far_date($b['date']??'');if($date<$t['date'])throw new DomainException('Reversal date must be on or after the original transaction.');
    $s['investmentTransactions'][$id]['status']='Reversed';inv_validate_timeline($s,$e);$old=$s['journals'][$t['journalId']]??null;if(!is_array($old)||!empty($old['reversedByPostId']))throw new DomainException('This posting is already reversed.');
    if(!empty($t['salaryPeriodId'])){
        $pid=$t['salaryPeriodId'];$sp=$s['salaryPeriods'][$pid]??null;if(!is_array($sp)||far_cents($sp['paidAfterPrepare']??0,true)<$t['remunerationCents'])throw new DomainException('Prepared remuneration changed. Review its payments before reversing.');
        $s['salaryPeriods'][$pid]['paidAfterPrepare']=round((float)$sp['paidAfterPrepare']-$t['remunerationCents']/100,2);$s['salaryPeriods'][$pid]['outstanding']=round((float)$sp['outstanding']+$t['remunerationCents']/100,2);$s['salaryPeriods'][$pid]['status']='Payable';$s['rentSalaryPayments']['INV|'.$id]['status']='Reversed';
    }
    $lines=[];foreach($old['lines'] as $l){[$l['debit'],$l['credit']]=[$l['credit'],$l['debit']];if(isset($l['bankDebit']))[$l['bankDebit'],$l['bankCredit']]=[$l['bankCredit'],$l['bankDebit']];$lines[]=$l;}
    $j=inv_journal($s,$e,$date,'REVERSAL',$old['id'],$lines,$u,$old['meta']+['reason'=>$reason]);$s['journals'][$j['id']]['reversalOf']=$old['id'];$s['journals'][$old['id']]['reversedByPostId']=$j['id'];$s['investmentTransactions'][$id]['reversalJournalId']=$j['id'];$s['investmentAudit'][]=['action'=>'REVERSE','id'=>$id,'reason'=>$reason,'date'=>$date,'by'=>$u['id']??0];return ['message'=>'REVERSED — ENTER THE CORRECT REPLACEMENT','journalId'=>$j['id'],'voucher'=>$s['journals'][$j['id']]];
}
function inv_payload(array $s,string $e):array {
    $brokers=[];$routes=[];foreach((array)($s['investmentBrokers']??[]) as $b)if($b['entity']===$e)$brokers[]=$b+['balanceCents'=>inv_balance($s,$e,'1430','brokerId',$b['id'])];foreach((array)($s['investmentRoutes']??[]) as $r)if($r['entity']===$e)$routes[]=$r+['balanceCents'=>inv_balance($s,$e,'1440','routeId',$r['id'])];
    $tx=[];foreach((array)($s['investmentTransactions']??[]) as $t)if($t['entity']===$e)$tx[]=$t+['voucher'=>$s['journals'][$t['journalId']]??null];usort($tx,static fn($a,$b)=>$b['sequence']<=>$a['sequence']);
    return ['ok'=>true,'entity'=>$e,'currency'=>$e==='TG'?'AED':'PKR','brokers'=>$brokers,'routes'=>$routes,'holdings'=>array_values(inv_holdings($s,$e)),'transactions'=>$tx,'banks'=>inv_banks($s,$e),'revision'=>(int)($s['revision']??0)];
}
