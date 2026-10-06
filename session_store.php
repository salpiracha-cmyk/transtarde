<?php
declare(strict_types=1);

/** Private, locked session records. PHP receives a freshly serialized array,
 * never partially written file bytes. No session values enter application logs. */
final class TTAtomicSessionStore implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface, SessionIdInterface {
    private string $root;
    private string $legacy;
    private $lock = null;
    private string $lockedId = '';

    public function __construct(string $legacy) {
        $this->legacy = $legacy;
        $this->root = $legacy . '/records';
        if (!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) throw new RuntimeException('Private session storage is unavailable.');
        if (is_link($this->root) || !is_writable($this->root)) throw new RuntimeException('Private session storage is unavailable.');
        chmod($this->root,0700);
    }
    private function valid(string $id): bool { return preg_match('/^[A-Za-z0-9,-]{1,256}$/D',$id) === 1; }
    private function path(string $id): string { if(!$this->valid($id))throw new RuntimeException('Invalid session identifier.');return $this->root.'/'.hash('sha256',$id).'.json'; }
    private function retired(string $id): string { return $this->root.'/'.hash('sha256',$id).'.retired'; }
    private function legacyPath(string $id): string { $this->path($id);return $this->legacy.'/sess_'.$id; }
    private function plainFile(string $path): bool { return is_file($path) && !is_link($path); }
    private function acquire(string $id): void {
        if($this->lockedId === $id && is_resource($this->lock))return;
        $this->close();$path=$this->path($id).'.lock';
        if(is_link($path))throw new RuntimeException('Private session lock is unavailable.');
        $lock=fopen($path,'c+b');
        if($lock===false || !flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Private session lock is unavailable.');}
        chmod($path,0600);$this->lock=$lock;$this->lockedId=$id;
    }
    private function markRetired(string $id): void {
        $path=$this->retired($id);
        if(is_link($path) || file_put_contents($path,'retired',LOCK_EX)===false)throw new RuntimeException('Session migration could not be recorded.');
        chmod($path,0600);
    }
    /** Legacy application sessions contain scalar values only. */
    private function decodeLegacy(string $raw): ?array {
        $values=[];$offset=0;$length=strlen($raw);
        while($offset<$length){
            $delimiter=strpos($raw,'|',$offset);if($delimiter===false)return null;
            $key=substr($raw,$offset,$delimiter-$offset);if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$key))return null;
            $tail=substr($raw,$delimiter+1);$value=@unserialize($tail,['allowed_classes'=>false]);
            if(!is_string($value)&&!is_int($value)&&!is_bool($value)&&$value!==null)return null;
            $encoded=serialize($value);if(!str_starts_with($tail,$encoded))return null;
            $values[$key]=$value;$offset=$delimiter+1+strlen($encoded);
        }
        return $values;
    }
    private function save(string $id,array $values): void {
        $path=$this->path($id);if(is_link($path))throw new RuntimeException('Private session record is unavailable.');
        $json=json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $temp=$this->root.'/.pending-'.bin2hex(random_bytes(16));$handle=fopen($temp,'x+b');
        if($handle===false)throw new RuntimeException('Private session write is unavailable.');
        try{
            chmod($temp,0600);$offset=0;$length=strlen($json);
            while($offset<$length){$n=fwrite($handle,substr($json,$offset));if($n===false||$n===0)throw new RuntimeException('Private session write is unavailable.');$offset+=$n;}
            if(!fflush($handle))throw new RuntimeException('Private session write is unavailable.');
            if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Private session write is unavailable.');
            fclose($handle);$handle=null;
            if(!rename($temp,$path))throw new RuntimeException('Private session write could not be committed.');
            chmod($path,0600);
        }finally{if(is_resource($handle))fclose($handle);if(is_file($temp))unlink($temp);}
    }
    public function open(string $path,string $name): bool { return true; }
    public function close(): bool { if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);} $this->lock=null;$this->lockedId='';return true; }
    public function create_sid(): string { return bin2hex(random_bytes(32)); }
    public function validateId(string $id): bool {
        if(!$this->valid($id))return false;
        return $this->plainFile($this->path($id)) || (!$this->plainFile($this->retired($id)) && $this->plainFile($this->legacyPath($id)));
    }
    public function read(string $id): string|false {
        $this->acquire($id);$path=$this->path($id);
        if($this->plainFile($path)){
            $raw=file_get_contents($path);if($raw===false)throw new RuntimeException('Private session read is unavailable.');
            $values=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($values))throw new RuntimeException('Private session record is invalid.');
            return serialize($values);
        }
        $legacy=$this->legacyPath($id);
        if(!$this->plainFile($this->retired($id))&&$this->plainFile($legacy)){
            $handle=fopen($legacy,'rb');if($handle===false||!flock($handle,LOCK_EX)){if(is_resource($handle))fclose($handle);throw new RuntimeException('Session migration is unavailable.');}
            try{
                $raw=stream_get_contents($handle);$values=is_string($raw)?$this->decodeLegacy($raw):null;
                // A damaged legacy record has no trustworthy authentication state.
                // Retire it and return an anonymous session so sign-in remains usable.
                if($values===null)$values=[];
                $this->save($id,$values);$this->markRetired($id);unlink($legacy);
            }finally{flock($handle,LOCK_UN);fclose($handle);}
            return serialize($values);
        }
        return serialize([]);
    }
    public function write(string $id,string $data): bool {
        $this->acquire($id);$values=@unserialize($data,['allowed_classes'=>false]);
        if(!is_array($values))throw new RuntimeException('Private session payload is invalid.');
        $this->save($id,$values);return true;
    }
    public function updateTimestamp(string $id,string $data): bool { return $this->write($id,$data); }
    public function destroy(string $id): bool {
        $this->acquire($id);$this->markRetired($id);
        foreach([$this->path($id),$this->legacyPath($id)]as$path)if($this->plainFile($path)&&!unlink($path))throw new RuntimeException('Private session could not be retired.');
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        $deleted=0;$cutoff=time()-$max_lifetime;
        foreach(glob($this->root.'/*.json')?:[]as$path){
            if(!$this->plainFile($path)||filemtime($path)>$cutoff)continue;
            $lock=fopen($path.'.lock','c+b');if($lock===false)continue;chmod($path.'.lock',0600);
            if(flock($lock,LOCK_EX|LOCK_NB)){
                clearstatcache(true,$path);
                if($this->plainFile($path)&&filemtime($path)<=$cutoff){$marker=substr($path,0,-5).'.retired';if(file_put_contents($marker,'retired',LOCK_EX)!==false){chmod($marker,0600);if(unlink($path))$deleted++;}}
                flock($lock,LOCK_UN);
            }
            fclose($lock);
        }
        return $deleted;
    }
}
