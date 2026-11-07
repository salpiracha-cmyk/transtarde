<?php
declare(strict_types=1);
try {
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
    $root=(string)getenv('TT_QUEUE_ROOT');
    if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$root))throw new RuntimeException('Unexpected target');
    foreach(['api/exmill_queue_core.php'=>'TT_QUEUE_CORE_SHA','accounts/source-bridge.js'=>'TT_QUEUE_BRIDGE_SHA','api/bridge_outbox.php'=>'TT_QUEUE_ENDPOINT_SHA'] as $path=>$env){
        $sha=(string)getenv($env);if(strlen($sha)!==64||!hash_equals($sha,(string)hash_file('sha256',$root.'/'.$path)))throw new RuntimeException('Deploy the reviewed queue fix first');
    }
    require $root.'/auth_store.php';require $root.'/api/exmill_queue_core.php';
    $h=fopen(TT_DATA_DIR.'/accounts.json','r+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Accounts busy');
    try {
        $raw=(string)stream_get_contents($h);$store=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($store)||array_is_list($store))throw new RuntimeException('Invalid Accounts');
        $before=$store;$result=eq_retire($store,'Owner-authorised draft queue retirement 2026-11-07');
        $beforeFacts=$before;$afterFacts=$store;foreach(['bridgeOutbox','bridgeOutboxRetired'] as $k){unset($beforeFacts[$k],$afterFacts[$k]);}
        if($beforeFacts!==$afterFacts)throw new RuntimeException('Financial sources changed');
        if($result['retiredDrafts']>0){
            $store['revision']=(int)($store['revision']??0)+1;
            $encoded=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(json_decode($encoded,true,512,JSON_THROW_ON_ERROR)!==$store)throw new RuntimeException('Round trip mismatch');
            rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Queue archive write failed');
            rewind($h);if(!hash_equals(hash('sha256',$encoded),hash('sha256',(string)stream_get_contents($h))))throw new RuntimeException('Readback mismatch');
        }
        echo json_encode($result+['financialSourcesUnchanged'=>true,'originalQueueMessagesArchived'=>true,'remainingActiveMessages'=>count((array)($store['bridgeOutbox']??[]))],JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {flock($h,LOCK_UN);fclose($h);}
} catch(Throwable $e){fwrite(STDERR,'Draft queue retirement stopped: '.$e->getMessage().PHP_EOL);exit(1);}
