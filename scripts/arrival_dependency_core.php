<?php
declare(strict_types=1);

/** Owner-authorised test identities. A partial match never authorises removal. */
function arrival_test_manifest(): array {
    return [
        'PH-00001'=>['truck'=>'ABC-123','soda'=>'26002','payableWeight'=>30500,'bags'=>400],
        'PH-00002'=>['truck'=>'ABC-345','soda'=>'26001','payableWeight'=>39000,'bags'=>400],
        'PH-00003'=>['truck'=>'TK-123','soda'=>'26002','payableWeight'=>50000,'bags'=>500],
    ];
}

function arrival_dependency_rows(array $values): array {
    $rows=[];
    foreach ($values as $key=>$value) {
        $decoded=is_string($value)?json_decode($value,true,512,JSON_THROW_ON_ERROR):$value;
        if (is_array($decoded)) $rows[(string)$key]=$decoded;
    }
    return $rows;
}

/** Exact ID matches or token-delimited references; never match a shared Soda/party. */
function arrival_reference_paths(mixed $value, array $tokens, string $path=''): array {
    $found=[];
    if (is_array($value)) {
        foreach ($value as $key=>$child) {
            $next=$path===''?(string)$key:$path.'.'.$key;
            if (in_array((string)$key,$tokens,true)) $found[]=$next.' (key)';
            $found=array_merge($found,arrival_reference_paths($child,$tokens,$next));
        }
    } elseif (is_scalar($value)) {
        foreach ($tokens as $token) {
            if ((string)$value===$token || preg_match('/(?<![A-Za-z0-9])'.preg_quote($token,'/').'(?![A-Za-z0-9])/',(string)$value)) {
                $found[]=$path;break;
            }
        }
    }
    return array_values(array_unique($found));
}

function arrival_dependency_audit(array $values, array $accounts): array {
    $collections=arrival_dependency_rows($values);$targets=[];$tokens=[];$errors=[];
    foreach (arrival_test_manifest() as $slip=>$expected) {
        $matches=array_values(array_filter($collections['tt30slips']??[],static fn($r)=>is_array($r)&&($r['slip']??'')===$slip));
        if (count($matches)!==1) {$errors[]=$slip.': expected exactly one saved arrival, found '.count($matches);continue;}
        $row=$matches[0];$mismatch=[];
        foreach ($expected as $key=>$value) if (is_numeric($value)?(!is_numeric($row[$key]??null)||(float)$row[$key]!== (float)$value):strtoupper(trim((string)($row[$key]??'')))!==$value) $mismatch[]=$key;
        if (($row['unloadingDate']??'')!=='2026-09-23') $mismatch[]='unloadingDate';
        if (strcasecmp(trim((string)($row['broker']??'')),'shams')!==0) $mismatch[]='broker';
        if (empty($row['id'])||empty($row['queueId'])) $mismatch[]='stable arrival / queue IDs';
        if ($mismatch) $errors[]=$slip.': identity mismatch: '.implode(', ',$mismatch);
        $targets[$slip]=$row;
        $tokens[]=$slip;
        foreach (['id','queueId','bridgeId'] as $key) {
            $id=trim((string)($row[$key]??''));
            // Tiny integer IDs are ambiguous: report the blocker, never overmatch.
            if ($id!==''&&strlen($id)>=6) $tokens[]=$id;
            elseif ($id!=='') $errors[]=$slip.': ambiguous short '.$key;
        }
    }
    $tokens=array_values(array_unique($tokens));$references=[];
    foreach (['operations'=>$collections,'accounts'=>$accounts] as $store=>$data) {
        foreach ($data as $collection=>$rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $id=>$row) {
                $paths=arrival_reference_paths($row,$tokens);
                if (in_array((string)$id,$tokens,true)) $paths[]='(record key)';
                if (!$paths) continue;
                $references[]=['store'=>$store,'collection'=>$collection,'key'=>(string)$id,'id'=>is_array($row)?(string)($row['id']??''):'','paths'=>$paths,'sha256'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),
                    'status'=>is_array($row)?(string)($row['status']??''):'','journalId'=>is_array($row)?(string)($row['journalId']??''):'','billId'=>is_array($row)?(string)($row['billId']??''):''];
            }
        }
    }
    // Resolve financial dependencies by their canonical IDs too, even when the
    // dependent payment/allocation no longer carries the original PH number.
    $financialIds=[];
    foreach ($references as $r) if ($r['store']==='accounts') foreach (['key','id','journalId','billId'] as $key) if ($r[$key]!=='') $financialIds[]=$r[$key];
    $financialIds=array_values(array_unique($financialIds));$indirect=[];$seen=[];
    do {
        $changed=false;
        if ($financialIds) foreach ($accounts as $collection=>$rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $id=>$row) {
                $recordKey=$collection.'|'.$id;
                if (isset($seen[$recordKey])) continue;
                $paths=arrival_reference_paths($row,$financialIds);
                if (!array_is_list($rows)&&in_array((string)$id,$financialIds,true)) $paths[]='(record key)';
                if (!$paths) continue;
                $seen[$recordKey]=true;$changed=true;
                $indirect[]=['collection'=>$collection,'key'=>(string)$id,'paths'=>$paths];
                if (!array_is_list($rows)) $financialIds[]=(string)$id;
                if (is_array($row)) foreach (['id','journalId','billId','eventId','postingIdentity'] as $key) {
                    if (is_scalar($row[$key]??null)&&strlen((string)$row[$key])>=3) $financialIds[]=(string)$row[$key];
                }
                $financialIds=array_values(array_unique($financialIds));
            }
        }
    } while ($changed);
    $sodas=[];
    foreach (['purchaseSodas','purchaseSodasV2'] as $collection) foreach ((array)($accounts[$collection]??[]) as $row) {
        if (!is_array($row)||!in_array((string)($row['sodaNo']??''),['26001','26002'],true)) continue;
        $sodas[]=['collection'=>$collection,'id'=>$row['id']??'','sodaNo'=>$row['sodaNo'],'entity'=>$row['entity']??'','locationId'=>$row['locationId']??'','locationName'=>$row['locationName']??$row['location']??''];
    }
    return ['readOnly'=>true,'identityVerified'=>!$errors,'errors'=>$errors,'targets'=>$targets,'references'=>$references,'financialLinks'=>$indirect,'sourceSodas'=>$sodas,
        'scope'=>'Current operational and Accounts stores only. Historical snapshots/audit are retained. This report never authorises deletion of journals, bills, payments or source Sodas.'];
}
