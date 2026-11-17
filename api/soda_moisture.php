<?php
declare(strict_types=1);

function soda_free_moisture(mixed $value): ?float {
    if ($value===null||$value==='') return null;
    if (!is_numeric($value)||!in_array((float)$value,[14.0,15.0,15.5,16.0],true)) {
        throw new InvalidArgumentException('Choose 14%, 15%, 15.5% or 16% for free moisture.');
    }
    return (float)$value;
}

/** Historical Pohanches carry their own term once issued. */
function pohanch_free_moisture(array $slip, ?array $prior): ?float {
    $id=(string)($slip['sourceSodaId']??'');
    if ($prior&&(string)($prior['soda']??'')===(string)($slip['soda']??'')
        && (!isset($prior['sourceSodaId'])||(string)$prior['sourceSodaId']===$id)) {
        return soda_free_moisture($prior['freeMoisturePct']??null);
    }
    if ($id===''||!is_file(TT_DATA_DIR.'/accounts.json')) return null;
    $file=fopen(TT_DATA_DIR.'/accounts.json','rb');
    if (!$file) throw new RuntimeException('Saved SODA terms could not be read.');
    try {
        if (!flock($file,LOCK_SH|LOCK_NB)) throw new RuntimeException('Saved SODA terms could not be read.');
        $store=json_decode(stream_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    } finally { fclose($file); }
    foreach (['purchaseSodas','purchaseSodasV2'] as $collection) {
        foreach (($store[$collection]??[]) as $key=>$soda) {
            if ((string)($soda['id']??$key)!==$id) continue;
            if (($soda['entity']??'TTI')!==($slip['entity']??'TTI')||(string)($soda['sodaNo']??'')!==(string)($slip['soda']??'')
                || ($soda['commodity']??'RICE')!=='RICE'||($soda['readyRoute']??'')==='EX_MILL') return null;
            return soda_free_moisture($soda['freeMoisturePct']??null);
        }
    }
    return null;
}
