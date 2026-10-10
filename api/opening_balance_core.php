<?php
declare(strict_types=1);

const JOB_DATE = '2026-07-01';
const JOB_CLEARING = '3400';

function job_authorized(array $u): bool {
    if (($u['role'] ?? '') === 'Super Admin') return true;
    if (!tt_user_can_open_module($u, 'Directors')) return false;
    $p = $u['permissions']['Directors'] ?? [];
    return $p === 'all' || (is_array($p) && (in_array('Approve', $p, true) || in_array('Approve', (array)($p['jv'] ?? []), true)));
}
function job_entities(array $u): array {
    if (($u['role'] ?? '') === 'Super Admin') return ['TTI', 'BRM', 'TG'];
    if (!job_authorized($u)) return [];
    if (tt_user_can_open_module($u, 'Accounts')) return tt_user_accounts_entities($u);
    $p = $u['permissions']['Directors'] ?? [];
    $scoped = is_array($p) && array_intersect(['entity-tti', 'entity-brm', 'entity-tg'], array_keys($p));
    return array_values(array_filter(['TTI', 'BRM', 'TG'], static fn($e) => !$scoped || array_intersect(['View', 'Approve'], (array)($p['entity-'.strtolower($e)] ?? []))));
}
function job_access(array $u, string $e, bool $write = false): void {
    if (!job_authorized($u) || !in_array($e, job_entities($u), true)) jvw_out(['ok'=>false, 'error'=>'Opening balances require Super Admin or authorised Directors access to these company books.'], 403);
    if ($write && ($u['role'] ?? '') !== 'Super Admin' && tt_user_can_open_module($u, 'Accounts')
        && !tt_user_can_access_entity($u, $e, 'Approve')) jvw_out(['ok'=>false, 'error'=>'Company approval permission is required.'], 403);
}
function job_key(string $s): string {
    $s = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
}
function job_party_name(array $line, array $meta = []): string {
    foreach ([$line, $meta] as $row) foreach (['subledger','counterparty','party','supplier','broker','customer','vendor'] as $key) {
        $name=trim((string)($row[$key]??'')); if($name!=='') {
            foreach(['business_parties','export_customers'] as $type)foreach((array)(tt_list_masters()[$type]??[]) as $r)if((string)($r['id']??'')===$name)return trim((string)($r['values'][0]??$name));
            return $name;
        }
    }
    return '';
}
function job_banks(string $e): array {
    $out = [];
    foreach ((array)(tt_list_masters()['banks'] ?? []) as $r) {
        $v = array_values((array)($r['values'] ?? [])); $link = strtoupper((string)($v[1] ?? ''));
        $owner = str_contains($link, 'BUKSH') || preg_match('/\bBRM\b/', $link) ? 'BRM' : (str_contains($link, 'TRANS GRAINS') || preg_match('/\bTG\b/', $link) ? 'TG' : (str_contains($link, 'TRANSTRADE INTERNATIONAL') || preg_match('/\bTTI\b/', $link) ? 'TTI' : ''));
        $id = (string)($r['id'] ?? '');
        if ($owner !== $e || !in_array(($v[0] ?? ''), ['Company Account','Proprietor / Owner Account','Personal Account'], true) || $id === '' || strcasecmp((string)($v[13] ?? 'Active'), 'Active') !== 0) continue;
        if (function_exists('tt_bank_can_transact') && !tt_bank_can_transact($id)) continue;
        $out[] = ['id'=>$id, 'name'=>!empty($r['linkedRetentionAccount'])?(string)($v[4]??'').' · USD':implode(' · ',array_filter([$v[4]??'',(trim((string)($v[8]??''))!==''?(string)$v[8]:(string)($v[9]??'')),strtoupper((string)($v[7]??'')),$v[3]??''])), 'currency'=>strtoupper((string)($v[7] ?? '')), 'bankName'=>(string)($v[4] ?? ''), 'accountTitle'=>(string)($v[3] ?? ''),'accountNumber'=>(string)($v[8]??''),'iban'=>(string)($v[9]??'')];
    }
    return $out;
}
function job_parties(array $s, string $e): array {
    $names = [];
    foreach (['business_parties', 'export_customers'] as $type) foreach ((array)(tt_list_masters()[$type] ?? []) as $r) {
        $name = trim((string)($r['values'][0] ?? '')); if ($name !== '') $names[job_key($name)] = $name;
    }
    foreach ((array)($s['journals'] ?? []) as $j) if (($j['entity'] ?? '') === $e && ($j['status'] ?? '') === 'Posted') foreach ((array)($j['lines'] ?? []) as $l) {
        $name = job_party_name($l); if ($name !== '') $names[job_key($name)] ??= $name;
    }
    natcasesort($names); return array_values($names);
}
function job_role_accounts(): array { return ['Supplier'=>'2110','Broker'=>'2120','Indentor'=>'2120','Local Buyer'=>'1220','Buyer'=>'1210','Customer'=>'1210','Export Buyer'=>'1210','Freight Forwarder'=>'2130','Shipping Line / Carrier'=>'2130','Transporter'=>'2130','Clearing Agent'=>'2130','Inspection'=>'2140','Fumigation'=>'2140','Service Provider'=>'2140','Bag Supplier'=>'2140','Labour Contractor'=>'2190','Agent'=>'2140','Other'=>'2140']; }
/** Management chooses a name; category determines its control account. */
function job_targets(string $e): array {
    $map=job_role_accounts();
    $out=[];
    foreach(['business_parties','export_customers'] as $type)foreach((array)(tt_list_masters()[$type]??[]) as $r){
        $v=(array)($r['values']??[]);$name=trim((string)($v[0]??''));if($name===''||strcasecmp((string)($v[10]??'Active'),'Inactive')===0)continue;
        $roles=$type==='export_customers'?['Export Buyer']:tt_business_party_categories($v[2]??'');
        $heads=[];foreach($roles as $role)foreach($map as $category=>$code)if(strcasecmp($role,$category)===0)$heads[$code][]=$category;
        foreach($heads as $code=>$categories)$out[]=['key'=>$type.':'.(string)($r['id']??job_key($name)).':'.$code,'label'=>$name.(trim((string)($v[1]??''))!==''?' ('.trim((string)$v[1]).')':'').' — '.implode(' / ',$categories),'name'=>$name,'account'=>(string)$code,'party'=>$name,'bankId'=>'','role'=>$categories[0],'kind'=>'party'];
    }
    foreach(job_banks($e) as $b)$out[]=['key'=>'bank:'.$b['id'],'label'=>$b['name'],'name'=>$b['name'],'account'=>'1110','party'=>'','bankId'=>$b['id'],'kind'=>'bank'];
    usort($out,static fn($a,$b)=>strcasecmp($a['label'],$b['label']));return $out;
}
function job_balances(array $s, string $e): array {
    $out = [];
    foreach ((array)($s['journals'] ?? []) as $j) {
        if (($j['entity'] ?? '') !== $e || ($j['status'] ?? '') !== 'Posted' || (string)($j['date'] ?? '') > JOB_DATE) continue;
        foreach ((array)($j['lines'] ?? []) as $l) {
            $code = (string)($l['account'] ?? ''); if ($code === JOB_CLEARING) continue;
            $party = in_array($code,['1110','1120'],true)||empty(jvw_catalog()[$code]['subledger'])?'':job_party_name($l,(array)($j['meta']??[]));
            $bank = (string)($l['bankAccountId'] ?? $j['meta']['bankAccountId'] ?? '');
            $key = $code.'|'.job_key($party).'|'.$bank;
            $out[$key] ??= ['account'=>$code, 'party'=>$party, 'bankId'=>$bank, 'balance'=>0, 'postIds'=>[]];
            $out[$key]['balance'] = round($out[$key]['balance'] + (float)($l['debit'] ?? 0) - (float)($l['credit'] ?? 0), 2);
            $out[$key]['postIds'][] = (string)($j['id'] ?? '');
        }
    }
    return array_values($out);
}
function job_payload(array $s, string $e, array $u): array {
    if (!job_authorized($u)) return ['allowed'=>false];
    job_access($u, $e);
    $entries = [];
    foreach ((array)($s['journals'] ?? []) as $j) if (($j['entity'] ?? '') === $e && ($j['sourceType'] ?? '') === 'OPENING_BALANCE_BF') $entries[] = $j;
    usort($entries, static fn($a,$b) => strcmp($b['id'], $a['id']));
    $accounts = [];
    foreach (jvw_catalog() as $a) if ((string)$a['code'] !== JOB_CLEARING) $accounts[] = ['code'=>(string)$a['code'], 'name'=>$a['name'], 'requiresSubledger'=>!in_array((string)$a['code'],['1110','1120'],true)&&!empty($a['subledger'])];
    return ['allowed'=>true, 'enabled'=>($s['openingBalanceSettings'][$e]['enabled'] ?? true) === true, 'canDisable'=>($u['role'] ?? '') === 'Super Admin', 'date'=>JOB_DATE, 'currency'=>$e==='TG'?'AED':'PKR', 'entities'=>job_entities($u), 'accounts'=>$accounts, 'banks'=>job_banks($e), 'parties'=>job_parties($s,$e), 'targets'=>job_targets($e), 'balances'=>job_balances($s,$e), 'entries'=>$entries];
}
function job_amount(mixed $v, string $label): float {
    if (!is_numeric($v) || !is_finite((float)$v) || (float)$v <= 0 || (float)$v > 100000000000) throw new DomainException($label.' must be a positive amount.');
    $n = round((float)$v, 2); if ($n <= 0) throw new DomainException($label.' must be greater than zero.'); return $n;
}
function job_post(array &$s, string $e, array $b, array $u): array {
    job_access($u,$e,true);
    if (($s['openingBalanceSettings'][$e]['enabled'] ?? true) !== true) throw new DomainException('Opening balance entry is disabled for these company books.');
    if (($b['date'] ?? JOB_DATE) !== JOB_DATE) throw new DomainException('Opening balance date must be 1 July 2026.');
    if(!empty($b['targetKey'])){
        $target=null;foreach(job_targets($e) as $row)if($row['key']===$b['targetKey'])$target=$row;
        if(!$target)throw new DomainException('This party or bank is no longer available. Refresh and select its current master record.');
        $b['account']=$target['account'];$b['party']=$target['party'];$b['bankId']=$target['bankId'];
    }
    $code = trim((string)($b['account'] ?? '')); $catalog = jvw_catalog();
    if ($code === JOB_CLEARING || !isset($catalog[$code])) throw new DomainException('Select an approved opening account.');
    $side = (string)($b['side'] ?? ''); if (!in_array($side,['Debit','Credit'],true)) throw new DomainException('Select Debit or Credit.');
    if (isset($b['lines']) || isset($b['debit']) || isset($b['credit'])) throw new DomainException('Use one amount and select Debit or Credit.');
    $amount = job_amount($b['amount'] ?? null, 'Amount'); $base = $e==='TG'?'AED':'PKR'; $currency = $base;
    $party = jvw_text($b['party'] ?? '',120,'Party / subsidiary');
    foreach (job_parties($s,$e) as $name) if (job_key($name) === job_key($party)) {$party=$name;break;}
    if (!empty($catalog[$code]['subledger']) && !in_array($code,['1110','1120'],true) && $party === '') throw new DomainException('Enter the party or subsidiary for this account.');
    if (empty($catalog[$code]['subledger']) && $party !== '') throw new DomainException('This account does not use a party or subsidiary.');
    $bankId = trim((string)($b['bankId'] ?? '')); $bank = null;
    if ($code === '1110') {
        foreach (job_banks($e) as $row) if ($row['id'] === $bankId) $bank=$row;
        if (!$bank) throw new DomainException('Select an active bank account belonging to this company.');
        $party='';$currency=$bank['currency'];
    } elseif ($bankId !== '') throw new DomainException('A bank account is only applicable to Bank opening balances.');
    $native = $amount; $rate = 1.0;
    if ($currency !== $base) {
        $rate = $b['rate'] ?? null;
        if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate <= 0 || (float)$rate > 1000000) throw new DomainException('Enter the opening exchange rate to '.$base.'.');
        $rate=(float)$rate;$amount=job_amount($native*$rate,'Book amount');
    }
    foreach (job_balances($s,$e) as $existing) if ($existing['account']===$code && job_key($existing['party'])===job_key($party) && $existing['bankId']===$bankId && abs($existing['balance'])>.005) {
        jvw_out(['ok'=>false,'error'=>'This account / party already has a balance at 1 July 2026. Review its existing Post IDs before adding an opening balance.','existing'=>$existing],409);
    }
    $note=jvw_text($b['note'] ?? '',400,'Details');$ref=jvw_text($b['reference'] ?? '',120,'Reference');jvw_duplicate($s,$e,$ref);
    $narr='OPENING BALANCE B/F'.($note!==''?' — '.$note:'');
    $extra=['subledger'=>$party,'counterparty'=>$party,'memo'=>$narr,'currency'=>$currency,'nativeCurrency'=>$currency,'nativeDebit'=>$side==='Debit'?$native:0,'nativeCredit'=>$side==='Credit'?$native:0];
    if ($bank) $extra+=['bankAccountId'=>$bankId,'bankName'=>$bank['bankName'],'bankAccountTitle'=>$bank['accountTitle'],'bankDebit'=>$side==='Debit'?$native:0,'bankCredit'=>$side==='Credit'?$native:0,'rate'=>$rate];
    if ($code==='1120') $extra['paymentAccountId']='CASH|'.$e;
    $lines=[['account'=>$code,'accountName'=>$catalog[$code]['name'],'debit'=>$side==='Debit'?$amount:0,'credit'=>$side==='Credit'?$amount:0]+$extra,
        ['account'=>JOB_CLEARING,'accountName'=>'Opening Balance Clearing','debit'=>$side==='Credit'?$amount:0,'credit'=>$side==='Debit'?$amount:0,'memo'=>$narr,'currency'=>$base,'nativeCurrency'=>$base,'nativeDebit'=>$side==='Credit'?$amount:0,'nativeCredit'=>$side==='Debit'?$amount:0]];
    $id=tt_next_post_id((array)$s['journals'],'Accounts','Journal',JOB_DATE);$now=gmdate('c');$by=(string)($u['full_name']??$u['username']??'Management');
    $s['journals'][$id]=['id'=>$id,'entity'=>$e,'date'=>JOB_DATE,'sourceType'=>'OPENING_BALANCE_BF','reference'=>$ref?:$id,'narration'=>$narr,'lines'=>$lines,'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>['openingBalance'=>true,'bankAccountId'=>$bankId,'openingAccount'=>$code,'openingParty'=>$party,'transactionCurrency'=>$currency,'openingRate'=>$rate],'createdAt'=>$now,'createdBy'=>$by,'userId'=>(int)($u['id']??0),'approvedAt'=>$now,'approvedBy'=>$by,'approvedByUserId'=>(int)($u['id']??0),'reversalOf'=>null];
    $draft=jvw_next((array)$s['jvDrafts'],'JVD');$s['jvDrafts'][$draft]=$s['journals'][$id];$s['jvDrafts'][$draft]['id']=$draft;$s['jvDrafts'][$draft]['journalId']=$id;$s['jvDrafts'][$draft]['openingBalance']=true;$s['jvDrafts'][$draft]['updatedAt']=$now;
    return ['journalId'=>$id,'jvId'=>$draft,'status'=>'Posted'];
}
function job_action(array &$s, string $e, array $b, array $u): array {
    job_access($u,$e,true);$action=(string)$b['action'];
    $key=(string)($b['requestKey']??'');if(!preg_match('/^[a-zA-Z0-9._:-]{8,128}$/',$key))throw new DomainException('Reopen the form before saving.');
    $identity=$e.'|'.(string)($u['id']??0).'|'.$key;$hash=hash('sha256',json_encode($b));
    if(isset($s['openingBalanceRequests'][$identity])){$old=$s['openingBalanceRequests'][$identity];if($old['hash']!==$hash)throw new DomainException('This request has already been used. Reopen the form.');return $old['result']+['replayed'=>true];}
    if($action==='post_opening_balance')$result=job_post($s,$e,$b,$u);
    elseif($action==='set_opening_balance_enabled'){
        if(($u['role']??'')!=='Super Admin')jvw_out(['ok'=>false,'error'=>'Only Super Admin can enable or disable opening entry.'],403);
        if(!isset($b['enabled'])||!is_bool($b['enabled']))throw new DomainException('Select the opening entry setting.');
        $s['openingBalanceSettings'][$e]=['enabled'=>$b['enabled'],'changedAt'=>gmdate('c'),'changedBy'=>(string)($u['full_name']??$u['username']??''),'userId'=>(int)($u['id']??0)];$result=['enabled'=>$b['enabled']];
    }elseif($action==='reverse_opening_balance'){
        $id=(string)($b['postId']??'');$j=$s['journals'][$id]??null;
        if(!is_array($j)||($j['entity']??'')!==$e||($j['sourceType']??'')!=='OPENING_BALANCE_BF'||!empty($j['openingReversalId']))throw new DomainException('Select an unreversed opening balance in these company books.');
        foreach((array)($s['carryForwardOpeningLinks']??[]) as $link)if(($link['journalId']??'')===$id)throw new DomainException('This opening balance is assigned to a carry-forward shipment. Correct its linked transactions through Accounts before reversing.');
        $reason=jvw_text($b['reason']??'',300,'Reversal reason',true);$rid=tt_next_post_id((array)$s['journals'],'Accounts','Journal',JOB_DATE);$reverse=$j;$reverse['id']=$rid;$reverse['sourceType']='OPENING_BALANCE_REVERSAL';$reverse['reference']=$id;$reverse['reversalOf']=$id;$reverse['narration']='OPENING BALANCE REVERSAL — '.$reason;
        foreach($reverse['lines'] as &$l)foreach([['debit','credit'],['nativeDebit','nativeCredit'],['bankDebit','bankCredit']] as [$dr,$cr])if(isset($l[$dr])||isset($l[$cr])){[$l[$dr],$l[$cr]]=[$l[$cr]??0,$l[$dr]??0];}unset($l);
        $reverse['createdAt']=gmdate('c');$reverse['createdBy']=(string)($u['full_name']??$u['username']??'');$reverse['userId']=(int)($u['id']??0);$reverse['approvedBy']=$reverse['createdBy'];$reverse['approvedByUserId']=$reverse['userId'];$reverse['approvedAt']=$reverse['createdAt'];
        $s['journals'][$rid]=$reverse;$s['journals'][$id]['openingReversalId']=$rid;
        foreach($s['jvDrafts'] as &$d)if(($d['journalId']??'')===$id){$d['status']='Reversed';$d['reversalJournalId']=$rid;$d['reversalReason']=$reason;$d['updatedAt']=gmdate('c');}unset($d);
        $result=['journalId'=>$rid,'status'=>'Reversed'];
    }else throw new DomainException('Unknown opening balance action.');
    $s['openingBalanceRequests'][$identity]=['hash'=>$hash,'result'=>$result];return $result;
}

