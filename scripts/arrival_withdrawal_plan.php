<?php
declare(strict_types=1);
require_once __DIR__.'/arrival_dependency_core.php';
require_once dirname(__DIR__).'/api/arrival_withdrawal_core.php';

/** Pure, fail-closed plan. Current books and original Sodas are never changed. */
function arrival_withdrawal_plan(array $operations,array $accounts,string $now): array {
    $audit=arrival_dependency_audit((array)($operations['values']??[]),$accounts);
    if (!$audit['identityVerified']) throw new DomainException(implode('; ',$audit['errors']));
    foreach (tt_withdrawn_arrivals() as $expected) foreach (['id','queueId','bridgeId'] as $key) {
        if ((string)($audit['targets'][$expected['slip']][$key]??'')!==$expected[$key]) throw new DomainException('Stable arrival identity changed.');
    }
    foreach ($audit['references'] as $r) {
        if ($r['store']==='accounts'||!in_array($r['collection'],['tt30slips','tt30queue','tt39accountsoutbox'],true)) throw new DomainException('Linked record requires separate reconciliation: '.$r['store'].'/'.$r['collection']);
    }
    if ($audit['financialLinks']) throw new DomainException('A bill or payment depends on this receipt.');
    foreach ($audit['outboxAcknowledgements'] as $ack) if ($ack['currentJournalExists']) throw new DomainException('An acknowledged Post ID currently exists; review it before withdrawal.');
    $removed=[];
    foreach (['tt30slips','tt30queue','tt39accountsoutbox'] as $key) {
        $rows=json_decode((string)($operations['values'][$key]??'[]'),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($rows)||!array_is_list($rows)) throw new DomainException('Expected a list for '.$key);
        $kept=[];
        foreach ($rows as $row) {
            if (tt_is_withdrawn_arrival($row)) $removed[$key][]=$row;else $kept[]=$row;
        }
        if ($key==='tt30slips'&&count($removed[$key]??[])!==3) throw new DomainException('Expected exactly three test arrivals.');
        if ($key==='tt30queue'&&count($removed[$key]??[])!==3) throw new DomainException('Expected exactly three linked arrival-list rows.');
        // The read-only production trace reviewed six old acknowledgements.
        if ($key==='tt39accountsoutbox'&&count($removed[$key]??[])!==6) throw new DomainException('Outbox dependencies changed since review.');
        $operations['values'][$key]=json_encode($kept,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $operations['meta'][$key]=['version'=>(int)($operations['meta'][$key]['version']??0)+1,'updatedAt'=>$now,'updatedBy'=>'Owner-authorised test-arrival withdrawal','userId'=>0,'module'=>'Super Admin'];
        $operations['revision']=(int)($operations['revision']??0)+1;
    }
    $operations['arrivalWithdrawalAudit'][]=['at'=>$now,'reason'=>'Owner identified PH-00001/2/3 as disposable test arrivals; retain original Sodas and all current Accounts books.','records'=>array_map('count',$removed),'removedKg'=>119500,'removedBags'=>1300];
    return ['operations'=>$operations,'removed'=>$removed,'accountsChanged'=>false];
}
