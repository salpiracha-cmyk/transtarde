<?php
declare(strict_types=1);

/** Exact, owner-identified disposable receipts; source Sodas are not withdrawn. */
function tt_withdrawn_arrivals(): array {
    return [
        ['slip'=>'PH-00001','id'=>'1790152499385','queueId'=>'1790152272787','bridgeId'=>'PHB-1790152499385-6m0l1s'],
        ['slip'=>'PH-00002','id'=>'1790152521608','queueId'=>'1790152314316','bridgeId'=>'PHB-1790152521608-njcg2b'],
        ['slip'=>'PH-00003','id'=>'1790152550416','queueId'=>'1790152351681','bridgeId'=>'PHB-1790152550416-6ad87u'],
    ];
}

function tt_is_withdrawn_arrival(mixed $value): bool {
    if (is_array($value)) {foreach ($value as $child) if (tt_is_withdrawn_arrival($child)) return true;return false;}
    if (!is_scalar($value)) return false;
    foreach (tt_withdrawn_arrivals() as $row) foreach (['id','queueId','bridgeId'] as $key) {
        if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($row[$key],'/').'(?![A-Za-z0-9])/',(string)$value)) return true;
    }
    return false;
}

function tt_guard_withdrawn_arrival(mixed $row): void {
    if (tt_is_withdrawn_arrival($row)) throw new DomainException('This test arrival was withdrawn by the owner. Reopen the latest shared records; it cannot be restored by a cached handoff.');
}

function tt_guard_arrival_collection(string $key,string $old,string $incoming): void {
    if ($key==='tt40arrivalwithdrawals') throw new DomainException('Arrival withdrawal history is maintained by the controlled recovery workflow.');
    if (!in_array($key,['tt30slips','tt30queue','tt39accountsoutbox'],true)) return;
    $rows=json_decode($incoming,true,512,JSON_THROW_ON_ERROR);$before=json_decode($old,true)?:[];
    if (!is_array($rows)) throw new DomainException('Arrival rows must be an array.');
    // A normal unrelated save may retain pre-cleanup rows unchanged. It may not
    // amend, recreate or requeue a withdrawn identity.
    $known=[];foreach ($before as $row) if (tt_is_withdrawn_arrival($row)) $known[]=json_encode($row,JSON_THROW_ON_ERROR);
    foreach ($rows as $row) if (tt_is_withdrawn_arrival($row)&&!in_array(json_encode($row,JSON_THROW_ON_ERROR),$known,true)) tt_guard_withdrawn_arrival($row);
}
