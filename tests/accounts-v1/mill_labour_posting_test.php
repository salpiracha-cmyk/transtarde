<?php
declare(strict_types=1);
require __DIR__.'/mill_liabilities_test.php';
require dirname(__DIR__,2).'/api/supplier_opening_core.php';

require dirname(__DIR__,2).'/api/mill_labour_posting.php';
define('TT_DATA_DIR',sys_get_temp_dir().'/tt-labour-posting-'.bin2hex(random_bytes(8)));
function tt_ensure_data_dir():void {if(!is_dir(TT_DATA_DIR))mkdir(TT_DATA_DIR,0700);}
function tt_list_masters():array {return $GLOBALS['masters'];}
function tt_user_can_open_module(array $u,string $module):bool {return $module===($u['module']??'');}
function tt_user_can_module_action(array $u,string $module,string $area,string $action):bool {
    return tt_user_can_open_module($u,$module)&&$area===($module==='Mill'?'labour':'expenses')&&in_array($action,$u['actions'],true);
}
function tt_user_can_access_entity(array $u,string $entity,string $action):bool {return $entity===($u['entity']??'');}
function tt_next_post_id(array $journals,mixed ...$args):string {return 'POST-'.(count($journals)+1);}
$labour['commodity']='Rice';
$new=array_replace($labour,['id'=>'L-2','ref'=>'L2']);
$new['lines'][]=['qty'=>1,'rate'=>0,'basis'=>'Per bag'];
$invalid=array_replace($new,['id'=>'L-3','ref'=>'L3','amount'=>999.0]);
$before=json_encode([$labour]);
$after=json_encode([$labour,$new,$invalid]);
$actor=['id'=>21,'full_name'=>'Mill clerk','location'=>'TTI Rice Mills','module'=>'Mill','actions'=>['Create']];
$reviewer=['id'=>22,'full_name'=>'Accounts editor','location'=>'TTI Rice Mills','module'=>'Accounts','actions'=>['View','Edit'],'entity'=>'TTI'];
tt_ensure_data_dir();
try {
    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>['tt38labourbills'=>$after]]));
    verify(ml_post_appended_labour($before,$before,$actor)===[]&&!is_file(TT_DATA_DIR.'/accounts.json'),'Historical labour was posted on an unchanged save');
    $result=ml_post_appended_labour($before,$after,$actor);
    verify($result['L-2']===['status'=>'Posted','postId'=>'POST-1']&&$result['L-3']['status']==='Needs Accounts review','New committed labour bill or invalid total handled incorrectly');
    $books=ml_read_accounts();
    $journal=$books['journals']['POST-1'];
    verify(count($books['journals'])===1&&$journal['reference']==='L2'&&$journal['userId']===21&&$journal['totalCredit']===123.5&&array_column($journal['lines'],'account')===['5200','2190'],'Auto-post did not create exactly one no-cash liability under the Mill actor');
    verify(ml_post_appended_labour($before,$after,$actor)===$result&&ml_read_accounts()===$books,'Retry rewrote Accounts or posted twice');
    verify(ml_post_appended_labour($before,$after,$reviewer)['L-2']['status']==='Needs Accounts review','Accounts edit permission substituted for Mill create');
    msa_scope($reviewer,$new,'LABOUR','Edit');
    denied(fn()=>msa_scope(array_replace($reviewer,['entity'=>'BRM']),$new,'LABOUR','Edit'),'Another company could amend labour');
    denied(fn()=>msa_scope($reviewer,$new,'ELECTRICITY','Edit'),'Accounts-only access could amend an unrelated Mill source');
    $canonical=reset($books['supplierBills']);
    verify(!sop_bill_uses_running_ledger($books,'TTI',$canonical),'An unconfigured labour party lost bill payments');
    $books['paymentPayees'][]=['id'=>'PARTY|CONTRACTOR','name'=>'Labour Co','entity'=>'TTI','kind'=>'BUSINESS','accountCode'=>'2190','active'=>true];
    verify(sop_bill_uses_running_ledger($books,'TTI',$canonical)&&!sop_bill_uses_running_ledger($books,'BRM',$canonical),'Party-ledger treatment crossed company or missed a configured contractor');
} finally {
    foreach(glob(TT_DATA_DIR.'/*') as $path)unlink($path);
    rmdir(TT_DATA_DIR);
}
echo "PASS future labour liability posting, replay, Accounts edit scope and configured party-ledger treatment\n";
