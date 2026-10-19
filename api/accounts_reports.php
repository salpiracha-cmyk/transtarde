<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_subaccounts_core.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const AR_FILE = TT_DATA_DIR.'/accounts.json';
const AR_MASTER = __DIR__.'/../accounts/accounting_master_v1.json';

function ar_out(array $value, int $status = 200): never {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ar_date(string $value, string $label): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date
        || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        ar_out(['ok' => false, 'error' => $label.' is invalid.'], 422);
    }
    return $value;
}

function ar_read(string $path): array {
    if(!is_file($path))throw new RuntimeException('Report source is unavailable.');
    $handle=@fopen($path,'rb');
    if($handle===false)throw new RuntimeException('Report source is unavailable.');
    try {
        if(!flock($handle,LOCK_SH))throw new RuntimeException('Report source is busy.');
        $value=json_decode((string)stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||($value!==[]&&array_is_list($value)))throw new RuntimeException('Report source is invalid.');
        return $value;
    } finally {flock($handle,LOCK_UN);fclose($handle);}
}

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

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) {
        ar_out(['ok' => false, 'error' => 'Accounts permission required.'], 403);
    }

    $entity = strtoupper(trim((string)($_GET['entity'] ?? '')));
    if (
        !in_array($entity, ['TTI', 'BRM', 'TG'], true)
        || !tt_user_can_access_entity($user, $entity, 'View')
    ) {
        ar_out(['ok' => false, 'error' => 'You do not have permission for this legal entity.'], 403);
    }

    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $asOf = ar_date((string)($_GET['asOf'] ?? $today), 'As-of date');
    $from = ar_date((string)($_GET['from'] ?? substr($asOf, 0, 4).'-01-01'), 'From date');
    if ($from > $asOf) {
        ar_out(['ok' => false, 'error' => 'From date cannot be after the as-of date.'], 422);
    }

    $store = ar_read(AR_FILE);
    $master = ar_read(AR_MASTER);
    if(isset($store['journals'])&&!is_array($store['journals']))throw new RuntimeException('Journal source is invalid.');
    if(!is_array($master['chart']??null)||!$master['chart'])throw new RuntimeException('Account catalog is unavailable.');
    $catalog = [];
    foreach (array_merge((array)($master['chart'] ?? []), (array)($master['peopleSubledgers'] ?? [])) as $account) {
        if (is_array($account) && isset($account['code'])) {
            $catalog[(string)$account['code']] = $account;
        }
    }

    $hidden=alv_hidden($store,$asOf);
    $journals = array_values(array_filter(
        (array)($store['journals'] ?? []),
        static fn($journal): bool =>
            is_array($journal)
            && ($journal['entity'] ?? '') === $entity
            && alv_posted($journal)
            && is_string($journal['date'] ?? null)
            && $journal['date'] <= $asOf
    ));
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
    $zakatClassificationComplete=abs($zakatControlNet-$zakatAllPaid['totalPKR'])<.005&&!$zakatAllPaid['warnings'];
    $statementWarnings=$zakat['warnings'];
    $balanceSheetWarnings=$zakatClassificationComplete?[]:['Zakat 7210 includes unpaid or opening control amounts. This is the original posted-books balance sheet; reconcile the annual control and its approved counterpart before finalising its classification. No reclassification journal or balancing equity has been assumed.'];

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
            $expenses[] = ['account' => $code, 'name' => (string)($account['name'] ?? $code), 'amount' => (string)$code==='7210'&&$zakat['warnings']?null:$amount];
            $profit -= $amount;
        }
    }

    usort($income,static fn($a,$b)=>strnatcmp((string)$a['account'],(string)$b['account']));
    usort($expenses,static fn($a,$b)=>strnatcmp((string)$a['account'],(string)$b['account']));

    $assets = [];
    $liabilities = [];
    $equity = [];
    $cumulativeProfit = 0.0;
    foreach ($balances as $code => $net) {
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
    ar_out([
        'ok' => true,
        'entity' => $entity,
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
    ]);
} catch (Throwable $error) {
    error_log('Accounts reports failed: '.$error->getMessage());
    ar_out(['ok' => false, 'error' => 'Accounts reports could not be prepared.'], 500);
}
