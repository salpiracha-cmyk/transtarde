<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once __DIR__.'/exmill_corn_core.php';
require_once __DIR__.'/exmill_correction_core.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function mps_out(array $payload,int $status=200): never {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
/** Compare the physical ISO identity without rewriting its saved display text. */
function mps_container_identity(string $value):string{$id=(string)preg_replace('/[^A-Z0-9]/','',strtoupper(trim($value)));return preg_match('/^[A-Z]{4}[0-9]{7}$/D',$id)?substr($id,0,10):$id;}
function mps_store():array{return ['revision'=>0,'purchaseSodas'=>[],'purchaseSodasV2'=>[],'purchaseSodaLiftings'=>[],'journals'=>[],'events'=>[],'commodityBills'=>[]];}
function mps_date(mixed $value):string{$v=trim((string)$value);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new InvalidArgumentException('A valid lifting date is required.');return $v;}
function mps_num(mixed $value,string $label,bool $positive=true):float{if(!is_numeric($value))throw new InvalidArgumentException($label.' must be numeric.');$n=(float)$value;if(!is_finite($n)||($positive?$n<=0:$n<0))throw new InvalidArgumentException($label.' must be '.($positive?'greater than zero.':'zero or greater.'));return $n;}
function mps_id(array $rows,string $prefix):string{do{$id=$prefix.'-'.gmdate('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));}while(isset($rows[$id]));return $id;}
function mps_sodas(array $s):array{$out=[];foreach(['purchaseSodas','purchaseSodasV2']as $c)foreach((array)($s[$c]??[])as$id=>$r){if(!is_array($r))continue;$key=(string)($r['id']??$id);if(!isset($out[$key]))$out[$key]=$r+['id'=>$key];}return $out;}
function mps_soda(array $s,string $id):?array{$all=mps_sodas($s);if(isset($all[$id]))return $all[$id];foreach($all as$r)if((string)($r['sodaNo']??'')===$id)return $r;return null;}
function mps_billed(array $s,string $sourceKey):string{foreach((array)($s['events']??[])as$e)if(is_array($e)&&(string)($e['sourceKey']??'')===$sourceKey&&!empty($e['billId']))return (string)$e['billId'];return '';}
function mps_can_handoff(array $user,string $entity,array $soda):bool {
    if((tt_user_can_module_action($user,'Accounts','purchases','Create')||tt_user_can_module_action($user,'Accounts','purchases','Edit'))&&(tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')))return true;
    if(!tt_user_can_module_action($user,'Mill','export','Create')&&!tt_user_can_module_action($user,'Mill','export','Edit'))return false;
    $grants=(array)($user['permissions']['Accounts']??[]);
    foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$grants)){
        if(!tt_user_can_access_entity($user,$entity,'Create')&&!tt_user_can_access_entity($user,$entity,'Edit'))return false;
        break;
    }
    $normal=static fn($value)=>strtolower(trim((string)preg_replace('/\s+/',' ',(string)$value)));
    $assigned=$normal($user['location']??'');
    $physical=$normal(($soda['locationName']??'')?:($soda['location']??''));
    return in_array($assigned,['','all locations','all authorized locations'],true)||($physical!==''&&$assigned===$physical);
}

try {
    $user=tt_require_login();
    $canMill=tt_user_can_open_module($user,'Mill');$canAccounts=tt_user_can_open_module($user,'Accounts');$canExports=tt_user_can_open_module($user,'Exports');
    if($_SERVER['REQUEST_METHOD']==='POST'&&!$canMill&&!$canAccounts)mps_out(['ok'=>false,'error'=>'Mill or Accounts permission required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'&&!$canMill&&!$canAccounts&&!$canExports)mps_out(['ok'=>false,'error'=>'Mill, Accounts or Exports permission required.'],403);
    $file=TT_DATA_DIR.'/accounts.json';$store=mps_store();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $body=json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))mps_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
        if((string)($body['action']??'')!=='sync_lifting')mps_out(['ok'=>false,'error'=>'Unknown action.'],422);
        $sourceSodaId=trim((string)($body['sourceSodaId']??''));$liftingId=trim((string)($body['liftingId']??''));if($sourceSodaId===''||$liftingId===''||strlen($liftingId)>180)mps_out(['ok'=>false,'error'=>'Stable Soda and lifting identities are required.'],422);
        $preview=!empty($body['preview']);if($preview&&!is_file($file))mps_out(['ok'=>false,'error'=>'The linked purchase store is unavailable.'],422);if(!$preview)tt_ensure_data_dir();$handle=fopen($file,$preview?'rb':'c+');if($handle===false||!flock($handle,$preview?LOCK_SH:LOCK_EX))throw new RuntimeException('store');
        try{
            rewind($handle);$raw=stream_get_contents($handle);$decoded=$raw?json_decode($raw,true):null;$store=is_array($decoded)?array_replace_recursive(mps_store(),$decoded):mps_store();
            $soda=mps_soda($store,$sourceSodaId);if(!is_array($soda)||(string)($soda['readyRoute']??'')!=='EX_MILL')throw new InvalidArgumentException('The linked Ex-Mill purchase Soda was not found.');
            $corn=tt_exmill_is_corn($soda);if($corn)$sourceSodaId=(string)($soda['id']??$sourceSodaId);$entity=strtoupper((string)($soda['entity']??''));if(!in_array($entity,['TTI','BRM'],true)||!mps_can_handoff($user,$entity,$soda))mps_out(['ok'=>false,'error'=>'You do not have permission to hand this lifting to Accounts.'],403);
            $claimed=strtoupper(trim((string)($body['entity']??'')));if($claimed!==''&&$claimed!==$entity)throw new InvalidArgumentException('The legal entity must come from the approved Soda.');
            $date=mps_date($body['date']??'');if(isset($body['qualityStatus'])&&strtoupper(trim((string)$body['qualityStatus']))!=='OK')throw new InvalidArgumentException('Only quality-approved loaded containers can create an Ex-Mill purchase liability.');$kg=round(mps_num($body['kg']??null,'Container weighbridge weight'),3);$bags=(int)round(mps_num($body['bags']??0,'Bags',false));$container=trim((string)($body['container']??''));if($container==='')throw new InvalidArgumentException('Container number is required before the Ex-Mill liability can be recognized.');
            $key=$entity.'|'.$sourceSodaId.'|'.$liftingId;$sourceKey='EXMILL|'.$sourceSodaId.'|'.$liftingId;$old=$store['purchaseSodaLiftings'][$key]??null;$billId=mps_billed($store,$sourceKey);
            foreach((array)$store['purchaseSodaLiftings']as$otherKey=>$other)if($otherKey!==$key&&is_array($other)&&empty($other['reversed'])&&mps_container_identity((string)($other['container']??''))===mps_container_identity($container))throw new InvalidArgumentException('This container is already linked to another Ex-Mill lifting.');
            $reversed=!empty($body['reversed']);$originalJournal=null;$reason='';$reversal=null;
            // A retry acknowledges the original committed facts. It must not reprice
            // a receipt after a Soda amendment or rewrite its Post ID/history.
            if(is_array($old)){
                $same=(string)($old['date']??'')===$date
                    &&(string)($old['container']??'')===$container
                    &&(string)($old['truck']??'')===trim((string)($body['truck']??''))
                    &&round((float)($old['kg']??0),3)===$kg
                    &&(int)($old['bags']??0)===$bags
                    &&(string)($old['shipmentId']??'')===trim((string)($body['shipmentId']??''))
                    &&(string)($old['contractRef']??'')===trim((string)($body['contractRef']??''))
                    &&(string)($old['lotRef']??'')===trim((string)($body['lotRef']??''))
                    &&!empty($old['reversed'])===$reversed;
                if($same&&!$corn){foreach(['seal','instructionKey'] as $field)if(array_key_exists($field,$old)&&(string)$old[$field]!==trim((string)($body[$field]??'')))$same=false;}
                if($same&&$corn){foreach(['weightBasis','weighbridgeKg','silicaKg','kraftKg','bagTareKg','containerTareKg','seal','instructionKey'] as $field)if(($old[$field]??null)!==($body[$field]??null))$same=false;}
                if($same){
                    try{$priorJournal=mps_verified_journal($store,$old,$entity,$sourceKey);}catch(DomainException $e){mps_out(['ok'=>false,'error'=>$e->getMessage()],409);}
                    $priorEvent=$store['events'][$entity.'|COMMODITY_RECEIPT_ACCEPTED|'.$sourceKey];
                    mps_out(['ok'=>true,'replayed'=>true,'lifting'=>$old,'journal'=>$priorJournal,'event'=>$priorEvent,'revision'=>(int)$store['revision']]);
                }
            }
            if($billId!=='')mps_out(['ok'=>false,'error'=>'This lifting is already included in supplier bill '.$billId.'. Reconcile the bill before changing any receipt facts.'],409);
            if($reversed)mps_out(['ok'=>false,'error'=>'Lifting cancellation requires Accounts source reconciliation. The original posting was preserved.'],409);
            if(is_array($old)){
                if(!mps_edit_allowed($user,$entity,$soda))mps_out(['ok'=>false,'error'=>'Edit permission for this loading workflow and company/location is required.'],403);
                try{$originalJournal=mps_verified_journal($store,$old,$entity,$sourceKey);$reason=mps_correction_reason($body,$old,$originalJournal);}catch(DomainException $e){mps_out(['ok'=>false,'error'=>$e->getMessage()],409);}
            }
            $cornSource=null;if($corn){$values=tt_inv_read_values();$root=json_decode((string)($values['transtrade_export_v3_operational']??'{}'),true,512,JSON_THROW_ON_ERROR);$cornSource=tt_exmill_corn_authorization($soda,$body,$root);$count=0;$instructionKg=0.;foreach((array)$store['purchaseSodaLiftings'] as $priorKey=>$prior)if($priorKey!==$key&&is_array($prior)&&empty($prior['reversed'])&&($prior['instructionKey']??'')===$cornSource['instructionKey']){$count++;$instructionKg+=(float)($prior['kg']??0);}if($count>=$cornSource['totalContainers']||$instructionKg+$kg>$cornSource['quantityKg']*1.05+.001)throw new InvalidArgumentException('Corn loading exceeds the issued instruction container count or quantity.');}
            $received=0.0;foreach((array)$store['purchaseSodaLiftings']as$otherKey=>$other)if($otherKey!==$key&&is_array($other)&&empty($other['reversed'])&&(string)($other['sourceSodaId']??'')===$sourceSodaId)$received+=(float)($other['kg']??0);
            $maximum=(float)($soda['qtyToKg']??0);if($maximum>0&&$received+$kg>$maximum*1.05+.001)throw new InvalidArgumentException('The lifting exceeds the contracted Ex-Mill quantity by more than 5%. Amend the Soda before posting additional weight.');
            $rate=mps_num($originalJournal?($originalJournal['meta']['ratePerKg']??0):($soda['ratePerKg']??$soda['rate']??0),'Accepted Soda rate');$amount=round($kg*$rate,2);if($originalJournal)$reversal=mps_reverse_journal($store,$originalJournal,$user,$reason);$jid=tt_next_post_id((array)$store['journals'],'Accounts','Journal');
            $record=['key'=>$key,'entity'=>$entity,'sourceSodaId'=>(string)($soda['id']??$sourceSodaId),'soda'=>(string)($soda['sodaNo']??''),'liftingId'=>$liftingId,'sourceKey'=>$sourceKey,'date'=>$date,'container'=>$container,'truck'=>trim((string)($body['truck']??'')),'seal'=>trim((string)($body['seal']??'')),'instructionKey'=>trim((string)($body['instructionKey']??'')),'kg'=>$kg,'bags'=>$bags,'shipmentId'=>trim((string)($body['shipmentId']??'')),'contractRef'=>trim((string)($body['contractRef']??'')),'lotRef'=>trim((string)($body['lotRef']??'')),'journalId'=>$jid,'provisionalAmount'=>$reversed?0:$amount,'reversed'=>$reversed,'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Mill')];
            if($cornSource)$record=array_merge($record,array_intersect_key($body,array_flip(['weightBasis','weighbridgeKg','silicaKg','kraftKg','bagTareKg','containerTareKg','seal','instructionKey'])),$cornSource,['commodity'=>'CORN']);
            if(is_array($old)){$history=is_array($old['versions']??null)?$old['versions']:[];$snapshot=$old;unset($snapshot['versions']);$history[]=['at'=>gmdate('c'),'before'=>$snapshot,'by'=>$record['updatedBy']];$record['versions']=$history;$record['correctionReason']=$reason;$record['createdAt']=$old['createdAt']??gmdate('c');$record['createdBy']=$old['createdBy']??$record['updatedBy'];}else{$record['createdAt']=gmdate('c');$record['createdBy']=$record['updatedBy'];}
            $meta=['sourceKey'=>$sourceKey,'sourceSodaId'=>$record['sourceSodaId'],'soda'=>$record['soda'],'pohanch'=>$container,'container'=>$container,'truck'=>$record['truck'],'payableWeightKg'=>$kg,'weighbridgeWeightKg'=>$kg,'bags'=>$bags,'ratePerKg'=>$rate,'grossRatePerKg'=>$rate,'katPaisaPerKg'=>0,'provisionalNetRatePerKg'=>$rate,'supplierId'=>(string)($soda['supplierId']??''),'supplier'=>(string)($soda['party']??''),'party'=>(string)($soda['party']??''),'broker'=>(string)($soda['broker']??''),'locationId'=>(string)($soda['locationId']??$soda['exMillId']??''),'locationName'=>(string)($soda['locationName']??$soda['location']??''),'shipmentId'=>$record['shipmentId'],'contractRef'=>$record['contractRef'],'lotRef'=>$record['lotRef'],'productIdentityCode'=>(string)($soda['productIdentityCode']??''),'commodity'=>(string)($soda['commodity']??'RICE'),'baseVariety'=>(string)($soda['baseVariety']??$soda['variety']??''),'variety'=>(string)($soda['displayName']??$soda['variety']??''),'riceType'=>(string)($soda['riceType']??''),'brokenGrade'=>(string)($soda['brokenGrade']??''),'displayName'=>(string)($soda['displayName']??''),'productStage'=>(string)($soda['productStage']??'READY'),'readyRoute'=>'EX_MILL'];
            if($cornSource)$meta=array_merge($meta,$cornSource,['weighbridgeWeightKg'=>(float)$body['weighbridgeKg'],'weightBasis'=>$body['weightBasis']]);
            if($originalJournal){
                // A Soda amendment is not permission to change an accepted supplier,
                // product, location or unit rate during a physical receipt correction.
                $mutable=['truck','payableWeightKg','weighbridgeWeightKg','bags','weightBasis','weight','netKg','cargoGrossKg','packingKg'];
                $facts=array_intersect_key($meta,array_flip($mutable));$meta=array_merge((array)$originalJournal['meta'],$facts,['correctionReason'=>$reason,'amendmentOfPostId'=>$originalJournal['id'],'reversalPostId'=>$reversal['id'],'publicPostId'=>$originalJournal['meta']['publicPostId']??$originalJournal['id']]);
            }
            $journal=['id'=>$jid,'entity'=>$entity,'date'=>$date,'sourceType'=>'EX_MILL_PURCHASE_LIABILITY','reference'=>$container,'narration'=>($reversed?'Reversed — ':'').'Ex-Mill purchase liability — Soda '.$record['soda'],'lines'=>$reversed?[]:[['account'=>'1310','accountName'=>'Inventory / Purchases','debit'=>$amount,'credit'=>0],['account'=>'2210','accountName'=>'Goods Received Not Invoiced','debit'=>0,'credit'=>$amount]],'totalDebit'=>$reversed?0:$amount,'totalCredit'=>$reversed?0:$amount,'status'=>'Posted','billStatus'=>'Pending','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>$record['updatedBy'],'userId'=>(int)($user['id']??0),'updatedAt'=>gmdate('c'),'updatedBy'=>$record['updatedBy'],'reversalOf'=>null];
            $eventId=$entity.'|COMMODITY_RECEIPT_ACCEPTED|'.$sourceKey;$event=['id'=>$eventId,'eventType'=>'COMMODITY_RECEIPT_ACCEPTED','entity'=>$entity,'sourceKey'=>$sourceKey,'journalId'=>$jid,'billId'=>$billId,'status'=>$reversed?'Reversed':'Accepted / Liability Posted','occurredAt'=>$date,'updatedAt'=>gmdate('c')];
            $previews=array_values(array_filter([$reversal,$journal]));$reviewFingerprint=mps_review_fingerprint($previews);
            if($preview){foreach($previews as &$entry)$entry['id']='PREVIEW';unset($entry);mps_out(['ok'=>true,'previews'=>$previews,'reviewFingerprint'=>$reviewFingerprint]);}
            if(array_key_exists('reviewFingerprint',$body)&&!hash_equals($reviewFingerprint,(string)$body['reviewFingerprint']))mps_out(['ok'=>false,'error'=>'The accounting amounts changed after review. Review this lifting again before posting.'],409);
            if($originalJournal){$store['exMillPostReplacements'][$originalJournal['id']]=$jid;$store['exMillLiftingCorrections'][]=['sourceKey'=>$sourceKey,'originalPostId'=>$originalJournal['id'],'reversalPostId'=>$reversal['id'],'replacementPostId'=>$jid,'reason'=>$reason,'at'=>gmdate('c'),'by'=>$record['updatedBy'],'userId'=>(int)($user['id']??0),'eventBefore'=>$store['events'][$entity.'|COMMODITY_RECEIPT_ACCEPTED|'.$sourceKey]??null];}
            $store['purchaseSodaLiftings'][$key]=$record;$store['journals'][$jid]=$journal;$store['events'][$eventId]=$event;$store['revision']=(int)($store['revision']??0)+1;rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($handle);
        }finally{flock($handle,LOCK_UN);fclose($handle);}mps_out(['ok'=>true,'lifting'=>$record,'journal'=>$journal,'event'=>$event,'revision'=>(int)$store['revision']]);
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')mps_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(is_file($file)){$handle=fopen($file,'r');if($handle===false||!flock($handle,LOCK_SH))throw new RuntimeException('store');try{$raw=stream_get_contents($handle);}finally{flock($handle,LOCK_UN);fclose($handle);}$decoded=$raw?json_decode($raw,true):null;if(is_array($decoded))$store=array_replace_recursive($store,$decoded);}
    $seen=[];$rows=[];foreach(mps_sodas($store)as$id=>$row){$status=(string)($row['status']??$row['manualStatus']??'Open');if(in_array($status,['Cancelled','Completed','Short Closed','Manual Closed'],true))continue;$number=(string)($row['sodaNo']??'');$key=strtoupper((string)($row['entity']??'TTI')).'|'.$number;if($number===''||isset($seen[$key]))continue;$stage=tt_product_stage((string)($row['productStage']??''));if($stage===''){$identity=tt_product_identity((string)($row['commodity']??'RICE'),(string)($row['displayName']??$row['variety']??''));$stage=$identity['productStage'];$row=array_replace($identity,$row);}$route=strtoupper((string)($row['readyRoute']??''));if($stage==='READY'&&!in_array($route,['EX_MILL','DELIVER_TO_STOCK'],true))$route=(string)($row['movementRole']??'')==='LIFT_FROM'?'EX_MILL':'DELIVER_TO_STOCK';if($stage==='RAW'&&strtoupper((string)($row['commodity']??''))!=='CORN')$route='DELIVER_TO_STOCK';$seen[$key]=true;$rows[]=['id'=>(string)($row['id']??$id),'entity'=>(string)($row['entity']??'TTI'),'sodaNo'=>$number,'sodaDate'=>(string)($row['sodaDate']??''),'purchaseProductId'=>(string)($row['purchaseProductId']??''),'commodity'=>(string)($row['commodity']??'RICE'),'baseVariety'=>(string)($row['baseVariety']??$row['variety']??''),'riceType'=>(string)($row['riceType']??''),'brokenGrade'=>(string)($row['brokenGrade']??''),'productStage'=>$stage,'displayName'=>(string)($row['displayName']??tt_product_display((string)($row['commodity']??'RICE'),(string)($row['baseVariety']??$row['variety']??''),$stage,(string)($row['riceType']??''))),'katProfile'=>$route==='EX_MILL'&&strtoupper((string)($row['commodity']??''))==='RICE'?'':(string)($row['katProfile']??''),'broker'=>(string)($row['broker']??''),'supplierId'=>(string)($row['supplierId']??''),'party'=>(string)($row['party']??''),'readyRoute'=>$route,'locationId'=>(string)($row['locationId']??$row['exMillId']??''),'locationName'=>(string)($row['locationName']??$row['location']??''),'locationType'=>(string)($row['locationType']??''),'locationAddress'=>(string)($row['locationAddress']??''),'qtyFromKg'=>(float)($row['qtyFromKg']??0),'qtyToKg'=>(float)($row['qtyToKg']??0),'expectedTrucks'=>(int)($row['expectedTrucks']??0),'ratePerKg'=>(float)($row['ratePerKg']??$row['rate']??0),'arrivalDueDate'=>(string)($row['arrivalDueDate']??''),'remarks'=>(string)($row['remarks']??''),'updatedAt'=>(string)($row['amendedAt']??$row['updatedAt']??$row['createdAt']??'')];}
    mps_out(['ok'=>true,'revision'=>(int)($store['revision']??0),'sodas'=>$rows]);
} catch(InvalidArgumentException|DomainException $error){mps_out(['ok'=>false,'error'=>$error->getMessage()],422);} catch(Throwable $error){error_log('milling_purchase_sodas: '.$error->getMessage());mps_out(['ok'=>false,'error'=>'Purchase Sodas are temporarily unavailable.'],500);}
