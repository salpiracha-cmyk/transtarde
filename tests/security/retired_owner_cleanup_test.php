<?php
declare(strict_types=1);
// A restored store without the old marker must never be read or mutated here.
function tt_read_store():array { throw new RuntimeException('Retired cleanup read storage.'); }
function tt_mutate_store(callable $callback):mixed { throw new RuntimeException('Retired cleanup mutated storage.'); }
require dirname(__DIR__,2).'/owner_master_cleanup.php';
for($i=0;$i<2;$i++){
    if(tt_apply_owner_master_cleanup()!==['changed'=>false,'deleted'=>0])throw new RuntimeException('Cleanup compatibility changed.');
}
$entry=file_get_contents(dirname(__DIR__,2).'/index.php');
if(str_contains($entry,'tt_apply_owner_master_cleanup')||str_contains($entry,'owner_master_cleanup.php'))throw new RuntimeException('Console must not run a destructive cleanup.');
echo "PASS retired Parties cleanup: restored stores and repeated calls never touch storage.\n";
