<?php
declare(strict_types=1);

/** Existing books are a source, never an implicit new/empty store. */
function tt_accounts_source_decode(string $raw, array $defaults): array {
    if (trim($raw) === '' || !str_starts_with(ltrim($raw), '{')) {
        throw new RuntimeException('Accounts source is empty or invalid.');
    }
    $store = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($store)) throw new RuntimeException('Invalid Accounts source.');
    // PHP accepts overflowing JSON numbers as INF; those cannot be persisted.
    json_encode($store, JSON_THROW_ON_ERROR);
    foreach (array_replace(['journals' => []], $defaults) as $key => $value) {
        if (is_array($value) && array_key_exists($key, $store) && !is_array($store[$key])) {
            throw new RuntimeException('Invalid Accounts source collection.');
        }
    }
    return array_replace_recursive($defaults, $store);
}
