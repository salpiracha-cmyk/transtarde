<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/scripts/post_id_migration_core.php';

function migration_check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}

$accounts=[
    'revision'=>7,
    'journals'=>[
        'AUTO-2026-00049'=>['id'=>'AUTO-2026-00049','date'=>'2026-10-02','reference'=>'205','narration'=>'Transport bill'],
        'AUTO-2026-000050'=>['id'=>'AUTO-2026-000050','date'=>'2026-10-02','reference'=>'58129036','narration'=>'Payment'],
        'JV-2026-000002'=>['id'=>'JV-2026-000002','date'=>'2026-10-03','reference'=>'ADJ','narration'=>'Adjustment'],
        '205'=>['id'=>'205','date'=>'2026-10-04','reference'=>'205','narration'=>'Supplier reference 205'],
        'POST-2026-00012'=>['id'=>'POST-2026-00012','date'=>'2026-09-30','reference'=>'JI-8240/26','narration'=>'Freight'],
    ],
    'supplierBills'=>['TRB-2026-00002'=>['postingJournalIds'=>['AUTO-2026-00049']],'205'=>['id'=>'205','reference'=>'205','journalId'=>'205']],
    'supplierSettlements'=>['SP-2026-000001'=>['journalId'=>'AUTO-2026-000050','note'=>'Paid by AUTO-2026-000050']],
    'jvDrafts'=>['JVD-2026-000001'=>['journalId'=>'JV-2026-000002']],
];
$plan=tt_post_migration_plan($accounts);
migration_check($plan['map']['AUTO-2026-00049']==='POST-2026-00049','Transport bill number must be preserved.');
migration_check($plan['map']['AUTO-2026-000050']==='POST-2026-00050','Payment number must be normalized to five digits.');
migration_check($plan['map']['JV-2026-000002']==='POST-2026-00051','Conflicting legacy IDs must receive the next free universal number.');
migration_check($plan['map']['205']==='POST-2026-00052','Numeric journal IDs must receive the next universal number.');
$migrated=tt_post_migration_apply($accounts,$plan);tt_post_migration_validate($migrated,$plan);
migration_check($migrated['supplierBills']['TRB-2026-00002']['postingJournalIds'][0]==='POST-2026-00049','Supplier bill link must migrate.');
migration_check($migrated['supplierSettlements']['SP-2026-000001']['journalId']==='POST-2026-00050','Payment link must migrate.');
migration_check($migrated['supplierSettlements']['SP-2026-000001']['note']==='Paid by AUTO-2026-000050','Free-text audit notes must remain unchanged.');
migration_check(isset($migrated['supplierBills']['205'])&&$migrated['supplierBills']['205']['reference']==='205','Supplier bill keys and references must remain unchanged.');
migration_check($migrated['supplierBills']['205']['journalId']==='POST-2026-00052','Only the supplier bill journal link must migrate.');
migration_check($migrated['journals']['POST-2026-00049']['legacyPostId']==='AUTO-2026-00049','Legacy ID must remain available for audit.');
migration_check(tt_post_migration_plan($migrated)['map']===[],'Migration must be idempotent.');
echo "Post ID migration test passed.\n";
