<?php
declare(strict_types=1);
/** Resolve identities in a report copy, using the same explicit party/source
 * evidence as Party Ledgers. Never change posted journals or infer from narration. */
function cfp_name(array $row):string {
    foreach(['supplier','broker','customer','counterparty','party','vendor','subledger','person','payee'] as $field){$v=trim((string)($row[$field]??''));if($v!=='')return $v;}return '';
}
function cfp_books(array $state,array $masters):array {
    require_once __DIR__.'/accounts_ledger_visibility.php';
    $copy=alv_amendment_books($state);$aliases=[];
    foreach(['business_parties','export_customers'] as $type)foreach((array)($masters[$type]??[]) as $r){$name=trim((string)($r['values'][0]??''));if($name==='')continue;foreach([$r['id']??'',$name,$r['values'][1]??''] as $alias)if((string)$alias!=='')$aliases[cfp_key((string)$alias)][$name]=true;}
    $copy['journals']??=[];foreach($copy['journals'] as &$j){$meta=(array)($j['meta']??[]);
        foreach($j['lines']??[] as $i=>$line){
            $code=(string)($line['account']??'');if(!in_array($code,['1210','1220','1230','1240','1250','2110','2120','2130','2140','2190','2210','2500','2510'],true))continue;
            $party=cfp_name($line);$candidate=$state['exportCandidates'][(string)($line['candidateId']??$line['sourceCandidateId']??$meta['candidateId']??'')]??[];
            $bill=$state['commodityBills'][(string)($line['billId']??$meta['billId']??'')]??[];
            if($party===''&&in_array($meta['originalSourceType']??$j['sourceType']??'',['COMMODITY_BILL_VERIFIED'],true))$party=trim((string)(in_array($code,['2110','2210'],true)?($bill['relationshipName']??$meta['relationshipName']??''):($code==='2120'?($bill['broker']??$meta['broker']??''):'')));
            if($party===''&&$code==='1210')$party=cfp_name($candidate)?:cfp_name((array)($candidate['meta']??[]));
            foreach(['supplierBillId'=>'supplierBills','bagBillId'=>'bagSupplierBills','purchaseId'=>'otherPurchases','billId'=>'commodityBills','receiptId'=>'exportReceipts'] as $field=>$collection)if($party==='')$party=cfp_name((array)($state[$collection][(string)($line[$field]??$meta[$field]??'')]??[]));
            if($party==='')$party=cfp_name($meta);
            $matches=$aliases[cfp_key($party)]??[];if(count($matches)===1)$party=(string)array_key_first($matches);
            if($party!==''){$line['counterparty']=$party;foreach(['supplier','broker','customer','party','partyName'] as $field)if(isset($line[$field]))$line[$field]=$party;$j['lines'][$i]=$line;}
        }
    }unset($j);return $copy;
}
function cfp_key(string $v):string{return strtolower(preg_replace('/[^\p{L}\p{N}]/u','',$v)??'');}
/** One net external position per company/party/currency. Estimates remain
 * separate from actual bills. Unknown identities are held for review. */
function cfp_rows(array $source):array {
    $groups=[];$out=[];
    foreach($source['rows'] as $r){
        if(!empty($r['excluded'])){$out[]=$r;continue;}
        $code=(string)$r['account'];$party=trim((string)($r['party']??''));
        $section=$code==='1110'?'banks':(in_array($code,['1210','1220'],true)?'customers':($code==='1230'?'advances':(in_array($code,['1250','2110','2120','2130','2140','2190'],true)?'suppliers':($code==='2210'?'estimates':''))));
        if($section===''||($section!=='banks'&&$party==='')){$r['excluded']=true;$r['reason']=$section===''?'Outside the bank, customer, advance and supplier cash-flow scope.':'Party identity required; reconcile source before including this balance.';if($section!==''&&abs((float)($r['amount']??0))>.005)$source['warnings'][]=$r['reason'].' · '.implode(', ',(array)$r['postIds']);$out[]=$r;continue;}
        if($section==='banks'){$r['cashflowSection']=$section;$out[]=$r;continue;}
        $key=$r['entity'].'|'.$r['currency'].'|'.$section.'|'.cfp_key($party);
        if(!isset($groups[$key])){$groups[$key]=$r;$groups[$key]['id']='PARTY-'.substr(hash('sha256',$key),0,24);$groups[$key]['label']=$party.($section==='estimates'?' — Estimated / unbilled':'');$groups[$key]['amount']=0.;$groups[$key]['postIds']=[];$groups[$key]['cashflowSection']=$section;$groups[$key]['sourceAccounts']=[];}
        $g=&$groups[$key];$g['amount']=$r['amount']===null||$g['amount']===null?null:round($g['amount']+$r['amount'],2);$g['postIds']=array_values(array_unique(array_merge($g['postIds'],(array)$r['postIds'])));$g['sourceAccounts'][]=$code;unset($g);
    }
    foreach($groups as $r){if($r['amount']!==null&&abs($r['amount'])<.005)continue;$r['originalAmount']=$r['amount'];$r['sourceAccounts']=array_values(array_unique($r['sourceAccounts']));$r['sourceFingerprint']=hash('sha256',json_encode($r,JSON_THROW_ON_ERROR));$out[]=$r;}
    $source['rows']=$out;$source['warnings']=array_values(array_unique($source['warnings']??[]));$source['cashflowBasis']='PARTY_V2';$source['basis']='Party-wise cash position: bank balances plus customer receipts and recoverable advances, less net supplier payables and separately identified estimates. Other ledger controls and inventory are excluded. Source journals are unchanged.';return $source;
}
