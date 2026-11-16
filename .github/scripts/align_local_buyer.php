<?php
declare(strict_types=1);

const MLA_TYPES = ['LOCAL_SALE_RECOGNIZED', 'LOCAL_SALE_PAYMENT_APPROVED', 'LOCAL_SALE_ADVANCE_APPLIED'];
const MLA_PARTY_FIELDS = ['party', 'customer', 'counterparty', 'subledger'];

function mla_key(string $value): string {
    return hash('sha256', strtolower(trim($value)));
}

function mla_require(bool $condition, string $check): void {
    if (!$condition) throw new RuntimeException($check);
}

/** Align only the confirmed, paid Mill sale. The saved Mill buyer supplies the name. */
function mla_align(array &$books, array $millSales, array $plan): array {
    $sources = array_values(array_filter($millSales, static function (array $sale) use ($plan): bool {
        $entity = strtoupper((string)($sale['accountEntity'] ?? $sale['entity'] ?? ''));
        return empty($sale['cancelled']) && mla_key($entity.'|'.($sale['soda'] ?? '').'|'.($sale['party'] ?? '')) === $plan['sale'];
    }));
    mla_require(count($sources) === 1, 'MILL_SALE_NOT_UNIQUE');
    $sale = $sources[0];
    $company = strtoupper((string)($sale['accountEntity'] ?? $sale['entity']));
    $soda = (string)$sale['soda'];
    $buyer = trim((string)$sale['party']);
    $buyerKey = mla_key($buyer);
    $identityPrefix = $company.'|'.$soda.'|';
    $isAlias = static fn(string $name): bool => mla_key($identityPrefix.$buyer.'|'.trim($name)) === $plan['alias'];
    $isBuyer = static fn(string $name): bool => mla_key($name) === $buyerKey || $isAlias($name);
    mla_require($buyer !== '' && tt_inv_is_byproduct((string)($sale['product'] ?? '')), 'MILL_BUYER_OR_PRODUCT_MISMATCH');

    $next = [];
    $before = [];
    $selectedPosts = [];
    foreach ($books['journals'] ?? [] as $id => $journal) {
        $type = (string)($journal['sourceType'] ?? '');
        if (!isset($plan['posts'][$type]) || mla_key($identityPrefix.$id) !== $plan['posts'][$type]) continue;
        mla_require(($journal['id'] ?? '') === $id && ($journal['entity'] ?? '') === $company
            && ($journal['status'] ?? '') === 'Posted' && ($journal['meta']['soda'] ?? '') === $soda
            && empty($journal['reversalOf']) && empty($journal['amendedByPostId']) && empty($journal['reversedByPostId'])
            && empty($journal['meta']['reversedByPostId']) && empty($journal['meta']['deletedFromBooks']), 'POST_IDENTITY_MISMATCH');
        $selectedPosts[$type] = $journal;
    }
    mla_require(count($selectedPosts) === count(MLA_TYPES), 'LINKED_POSTS_NOT_FOUND');
    foreach ($books['journals'] as $journal) {
        if (($journal['entity'] ?? '') !== $company || ($journal['status'] ?? '') !== 'Posted') continue;
        foreach ($selectedPosts as $post) {
            mla_require(($journal['reversalOf'] ?? null) !== $post['id'], 'LINKED_POST_REVERSED');
        }
    }

    $amounts = [];
    $controls = [
        MLA_TYPES[0] => ['1220' => 1],
        MLA_TYPES[1] => ['2510' => -1],
        MLA_TYPES[2] => ['2510' => 1, '1220' => -1],
    ];
    foreach ($selectedPosts as $type => $journal) {
        $original = $journal;
        $control = $controls[$type];
        foreach ($journal['lines'] as &$line) {
            $account = (string)($line['account'] ?? '');
            if (isset($control[$account])) {
                $delta = round(100 * ((float)$line['debit'] - (float)$line['credit']));
                mla_require($delta * $control[$account] > 0, 'CONTROL_LINE_DIRECTION_MISMATCH');
                $amounts[$type.'|'.$account] = ($amounts[$type.'|'.$account] ?? 0) + abs($delta);
                foreach (MLA_PARTY_FIELDS as $field) {
                    if (empty($line[$field])) continue;
                    $matches = $isBuyer((string)$line[$field]);
                    // An advance subsidiary label can describe the account.
                    if ($field === 'subledger' && $account === '2510') continue;
                    mla_require($matches, 'UNEXPECTED_CUSTOMER_ON_CONTROL_LINE');
                }
                foreach (['party','customer','counterparty'] as $field) {
                    if ($field === 'party' || isset($line[$field])) $line[$field] = $buyer;
                }
                if ($account === '1220' || $isBuyer((string)($line['subledger'] ?? ''))) {
                    $line['subledger'] = $buyer;
                }
            } elseif (!in_array($account, ['1110', '1120'], true)) {
                foreach (['party','customer','counterparty'] as $field) {
                    if ($isAlias((string)($line[$field] ?? ''))) $line[$field] = $buyer;
                }
            }
        }
        unset($line);
        foreach (['party','customer','counterparty'] as $field) {
            if ($field === 'party' || isset($journal['meta'][$field])) {
                $old = (string)($journal['meta'][$field] ?? '');
                mla_require($old === '' || $isBuyer($old), 'UNEXPECTED_BUYER_ON_POST');
                $journal['meta'][$field] = $buyer;
            }
        }
        $narration = (string)($journal['narration'] ?? '');
        $parts = explode(' — ', $narration);
        if (count($parts) > 1 && $isAlias((string)end($parts))) {
            $parts[count($parts)-1] = $buyer;
            $journal['narration'] = implode(' — ', $parts);
        }
        if ($type === MLA_TYPES[1]) {
            $bank = array_values(array_filter($original['lines'], static fn(array $line): bool => (string)$line['account'] === '1110'));
            mla_require(count($bank) === 1 && (float)$bank[0]['credit'] === 0.0
                && round(100 * (float)$bank[0]['debit']) === ($amounts[$type.'|2510'] ?? 0), 'ORIGINAL_BANK_DEBIT_MISMATCH');
        }
        if ($journal !== $original) {
            $next['journals'][$journal['id']] = $journal;
            $before['journals'][$journal['id']] = array_intersect_key($original, array_flip(['narration','meta','lines']));
        }
    }
    mla_require(count($amounts) === 4 && count(array_unique($amounts)) === 1
        && mla_key($identityPrefix.$buyer.'|'.(int)reset($amounts)) === $plan['amount'], 'SETTLEMENT_DOES_NOT_CLEAR');

    foreach (['localSalesCandidates' => MLA_TYPES[0], 'localSalesPaymentCandidates' => MLA_TYPES[1]] as $collection => $type) {
        foreach ($books[$collection] ?? [] as $id => $row) {
            if (($row['journalId'] ?? '') !== $selectedPosts[$type]['id']) continue;
            mla_require(($row['entity'] ?? '') === $company && ($row['soda'] ?? '') === $soda
                && $isBuyer((string)($row['party'] ?? '')), 'UNEXPECTED_BUYER_ON_SAVED_HANDOFF');
            if ($row['party'] === $buyer) continue;
            $before[$collection][$id] = $row['party'];
            $row['party'] = $buyer;
            $next[$collection][$id] = $row;
        }
    }

    if ($next) {
        foreach ($next as $collection => $rows) foreach ($rows as $id => $row) $books[$collection][$id] = $row;
        $books['localBuyerAlignmentAudit'][] = [
            'at' => gmdate('c'), 'by' => 'Codex maintenance authorised by Salman',
            'entity' => $company, 'soda' => $soda, 'buyer' => $buyer,
            'reason' => 'Align confirmed local sale and settlement to the buyer saved in Mill.',
            'before' => $before,
        ];
        $books['revision'] = (int)($books['revision'] ?? 0) + 1;
    }
    return ['result' => $next ? 'aligned' : 'already_aligned', 'verifiedPosts' => count($selectedPosts),
        'receivableCleared' => true, 'advanceCleared' => true, 'bankLinePreserved' => true];
}

function mla_write($handle, string $contents): void {
    rewind($handle);
    mla_require(ftruncate($handle, 0), 'BOOK_WRITE_FAILED');
    $length = strlen($contents);
    for ($offset = 0; $offset < $length; $offset += $count) {
        $count = fwrite($handle, substr($contents, $offset));
        mla_require(is_int($count) && $count > 0, 'BOOK_WRITE_FAILED');
    }
    mla_require(fflush($handle) && (!function_exists('fsync') || fsync($handle)), 'BOOK_SYNC_FAILED');
    rewind($handle);
    mla_require(stream_get_contents($handle) === $contents, 'BOOK_VERIFICATION_FAILED');
}

if (defined('TT_MLA_LIBRARY')) return;

try {
    $root = (string)getenv('TT_MLA_ROOT');
    mla_require(PHP_SAPI === 'cli' && (bool)preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D', $root), 'INVALID_TARGET');
    require $root.'/auth_store.php';
    require_once $root.'/inventory_reconciliation.php';
    require_once $root.'/api/local_sales_source_core.php';
    $plan = [
        'sale' => '67436de918a0c6a788570c7b9b9d9d7f3314bc7a23851d94e3d931137dc2ddf3',
        'alias' => '3079afc8b95d348ea6e2062da4533503c26163567a2a52ca644a3bea44923c4a',
        'amount' => '3d92173d665a1fcd5bd31f7bcf6aa89cfe7325b3f7692782d7dedcab3ae66563',
        'posts' => [
            MLA_TYPES[0] => '4f1401c93b75eb0060f6611c9af395888c992fe005f0d6bf1638a4033647d7ab',
            MLA_TYPES[1] => '885148a5ccdb6b0be845b3fa2cc4b7ce291d8b2b6d2bd11a181a9e54ea1745ad',
            MLA_TYPES[2] => 'c8acd5fe3bd473111318bff3971d020b1c12c6bbcecd8aed20f1e0f6a1cb00b5',
        ],
    ];
    $handle = fopen(TT_DATA_DIR.'/accounts.json', 'r+b');
    mla_require(is_resource($handle) && flock($handle, LOCK_EX), 'ACCOUNTS_LOCK_UNAVAILABLE');
    try {
        $raw = stream_get_contents($handle);
        $books = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $result = mla_align($books, tt_inv_rows(lsc_values(), 'tt35localsales'), $plan);
        if ($result['result'] === 'aligned') {
            $updated = json_encode($books, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $backup = TT_DATA_DIR.'/local-buyer-'.$plan['sale'].'.json.gz';
            if (!is_file($backup)) {
                $compressed = gzencode($raw, 6);
                mla_require(is_string($compressed), 'RECOVERY_SNAPSHOT_UNAVAILABLE');
                $safety = fopen($backup, 'x+b');
                mla_require(is_resource($safety), 'RECOVERY_SNAPSHOT_UNAVAILABLE');
                try {
                    mla_require(chmod($backup, 0600), 'RECOVERY_SNAPSHOT_PERMISSIONS');
                    mla_write($safety, $compressed);
                } finally {
                    fclose($safety);
                }
            }
            mla_require(!is_link($backup) && gzdecode((string)file_get_contents($backup)) === $raw, 'RECOVERY_SNAPSHOT_MISMATCH');
            try {
                mla_write($handle, $updated);
            } catch (Throwable $error) {
                mla_write($handle, $raw);
                throw $error;
            }
        }
        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
} catch (Throwable $error) {
    $message = $error->getMessage();
    fwrite(STDERR, (preg_match('/^[A-Z_]+$/D', $message) ? $message : 'BUYER_ALIGNMENT_FAILED').PHP_EOL);
    exit(1);
}
