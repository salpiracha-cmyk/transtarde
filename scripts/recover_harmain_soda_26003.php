<?php
declare(strict_types=1);

/** Salman-authorized recovery of one original SODA; never restores financial entries. */
function harmain_plan(array $accounts, array $operations, array $original, string $now): array {
    $id = 'PS-RICE-26003';
    if (($original['id'] ?? '') !== $id || ($original['entity'] ?? '') !== 'TTI'
        || ($original['party'] ?? '') !== 'Kas commodities' || ($original['readyRoute'] ?? '') !== 'EX_MILL'
        || (float)($original['ratePerKg'] ?? 0) !== 113.0 || (float)($original['qtyToKg'] ?? 0) !== 270000.0) {
        throw new RuntimeException('The snapshot SODA does not match the owner-selected record.');
    }
    foreach (['purchaseSodas', 'purchaseSodasV2'] as $collection) {
        foreach ((array)($accounts[$collection] ?? []) as $key => $row) {
            if (($row['entity'] ?? '') === 'TTI' && (string)($row['sodaNo'] ?? '') === '26003'
                && ($collection !== 'purchaseSodas' || (string)$key !== $id)) {
                throw new RuntimeException('SODA number 26003 is already in use; recovery refused.');
            }
        }
    }
    $existing = $accounts['purchaseSodas'][$id] ?? null;
    if ($existing !== null && ($existing['ownerRecovery']['id'] ?? '') !== 'HARMAIN-26003-20260930') {
        throw new RuntimeException('An existing SODA must not be overwritten.');
    }
    $values = (array)($operations['values'] ?? []);
    $decode = static function (string $key) use ($values): array {
        $value = $values[$key] ?? '[]';
        $result = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (!is_array($result)) throw new RuntimeException('Operational collection is invalid: '.$key);
        return $result;
    };
    $instructions = $decode('tt40exinstructions');
    $sodas = $decode('tt35exmill');
    $loads = $decode('tt35exload');
    $instructionIndex = null;
    foreach ($instructions as $i => $row) {
        if (($row['shipmentId'] ?? '') !== 'L-mugwlj2o-ks1u9' || ($row['lotRef'] ?? '') !== 'TG/AMT/13/L01') continue;
        if ($instructionIndex !== null || ($row['entity'] ?? '') !== 'TTI'
            || ($row['mill'] ?? '') !== 'AL-Harmain rice mills'
            || !in_array((string)($row['sourceSodaId'] ?? ''), ['', $id], true)) {
            throw new RuntimeException('AMT instruction linkage is ambiguous or has changed.');
        }
        $instructionIndex = $i;
    }
    if ($instructionIndex === null) throw new RuntimeException('The live AMT loading instruction was not found.');
    $rowIndex = null;
    foreach ($sodas as $i => $row) {
        if ((string)($row['_ttPurchaseSodaId'] ?? $row['_ttBridgeId'] ?? '') !== $id) continue;
        if ($rowIndex !== null) throw new RuntimeException('Duplicate Milling SODA projections exist.');
        $rowIndex = $i;
    }
    $localId = $rowIndex === null ? 784441754 : $sodas[$rowIndex]['id'];
    foreach ($sodas as $i => $row) {
        if ($i !== $rowIndex && (string)($row['id'] ?? '') === (string)$localId) {
            throw new RuntimeException('Milling SODA identity collision.');
        }
    }
    foreach ($loads as $row) {
        if ((string)($row['instructionId'] ?? '') === (string)$instructions[$instructionIndex]['id']
            && (string)($row['sodaId'] ?? '') !== (string)$localId) {
            throw new RuntimeException('Current container linkage differs; no container records will be rewritten.');
        }
    }
    $restored = $existing ?? $original;
    if ($existing === null) {
        // The deleted placeholder party is represented by the supported empty broker.
        if (strcasecmp(trim((string)($restored['broker'] ?? '')), 'No broker') === 0) $restored['broker'] = '';
        $restored['ownerRecovery'] = ['id'=>'HARMAIN-26003-20260930', 'at'=>$now,
            'by'=>'Salman', 'snapshot'=>'pre-accounts-test-reset-20260925-175159.zip'];
        $restored['audit'][] = ['at'=>$now, 'by'=>'Salman',
            'reason'=>'Owner-authorized original SODA recovery after Accounts test reset; no lifting or journal restored.'];
        $accounts['purchaseSodas'][$id] = $restored;
        $accounts['revision'] = (int)($accounts['revision'] ?? 0) + 1;
    }
    $projection = $rowIndex === null ? [] : $sodas[$rowIndex];
    $projection = array_replace($projection, ['id'=>$localId, '_ttBridge'=>'accounts-soda',
        '_ttBridgeId'=>$id, '_ttPurchaseSodaId'=>$id, 'entity'=>'TTI', 'soda'=>'26003',
        'mill'=>$restored['locationName'], 'broker'=>$restored['broker'], 'party'=>$restored['party'],
        'product'=>$restored['displayName'], 'purchaseProductId'=>$restored['purchaseProductId'],
        'baseVariety'=>$restored['baseVariety'], 'riceType'=>$restored['riceType'],
        'brokenGrade'=>$restored['brokenGrade'] ?? '', 'productStage'=>'READY',
        'displayName'=>$restored['displayName'], 'qtyKg'=>$restored['qtyToKg'],
        'deliveryTo'=>'Ex-Mill', 'arrivalDueDate'=>$restored['arrivalDueDate'], 'allocationStatus'=>'LINKED']);
    if (($projection['sourceRouteStatus'] ?? '') === 'ROUTE_CHANGED') unset($projection['sourceRouteStatus']);
    if (($projection['status'] ?? '') === 'Route Changed — Review Required') unset($projection['status']);
    if ($rowIndex === null) $sodas[] = $projection; else $sodas[$rowIndex] = $projection;
    $instructions[$instructionIndex]['sourceSodaId'] = $id;
    $instructions[$instructionIndex]['localSodaId'] = $localId;
    $instructions[$instructionIndex]['allocationStatus'] = 'LINKED';
    foreach (['tt35exmill'=>$sodas, 'tt40exinstructions'=>$instructions] as $key => $rows) {
        $encoded = json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (($values[$key] ?? null) === $encoded) continue;
        $operations['values'][$key] = $encoded;
        $operations['revision'] = (int)($operations['revision'] ?? 0) + 1;
        $operations['meta'][$key] = ['version'=>(int)($operations['meta'][$key]['version'] ?? 0)+1,
            'updatedAt'=>$now, 'updatedBy'=>'Salman-authorized SODA recovery', 'userId'=>0, 'module'=>'System'];
    }
    return [$accounts, $operations, ['sodaId'=>$id, 'sodaNo'=>'26003', 'supplier'=>$restored['party'],
        'shipment'=>'TG/AMT/13', 'localSodaId'=>$localId, 'instructionId'=>$instructions[$instructionIndex]['id'],
        'containersPreserved'=>count(array_filter($loads, static fn($r)=>(string)($r['instructionId']??'')===(string)$instructions[$instructionIndex]['id'])),
        'alreadyRecovered'=>$existing!==null]];
}

function harmain_write($handle, string $raw): void {
    rewind($handle);
    if (!ftruncate($handle, 0)) throw new RuntimeException('Recovery write could not start.');
    $offset = 0;
    while ($offset < strlen($raw)) {
        $written = fwrite($handle, substr($raw, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Recovery write failed.');
        $offset += $written;
    }
    if (!fflush($handle)) throw new RuntimeException('Recovery flush failed.');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $target = $argv[1] ?? '';
    $apply = in_array('--apply', $argv, true);
    if (!preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~', $target)) {
        fwrite(STDERR, "Unexpected production target.\n"); exit(2);
    }
    require $target.'/auth_store.php';
    require $target.'/backup_lib.php';
    $reader = new TT_SimpleZipReader(TT_DATA_DIR.'/backups/pre-accounts-test-reset-20260925-175159.zip');
    $sourceRaw = $reader->get('System_Recovery/private/accounts.json');
    $reader->close();
    if (!is_string($sourceRaw) || hash('sha256', $sourceRaw) !== 'b422b7b7969e45b333a260ecd07fbd4467bc1ac999972af509e5548a06809766') {
        fwrite(STDERR, "The server snapshot does not match the owner-supplied backup.\n"); exit(2);
    }
    $original = json_decode($sourceRaw, true, 512, JSON_THROW_ON_ERROR)['purchaseSodas']['PS-RICE-26003'];
    $masters = tt_list_masters();
    foreach (['business_parties'=>$original['supplierId'], 'mills'=>$original['locationId']] as $category=>$requiredId) {
        if (!array_filter((array)($masters[$category]??[]), static fn($r)=>(string)($r['id']??'')===$requiredId)) {
            throw new RuntimeException('Required live master is missing: '.$category);
        }
    }
    $handles = []; $raws = []; $changed = false;
    try {
        foreach (['accounts','operations'] as $name) {
            $path = TT_DATA_DIR.'/'.$name.'.json';
            if (!is_file($path)) throw new RuntimeException('Required live store is missing.');
            $handle = fopen($path, 'r+');
            if ($handle === false || !flock($handle, $apply?LOCK_EX:LOCK_SH)) throw new RuntimeException('Live store is locked.');
            $handles[$name] = $handle; $raws[$name] = stream_get_contents($handle);
        }
        $beforeA = json_decode($raws['accounts'], true, 512, JSON_THROW_ON_ERROR);
        $beforeO = json_decode($raws['operations'], true, 512, JSON_THROW_ON_ERROR);
        [$afterA,$afterO,$report] = harmain_plan($beforeA,$beforeO,$original,gmdate('c'));
        $checkA=$afterA; $baselineA=$beforeA;
        unset($checkA['purchaseSodas']['PS-RICE-26003'],$baselineA['purchaseSodas']['PS-RICE-26003'],$checkA['revision'],$baselineA['revision']);
        if ($checkA!==$baselineA) throw new RuntimeException('Unrelated Accounts data changed.');
        foreach ($beforeO['values'] as $key=>$value) {
            if (!in_array($key,['tt35exmill','tt40exinstructions'],true) && ($afterO['values'][$key]??null)!==$value) {
                throw new RuntimeException('Unrelated operational data changed.');
            }
        }
        $report['financialEntriesUnchanged']=true; $report['containerDetailsUnchanged']=true;
        $report['applied']=$apply;
        if ($apply && ($afterA!==$beforeA || $afterO!==$beforeO)) {
            $safety = TT_DATA_DIR.'/backups/harmain-recovery-safety-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            if (!mkdir($safety,0700)) throw new RuntimeException('Safety copy could not be created.');
            foreach ($raws as $name=>$raw) {
                $file=$safety.'/'.$name.'.json';
                if (file_put_contents($file,$raw,LOCK_EX)!==strlen($raw)) throw new RuntimeException('Safety copy failed.');
                chmod($file,0600);
                if (hash_file('sha256',$file)!==hash('sha256',$raw)) throw new RuntimeException('Safety verification failed.');
            }
            $changed=true;
            harmain_write($handles['accounts'],json_encode($afterA,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            harmain_write($handles['operations'],json_encode($afterO,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            foreach (['accounts'=>$afterA,'operations'=>$afterO] as $name=>$expected) {
                rewind($handles[$name]);
                if (json_decode(stream_get_contents($handles[$name]),true,512,JSON_THROW_ON_ERROR)!==$expected) throw new RuntimeException('Live recovery read-back failed.');
            }
            $report['safetyCopy']=basename($safety);
        }
        echo json_encode(['ok'=>true]+$report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        if ($changed) foreach ($raws as $name=>$raw) harmain_write($handles[$name],$raw);
        throw $error;
    } finally {
        foreach ($handles as $handle) { flock($handle,LOCK_UN); fclose($handle); }
    }
} elseif (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
