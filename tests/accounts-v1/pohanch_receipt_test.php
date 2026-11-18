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
    $books = ['revision'=>0,'journals'=>[],'purchaseSodas'=>['soda-1'=>['entity'=>'TTI',
        'sodaNo'=>'fixture-soda','commodity'=>'RICE','rate'=>105,'locationId'=>'mill-1','broker'=>'Fixture Broker']]];
    $path = TT_DATA_DIR.'/accounts.json';
    file_put_contents($path,json_encode($books));
    $first = pr_reconcile();
    check(count($first)===1 && $first[0]['amount']===4960642.5,'Expected historical rate, KAT and marked slip only.');
    $posted = file_get_contents($path);
    check(pr_reconcile()[0]['status']==='existing' && $posted===file_get_contents($path),'Retry duplicated a receipt or changed books.');
    $changes = array_replace($record,['unloadingDate'=>'2026-11-10','bags'=>490,'payableWeight'=>50000,'brokenKat'=>80]);
    $amended = pr_stamp_receipt_requests($marked,json_encode([$changes]),['id'=>'arrival-1'],$actor);
    pr_validate_amendments($marked,$amended);
    check(file_get_contents($path)===$posted,'Validation must not write the ledger before Mill saves.');
    $record = json_decode($amended,true)[0];
    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>['tt30slips'=>json_encode([$record,$old])]]));
    $after = pr_reconcile();
    check($after[0]['status']==='amended' && $after[0]['journalId']==='FIXTURE-1','Amendment must update the original receipt.');
    $amendedBooks = json_decode(file_get_contents($path),true);
    $receipt = $amendedBooks['journals']['FIXTURE-1'];
    check($receipt['date']==='2026-11-10' && $receipt['meta']['bags']===490 &&
        $receipt['meta']['payableWeightKg']===50000 && $receipt['meta']['katPaisaPerKg']===80 &&
        $receipt['totalDebit']===4909018 && $receipt['totalCredit']===$receipt['totalDebit'], 'Saved receipt did not use the amended facts.');
    check(count($amendedBooks['workflowAudit'])===1 && $amendedBooks['workflowAudit'][0]['before']['meta']['bags']===500,
        'Original value must be auditable.');
    $amendedBytes = file_get_contents($path);
    check(pr_reconcile()[0]['status']==='existing' && file_get_contents($path)===$amendedBytes,'Amendment retry changed the books.');
    $withBill = $amendedBooks;
    $withBill['events']['TTI|COMMODITY_RECEIPT_ACCEPTED|POHANCH|1|fixture-1']['billId'] = 'BILL-1';
    file_put_contents($path,json_encode($withBill));
    $again=pr_stamp_receipt_requests($amended,json_encode([array_replace($record,['bags'=>480])]),['id'=>'arrival-1'],$actor);
    try {
        pr_validate_amendments($amended,$again);
        check(false,'Billed amendment must be rejected before Mill commits.');
    } catch (DomainException $error) {
        check(str_contains($error->getMessage(),'Accounts bill'),'Unexpected amendment rejection.');
    }
    file_put_contents($path,$amendedBytes);
    $old['printed'] = true;
    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>['tt30slips'=>json_encode([$record,$old])]]));
    $legacy = pr_reconcile();
    check(count($legacy)===2 && $legacy[1]['status']==='posted' && $legacy[1]['amount']===4960642.5,
        'An issued historical slip must recover automatically at its saved value.');
    $explicitRetry = pr_reconcile(['entity'=>'TTI','soda'=>'fixture-soda','pohanch'=>'PH-90002'],['name'=>'Fixture Accounts']);
    check($explicitRetry[0]['status']==='existing' && pr_reconcile()[1]['status']==='existing',
        'Retry must recognize the recovered historical receipt.');
    $saved = json_decode(file_get_contents($path),true);
    check(count($saved['journals'])===2 && count($saved['events'])===2,'Unexpected Accounts postings.');
    check($saved['journals']['FIXTURE-1']['totalDebit']===$saved['journals']['FIXTURE-1']['totalCredit'],'Unbalanced receipt.');
    check($saved['journals']['FIXTURE-1']['meta']['bags']===490, 'Receipt did not retain the bag count.');
    check(!isset($saved['commodityBills']), 'Receipt recovery must not post a bill.');
    $legacyIdentity = $saved;
    unset($legacyIdentity['postingIdentities']);
    foreach ($legacyIdentity['events'] as &$event) $event['sourceKey']='old-key';
    unset($event);
    check(pr_post_receipt($legacyIdentity,$old,[])['status']==='existing','Legacy reference was not recognized.');
    echo "PASS durable Pohanch marker, saved valuation, issued history recovery, audited amendment, retry and billed protection\n";
} finally {
    foreach (glob(TT_DATA_DIR.'/*') as $file) unlink($file);
    rmdir(TT_DATA_DIR);
}
