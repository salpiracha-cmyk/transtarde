<?php
declare(strict_types=1);

$store = [];

function tt_mutate_store(callable $callback): void {
    global $store;
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

echo "Universal post ID sequence test passed.\n";
