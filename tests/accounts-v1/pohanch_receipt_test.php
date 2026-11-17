<?php
declare(strict_types=1);

foreach (['HOST','NAME','USER','PASS'] as $key) putenv('TT_DB_'.$key);

define('TT_DATA_DIR', sys_get_temp_dir().'/pohanch-fixture-'.bin2hex(random_bytes(6)));
mkdir(TT_DATA_DIR, 0700);
require __DIR__.'/../../api/pohanch_receipt_core.php';

function tt_next_post_id(array $journals, mixed ...$args): string {return 'FIXTURE-'.(count($journals)+1);}
function check(bool $true, string $message): void {if (!$true) throw new RuntimeException($message);}

try {
    $row = ['id'=>'fixture-1','entity'=>'TTI','millId'=>1,'bridgeId'=>'fixture-1','queueId'=>'arrival-1',
        'slip'=>'PH-90001','soda'=>'fixture-soda','sourceSodaId'=>'soda-1','truck'=>'FIXTURE-TRUCK',
        'commodity'=>'RICE','locationId'=>'mill-1','unloadingDate'=>'2026-11-09','purchaseRate'=>99,
        'payableWeight'=>50500,'moistureKatKg'=>10,'brokenKat'=>75,'bags'=>500];
    $actor = ['id'=>10,'full_name'=>'Fixture operator'];
    $marked = pr_stamp_receipt_requests('[]',json_encode([$row]),['id'=>'arrival-1'],$actor);
    $record = json_decode($marked,true)[0];
    check($record[PR_RECEIPT_MARKER] === ['userId'=>10,'name'=>'Fixture operator'], 'Marker was not created.');
    check(pr_stamp_receipt_requests($marked,json_encode([$row]),null,[]) === $marked,'Subsequent saves lost the marker.');
    check(!isset(json_decode(pr_stamp_receipt_requests('[]',$marked,null,[]),true)[0][PR_RECEIPT_MARKER]),'Forged marker accepted.');
    $old = array_replace($row,['id'=>'fixture-2','bridgeId'=>'fixture-2','slip'=>'PH-90002','truck'=>'OTHER-TRUCK']);
    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>['tt30slips'=>json_encode([$record,$old])]]));
    $books = ['revision'=>0,'journals'=>[],'events'=>[],'purchaseSodas'=>['soda-1'=>['entity'=>'TTI',
        'sodaNo'=>'fixture-soda','commodity'=>'RICE','rate'=>105,'locationId'=>'mill-1','broker'=>'Fixture Broker']]];
    $path = TT_DATA_DIR.'/accounts.json';
    file_put_contents($path,json_encode($books));
    $first = pr_reconcile();
    check(count($first)===1 && $first[0]['amount']===4960642.5,'Expected historical rate, KAT and marked slip only.');
    $posted = file_get_contents($path);
    check(pr_reconcile()[0]['status']==='existing' && $posted===file_get_contents($path),'Retry duplicated a receipt or changed books.');
    $legacy = pr_reconcile(['entity'=>'TTI','soda'=>'fixture-soda','pohanch'=>'PH-90002'],['name'=>'Fixture Accounts']);
    check($legacy[0]['status']==='posted','Explicit historical recovery did not post.');
    $saved = json_decode(file_get_contents($path),true);
    check(count($saved['journals'])===2 && count($saved['events'])===2,'Unexpected Accounts postings.');
    check($saved['journals']['FIXTURE-1']['totalDebit']===$saved['journals']['FIXTURE-1']['totalCredit'],'Unbalanced receipt.');
    check($saved['journals']['FIXTURE-1']['meta']['bags']===500, 'Receipt did not retain the bag count.');
    check(!isset($saved['commodityBills']), 'Receipt recovery must not post a bill.');
    $legacyIdentity = $saved;
    unset($legacyIdentity['postingIdentities']);
    foreach ($legacyIdentity['events'] as &$event) $event['sourceKey']='old-key';
    unset($event);
    check(pr_post_receipt($legacyIdentity,$old,[])['status']==='existing','Legacy reference was not recognized.');
    echo "PASS durable Pohanch marker, saved valuation, explicit historical restore and duplicate protection\n";
} finally {
    foreach (glob(TT_DATA_DIR.'/*') as $file) unlink($file);
    rmdir(TT_DATA_DIR);
}
