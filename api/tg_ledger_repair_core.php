<?php
declare(strict_types=1);
require_once __DIR__.'/tg_native_currency.php';
require_once __DIR__.'/accounts_bank_payment.php';
require_once __DIR__.'/accounts_reference.php';

function tglr_active(array $journal):bool {
    return ($journal['entity']??'')==='TG'&&($journal['status']??'')==='Posted'&&empty($journal['reversalOf'])&&empty($journal['reversedByPostId'])&&empty($journal['amendedByPostId'])&&empty($journal['meta']['deletedFromBooks']);
}
/** Plan only from saved native amounts, source currencies and historical rates. */
function tglr_plan(array $store):array {
    $native=[];$npss=[];$review=[];
    foreach((array)($store['journals']??[]) as $id=>$journal){
        if(!tglr_active($journal))continue;
        $working=$journal;$changes=[];$currencies=[];
        foreach((array)$journal['lines'] as $line)if(($line['account']??'')==='1110'&&!empty($line['currency']))$currencies[strtoupper($line['currency'])]=true;
        if(count($currencies)===1&&empty($working['meta']['currency']))$working['meta']['currency']=array_key_first($currencies);
        foreach((array)$journal['lines'] as $index=>$line){
            if((string)($line['account']??'')==='7100')continue;
            $candidate=$line;if(count($currencies)===1&&!isset($line['nativeDebit'],$line['nativeCredit'])&&($journal['sourceType']??'')!=='TG_USD_AED_TRANSFER'&&in_array((string)($line['currency']??''),['','AED'],true)&&array_key_first($currencies)!=='AED')$candidate['currency']=array_key_first($currencies);
            // The opening equity side is the exact opposite of the evidenced native opening line.
            if(($journal['sourceType']??'')==='CARRY_FORWARD_OPENING'&&(string)($line['account']??'')==='3400'&&count($journal['lines'])===2){
                $other=$journal['lines'][1-$index];
                if(isset($other['nativeDebit'],$other['nativeCredit'])&&!empty($other['currency'])&&abs((float)($other['debit']??0)-(float)($line['credit']??0))<.005&&abs((float)($other['credit']??0)-(float)($line['debit']??0))<.005){$candidate['currency']=$other['currency'];$candidate['nativeDebit']=$other['nativeCredit'];$candidate['nativeCredit']=$other['nativeDebit'];}
            }
            $display=tgn_line($working,$candidate);
            if($display['unavailable']){$review[]=['postId'=>tt_accounts_public_post($store,$journal),'line'=>$index+1,'reason'=>'Saved native amount or historical currency rate is missing.'];continue;}
            $fields=['currency'=>$display['currency'],'nativeDebit'=>$display['debit'],'nativeCredit'=>$display['credit']];
            if(($line['account']??'')==='1110')$fields+=['bankDebit'=>$display['debit'],'bankCredit'=>$display['credit']];
            $different=false;foreach($fields as $key=>$value)if(!array_key_exists($key,$line)||(is_numeric($value)?abs((float)$line[$key]-(float)$value)>.005:(string)$line[$key]!==$value)){$different=true;break;}
            if($different)$changes[]=['line'=>$index,'before'=>$line,'fields'=>$fields];
        }
        if($changes)$native[]=['journalId'=>(string)$id,'postId'=>tt_accounts_public_post($store,$journal),'date'=>$journal['date'],'changes'=>$changes];
    }
    foreach((array)($store['generalExpenses']??[]) as $id=>$record){
        if(($record['entity']??'')!=='TG'||($record['status']??'')!=='Posted'||($record['expenseType']??'')!=='DIRECT')continue;
        $journal=$store['journals'][$record['journalId']??'']??[];if(!tglr_active($journal))continue;
        $fee=0;$principal=[];$feeRows=[];
        foreach((array)($record['expenseLines']??[]) as $index=>$row){
            if(preg_match('/\bNPSS\b/i',(string)($row['purpose']??''))){$fee=round($fee+(float)$row['amount'],2);$feeRows[]=$index;}else $principal[]=$row;
        }
        if($fee<=0)continue;
        $banks=array_values(array_filter((array)$journal['lines'],static fn($l)=>($l['account']??'')==='1110'&&(float)($l['credit']??0)>0));
        if(count($banks)!==1||($banks[0]['currency']??'')!=='AED'||!$principal||!empty($journal['meta']['additionalAedBankCharge'])||array_filter($journal['lines'],static fn($l)=>($l['account']??'')==='1140')){$review[]=['postId'=>tt_accounts_public_post($store,$journal),'reason'=>'NPSS needs source review: existing VAT, split banks, or missing separate principal.'];continue;}
        $npss[]=['expenseId'=>(string)$id,'journalId'=>$journal['id'],'postId'=>tt_accounts_public_post($store,$journal),'date'=>$record['paymentDate'],'payee'=>$record['payee'],'bankAccountId'=>$banks[0]['bankAccountId'],'principal'=>round(array_sum(array_column($principal,'amount')),2),'charge'=>$fee,'vat'=>round($fee*.05,2),'vatRate'=>5,'feeRows'=>$feeRows,'expenseLines'=>$principal,'before'=>$record];
    }
    $plan=['native'=>$native,'npss'=>$npss,'review'=>$review];$plan['fingerprint']=hash('sha256',json_encode([$store['revision']??0,$plan],JSON_THROW_ON_ERROR));return $plan;
}
function tglr_apply(array &$store,array $user,string $fingerprint):array {
    if(($user['role']??'')!=='Super Admin'||!tt_user_can_access_entity($user,'TG','Edit'))throw new DomainException('Super Admin and TG company Edit access are required.');
    $plan=tglr_plan($store);if(!hash_equals($plan['fingerprint'],$fingerprint))throw new DomainException('The books changed. Review the current correction summary before applying it.');
    $working=$store;$audit=['at'=>gmdate('c'),'by'=>$user['username']??'','fingerprint'=>$fingerprint,'native'=>[],'npss'=>[],'review'=>$plan['review']];
    if($plan['npss']){
        if(abs((float)(function_exists('tt_company_vat_rate')?tt_company_vat_rate('TG',5):5)-5)>.0001)throw new DomainException('Historical NPSS corrections require the requested 5% VAT rate. Review TG company VAT setup first.');
        if(!defined('TT_EXPENSES_FUNCTIONS_ONLY'))define('TT_EXPENSES_FUNCTIONS_ONLY',true);require_once __DIR__.'/expenses_v1.php';
        foreach($plan['npss'] as $item){
            $old=$item['before'];$rows=array_map(static fn($r)=>$r+['accountCode'=>$r['account']],$item['expenseLines']);
            $body=['id'=>$item['expenseId'],'paymentDate'=>$old['paymentDate'],'paymentAccountId'=>$old['paymentAccountId'],'payee'=>$old['payee'],'payeeId'=>$old['payeeId']??'','reference'=>$old['reference']??'','paymentNarration'=>$old['paymentNarration']??'','expenseLines'=>$rows,'amount'=>$item['principal'],'reason'=>'Reclassify NPSS to Online Banking Charges and record 5% input VAT','bankPaymentMethod'=>$old['bankPaymentMethod']??'ONLINE_BANKING','bankPaymentDate'=>$old['bankPaymentDate']??$old['paymentDate'],'bankReference'=>$old['bankReference']??'','chequeNo'=>$old['chequeNo']??'','chequeDate'=>$old['chequeDate']??'','addBankCharge'=>true,'extraBankChargeNative'=>$item['charge']];
            $tracking=tt_accounts_bank_payment_details($body);$beforeIds=array_keys($working['journals']);$result=dex_amend($working,'TG',$body,$user,ev1_names(),$tracking);tt_accounts_track_bank_payment($working,$beforeIds,$tracking);
            $audit['npss'][]=['postId'=>$item['postId'],'date'=>$item['date'],'payee'=>$item['payee'],'principal'=>$item['principal'],'charge'=>$item['charge'],'vat'=>$item['vat'],'bankDebit'=>round($item['principal']+$item['charge']+$item['vat'],2),'before'=>$old,'replacement'=>$result];
        }
    }
    // Include replacement fee/VAT journals created above, so one reviewed action completes the repair.
    $nativePlan=$plan['npss']?tglr_plan($working)['native']:$plan['native'];
    foreach($nativePlan as $item){
        // Historical originals remain immutable; only their active replacements can be stamped.

        if(!tglr_active($working['journals'][$item['journalId']]))continue;
        foreach($item['changes'] as $change)$working['journals'][$item['journalId']]['lines'][$change['line']]=array_replace($change['before'],$change['fields']);
        $working['journals'][$item['journalId']]['meta']['nativeCurrencyRepairAt']=$audit['at'];$audit['native'][]=$item;
    }
    if($audit['native']||$audit['npss']){$working['tgLedgerRepairAudit'][]=$audit;$working['revision']=(int)($working['revision']??0)+1;}
    $store=$working;return $audit;
}
