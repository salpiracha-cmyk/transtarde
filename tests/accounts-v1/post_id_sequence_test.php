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

assert($first === 'POST-2026-00001');
assert($second === 'POST-2026-00002');
assert($nextYear === 'POST-2027-00001');
assert(tt_post_id_matches_search($second, '2'));
assert(tt_post_id_matches_search($second, '00002'));
assert(!tt_post_id_matches_search($second, '100'));
assert(tt_format_post_id(100, 2026) === 'POST-2026-00100');

echo "Universal post ID sequence test passed.\n";
