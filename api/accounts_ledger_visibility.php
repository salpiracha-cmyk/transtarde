<?php
declare(strict_types=1);

function alv_posted(array $journal):bool {return ($journal['status']??'')==='Posted'||(($journal['status']??'')==='Posted / Bill Pending'&&($journal['sourceType']??'')==='EX_MILL_PURCHASE_LIABILITY');}

/** Read-only cancellation projection. Never removes or changes audit journals.
 * A later reversal does not suppress the original on an earlier as-of date.
 * Only complete, same-entity, opposite accounting legs are suppressed together.
 */
function alv_hidden(array $store, string $asOf = ''): array {
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
