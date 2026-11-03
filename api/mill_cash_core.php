<?php
declare(strict_types=1);
require_once __DIR__.'/expense_locations.php';

function mcl_locations(string $entity): array {
    return array_values(array_filter(ev1_owned_locations($entity),static fn($r)=>$r['location']==='MILL'));
}
function mcl_location(string $entity,string $id): array {
    foreach(mcl_locations($entity) as $row)if($row['id']===$id)return $row;
    throw new DomainException('Choose an active company mill from Mills & Locations Master.');
}
function mcl_rows(array $state,string $entity,string $locationId): array {
    $rows=[];$cashId='CASH|'.$entity.'|MILL|'.$locationId;$asOf=tt_accounts_balance_date(null);
    foreach(pc_rows($state,$entity) as $row){
        if($row['cashId']!==$cashId||$row['date']>$asOf)continue;
        $journal=$state['journals'][$row['journalId']]??[];
        $category=$journal['meta']['expenseLines'][0]['category']??'';$type=(string)($journal['meta']['millPettyType']??(['OFFICE'=>'Office Expense','MILL'=>'Milling Expense','REPAIRS'=>'Plant Expense'][$category]??'Accounts Cash Payment'));
        $rows[]=['id'=>'ACCOUNTS|'.$row['id'],'postId'=>$row['postId'],'date'=>$row['date'],'ref'=>$row['narration'],'payee'=>$row['payee'],'expenseType'=>(string)($journal['meta']['expenseLines'][0]['purpose']??''),'voucher'=>$row['postId'],'debit'=>$row['received'],'credit'=>$row['paid'],'type'=>$row['received']>0?'PETTY CASH RECEIVED':$type,'source'=>'Accounts'];
    }
    return $rows;
}
function mcl_payload(array $s,string $e,array $mill,array $u):array {
    $rows=mcl_rows($s,$e,$mill['id']);$balance=0.;foreach($rows as &$row){$balance=round($balance+$row['debit']-$row['credit'],2);$row['balance']=$balance;}unset($row);
    return ['ok'=>true,'location'=>$mill,'entity'=>$e,'revision'=>(int)($s['revision']??0),'rows'=>$rows,'balance'=>$balance,'asOf'=>tt_accounts_balance_date(null),'canCreate'=>tt_user_can_module_action($u,'Mill','petty','Create')&&tt_user_business_entity($u,$e,'Create')];
}
function mcl_post(array &$s,string $e,array $mill,array $b,array $u):array {
    $types=['Office Expense'=>'OFFICE','Milling Expense'=>'MILL','Plant Expense'=>'REPAIRS'];$type=(string)($b['type']??'');
    if(!isset($types[$type]))throw new DomainException('Choose Office, Milling or Plant expense.');
    $date=ev1_date((string)($b['date']??''));if($date>tt_accounts_balance_date(null))throw new DomainException('A cash payment cannot be dated in the future.');
    $amount=ev1_amount($b['amount']??0);$note=trim((string)($b['narration']??''));$purpose=trim((string)($b['expenseType']??''));$payee=trim((string)($b['payee']??''));
    if($note===''||strlen($note)>500||$purpose===''||strlen($purpose)>180||$payee===''||strlen($payee)>180)throw new DomainException('Enter paid to, expense type and narration within their displayed limits.');
    $cash='CASH|'.$e.'|MILL|'.$mill['id'];
    if($amount>pc_balance($s,$e,$cash,$date)+.005)throw new DomainException('Mill Petty Cash has insufficient funds on this date.');
    $result=dex_post($s,$e,['paymentDate'=>$date,'paymentAccountId'=>$cash,'payee'=>$payee,'paymentNarration'=>$note,'expenseLines'=>[['category'=>$types[$type],'amount'=>$amount,'purpose'=>$purpose,'expenseArea'=>$types[$type]==='MILL'?'MILL':'OFFICE']]],$u,ev1_names(),[]);
    $jid=$result['journalId'];$s['journals'][$jid]['meta']+=['millPettyType'=>$type,'millLocationId'=>$mill['id'],'millName'=>$mill['name'],'entryModule'=>'Mill'];
    $s['generalExpenses'][$result['generalExpenseId']]['millPettyType']=$type;
    pc_assert_custody_balance($s,$e,$cash);
    return $result;
}
