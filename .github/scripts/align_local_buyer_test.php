<?php
declare(strict_types=1);

define('TT_MLA_LIBRARY', true);
require __DIR__.'/align_local_buyer.php';
require dirname(__DIR__, 2).'/inventory_reconciliation.php';

$old = 'Fixture Buyer';
$buyer = 'Fixture Buyer Powder';
$soda = 'LS-TEST';
$prefix = 'TTI|'.$soda.'|';
$ids = ['fixture-sale', 'fixture-receipt', 'fixture-application'];
$amount = 873.21;
$plan = ['sale'=>mla_key($prefix.$buyer), 'alias'=>mla_key($prefix.$buyer.'|'.$old),
    'posts'=>array_combine(MLA_TYPES, array_map(static fn(string $id): string => mla_key($prefix.$id), $ids)),
    'amount'=>mla_key($prefix.$buyer.'|87321')];
$source = [['entity'=>'TTI', 'soda'=>$soda, 'party'=>$buyer, 'product'=>'Powder']];
$line = static fn(string $account, float $dr, float $cr, array $details=[]): array =>
    ['account'=>$account,'debit'=>$dr,'credit'=>$cr]+$details;
$party = ['party'=>$old, 'counterparty'=>$old, 'subledger'=>$old];
$bankLine = $line('1110', $amount, 0, ['bankAccountId'=>'test-bank','date'=>'2026-10-13']);
$journal = static fn(int $i, string $name, array $lines): array =>
    ['id'=>$ids[$i], 'sourceType'=>MLA_TYPES[$i], 'entity'=>'TTI','status'=>'Posted',
    'date'=>'2026-10-13','meta'=>['soda'=>$soda,'party'=>$name], 'narration'=>'Powder — '.$name,
    'totalDebit'=>$amount,'totalCredit'=>$amount,'lines'=>$lines];
$books = ['revision'=>1,'journals'=>[
    $ids[0]=>$journal(0,$old,[$line('1220',$amount,0,$party),$line('4300',0,$amount,['subledger'=>'Powder Sales','party'=>$old])]),
    $ids[1]=>$journal(1,$buyer,[$bankLine,$line('2510',0,$amount,['party'=>$buyer,'subledger'=>'By-product Buyer Advances'])]),
    $ids[2]=>$journal(2,$buyer,[$line('2510',$amount,0,['party'=>$buyer]),$line('1220',0,$amount,['party'=>$buyer])]),
    'unrelated'=>$journal(0,$old,[$line('1220',$amount,0,$party)]),
], 'localSalesCandidates'=>['candidate'=>['party'=>$old,'journalId'=>$ids[0],'entity'=>'TTI','soda'=>$soda]]];
$before = $books;
$result = mla_align($books,$source,$plan);
mla_require($result === ['result'=>'aligned','verifiedPosts'=>3,'receivableCleared'=>true,
    'advanceCleared'=>true,'bankLinePreserved'=>true], 'ALIGNMENT_RESULT');
mla_require($books['revision'] === 2 && count($books['journals']) === count($before['journals']), 'DUPLICATE_JOURNAL');
mla_require($books['journals']['unrelated'] === $before['journals']['unrelated'], 'UNRELATED_JOURNAL_CHANGED');
mla_require($books['journals'][$ids[1]]['lines'][0] === $bankLine, 'BANK_EDITED');
mla_require($books['journals'][$ids[0]]['lines'][1]['subledger'] === 'Powder Sales', 'REVENUE_SUBSIDIARY_CHANGED');
mla_require($books['localSalesCandidates']['candidate']['party'] === $buyer, 'HANDOFF_NOT_ALIGNED');
foreach ($ids as $id) {
    mla_require($books['journals'][$id]['meta']['party'] === $buyer, 'BUYER_NOT_ALIGNED');
    foreach (['id','date','sourceType','totalDebit','totalCredit'] as $field) {
        mla_require($books['journals'][$id][$field] === $before['journals'][$id][$field], 'JOURNAL_FACT_CHANGED');
    }
}
foreach (['1220','2510'] as $control) {
    $balance = 0.;
    foreach ($ids as $id) foreach ($books['journals'][$id]['lines'] as $line) {
        if ($line['account'] !== $control) continue;
        mla_require($line['party'] === $buyer, 'CONTROL_PARTY_NOT_ALIGNED');
        $balance += $line['debit'] - $line['credit'];
    }
    mla_require($balance === 0.0, 'CONTROL_NOT_CLEARED');
}
mla_require($books['localBuyerAlignmentAudit'][0]['before']['journals'][$ids[0]]['lines'] === $before['journals'][$ids[0]]['lines'], 'AUDIT_ORIGINAL_MISSING');
$after = $books;
mla_require(mla_align($books,$source,$plan)['result'] === 'already_aligned' && $books === $after, 'RETRY_WROTE_BOOKS');
foreach (['changed_amount','different_company','touched_by_reversal','different_buyer'] as $case) {
    $invalid = $before;
    $badSource = $source;
    if ($case === 'changed_amount') $invalid['journals'][$ids[2]]['lines'][1]['credit'] = 1;
    if ($case === 'different_company') $invalid['journals'][$ids[1]]['entity'] = 'BRM';
    if ($case === 'touched_by_reversal') $invalid['journals']['unrelated']['reversalOf'] = $ids[0];
    if ($case === 'different_buyer') $badSource[0]['party'] = 'Other fixture';
    $initial = $invalid;
    try {
        mla_align($invalid,$badSource,$plan);
    } catch (RuntimeException) {
        mla_require($invalid === $initial, 'REFUSED_PLAN_WROTE_BOOKS');
        continue;
    }
    throw new RuntimeException('UNSAFE_ALIGNMENT_WAS_ACCEPTED');
}
echo "PASS canonical Mill buyer, clearing controls, preserved bank and journal facts, audit, idempotency and rejected mismatches\n";
