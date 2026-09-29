<?php
declare(strict_types=1);

const TT_BACKUP_SCHEMA = 2;
const TT_BACKUP_APP_VERSION = 'Transtrade V1';
const TT_BACKUP_MAX_RESTORE_BYTES = 536870912; // 512 MB

final class TT_SimpleZipWriter {
    private $fh;
    private array $central = [];
    private int $offset = 0;
    private bool $closed = false;

    public function __construct(private string $path) {
        $this->fh = fopen($path, 'wb');
        if ($this->fh === false) throw new RuntimeException('Backup ZIP could not be created.');
    }

    private static function dosTime(?int $ts = null): array {
        $d = getdate($ts ?? time());
        $year = max(1980, min(2107, (int)$d['year']));
        $time = (($d['hours'] & 0x1f) << 11) | (($d['minutes'] & 0x3f) << 5) | ((int)($d['seconds'] / 2) & 0x1f);
        $date = ((($year - 1980) & 0x7f) << 9) | (($d['mon'] & 0xf) << 5) | ($d['mday'] & 0x1f);
        return [$time, $date];
    }

    private function write(string $data): void {
        $len = strlen($data);
        $written = fwrite($this->fh, $data);
        if ($written === false || $written !== $len) throw new RuntimeException('Backup ZIP could not be written.');
        $this->offset += $len;
    }

    public function addString(string $name, string $data, bool $compress = true): void {
        if ($this->closed) throw new RuntimeException('Backup ZIP is already closed.');
        $name = str_replace('\\', '/', ltrim($name, '/'));
        if ($name === '' || str_contains($name, '../')) throw new InvalidArgumentException('Unsafe backup entry name.');
        [$mtime, $mdate] = self::dosTime();
        $crc = crc32($data);
        $usize = strlen($data);
        $compressed = $compress && $usize > 0 ? gzdeflate($data, 6) : $data;
        if ($compressed === false || strlen($compressed) >= $usize) { $compressed = $data; $method = 0; }
        else $method = 8;
        $csize = strlen($compressed);
        $flags = 0x0800; // UTF-8 names
        $offset = $this->offset;
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $mtime, $mdate, $crc, $csize, $usize, strlen($name), 0);
        $this->write($local . $name . $compressed);
        $this->central[] = compact('name','flags','method','mtime','mdate','crc','csize','usize','offset');
    }

    public function addFile(string $name, string $path, bool $compress = true): array {
        if ($this->closed) throw new RuntimeException('Backup ZIP is already closed.');
        $name = str_replace('\\', '/', ltrim($name, '/'));
        if ($name === '' || str_contains($name, '../')) throw new InvalidArgumentException('Unsafe backup entry name.');
        $source = fopen($path, 'rb');
        if ($source === false) throw new RuntimeException('A backup source file could not be read.');
        [$mtime, $mdate] = self::dosTime(filemtime($path) ?: null);
        $flags = 0x0808; // UTF-8 name plus a data descriptor after streamed contents.
        $method = 0; // Documents are commonly pre-compressed; store them without buffering in PHP memory.
        $offset = $this->offset;
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $mtime, $mdate, 0, 0, 0, strlen($name), 0);
        $this->write($local . $name);
        $crcContext = hash_init('crc32b');
        $shaContext = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) throw new RuntimeException('A backup source file could not be read.');
                if ($chunk === '') continue;
                $size += strlen($chunk);
                if ($size > 0xffffffff) throw new RuntimeException('A backup source file is too large for this ZIP format.');
                hash_update($crcContext, $chunk);
                hash_update($shaContext, $chunk);
                $this->write($chunk);
            }
        } finally {
            fclose($source);
        }
        $crcHex = hash_final($crcContext);
        $sha256 = hash_final($shaContext);
        $crc = (int)hexdec($crcHex);
        $this->write(pack('VVVV', 0x08074b50, $crc, $size, $size));
        $this->central[] = ['name'=>$name,'flags'=>$flags,'method'=>$method,'mtime'=>$mtime,'mdate'=>$mdate,'crc'=>$crc,'csize'=>$size,'usize'=>$size,'offset'=>$offset];
        return ['size'=>$size, 'sha256'=>$sha256];
    }

    public function close(): void {
        if ($this->closed) return;
        $centralOffset = $this->offset;
        foreach ($this->central as $e) {
            $header = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $e['flags'], $e['method'], $e['mtime'], $e['mdate'], $e['crc'], $e['csize'], $e['usize'], strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']);
            $this->write($header . $e['name']);
        }
        $centralSize = $this->offset - $centralOffset;
        $count = count($this->central);
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
        fflush($this->fh);
        fclose($this->fh);
        $this->closed = true;
    }

    public function __destruct() { if (!$this->closed && is_resource($this->fh)) { try { $this->close(); } catch (Throwable) {} } }
}

final class TT_SimpleZipReader {
    private $fh;
    private array $entries = [];

    public function __construct(private string $path, private int $maxBytes = TT_BACKUP_MAX_RESTORE_BYTES) {
        $this->fh = fopen($path, 'rb');
        if ($this->fh === false) throw new InvalidArgumentException('The selected file is not readable.');
        $this->parse();
    }

    private function readExact(int $length): string {
        $data = '';
        while (strlen($data) < $length && !feof($this->fh)) {
            $chunk = fread($this->fh, $length - strlen($data));
            if ($chunk === false) throw new InvalidArgumentException('The backup ZIP is incomplete.');
            $data .= $chunk;
        }
        if (strlen($data) !== $length) throw new InvalidArgumentException('The backup ZIP is incomplete.');
        return $data;
    }

    private function parse(): void {
        $size = filesize($this->path);
        if ($size === false || $size < 22 || $size > $this->maxBytes) throw new InvalidArgumentException('The selected backup has an invalid size.');
        $tailSize = min($size, 66000);
        fseek($this->fh, $size - $tailSize);
        $tail = $this->readExact($tailSize);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || $pos + 22 > strlen($tail)) throw new InvalidArgumentException('The selected file is not a valid Transtrade ZIP backup.');
        $eocd = unpack('vdisk/vstart/ventriesDisk/ventries/Vcsize/Vcoffset/vcomment', substr($tail, $pos + 4, 18));
        if (!$eocd || (int)$eocd['disk'] !== 0 || (int)$eocd['start'] !== 0) throw new InvalidArgumentException('Multi-part ZIP backups are not supported.');
        fseek($this->fh, (int)$eocd['coffset']);
        for ($i = 0; $i < (int)$eocd['entries']; $i++) {
            $head = $this->readExact(46);
            if (substr($head, 0, 4) !== "PK\x01\x02") throw new InvalidArgumentException('The backup ZIP directory is invalid.');
            $u = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vinternal/Vexternal/Voffset', substr($head, 4));
            $name = $this->readExact((int)$u['nlen']);
            if ((int)$u['elen'] > 0) $this->readExact((int)$u['elen']);
            if ((int)$u['clen'] > 0) $this->readExact((int)$u['clen']);
            if ($name === '' || str_contains($name, '../')) throw new InvalidArgumentException('The backup ZIP contains an unsafe path.');
            $this->entries[$name] = $u + ['name'=>$name];
        }
    }

    public function names(): array { return array_keys($this->entries); }
    public function has(string $name): bool { return isset($this->entries[$name]); }

    /** Verify a stored entry with bounded memory, including the ZIP CRC. */
    public function storedDigest(string $name, mixed $destination = null): array|false {
        $e = $this->entries[$name] ?? null;
        if (!$e) return false;
        if ((int)$e['method'] !== 0) throw new InvalidArgumentException('A server snapshot entry is not stored in the supported format.');
        fseek($this->fh, (int)$e['offset']);
        $local = $this->readExact(30);
        if (substr($local, 0, 4) !== "PK\x03\x04") throw new InvalidArgumentException('The backup ZIP entry is invalid.');
        $u = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($local, 4));
        $localName = $this->readExact((int)$u['nlen']);
        if ($localName !== $name || (int)$u['method'] !== 0) throw new InvalidArgumentException('The backup ZIP entry name or format is invalid.');
        if ((int)$u['elen'] > 0) $this->readExact((int)$u['elen']);
        $sha = hash_init('sha256'); $crc = hash_init('crc32b');
        $remaining = (int)$e['csize'];
        while ($remaining > 0) {
            $chunk = $this->readExact(min($remaining, 1024 * 1024));
            hash_update($sha, $chunk); hash_update($crc, $chunk);
            if ($destination !== null && fwrite($destination, $chunk) !== strlen($chunk)) throw new RuntimeException('A recovery file could not be staged.');
            $remaining -= strlen($chunk);
        }
        if ((int)$e['csize'] !== (int)$e['usize'] || sprintf('%u', hexdec(hash_final($crc))) !== sprintf('%u', (int)$e['crc'])) throw new InvalidArgumentException('A backup entry failed its ZIP integrity check.');
        return ['size'=>(int)$e['usize'], 'sha256'=>hash_final($sha)];
    }

    public function get(string $name): string|false {
        $e = $this->entries[$name] ?? null;
        if (!$e) return false;
        fseek($this->fh, (int)$e['offset']);
        $local = $this->readExact(30);
        if (substr($local, 0, 4) !== "PK\x03\x04") throw new InvalidArgumentException('The backup ZIP entry is invalid.');
        $u = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($local, 4));
        if ((int)$u['nlen'] > 0) $this->readExact((int)$u['nlen']);
        if ((int)$u['elen'] > 0) $this->readExact((int)$u['elen']);
        $compressed = $this->readExact((int)$e['csize']);
        if ((int)$e['method'] === 0) $data = $compressed;
        elseif ((int)$e['method'] === 8) { $data = gzinflate($compressed); if ($data === false) throw new InvalidArgumentException('A compressed backup entry is damaged.'); }
        else throw new InvalidArgumentException('This ZIP compression method is not supported.');
        if (strlen($data) !== (int)$e['usize'] || sprintf('%u', crc32($data)) !== sprintf('%u', (int)$e['crc'])) throw new InvalidArgumentException('A backup entry failed its ZIP integrity check.');
        return $data;
    }

    public function close(): void { if (is_resource($this->fh)) fclose($this->fh); }
    public function __destruct() { $this->close(); }
}

function tt_backup_dir(): string { return TT_DATA_DIR . '/backups'; }
function tt_backup_state_file(): string { return TT_DATA_DIR . '/backup_state.json'; }
function tt_backup_state_lock_file(): string { return TT_DATA_DIR . '/backup-state.lock'; }
function tt_backup_job_lock_file(): string { return TT_DATA_DIR . '/backup-job.lock'; }
function tt_operations_file(): string { return TT_DATA_DIR . '/operations.json'; }

function tt_backup_ensure_dirs(): void {
    tt_ensure_data_dir();
    $dir = tt_backup_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('The private backup folder could not be created.');
}

function tt_backup_require_space(string $directory, int $estimatedBytes): void {
    $free = disk_free_space($directory);
    if ($free === false || $free < max(64 * 1024 * 1024, $estimatedBytes + 32 * 1024 * 1024)) {
        throw new RuntimeException('Insufficient available disk space for a verified backup.');
    }
}

function tt_backup_read_state_unlocked(): array {
    $path = tt_backup_state_file();
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function tt_backup_read_state(): array {
    tt_ensure_data_dir();
    $lock = fopen(tt_backup_state_lock_file(), 'c+');
    if ($lock === false || !flock($lock, LOCK_SH)) throw new RuntimeException('Backup status is unavailable.');
    try { return tt_backup_read_state_unlocked(); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

function tt_backup_write_state(array $patch): void {
    tt_ensure_data_dir();
    $lock = fopen(tt_backup_state_lock_file(), 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Backup status is unavailable.');
    $tmp = '';
    try {
        $state = array_merge(tt_backup_read_state_unlocked(), $patch);
        foreach ($state as $key => $value) if ($value === null) unset($state[$key]);
        $tmp = tt_backup_state_file() . '.tmp-' . bin2hex(random_bytes(4));
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $handle = fopen($tmp, 'x+b');
        if ($handle === false) throw new RuntimeException('Backup status could not be prepared.');
        try {
            @chmod($tmp, 0600);
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new RuntimeException('Backup status could not be saved.');
            if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Backup status could not be synchronized.');
        } finally { fclose($handle); }
        if (!rename($tmp, tt_backup_state_file())) throw new RuntimeException('Backup status could not be saved.');
        $tmp = '';
    } finally {
        if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function tt_backup_private_files(): array {
    tt_ensure_data_dir();
    $files = [];
    $root = rtrim(TT_DATA_DIR, '/\\');
    if (!is_dir($root)) return $files;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $path = $file->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        if ($rel === '' || str_starts_with($rel, 'backups/') || $rel === 'backup_state.json' || str_ends_with($rel, '.lock') || str_contains($rel, '.tmp-') || str_contains($rel, '.restore-')) continue;
        $files[$rel] = $path;
    }
    ksort($files);
    return $files;
}


function tt_backup_app_files(): array {
    $root = rtrim(__DIR__, '/\\');
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $path = $file->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        if ($rel === '' || str_starts_with($rel, '.git/') || str_starts_with($rel, 'node_modules/') || preg_match('~(^|/)(cache|tmp)/~i', $rel) || preg_match('/\.(log|tmp)$/i', $rel)) continue;
        $files[$rel] = $path;
    }
    ksort($files);
    return $files;
}

function tt_backup_read_operations(): array {
    $path = tt_operations_file();
    if (!is_file($path)) return ['revision'=>0,'values'=>[],'meta'=>[]];
    $raw = file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? array_merge(['revision'=>0,'values'=>[],'meta'=>[]], $data) : ['revision'=>0,'values'=>[],'meta'=>[]];
}

function tt_backup_flatten(array $row, string $prefix = '', int $depth = 0): array {
    $out = [];
    foreach ($row as $key => $value) {
        $name = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_scalar($value) || $value === null) $out[$name] = $value === null ? '' : (string)$value;
        elseif (is_array($value) && $depth < 1 && !array_is_list($value)) $out += tt_backup_flatten($value, $name, $depth + 1);
        else $out[$name] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return $out;
}

function tt_backup_table_from_rows(array $rows): array {
    if (!$rows) return ['headers'=>['Value'], 'rows'=>[]];
    $flat = []; $headers = [];
    foreach ($rows as $row) {
        if (!is_array($row)) $row = ['Value'=>$row];
        $r = tt_backup_flatten($row); $flat[] = $r;
        foreach (array_keys($r) as $h) if (!in_array($h, $headers, true)) $headers[] = $h;
    }
    return ['headers'=>$headers, 'rows'=>array_map(static fn(array $r): array => array_map(static fn(string $h): string => (string)($r[$h] ?? ''), $headers), $flat)];
}

function tt_backup_master_headers(string $type, int $width): array {
    $known = [
        'companies'=>['Legal company name','Short code','Country','Entity scope','Company roles','TG special handling','System behaviour / notes','Owners / partners','KCCI membership no.','REAP membership no.','NTN number','Sales tax number','Company number','Bank accounts','Document identities'],
        'commodities'=>['Commodity','Code','Base unit','Soda / contract enabled','Quality / specification profile','KAT / deduction profile','Default accounting mapping','Staff instruction / message'],
        'product_settings'=>['Current crop year'],
        'products'=>['Commodity','Variety','Rice type','Code','Origin','Profile / use','Avg. grain length','Broken','Moisture','Damaged / Shriveled / Yellow','Chalky / Immature','Contrasting / Other varieties','Foreign grains','Foreign matter','Paddy','Red kernels / Red rice','Under-milled / Red-striped','Finish','Additional quality wording','Source / basis','Custom specifications','HS Code'],
        'parties'=>['Party','Code / reference','Type / notes'],
        'mills'=>['Mill / location','Code / reference','Location type','Full address','Contact details','Status','Notes'],
        'banks'=>['Account type','Company','Label','Account title','Bank name','Branch','Country','Currency','Account number','IBAN','SWIFT','Purpose','Visibility','Status'],
        'salary_staff'=>['Staff / person name','Legal book','Salary group','Net salary / remuneration (Rs)','Zakat (Rs)','Other recurring allowances (Rs)','Effective from','Effective to','Accounts treatment','Include in Mill production cost','Status','Notes'],
        'purchase_products'=>['Commodity','Base variety / product','Rice type','Purchase classification','Purchase unit','Arrival KAT profile (our location only)','Legacy brokery rule','Legacy inventory account','Status','Notes','Broken grade (optional)'],
        'purchase_kat'=>['Commodity','Base variety','Rice type','Purchase classification','Profile name','Effective from','Effective to','Status','Notes','Quality parameters'],
        'export_documents'=>['Document Name','Original','Copies','Applies To','Status'],
        'export_terms'=>['Payment Group','Term Text','Status'],
        'export_realization_charges'=>['Charge / tax name','Code','Category','Tax regime / context','Calculation base','Rate % / formula','Effective from','Effective to','Accounting treatment','GL account code','Bank certificate required','Applies to entities','Status','Notes','Deduction nature / tax character'],
        'export_customers'=>['Customer name','Code','Roles','Primary document address','Country','Email','Phone','Tax / registration','Packing default','Notify parties JSON','Status','Notes','Show country','Show email','Show phone','Show tax','Contacts JSON','Consignees JSON','Additional notify parties JSON','Additional document addresses JSON','Default currency','Default payment / customer instructions'],
        'business_parties'=>['Party name','Code / reference','Categories','Address','Country','Contact person','Phone','Email','NTN / tax number','Payment terms','Status','Notes','Brokery profile'],
        'reference_lists'=>['List','Option / currency code','Currency full name'],
        'tg_currency_rates'=>['Rate set name','Code','Currency pair','Sell USD → Receive AED','Buy USD ← Pay AED','Final Accounts / Tax Rate','Effective from','Effective to','TG year-end (MM-DD)','Closing / reporting currency','Status','Notes'],
        'tg_compliance_documents'=>['Document type','Holder','Document number','Issue date','Expiry date','Status','Stored file','Original file','MIME type','Notes','Created at','Created by','Renewal of'],
    ];
    $headers = $known[$type] ?? [];
    if ($width === 0) return $headers ?: ['Value'];
    while (count($headers) < $width) $headers[] = 'Field ' . (count($headers) + 1);
    return array_slice($headers, 0, $width);
}

function tt_backup_datasets(): array {
    $store = tt_read_store(); $operations = tt_backup_read_operations(); $datasets = [];
    $datasets['Summary'] = ['headers'=>['Item','Value'], 'rows'=>[
        ['Backup created (UTC)', gmdate('c')], ['Application','Transtrade'], ['Purpose','Human-readable owner data export'],
        ['Operational revision',(string)($operations['revision'] ?? 0)], ['Users',(string)count((array)($store['users'] ?? []))], ['Audit records',(string)count((array)($store['audit'] ?? []))],
    ]];
    $userRows=[];
    foreach ((array)($store['users'] ?? []) as $u) {
        $userRows[]=['ID'=>(string)($u['id']??''),'Full name'=>(string)($u['full_name']??''),'Username'=>(string)($u['username']??''),'Role'=>(string)($u['role']??''),'Location'=>(string)($u['location']??''),'Active'=>!empty($u['active'])?'Yes':'No','Permissions'=>json_encode($u['permissions']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'Created at'=>(string)($u['created_at']??''),'Last login'=>(string)($u['last_login_at']??''),'Must change password'=>!empty($u['must_change_password'])?'Yes':'No'];
    }
    $datasets['Users']=tt_backup_table_from_rows($userRows);
    $datasets['Audit Trail']=tt_backup_table_from_rows((array)($store['audit']??[]));
    foreach ((array)($store['masters']??[]) as $type=>$rows) {
        $max=0; foreach((array)$rows as $row)$max=max($max,count((array)($row['values']??[])));
        $headers=tt_backup_master_headers((string)$type,$max);$tableRows=[];
        foreach((array)$rows as $row){$vals=array_values((array)($row['values']??[]));while(count($vals)<$max)$vals[]='';$tableRows[]=array_map(static fn($v):string=>is_scalar($v)||$v===null?(string)$v:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$vals);}
        $datasets['Master '.ucwords(str_replace('_',' ',(string)$type))]=['headers'=>$headers,'rows'=>$tableRows];
    }
    $indexRows=[];
    foreach ((array)($operations['values']??[]) as $key=>$raw) {
        $decoded=is_string($raw)?json_decode($raw,true):$raw;$meta=(array)(($operations['meta']??[])[$key]??[]);
        $indexRows[]=[(string)$key,(string)($meta['updatedAt']??''),(string)($meta['updatedBy']??''),is_array($decoded)?(array_is_list($decoded)?'List':'Object'):gettype($decoded)];
        $base=preg_replace('/^transtrade_|_operational$/','',(string)$key) ?: (string)$key;
        if(is_array($decoded)&&array_is_list($decoded))$datasets['Ops '.$base]=tt_backup_table_from_rows($decoded);
        elseif(is_array($decoded)){
            $created=false;foreach($decoded as $sub=>$value){if(is_array($value)&&array_is_list($value)&&$value){$datasets['Ops '.$base.' '.$sub]=tt_backup_table_from_rows($value);$created=true;}}
            if(!$created){$rows=[];foreach($decoded as $sub=>$value)$rows[]=['Field'=>(string)$sub,'Value'=>is_scalar($value)||$value===null?(string)$value:json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)];$datasets['Ops '.$base]=tt_backup_table_from_rows($rows);}
        } else $datasets['Ops '.$base]=['headers'=>['Value'],'rows'=>[[is_scalar($decoded)||$decoded===null?(string)$decoded:(string)$raw]]];
    }
    $datasets['Operational Index']=['headers'=>['Storage key','Updated at','Updated by','Data shape'],'rows'=>$indexRows];
    return $datasets;
}

function tt_backup_xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function tt_backup_safe_cell(string $value): string {
    // A leading quote is displayed by Excel as text; guard whitespace-prefixed formulas too.
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
}
function tt_backup_sheet_name(string $name, array &$used): string {
    $name=preg_replace('~[\\/?:*\[\]]~u',' ',$name) ?: 'Sheet'; $name=trim(preg_replace('/\s+/u',' ',$name) ?: 'Sheet');
    $substr=function(string $s,int $start,int $len):string{return function_exists('mb_substr')?mb_substr($s,$start,$len):substr($s,$start,$len);};
    $lower=function(string $s):string{return function_exists('mb_strtolower')?mb_strtolower($s):strtolower($s);};
    $strlen=function(string $s):int{return function_exists('mb_strlen')?mb_strlen($s):strlen($s);};
    $base=$substr($name,0,31);$candidate=$base;$i=2;while(isset($used[$lower($candidate)])){$suffix=' '.$i++;$candidate=$substr($base,0,31-$strlen($suffix)).$suffix;}$used[$lower($candidate)]=true;return $candidate;
}
function tt_backup_col(int $n): string { $s='';while($n>0){$n--;$s=chr(65+($n%26)).$s;$n=intdiv($n,26);}return $s; }

function tt_backup_make_xlsx(array $datasets): string {
    $tmp=tempnam(sys_get_temp_dir(),'tt-xlsx-');if($tmp===false)throw new RuntimeException('Temporary Excel file could not be created.');
    $zip=new TT_SimpleZipWriter($tmp);$used=[];$sheets=[];$rels=[];$content=[];$index=1;
    foreach($datasets as $title=>$table){$name=tt_backup_sheet_name((string)$title,$used);$rows=[(array)($table['headers']??[])];foreach((array)($table['rows']??[]) as $r)$rows[]=(array)$r;
        $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><sheetData>';
        foreach($rows as $ri=>$row){$rnum=$ri+1;$xml.='<row r="'.$rnum.'">';foreach(array_values($row) as $ci=>$value){$cell=tt_backup_col($ci+1).$rnum;$style=$ri===0?' s="1"':'';$xml.='<c r="'.$cell.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.tt_backup_xml(tt_backup_safe_cell((string)$value)).'</t></is></c>';}$xml.='</row>';}
        $xml.='</sheetData><autoFilter ref="A1:'.tt_backup_col(max(1,count($rows[0]??[]))).'1"/></worksheet>';
        $zip->addString('xl/worksheets/sheet'.$index.'.xml',$xml);$sheets[]='<sheet name="'.tt_backup_xml($name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';$rels[]='<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';$content[]='<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';$index++;}
    $zip->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'.implode('',$content).'</Types>');
    $zip->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('',$sheets).'</sheets></workbook>');
    $rels[]='<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';$zip->addString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('',$rels).'</Relationships>');
    $zip->addString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $now=gmdate('Y-m-d\TH:i:s\Z');$zip->addString('docProps/core.xml','<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Transtrade Business Data</dc:title><dc:creator>Transtrade</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created></cp:coreProperties>');$zip->addString('docProps/app.xml','<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Transtrade</Application></Properties>');$zip->close();return $tmp;
}

function tt_backup_csv(array $table): string {$h=fopen('php://temp','w+');if($h===false)return '';fputcsv($h,(array)($table['headers']??[]));foreach((array)($table['rows']??[]) as $row)fputcsv($h,array_map(static fn($v):string=>tt_backup_safe_cell((string)$v),(array)$row));rewind($h);$data=stream_get_contents($h)?:'';fclose($h);return "\xEF\xBB\xBF".$data;}
function tt_backup_make_docx(array $summaryLines): string {$tmp=tempnam(sys_get_temp_dir(),'tt-docx-');if($tmp===false)throw new RuntimeException('Temporary Word file could not be created.');$zip=new TT_SimpleZipWriter($tmp);$paras=[];foreach($summaryLines as $i=>$line){$bold=$i===0?'<w:rPr><w:b/><w:sz w:val="32"/></w:rPr>':'';$paras[]='<w:p><w:r>'.$bold.'<w:t xml:space="preserve">'.tt_backup_xml((string)$line).'</w:t></w:r></w:p>';}$zip->addString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');$zip->addString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');$zip->addString('word/document.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.implode('',$paras).'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>');$zip->close();return $tmp;}
function tt_backup_pdf_escape(string $s): string {return str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$s);}
function tt_backup_make_pdf(array $lines): string {$content="BT\n/F1 16 Tf\n50 790 Td\n";$first=true;foreach($lines as $line){foreach(preg_split('/\n/',wordwrap((string)$line,88,"\n",true)) as $part){if(!$first)$content.="0 -18 Td\n";$content.='('.tt_backup_pdf_escape($part).") Tj\n";$first=false;}}$content.="ET";$objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($content).' >>'."\nstream\n".$content."\nendstream"];$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$obj){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);$pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";$tmp=tempnam(sys_get_temp_dir(),'tt-pdf-');if($tmp===false)throw new RuntimeException('Temporary PDF could not be created.');file_put_contents($tmp,$pdf);return $tmp;}
function tt_backup_summary_lines(array $datasets): array {$lines=['Transtrade Backup Summary','Created: '.gmdate('d M Y H:i').' UTC','This package contains readable owner data plus original stored documents where available.',''];foreach($datasets as $name=>$table)$lines[]=$name.': '.count((array)($table['rows']??[])).' data rows';$lines[]='';$lines[]='The Complete Recovery Backup additionally contains encrypted raw system recovery files. Keep it confidential and retain its password separately.';return $lines;}
function tt_backup_safe_name(string $name): string {$name=preg_replace('/[^A-Za-z0-9._ -]+/u','_',trim($name))?:'data';return trim($name,' ._')?:'data';}

function tt_backup_business_payloads(): array {
    $datasets=tt_backup_datasets();$payloads=[];$temps=[];$xlsx=tt_backup_make_xlsx($datasets);$temps[]=$xlsx;$payloads['Business_Data/Transtrade_Business_Data.xlsx']=file_get_contents($xlsx)?:'';
    foreach($datasets as $name=>$table)$payloads['Business_Data/CSV/'.tt_backup_safe_name((string)$name).'.csv']=tt_backup_csv($table);
    $lines=tt_backup_summary_lines($datasets);$docx=tt_backup_make_docx($lines);$temps[]=$docx;$payloads['Business_Data/Transtrade_Backup_Summary.docx']=file_get_contents($docx)?:'';$pdf=tt_backup_make_pdf($lines);$temps[]=$pdf;$payloads['Business_Data/Transtrade_Backup_Summary.pdf']=file_get_contents($pdf)?:'';
    foreach($temps as $t)@unlink($t);
    return [$payloads,$datasets];
}

function tt_backup_manifest(string $type,array $files,array $extra=[]):array{$inventory=[];foreach($files as $rel=>$path)$inventory[]=['path'=>$rel,'size'=>is_file($path)?filesize($path):0,'sha256'=>is_file($path)?hash_file('sha256',$path):''];return array_merge(['schema'=>TT_BACKUP_SCHEMA,'application'=>'Transtrade','appVersion'=>TT_BACKUP_APP_VERSION,'type'=>$type,'createdAt'=>gmdate('c'),'recoveryFileCount'=>count($inventory),'recoveryFiles'=>$inventory],$extra);}
function tt_backup_derive_key(string $password,string $salt,int $iterations):string{if(strlen($password)<12)throw new InvalidArgumentException('Recovery ZIP password must contain at least 12 characters.');return hash_pbkdf2('sha256',$password,$salt,$iterations,32,true);}
function tt_backup_encrypt(string $plain,string $key):array{if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL encryption is not available on this server.');$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'TRANSTRADE-BACKUP');if($cipher===false)throw new RuntimeException('Recovery data could not be encrypted.');return ['cipher'=>$cipher,'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),'sha256'=>hash('sha256',$plain),'size'=>strlen($plain)];}
function tt_backup_decrypt(string $cipher,array $meta,string $key):string{if(!function_exists('openssl_decrypt'))throw new RuntimeException('OpenSSL decryption is not available on this server.');$plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,base64_decode((string)$meta['nonce'],true)?:'',base64_decode((string)$meta['tag'],true)?:'','TRANSTRADE-BACKUP');if($plain===false||!hash_equals((string)($meta['sha256']??''),hash('sha256',$plain)))throw new InvalidArgumentException('The backup password is incorrect or an encrypted file failed integrity verification.');return $plain;}
function tt_backup_decrypt_entry(TT_SimpleZipReader $zip,array $meta,string $key,mixed $destination):void{
    $digest=hash_init('sha256');$size=0;
    foreach(isset($meta['chunks']) ? (array)$meta['chunks'] : [$meta] as $chunk){
        $cipher=$zip->get((string)($chunk['stored']??''));if($cipher===false)throw new InvalidArgumentException('An encrypted recovery entry is missing.');
        $plain=tt_backup_decrypt($cipher,(array)$chunk,$key);$size+=strlen($plain);hash_update($digest,$plain);
        if(fwrite($destination,$plain)!==strlen($plain))throw new RuntimeException('A recovery file could not be staged.');
    }
    if(isset($meta['chunks'])&&($size!==(int)($meta['size']??-1)||!hash_equals((string)($meta['sha256']??''),hash_final($digest))))throw new InvalidArgumentException('An encrypted recovery file failed its integrity check.');
}

function tt_build_download_backup(bool $full,string $password=''):array{
    [$business,$datasets]=tt_backup_business_payloads();$private=tt_backup_private_files();$appFiles=$full?tt_backup_app_files():[];$documents=[];
    foreach($private as $rel=>$path)if(preg_match('#^(documents|uploads)/(.*)$#i',$rel,$m))$documents['Documents/'.$m[2]]=$path;
    $estimate=0;foreach($private as $path)$estimate+=filesize($path)?:0;foreach($appFiles as $path)$estimate+=filesize($path)?:0;
    $tmp=tempnam(sys_get_temp_dir(),'tt-backup-');if($tmp===false)throw new RuntimeException('Temporary backup file could not be created.');
    try { tt_backup_require_space(dirname($tmp),$full ? $estimate*2 : array_sum(array_map('filesize',$documents))); }
    catch(Throwable $error) { @unlink($tmp); throw $error; }
    $zip=new TT_SimpleZipWriter($tmp);
    if(!$full){foreach($business as $entry=>$data)$zip->addString($entry,$data);foreach($documents as $entry=>$path)$zip->addFile($entry,$path);$manifest=tt_backup_manifest('business_data',[],['businessSheets'=>count($datasets),'containsTechnicalSecrets'=>false]);$zip->addString('backup-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$zip->close();return ['path'=>$tmp,'manifest'=>$manifest];}
    $salt=random_bytes(16);$iterations=200000;$key=tt_backup_derive_key($password,$salt,$iterations);$map=[];$counter=1;
    $addEncrypted=function(string $logical,string $data)use($zip,$key,&$map,&$counter):void{$enc=tt_backup_encrypt($data,$key);$stored='Encrypted/'.str_pad((string)$counter++,6,'0',STR_PAD_LEFT).'.bin';$zip->addString($stored,$enc['cipher']);unset($enc['cipher']);$enc['stored']=$stored;$map[$logical]=$enc;};
    $addEncryptedFile=function(string $logical,string $path)use($zip,$key,&$map,&$counter):void{
        $source=fopen($path,'rb');if($source===false)throw new RuntimeException('A recovery source file could not be read.');
        $chunks=[];$digest=hash_init('sha256');$size=0;
        try {
            do {
                $plain=fread($source,1024*1024);
                if($plain===false)throw new RuntimeException('A recovery source file could not be read.');
                $size+=strlen($plain);hash_update($digest,$plain);
                $enc=tt_backup_encrypt($plain,$key);$stored='Encrypted/'.str_pad((string)$counter++,6,'0',STR_PAD_LEFT).'.bin';
                $zip->addString($stored,$enc['cipher'],false);unset($enc['cipher']);$enc['stored']=$stored;$chunks[]=$enc;
            } while(!feof($source));
        } finally { fclose($source); }
        $map[$logical]=['size'=>$size,'sha256'=>hash_final($digest),'chunks'=>$chunks];
    };
    foreach($business as $entry=>$data)$addEncrypted($entry,$data);
    foreach($documents as $entry=>$path)$addEncryptedFile($entry,$path);
    foreach($private as $rel=>$path)$addEncryptedFile('System_Recovery/private/'.$rel,$path);
    foreach($appFiles as $rel=>$path)$addEncryptedFile('Application_Code/'.$rel,$path);
    $appInventory=[];foreach($appFiles as $rel=>$path)$appInventory[]=['path'=>$rel,'size'=>filesize($path)?:0,'sha256'=>hash_file('sha256',$path)?:''];
    $manifest=tt_backup_manifest('full_recovery',$private,['businessSheets'=>count($datasets),'containsTechnicalSecrets'=>true,'appFileCount'=>count($appFiles),'applicationFiles'=>$appInventory,'encryption'=>['algorithm'=>'AES-256-GCM','kdf'=>'PBKDF2-HMAC-SHA256','iterations'=>$iterations,'salt'=>base64_encode($salt)],'encryptedEntries'=>$map]);
    $zip->addString('README.txt',"TRANSTRADE COMPLETE RECOVERY BACKUP\n\nSensitive contents are encrypted with the password chosen by the owner. Restore through Transtrade Super Admin > Backup & Data Export.\nThe password is not stored in this ZIP.\n");$zip->addString('backup-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$zip->close();return ['path'=>$tmp,'manifest'=>$manifest];
}

function tt_backup_verify_snapshot(string $path, array $expectedInventory): void {
    $reader = new TT_SimpleZipReader($path, 0xffffffff);
    try {
        $manifestRaw = $reader->get('backup-manifest.json');
        $manifest = $manifestRaw === false ? null : json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['type'] ?? '') !== 'server_snapshot') throw new RuntimeException('The server snapshot manifest is invalid.');
        $actual = [];
        foreach ((array)($manifest['recoveryFiles'] ?? []) as $entry) {
            $logical = (string)($entry['path'] ?? '');
            if ($logical === '') throw new RuntimeException('The server snapshot inventory is invalid.');
            $digest = $reader->storedDigest('System_Recovery/private/' . $logical);
            if ($digest === false) throw new RuntimeException('The server snapshot is incomplete.');
            if ((int)($entry['size'] ?? -1) !== $digest['size'] || !hash_equals((string)($entry['sha256'] ?? ''), $digest['sha256'])) throw new RuntimeException('A server snapshot entry failed integrity verification.');
            $actual[$logical] = $digest;
        }
        if ($actual !== $expectedInventory) throw new RuntimeException('The server snapshot inventory changed while it was being created.');
    } finally { $reader->close(); }
}

function tt_create_server_snapshot(string $kind='auto'): string {
    tt_backup_ensure_dirs();
    $jobLock = fopen(tt_backup_job_lock_file(), 'c+');
    if ($jobLock === false || !flock($jobLock, LOCK_EX)) throw new RuntimeException('The backup service is busy.');
    $temp = '';
    $authLock = null;
    $operationsLock = null;
    try {
        if ($kind === 'auto') {
            $state = tt_backup_read_state();
            $last = strtotime((string)($state['last_auto_at'] ?? '')) ?: 0;
            if (time() - $last < 3600 && !empty($state['last_snapshot'])) return (string)$state['last_snapshot'];
        }
        $authLock = tt_open_store_lock(LOCK_SH);
        if (is_file(TT_STORE_FILE)) {
            $raw = file_get_contents(TT_STORE_FILE);
            if ($raw === false) throw new RuntimeException('Secure storage could not be read for backup.');
            tt_decode_store($raw);
        }
        $operationsPath = tt_operations_file();
        if (is_file($operationsPath)) {
            $operationsLock = fopen($operationsPath, 'rb');
            if ($operationsLock === false || !flock($operationsLock, LOCK_SH)) throw new RuntimeException('Shared operational storage could not be locked for backup.');
            $raw = stream_get_contents($operationsLock);
            if ($raw === false || ($raw !== '' && !is_array(json_decode($raw, true)))) throw new RuntimeException('Shared operational storage is invalid and was not backed up.');
        }
        $stamp = gmdate('Ymd-His');
        $name = tt_backup_safe_name($kind) . '-' . $stamp . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.zip';
        $path = tt_backup_dir() . '/' . $name;
        $temp = tt_backup_dir() . '/.snapshot-' . bin2hex(random_bytes(8)) . '.tmp';
        $sources = tt_backup_private_files();
        $estimate = 0;
        foreach ($sources as $file) $estimate += filesize($file) ?: 0;
        tt_backup_require_space(tt_backup_dir(), $estimate);
        $zip = new TT_SimpleZipWriter($temp);
        $inventory = [];
        foreach ($sources as $rel => $file) {
            $meta = $zip->addFile('System_Recovery/private/' . $rel, $file, false);
            $inventory[$rel] = $meta;
        }
        ksort($inventory);
        $manifest = [
            'schema'=>TT_BACKUP_SCHEMA,
            'application'=>'Transtrade',
            'appVersion'=>TT_BACKUP_APP_VERSION,
            'type'=>'server_snapshot',
            'createdAt'=>gmdate('c'),
            'recoveryFileCount'=>count($inventory),
            'recoveryFiles'=>array_map(static fn(string $rel, array $meta): array => ['path'=>$rel] + $meta, array_keys($inventory), array_values($inventory)),
            'snapshotKind'=>$kind,
        ];
        $zip->addString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $zip->close();
        @chmod($temp, 0600);
        tt_backup_verify_snapshot($temp, $inventory);
        if (!rename($temp, $path)) throw new RuntimeException('The verified server snapshot could not be committed.');
        $temp = '';
        @chmod($path, 0600);
        $now = gmdate('c');
        $statePatch = ['last_snapshot'=>$name, 'last_success_at'=>$now, 'last_error_at'=>null, 'last_error'=>null, 'consecutive_failures'=>0];
        if ($kind === 'auto') $statePatch['last_auto_at'] = $now;
        elseif ($kind === 'manual') $statePatch['last_manual_at'] = $now;
        elseif ($kind === 'pre-restore') $statePatch['last_pre_restore_at'] = $now;
        tt_backup_write_state($statePatch);
        tt_backup_rotate();
        return $name;
    } catch (Throwable $error) {
        if ($temp !== '' && is_file($temp)) @unlink($temp);
        try {
            $state = tt_backup_read_state();
            tt_backup_write_state([
                'last_error_at'=>gmdate('c'),
                'last_error'=>'A server snapshot could not be completed or verified.',
                'consecutive_failures'=>(int)($state['consecutive_failures'] ?? 0) + 1,
            ]);
        } catch (Throwable) {}
        throw $error;
    } finally {
        if (is_resource($operationsLock)) { flock($operationsLock, LOCK_UN); fclose($operationsLock); }
        if (is_resource($authLock)) { flock($authLock, LOCK_UN); fclose($authLock); }
        flock($jobLock, LOCK_UN);
        fclose($jobLock);
    }
}

function tt_backup_rotate(): void {
    $now = time();
    $auto = glob(tt_backup_dir() . '/auto-*.zip') ?: [];
    rsort($auto, SORT_STRING);
    $keep = []; $days = []; $months = [];
    foreach ($auto as $path) {
        $mtime = filemtime($path) ?: $now; $age = $now - $mtime;
        if ($age <= 48 * 3600) { $keep[$path] = true; continue; }
        if ($age <= 30 * 86400) { $key=gmdate('Y-m-d',$mtime); if (!isset($days[$key])) { $days[$key]=true; $keep[$path]=true; } continue; }
        if ($age <= 366 * 86400) { $key=gmdate('Y-m',$mtime); if (!isset($months[$key])) { $months[$key]=true; $keep[$path]=true; } }
    }
    foreach ($auto as $path) if (!isset($keep[$path]) && !@unlink($path)) error_log('Transtrade could not prune expired automatic snapshot ' . basename($path));
    foreach (['manual'=>12, 'pre-restore'=>12] as $prefix => $limit) {
        $files = glob(tt_backup_dir() . '/' . $prefix . '-*.zip') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, $limit) as $path) if (!@unlink($path)) error_log('Transtrade could not prune expired ' . $prefix . ' snapshot ' . basename($path));
    }
}

function tt_maybe_auto_backup(): void {
    try {
        tt_backup_ensure_dirs();
        $state = tt_backup_read_state();
        $last = strtotime((string)($state['last_auto_at'] ?? '')) ?: 0;
        if (time() - $last >= 3600) tt_create_server_snapshot('auto');
    } catch (Throwable $error) {
        error_log('Transtrade automatic backup failed: ' . $error->getMessage());
    }
}

function tt_backup_status(): array {
    tt_maybe_auto_backup();
    tt_backup_ensure_dirs();
    $files = glob(tt_backup_dir() . '/*.zip') ?: [];
    $bytes = 0; foreach ($files as $file) $bytes += filesize($file) ?: 0;
    $state = tt_backup_read_state();
    return [
        'automaticAvailable'=>function_exists('gzdeflate') && function_exists('openssl_encrypt'),
        'lastAutoBackup'=>$state['last_auto_at'] ?? null,
        'lastOwnerDownload'=>$state['last_owner_download_at'] ?? null,
        'snapshotCount'=>count($files),
        'snapshotBytes'=>$bytes,
        'lastSnapshot'=>$state['last_snapshot'] ?? null,
        'lastBackupSuccess'=>$state['last_success_at'] ?? null,
        'lastBackupErrorAt'=>$state['last_error_at'] ?? null,
        'backupHealthy'=>empty($state['last_error_at']) || (!empty($state['last_success_at']) && strtotime((string)$state['last_success_at']) >= strtotime((string)$state['last_error_at'])),
        'consecutiveFailures'=>(int)($state['consecutive_failures'] ?? 0),
        'snapshots'=>tt_backup_snapshot_list(),
    ];
}

function tt_backup_open_verified(string $path,string $password=''):array{$zip=new TT_SimpleZipReader($path);$manifestRaw=$zip->get('backup-manifest.json');$manifest=$manifestRaw?json_decode($manifestRaw,true):null;if(!is_array($manifest)||($manifest['application']??'')!=='Transtrade'||($manifest['type']??'')!=='full_recovery'){$zip->close();throw new InvalidArgumentException('This is not a Complete Transtrade Recovery Backup.');}if((int)($manifest['schema']??0)>TT_BACKUP_SCHEMA){$zip->close();throw new InvalidArgumentException('This backup was created by a newer Transtrade backup format.');}$enc=(array)($manifest['encryption']??[]);$salt=base64_decode((string)($enc['salt']??''),true);if($salt===false||($enc['algorithm']??'')!=='AES-256-GCM'){$zip->close();throw new InvalidArgumentException('The recovery backup encryption information is invalid.');}$key=tt_backup_derive_key($password,$salt,(int)($enc['iterations']??200000));$map=(array)($manifest['encryptedEntries']??[]);$authMeta=(array)($map['System_Recovery/private/auth.json']??[]);if(!$authMeta||empty($authMeta['stored'])&&empty($authMeta['chunks'])){$zip->close();throw new InvalidArgumentException('The recovery backup does not contain auth.json.');}$stream=fopen('php://temp','w+');try{tt_backup_decrypt_entry($zip,$authMeta,$key,$stream);rewind($stream);$auth=stream_get_contents($stream);}finally{fclose($stream);}if(!is_array(json_decode($auth,true))){$zip->close();throw new InvalidArgumentException('The backup contains invalid recovery data.');}return[$zip,$manifest,$key];}
function tt_backup_verify_full(string $path,string $password):array{
    [$zip,$manifest,$key]=tt_backup_open_verified($path,$password);
    try{
        foreach((array)($manifest['encryptedEntries']??[]) as $meta){
            $sink=fopen('php://temp/maxmemory:1048576','w+');if($sink===false)throw new RuntimeException('Backup verification storage is unavailable.');
            try{tt_backup_decrypt_entry($zip,(array)$meta,$key,$sink);}finally{fclose($sink);}
        }
        return $manifest;
    }finally{$zip->close();}
}
function tt_backup_atomic_write(string $path,string $data):void{$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('A recovery folder could not be created.');$tmp=$path.'.restore-'.bin2hex(random_bytes(4));if(file_put_contents($tmp,$data,LOCK_EX)===false)throw new RuntimeException('A recovery file could not be written.');@chmod($tmp,0600);if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('A recovery file could not be replaced.');}}
function tt_backup_restore_allowed(string $rel):bool{return $rel!==''&&!str_contains($rel,'..')&&!str_starts_with($rel,'/')&&!str_starts_with($rel,'backups/')&&!in_array($rel,['backup_state.json','auth.lock','backup-job.lock','backup-state.lock'],true)&&preg_match('/\.(json|pdf|doc|docx|xls|xlsx|csv|txt|jpg|jpeg|png|webp)$/i',$rel)===1;}
function tt_backup_commit_staged(array $staged):int{
    $authLock=tt_open_store_lock(LOCK_EX);$operationsLock=null;$count=0;
    try{
        if(is_file(tt_operations_file())){$operationsLock=fopen(tt_operations_file(),'rb');if($operationsLock===false||!flock($operationsLock,LOCK_EX))throw new RuntimeException('Shared operational storage could not be locked for restore.');}
        foreach($staged as $rel=>$tmp){$dest=TT_DATA_DIR.'/'.$rel;$dir=dirname($dest);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('A recovery folder could not be created.');if(!rename($tmp,$dest))throw new RuntimeException('A recovery file could not be replaced.');@chmod($dest,0600);$count++;}
    }finally{if(is_resource($operationsLock)){flock($operationsLock,LOCK_UN);fclose($operationsLock);}flock($authLock,LOCK_UN);fclose($authLock);}
    return $count;
}
function tt_backup_stage_file(string $rel,callable $write):string{
    $dir=dirname(TT_DATA_DIR.'/'.$rel);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('A recovery folder could not be created.');
    $tmp=$dir.'/.restore-'.bin2hex(random_bytes(8));$out=fopen($tmp,'x+b');if($out===false)throw new RuntimeException('A recovery file could not be staged.');
    try{$write($out);if(!fflush($out))throw new RuntimeException('A recovery file could not be flushed.');}catch(Throwable $error){fclose($out);@unlink($tmp);throw $error;}fclose($out);@chmod($tmp,0600);
    if(str_ends_with(strtolower($rel),'.json')){$raw=file_get_contents($tmp);if($raw===false){@unlink($tmp);throw new InvalidArgumentException('A JSON recovery file failed validation.');}json_decode($raw,true);if(json_last_error()!==JSON_ERROR_NONE){@unlink($tmp);throw new InvalidArgumentException('A JSON recovery file failed validation.');}}
    return $tmp;
}
function tt_restore_complete_backup(string $path,string $password):array{
    [$zip,$manifest,$key]=tt_backup_open_verified($path,$password);$staged=[];
    try{
        foreach((array)($manifest['encryptedEntries']??[]) as $logical=>$meta){
            if(!str_starts_with((string)$logical,'System_Recovery/private/'))continue;
            $rel=substr((string)$logical,strlen('System_Recovery/private/'));if(!tt_backup_restore_allowed($rel))continue;
            $staged[$rel]=tt_backup_stage_file($rel,static fn($out)=>tt_backup_decrypt_entry($zip,(array)$meta,$key,$out));
        }
        if(!isset($staged['auth.json']))throw new InvalidArgumentException('The recovery backup does not contain auth.json.');
        $safety=tt_create_server_snapshot('pre-restore');
        $restored=tt_backup_commit_staged($staged);
        return ['restoredFiles'=>$restored,'safetySnapshot'=>$safety,'manifest'=>$manifest];
    }finally{$zip->close();foreach($staged as $tmp)if(is_file($tmp))@unlink($tmp);}
}

function tt_backup_snapshot_list():array{
    tt_backup_ensure_dirs();$files=glob(tt_backup_dir().'/{auto,manual,pre-restore}-*.zip',GLOB_BRACE)?:[];usort($files,static fn($a,$b):int=>(filemtime($b)?:0)<=>(filemtime($a)?:0));
    return array_map(static fn($file):array=>['name'=>basename($file),'bytes'=>filesize($file)?:0,'createdAt'=>gmdate('c',filemtime($file)?:time())],array_slice($files,0,100));
}
function tt_backup_verified_server_snapshot(string $name):array{
    if(!preg_match('/^(auto|manual|pre-restore)-[0-9]{8}-[0-9]{6}(?:-[a-f0-9]{8})?\.zip$/D',$name))throw new InvalidArgumentException('Select a listed server snapshot.');
    $path=tt_backup_dir().'/'.$name;if(!is_file($path))throw new InvalidArgumentException('The selected snapshot is no longer available.');
    $zip=new TT_SimpleZipReader($path,0xffffffff);
    try{$raw=$zip->get('backup-manifest.json');$manifest=$raw===false?null:json_decode($raw,true);
        if(!is_array($manifest)||($manifest['application']??'')!=='Transtrade'||($manifest['type']??'')!=='server_snapshot')throw new InvalidArgumentException('This server snapshot is invalid.');
        $inventory=[];foreach((array)($manifest['recoveryFiles']??[]) as $item){$rel=(string)($item['path']??'');if(!tt_backup_restore_allowed($rel)||isset($inventory[$rel]))throw new InvalidArgumentException('The snapshot inventory is invalid.');$inventory[$rel]=['size'=>(int)($item['size']??-1),'sha256'=>(string)($item['sha256']??'')];}
        if(!isset($inventory['auth.json']))throw new InvalidArgumentException('The snapshot has no authentication data.');
        tt_backup_verify_snapshot($path,$inventory);return $manifest;
    }finally{$zip->close();}
}
function tt_restore_server_snapshot(string $name):array{
    $manifest=tt_backup_verified_server_snapshot($name);$zip=new TT_SimpleZipReader(tt_backup_dir().'/'.$name,0xffffffff);$staged=[];
    try{foreach($manifest['recoveryFiles'] as $item){$rel=(string)$item['path'];$staged[$rel]=tt_backup_stage_file($rel,static function($out)use($zip,$rel,$item):void{$digest=$zip->storedDigest('System_Recovery/private/'.$rel,$out);if($digest===false||$digest['size']!==(int)$item['size']||!hash_equals((string)$item['sha256'],$digest['sha256']))throw new InvalidArgumentException('A snapshot entry failed verification.');});}
        $safety=tt_create_server_snapshot('pre-restore');$restored=tt_backup_commit_staged($staged);return ['restoredFiles'=>$restored,'safetySnapshot'=>$safety];
    }finally{$zip->close();foreach($staged as $tmp)if(is_file($tmp))@unlink($tmp);}
}
?>
