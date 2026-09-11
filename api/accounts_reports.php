<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';

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
    $raw = is_file($path) ? file_get_contents($path) : false;
    $value = $raw ? json_decode($raw, true) : null;
    return is_array($value) ? $value : [];
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
    $catalog = [];
    foreach (array_merge((array)($master['chart'] ?? []), (array)($master['peopleSubledgers'] ?? [])) as $account) {
        if (is_array($account) && isset($account['code'])) {
            $catalog[(string)$account['code']] = $account;
        }
    }

    $journals = array_values(array_filter(
        (array)($store['journals'] ?? []),
        static fn($journal): bool =>
            is_array($journal)
            && ($journal['entity'] ?? '') === $entity
            && ($journal['status'] ?? '') === 'Posted'
            && is_string($journal['date'] ?? null)
            && $journal['date'] <= $asOf
    ));
    usort(
        $journals,
        static fn(array $a, array $b): int =>
            strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''))
            ?: strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''))
    );

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
            $ledger[] = [
                'date' => (string)($journal['date'] ?? ''),
                'journalId' => (string)($journal['id'] ?? ''),
                'sourceType' => (string)($journal['sourceType'] ?? ''),
                'reference' => (string)($journal['reference'] ?? ''),
                'narration' => (string)($journal['narration'] ?? ''),
                'account' => $code,
                'accountName' => (string)($line['accountName'] ?? ($catalog[$code]['name'] ?? $code)),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }
    }

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

    $income = [];
    $expenses = [];
    $profit = 0.0;
    foreach ($periodBalances as $code => $net) {
        $account = $catalog[$code] ?? [];
        $class = (string)($account['class'] ?? 'Other');
        if ($class === 'Revenue') {
            $amount = round(-$net, 2);
            $income[] = ['account' => $code, 'name' => (string)($account['name'] ?? $code), 'amount' => $amount];
            $profit += $amount;
        } elseif ($class === 'Expense' || $code === '7100') {
            $amount = round($net, 2);
            $expenses[] = ['account' => $code, 'name' => (string)($account['name'] ?? $code), 'amount' => $amount];
            $profit -= $amount;
        }
    }

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
        } elseif ($class === 'Revenue') {
            $cumulativeProfit += -$net;
        } elseif ($class === 'Expense' || $code === '7100') {
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
        'from' => $from,
        'asOf' => $asOf,
        'generalLedger' => $ledger,
        'trialBalance' => [
            'rows' => $trial,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'balanced' => abs($totalDebit - $totalCredit) < .005,
        ],
        'profitAndLoss' => [
            'income' => $income,
            'expenses' => $expenses,
            'profit' => round($profit, 2),
        ],
        'balanceSheet' => [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totalAssets' => ar_sum_rows($assets, 'amount'),
            'totalLiabilitiesAndEquity' => round(ar_sum_rows($liabilities, 'amount') + ar_sum_rows($equity, 'amount'), 2),
        ],
        'dataQuality' => ['skippedMalformedLines' => $skippedMalformedLines],
        'serverNow' => gmdate('c'),
    ]);
} catch (Throwable $error) {
    error_log('Accounts reports failed: '.$error->getMessage());
    ar_out(['ok' => false, 'error' => 'Accounts reports could not be prepared.'], 500);
}
