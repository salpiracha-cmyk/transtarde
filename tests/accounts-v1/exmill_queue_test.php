<?php
require __DIR__.'/../../api/exmill_queue_core.php';
function check($v,$message){if(!$v)throw new Exception($message);}
$draft=['kind'=>'exMillLifting','status'=>'Pending','body'=>['kg'=>0,'entity'=>'TTI','sourceSodaId'=>'S1','container'=>'ABCD1234567']];
$store=['revision'=>7,'bridgeOutbox'=>['D'=>$draft,'positive'=>array_replace_recursive($draft,['body'=>['kg'=>27000]]),'other'=>array_replace($draft,['kind'=>'accountEvent']),'posted'=>array_replace($draft,['status'=>'Posted']),'unknown'=>array_replace_recursive($draft,['body'=>['kg'=>'bad']])],'journals'=>['J'=>['id'=>'J','entity'=>'TTI','sourceType'=>'EX_MILL_PURCHASE_LIABILITY','meta'=>['sourceKey'=>'EXMILL|S1|CN-ABCD1234567'],'totalDebit'=>100]],'purchaseSodaLiftings'=>[['entity'=>'TTI','sourceSodaId'=>'S1','container'=>'ABCD1234567','sourceKey'=>'EXMILL|S1|CN-ABCD1234567','journalId'=>'J','provisionalAmount'=>100]],'events'=>['keep'=>1],'other'=>['keep'=>2]];
$before=$store;$r=eq_retire($store,'Fixture');check($r===['retiredDrafts'=>1,'draftsWithExistingPost'=>1],'Exact zero draft retired');check($store['bridgeOutboxRetired']['D']['original']===$draft,'Original retained');check($store['bridgeOutboxRetired']['D']['existingJournalIds']===['J'],'Existing post linked');
foreach(['journals','purchaseSodaLiftings','events','other','revision'] as $k)check($store[$k]===$before[$k],'Financial source unchanged');
foreach(['positive','other','posted','unknown'] as $k)check($store['bridgeOutbox'][$k]===$before['bridgeOutbox'][$k],'Unrelated message preserved');
$once=$store;check(eq_retire($store,'Retry')['retiredDrafts']===0&&$store===$once,'Retry no-op');
$store['bridgeOutbox']['D']=$draft;eq_retire($store,'Old browser');check(isset($store['bridgeOutbox']['D'])&&$store['bridgeOutboxRetired']['D']===$once['bridgeOutboxRetired']['D'],'Archive collision never overwritten');
foreach([null,'',-1,'bad',27] as $kg)check(!eq_draft(['kind'=>'exMillLifting','body'=>['kg'=>$kg]]),'Invalid or positive weight not retired');
echo "PASS draft retirement, exact posting association, immutable financial sources, unrelated queues, retries and archive preservation\n";
