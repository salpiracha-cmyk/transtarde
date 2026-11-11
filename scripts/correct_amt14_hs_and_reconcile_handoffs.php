<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/exmill_queue_core.php';
require_once dirname(__DIR__).'/api/exmill_correction_core.php';

const AMT14_SOURCE = 'transtrade_export_v3_operational';
const AMT14_CODE = '1006.4000';

function amt14_correct_codes(array &$root): array {
    $result = ['contracts'=>0,'lots'=>0];
    foreach (['contracts'=>'ref','shipments'=>'contractRef'] as $group=>$field) {
        if (!isset($root[$group]) || !is_array($root[$group])) continue;
        foreach ($root[$group] as &$record) {
            if (!is_array($record) || ($record[$field]??'') !== 'TG/AMT/14') continue;
            $before = $record;
            if ($group === 'contracts') {
                $record['hsCode'] = AMT14_CODE;
            } elseif (is_array($record['customs']??null)) {
                $record['customs']['hsCode'] = AMT14_CODE;
                foreach (['customs','bl','commercial'] as $document) {
                    if (!is_string($record[$document]['description']??null)) continue;
                    $record[$document]['description'] = preg_replace(
                        '/(\bHS\s*CODE\s*:?\s*)1006\.?3090\b/i',
                        '${1}'.AMT14_CODE,
                        $record[$document]['description']
                    );
                }
            }
            if ($record !== $before) $result[$group==='contracts'?'contracts':'lots']++;
        }
        unset($record);
    }
    return $result;
}

function amt14_correct_payload(string $payload): array {
    $root = json_decode($payload,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($root)) throw new RuntimeException('Invalid Exports storage.');
    $result = amt14_correct_codes($root);
    return [array_sum($result)>0 ? json_encode($root,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) : $payload,$result];
}

function amt14_update_exports(): array {
    $env = static fn($key) => defined('TT_DB_'.$key) ? (string)constant('TT_DB_'.$key) : (string)getenv('TT_DB_'.$key);
    if ($env('HOST')!=='' && $env('NAME')!=='' && $env('USER')!=='') {
        $db = new PDO('mysql:host='.$env('HOST').';dbname='.$env('NAME').';charset=utf8mb4',$env('USER'),$env('PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        try {
            $select = $db->prepare('SELECT payload, version FROM tt_operation_records WHERE storage_key=? FOR UPDATE');
            $select->execute([AMT14_SOURCE]);
            $record = $select->fetch(PDO::FETCH_ASSOC);
            [$payload,$result] = amt14_correct_payload((string)($record['payload']??'{}'));
            if (array_sum($result)>0) {
                $version = (int)$record['version']+1;
                $by = 'Owner-authorized TG/AMT/14 HS-code correction';
                $db->prepare("UPDATE tt_operation_records SET payload=?,version=?,updated_at=UTC_TIMESTAMP(6),updated_by=?,updated_by_user=NULL,updated_by_module='System' WHERE storage_key=?")
                    ->execute([$payload,$version,$by,AMT14_SOURCE]);
                $db->prepare("INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,UTC_TIMESTAMP(6),?,NULL,'System')")
                    ->execute([AMT14_SOURCE,$version,hash('sha256',$payload),$by]);
            }
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    return amt14_locked_file(TT_DATA_DIR.'/operations.json',static function(array &$store): array {
        [$payload,$result] = amt14_correct_payload((string)($store['values'][AMT14_SOURCE]??'{}'));
        if (array_sum($result)>0) {
            $store['values'][AMT14_SOURCE] = $payload;
            $store['meta'][AMT14_SOURCE]['version'] = (int)($store['meta'][AMT14_SOURCE]['version']??0)+1;
        }
        return $result;
    });
}

function amt14_locked_file(string $path, callable $change): array {
    if (!is_file($path)) return [];
    $handle = fopen($path,'r+b');
    if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Storage unavailable.');
    try {
        $raw = stream_get_contents($handle);
        $store = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($store)) throw new RuntimeException('Invalid store.');
        $before = $store;
        $result = $change($store);
        if ($store !== $before) {
            $store['revision'] = (int)($store['revision']??0)+1;
            $payload = json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle,0) || fwrite($handle,$payload)!==strlen($payload) || !fflush($handle)) {
                rewind($handle);ftruncate($handle,0);fwrite($handle,$raw);fflush($handle);
                throw new RuntimeException('Storage write failed.');
            }
        }
        return $result;
    } finally {flock($handle,LOCK_UN);fclose($handle);}
}

function amt14_reconcile_handoffs(array &$store): array {
    $result = eq_retire($store,'Deployment reconciliation');
    $result['confirmed'] = 0;
    $result['unresolved'] = [];
    foreach ((array)($store['bridgeOutbox']??[]) as $key=>$item) {
        if (!is_array($item) || ($item['status']??'')==='Posted') continue;
        $kind = $item['kind']??'';
        $body = (array)($item['body']??[]);
        $entity = (string)($item['entity']??'');
        $journal = null;
        if ($kind==='exMillLifting' && empty($body['reversed']) && (float)($body['kg']??0)>0) {
            $soda = (string)($body['sourceSodaId']??'');
            $id = (string)($body['liftingId']??'');
            $lifting = $store['purchaseSodaLiftings'][$entity.'|'.$soda.'|'.$id]??null;
            if ($id!=='' && $soda!=='' && is_array($lifting) && empty($lifting['reversed'])
                && round((float)$lifting['kg'],3)===round((float)$body['kg'],3)
                && (int)($lifting['bags']??0)===(int)($body['bags']??0)) {
                $matches = true;
                foreach (['date','container','truck','shipmentId','contractRef','lotRef','seal','instructionKey'] as $field) {
                    if (trim((string)($body[$field]??''))!==trim((string)($lifting[$field]??''))) $matches=false;
                }
                if ($matches) {
                    try {$journal=mps_verified_journal($store,$lifting,$entity,'EXMILL|'.$soda.'|'.$id);}
                    catch (DomainException) {}
                }
            }
        }
        if ($journal) {
            $store['bridgeOutbox'][$key] = array_replace($item,[
                'status'=>'Posted','postedAt'=>gmdate('c'),'postedBy'=>'Deployment reconciliation',
                'result'=>['journal'=>$journal['id'],'verifiedExisting'=>true],'lastError'=>'',
            ]);
            $result['confirmed']++;
            continue;
        }
        $kind = in_array($kind,['exMillLifting','accountEvent','exportCandidate','localSaleCandidate','localPaymentCandidate','loadingProgramme'],true)?$kind:'other';
        $error = (string)($item['lastError']??'');
        $class = match(true) {
            $error==='' => 'no recorded error',
            (bool)preg_match('/already|duplicate|409/i',$error) => 'existing-record conflict',
            (bool)preg_match('/permission|denied|403/i',$error) => 'permission',
            (bool)preg_match('/weight|quantity|kg/i',$error) => 'weight or quantity',
            (bool)preg_match('/not found|missing|required/i',$error) => 'missing source or data',
            default => 'other error',
        };
        $label=$kind.' / '.$class;
        $result['unresolved'][$label]=($result['unresolved'][$label]??0)+1;
    }
    return $result;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    try {
        echo 'AMT14_HS='.json_encode(amt14_update_exports(),JSON_THROW_ON_ERROR).PHP_EOL;
        echo 'ACCOUNTS_HANDOFFS='.json_encode(amt14_locked_file(TT_DATA_DIR.'/accounts.json','amt14_reconcile_handoffs'),JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable) {
        fwrite(STDERR,"The HS-code or handoff reconciliation could not complete.\n");
        exit(1);
    }
}
