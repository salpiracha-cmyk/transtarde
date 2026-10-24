<?php
declare(strict_types=1);

$store = [];

function tt_mutate_store(callable $callback,bool $automaticBackup=true): void {
    global $store;
    $GLOBALS['postIdMutations'][]=$automaticBackup;
    $callback($store);
}

require dirname(__DIR__, 2) . '/master_store.php';

$first = tt_reserve_post_id('Accounts', 'Transport', ['year' => 2026]);
$second = tt_reserve_post_id('Mill', 'Arrival', ['year' => 2026]);
$nextYear = tt_reserve_post_id('Exports', 'Contract', ['year' => 2027]);

function post_id_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

post_id_check($first === 'POST-2026-00001', 'First yearly ID');
post_id_check($second === 'POST-2026-00002', 'Shared sequence across modules');
post_id_check($nextYear === 'POST-2027-00001', 'Sequence resets in a new year');
post_id_check(tt_reserve_post_id('Accounts', 'Journal', ['year' => 2026]) === 'POST-2026-00003', 'Older year continues independently');
post_id_check(tt_post_id_matches_search($second, '2'), 'Short number search');
post_id_check(tt_post_id_matches_search($second, '00002'), 'Padded number search');
post_id_check(!tt_post_id_matches_search($second, '100'), 'Unrelated number search');
post_id_check(tt_format_post_id(100, 2026) === 'POST-2026-00100', 'Formatted ID');
try {
    tt_format_post_id(100000, 2026);
    throw new RuntimeException('Overflow must be rejected');
} catch (RuntimeException $error) {
    post_id_check($error->getMessage() === 'Yearly Post ID limit reached.', 'Overflow is rejected');
}

$before=count($GLOBALS['postIdMutations']);$block=tt_reserve_post_id_block(['POST-'.date('Y').'-00080'=>['id'=>'POST-'.date('Y').'-00080']],4);
post_id_check(count($GLOBALS['postIdMutations'])===$before+1,'A correction batch uses one sequence write');
post_id_check(end($GLOBALS['postIdMutations'])===false,'No backup may run under the Accounts lock');
post_id_check($block[0]==='POST-'.date('Y').'-00081'&&$block[3]==='POST-'.date('Y').'-00084','Batch uses the shared yearly counter');
post_id_check(tt_next_post_id([], 'Mill','Arrival')==='POST-'.date('Y').'-00085','Other modules continue after the reserved block');
echo "Universal post ID sequence test passed.\n";

post_id_check(tt_format_post_id(2,26)==='POST-0026-00002','Year always has four digits');
post_id_check(tt_next_post_id(['POST-0026-00008'=>['id'=>'POST-0026-00008']], 'Accounts','Journal','0026-09-22')==='POST-0026-00009','Existing padded-year IDs set the sequence minimum');

require dirname(__DIR__,2).'/api/accounts_posting_preview.php';
function tt_accounts_input():string {return json_encode(['preview'=>$GLOBALS['sequencePreview']??false]);}
$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['sequencePreview']=true;
$original=serialize($store);$mutations=count($GLOBALS['postIdMutations']);
$tentative=tt_next_post_id(['POST-2026-00090'=>['id'=>'POST-2026-00090']],'Accounts','Review','2026-10-07');
$block=tt_reserve_post_id_block([],2);
post_id_check(serialize($store)===$original&&count($GLOBALS['postIdMutations'])===$mutations,'Preview must not reserve Post IDs or write the shared sequence');
post_id_check($tentative==='POST-2026-00091'&&count(array_unique(array_merge([$tentative],$block)))===3,'Preview journal and batch identities remain distinct');
$GLOBALS['sequencePreview']=false;
$real=tt_reserve_post_id('Accounts','Actual commit',['year'=>2026]);
post_id_check($real==='POST-2026-00086','The real sequence does not consume tentative review IDs');
post_id_check(end($GLOBALS['postIdMutations'])===false,'Post ID allocation must not start a secondary reader under the financial lock');
echo "Posting preview leaves the real universal sequence unchanged.\n";
