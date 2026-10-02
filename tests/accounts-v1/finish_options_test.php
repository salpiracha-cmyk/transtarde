<?php
declare(strict_types=1);

$GLOBALS['finish_test_store']=['masters'=>['products'=>[]]];
function tt_read_store(): array { return $GLOBALS['finish_test_store']; }
function tt_mutate_store(callable $callback): mixed { return $callback($GLOBALS['finish_test_store']); }
function finish_check(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}

require dirname(__DIR__,2).'/master_store.php';

$original=tt_export_finish_options();
finish_check(count($original)===3,'The established finishes must remain available by default.');
$custom='Satin polished';
tt_manage_master_option('product_finishes','add',$custom);
finish_check(in_array($custom,tt_master_options()['product_finishes'],true),'New Finish must appear in active choices.');
finish_check(tt_export_finish_normalize($custom)===$custom,'Custom Finish must survive normalization.');
finish_check(tt_export_finish_normalize('Well milled, double steamed')==='Well milled, double steamed','Custom well-milled Finish must not be rewritten as a preset.');

$values=array_fill(0,22,'');$values[17]=$custom;
$GLOBALS['finish_test_store']['masters']['products'][]=['id'=>'historical-product','values'=>$values];
tt_manage_master_option('product_finishes','rename','Mirror polished',$custom);
$active=tt_master_options()['product_finishes'];
finish_check(in_array('Mirror polished',$active,true),'Edited Finish must appear in active choices.');
finish_check(!in_array($custom,$active,true),'Edited Finish must no longer be offered.');
finish_check($GLOBALS['finish_test_store']['masters']['products'][0]['values'][17]===$custom,'Editing a choice must not rewrite saved products.');

tt_manage_master_option('product_finishes','delete','', 'Mirror polished');
$active=tt_master_options()['product_finishes'];
finish_check(!in_array('Mirror polished',$active,true),'Deactivated Finish must disappear from active choices.');
finish_check($GLOBALS['finish_test_store']['masters']['products'][0]['values'][17]===$custom,'Deactivation must preserve saved product history.');
tt_manage_master_option('product_finishes','add','Mirror polished');
finish_check(in_array('Mirror polished',tt_master_options()['product_finishes'],true),'Deactivated Finish must be restorable.');

echo "Finish add, edit, deactivate, restore and historical product retention passed\n";
