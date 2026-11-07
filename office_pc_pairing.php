<?php
declare(strict_types=1);
// Bounded device authorization. A public start cannot authorize a PC.
function tt_pc_pair_file():string{return TT_DATA_DIR.'/office-pairing.json';}
function tt_pc_pair_change(Closure $fn):mixed{
    tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/office-pairing.lock','c+');
    if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('PC authorization is busy.');
    try{
        $path=tt_pc_pair_file();if(is_link($path))throw new RuntimeException('Unsafe authorization storage.');
        $rows=is_file($path)?json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR):[];
        if(!is_array($rows)||count($rows)>100)throw new RuntimeException('PC authorization requires owner review.');
        foreach($rows as$id=>$row)if((int)($row['expiresAt']??0)<time())unset($rows[$id]);
        $result=$fn($rows);tt_backup_password_write($path,json_encode($rows,JSON_THROW_ON_ERROR));return $result;
    }finally{flock($h,LOCK_UN);fclose($h);}
}
function tt_pc_pair_start(string $id,string $secret,string $computer):array{
    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new InvalidArgumentException('Invalid PC authorization request.');
    $computer=trim(preg_replace('/[\x00-\x1f\x7f]/','',$computer)??'');$computer=mb_substr($computer,0,80);
    return tt_pc_pair_change(static function(array &$rows)use($id,$secret,$computer){
        if(isset($rows[$id])||count($rows)>=100)throw new InvalidArgumentException('Please try PC setup again later.');
        $rows[$id]=['secretHash'=>hash('sha256',$secret),'computer'=>$computer?:'Windows PC','code'=>(string)random_int(100000,999999),'state'=>'pending','expiresAt'=>time()+900];
        return ['ok'=>true,'state'=>'pending','code'=>$rows[$id]['code'],'approvalUrl'=>'https://app.transtradeinternational.com/connect-backup-pc.php?request='.$id];
    });
}
function tt_pc_pair_view(string $id):?array{
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))return null;
    return tt_pc_pair_change(static fn(array &$rows)=>isset($rows[$id])?array_intersect_key($rows[$id],array_flip(['computer','code','state','expiresAt'])):null);
}
function tt_pc_pair_approve(string $id):void{
    tt_pc_pair_change(static function(array &$rows)use($id){
        if(!isset($rows[$id])||$rows[$id]['state']!=='pending')throw new InvalidArgumentException('This PC request expired or was already approved. Start setup again.');
        if(tt_backup_password_get()===null)throw new InvalidArgumentException('Set your backup protection password in Backup & Recovery first.');
        $token=tt_office_backup_issue_token();$nonce=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($token,'aes-256-gcm',tt_backup_password_key(),OPENSSL_RAW_DATA,$nonce,$tag,'TRANSTRADE-PC-AUTHORIZATION');
        if($cipher===false)throw new RuntimeException('PC authorization could not be protected.');
        $rows[$id]+=['nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher)];$rows[$id]['state']='approved';
    });
}
function tt_pc_pair_poll(string $id,string $secret):array{
    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new InvalidArgumentException('Invalid PC authorization.');
    return tt_pc_pair_change(static function(array &$rows)use($id,$secret){
        if(!isset($rows[$id])||!hash_equals($rows[$id]['secretHash'],hash('sha256',$secret)))throw new InvalidArgumentException('PC authorization expired. Start setup again.');
        $r=$rows[$id];if($r['state']==='pending')return ['ok'=>true,'state'=>'pending'];
        $token=openssl_decrypt(base64_decode($r['cipher'],true),'aes-256-gcm',tt_backup_password_key(),OPENSSL_RAW_DATA,base64_decode($r['nonce'],true),base64_decode($r['tag'],true),'TRANSTRADE-PC-AUTHORIZATION');
        if(!is_string($token)||!tt_office_backup_token_valid($token))throw new InvalidArgumentException('This authorization was disconnected. Start setup again.');
        unset($rows[$id]);return ['ok'=>true,'state'=>'approved','token'=>$token];
    });
}
