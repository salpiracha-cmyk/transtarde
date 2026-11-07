<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_reference.php';
function apd_tg_supported(array $j):bool {
 return ($j['entity']??'')==='TG'&&in_array($j['sourceType']??'',['TG_BANK_RECEIPT','TG_CUSTOMER_ADVANCE_RECEIVED','TG_CUSTOMER_ADVANCE_APPLIED','TG_BANK_PAYMENT','TG_SUPPLIER_ADVANCE','TG_LIABILITY_PAYMENT','TG_USD_AED_TRANSFER','INTERNAL_BANK_TRANSFER','PERSONAL_BANK_ADVANCE','TG_SUPPLIER_SERVICE_LIABILITY','CARRY_FORWARD_OPENING'],true);
}
function apd_reverse_plain(array &$s,array $u,string $id,string $reason):string {
 $j=$s['journals'][$id];if(!empty($j['reversalOf'])||!empty($j['reversedByPostId']))throw new DomainException('The posting is already reversed.');
 $rid=tt_next_post_id((array)$s['journals'],'Accounts','Journal',$j['date']);$reverse=$j;$reverse['id']=$rid;$reverse['reversalOf']=$id;$reverse['sourceType']='POST_DELETION';$reverse['meta']=['originalPostId'=>$id,'deletionReason'=>$reason];$reverse['narration']='Deleted '.$id.' — '.$reason;$reverse['createdAt']=gmdate('c');$reverse['createdBy']=$u['full_name']??$u['username']??'';$reverse['userId']=$u['id']??0;unset($reverse['amendedByPostId'],$reverse['reversedByPostId'],$reverse['openingReversalId']);
 foreach($reverse['lines'] as &$line)foreach([['debit','credit'],['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr])if(isset($line[$dr])||isset($line[$cr]))[$line[$dr],$line[$cr]]=[$line[$cr]??0,$line[$dr]??0];unset($line);[$reverse['totalDebit'],$reverse['totalCredit']]=[$j['totalCredit'],$j['totalDebit']];$s['journals'][$rid]=$reverse;$s['journals'][$id]['reversedByPostId']=$rid;return $rid;
}
/** Reverse dependent settlements before their TG source, in the same locked transaction. */
function apd_tg_cancel(array &$s,array $u,string $id,string $reason,array &$seen):void {
 if(isset($seen[$id]))throw new DomainException('A linked cancellation contains a cycle. Review the source links.');$seen[$id]=true;$j=$s['journals'][$id]??null;
 if(!$j||!apd_tg_supported($j)||!tt_post_correction_allowed($u,$j)||!tt_user_can_access_entity($u,'TG','Edit'))throw new DomainException('TG source and company Edit permission are required for every linked posting.');
 $source=$j['sourceType'];$candidateIds=[];$liabilityIds=[];$receiptIds=[];
 foreach((array)($s['exportCandidates']??[]) as $cid=>$c)if(($c['entity']??'')==='TG'&&($c['journalId']??'')===$id)$candidateIds[]=(string)$cid;
 foreach((array)($s['tgLiabilities']??[]) as $lid=>$l)if(($l['journalId']??'')===$id)$liabilityIds[]=(string)$lid;
 foreach((array)($s['tgBankTransactions']??[]) as $tid=>$t)if(($t['journalId']??'')===$id&&($t['receiptType']??'')==='CUSTOMER_ADVANCE')$receiptIds[]=(string)$tid;
 foreach((array)($s['tgAdvanceApplications']??[]) as $a){if(in_array($a['status']??'',['Deleted','Reversed','Reversed for Amendment'],true))continue;if(in_array((string)($a['receiptId']??''),$receiptIds,true)||in_array((string)($a['candidateId']??''),$candidateIds,true))apd_tg_cancel($s,$u,(string)$a['journalId'],$reason,$seen);}
 foreach((array)($s['tgBankTransactions']??[]) as $t){if(in_array($t['status']??'',['Deleted','Reversed','Reversed for Amendment'],true)||($t['journalId']??'')===$id)continue;$depends=in_array((string)($t['sourceCandidateId']??''),$candidateIds,true)||in_array((string)($t['sourceLiabilityId']??''),$liabilityIds,true)||in_array((string)($t['sourceLiabilityId']??''),$candidateIds,true);foreach((array)($t['liabilityAllocations']??[]) as $a)if(in_array((string)($a['id']??''),array_merge($candidateIds,$liabilityIds),true))$depends=true;if($depends)apd_tg_cancel($s,$u,(string)$t['journalId'],$reason,$seen);}
 foreach((array)($s['tgRemittances']??[]) as $r){if(in_array($r['status']??'',['Deleted','Reversed','Reopened','Draft','Reversed for Amendment'],true))continue;foreach((array)($r['allocations']??[]) as $a)if(in_array((string)($a['sourceLiabilityId']??''),array_merge($candidateIds,$liabilityIds),true)){require_once __DIR__.'/tg_remittance_core.php';foreach((array)($r['receiptIds']??[]) as $receiptId){$company=(string)($s['exportReceipts'][$receiptId]['entity']??'');if($company!==''&&!tt_user_can_access_entity($u,$company,'Edit'))throw new DomainException('Edit access for every linked credit-advice company is required.');}tgr_reopen($s,['remittanceId'=>$r['id'],'date'=>max($j['date'],$r['date']),'reason'=>$reason],$u);break;}}
 foreach((array)($s['tgRemittanceDrafts']??[]) as $did=>$draft)if(($draft['status']??'')==='Pending')foreach((array)($draft['allocations']??[]) as $allocation)if(in_array((string)($allocation['sourceLiabilityId']??''),array_merge($candidateIds,$liabilityIds),true)){$s['tgRemittanceDrafts'][$did]['status']='Cancelled';$s['tgRemittanceDrafts'][$did]['cancelReason']=$reason;break;}
 $rid=apd_reverse_plain($s,$u,$id,$reason);
 foreach(['tgBankTransactions','tgAdvanceApplications','tgBankTransfers','internalBankTransfers','tgLiabilities'] as $collection)foreach((array)($s[$collection]??[]) as $key=>$record)if(($record['journalId']??'')===$id){$s[$collection][$key]['status']='Deleted';$s[$collection][$key]['reversalJournalId']=$rid;$s[$collection][$key]['deleteReason']=$reason;}
 foreach($candidateIds as $cid){$s['exportCandidates'][$cid]['status']='Deleted';$s['exportCandidates'][$cid]['cancelledJournalId']=$id;$s['exportCandidates'][$cid]['journalId']='';$s['exportCandidates'][$cid]['pendingValuation']=false;}
 foreach((array)($s['carryForwardOpeningLinks']??[]) as $key=>$link)if(($link['journalId']??'')===$id){$s['carryForwardOpeningLinks'][$key]['status']='Deleted';$s['carryForwardOpeningLinks'][$key]['cancelledJournalId']=$id;$s['carryForwardOpeningLinks'][$key]['journalId']='';}
 unset($seen[$id]);
}
