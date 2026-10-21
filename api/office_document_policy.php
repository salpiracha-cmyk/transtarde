<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/upload_validation.php';
const OFFICE_JOB_MAX_BYTES=268435456;
const OFFICE_JOBS_MAX_BYTES=1073741824;
const OFFICE_JOBS_MAX_COUNT=1000;
const OFFICE_JOBS_MAX_ENTRIES=100000;
function office_document_name(string $name): string {
    if($name===''||strlen($name)>800||strlen(mb_convert_encoding($name,'UTF-16LE','UTF-8'))>400||trim($name)!==$name||preg_match('/[<>:"\/\\\\|?*\x00-\x1f\x7f]/',$name)||preg_match('/[. ]$/D',$name)||preg_match('/^(con|prn|aux|nul|conin\$|conout\$|clock\$|com[1-9¹²³]|lpt[1-9¹²³])(?:\.|$)/iu',$name))throw new InvalidArgumentException('Choose a safe document name without paths or Windows reserved names.');
    if(str_contains($name,'~'))throw new InvalidArgumentException('Office document names may not use Windows short-name aliases.');
    return mb_strtoupper($name,'UTF-8');
}
function office_zip_documents(string $path,bool $docx): void {
    $h=fopen($path,'rb');if($h===false)throw new InvalidArgumentException('Document package is unavailable.');$temps=[];
    try{
        $size=filesize($path);if($size<22||$size>75*1024*1024)throw new InvalidArgumentException('Document package size is invalid.');
        fseek($h,-22,SEEK_END);$end=fread($h,22);$e=unpack('Vsignature/vdisk/vcentralDisk/ventriesDisk/ventries/VcentralSize/VcentralOffset/vcomment',$end);
        if($e['signature']!==0x06054b50||$e['disk']||$e['centralDisk']||$e['comment']||$e['entries']!==$e['entriesDisk']||$e['entries']<1||$e['entries']>80||$e['centralOffset']+$e['centralSize']!==$size-22)throw new InvalidArgumentException('Use a complete application-generated document package.');
        fseek($h,$e['centralOffset']);$rows=[];$seen=[];$required=['[Content_Types].xml','_rels/.rels','word/_rels/document.xml.rels','word/styles.xml','word/document.xml'];
        for($i=0;$i<$e['entries'];$i++){
            $header=fread($h,46);if(strlen($header)!==46)throw new InvalidArgumentException('Package directory is incomplete.');
            $r=unpack('Vsignature/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vattributes/Vexternal/Voffset',$header);
            if($r['signature']!==0x02014b50||$r['needed']>20||($r['flags']&~0x800)!==0||$r['method']!==0||$r['compressed']!==$r['size']||$r['size']<1||$r['size']>75*1024*1024||$r['disk']||$r['extraLength']||$r['commentLength']||$r['nameLength']<1||$r['nameLength']>800||(($r['external']>>16)&0170000)!==0&&(($r['external']>>16)&0170000)!==0100000)throw new InvalidArgumentException('Compressed, linked, encrypted or unsupported package members are not permitted.');
            $name=fread($h,$r['nameLength']);if(strlen($name)!==$r['nameLength'])throw new InvalidArgumentException('Package name is incomplete.');
            if($docx){if(!in_array($name,$required,true))throw new InvalidArgumentException('Word package contains unsupported or active content.');$key=strtolower($name);}else{$key=office_document_name($name);if(!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['pdf','jpg','jpeg','png','webp'],true))throw new InvalidArgumentException('BAGS package may contain only PDF and image documents.');}
            if(isset($seen[$key]))throw new InvalidArgumentException('Package repeats a document name.');$seen[$key]=true;$rows[]=[$name,$r];
        }
        if(ftell($h)!==$size-22||($docx&&count($seen)!==count($required)))throw new InvalidArgumentException('Package directory does not match its documents.');
        $offset=0;
        foreach($rows as [$name,$r]){
            if($r['offset']!==$offset)throw new InvalidArgumentException('Package has overlapping or hidden members.');fseek($h,$offset);$header=fread($h,30);if(strlen($header)!==30)throw new InvalidArgumentException('Package member is incomplete.');
            $l=unpack('Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength',$header);
            foreach(['needed','flags','method','time','date','crc','compressed','size','nameLength','extraLength'] as $key)if($l[$key]!==$r[$key])throw new InvalidArgumentException('Package metadata conflicts with its document.');
            if($l['signature']!==0x04034b50||fread($h,$r['nameLength'])!==$name)throw new InvalidArgumentException('Package member name conflicts.');
            $offset+=30+$r['nameLength']+$r['size'];if($offset>$e['centralOffset'])throw new InvalidArgumentException('Package member exceeds its boundary.');
            $tmp=tempnam(sys_get_temp_dir(),'tti-office-doc-');if($tmp===false)throw new RuntimeException('Document validation storage is unavailable.');$temps[]=$tmp;$out=fopen($tmp,'wb');if($out===false)throw new RuntimeException('Document validation storage is unavailable.');$crc=hash_init('crc32b');$remaining=$r['size'];
            try{while($remaining>0){$chunk=fread($h,min(65536,$remaining));if($chunk===false||$chunk==='')throw new InvalidArgumentException('Package document is incomplete.');$remaining-=strlen($chunk);hash_update($crc,$chunk);if(fwrite($out,$chunk)!==strlen($chunk))throw new RuntimeException('Document validation failed.');}}finally{fclose($out);}
            if(hash_final($crc)!==sprintf('%08x',$r['crc']))throw new InvalidArgumentException('Package document checksum is invalid.');
            if(!$docx){office_document_validate($tmp,$name,false);continue;}
            if($r['size']>2097152)throw new InvalidArgumentException('Word package XML exceeds the permitted size.');$xml=file_get_contents($tmp);
            if($xml===false||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new InvalidArgumentException('Word package entities are not permitted.');
            $previous=libxml_use_internal_errors(true);try{$dom=new DOMDocument();$valid=$dom->loadXML($xml,LIBXML_NONET);libxml_clear_errors();}finally{libxml_use_internal_errors($previous);}
            if(!$valid||$dom->doctype)throw new InvalidArgumentException('Word document XML is invalid.');
            foreach($dom->getElementsByTagName('*') as $node){
                if(in_array($node->localName,['altChunk','object','OLEObject','fldSimple','instrText','hyperlink'],true)||strcasecmp($node->getAttribute('TargetMode'),'External')===0)throw new InvalidArgumentException('Word package active or external content is not permitted.');
                if($node->localName==='Relationship'&&!in_array($node->getAttribute('Target'),['word/document.xml','styles.xml'],true))throw new InvalidArgumentException('Word relationship is unsupported.');
                if($node->hasAttribute('ContentType')&&!in_array($node->getAttribute('ContentType'),['application/xml','application/vnd.openxmlformats-package.relationships+xml','application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml','application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml'],true))throw new InvalidArgumentException('Word content type is unsupported.');
            }
        }
        if($offset!==$e['centralOffset'])throw new InvalidArgumentException('Package contains hidden bytes.');
    }finally{fclose($h);foreach($temps as $tmp)@unlink($tmp);}
}
function office_pdf_structure(string $path): bool {
    $h=fopen($path,'rb');if($h===false)return false;
    try{$size=(int)(fstat($h)['size']??0);if($size<30)return false;fseek($h,-min($size,4096),SEEK_END);$tail=stream_get_contents($h);if(!is_string($tail)||!preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/D',$tail,$match))return false;$offset=(int)$match[1];if($offset<8||$offset>=$size)return false;fseek($h,$offset);$xref=fread($h,min(65536,$size-$offset));if(!is_string($xref))return false;
        if(str_starts_with($xref,'xref'))return preg_match('/trailer\s*<<[\s\S]*?\/Root\s+\d+\s+\d+\s+R[\s\S]*?>>/',$tail)===1;
        return preg_match('/^\d+\s+\d+\s+obj\s*<<(?:(?!stream)[\s\S])*?\/Type\s*\/XRef(?:(?!stream)[\s\S])*?\/Root\s+\d+\s+\d+\s+R/', $xref)===1||preg_match('/^\d+\s+\d+\s+obj\s*<<(?:(?!stream)[\s\S])*?\/Root\s+\d+\s+\d+\s+R(?:(?!stream)[\s\S])*?\/Type\s*\/XRef/', $xref)===1;
    }finally{fclose($h);}
}
function office_document_validate(string $path,string $name,bool $packages=true): void {
    office_document_name($name);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext]??null;
    if($mime!==null){if(!tt_upload_signature_valid($path,$mime)||($ext==='pdf'&&!office_pdf_structure($path)))throw new InvalidArgumentException('Document bytes do not match its PDF or image format.');return;}
    if($packages&&in_array($ext,['docx','zip'],true)){office_zip_documents($path,$ext==='docx');return;}
    throw new InvalidArgumentException('Office archives accept PDF, JPEG, PNG, WebP, generated Word and BAGS document packages only.');
}
function office_jobs_admit(string $root,int $bytes,int $files): void {
    if($bytes<1||$bytes>OFFICE_JOB_MAX_BYTES||$files<1||$files>80)throw new InvalidArgumentException('One shipment archive must contain at most 80 files and 256 MiB.');
    if(is_link($root))throw new RuntimeException('Office storage needs review.');
    $total=0;$entries=0;$jobs=0;
    foreach(new DirectoryIterator($root) as $child){if($child->isDot())continue;if($child->isLink())throw new RuntimeException('Office storage needs review.');if($child->getFilename()==='.admission.lock')continue;if($child->isLink())throw new RuntimeException('Office storage needs review.');if($child->isDir())$jobs++;}
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as $entry){if($entry->getPathname()===$root.'/.admission.lock')continue;if(++$entries>OFFICE_JOBS_MAX_ENTRIES||$entry->isLink())throw new RuntimeException('Office storage needs review.');if($entry->isFile()){$size=$entry->getSize();if($size<0||!$entry->isReadable())throw new RuntimeException('Office storage needs review.');$total+=$size;}elseif(!$entry->isDir())throw new RuntimeException('Office storage needs review.');}
    if($jobs>=OFFICE_JOBS_MAX_COUNT||$entries+$files+3>OFFICE_JOBS_MAX_ENTRIES||$total+$bytes+($jobs+1)*1048576>OFFICE_JOBS_MAX_BYTES)throw new RuntimeException('Office archive capacity is full. Existing documents were preserved. Ask Super Admin to review archive storage.');
}
function office_job_document_paths(array $job,string $dir,?string $selectedStored=null): array {
    if(is_link($dir))throw new InvalidArgumentException('Office file folder requires review.');
    $parts=$job['folderParts']??[];if(!is_array($parts)||count($parts)!==3)throw new InvalidArgumentException('Office folder path requires customer, contract and lot.');foreach($parts as $part)office_document_name((string)$part);
    $rows=$job['files']??[];if(!is_array($rows)||count($rows)<1||count($rows)>80)throw new InvalidArgumentException('Office file manifest is invalid.');$seen=[];$storage=[];$bytes=0;$out=[];
    foreach($rows as $row){if(!is_array($row))throw new InvalidArgumentException('Office file manifest is invalid.');$name=(string)($row['name']??'');$key=office_document_name($name);$folder=(string)($row['folder']??'');if($folder!=='')$key=office_document_name($folder).'/'.$key;$stored=(string)($row['storedName']??'');$storageKey=office_document_name($stored);if(isset($seen[$key])||isset($storage[$storageKey]))throw new InvalidArgumentException('Office manifest repeats a Windows file destination.');$seen[$key]=true;$storage[$storageKey]=true;
        $path=$dir.'/'.$stored;if(is_link($path)||!is_file($path)||!is_readable($path)||filesize($path)!==(int)($row['size']??-1))throw new InvalidArgumentException('Office document storage requires review.');$bytes+=(int)$row['size'];if($bytes>OFFICE_JOB_MAX_BYTES)throw new InvalidArgumentException('Office package exceeds its total size limit.');if($selectedStored===null||$stored===$selectedStored)office_document_validate($path,$name);$out[]=$path;
    }return $out;
}
