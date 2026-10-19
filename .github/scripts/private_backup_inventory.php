<?php
// Standalone read-only inventory. No application includes, restores or writers.
declare(strict_types=1);
$target=getenv('TT_BACKUP_INVENTORY_ROOT')?:'';
if(getenv('TT_BACKUP_INVENTORY_FIXTURE')!=='1'&&!preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~D',$target))throw new RuntimeException('Invalid target');
$private=dirname($target).'/transtrade_private';$root=$private.'/backups';
foreach([$target,$private,$root]as$p)if(!is_dir($p)||is_link($p)||realpath($p)!==$p)throw new RuntimeException('Unsafe root');
function bucket(string $name):string{
 $n=strtolower($name);$base=basename($n);
 if($base==='backup-manifest.json')return 'snapshot_manifest';
 if(preg_match('~(^|/)(backups|backup)(/|$)~',$n)||preg_match('/backup|before|migration|corrupt|pre-restore/',$base))return 'historical_or_recovery_copies';
 if($base==='operations.json')return 'live_operations_store';
 if($base==='auth.json')return 'live_authentication_store';
 if(preg_match('~(^|/)(uploads|documents|attachments)(/|$)~',$n))return 'uploaded_documents';
 if(preg_match('/audit|log/',$base))return 'audit_or_logs';
 if(preg_match('/\.(pdf|png|jpe?g|webp)$/',$base))return 'document_or_image';
 if(preg_match('/\.(zip|gz|tgz|tar)$/',$base))return 'other_archives';
 return 'other_private_files';
}
function add(array &$a,string $key,int $bytes):void{if(!isset($a[$key]))$a[$key]=['files'=>0,'bytes'=>0];$a[$key]['files']++;$a[$key]['bytes']+=$bytes;}
function exact($f,int $n):string{$s='';while(strlen($s)<$n){$c=fread($f,$n-strlen($s));if($c===false||$c==='')throw new RuntimeException('Truncated ZIP');$s.=$c;}return $s;}
function zipmeta(string $path):array{
 $f=fopen($path,'rb');if(!$f)throw new RuntimeException('Unreadable ZIP');
 try{
  $size=filesize($path);if($size<22)throw new RuntimeException('Short ZIP');
  $tail=min($size,66000);fseek($f,$size-$tail);$s=exact($f,$tail);$pos=strrpos($s,"PK\x05\x06");if($pos===false)throw new RuntimeException('Missing ZIP directory');
  $end=unpack('vdisk/vstart/vdiskcount/vcount/Vsize/Voffset/vcomment',substr($s,$pos+4,18));
  if($end['disk']||$end['start']||$end['offset']+$end['size']>$size||$end['count']===65535)throw new RuntimeException('Unsupported ZIP');
  fseek($f,$end['offset']);$entries=[];$groups=[];$largest=[];$packed=0;$unpacked=0;$manifestEntry=null;
  for($i=0;$i<$end['count'];$i++){
   $h=exact($f,46);if(substr($h,0,4)!=="PK\x01\x02")throw new RuntimeException('Invalid ZIP directory');
   $e=unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vinternal/Vexternal/Voffset',substr($h,4));
   $name=exact($f,$e['nlen']);if($e['elen']+$e['clen'])exact($f,$e['elen']+$e['clen']);
   if(str_starts_with($name,'/')||preg_match('~(^|/)\.\.(/|$)~',$name)||isset($entries[$name]))throw new RuntimeException('Unsafe ZIP path');
   $entries[$name]=true;$key=bucket($name);add($groups,$key,$e['usize']);$packed+=$e['csize'];$unpacked+=$e['usize'];
   $largest[]=['category'=>$key,'pathId'=>substr(hash('sha256',$name),0,12),'bytes'=>$e['usize'],'compressedBytes'=>$e['csize']];
   if($name==='backup-manifest.json')$manifestEntry=$e;
  }
  usort($largest,fn($a,$b)=>$b['bytes']<=>$a['bytes']);
  $result=['entryCount'=>count($entries),'packedEntryBytes'=>$packed,'unpackedEntryBytes'=>$unpacked,'categories'=>$groups,'largestEntries'=>array_slice($largest,0,8),'manifestValid'=>false];
  if($manifestEntry&&$manifestEntry['usize']<=16777216&&$manifestEntry['csize']<=16777216){
   $e=$manifestEntry;fseek($f,$e['offset']);$h=exact($f,30);if(substr($h,0,4)!=="PK\x03\x04")throw new RuntimeException('Invalid manifest header');
   $lh=unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen',substr($h,4));exact($f,$lh['nlen']+$lh['elen']);$raw=exact($f,$e['csize']);
   if($e['method']===8)$raw=gzinflate($raw,16777216);elseif($e['method']!==0)throw new RuntimeException('Unsupported manifest');
   if(!is_string($raw)||strlen($raw)!==$e['usize']||sprintf('%u',crc32($raw))!==sprintf('%u',$e['crc']))throw new RuntimeException('Manifest CRC mismatch');
   $m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$result['manifestValid']=($m['application']??'')==='Transtrade'&&($m['type']??'')==='server_snapshot';
   $result['snapshotKind']=in_array($m['snapshotKind']??'', ['auto','manual','pre-restore'],true)?$m['snapshotKind']:'other';
   $result['createdAt']=preg_match('/^\d{4}-\d{2}-\d{2}T[0-9:+Z-]+$/D',(string)($m['createdAt']??''))?$m['createdAt']:null;
   $inv=[];foreach(($m['recoveryFiles']??[])as$x){$p=$x['path']??'';if(!is_string($p)||!isset($entries['System_Recovery/private/'.$p]))throw new RuntimeException('Manifest path missing');$inv[$p]=['size'=>$x['size']??null,'sha256'=>$x['sha256']??null];}
   ksort($inv);$result['declaredInventoryDigest']=hash('sha256',json_encode($inv));$result['declaredRecoveryFiles']=count($inv);
  }
  return $result;
 }finally{fclose($f);}
}
$out=['mode'=>'read_only_metadata','backupFiles'=>0,'backupBytes'=>0,'symlinksSkipped'=>0,'types'=>[],'byDate'=>[],'zipDirectoryReadable'=>0,'zipDirectoryFailed'=>0,'zipContentCategories'=>[],'manifestInventoryGroups'=>[],'largestBackups'=>[],'livePrivateExcludingBackupsAndSessions'=>[]];$rows=[];$inventories=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as$file){if($file->isLink()){$out['symlinksSkipped']++;continue;}if(!$file->isFile())continue;$path=$file->getPathname();$name=$file->getFilename();$bytes=$file->getSize();$date=gmdate('Y-m-d',$file->getMTime());$kind=preg_match('/^(auto|manual|pre-restore)-/',$name,$m)?$m[1]:(str_ends_with($name,'.tmp')?'unfinished_temp':(str_ends_with($name,'.zip')?'other_zip':'other_file'));$out['backupFiles']++;$out['backupBytes']+=$bytes;add($out['types'],$kind,$bytes);add($out['byDate'],$date,$bytes);
 $row=['fileId'=>substr(hash('sha256',substr($path,strlen($root))),0,12),'kind'=>$kind,'bytes'=>$bytes,'modifiedUtc'=>gmdate('c',$file->getMTime())];
 if(str_ends_with(strtolower($name),'.zip')){try{$meta=zipmeta($path);$out['zipDirectoryReadable']++;foreach($meta['categories']as$key=>$v){if(!isset($out['zipContentCategories'][$key]))$out['zipContentCategories'][$key]=['files'=>0,'bytes'=>0];$out['zipContentCategories'][$key]['files']+=$v['files'];$out['zipContentCategories'][$key]['bytes']+=$v['bytes'];}if(isset($meta['declaredInventoryDigest'])){$d=$meta['declaredInventoryDigest'];if(!isset($inventories[$d]))$inventories[$d]=['copies'=>0,'bytes'=>0];$inventories[$d]['copies']++;$inventories[$d]['bytes']+=$bytes;} $row['zip']=$meta;}catch(Throwable $e){$out['zipDirectoryFailed']++;$row['zipStatus']='unreadable_or_unsupported';}}
 $rows[]=$row;
}
usort($rows,fn($a,$b)=>$b['bytes']<=>$a['bytes']);$out['largestBackups']=array_slice($rows,0,8);usort($inventories,fn($a,$b)=>$b['copies']<=>$a['copies']);$out['manifestInventoryGroups']=['distinctInventories'=>count($inventories),'groupsWithMultipleCopies'=>count(array_filter($inventories,fn($g)=>$g['copies']>1)),'largestGroups'=>array_slice($inventories,0,8),'note'=>'Declared manifest content identity only; not full archive integrity verification'];ksort($out['byDate']);
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private,FilesystemIterator::SKIP_DOTS));foreach($it as$file){if(!$file->isFile()||$file->isLink())continue;$rel=substr($file->getPathname(),strlen($private)+1);if(preg_match('~^(backups|sessions)/~',$rel))continue;add($out['livePrivateExcludingBackupsAndSessions'],bucket($rel),$file->getSize());}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
