<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const PS_FILE = TT_DATA_DIR . '/accounts.json';
const PS_TRUCK_DOUBLE_KG = 35000.0;
const PS_MAX_OVER_KG = 950.0;

function ps_out(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ps_default(): array {
    return ['revision'=>0, 'events'=>[], 'journals'=>[], 'commodityBills'=>[], 'supplierSettlements'=>[], 'purchaseSodas'=>[], 'purchaseSodaLiftings'=>[], 'postingKeys'=>[]];
}

function ps_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $permissions = $user['permissions']['Accounts'] ?? null;
    if ($permissions === 'all') return true;
    if (!is_array($permissions)) return false;
    foreach ($permissions as $permission) {
        if (is_string($permission) && in_array($permission, ['Create','Edit','Approve'], true)) return true;
        if (is_array($permission) && array_intersect($permission, ['Create','Edit','Approve'])) return true;
    }
    return false;
}

function ps_stock_types(): array { return ['Own Mill','Reprocessing Mill','External Mill','Warehouse','Stock Location']; }
function ps_receiving_types(): array { return ['Own Mill','Reprocessing Mill','Warehouse','Stock Location']; }

function ps_locations(): array {
    $out=[];
    foreach(tt_active_location_masters() as$row){$v=array_values((array)($row['values']??[]));while(count($v)<7)$v[]='';$type=tt_normalize_location_type((string)$v[2]);if(!in_array($type,ps_stock_types(),true))continue;$out[]=['id'=>(string)($row['id']??''),'name'=>(string)$v[0],'code'=>(string)$v[1],'type'=>$type,'address'=>(string)$v[3],'status'=>(string)$v[5]];}
    usort($out,static fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));return$out;
}

function ps_suppliers(): array {
    $out=[];
    foreach((array)(tt_list_masters()['business_parties']??[])as$row){if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<13)$v[]='';if(!tt_business_party_has_category($v[2],'Supplier')||strcasecmp((string)$v[10],'Inactive')===0)continue;$profile=json_decode((string)$v[12],true);if(!is_array($profile))$profile=[];$candidate=['id'=>(string)($row['id']??''),'name'=>(string)$v[0],'code'=>(string)$v[1],'linkedExternalMillId'=>(string)($profile['linkedExternalMillId']??'')];$key=tt_master_name_identity($candidate['name'],'business_parties');if(!isset($out[$key])||strlen($candidate['name'])<strlen((string)$out[$key]['name']))$out[$key]=$candidate;}
    $out=array_values($out);
    usort($out,static fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));return$out;
}

function ps_metadata(array $user): array {
    $products=array_values(array_filter(tt_purchase_product_profiles(),static fn($p)=>in_array((string)($p['productStage']??''),['RAW','READY'],true)));
    $defaultProduct='';foreach($products as$p)if(($p['id']??'')==='purchase-products-rice-irri6-white-raw'||((string)($p['commodity']??'')==='RICE'&&strcasecmp((string)($p['baseVariety']??''),'IRRI-6')===0&&strcasecmp((string)($p['riceType']??''),'White')===0&&(string)($p['productStage']??'')==='RAW')){$defaultProduct=(string)$p['id'];break;}
    $locations=ps_locations();$defaultLocation='';foreach($locations as$l)if(($l['type']??'')==='Own Mill'&&tt_location_identity((string)$l['name'])===tt_location_identity('TTI Rice Mills')){$defaultLocation=(string)$l['id'];break;}
    return ['purchaseProducts'=>$products,'locations'=>$locations,'suppliers'=>ps_suppliers(),'brokers'=>tt_broker_profiles(null,'buying'),'defaults'=>['rawPurchaseProductId'=>$defaultProduct,'rawLocationId'=>$defaultLocation],'permissions'=>[
        'canAddLocation'=>tt_user_can_master($user,'mills','Create'),'canRemoveLocation'=>tt_user_can_master($user,'mills','Deactivate'),
        'canAddProduct'=>tt_user_can_master($user,'purchase_products','Create'),'canRemoveProduct'=>tt_user_can_master($user,'purchase_products','Deactivate'),
        'canAddParty'=>tt_user_can_master($user,'business_parties','Create'),'canRemoveParty'=>tt_user_can_master($user,'business_parties','Deactivate'),
        'canLinkSupplierMill'=>tt_user_can_master($user,'business_parties','Edit')]];
}

function ps_find_location(string $id,array $locations): ?array {foreach($locations as$l)if((string)($l['id']??'')===$id)return$l;return null;}
function ps_find_supplier(string $id,array $suppliers): ?array {foreach($suppliers as$s)if((string)($s['id']??'')===$id)return$s;return null;}

function ps_link_supplier_mill(string $supplierId,string $millId): void {
    tt_mutate_store(function (&$data) use($supplierId,$millId): void {$rows=&$data['masters']['business_parties'];if(!is_array($rows))$rows=[];foreach($rows as&$row){if((string)($row['id']??'')!==$supplierId)continue;$v=array_values((array)($row['values']??[]));while(count($v)<13)$v[]='';$profile=json_decode((string)$v[12],true);if(!is_array($profile))$profile=[];$profile['buying']=array_values(is_array($profile['buying']??null)?$profile['buying']:[]);$profile['selling']=array_values(is_array($profile['selling']??null)?$profile['selling']:[]);$profile['linkedExternalMillId']=$millId;$v[12]=json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$row['values']=$v;unset($row);return;}unset($row);throw new InvalidArgumentException('Supplier was not found.');});
}

function ps_upsert_party_category(string $name,string $category): array {
    $name=trim(preg_replace('/\s+/u',' ',$name)??'');
    if($name===''||strlen($name)>180)throw new InvalidArgumentException('Enter a valid '.$category.' name.');
    return tt_mutate_store(function(&$data)use($name,$category):array{
        if(!isset($data['masters']['business_parties'])||!is_array($data['masters']['business_parties']))$data['masters']['business_parties']=[];
        foreach($data['masters']['business_parties']as&$row){$v=array_values((array)($row['values']??[]));while(count($v)<13)$v[]='';if(!tt_master_names_conflict($name,(string)$v[0],'business_parties'))continue;$categories=tt_business_party_categories($v[2]);if(!array_filter($categories,static fn($item)=>strcasecmp($item,$category)===0))$categories[]=$category;$v[2]=implode('; ',$categories);$v[10]='Active';$profile=json_decode((string)$v[12],true);if(!is_array($profile))$profile=[];$profile['buying']=array_values(is_array($profile['buying']??null)?$profile['buying']:[]);$profile['selling']=array_values(is_array($profile['selling']??null)?$profile['selling']:[]);$v[12]=json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$row['values']=$v;$out=$row;unset($row);return$out;}unset($row);
        $id='business-parties-auto-'.substr(hash('sha256',strtolower($name)),0,14);$row=['id'=>$id,'values'=>[$name,'',$category,'','','','','','','','Active','Added from Accounts Soda Centre.','{"buying":[],"selling":[]}']];$data['masters']['business_parties'][]=$row;return$row;
    });
}

function ps_remove_party_category(string $id,string $category): array {
    return tt_mutate_store(function(&$data)use($id,$category):array{
        $rows=&$data['masters']['business_parties'];if(!is_array($rows))$rows=[];foreach($rows as&$row){if((string)($row['id']??'')!==$id)continue;$v=array_values((array)($row['values']??[]));while(count($v)<13)$v[]='';$categories=array_values(array_filter(tt_business_party_categories($v[2]),static fn($item)=>strcasecmp($item,$category)!==0));$v[2]=implode('; ',$categories);if(!$categories)$v[10]='Inactive';$row['values']=$v;$out=$row;unset($row);return$out;}unset($row);throw new InvalidArgumentException($category.' was not found.');
    });
}

function ps_user_name(array $user): string {
    return (string)($user['full_name'] ?? $user['username'] ?? 'Accounts');
}

function ps_read(): array {
    tt_ensure_data_dir();
    if (!is_file(PS_FILE)) return ps_default();
    $handle = fopen(PS_FILE, 'r');
    if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('store');
    try { $raw = stream_get_contents($handle); }
    finally { flock($handle, LOCK_UN); fclose($handle); }
    $store = $raw ? json_decode($raw, true) : null;
    return is_array($store) ? array_replace_recursive(ps_default(), $store) : ps_default();
}

function ps_date(string $value, string $label): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$errors=DateTimeImmutable::getLastErrors();
    if (!$date||($errors!==false&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0))||$date->format('Y-m-d')!==$value) ps_out(['ok'=>false, 'error'=>$label . ' is invalid.'], 422);
    return $value;
}

function ps_decimal(mixed $value, string $label, bool $allowZero = false): float {
    if (!is_numeric($value)) ps_out(['ok'=>false, 'error'=>$label . ' is invalid.'], 422);
    $number = round((float)$value, 3);
    if(!is_finite($number)) ps_out(['ok'=>false, 'error'=>$label . ' is invalid.'], 422);
    if ($number < 0 || (!$allowZero && $number <= 0)) ps_out(['ok'=>false, 'error'=>$label . ' is invalid.'], 422);
    return $number;
}

function ps_request_key(mixed $value): string {
    $key=trim((string)$value);
    if($key===''||strlen($key)>160||!preg_match('/^[A-Za-z0-9._:-]+$/',$key))ps_out(['ok'=>false,'error'=>'A stable request key is required. Refresh the form and try again.'],422);
    return $key;
}
function ps_fingerprint(array $body): string {unset($body['csrf'],$body['requestKey']);ksort($body);return hash('sha256',json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}

function ps_next_no(array $sodas): string {
    $year = gmdate('y');
    $maximum = 0;
    foreach ($sodas as $soda) {
        $number = (string)($soda['sodaNo'] ?? '');
        if (preg_match('/^' . preg_quote($year, '/') . '(\d{3})$/', $number, $match)) $maximum = max($maximum, (int)$match[1]);
    }
    return $year . str_pad((string)($maximum + 1), 3, '0', STR_PAD_LEFT);
}

function ps_paid_for_bill(array $store, string $billId): float {
    $total = 0.0;
    foreach ((array)($store['supplierSettlements'] ?? []) as $settlement) {
        if (!is_array($settlement) || !in_array((string)($settlement['status'] ?? ''), ['Posted','Approved / Posted'], true)) continue;
        foreach ((array)($settlement['allocations'] ?? []) as $allocation) {
            if (is_array($allocation) && (string)($allocation['billId'] ?? '') === $billId) $total += (float)($allocation['amount'] ?? 0);
        }
    }
    return round($total, 2);
}

function ps_rows(array $store, string $entity): array {
    $rows = [];
    foreach ((array)($store['purchaseSodas'] ?? []) as $id => $soda) {
        if (!is_array($soda) || strtoupper((string)($soda['entity'] ?? '')) !== $entity) continue;
        $commodity = strtoupper((string)($soda['commodity'] ?? 'RICE'));
        $number = (string)($soda['sodaNo'] ?? '');
        $received = 0.0; $truckUnits = 0; $physicalTrucks = 0; $unbilled = 0; $billed = 0.0; $paid = 0.0;
        $receipts = [];
        if((string)($soda['readyRoute']??'')==='EX_MILL')foreach((array)($store['purchaseSodaLiftings']??[])as$lifting){if(!is_array($lifting)||!empty($lifting['reversed'])||strtoupper((string)($lifting['entity']??''))!==$entity||((string)($lifting['sourceSodaId']??'')!==(string)$id&&(string)($lifting['soda']??'')!==$number))continue;$kg=(float)($lifting['kg']??0);if($kg<=0)continue;$received+=$kg;$physicalTrucks++;$truckUnits++;$receipts[]=['type'=>'Ex-Mill Lifting','date'=>(string)($lifting['date']??''),'truck'=>(string)($lifting['truck']??''),'pohanch'=>(string)($lifting['container']??$lifting['liftingId']??''),'kg'=>$kg,'truckUnits'=>1,'billed'=>false,'billId'=>''];}
        foreach ((array)($store['events'] ?? []) as $event) {
            if (!is_array($event) || ($event['eventType'] ?? '') !== 'COMMODITY_RECEIPT_ACCEPTED' || strtoupper((string)($event['entity'] ?? '')) !== $entity) continue;
            $journal = $store['journals'][$event['journalId'] ?? ''] ?? null;
            if (!is_array($journal)) continue;
            $meta = is_array($journal['meta'] ?? null) ? $journal['meta'] : [];
            if ((string)($meta['soda'] ?? '') !== $number || (string)($soda['readyRoute']??'')==='EX_MILL') continue;
            $eventCommodity = strtoupper((string)($meta['commodity'] ?? $commodity));
            if ($eventCommodity !== $commodity) continue;
            $kg = (float)($meta['payableWeightKg'] ?? 0);
            $units = $kg > PS_TRUCK_DOUBLE_KG ? 2 : 1;
            $received += $kg; $truckUnits += $units; $physicalTrucks++;
            $billId = (string)($event['billId'] ?? '');
            if ($billId === '') $unbilled++;
            $receipts[] = ['date'=>(string)($journal['date'] ?? ''), 'truck'=>(string)($meta['truck'] ?? ''), 'pohanch'=>(string)($meta['pohanch'] ?? $journal['reference'] ?? ''), 'kg'=>$kg, 'truckUnits'=>$units, 'billed'=>$billId !== '', 'billId'=>$billId];
        }
        foreach ((array)($store['commodityBills'] ?? []) as $billId => $bill) {
            if (!is_array($bill) || strtoupper((string)($bill['entity'] ?? '')) !== $entity || !in_array($number, (array)($bill['sodas'] ?? []), true)) continue;
            if (strtoupper((string)($bill['commodity'] ?? $commodity)) !== $commodity) continue;
            $billed += (float)($bill['supplierPayableTotal'] ?? $bill['finalCommodityValue'] ?? 0);
            $paid += ps_paid_for_bill($store, (string)$billId);
        }
        $minimum = (float)($soda['qtyFromKg'] ?? 0); $maximum = (float)($soda['qtyToKg'] ?? 0); $expected = (int)($soda['expectedTrucks'] ?? 0);
        $hasWeight = $minimum > 0; $hasTrucks = $expected > 0;
        $weightMet = !$hasWeight || $received >= $minimum; $truckMet = !$hasTrucks || $truckUnits >= $expected;
        $maxAllowed = $maximum > 0 ? $maximum + PS_MAX_OVER_KG : 0; $overMaximum = $maxAllowed > 0 && $received > $maxAllowed + .001;
        if((string)($soda['readyRoute']??'')==='EX_MILL')$unbilled=$received>0&&$billed<=0?1:0;
        $status = (string)($soda['status'] ?? 'Open');
        if (!in_array($status, ['Completed','Short Closed','Cancelled'], true)) {
            if ($received <= 0) $status = 'Open';
            elseif ($overMaximum) $status = 'Over Maximum — Review';
            elseif (!$weightMet || !$truckMet) $status = 'Part Received';
            elseif ($unbilled > 0) $status = 'Received / Bill Pending';
            else $status = 'Ready to Complete';
        }
        $rows[] = $soda + ['id'=>(string)$id, 'basis'=>$hasWeight && $hasTrucks ? 'BOTH' : ($hasWeight ? 'WEIGHT' : 'TRUCKS'), 'receivedKg'=>round($received,3), 'liftedKg'=>(string)($soda['readyRoute']??'')==='EX_MILL'?round($received,3):0, 'trucksReceived'=>$physicalTrucks, 'truckUnitsReceived'=>$truckUnits, 'unbilledArrivals'=>$unbilled, 'billedAmount'=>round($billed,2), 'paidAmount'=>round($paid,2), 'payableOutstanding'=>round(max(0,$billed-$paid),2), 'calculatedStatus'=>$status, 'maxAllowedKg'=>round($maxAllowed,3), 'overMaximum'=>$overMaximum, 'receipts'=>$receipts];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['sodaDate'] ?? ''), (string)($a['sodaDate'] ?? '')) ?: strcmp((string)($b['sodaNo'] ?? ''), (string)($a['sodaNo'] ?? '')));
    return $rows;
}

function ps_validate(array $body, ?array $existing = null): array {
    $profiles=tt_purchase_product_profiles();$purchaseProductId=trim((string)($body['purchaseProductId']??$existing['purchaseProductId']??''));$product=tt_find_purchase_product($purchaseProductId,$profiles);
    if(!$product||!in_array((string)($product['productStage']??''),['RAW','READY'],true))ps_out(['ok'=>false,'error'=>'Select an active Raw or Ready purchase product from Purchase Commodities & KAT.'],422);
    $commodity=(string)$product['commodity'];$stage=(string)$product['productStage'];
    $date = ps_date((string)($body['sodaDate'] ?? ''), 'Soda date');
    $broker = trim((string)($body['broker'] ?? ''));$supplierId=trim((string)($body['supplierId']??''));$supplier=ps_find_supplier($supplierId,ps_suppliers());if($supplierId!==''&&!$supplier)ps_out(['ok'=>false,'error'=>'Select an active Supplier profile from Business Parties.'],422);$party=$supplier?(string)$supplier['name']:'';
    if ($broker === '') ps_out(['ok'=>false, 'error'=>'Broker is required.'], 422);
    $brokerProfile = tt_broker_profile($broker, $date, 'buying');
    if (!$brokerProfile) ps_out(['ok'=>false, 'error'=>'Select an active Broker profile from Business Parties.'], 422);
    $broker = (string)$brokerProfile['name'];
    $fromRaw = trim((string)($body['qtyFromMT'] ?? '')); $toRaw = trim((string)($body['qtyToMT'] ?? '')); $trucksRaw = trim((string)($body['expectedTrucks'] ?? ''));
    if ($fromRaw === '' && $toRaw === '' && $trucksRaw === '') ps_out(['ok'=>false, 'error'=>'Enter minimum/maximum quantity, expected trucks, or both.'], 422);
    if ($toRaw !== '' && $fromRaw === '') ps_out(['ok'=>false, 'error'=>'Enter minimum quantity when using a maximum quantity.'], 422);
    $minimum = $fromRaw !== '' ? ps_decimal($fromRaw, 'Minimum quantity') * 1000 : 0.0;
    $maximum = $toRaw !== '' ? ps_decimal($toRaw, 'Maximum quantity') * 1000 : $minimum;
    if ($maximum > 0 && $maximum < $minimum) ps_out(['ok'=>false, 'error'=>'Maximum quantity cannot be below minimum quantity.'], 422);
    $trucks = $trucksRaw !== '' ? (int)$trucksRaw : 0;
    if ($trucksRaw !== '' && ($trucks < 1 || $trucks > 999)) ps_out(['ok'=>false, 'error'=>'Expected trucks must be between 1 and 999.'], 422);
    $rate = ps_decimal($body['rate'] ?? $body['ratePerKg'] ?? 0, 'Rate');
    $unit = strtoupper((string)($body['rateUnit'] ?? 'KG'));
    if (!in_array($unit, ['KG','MAUND'], true)) ps_out(['ok'=>false, 'error'=>'Invalid rate unit.'], 422);
    $payment = strtoupper((string)($body['paymentTermType'] ?? 'CASH'));
    if (!in_array($payment, ['CASH','CREDIT'], true)) ps_out(['ok'=>false, 'error'=>'Invalid payment terms.'], 422);
    $creditDays = $payment === 'CREDIT' ? (int)($body['creditDays'] ?? 0) : 0;
    if ($payment==='CREDIT'&&($creditDays < 1 || $creditDays > 365)) ps_out(['ok'=>false, 'error'=>'Credit Days are required for Credit and must be between 1 and 365.'], 422);
    $due = ps_date((string)($body['arrivalDueDate'] ?? $body['deliveryDeadline'] ?? ''), 'Arrival due date');
    if($due<$date)ps_out(['ok'=>false,'error'=>'Expected Arrival / Delivery Date cannot be before the Soda date.'],422);
    $route=$stage==='READY'?strtoupper(trim((string)($body['readyRoute']??''))):'DELIVER_TO_STOCK';if($stage==='READY'&&!in_array($route,['EX_MILL','DELIVER_TO_STOCK'],true))ps_out(['ok'=>false,'error'=>'Choose whether Ready Rice remains at the Ex-Mill or is delivered to our mill / stock location.'],422);
    $locationId=trim((string)($body['locationId']??''));$location=ps_find_location($locationId,ps_locations());if(!$location)ps_out(['ok'=>false,'error'=>'Select an active stock-holding mill / location. Office locations are not allowed.'],422);
    if($route==='EX_MILL'&&($location['type']??'')!=='External Mill')ps_out(['ok'=>false,'error'=>'Select an External Mill for the Ex-Mill route.'],422);
    if($route==='DELIVER_TO_STOCK'&&!in_array((string)($location['type']??''),ps_receiving_types(),true))ps_out(['ok'=>false,'error'=>'Select an own mill, reprocessing mill, warehouse or stock location for delivery.'],422);
    return ['commodity'=>$commodity,'purchaseProductId'=>$purchaseProductId,'baseVariety'=>(string)$product['baseVariety'],'riceType'=>(string)$product['riceType'],'productStage'=>$stage,'displayName'=>(string)$product['displayName'],'katProfile'=>(string)$product['katProfile'],'sodaDate'=>$date,'broker'=>$broker,'supplierId'=>$supplierId,'party'=>$party,'variety'=>(string)$product['baseVariety'],'readyRoute'=>$route,'movementRole'=>$route==='EX_MILL'?'LIFT_FROM':'DELIVER_TO','locationId'=>(string)$location['id'],'location'=>(string)$location['name'],'locationName'=>(string)$location['name'],'locationType'=>(string)$location['type'],'locationAddress'=>(string)$location['address'],'exMillId'=>$route==='EX_MILL'?(string)$location['id']:'','qtyFromKg'=>$minimum,'qtyToKg'=>$maximum,'expectedTrucks'=>$trucks,'completionBasis'=>$minimum > 0 && $trucks > 0 ? 'BOTH' : ($minimum > 0 ? 'WEIGHT' : 'TRUCKS'),'rate'=>$rate,'ratePerKg'=>$unit === 'MAUND' ? round($rate/40, 6) : $rate,'rateUnit'=>$unit,'paymentTermType'=>$payment,'creditDays'=>$creditDays,'arrivalDueDate'=>$due,'deliveryDeadline'=>$due,'terms'=>trim((string)($body['terms']??'')),'remarks'=>trim((string)($body['remarks']??'')),'maxOverToleranceKg'=>PS_MAX_OVER_KG,'doubleTruckAboveKg'=>PS_TRUCK_DOUBLE_KG];
}

try {
    $user = tt_require_login();
    if (!tt_user_can_open_module($user, 'Accounts')) ps_out(['ok'=>false, 'error'=>'Accounts permission required.'], 403);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $entity = strtoupper(trim((string)($_GET['entity'] ?? 'TTI')));
        if (!in_array($entity, ['TTI','BRM'], true)) ps_out(['ok'=>false, 'error'=>'Sodas are available only in the selected Pakistan legal books.'], 422);
        if(!tt_user_can_access_entity($user,$entity,'View'))ps_out(['ok'=>false,'error'=>'You do not have permission for this legal entity.'],403);
        $store = ps_read();
        ps_out(['ok'=>true, 'sodas'=>ps_rows($store,$entity), 'nextSodaNo'=>ps_next_no((array)$store['purchaseSodas']), 'rules'=>['doubleTruckAboveKg'=>PS_TRUCK_DOUBLE_KG, 'maxOverKg'=>PS_MAX_OVER_KG]]+ps_metadata($user));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ps_out(['ok'=>false, 'error'=>'Method not allowed.'], 405);
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) ps_out(['ok'=>false, 'error'=>'Session expired. Refresh and try again.'], 419);
    $action = (string)($body['action'] ?? '');
    // The approved Accounts rule allows every Accounts user to amend a Soda.
    // Create and status controls continue to respect the user's write permission.
    if($action==='add_location'){
        if(!tt_user_can_master($user,'mills','Create'))ps_out(['ok'=>false,'error'=>'Mill / Location Master Create permission required.'],403);$name=trim((string)($body['name']??''));$type=tt_normalize_location_type((string)($body['type']??''));if(!in_array($type,ps_stock_types(),true))ps_out(['ok'=>false,'error'=>'Only a stock-holding location can be added here.'],422);$duplicate=tt_find_location_duplicate($name);if(is_array($duplicate))ps_out(['ok'=>false,'error'=>'A matching or similar location already exists: '.(string)(($duplicate['values']??[])[0]??'').'. Select it instead.'],409);$row=tt_upsert_location_master($name,$type,'Accounts Soda Centre','Added from the route selector.');ps_out(['ok'=>true,'locationId'=>(string)($row['id']??'')]+ps_metadata($user));
    }
    if($action==='link_supplier_mill'){
        if(!tt_user_can_master($user,'business_parties','Edit'))ps_out(['ok'=>false,'error'=>'Business Parties Edit permission required.'],403);$supplierId=trim((string)($body['supplierId']??''));$millId=trim((string)($body['millId']??''));$supplier=ps_find_supplier($supplierId,ps_suppliers());$mill=ps_find_location($millId,ps_locations());if(!$supplier||!$mill||($mill['type']??'')!=='External Mill')ps_out(['ok'=>false,'error'=>'Select a valid supplier and External Mill.'],422);ps_link_supplier_mill($supplierId,$millId);ps_out(['ok'=>true]+ps_metadata($user));
    }
    if(in_array($action,['add_party_category','remove_party_category'],true)){
        $category=strcasecmp(trim((string)($body['category']??'')),'Broker')===0?'Broker':(strcasecmp(trim((string)($body['category']??'')),'Supplier')===0?'Supplier':'');
        if($category==='')ps_out(['ok'=>false,'error'=>'Select Broker or Supplier.'],422);
        $needed=$action==='add_party_category'?'Create':'Deactivate';if(!tt_user_can_master($user,'business_parties',$needed))ps_out(['ok'=>false,'error'=>'Business Parties '.$needed.' permission required.'],403);
        $row=$action==='add_party_category'?ps_upsert_party_category((string)($body['name']??''),$category):ps_remove_party_category(trim((string)($body['id']??'')),$category);
        tt_audit((int)($user['id']??0),(string)($user['username']??''),($action==='add_party_category'?'Added ':'Removed ').$category.' option '.(string)(($row['values']??[])[0]??''));
        ps_out(['ok'=>true,'partyId'=>(string)($row['id']??'')]+ps_metadata($user));
    }
    if($action==='delete'&&($user['role']??'')!=='Super Admin')ps_out(['ok'=>false,'error'=>'Only Super Admin can permanently delete an unused Soda.'],403);
    if ($action !== 'amend' && !ps_can_write($user)) ps_out(['ok'=>false, 'error'=>'Accounts Create or Edit permission required.'], 403);
    $entity = strtoupper(trim((string)($body['entity'] ?? 'TTI')));
    if (!in_array($entity, ['TTI','BRM'], true)) ps_out(['ok'=>false, 'error'=>'Invalid legal entity.'], 422);
    $entityAction=$action==='create'?'Create':'Edit';if(!tt_user_can_access_entity($user,$entity,$entityAction))ps_out(['ok'=>false,'error'=>'You do not have permission for this legal entity.'],403);
    tt_ensure_data_dir();
    $handle = fopen(PS_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('store');
    try {
        rewind($handle); $raw = stream_get_contents($handle); $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = ps_default();
        $store = array_replace_recursive(ps_default(), $store); $created = null;
        if ($action === 'create') {
            $requestKey=ps_request_key($body['requestKey']??'');$postingKey=$entity.'|PURCHASE_SODA|'.$requestKey;$fingerprint=ps_fingerprint($body);$prior=$store['postingKeys'][$postingKey]??null;
            if(is_array($prior)){if(!hash_equals((string)($prior['fingerprint']??''),$fingerprint))ps_out(['ok'=>false,'error'=>'This request key was already used for a different Soda.'],409);$created=$store['purchaseSodas'][(string)($prior['sodaId']??'')]??null;ps_out(['ok'=>true,'sodas'=>ps_rows($store,$entity),'nextSodaNo'=>ps_next_no((array)$store['purchaseSodas']),'created'=>$created,'duplicate'=>true]+ps_metadata($user));}
            $values = ps_validate($body); $number = ps_next_no((array)$store['purchaseSodas']); $id = 'PS-' . $values['commodity'] . '-' . $number;
            $created = ['id'=>$id, 'entity'=>$entity, 'sodaNo'=>$number, 'status'=>'Open','requestKey'=>$requestKey, 'createdAt'=>gmdate('c'), 'createdBy'=>ps_user_name($user), 'audit'=>[]] + $values;
            $store['purchaseSodas'][$id] = $created;
            $store['postingKeys'][$postingKey]=['fingerprint'=>$fingerprint,'sodaId'=>$id,'createdAt'=>gmdate('c')];
        } elseif ($action === 'amend') {
            $id = trim((string)($body['id'] ?? '')); $reason = trim((string)($body['reason'] ?? ''));
            if ($reason === '') ps_out(['ok'=>false, 'error'=>'Reason for amendment is required.'], 422);
            $old = $store['purchaseSodas'][$id] ?? null;
            if (!is_array($old) || strtoupper((string)($old['entity'] ?? '')) !== $entity) ps_out(['ok'=>false, 'error'=>'Soda not found in the selected legal books.'], 404);
            $values = ps_validate($body, $old); $changes = [];
            foreach ($values as $key => $newValue) {
                $oldValue = $old[$key] ?? null;
                if ((string)$oldValue !== (string)$newValue) $changes[$key] = ['from'=>$oldValue, 'to'=>$newValue];
            }
            if (!$changes) ps_out(['ok'=>false, 'error'=>'No Soda details were changed.'], 422);
            $audit = is_array($old['audit'] ?? null) ? $old['audit'] : [];
            $audit[] = ['at'=>gmdate('c'), 'by'=>ps_user_name($user), 'reason'=>$reason, 'changes'=>$changes];
            $store['purchaseSodas'][$id] = array_replace($old, $values, ['audit'=>$audit, 'amendedAt'=>gmdate('c'), 'amendedBy'=>ps_user_name($user)]);
        } elseif ($action === 'delete') {
            $id=trim((string)($body['id']??''));$reason=trim((string)($body['reason']??''));$old=$store['purchaseSodas'][$id]??null;
            if($reason==='')ps_out(['ok'=>false,'error'=>'Reason for deletion is required.'],422);
            if(!is_array($old)||strtoupper((string)($old['entity']??''))!==$entity)ps_out(['ok'=>false,'error'=>'Soda not found.'],404);
            $number=(string)($old['sodaNo']??'');$linked=false;
            foreach((array)($store['events']??[])as$event){if(!is_array($event))continue;$journal=$store['journals'][$event['journalId']??'']??null;$meta=is_array($journal['meta']??null)?$journal['meta']:[];if((string)($meta['soda']??'')===$number){$linked=true;break;}}
            if(!$linked)foreach((array)($store['commodityBills']??[])as$bill){if(is_array($bill)&&in_array($number,(array)($bill['sodas']??[]),true)){$linked=true;break;}}
            if(!$linked)foreach((array)($store['purchaseSodaLiftings']??[])as$lifting){if(is_array($lifting)&&((string)($lifting['sourceSodaId']??'')===$id||(string)($lifting['soda']??'')===$number)){$linked=true;break;}}
            if($linked)ps_out(['ok'=>false,'error'=>'This Soda has a linked Pohanch, lifting or bill and cannot be deleted. Amend or cancel it so the audit trail remains intact.'],409);
            unset($store['purchaseSodas'][$id]);foreach((array)($store['postingKeys']??[])as$key=>$posting){if(is_array($posting)&&(string)($posting['sodaId']??'')===$id)unset($store['postingKeys'][$key]);}
            tt_audit((int)($user['id']??0),(string)($user['username']??''),'Permanently deleted unused Soda '.$number.' — '.$reason);
        } elseif ($action === 'set_status') {
            $id = trim((string)($body['id'] ?? '')); $status = (string)($body['status'] ?? ''); $reason = trim((string)($body['reason'] ?? ''));
            if (!in_array($status, ['Open','Completed','Short Closed','Cancelled'], true)) ps_out(['ok'=>false, 'error'=>'Invalid Soda status.'], 422);
            if ($reason === '') ps_out(['ok'=>false, 'error'=>'Reason is required for a Soda status change.'], 422);
            $old = $store['purchaseSodas'][$id] ?? null;
            if (!is_array($old) || strtoupper((string)($old['entity'] ?? '')) !== $entity) ps_out(['ok'=>false, 'error'=>'Soda not found.'], 404);
            $audit = is_array($old['audit'] ?? null) ? $old['audit'] : [];
            $audit[] = ['at'=>gmdate('c'), 'by'=>ps_user_name($user), 'reason'=>$reason, 'changes'=>['status'=>['from'=>$old['status'] ?? '', 'to'=>$status]]];
            $store['purchaseSodas'][$id] = array_replace($old, ['status'=>$status, 'audit'=>$audit, 'statusChangedAt'=>gmdate('c'), 'statusChangedBy'=>ps_user_name($user)]);
        } else ps_out(['ok'=>false, 'error'=>'Unknown action.'], 422);
        $store['revision'] = (int)($store['revision'] ?? 0) + 1;
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); fflush($handle);
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    ps_out(['ok'=>true, 'sodas'=>ps_rows($store,$entity), 'nextSodaNo'=>ps_next_no((array)$store['purchaseSodas']), 'created'=>$created]+ps_metadata($user));
} catch (InvalidArgumentException $error) {
    ps_out(['ok'=>false,'error'=>$error->getMessage()],422);
} catch (Throwable $error) {
    error_log('purchase_sodas: ' . $error->getMessage());
    ps_out(['ok'=>false, 'error'=>'Soda control is temporarily unavailable.'], 500);
}
