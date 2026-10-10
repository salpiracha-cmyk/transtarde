<?php
declare(strict_types=1);

function ev1_reverse_journals(array &$store,array $ids,string $entity,array $user,array $names,string $reason): array {
    if(strlen(trim($reason))<5||strlen($reason)>500)throw new InvalidArgumentException('Enter a reason of 5 to 500 characters.');
    $targets=[];
    foreach(array_unique(array_filter($ids)) as $id){
        $seen=[];
        while(!empty($store['journals'][$id]['amendedByPostId'])){if(isset($seen[$id]))throw new DomainException('The posting correction history needs review.');$seen[$id]=true;$id=(string)$store['journals'][$id]['amendedByPostId'];}
        $journal=$store['journals'][$id]??null;
        if(!is_array($journal)||($journal['entity']??'')!==$entity||($journal['status']??'')!=='Posted'||!empty($journal['reversalOf']))throw new DomainException('The linked expense posting needs review before deletion.');
        foreach((array)$store['journals'] as $other)if(($other['reversalOf']??'')===$id)throw new DomainException('This expense posting has already been reversed.');
        $targets[$id]=$journal;
    }
    $out=[];$date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    foreach($targets as $id=>$original){
        $lines=[];foreach((array)$original['lines'] as $line){$debit=$line['debit'];$line['debit']=$line['credit'];$line['credit']=$debit;if(isset($line['bankDebit'])||isset($line['bankCredit'])){ $native=$line['bankDebit']??0;$line['bankDebit']=$line['bankCredit']??0;$line['bankCredit']=$native; }if(isset($line['nativeDebit'])||isset($line['nativeCredit'])){ $native=$line['nativeDebit']??0;$line['nativeDebit']=$line['nativeCredit']??0;$line['nativeCredit']=$native; }$lines[]=$line;}
        $reverse=ev1_journal($store,$entity,$date,'EXPENSE_DELETION',$id,'Deleted expense '.$id.' — '.$reason,$lines,$user,['originalSourceType'=>$original['meta']['originalSourceType']??$original['sourceType'],'reason'=>$reason]);
        $store['journals'][$reverse]['reversalOf']=$id;$store['journals'][$id]['reversedByPostId']=$reverse;$out[]=$reverse;
    }
    return $out;
}

function ev1_delete_expense(array &$store,array $body,string $entity,array $user,array $names): array {
    $collection=(string)($body['collection']??'');$id=(string)($body['id']??'');
    if(!in_array($collection,['utilityPayments','generalExpenses','reimbursements','creditCardStatements','donations'],true))throw new InvalidArgumentException('Select a supported expense record.');
    $record=$store[$collection][$id]??null;
    if(!is_array($record)||($record['entity']??'')!==$entity)throw new DomainException('Expense not found in this company.');
    if(($record['status']??'')==='Amended')throw new DomainException('Open the replacement expense to delete the current payment.');
    if(($record['status']??'')==='Deleted')return ['id'=>$id,'status'=>'Deleted','duplicate'=>true];
    if(in_array($collection,['utilityPayments','generalExpenses'],true)&&!empty($record['cardStatementId']))throw new DomainException('Delete the linked card bill before deleting this utility.');if($collection==='creditCardStatements'){if(ev1_card_classified($store,$id)>.005)throw new DomainException('Remove the bill breakdowns before deleting this card bill.');foreach((array)($record['includedExpenses']??[]) as $charge)unset($store['generalExpenses'][$charge['id']]['cardStatementId']);}if($collection==='creditCardStatements')foreach((array)($record['includedUtilities']??[]) as $utility)unset($store['utilityPayments'][$utility['id']]['cardStatementId']);$ids=[$record['journalId']??null,$record['captureJournalId']??null,$record['statementJournalId']??null];
    $ids=array_merge($ids,(array)($record['postingJournalIds']??[]));foreach((array)($record['settlements']??[]) as $payment)$ids[]=$payment['journalId']??null;
    $reason=trim((string)($body['reason']??''));if(!empty($record['personalLink']))pex_clear($store,$entity,$record['personalLink'],$user,$names,$reason);$reversals=ev1_reverse_journals($store,$ids,$entity,$user,$names,$reason);
    $store[$collection][$id]['status']='Deleted';$store[$collection][$id]['deletedAt']=gmdate('c');$store[$collection][$id]['deletedBy']=(string)($user['username']??'');$store[$collection][$id]['deleteReason']=$reason;$store[$collection][$id]['deletionJournalIds']=$reversals;
    $store['expenseAudit'][]=['at'=>gmdate('c'),'action'=>'DELETE','collection'=>$collection,'id'=>$id,'entity'=>$entity,'by'=>$user['username']??'','reason'=>$reason,'before'=>$record,'reversalJournalIds'=>$reversals];
    return ['id'=>$id,'status'=>'Deleted','reversalJournalIds'=>$reversals];
}

