<?php
declare(strict_types=1);
require_once __DIR__.'/mill_recovery_core.php';

const TT_PC_ACCOUNTS = TT_DATA_DIR . '/accounts.json';
const TT_PC_OPERATIONS = TT_DATA_DIR . '/operations.json';

function pc_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pc_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $p = $user['permissions']['Accounts'] ?? null;
    if ($p === 'all') return true;
    if (!is_array($p)) return false;
    if (in_array('Create', $p, true) || in_array('Edit', $p, true) || in_array('Approve', $p, true)) return true;
    foreach ($p as $actions) {
        if (is_array($actions) && (in_array('Create', $actions, true) || in_array('Edit', $actions, true) || in_array('Approve', $actions, true))) return true;
    }
    return false;
}

function pc_require_entity(array $user,string $entity,bool $write=false): void {
    if (($user['role'] ?? '') === 'Super Admin') return;
    $allowed=$write?(tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')||tt_user_can_access_entity($user,$entity,'Approve')):tt_user_can_access_entity($user,$entity,'View');
    if(!$allowed) pc_out(['ok'=>false,'error'=>'You do not have permission for this legal entity.'],403);
}

function pc_accounts_default(): array {
    return [
        'revision' => 0,
        'journals' => [],
        'events' => [],
        'commodityBills' => [],
        'inventoryCostRates' => [],
        'localSalesCostRates' => [],
        'productionCostRuns' => [],
    ];
}

function pc_read_accounts(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_PC_ACCOUNTS)) return pc_accounts_default();
    $h = fopen(TT_PC_ACCOUNTS, 'r');
    if ($h === false || !flock($h, LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try { $raw = stream_get_contents($h); }
    finally { flock($h, LOCK_UN); fclose($h); }
    $decoded = $raw ? json_decode($raw, true,512,JSON_THROW_ON_ERROR) : null;
    if($raw!==''&&!is_array($decoded))throw new RuntimeException('Accounts source is invalid.');return is_array($decoded)?array_replace_recursive(pc_accounts_default(),$decoded):pc_accounts_default();
}

function pc_read_operations(): array {return mr_read_operations_source(TT_PC_OPERATIONS);}

function pc_op(array $ops, string $key): array {
    $raw = $ops['values'][$key] ?? '';
    if (!is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($decoded)||!array_is_list($decoded))throw new RuntimeException('Invalid operational rows.');return $decoded;
}

function pc_date(string $value): string {
    if (!mr_valid_date($value)) pc_out(['ok' => false, 'error' => 'Valid date required.'], 422);
    return $value;
}

function pc_entity(string $value): string {
    $value = strtoupper(trim($value));
    if (!in_array($value, ['TTI', 'BRM'], true)) pc_out(['ok' => false, 'error' => 'Production costing is available for TTI / BRM Pakistan books.'], 422);
    return $value;
}

function pc_norm(string $value): string {
    $value = preg_replace('/[^A-Z0-9]+/i', ' ', trim($value)) ?? trim($value);
    return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
}

function pc_variety_key(string $value): string {
    $u = pc_norm($value);
    if (str_contains($u, 'IRRI 6')) $base = 'IRRI-6';
    elseif (str_contains($u, '1121')) $base = '1121';
    elseif (str_contains($u, 'PK 386')) $base = 'PK-386';
    elseif (str_contains($u, 'SUPER KERNEL')) $base = 'SUPER KERNEL';
    elseif (str_contains($u, 'D 98')) $base = 'D-98';
    elseif (preg_match('/(^| )C 9( |$)/', $u)) $base = 'C-9';
    else $base = $u ?: 'RICE';
    if (str_contains($u, 'PARBOIL')||str_contains($u,'SELLA')) $base .= ' PARBOIL';
    return $base;
}

function pc_product_key(string $value): string {
    $u = pc_norm($value);
    if (str_starts_with($u, 'B2 CSR')) return 'B2 CSR';
    if (str_starts_with($u, 'B2') || str_contains($u, 'BROKEN B2')) return 'B2';
    if ($u === 'CSR') return 'CSR';
    if ($u === 'B3') return 'B3';
    if ($u === 'PADDY') return 'PADDY';
    if ($u === 'POWDER') return 'POWDER';
    return $u;
}

function pc_is_waste(string $value): bool {
    $u = pc_norm($value);
    return str_contains($u, 'STONE') || str_contains($u, 'SUTLI') || str_contains($u, 'DUST');
}

function pc_next(array $items, string $prefix): string {
    $n = count($items) + 1;
    do {
        $id = $prefix . '-' . gmdate('Y') . '-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
        $n++;
    } while (isset($items[$id]));
    return $id;
}

function pc_rates(array $store): array {
    $rows = [];
    foreach ((array)($store['localSalesCostRates'] ?? []) as $r) if (is_array($r)) $rows[] = $r;
    foreach ((array)($store['inventoryCostRates'] ?? []) as $r) if (is_array($r)) $rows[] = $r;
    return $rows;
}

function pc_rate_for(array $store, string $entity, string $product, string $date): ?array {
    $best = null;
    $key = pc_product_key($product);
    foreach (pc_rates($store) as $r) {
        if (($r['status'] ?? 'Active') !== 'Active' || ($r['entity'] ?? '') !== $entity) continue;
        $matches = pc_product_key((string)($r['product'] ?? '')) === $key;
        if (!$matches) {
            foreach ((array)($r['aliases'] ?? []) as $alias) {
                if (pc_product_key((string)$alias) === $key) { $matches = true; break; }
            }
        }
        if (!$matches) continue;
        $from = (string)($r['effectiveFrom'] ?? '');
        if ($from === '' || $from > $date) continue;
        if ($best === null || $from > (string)($best['effectiveFrom'] ?? '')) $best = $r;
    }
    return $best;
}

function pc_bill_values(array $store): array {
    $out = [];
    foreach ((array)($store['commodityBills'] ?? []) as $bill) {
        if (!is_array($bill) || ($bill['status'] ?? '') !== 'Verified / Posted') continue;
        $allocations = (array)($bill['receiptAllocations'] ?? []);
        if (!$allocations) continue;
        $base = 0.0;
        foreach ($allocations as $a) $base += max(0, (float)($a['provisionalAmount'] ?? 0));
        $inventoryValue = round((float)($bill['finalCommodityValue'] ?? 0) + (float)($bill['brokerageGross'] ?? 0), 2);
        if ($inventoryValue <= 0) continue;
        $used = 0.0;
        $validIndexes = array_keys($allocations);
        $lastIndex = end($validIndexes);
        foreach ($allocations as $i => $a) {
            $sourceKey = trim((string)($a['sourceKey'] ?? ''));
            if ($sourceKey === '') continue;
            if ($i === $lastIndex) $part = round($inventoryValue - $used, 2);
            else {
                $weight = $base > 0 ? max(0, (float)($a['provisionalAmount'] ?? 0)) / $base : 1 / max(1, count($allocations));
                $part = round($inventoryValue * $weight, 2);
            }
            $used = round($used + $part, 2);
            $out[$sourceKey] = ['value' => $part, 'billId' => (string)($bill['id'] ?? ''), 'final' => true];
        }
    }
    return $out;
}

function pc_receipts(array $store): array {
    $billed = pc_bill_values($store);
    $rows = [];
    foreach ((array)($store['events'] ?? []) as $event) {
        if (!is_array($event) || ($event['eventType'] ?? '') !== 'COMMODITY_RECEIPT_ACCEPTED') continue;
        $journal = $store['journals'][(string)($event['journalId'] ?? '')] ?? null;
        if (!is_array($journal)) continue;
        $meta = is_array($journal['meta'] ?? null) ? $journal['meta'] : [];
        $kg = (float)($meta['payableWeightKg'] ?? 0);
        if ($kg <= 0) continue;
        $sourceKey = (string)($event['sourceKey'] ?? '');
        $bill = $billed[$sourceKey] ?? null;
        $variety = (string)($meta['variety'] ?? '');
        $rows[] = [
            'entity' => (string)($event['entity'] ?? $journal['entity'] ?? 'TTI'),
            'date' => (string)($journal['date'] ?? ''),
            'sourceKey' => $sourceKey,
            'variety' => $variety,
            'varietyKey' => pc_variety_key($variety),
            'kg' => $kg,
            'value' => $bill ? (float)$bill['value'] : (float)($journal['totalDebit'] ?? 0),
            'final' => $bill !== null,
            'billId' => $bill['billId'] ?? '',
        ];
    }
    usort($rows, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
    return $rows;
}

function pc_production(array $ops,string $entity='TTI'): array {
    $rows=[];
    foreach(pc_op($ops,'tt30prod') as $p){
        if(!is_array($p)||strtoupper((string)($p['entity']??'TTI'))!==$entity)continue;
        $date=(string)($p['date']??'');if(!mr_valid_date($date))continue;
        $outputs=[];$readyKg=0.;foreach(mr_output_rows($p) as $o){$ready=$o['kind']==='finished';$outputs[]=['product'=>$o['product'],'kg'=>$o['kg'],'ready'=>$ready];if($ready)$readyKg+=$o['kg'];}
        if(!$outputs&&mr_actual_input($p)===null)continue;
        $input=mr_actual_input($p);$variety=(string)($p['sourceVariety']??$p['displayName']??$p['variety']??'Rice');
        $rows[]=['id'=>(string)($p['id']??($date.'|'.($p['shift']??'').'-'.count($rows))),'entity'=>$entity,'date'=>$date,'shift'=>(string)($p['shift']??''),'variety'=>$variety,'varietyKey'=>pc_variety_key($variety),'inputKg'=>$input??0,'inputComplete'=>$input!==null&&$input>0,'readyKg'=>round($readyKg,3),'outputs'=>$outputs,'source'=>$p];
    }
    usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['shift'],$b['shift']));return $rows;
}

function pc_group_production(array $production): array {
    $groups = [];
    foreach ($production as $p) {
        $id = $p['entity'].'|'.$p['date'].'|'.$p['varietyKey'];
        if (!isset($groups[$id])) {
            $groups[$id] = [
                'id' => $id,
                'date' => $p['date'],
                'variety' => $p['variety'],
                'varietyKey' => $p['varietyKey'],
                'millInputs'=>[], 'inputKg' => 0.0,'inputComplete'=>true,'requiresInputStockCost'=>false,'sourceRuns'=>[],
                'readyKg' => 0.0,
                'outputs' => [],
                'runIds' => [],
            ];
        }
        $mill=pc_norm((string)($p['source']['millName']??$p['source']['locationName']??''));
        if (!isset($groups[$id]['millInputs'][$mill])) $groups[$id]['millInputs'][$mill]=['kg'=>0.0,'complete'=>true];
        $groups[$id]['millInputs'][$mill]['kg']+=(float)$p['inputKg'];
        $groups[$id]['millInputs'][$mill]['complete']=$groups[$id]['millInputs'][$mill]['complete']&&$p['inputComplete'];
        $groups[$id]['inputKg'] += (float)$p['inputKg'];$groups[$id]['inputComplete']=$groups[$id]['inputComplete']&&$p['inputComplete'];$groups[$id]['sourceRuns'][]=$p['source'];$groups[$id]['requiresInputStockCost']=$groups[$id]['requiresInputStockCost']||!in_array(strtoupper((string)($p['source']['inputStage']??'RAW')),['','RAW'],true);
        $groups[$id]['readyKg'] += (float)$p['readyKg'];
        $groups[$id]['runIds'][] = $p['id'];
        foreach ($p['outputs'] as $o) {
            $name = (string)$o['product'];
            if (!isset($groups[$id]['outputs'][$name])) $groups[$id]['outputs'][$name] = ['product' => $name, 'kg' => 0.0, 'ready' => $o['ready']];
            $groups[$id]['outputs'][$name]['kg'] += (float)$o['kg'];
        }
    }
    foreach ($groups as &$g) {
        $g['inputKg'] = round($g['inputKg'], 3);$g['productionFingerprint']=mr_fingerprint($g['sourceRuns']);unset($g['sourceRuns']);
        $g['readyKg'] = round($g['readyKg'], 3);
        $g['outputs'] = array_values($g['outputs']);
        foreach ($g['outputs'] as &$o) $o['kg'] = round((float)$o['kg'], 3);
        unset($o);
    }
    unset($g);
    uasort($groups, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']) ?: strcmp((string)$a['varietyKey'], (string)$b['varietyKey']));
    return $groups;
}

function pc_cost_sources(array $ops, array $groups, array $store = [], string $entity = 'TTI'): array {
    $sources=[];
    $add=static function(string $id,string $type,string $from,string $to,float $amount,string $reference,string $mill,array $provenance=[])use(&$sources,$groups):void {
        if ($amount<=0||!mr_valid_date($from)||!mr_valid_date($to)||$to<$from) return;
        $amount=round($amount,2);$scope=pc_norm($mill);$kg=0.0;$complete=$scope!==''&&empty($provenance['sourceAmended'])&&($provenance['postingVerified']??true);$eligible=[];
        foreach ($groups as $g) {
            if ($scope===''||$g['date']<$from||$g['date']>$to) continue;
            $part=$g['millInputs'][$scope]??['kg'=>0,'complete'=>false];
            if ($part['kg']>0) {$kg+=(float)$part['kg'];$complete=$complete&&!empty($part['complete']);$eligible[$g['id']]=(float)$part['kg'];}
        }
        $allocations=[];$used=0.0;$last=array_key_last($eligible);
        foreach ($eligible as $key=>$part) {$share=$key===$last?round($amount-$used,2):round($amount*$part/$kg,2);$allocations[$key]=$share;$used=round($used+$share,2);}
        $sources[]=['id'=>$id,'type'=>$type,'from'=>$from,'to'=>$to,'amount'=>round($amount,2),'reference'=>$reference,'millName'=>$mill,'millScope'=>$scope,'productionKg'=>round($kg,3),'ratePerKg'=>$kg>0?$amount/$kg:0,'inputComplete'=>$complete&&$kg>0,'allocations'=>$allocations,
            'allocationStatus'=>$scope===''?'Mill location unverified':($kg>0?'Allocated to this mill production':'No confirmed production at this mill in this period')]+$provenance;
    };
    $booked=[];
    foreach ((array)($store['millLiabilities']??[]) as $record) {
        if (!is_array($record)||($record['entity']??'')!==$entity) continue;
        $booked[(string)($record['sourceCollection']??'').'|'.(string)($record['sourceRecordId']??'')]=$record;
    }
    $collections=['tt38kebills'=>['KE','Electricity'],'tt38labourbills'=>['LAB','Labour'],'tt37processingexpenses'=>['EXP','Processing Expense'],'tt38reprocessbills'=>['RP','Reprocessing']];
    $seen=[];$seenElectricity=[];
    foreach ($collections as $collection=>[$prefix,$type]) {
        $rows=pc_op($ops,$collection);
        // The immutable posted snapshot remains a source if an operational row disappears.
        foreach ($booked as $key=>$record) if (($record['sourceCollection']??'')===$collection&&!array_filter($rows,static fn($r)=>(string)($r['id']??'')===(string)$record['sourceRecordId'])&&is_array($record['sourceSnapshot']??null)) $rows[]=$record['sourceSnapshot'];
        foreach ($rows as $current) {
            if (!is_array($current)||strtoupper((string)($current['entity']??''))!==$entity) continue;
            $key=$collection.'|'.(string)($current['id']??'');if(isset($seen[$key]))continue;$seen[$key]=true;
            $record=$booked[$key]??null;
            if (is_array($record)&&($record['status']??'')==='Reversed') continue;
            $x=is_array($record['sourceSnapshot']??null)?$record['sourceSnapshot']:$current;
            // Maize handling/packing is not a Rice milling conversion cost.
            if ($prefix==='LAB'&&in_array(strtoupper(trim((string)($x['commodity']??''))),['MAIZE','CORN','MAKAI'],true)) continue;
            $journal=is_array($record)?($store['journals'][$record['journalId']??'']??null):null;
            $verified=!is_array($record)||(is_array($journal)&&($journal['status']??'')==='Posted'&&($journal['meta']['millSourceKey']??'')===($record['key']??''));
            $provenance=['sourceCollection'=>$collection,'sourceRecordId'=>(string)($x['id']??''),'postingVerified'=>$verified,'accountsStatus'=>is_array($record)?($verified?'Posted':'Posting requires reconciliation'):'Operational bill — Accounts posting unverified','postId'=>(string)($record['journalId']??''),'sourceAmended'=>is_array($record)&&$current!==$x];
            $from=(string)($prefix==='KE'?($x['readFrom']??$x['date']??''):($x['from']??$x['date']??''));$to=(string)($prefix==='KE'?($x['readTo']??$x['date']??''):($x['till']??$x['date']??''));
            $ref=(string)($x['billNo']??$x['ref']??'');$mill=(string)($x['millName']??$x['locationName']??$x['mill']??'');
            $amount=(float)($x['amount']??0);
            $add($prefix.'|'.($x['id']??$ref),$prefix==='EXP'?(string)($x['type']??$type):$type,$from,$to,$amount,$ref,$mill,$provenance);
            if ($prefix==='KE') $seenElectricity[strtoupper(trim($ref)).'|'.$from.'|'.$to.'|'.round($amount,2).'|'.pc_norm($mill)]=true;
        }
    }
    foreach ((array)($store['utilityPayments']??[]) as $bill) {
        if (!is_array($bill)||($bill['entity']??'')!==$entity||($bill['status']??'')==='Deleted'||empty($bill['productionCostEligible'])) continue;
        $from=(string)($bill['readFrom']??$bill['paymentDate']??'');$to=(string)($bill['readTo']??$bill['paymentDate']??'');$reference=(string)($bill['reference']??'');$mill=(string)($bill['locationName']??'');
        $signature=strtoupper(trim($reference)).'|'.$from.'|'.$to.'|'.round((float)($bill['amount']??0),2).'|'.pc_norm($mill);
        if (($bill['utilityType']??'')==='ELECTRICITY'&&$reference!==''&&isset($seenElectricity[$signature])) continue;
        $add('UTILITY|'.$bill['id'],(string)($bill['utilityTypeName']??'Mill Utility'),$from,$to,(float)($bill['amount']??0),$reference,$mill,['accountsStatus'=>'Posted payment','postId'=>(string)($bill['journalId']??'')]);
    }
    return $sources;
}

function pc_approved(array $store,array $group,string $entity):?array {
    $legacy=$group['date'].'|'.$group['varietyKey'];
    $saved=$store['productionCostRuns'][$group['id']]??$store['productionCostRuns'][$legacy]??null;
    return is_array($saved)&&($saved['entity']??'TTI')===$entity?$saved:null;
}

function pc_calculate(array $store, array $ops, string $entity): array {
    $groups = pc_group_production(pc_production($ops,$entity));
    $receipts = array_values(array_filter(pc_receipts($store), static fn($r) => ($r['entity'] ?? 'TTI') === $entity));
    $sources = pc_cost_sources($ops, $groups, $store, $entity);

    $events = [];
    foreach ($receipts as $r) $events[] = ['date' => $r['date'], 'kind' => 'R', 'priority' => 0, 'data' => $r];
    foreach ($groups as $id => $g) $events[] = ['date' => $g['date'], 'kind' => 'P', 'priority' => 1, 'id' => $id, 'data' => $g];
    usort($events, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']) ?: ((int)$a['priority'] <=> (int)$b['priority']));

    $pool = [];$uncertain=[];
    $result = [];
    foreach ($events as $event) {
        if ($event['kind'] === 'R') {
            $r = $event['data'];
            $key = $r['varietyKey'];
            if (!isset($pool[$key])) $pool[$key] = ['kg' => 0.0, 'value' => 0.0, 'provisionalKg' => 0.0];
            $pool[$key]['kg'] += (float)$r['kg'];
            $pool[$key]['value'] += (float)$r['value'];
            if (empty($r['final'])) $pool[$key]['provisionalKg'] += (float)$r['kg'];
            continue;
        }

        $g = $event['data'];
        $key = $g['varietyKey'];
        $state = $pool[$key] ?? ['kg' => 0.0, 'value' => 0.0, 'provisionalKg' => 0.0];
        $available = (float)$state['kg'];
        $average = $available > 0 ? (float)$state['value'] / $available : 0.0;
        $approved=pc_approved($store,$g,$entity);$sourceChanged=$approved&&!empty($approved['calculation']['productionFingerprint'])&&!hash_equals($approved['calculation']['productionFingerprint'],$g['productionFingerprint']);$poolWasUncertain=!empty($uncertain[$key]);$need=(float)$g['inputKg'];if($approved&&isset($approved['calculation']['inputKg']))$need=(float)$approved['calculation']['inputKg'];if((empty($g['inputComplete'])&&!$approved)||!empty($g['requiresInputStockCost'])||$sourceChanged)$uncertain[$key]=true;
        $enough = $available + 0.001 >= $need;
        $share = $available > 0 ? min(1, $need / $available) : 0;
        $provisionalConsumed = (float)$state['provisionalKg'] * $share;
        $rawCost = round($need * $average, 2);

        if ($available > 0) {
            $consume = min($need, $available);
            $valueConsume = $consume * $average;
            $provisionalConsume = (float)$state['provisionalKg'] * ($consume / $available);
            $state['kg'] -= $consume;
            $state['value'] -= $valueConsume;
            $state['provisionalKg'] = max(0, (float)$state['provisionalKg'] - $provisionalConsume);
            $pool[$key] = $state;
        }

        $conversion = 0.0;
        $sourceRows = [];
        foreach ($sources as $source) {
            if ($g['date'] < $source['from'] || $g['date'] > $source['to']) continue;
            if (!array_key_exists($g['id'],$source['allocations'])) continue;
            $allocated = (float)$source['allocations'][$g['id']];
            $conversion += $allocated;
            $sourceRows[] = [
                'id' => $source['id'],
                'type' => $source['type'],'millName'=>$source['millName'],'accountsStatus'=>$source['accountsStatus']??'Unverified','postId'=>$source['postId']??'','sourceAmended'=>$source['sourceAmended']??false,'postingVerified'=>$source['postingVerified']??true,
                'reference' => $source['reference'],
                'allocatedAmount' => round($allocated, 2),
                'ratePerKg' => round((float)$source['ratePerKg'], 6),'inputComplete'=>$source['inputComplete']??true,
                'from' => $source['from'],
                'to' => $source['to'],
            ];
        }
        $conversion = round($conversion, 2);
        $jointCost = round($rawCost + $conversion, 2);

        $byProductValue = 0.0;
        $byProducts = [];
        $missing = [];
        foreach ($g['outputs'] as $output) {
            if (!empty($output['ready'])) continue;
            $product = (string)$output['product'];
            $kg = (float)$output['kg'];
            if (pc_is_waste($product)) {
                $byProducts[] = ['product' => $product, 'kg' => $kg, 'costPerKg' => 0, 'value' => 0, 'source' => 'Waste / Nil value'];
                continue;
            }
            $savedRate = pc_rate_for($store, $entity, $product, $g['date']);
            if (!$savedRate) {
                $missing[] = pc_product_key($product);
                $byProducts[] = ['product' => $product, 'kg' => $kg, 'costPerKg' => null, 'value' => null, 'source' => 'Missing'];
                continue;
            }
            $costPerKg = (float)($savedRate['costPerKg'] ?? 0);
            $value = round($kg * $costPerKg, 2);
            $byProductValue += $value;
            $byProducts[] = ['product' => $product, 'kg' => $kg, 'costPerKg' => $costPerKg, 'value' => $value, 'source' => (string)($savedRate['id'] ?? 'Saved cost')];
        }
        $missing = array_values(array_unique($missing));
        $readyCost = round($jointCost - $byProductValue, 2);
        $readyRate = $g['readyKg'] > 0 && $readyCost > 0 ? $readyCost / (float)$g['readyKg'] : 0;
        $unknownCostLocation=false;foreach($sources as $s)if($s['millScope']===''&&$g['date']>=$s['from']&&$g['date']<=$s['to'])$unknownCostLocation=true;
        $allocationComplete=!$unknownCostLocation;foreach($sourceRows as $source)if(($source['inputComplete']??true)===false)$allocationComplete=false;

        $numericSourceRows=array_map(static fn($r)=>array_diff_key($r,array_flip(['millName','accountsStatus','postId','sourceAmended','postingVerified'])),$sourceRows);
        $costFingerprint=hash('sha256',json_encode([$g['productionFingerprint'],$entity,$average,$rawCost,$numericSourceRows,$byProducts,$readyCost],JSON_THROW_ON_ERROR));if($approved&&!empty($approved['calculation']['costFingerprint'])&&!hash_equals($approved['calculation']['costFingerprint'],$costFingerprint)){$sourceChanged=true;$uncertain[$key]=true;}
        if ($approved) $status=$sourceChanged?'Approved cost — production changed or source costs changed; review required':(($approved['calculation']['quantityBasis']??'')==='ACTUAL_INPUT'?'Approved':'Approved historical cost — actual input unverified');
        elseif (!empty($g['requiresInputStockCost'])) $status='Waiting — reprocessing input-stock cost needs review';
        elseif (empty($g['inputComplete'])) $status='Waiting — actual raw input is missing';
        elseif ($poolWasUncertain) $status='Waiting — earlier production input is missing or changed';
        elseif ($unknownCostLocation) $status='Waiting — Mill cost location is unverified';
        elseif (!$allocationComplete) $status='Waiting — actual input is missing in the cost-allocation period';
        elseif (!$enough) $status = 'Waiting — purchase stock is short';
        elseif ($average <= 0) $status = 'Waiting — purchase cost is missing';
        elseif ($provisionalConsumed > 0.001) $status = 'Waiting — final purchase bill is pending';
        elseif ($g['readyKg'] <= 0) $status = 'Waiting — Ready Rice output is missing';
        elseif ($missing) $status = 'Waiting — by-product value is missing';
        elseif (!$sourceRows) $status = 'Waiting — production costs are missing';
        elseif ($readyCost <= 0) $status = 'Waiting — by-product value is too high';
        else $status = 'Ready for Accounts Review';

        $result[] = [
            'id' => $g['id'],'runIds'=>$g['runIds'],'productionFingerprint'=>$g['productionFingerprint'],'inputComplete'=>$g['inputComplete'],'requiresInputStockCost'=>$g['requiresInputStockCost'],'approvedSnapshot'=>$approved,
            'date' => $g['date'],
            'entity' => $entity,
            'variety' => $g['variety'],
            'varietyKey' => $g['varietyKey'],
            'inputKg' => $g['inputKg'],
            'readyKg' => $g['readyKg'],
            'outputs' => $g['outputs'],
            'rawAvailableBeforeKg' => round($available, 3),
            'rawAverageCostPerKg' => round($average, 6),
            'rawCost' => $rawCost,
            'usesProvisionalPurchaseCost' => $provisionalConsumed > 0.001,
            'provisionalInputKg' => round($provisionalConsumed, 3),
            'conversionCost' => $conversion,
            'conversionCostPerKg' => $g['inputKg'] > 0 ? round($conversion / $g['inputKg'], 6) : 0,
            'conversionSources' => $sourceRows,
            'jointCost' => $jointCost,
            'byProducts' => $byProducts,
            'missingByProductValues' => $missing,
            'byProductValue' => round($byProductValue, 2),
            'readyRiceCost' => $readyCost,
            'suggestedReadyRiceCostPerKg' => !empty($g['inputComplete'])&&empty($g['requiresInputStockCost'])&&!$poolWasUncertain&&$allocationComplete?round($readyRate,6):null,
            'enoughRawStock' => $enough,
            'status' => $status,
            'readyToApprove' => $status === 'Ready for Accounts Review',
            'approvedRateId' => $approved['rateId'] ?? null,
            'approvedAt' => $approved['approvedAt'] ?? null,
        ];
        $last=count($result)-1;$result[$last]['reviewFingerprint']=hash('sha256',json_encode([$g['productionFingerprint'],$entity,$average,$rawCost,$numericSourceRows,$byProducts,$readyCost],JSON_THROW_ON_ERROR));
        if($approved){$row=&$result[count($result)-1];foreach(['inputKg','readyKg','rawAverageCostPerKg','rawCost','conversionCost','conversionCostPerKg','byProducts','conversionSources','byProductValue','readyRiceCost'] as $field)if(array_key_exists($field,$approved['calculation']??[]))$row[$field]=$approved['calculation'][$field];$row['suggestedReadyRiceCostPerKg']=$approved['costPerKg']??null;unset($row);}

    }

    return ['groups' => $result, 'sourceCosts' => $sources];
}
