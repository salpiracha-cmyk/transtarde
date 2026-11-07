<?php
declare(strict_types=1);

/** Legacy number-only saves cannot be accepted by sync_lifting (positive kg required). */
function eq_draft(array $item): bool {
    $b=$item['body']??null;
    return ($item['kind']??'')==='exMillLifting' && is_array($b)
        && isset($b['kg']) && is_numeric($b['kg']) && (float)$b['kg']===0.0
        && empty($b['reversed']);
}

/** Retire only impossible draft messages; preserve originals and all accounting facts. */
function eq_retire(array &$store,string $actor): array {
    $retired=0;$matched=0;
    foreach((array)($store['bridgeOutbox']??[]) as $key=>$item){
        if(!is_array($item)||!eq_draft($item)||($item['status']??'Pending')==='Posted')continue;
        // An existing archive collision is held, never overwritten.
        if(isset($store['bridgeOutboxRetired'][$key]))continue;
        $b=$item['body'];$journalIds=[];
        foreach((array)($store['purchaseSodaLiftings']??[]) as $l){
            if(!is_array($l)||!empty($l['reversed'])||($l['entity']??'')!==($b['entity']??'')||($l['sourceSodaId']??'')!==($b['sourceSodaId']??'')||strtoupper(trim((string)($l['container']??'')))!==strtoupper(trim((string)($b['container']??''))))continue;
            $j=$store['journals'][$l['journalId']??'']??null;
            if(is_array($j)&&($j['sourceType']??'')==='EX_MILL_PURCHASE_LIABILITY'&&($j['entity']??'')===($l['entity']??'')&&($j['meta']['sourceKey']??'')===($l['sourceKey']??'')&&round((float)($j['totalDebit']??-1),2)===round((float)($l['provisionalAmount']??-2),2))$journalIds[]=$j['id'];
        }
        $store['bridgeOutboxRetired'][$key]=[
            'original'=>$item,'retiredAt'=>gmdate('c'),'retiredBy'=>$actor,
            'reason'=>'Legacy container-only draft; no positive weight, never eligible for Accounts posting. Native loading save owns the liability.',
            'existingJournalIds'=>array_values(array_unique($journalIds)),
        ];
        unset($store['bridgeOutbox'][$key]);$retired++;if($journalIds)$matched++;
    }
    return ['retiredDrafts'=>$retired,'draftsWithExistingPost'=>$matched];
}
