"""Native sessions have bounded lock metadata; legacy logout remains irreversible."""
import pathlib
import concurrent.futures
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-session-retention-') as directory:
    script = pathlib.Path(directory) / 'exercise.php'
    baseline = pathlib.Path(directory) / 'baseline.php'
    baseline.write_text("<?php\ndeclare(strict_types=1);\n\n/** Private, locked session records. PHP receives a freshly serialized array,\n * never partially written file bytes. No session values enter application logs. */\nfinal class TTBaselineSessionStore implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface, SessionIdInterface {\n    private string $root;\n    private string $legacy;\n    private $lock = null;\n    private string $lockedId = '';\n\n    public function __construct(string $legacy) {\n        $this->legacy = $legacy;\n        $this->root = $legacy . '/records';\n        if (!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) throw new RuntimeException('Private session storage is unavailable.');\n        if (is_link($this->root) || !is_writable($this->root)) throw new RuntimeException('Private session storage is unavailable.');\n        chmod($this->root,0700);\n    }\n    private function valid(string $id): bool { return preg_match('/^[A-Za-z0-9,-]{1,256}$/D',$id) === 1; }\n    private function path(string $id): string { if(!$this->valid($id))throw new RuntimeException('Invalid session identifier.');return $this->root.'/'.hash('sha256',$id).'.json'; }\n    private function retired(string $id): string { return $this->root.'/'.hash('sha256',$id).'.retired'; }\n    private function legacyPath(string $id): string { $this->path($id);return $this->legacy.'/sess_'.$id; }\n    private function plainFile(string $path): bool { return is_file($path) && !is_link($path); }\n    private function acquire(string $id): void {\n        if($this->lockedId === $id && is_resource($this->lock))return;\n        $this->close();$path=$this->path($id).'.lock';\n        if(is_link($path))throw new RuntimeException('Private session lock is unavailable.');\n        $lock=fopen($path,'c+b');\n        if($lock===false || !flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Private session lock is unavailable.');}\n        chmod($path,0600);$this->lock=$lock;$this->lockedId=$id;\n    }\n    private function markRetired(string $id): void {\n        $path=$this->retired($id);\n        if(is_link($path) || file_put_contents($path,'retired',LOCK_EX)===false)throw new RuntimeException('Session migration could not be recorded.');\n        chmod($path,0600);\n    }\n    /** Legacy application sessions contain scalar values only. */\n    private function decodeLegacy(string $raw): ?array {\n        $values=[];$offset=0;$length=strlen($raw);\n        while($offset<$length){\n            $delimiter=strpos($raw,'|',$offset);if($delimiter===false)return null;\n            $key=substr($raw,$offset,$delimiter-$offset);if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$key))return null;\n            $tail=substr($raw,$delimiter+1);$value=@unserialize($tail,['allowed_classes'=>false]);\n            if(!is_string($value)&&!is_int($value)&&!is_bool($value)&&$value!==null)return null;\n            $encoded=serialize($value);if(!str_starts_with($tail,$encoded))return null;\n            $values[$key]=$value;$offset=$delimiter+1+strlen($encoded);\n        }\n        return $values;\n    }\n    private function save(string $id,array $values): void {\n        $path=$this->path($id);if(is_link($path))throw new RuntimeException('Private session record is unavailable.');\n        $json=json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);\n        $temp=$this->root.'/.pending-'.bin2hex(random_bytes(16));$handle=fopen($temp,'x+b');\n        if($handle===false)throw new RuntimeException('Private session write is unavailable.');\n        try{\n            chmod($temp,0600);$offset=0;$length=strlen($json);\n            while($offset<$length){$n=fwrite($handle,substr($json,$offset));if($n===false||$n===0)throw new RuntimeException('Private session write is unavailable.');$offset+=$n;}\n            if(!fflush($handle))throw new RuntimeException('Private session write is unavailable.');\n            if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Private session write is unavailable.');\n            fclose($handle);$handle=null;\n            if(!rename($temp,$path))throw new RuntimeException('Private session write could not be committed.');\n            chmod($path,0600);\n        }finally{if(is_resource($handle))fclose($handle);if(is_file($temp))unlink($temp);}\n    }\n    public function open(string $path,string $name): bool { return true; }\n    public function close(): bool { if(is_resource($this->lock)){flock($this->lock,LOCK_UN);fclose($this->lock);} $this->lock=null;$this->lockedId='';return true; }\n    public function create_sid(): string { return bin2hex(random_bytes(32)); }\n    public function validateId(string $id): bool {\n        if(!$this->valid($id))return false;\n        return $this->plainFile($this->path($id)) || (!$this->plainFile($this->retired($id)) && $this->plainFile($this->legacyPath($id)));\n    }\n    public function read(string $id): string|false {\n        $this->acquire($id);$path=$this->path($id);\n        if($this->plainFile($path)){\n            $raw=file_get_contents($path);if($raw===false)throw new RuntimeException('Private session read is unavailable.');\n            $values=json_decode($raw,true,512,JSON_THROW_ON_ERROR);\n            if(!is_array($values))throw new RuntimeException('Private session record is invalid.');\n            return serialize($values);\n        }\n        $legacy=$this->legacyPath($id);\n        if(!$this->plainFile($this->retired($id))&&$this->plainFile($legacy)){\n            $handle=fopen($legacy,'rb');if($handle===false||!flock($handle,LOCK_EX)){if(is_resource($handle))fclose($handle);throw new RuntimeException('Session migration is unavailable.');}\n            try{\n                $raw=stream_get_contents($handle);$values=is_string($raw)?$this->decodeLegacy($raw):null;\n                // A damaged legacy record has no trustworthy authentication state.\n                // Retire it and return an anonymous session so sign-in remains usable.\n                if($values===null)$values=[];\n                $this->save($id,$values);$this->markRetired($id);unlink($legacy);\n            }finally{flock($handle,LOCK_UN);fclose($handle);}\n            return serialize($values);\n        }\n        return serialize([]);\n    }\n    public function write(string $id,string $data): bool {\n        $this->acquire($id);$values=@unserialize($data,['allowed_classes'=>false]);\n        if(!is_array($values))throw new RuntimeException('Private session payload is invalid.');\n        $this->save($id,$values);return true;\n    }\n    public function updateTimestamp(string $id,string $data): bool {\n        $this->acquire($id);$path=$this->path($id);\n        // PHP calls this only when the serialized session is unchanged.\n        // Refresh GC age without encoding, fsync or replacing the data record.\n        if(!$this->plainFile($path))return $this->write($id,$data);\n        if(!touch($path))throw new RuntimeException('Private session timestamp could not be updated.');\n        return true;\n    }\n    public function destroy(string $id): bool {\n        $this->acquire($id);$this->markRetired($id);\n        foreach([$this->path($id),$this->legacyPath($id)]as$path)if($this->plainFile($path)&&!unlink($path))throw new RuntimeException('Private session could not be retired.');\n        return true;\n    }\n    public function gc(int $max_lifetime): int|false {\n        $deleted=0;$cutoff=time()-$max_lifetime;\n        foreach(glob($this->root.'/*.json')?:[]as$path){\n            if(!$this->plainFile($path)||filemtime($path)>$cutoff)continue;\n            $lock=fopen($path.'.lock','c+b');if($lock===false)continue;chmod($path.'.lock',0600);\n            if(flock($lock,LOCK_EX|LOCK_NB)){\n                clearstatcache(true,$path);\n                if($this->plainFile($path)&&filemtime($path)<=$cutoff){$marker=substr($path,0,-5).'.retired';if(file_put_contents($marker,'retired',LOCK_EX)!==false){chmod($marker,0600);if(unlink($path))$deleted++;}}\n                flock($lock,LOCK_UN);\n            }\n            fclose($lock);\n        }\n        return $deleted;\n    }\n}\n")
    script.write_text(r'''<?php
require __DIR__.'/baseline.php';
require $argv[1].'/session_store.php';$dir=$argv[2].'/sessions';mkdir($dir,0700);
$store=new TTAtomicSessionStore($dir);$records=$dir.'/records';
function verify($ok,$why){if(!$ok)throw new RuntimeException($why);}
for($i=0;$i<2048;$i++){
 $id=$store->create_sid();verify(TTAtomicSessionStore::isNativeId($id),'native ID');
 $store->read($id);$store->write($id,serialize(['csrf'=>'fixture','counter'=>$i]));
 if($i%2===0)$store->destroy($id);$store->close();
}
verify(count(glob($records.'/.stripe-*.lock'))<=256,'bounded stripes');
verify(count(glob($records.'/*.json.lock'))===1024,'locks exist only for live native records');
verify(count(glob($records.'/*.retired'))===0,'no native tombstones');
foreach(glob($records.'/*.json')as$p)touch($p,time()-7200);
verify($store->gc(3600)===1024,'expired records collected');
verify(count(glob($records.'/*.json'))===0,'no expired native records');
verify(count(glob($records.'/*.json.lock'))===0,'no expired native locks');
verify(count(glob($records.'/*.retired'))===0,'GC did not create tombstones');
// Native identifiers must never import a recreated legacy record.
$id=$store->create_sid();file_put_contents($dir.'/sess_'.$id,'user_id|i:1;');
verify(!$store->validateId($id),'native legacy fallback disabled');
verify(unserialize($store->read($id))===[],'native read ignores legacy');$store->close();
// A previous-release worker reads and revokes the same native record.
$id=$store->create_sid();$store->write($id,serialize(['user_id'=>1]));$store->close();
$oldStore=new TTBaselineSessionStore($dir);
verify($oldStore->validateId($id),'old worker recognizes native session');
verify(unserialize($oldStore->read($id))['user_id']===1,'old worker continuity');
$oldStore->destroy($id);$oldStore->close();
verify(!$store->validateId($id),'old logout revokes new session');
verify(unserialize($store->read($id))===[],'replayed native cookie anonymous');$store->close();
$store->gc(3600);
verify(!is_file($records.'/'.hash('sha256',$id).'.json.lock'),'old logout metadata reclaimed');
// Holding the old per-ID descriptor still locks new acquisitions after unlink.
$id=$store->create_sid();$store->write($id,serialize(['user_id'=>1]));$store->close();
$path=$records.'/'.hash('sha256',$id).'.json';$held=fopen($path.'.lock','c+b');
$store->destroy($id);$store->close();$store->read($id);
$a=fstat($held);$b=stat($path.'.lock');verify($a['ino']===$b['ino']&&$a['dev']===$b['dev'],'stable old descriptor inode');
$store->close();fclose($held);$store->gc(3600);
// Old validation may precede new logout, then create a unique lock late.
$id=$store->create_sid();$store->write($id,serialize(['user_id'=>1]));$store->close();
verify($oldStore->validateId($id),'stale old validation');$store->destroy($id);$store->close();
verify(unserialize($oldStore->read($id))===[],'stale old request lost auth');
$oldStore->write($id,serialize(['csrf'=>'anonymous']));$oldStore->close();
verify(unserialize($store->read($id))===['csrf'=>'anonymous'],'late old lock remains usable anonymously');
$store->destroy($id);$store->close();
// Classic IDs retain their exact original lock and permanent retirement gate.
$old='legacy-retention';file_put_contents($dir.'/sess_'.$old,'counter|i:7;');
verify(unserialize($store->read($old))['counter']===7,'legacy continuity');
$store->destroy($old);$store->close();file_put_contents($dir.'/sess_'.$old,'counter|i:7;');
verify(!$store->validateId($old),'classic logout cannot resurrect');
verify(is_file($records.'/'.hash('sha256',$old).'.json.lock'),'classic lock stable');
// GC cannot remove an active record even when its file age is stale.
$id=$store->create_sid();$store->write($id,serialize(['counter'=>9]));
$path=$records.'/'.hash('sha256',$id).'.json';touch($path,time()-7200);
verify($store->gc(3600)===0&&is_file($path),'active stripe protects against GC');
$store->close();verify($store->gc(3600)===1,'collect after release');
echo "PASS 2048 native sessions, bounded locks, deterministic GC, legacy replay prevention and active-session protection\n";
''')
    result = subprocess.run(['php', str(script), str(ROOT), directory], capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr
    print(result.stdout.strip())
    parallel = pathlib.Path(directory) / 'parallel'
    parallel.mkdir()
    worker = pathlib.Path(directory) / 'worker.php'
    worker.write_text(r'''<?php
require $argv[1].'/session_store.php';$s=new TTAtomicSessionStore($argv[2]);
$v=unserialize($s->read($argv[3]));$n=$v['counter']??0;$v['counter']=$n+1;
$s->write($argv[3],serialize($v));$s->close();echo $n;
''')
    sid = 'tt2-' + 'a' * 64
    def increment(_):
        return int(subprocess.run(['php', str(worker), str(ROOT), str(parallel), sid],
                                  capture_output=True, text=True, check=True).stdout)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        values = list(pool.map(increment, range(64)))
    assert sorted(values) == list(range(64)), 'Native striped locking lost an update'
    print('PASS 64 concurrent native session increments without lost updates')
