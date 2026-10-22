<?php
declare(strict_types=1);

/** TG-only historical migration. No operational, stock, supplier mirror or AED-bank writes. */
function tgh_cents(mixed $value): int {
    if (!is_string($value) || !preg_match('/^\d{1,10}\.\d{2}$/D', $value)) throw new DomainException('Amounts must use an exact USD amount with two decimals.');
    [$whole, $fraction] = explode('.', $value);
    return ((int)$whole * 100) + (int)$fraction;
}
function tgh_text(mixed $value, int $limit): string {
    if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) throw new DomainException('Invalid historical entry text.');
    return trim($value);
}
function tgh_bank_balance(array $store, string $through): int {
    $total = 0;
    foreach (($store['journals'] ?? []) as $journal) {
        if (($journal['entity'] ?? '') !== 'TG' || ($journal['status'] ?? '') !== 'Posted' || ($journal['date'] ?? '') > $through) continue;
        foreach (($journal['lines'] ?? []) as $line) {
            if (($line['account'] ?? '') !== '1110' || ($line['bankAccountId'] ?? '') !== 'banks-2') continue;
            if (strtoupper((string)($line['currency'] ?? '')) !== 'USD' || !isset($line['bankDebit'], $line['bankCredit'])) throw new DomainException('A USD bank line is missing its original bank amounts. Review that posting first.');
            $total += (int)round(((float)$line['bankDebit'] - (float)$line['bankCredit']) * 100);
        }
    }
    return $total;
}
function tgh_prepare(array $store, array $plan, array $user, array $bank, float $rate): array {
    if (($user['role'] ?? '') !== 'Super Admin') throw new DomainException('Super Admin access is required for historical reconstruction.');
    if (($bank['id'] ?? '') !== 'banks-2' || ($bank['currency'] ?? '') !== 'USD' || $rate <= 0 || !is_finite($rate)) throw new DomainException('The TG USD bank and saved USD/AED master rate are required.');
    $batch = tgh_text($plan['batch'] ?? '', 80);
    if (!preg_match('/^[A-Za-z0-9._-]{12,80}$/D', $batch)) throw new DomainException('Enter a unique reconstruction batch reference.');
    $hash = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    if (isset($store['tgUsdHistoryBatches'][$batch])) {
        if (!hash_equals($store['tgUsdHistoryBatches'][$batch]['hash'], $hash)) throw new DomainException('This batch reference was already used with different entries.');
        return ['store' => $store, 'journals' => [], 'replayed' => true, 'result' => $store['tgUsdHistoryBatches'][$batch]];
    }
    $entries = $plan['entries'] ?? null;
    if (!is_array($entries) || count($entries) < 1 || count($entries) > 100) throw new DomainException('Provide between one and 100 historical entries.');
    $original = $store;
    $created = [];
    $keys = [];
    $bridgeSources = [];
    $names = ['1110'=>'Bank Accounts','1210'=>'Customer / Export Receivables','2110'=>'Commodity Supplier / Broker Payables','2510'=>'Customer Advances / Unapplied Receipts','1230'=>'Own-bank Currency Transfer Clearing','3400'=>'Opening Balance Clearing'];
    $now = gmdate('c');
    foreach ($entries as $entry) {
        if (!is_array($entry)) throw new DomainException('Invalid historical entry.');
        $key = tgh_text($entry['key'] ?? '', 100);
        if ($key === '' || isset($keys[$key])) throw new DomainException('Each entry needs a distinct source key.');
        $keys[$key] = true;
        foreach (($original['journals'] ?? []) as $prior) if (($prior['meta']['tgHistorySourceKey'] ?? '') === $key && ($prior['entity'] ?? '') === 'TG') throw new DomainException('Historical source '.$key.' is already recorded.');
        $kind = (string)($entry['kind'] ?? '');
        $date = (string)($entry['date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '2026-01-01' || $date > ($kind === 'BRIDGE' ? '2026-07-01' : '2026-06-30') || ($kind === 'BRIDGE' && $date !== '2026-07-01')) throw new DomainException('Use January–June 2026 dates; migration bridges use 1 July only.');
        $cents = tgh_cents($entry['amount'] ?? null);
        if ($cents <= 0) throw new DomainException('Every entry needs a positive USD amount.');
        $party = tgh_text($entry['party'] ?? '', 180);
        $reference = tgh_text($entry['reference'] ?? '', 180);
        $note = tgh_text($entry['narration'] ?? '', 500);
        if ($reference === '' || $note === '') throw new DomainException('Every entry needs its original reference and explanation.');
        $bankSide = null; $bridgeBook = null;
        switch ($kind) {
            case 'OPENING':
                if ($date !== '2026-01-01') throw new DomainException('Historical opening balances use 1 January 2026.');
                $debit = !empty($entry['bank']) ? '1110' : '1210'; $credit = '3400'; $bankSide = $debit === '1110' ? 'debit' : null; break;
            case 'INVOICE': $debit = '1210'; $credit = '3400'; break;
            case 'RECEIPT': $debit = '1110'; $credit = !empty($entry['advance']) ? '2510' : '1210'; $bankSide = 'debit'; break;
            case 'PAYMENT': $debit = '2110'; $credit = '1110'; $bankSide = 'credit'; break;
            case 'TRANSFER_CLEARING': $debit = '1110'; $credit = '1230'; $bankSide = 'debit'; break;
            case 'BRIDGE':
                if (($entry['account'] ?? '') === '1210') { $debit = '3400'; $credit = '1210'; }
                elseif (($entry['account'] ?? '') === '2510') { $debit = '2510'; $credit = '3400'; }
                else throw new DomainException('A bridge may only clear historical receivables or already-applied advances.');
                $source = (string)($entry['sourcePostId'] ?? '');
                if (isset($bridgeSources[$source])) throw new DomainException('An opening invoice may only be bridged once in this batch.');
                $bridgeSources[$source] = true;
                $sourceJournal = $original['journals'][$source] ?? null;
                if (!$sourceJournal || ($sourceJournal['entity'] ?? '') !== 'TG' || ($sourceJournal['status'] ?? '') !== 'Posted' || !in_array($sourceJournal['sourceType'] ?? '', ['OPENING_SHIPMENT_BALANCE','OPENING_BALANCE_BF','CARRY_FORWARD_OPENING','CARRY_FORWARD_SHIPMENT_OPENING'], true)) throw new DomainException('Choose an existing TG opening invoice journal as bridge evidence.');
                $supported = 0; $supportedBook = 0.0;
                foreach ($sourceJournal['lines'] ?? [] as $line) if (($line['account'] ?? '') === '1210' && ($line['currency'] ?? '') === 'USD' && strcasecmp((string)($line['counterparty'] ?? $line['subledger'] ?? ''), $party) === 0) { $supported += (int)round(((float)($line['nativeDebit'] ?? 0) - (float)($line['nativeCredit'] ?? 0)) * 100); $supportedBook += (float)($line['debit']??0)-(float)($line['credit']??0); }
                if ($supported < $cents) throw new DomainException('Bridge amount or customer does not match the original USD opening invoice.');
                if (($entry['account'] ?? '') === '1210') {
                    if ($supported !== $cents) throw new DomainException('Receivable bridges must cancel the exact original opening amount.');
                    $bridgeBook = round($supportedBook,2);
                }
                break;
            default: throw new DomainException('Unsupported historical entry type.');
        }
        if ($party === '' && !($kind === 'OPENING' && !empty($entry['bank']))) throw new DomainException('Name the customer, supplier or own-bank transfer.');
        $native = $cents / 100; $book = $bridgeBook ?? round($native * $rate, 2);
        $lines = [];
        foreach ([[$debit, true], [$credit, false]] as [$account, $dr]) {
            $line = ['account'=>$account,'accountName'=>$names[$account],'debit'=>$dr?$book:0,'credit'=>$dr?0:$book,'currency'=>'USD','nativeCurrency'=>'USD','nativeDebit'=>$dr?$native:0,'nativeCredit'=>$dr?0:$native,'exchangeRate'=>$book/$native];
            if (!in_array($account, ['1110','3400'], true)) { $line['counterparty']=$party; $line['subledger']=$party; }
            if ($account === '1110') $line += ['bankAccountId'=>'banks-2','bankName'=>$bank['bankName'],'bankAccountTitle'=>$bank['accountTitle'],'accountNumber'=>$bank['accountNumber'],'bankDebit'=>$dr?$native:0,'bankCredit'=>$dr?0:$native];
            $lines[] = $line;
        }
        $id = 'TG-HISTORY-PREVIEW-'.(count($created)+1);
        $journal = ['id'=>$id,'entity'=>'TG','date'=>$date,'status'=>'Posted','sourceType'=>'TG_USD_HISTORY_RECONSTRUCTION','reference'=>$reference,'narration'=>$note,'lines'=>$lines,'totalDebit'=>$book,'totalCredit'=>$book,'meta'=>['tgHistoryBatch'=>$batch,'tgHistorySourceKey'=>$key,'kind'=>$kind,'currency'=>'USD','historicalOnly'=>true,'noIntercompanyMirror'=>true,'sourcePostId'=>$entry['sourcePostId']??''],'createdAt'=>$now,'createdBy'=>$user['full_name']??$user['username']??'Super Admin','userId'=>(int)($user['id']??0),'approvedAt'=>$now,'approvedBy'=>$user['full_name']??'Super Admin','approvedByUserId'=>(int)($user['id']??0),'reversalOf'=>null];
        $created[$id] = $journal; $store['journals'][$id] = $journal;
    }
    // Reuse the normal opening reversal shape, preserving the old voucher and its audit trail.
    $openingId = tgh_text($plan['reverseOpeningPostId'] ?? '', 100);
    $opening = $store['journals'][$openingId] ?? null;
    if (!$opening || ($opening['entity'] ?? '') !== 'TG' || ($opening['sourceType'] ?? '') !== 'OPENING_BALANCE_BF' || !empty($opening['openingReversalId']) || ($opening['date'] ?? '') !== '2026-07-01') throw new DomainException('Select the existing unreversed 1 July TG USD bank opening.');
    $bankLines = array_values(array_filter($opening['lines'] ?? [], static fn($l)=>($l['account'] ?? '')==='1110'));
    if (count($bankLines)!==1 || ($bankLines[0]['bankAccountId']??'')!=='banks-2' || ($bankLines[0]['currency']??'')!=='USD' || (int)round(((float)($bankLines[0]['bankDebit']??0)-(float)($bankLines[0]['bankCredit']??0))*100)!==tgh_cents($plan['expectedOldOpening']??null)) throw new DomainException('The selected old opening does not match the reviewed USD bank amount.');
    foreach (($store['carryForwardOpeningLinks']??[]) as $link) if (($link['journalId']??'')===$openingId) throw new DomainException('The bank opening is linked to a shipment and cannot be replaced here.');
    $reverse = $opening; $rid = 'TG-HISTORY-PREVIEW-'.(count($created)+1);
    $reverse['id']=$rid; $reverse['sourceType']='OPENING_BALANCE_REVERSAL'; $reverse['reference']=$openingId; $reverse['reversalOf']=$openingId; $reverse['narration']='Replace July USD bank opening with reconciled January–June history · '.$batch;
    foreach ($reverse['lines'] as &$line) foreach ([['debit','credit'],['nativeDebit','nativeCredit'],['bankDebit','bankCredit']] as [$dr,$cr]) if (isset($line[$dr])||isset($line[$cr])) [$line[$dr],$line[$cr]]=[$line[$cr]??0,$line[$dr]??0]; unset($line);
    $reverse['meta']=array_replace((array)($reverse['meta']??[]),['tgHistoryBatch'=>$batch,'tgHistorySourceKey'=>$batch.'-replace-opening']);
    $reverse['createdAt']=$now; $reverse['createdBy']=$user['full_name']??'Super Admin'; $reverse['userId']=(int)($user['id']??0);
    $created[$rid]=$reverse; $store['journals'][$rid]=$reverse; $store['journals'][$openingId]['openingReversalId']=$rid;
    $june = tgh_bank_balance($store,'2026-06-30');
    if ($june !== tgh_cents($plan['expectedJuneClosing']??null)) throw new DomainException('The reconstructed 30 June bank balance does not equal the statement: USD '.number_format($june/100,2).'. Nothing has been posted.');
    return ['store'=>$store,'journals'=>$created,'replayed'=>false,'hash'=>$hash,'batch'=>$batch,'openingId'=>$openingId,'juneClosing'=>$june/100,'currentClosing'=>tgh_bank_balance($store,'9999-12-31')/100];
}
