<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_subaccounts_core.php';
require_once __DIR__.'/tg_native_currency.php';
function ar_amount(mixed $value): float {
    return is_numeric($value) ? round((float)$value, 2) : 0.0;
}

function ar_valid_line(mixed $line): bool {
    return is_array($line) && trim((string)($line['account'] ?? '')) !== '';
}

function ar_sum_rows(array $rows, string $column): float {
    $sum = 0.0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $sum += ar_amount($row[$column] ?? 0);
        }
    }
    return round($sum, 2);
}

function ar_build_report(array $sourceStore,array $sourceMaster,string $entity,string $from,string $asOf,?string $currency=null):array {
    $store = alv_amendment_books($sourceStore);
    $master = $sourceMaster;
    if(isset($store['journals'])&&!is_array($store['journals']))throw new RuntimeException('Journal source is invalid.');
    if(!is_array($master['chart']??null)||!$master['chart'])throw new RuntimeException('Account catalog is unavailable.');
    $catalog = [];
    foreach (array_merge((array)($master['chart'] ?? []), (array)($master['peopleSubledgers'] ?? [])) as $account) {
        if (is_array($account) && isset($account['code'])) {
            $catalog[(string)$account['code']] = $account;
        }
    }

    $hidden=alv_hidden($store,$asOf);
    // Hiding a complete cancellation pair must not erase its current-period
    // reversal when the original belongs to an earlier reporting period.
    foreach((array)($store['journals']??[]) as $key=>$j){
        if(!is_array($j)||($j['entity']??'')!==$entity||!alv_posted($j)||($j['date']??'')<$from||($j['date']??'')>$asOf)continue;
        $originalId=(string)($j['reversalOf']??$j['meta']['reversalOf']??'');$original=$store['journals'][$originalId]??null;
        if(is_array($original)&&($original['entity']??'')===$entity&&($original['date']??'')<$from)unset($hidden[(string)($j['id']??$key)]);
    }

    $journals = array_values(array_filter(
        (array)($store['journals'] ?? []),
        static fn($journal): bool =>
            is_array($journal)
            && ($journal['entity'] ?? '') === $entity
            && alv_posted($journal)
            && is_string($journal['date'] ?? null)
            && $journal['date'] <= $asOf
    ));
    $presentationCurrency=$entity==='TG'?strtoupper(trim((string)($currency??'AED'))):'PKR';
    // Existing integrations request the historical AED books without a currency
    // parameter. Explicit currency selection translates report copies only.
    if($entity==='TG'&&$currency!==null){
        if(!in_array($presentationCurrency,(array)(tt_master_options()['currencies']??[]),true))throw new DomainException('Choose a report currency from Currency Master.');
        foreach($journals as &$reportJournal){$reportJournal=tgn_present($reportJournal,$presentationCurrency,$asOf);$store['journals'][$reportJournal['id']]=$reportJournal;}unset($reportJournal);
    }
    usort(
        $journals,
        static fn(array $a, array $b): int =>
            strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''))
            ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''))
    );

    $catalog=array_replace($catalog,sac_entity_chart($store,$entity));
    $balances = [];
    $ledger = [];
    $skippedMalformedLines = 0;
    foreach ($journals as $journal) {
        foreach ((array)($journal['lines'] ?? []) as $line) {
            if (!ar_valid_line($line)) {
                $skippedMalformedLines++;
                continue;
            }
            $code = trim((string)$line['account']);
            $debit = ar_amount($line['debit'] ?? 0);
            $credit = ar_amount($line['credit'] ?? 0);
            $balances[$code] = ($balances[$code] ?? 0.0) + $debit - $credit;
            if(isset($hidden[(string)($journal['id']??'')]))continue;
            if((string)$journal['date']<$from)continue;
            $ledger[] = [
                'date' => (string)($journal['date'] ?? ''),
                'journalId' => (string)($journal['id'] ?? ''),
                'publicPostId' => tt_accounts_public_post($store,$journal),
                'sourceType' => (string)($journal['sourceType'] ?? ''),
                'reference' => (string)($journal['reference'] ?? ''),
                'narration' => sac_journal_narration($store,$journal),
                'account' => $code,
                'accountName' => (string)($line['accountName'] ?? ($catalog[$code]['name'] ?? $code)),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }
    }

    usort($ledger,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strnatcmp($a['journalId'],$b['journalId']));
    $trial = [];
    foreach ($balances as $code => $net) {
        if (abs($net) < .005) {
            continue;
        }
        $account = $catalog[$code] ?? [];
        $trial[] = [
            'account' => $code,
            'name' => (string)($account['name'] ?? $code),
            'class' => (string)($account['class'] ?? 'Other'),
            'debit' => $net > 0 ? round($net, 2) : 0,
            'credit' => $net < 0 ? round(abs($net), 2) : 0,
        ];
    }
    usort($trial, static fn(array $a, array $b): int => strcmp((string)$a['account'], (string)$b['account']));

    $periodBalances = [];
    foreach ($journals as $journal) {
        if (($journal['date'] ?? '') < $from) {
            continue;
        }
        foreach ((array)($journal['lines'] ?? []) as $line) {
            if (!ar_valid_line($line)) {
                continue;
            }
            $code = trim((string)$line['account']);
            $periodBalances[$code] = ($periodBalances[$code] ?? 0.0)
                + ar_amount($line['debit'] ?? 0)
                - ar_amount($line['credit'] ?? 0);
        }
    }

    $zakat=gm_zakat_payments($store,$from,$asOf,$entity);
    $zakatBookNet=round((float)($periodBalances['7210']??0),2);
    $zakatControlNet=round((float)($balances['7210']??0),2);
    $zakatAllPaid=gm_zakat_payments($store,'0001-01-01',$asOf,$entity);
    // Presentation follows actual payments, not the chart's legacy Expense label.
    // Never create a balancing figure or alter original opening/payment journals.
    if(isset($periodBalances['7210'])||$zakat['rows']||$zakat['warnings'])$periodBalances['7210']=$zakat['totalPKR'];
    $zakatStatement=zp_statement_adjustments($journals,$zakatAllPaid,$catalog);
    $statementBalances=$balances;
    foreach($zakatStatement['adjustments'] as $code=>$amount)$statementBalances[$code]=($statementBalances[$code]??0)+$amount;
    $zakatClassificationComplete=!$zakatStatement['warnings']&&abs(($statementBalances['7210']??0)-$zakatAllPaid['totalPKR'])<.005;
    $statementWarnings=$zakat['warnings'];
    $balanceSheetWarnings=$zakatStatement['warnings'];

    $income = [];
    $expenses = [];
    $profit = 0.0;
    foreach ($periodBalances as $code => $net) {
        $account = $catalog[$code] ?? [];
        $class = (string)($account['class'] ?? 'Other');
        if (in_array($class, ['Revenue','Income'], true)) {
            $amount = round(-$net, 2);
            $income[] = ['account' => $code, 'name' => (string)($account['name'] ?? $code), 'amount' => $amount];
            $profit += $amount;
        } elseif ($class === 'Expense' || in_array((string)$code, ['7100','7195'], true)) {
            $amount = round($net, 2);
            $expenses[] = ['account' => $code, 'name' => (string)$code==='7210'?'Donations — Zakat paid':(string)($account['name'] ?? $code), 'amount' => (string)$code==='7210'&&$zakat['warnings']?null:$amount];
            $profit -= $amount;
        }
    }

    usort($income,static fn($a,$b)=>strnatcmp((string)$a['account'],(string)$b['account']));
    usort($expenses,static fn($a,$b)=>strnatcmp((string)$a['account'],(string)$b['account']));

    $assets = [];
    $liabilities = [];
    $equity = [];
    $cumulativeProfit = 0.0;
    foreach ($statementBalances as $code => $net) {
        $account = $catalog[$code] ?? [];
        $class = (string)($account['class'] ?? 'Other');
        $name = (string)($account['name'] ?? $code);
        if ($class === 'Asset' && abs($net) > .005) {
            $assets[] = ['account' => $code, 'name' => $name, 'amount' => round($net, 2)];
        } elseif ($class === 'Liability' && abs($net) > .005) {
            $liabilities[] = ['account' => $code, 'name' => $name, 'amount' => round(-$net, 2)];
        } elseif ($class === 'Equity' && abs($net) > .005) {
            $equity[] = ['account' => $code, 'name' => $name, 'amount' => round(-$net, 2)];
        } elseif (in_array($class, ['Revenue','Income'], true)) {
            $cumulativeProfit += -$net;
        } elseif ($class === 'Expense' || in_array((string)$code, ['7100','7195'], true)) {
            $cumulativeProfit -= $net;
        }
    }
    if (abs($cumulativeProfit) > .005) {
        $equity[] = [
            'account' => 'CURRENT-EARNINGS',
            'name' => 'Current cumulative earnings',
            'amount' => round($cumulativeProfit, 2),
        ];
    }

    $totalDebit = ar_sum_rows($trial, 'debit');
    $totalCredit = ar_sum_rows($trial, 'credit');
    return [
        'ok' => true,
        'entity' => $entity,
        'currency' => $presentationCurrency,
        'currencyBasis' => $entity==='TG'&&$currency!==null?'Report translation of native currency entries':'Company books',
        'revision' => (int)($store['revision'] ?? 0),
        'from' => $from,
        'asOf' => $asOf,
        'generalLedger' => $ledger,
        'expenseActivity' => sac_expense_activity($store,$entity,$from,$asOf),
        'personalAdjustments'=>array_values(array_filter(array_map(static function($r)use($from,$asOf){$r['rows']=array_values(array_filter((array)$r['rows'],static fn($row)=>($row['date']??'')>=$from&&($row['date']??'')<=$asOf));return $r;},array_filter((array)($store['expensePersonalLinks']??[]),static fn($r)=>($r['entity']??'')===$entity)),static fn($r)=>!empty($r['rows']))),
        'trialBalance' => [
            'rows' => $trial,
            'partyRows'=>alv_party_trial($journals,$catalog),
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < .005,
        ],
        'profitAndLoss' => [
            'income' => $income,
            'expenses' => $expenses,
            'profit' => $zakat['warnings']?null:round($profit, 2),
            'warnings' => $statementWarnings,
        ],
        'zakatReporting' => ['basis'=>'Actual paid Zakat less verified refunds; unpaid assessments and financial opening balances are excluded from expense.','bookNetPeriod'=>$zakatBookNet,'paidExpense'=>$zakat['warnings']?null:$zakat['totalPKR'],'verifiedPaidSubtotal'=>$zakat['totalPKR'],'paymentRows'=>$zakat['rows'],'warnings'=>$statementWarnings,'controlBookNet'=>$zakatControlNet,'classificationComplete'=>$zakatClassificationComplete],
        'balanceSheet' => [
            'basis' => 'Paid Zakat is donation expense in earnings; opening and unpaid Zakat remain in the separate annual control only. Original Trial Balance and General Ledger are unchanged.',
            'zakatPresentationAdjustments' => $zakatStatement['sources'],
            'classificationComplete' => $zakatClassificationComplete,
            'warnings' => $balanceSheetWarnings,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totalAssets' => ar_sum_rows($assets, 'amount'),
            'totalLiabilitiesAndEquity' => round(ar_sum_rows($liabilities, 'amount') + ar_sum_rows($equity, 'amount'), 2),
        ],
        'dataQuality' => ['skippedMalformedLines' => $skippedMalformedLines,'unclassifiedBalances'=>array_values(array_map(static fn($code)=>['account'=>(string)$code,'name'=>(string)($catalog[$code]['name']??$code),'class'=>(string)($catalog[$code]['class']??'Other'),'net'=>round($balances[$code],2)],array_filter(array_keys($balances),static fn($code)=>abs($balances[$code])>.005&&!in_array((string)($catalog[$code]['class']??'Other'),['Asset','Liability','Equity','Revenue','Income','Expense'],true)&&!in_array((string)$code,['7100','7195'],true))))],
        'serverNow' => gmdate('c'),
    ];
}
