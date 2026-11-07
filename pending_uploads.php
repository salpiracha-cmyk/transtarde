<?php
declare(strict_types=1);
/** Canonical Accounts references are the claim ledger. Always lock Accounts first. */
function tt_pending_types(): array {return ['receipt'=>['accounts-receipt-uploads','exportReceipts','bankAdviceFileRef'],'bagbill'=>['bag-bill-uploads','bagSupplierBills','attachmentToken']];}
function tt_pending_refs(array $books): array {
    $refs=[];foreach(tt_pending_types() as [$dir,$collection,$field])foreach((array)($books[$collection]??[]) as $row)if(is_array($row)&&!empty($row[$field]))$refs[$dir.'/'.basename((string)$row[$field])]=(string)($row['entity']??'');return $refs;
}
function tt_pending_locked(callable $callback): mixed {
    $dir=TT_DATA_DIR.'/backups/.pending-uploads';if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Upload quota storage unavailable.');
    $lock=fopen($dir.'/state.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Upload quota storage unavailable.');
    try{$path=$dir.'/state.json';$state=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['files'=>[]];if(!is_array($state)||!is_array($state['files']??null))throw new RuntimeException('Upload quota state invalid.');$result=$callback($state);$tmp=$path.'.tmp-'.bin2hex(random_bytes(6));$json=json_encode($state,JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$path))throw new RuntimeException('Upload quota could not be saved.');chmod($path,0600);return $result;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function tt_pending_save(array $user,string $entity,string $kind,string $source,string $token,int $size): void {
    [$folder]=tt_pending_types()[$kind]??throw new InvalidArgumentException('Unknown attachment type.');
    if(basename($token)!==$token||$size<=0)throw new InvalidArgumentException('Invalid attachment.');
    tt_ensure_data_dir();$accounts=fopen(TT_DATA_DIR.'/accounts.json','c+');if(!$accounts||!flock($accounts,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{$raw=stream_get_contents($accounts);$books=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);$refs=tt_pending_refs($books);$relative=$folder.'/'.$token;
        tt_pending_locked(static function(array &$state)use($user,$entity,$source,$relative,$size,$refs):void{
            $now=time();$uid=(string)$user['id'];$used=0;$own=0;
            foreach($state['files'] as $name=>$row){
                if(isset($refs[$name])){unset($state['files'][$name]);continue;}
                if((int)$row['expires']<$now){$path=TT_DATA_DIR.'/'.$name;if(is_file($path)&&!unlink($path))throw new RuntimeException('Expired upload could not be removed.');unset($state['files'][$name]);continue;}
                $used+=(int)$row['size'];if((string)$row['user']===$uid)$own+=(int)$row['size'];
            }
            if($used+$size>256*1024*1024||$own+$size>64*1024*1024)throw new InvalidArgumentException('Too many unfinished uploads. Save the pending invoices or wait for unused files to expire after 24 hours.');
            // Record reservation before moving the upload; a crash never leaves
            // an untracked permanent file. Unused reservations expire safely.
            $state['files'][$relative]=['user'=>$uid,'entity'=>$entity,'size'=>$size,'expires'=>$now+86400];
        });
        $dir=TT_DATA_DIR.'/'.$folder;if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Upload storage unavailable.');
        if(!move_uploaded_file($source,TT_DATA_DIR.'/'.$relative))throw new RuntimeException('Attachment could not be saved.');
        chmod(TT_DATA_DIR.'/'.$relative,0600);
    }finally{flock($accounts,LOCK_UN);fclose($accounts);}
}
/** Called under the same Accounts lock as the invoice/receipt commit. */
function tt_pending_claim(array $user,string $entity,string $kind,string $token,array $books): void {
    if($token==='')return;[$folder]=tt_pending_types()[$kind]??throw new InvalidArgumentException('Unknown attachment type.');
    if(basename($token)!==$token||!preg_match('/^(receipt|bagbill)-[A-Za-z0-9-]+\.(pdf|png|jpg|jpeg|webp)$/D',$token))throw new InvalidArgumentException('Invalid attachment reference.');
    $relative=$folder.'/'.$token;$refs=tt_pending_refs($books);
    if(isset($refs[$relative])){if($refs[$relative]!==$entity)throw new InvalidArgumentException('Attachment belongs to another company.');return;}
    tt_pending_locked(static function(array &$state)use($user,$entity,$relative):void{
        $row=$state['files'][$relative]??null;
        if(!$row||(string)$row['user']!==(string)$user['id']||$row['entity']!==$entity||(int)$row['expires']<time()||!is_file(TT_DATA_DIR.'/'.$relative))throw new InvalidArgumentException('Upload this attachment again before saving the record.');
    });
}
function tt_pending_unclaimed(?array $books=null): array {
    $path=TT_DATA_DIR.'/backups/.pending-uploads/state.json';if(!is_file($path))return [];
    $file=TT_DATA_DIR.'/accounts.json';$provided=$books!==null;$books??=[];
    if(!$provided&&is_file($file)){$h=fopen($file,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{$raw=stream_get_contents($h);$books=$raw===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);}finally{flock($h,LOCK_UN);fclose($h);}}
    return tt_pending_locked(static fn(array &$state)=>array_diff_key($state['files'],tt_pending_refs($books)));
}
