<?php
declare(strict_types=1);
class TTDocumentAiLimit extends RuntimeException {}
/** Atomic, installation-wide budgets; independent of browser sessions/workers. */
function tt_ai_limit_state(callable $fn): mixed {
    $dir=TT_DATA_DIR.'/backups/.security-limits';if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('AI usage limits unavailable.');
    $lock=fopen($dir.'/ai.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('AI usage limits unavailable.');
    try{$path=$dir.'/ai.json';$s=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];if(!is_array($s))throw new RuntimeException('AI usage limits invalid.');$result=$fn($s);$tmp=$path.'.tmp-'.bin2hex(random_bytes(6));$json=json_encode($s,JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$path))throw new RuntimeException('AI usage limits could not be saved.');chmod($path,0600);return $result;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function tt_ai_limit_acquire(int $userId,int $bytes): string {
    if($userId<=0||$bytes<0)throw new RuntimeException('Authenticated AI principal required.');
    return tt_ai_limit_state(static function(array &$s)use($userId,$bytes):string{
        $now=time();$day=gmdate('Y-m-d');if(($s['day']??'')!==$day)$s=['day'=>$day,'calls'=>[],'users'=>[],'count'=>0,'bytes'=>0,'leases'=>$s['leases']??[]];
        $s['calls']=array_values(array_filter($s['calls'],static fn($r)=>(int)$r['at']>$now-60));
        $s['leases']=array_filter($s['leases'],static fn($r)=>(int)$r['until']>$now);
        $u=$s['users'][$userId]??['count'=>0,'bytes'=>0];$ownCalls=count(array_filter($s['calls'],static fn($r)=>(int)$r['user']===$userId));$ownLeases=count(array_filter($s['leases'],static fn($r)=>(int)$r['user']===$userId));
        // Every provider attempt (including retries and health probes) consumes
        // one request and its input bytes; failures are not refunded.
        if(count($s['calls'])>=30||$ownCalls>=10||count($s['leases'])>=4||$ownLeases>=2||$s['count']>=300||$u['count']>=100||$s['bytes']+$bytes>128*1024*1024||$u['bytes']+$bytes>32*1024*1024)throw new TTDocumentAiLimit('Document reading usage limit reached. Try later or enter the document manually.',429);
        $id=bin2hex(random_bytes(16));$s['calls'][]=['at'=>$now,'user'=>$userId];$s['leases'][$id]=['until'=>$now+90,'user'=>$userId];$s['count']++;$s['bytes']+=$bytes;$u['count']++;$u['bytes']+=$bytes;$s['users'][$userId]=$u;return $id;
    });
}
function tt_ai_limit_release(string $id):void {tt_ai_limit_state(static function(array &$s)use($id):void{unset($s['leases'][$id]);});}
function tt_ai_metered(array $payload,callable $call):mixed {
    $user=function_exists('tt_current_user')?tt_current_user():null;
    $lease=tt_ai_limit_acquire((int)($user['id']??0),strlen(json_encode($payload,JSON_THROW_ON_ERROR)));
    try{return $call();}finally{tt_ai_limit_release($lease);}
}
