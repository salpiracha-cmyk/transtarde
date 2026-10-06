"""Anonymous sessions allocate no per-client files; authenticated locks remain stable."""
import concurrent.futures
import pathlib
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-session-retention-') as directory:
    script = pathlib.Path(directory) / 'exercise.php'
    (pathlib.Path(directory) / 'baseline.php').write_text("<?php\ndeclare(strict_types=1);\n\n/** Private, locked session records. PHP receives a freshly serialized array,\n * never partially written file bytes. No session values enter application logs. */\nfinal class TTBaselineSessionStore implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface, SessionIdInterface {\n    private string $root;\n    private string $legacy;\n    private $lock = null;\n    private string $lockedId = '';\n\n    public function __construct(string $legacy) {\n        $this->legacy = $legacy;\n        $this->root = $legacy . '/records';\n        if (!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) throw new RuntimeException('Private session storage is unavailable.');\n        if (is_link($this->root) || !is_writable($this->root)) throw new RuntimeException('Private session storage is unavailable.');\n        chmod($this->root,0700);\n    }\n    private function valid(string $id): bool { return preg_match('/^[A-Za-z0-9,-]{1,256}$/D',$id) === 1; }\n    private function path(string $id): string { if(!$this->valid($id))throw new RuntimeException('Invalid session identifier.');return $this->root.'/'.hash('sha256',$id).'.json'; }\n    private function retired(string $id): string { return $this->root.'/'.hash('sha256',$id).'.retired'; }\n    private function legacyPath(string $id): string { $this->path($id);return $this->legacy.'/sess_'.$id; }\n    private function plainFile(string $path): bool { return is_file($path) && !is_link($path); }\n    private function acquire(string $id): void {\n        if($this->lockedId === $id && is_resource($this->lock))return;\n        $this->close();$path=$this->path($id).'.lock';\n        if(is_link($path))throw new RuntimeException('Private session lock is unavailable.');\n        $lock=fopen($path,'c+b');\n        if($lock===false || !flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Private session lock is unavailable.');}\n        chmod($path,0600);$this->lock=$lock;$this->lockedId=$id;\n    }\n    private function markRetired(string $id): void {\n        $path=$this->retired($id);\n        if(is_link($path) || file_put_contents($path,'retired',LOCK_EX)===false)throw new RuntimeException('Session migration could not be recorded.');\n        chmod($path,0600);\n    }\n    /** Legacy application sessions contain scalar values only. */\n    private function decodeLegacy(string $raw): ?array {\n        $values=[];$offset=0;$length=strlen($raw);\n        while($offset<$length){\n            $delimiter=strpos($raw,'|',$offset);if($delimiter===false)return null;\n            $key=substr($raw,$offset,$delimiter-$offset);if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$key))return null;\n            $tail=substr($raw,$delimiter+1);$value=@unserialize($tail,['allowed_classes'=>false]);\n            if(!is_string($value)&&!is_int($value)&&!is_bool($value)&&$value!==null)return null;\n            $encoded=serialize($value);if(!str_starts_with($tail,$encoded))return null;\n            $values[$key]=$value;$offset=$delimiter+1+strlen($encoded);\n        }\n        return $values;\n    }\n    private function save(string $id,array $values): void {\n        $path=$this->path($id);if(is_link($path))throw new RuntimeException('Private session record is unavailable.');\n        $json=json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);\n        $temp=$this->root.'/.pending-'.bin2hex(random_bytes(16));$handle=fopen($temp,'x+b');\n        if($handle===false)throw new RuntimeException('Private session write is unavailable.');\n        try{\n            chmod($temp,0600);$offset=0;$length=strlen($json);\n            while($offset<$length){$n=fwrite($handle,substr($json,$offset));if($n===false||$n===0)throw new RuntimeException('Private session write is unavailable.');$offset+=$n;}\n            if(!fflush($handle))throw new RuntimeException('Private session write is unavailable.');\n            if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Private session write is unavailable.');\n            fclose($handle);$handle=null;\n            if(!rename($temp,$path))throw new RuntimeException('Private session write could not be committed.');\n            chmod($path,0600);\n        }finally{if(is_resource($handle))fclose($handle);if(is_file($temp))unlink($temp);}\n    }\n    public function open(string $path,string $name): bool { return true; }\n    public function close(): bool { if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);} $this->lock=null;$this->lockedId='';return true; }\n    public function create_sid(): string { return bin2hex(random_bytes(32)); }\n    public function validateId(string $id): bool {\n        if(!$this->valid($id))return false;\n        return $this->plainFile($this->path($id)) || (!$this->plainFile($this->retired($id)) && $this->plainFile($this->legacyPath($id)));\n    }\n    public function read(string $id): string|false {\n        $this->acquire($id);$path=$this->path($id);\n        if($this->plainFile($path)){\n            $raw=file_get_contents($path);if($raw===false)throw new RuntimeException('Private session read is unavailable.');\n            $values=json_decode($raw,true,512,JSON_THROW_ON_ERROR);\n            if(!is_array($values))throw new RuntimeException('Private session record is invalid.');\n            return serialize($values);\n        }\n        $legacy=$this->legacyPath($id);\n        if(!$this->plainFile($this->retired($id))&&$this->plainFile($legacy)){\n            $handle=fopen($legacy,'rb');if($handle===false||!flock($handle,LOCK_EX)){if(is_resource($handle))fclose($handle);throw new RuntimeException('Session migration is unavailable.');}\n            try{\n                $raw=stream_get_contents($handle);$values=is_string($raw)?$this->decodeLegacy($raw):null;\n                // A damaged legacy record has no trustworthy authentication state.\n                // Retire it and return an anonymous session so sign-in remains usable.\n                if($values===null)$values=[];\n                $this->save($id,$values);$this->markRetired($id);unlink($legacy);\n            }finally{flock($handle,LOCK_UN);fclose($handle);}\n            return serialize($values);\n        }\n        return serialize([]);\n    }\n    public function write(string $id,string $data): bool {\n        $this->acquire($id);$values=@unserialize($data,['allowed_classes'=>false]);\n        if(!is_array($values))throw new RuntimeException('Private session payload is invalid.');\n        $this->save($id,$values);return true;\n    }\n    public function updateTimestamp(string $id,string $data): bool {\n        $this->acquire($id);$path=$this->path($id);\n        // PHP calls this only when the serialized session is unchanged.\n        // Refresh GC age without encoding, fsync or replacing the data record.\n        if(!$this->plainFile($path))return $this->write($id,$data);\n        if(!touch($path))throw new RuntimeException('Private session timestamp could not be updated.');\n        return true;\n    }\n    public function destroy(string $id): bool {\n        $this->acquire($id);$this->markRetired($id);\n        foreach([$this->path($id),$this->legacyPath($id)]as$path)if($this->plainFile($path)&&!unlink($path))throw new RuntimeException('Private session could not be retired.');\n        return true;\n    }\n    public function gc(int $max_lifetime): int|false {\n        $deleted=0;$cutoff=time()-$max_lifetime;\n        foreach(glob($this->root.'/*.json')?:[]as$path){\n            if(!$this->plainFile($path)||filemtime($path)>$cutoff)continue;\n            $lock=fopen($path.'.lock','c+b');if($lock===false)continue;chmod($path.'.lock',0600);\n            if(flock($lock,LOCK_EX|LOCK_NB)){\n                clearstatcache(true,$path);\n                if($this->plainFile($path)&&filemtime($path)<=$cutoff){$marker=substr($path,0,-5).'.retired';if(file_put_contents($marker,'retired',LOCK_EX)!==false){chmod($marker,0600);if(unlink($path))$deleted++;}}\n                flock($lock,LOCK_UN);\n            }\n            fclose($lock);\n        }\n        return $deleted;\n    }\n}\n")
    script.write_text(r'''<?php
require __DIR__.'/baseline.php';
require $argv[1].'/session_store.php';$dir=$argv[2].'/sessions';mkdir($dir,0700);
$store=new TTAtomicSessionStore($dir);$records=$dir.'/records';
function verify($ok,$why){if(!$ok)throw new RuntimeException($why);}
for($i=0;$i<2048;$i++){
 $id=$store->create_sid();verify(TTAtomicSessionStore::isNativeId($id),'native ID');
 verify($store->validateId($id),'issued anonymous ID accepted');
 $values=unserialize($store->read($id));verify(strlen($values['csrf']??'')===64,'anonymous CSRF');
 $store->write($id,serialize($values));$store->updateTimestamp($id,serialize($values));
 if($i%2===0)$store->destroy($id);$store->close();
}
verify(count(glob($records.'/*.json'))===0,'no anonymous records');
verify(count(glob($records.'/*.json.lock'))===0,'no anonymous per-ID locks');
verify(count(glob($records.'/*.retired'))===0,'no anonymous retirement markers');
verify($store->gc(3600)===0,'anonymous GC requires no files');
verify((fileperms($records.'/.anonymous-key')&0777)===0600,'private signing key');
// Tokens cannot be forged, modified or used as a serialized user identity.
$id=$store->create_sid();$values=unserialize($store->read($id));$store->close();
$other=new TTAtomicSessionStore($dir);verify(unserialize($other->read($id))===$values,'cross-worker CSRF continuity');$other->close();
$fake=substr($id,0,-1).(str_ends_with($id,'0')?'1':'0');verify(!$store->validateId($fake),'altered token rejected');
verify(unserialize($store->read($fake))===[],'altered token has no authority');$store->close();
$key=file_get_contents($records.'/.anonymous-key');$at=dechex(time()-7200);$nonce=str_repeat('a',64);
$expired='tt3-'.$at.'-'.$nonce.'-'.hash_hmac('sha256','anon|'.$at.'|'.$nonce,$key);
verify(!$store->validateId($expired),'expired anonymous token rejected');
file_put_contents($dir.'/sess_'.$fake,'user_id|i:1;');verify(!$store->validateId($fake),'native legacy fallback prohibited');
verify(unserialize($store->read($fake))===[],'native legacy ignored');$store->close();
// Old workers cannot resume new stateless anonymous forms; they fail CSRF
// safely and require a refresh during rollout. Authenticated continuity remains.
$id=$store->create_sid();$oldStore=new TTBaselineSessionStore($dir);
verify(!$oldStore->validateId($id),'old worker rejects stateless anonymous token');
// Authentication is persisted under the original stable per-ID lock.
$id=$store->create_sid();$store->write($id,serialize(['user_id'=>1,'csrf'=>'authenticated']));$store->close();
$path=$records.'/'.hash('sha256',$id).'.json';verify(is_file($path),'authenticated record exists');
$inode=fileinode($path.'.lock');
verify($oldStore->validateId($id),'old worker validates authenticated token');
verify(unserialize($oldStore->read($id))['user_id']===1,'old worker resumes authenticated session');
$oldStore->destroy($id);$oldStore->close();
verify(!isset(unserialize($store->read($id))['user_id']),'logged-out cookie has no authority');$store->close();
verify(fileinode($path.'.lock')===$inode,'authenticated lock inode retained');
// Classic migrated sessions cannot be resurrected from old files after logout.
$old='legacy-retention';file_put_contents($dir.'/sess_'.$old,'counter|i:7;');
verify(unserialize($store->read($old))['counter']===7,'legacy continuity');$store->destroy($old);$store->close();
file_put_contents($dir.'/sess_'.$old,'counter|i:7;');verify(!$store->validateId($old),'classic retirement prevents replay');
// An active authenticated record remains protected against concurrent GC.
$id=$store->create_sid();$store->write($id,serialize(['user_id'=>1]));
$path=$records.'/'.hash('sha256',$id).'.json';touch($path,time()-7200);
verify($store->gc(3600)===0&&is_file($path),'active lock prevents GC');$store->close();
verify($store->gc(3600)===1,'expired authenticated record collected');
echo "PASS 2048 anonymous sessions without per-client files, signed CSRF continuity, forgery/expiry rejection, authenticated logout and legacy replay protection\n";
''')
    def php(path, *args):
        return subprocess.run(['php', '-d', 'disable_functions=link', str(path), str(ROOT), *map(str,args)],
                              capture_output=True, text=True, check=True).stdout
    try:
        print(php(script, directory).strip())
    except subprocess.CalledProcessError as error:
        raise AssertionError(error.stdout + error.stderr) from error
    parallel = pathlib.Path(directory) / 'parallel'
    parallel.mkdir()
    seed = pathlib.Path(directory) / 'seed.php'
    seed.write_text("<?php require $argv[1].'/session_store.php';$s=new TTAtomicSessionStore($argv[2]);echo $s->create_sid();")
    sid = php(seed, parallel)
    worker = pathlib.Path(directory) / 'worker.php'
    worker.write_text(r'''<?php
require $argv[1].'/session_store.php';$s=new TTAtomicSessionStore($argv[2]);
$v=unserialize($s->read($argv[3]));$n=$v['counter']??0;$v['counter']=$n+1;
$s->write($argv[3],serialize($v));$s->close();echo $n;
''')
    # Seed persisted state before concurrent workers; anonymous reads intentionally have no lock.
    assert php(worker, parallel, sid) == '0'
    def increment(_):
        return int(php(worker, parallel, sid))
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        values = list(pool.map(increment, range(64)))
    assert sorted(values) == list(range(1,65)), 'Authenticated locking lost an update'
    print('PASS 64 concurrent authenticated increments with PHP link disabled')
