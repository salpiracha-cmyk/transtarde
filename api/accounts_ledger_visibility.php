<?php
declare(strict_types=1);

/** Cash custody identity shared by office balances and individual cash ledgers.
 * Unlabelled historical cash belongs to the office; mill/staff custody stays separate.
 */
function alv_cash_id(array $line,string $entity):string {
    if(!empty($line['millLocationId']))return 'CASH|'.$entity.'|MILL|'.$line['millLocationId'];
    $id=(string)($line['cashAccountId']??$line['paymentAccountId']??'');
    return $id==='CASH|'.$entity||str_starts_with($id,'CASH|'.$entity.'|')?$id:'CASH|'.$entity;
}

function alv_posted(array $journal):bool {return ($journal['status']??'')==='Posted'||(($journal['status']??'')==='Posted / Bill Pending'&&($journal['sourceType']??'')==='EX_MILL_PURCHASE_LIABILITY');}

/** Correct effective cancellation dates of proven amendments/deletions in report copies.
 * Legacy expense edits cancelled on the edit day, double-counting backdated
 * replacements in earlier reports. Stored dates, createdAt and audit stay intact.
 * Ordinary financial reversals and unverified links retain their dated effect.
 */
function alv_amendment_books(array $store):array {
    $journals=(array)($store['journals']??[]);$reversals=[];
    foreach($journals as $id=>$j)if(is_array($j)){
        $parent=(string)($j['reversalOf']??$j['meta']['reversalOf']??'');
        if($parent!=='')$reversals[$parent][]=(string)$id;
    }
    foreach($journals as $id=>$original){
        if(!is_array($original)||!alv_posted($original)||!empty($original['reversalOf']))continue;
        if(count($reversals[(string)$id]??[])!==1)continue;
        $rid=$reversals[(string)$id][0];$reverse=$journals[$rid];
        // Native expense deletion is an explicit removal from books, not a later refund.
        $deleted=($reverse['sourceType']??'')==='EXPENSE_DELETION'
            &&($original['reversedByPostId']??'')===$rid
            &&trim((string)($reverse['meta']['reason']??''))!=='';
        if(!$deleted){
            $next=(string)($original['amendedByPostId']??'');$replacement=$journals[$next]??null;
            if(!is_array($replacement)||!alv_posted($replacement)||!empty($replacement['reversalOf'])||($original['entity']??'')!==($replacement['entity']??''))continue;
            $parent=(string)($replacement['meta']['amendmentOfPostId']??$replacement['meta']['amendmentOf']??'');
            if($parent!==(string)$id)continue;
        }
        if(!alv_posted($reverse)||($reverse['entity']??'')!==($original['entity']??'')||!empty($reverse['amendedByPostId'])||!empty($reversals[$rid]))continue;
        $date=(string)($original['date']??'');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)continue;
        $legs=[];
        foreach([$original,$reverse] as $entry)foreach((array)($entry['lines']??[]) as $line){
            if(!is_array($line))continue;
            // Legacy reopen reversals omit the original journal's bank/currency
            // context. Inherit missing context only inside this proven pair.
            $meta=(array)($entry['meta']??[])+(array)($original['meta']??[]);
            $key=json_encode([(string)($line['account']??''),(string)($line['bankAccountId']??$meta['bankAccountId']??''),(string)($line['cashAccountId']??''),(string)($line['subaccountId']??''),(string)($line['currency']??$meta['currency']??''),strtolower(trim((string)($line['supplier']??$line['broker']??$line['customer']??$line['counterparty']??$line['party']??$line['subledger']??$line['person']??'')))]);
            foreach([['debit','credit'],['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr]){
                $k=$key.'|'.$dr;$legs[$k]=($legs[$k]??0)+(int)round(100*((float)($line[$dr]??0)-(float)($line[$cr]??0)));
            }
        }
        if(!$legs||array_filter($legs,static fn($net)=>$net!==0))continue;
        $store['journals'][$rid]['date']=$date;
    }
    return $store;
}

/** Read-only cancellation projection. Never removes or changes audit journals.
 * A later reversal does not suppress the original on an earlier as-of date.
 * Only complete, same-entity, opposite accounting legs are suppressed together.
 */
function alv_hidden(array $store, string $asOf = ''): array {
    $store=alv_amendment_books($store);
    $journals=(array)($store['journals']??[]);$hidden=[];
    foreach($journals as $id=>$j)if(is_array($j)&&!empty($j['meta']['deletedFromBooks']))$hidden[(string)($j['id']??$id)]=true;
    uasort($journals,static fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??''))?:strnatcmp((string)($b['id']??''),(string)($a['id']??'')));
    foreach($journals as $id=>$j) {
        if(!is_array($j)||!alv_posted($j))continue;
        $originalId=(string)($j['reversalOf']??$j['meta']['reversalOf']??'');
        $original=$journals[$originalId]??null;
        if(isset($hidden[(string)($j['id']??$id)])||isset($hidden[$originalId]))continue;
        if($originalId===''||!is_array($original)||!alv_posted($original)
            ||($original['entity']??'')!==($j['entity']??'')
            ||($asOf!==''&&(($j['date']??'')>$asOf||($original['date']??'')>$asOf)))continue;
        $legs=[];
        foreach([$original,$j] as $entry)foreach((array)($entry['lines']??[]) as $line) {
            if(!is_array($line))continue;
            $key=json_encode([(string)($line['account']??''),(string)($line['bankAccountId']??''),(string)($line['cashAccountId']??''),(string)($line['subaccountId']??''),strtolower(trim((string)($line['supplier']??$line['broker']??$line['customer']??$line['counterparty']??$line['party']??$line['subledger']??$line['person']??'')))]);
            $legs[$key]=($legs[$key]??0)+(int)round(100*((float)($line['debit']??0)-(float)($line['credit']??0)));
        }
        if(!$legs||array_filter($legs,static fn($net)=>$net!==0))continue;
        $hidden[$originalId]=true;$hidden[(string)($j['id']??$id)]=true;
    }
    return $hidden;
}
function alv_record_hidden(array $record,array $hidden):bool {
    if(in_array(strtolower((string)($record['status']??'')),['cancelled','canceled','deleted','reversed'],true))return true;
    $ids=array_filter(array_merge([(string)($record['journalId']??'')],(array)($record['postingJournalIds']??[])));
    return $ids && !array_diff($ids,array_keys($hidden));
}

/** Party trial balance retains each account's party legs and an unassigned row.
 * It includes both sides of journals, so its totals remain a full trial balance.
 */
function alv_party_trial(array $journals,array $catalog):array {
    $groups=[];
    foreach($journals as $j)foreach((array)($j['lines']??[]) as $line) {
        if(!is_array($line)||trim((string)($line['account']??''))==='')continue;
        $code=(string)$line['account'];
        $party=trim((string)($line['supplier']??$line['broker']??$line['customer']??$line['counterparty']??$line['party']??$line['subledger']??$line['person']??''));
        // Never attribute bank/expense/contra legs to a journal-wide payee.
        $party=preg_replace('/\s+/u',' ',$party)?:'Unassigned / company';
        $key=$code.'|'.(function_exists('mb_strtolower')?mb_strtolower($party,'UTF-8'):strtolower($party));
        $groups[$key]??=['account'=>$code,'name'=>(string)($catalog[$code]['name']??$line['accountName']??$code),'class'=>(string)($catalog[$code]['class']??'Other'),'party'=>$party,'net'=>0];
        $groups[$key]['net']+=(int)round(100*((float)($line['debit']??0)-(float)($line['credit']??0)));
    }
    $rows=[];foreach($groups as $row){$net=$row['net'];unset($row['net']);if(!$net)continue;$row['debit']=$net>0?$net/100:0;$row['credit']=$net<0?-$net/100:0;$rows[]=$row;}
    usort($rows,static fn($a,$b)=>strnatcasecmp($a['party'],$b['party'])?:strnatcmp($a['account'],$b['account']));
    return $rows;
}
