<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_TG_FX_MASTER_TYPE = 'tg_currency_rates';
const TT_TG_FX_MASTER_FIELDS = 12;
const TT_TG_DOC_MASTER_TYPE = 'tg_compliance_documents';
const TT_TG_DOC_FIELDS = 13;
const TT_TG_DOC_DIR = TT_DATA_DIR . '/tg_documents';

function tgfx_out(array $d,int $s=200): never {http_response_code($s);header('Content-Type: application/json; charset=UTF-8');echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function tgfx_masters(): array {return (array)(tt_read_store()['masters']??[]);}
function tgfx_can_view(array $u): bool {return (($u['role']??'')==='Super Admin')||tt_user_can_open_module($u,'Directors');}
function tgfx_can_edit(array $u): bool {
    if(($u['role']??'')==='Super Admin') return true;
    $p=$u['permissions']['Directors']??null;
    if($p==='all') return true;
    if(!is_array($p)) return false;
    if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true)) return true;
    foreach($p as $a) if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)||in_array('Approve',$a,true))) return true;
    return false;
}
function tgfx_defaults(): array {
    return [[
        'TG USD / AED Peg — Current','TG-USD-AED-CURRENT','USD/AED','3.6700','3.6750','3.6700',
        '2026-01-01','','12-31','AED','Active',
        'Operational rates supplied by management. USD→AED means sell USD and receive AED. AED→USD means buy USD using AED. Final Accounts / Tax rate is a separate closing rate and may be changed later without altering historical transactions.'
    ]];
}
function tgfx_seed(array $u): array {
    $m=tgfx_masters();$rows=is_array($m[TT_TG_FX_MASTER_TYPE]??null)?$m[TT_TG_FX_MASTER_TYPE]:[];$seen=[];
    foreach($rows as $r){$v=(array)($r['values']??[]);$c=strtoupper(trim((string)($v[1]??'')));if($c!=='')$seen[$c]=true;}
    foreach(tgfx_defaults() as $v){$c=strtoupper($v[1]);if(isset($seen[$c]))continue;tt_create_master(TT_TG_FX_MASTER_TYPE,$v);$seen[$c]=true;tt_audit((int)($u['id']??0),(string)($u['username']??'system'),'Seeded TG master currency rate '.$c);}
    $m=tgfx_masters();return is_array($m[TT_TG_FX_MASTER_TYPE]??null)?$m[TT_TG_FX_MASTER_TYPE]:[];
}
function tgfx_clean(mixed $raw): array {
    if(!is_array($raw))tgfx_out(['ok'=>false,'error'=>'Enter the TG currency master details.'],422);$v=[];
    foreach(array_slice($raw,0,TT_TG_FX_MASTER_FIELDS) as $x){if(is_array($x)||is_object($x))tgfx_out(['ok'=>false,'error'=>'Master fields must contain text values.'],422);$x=trim((string)$x);if(strlen($x)>1600)tgfx_out(['ok'=>false,'error'=>'One master field is too long.'],422);$v[]=$x;}
    while(count($v)<TT_TG_FX_MASTER_FIELDS)$v[]='';
    if($v[0]===''||$v[1]==='')tgfx_out(['ok'=>false,'error'=>'Rate name and code are required.'],422);
    if(!preg_match('/^[A-Za-z0-9_-]{3,80}$/',$v[1]))tgfx_out(['ok'=>false,'error'=>'Code may contain letters, numbers, hyphen and underscore only.'],422);
    foreach([3,4,5] as $i){$n=(float)$v[$i];if($n<=0||$n>20)tgfx_out(['ok'=>false,'error'=>'Enter valid positive USD/AED rates.'],422);$v[$i]=number_format($n,4,'.','');}
    if($v[6]!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v[6]))tgfx_out(['ok'=>false,'error'=>'Effective From must be YYYY-MM-DD.'],422);
    if($v[7]!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v[7]))tgfx_out(['ok'=>false,'error'=>'Effective To must be YYYY-MM-DD.'],422);
    if(!preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/',$v[8]))tgfx_out(['ok'=>false,'error'=>'Year-end must be MM-DD.'],422);
    $v[9]=strtoupper($v[9]?:'AED');if($v[9]!=='AED')tgfx_out(['ok'=>false,'error'=>'TG closing/reporting presentation is currently configured in AED.'],422);
    return $v;
}
function tg_company(): array {
    $m=tgfx_masters();foreach((array)($m['companies']??[]) as $r){$v=(array)($r['values']??[]);if(strtoupper(trim((string)($v[1]??'')))==='TG')return ['id'=>(string)($r['id']??''),'name'=>(string)($v[0]??'Trans Grains'),'code'=>'TG','country'=>(string)($v[2]??'United Arab Emirates'),'scope'=>(string)($v[3]??'Offshore'),'roles'=>(string)($v[4]??''),'notes'=>(string)($v[6]??'')];}return ['id'=>'','name'=>'Trans Grains Foodstuff Trading L.L.C','code'=>'TG','country'=>'United Arab Emirates','scope'=>'Offshore','roles'=>'','notes'=>''];
}
function tg_entity_from_linked(string $s): bool {$u=strtoupper($s);return str_contains($u,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$u)===1;}
function tg_mask(string $v,int $last=5): string {$c=preg_replace('/\s+/','',trim($v))??'';if($c==='')return '';return str_repeat('•',max(0,strlen($c)-$last)).substr($c,-$last);}
function tg_banks(): array {
    $m=tgfx_masters();$out=[];foreach((array)($m['banks']??[]) as $r){if(!is_array($r))continue;$v=array_values((array)($r['values']??[]));while(count($v)<14)$v[]='';if(!tg_entity_from_linked((string)$v[1]))continue;$out[]=['id'=>(string)($r['id']??''),'accountType'=>(string)$v[0],'linkedCompany'=>(string)$v[1],'accountTitle'=>(string)$v[3],'bankName'=>(string)$v[4],'branch'=>(string)$v[5],'country'=>(string)$v[6],'currency'=>strtoupper((string)$v[7]),'accountNumber'=>(string)$v[8],'accountNumberMasked'=>tg_mask((string)$v[8]),'iban'=>(string)$v[9],'ibanMasked'=>tg_mask((string)$v[9]),'swift'=>(string)$v[10],'purpose'=>(string)$v[11],'visibility'=>(string)$v[12],'status'=>(string)$v[13]];}
    usort($out,static fn($a,$b)=>strcmp((string)$a['currency'],(string)$b['currency'])?:strcmp((string)$a['bankName'],(string)$b['bankName']));return $out;
}
function tg_bank_values(mixed $raw): array {
    if(!is_array($raw))tgfx_out(['ok'=>false,'error'=>'Enter the TG bank account details.'],422);
    $title=trim((string)($raw['accountTitle']??''));$bank=trim((string)($raw['bankName']??''));$currency=strtoupper(trim((string)($raw['currency']??'')));
    if($title===''||$bank===''||!in_array($currency,['USD','AED'],true))tgfx_out(['ok'=>false,'error'=>'Account title, bank name and valid currency are required.'],422);
    return ['Company Account','Trans Grains Foodstuff Trading L.L.C (TG)','',$title,$bank,trim((string)($raw['branch']??'')),trim((string)($raw['country']??'United Arab Emirates')),$currency,trim((string)($raw['accountNumber']??'')),trim((string)($raw['iban']??'')),trim((string)($raw['swift']??'')),trim((string)($raw['purpose']??'')),trim((string)($raw['visibility']??'Accounts; Directors')),trim((string)($raw['status']??'Active'))];
}
function tg_docs(): array {
    $m=tgfx_masters();$out=[];foreach((array)($m[TT_TG_DOC_MASTER_TYPE]??[]) as $r){if(!is_array($r))continue;$v=array_values((array)($r['values']??[]));while(count($v)<TT_TG_DOC_FIELDS)$v[]='';$out[]=['id'=>(string)($r['id']??''),'documentType'=>(string)$v[0],'holder'=>(string)$v[1],'documentNumber'=>(string)$v[2],'issueDate'=>(string)$v[3],'expiryDate'=>(string)$v[4],'status'=>(string)$v[5],'storedFile'=>(string)$v[6],'originalFile'=>(string)$v[7],'mime'=>(string)$v[8],'notes'=>(string)$v[9],'createdAt'=>(string)$v[10],'createdBy'=>(string)$v[11],'renewalOf'=>(string)$v[12],'hasFile'=>trim((string)$v[6])!==''];}
    usort($out,static fn($a,$b)=>strcmp((string)$a['expiryDate'],(string)$b['expiryDate'])?:strcmp((string)$a['holder'],(string)$b['holder']));return $out;
}
function tg_doc_clean(mixed $raw): array {
    if(!is_array($raw))tgfx_out(['ok'=>false,'error'=>'Enter compliance document details.'],422);$type=trim((string)($raw['documentType']??''));$holder=trim((string)($raw['holder']??''));$expiry=trim((string)($raw['expiryDate']??''));
    if(!in_array($type,['Trade License','Partner Emirates ID','Other Compliance Document'],true))tgfx_out(['ok'=>false,'error'=>'Select a valid TG compliance document type.'],422);
    if($holder==='')$holder=$type==='Trade License'?'Trans Grains Foodstuff Trading L.L.C':'';if($holder==='')tgfx_out(['ok'=>false,'error'=>'Partner / document holder is required.'],422);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expiry))tgfx_out(['ok'=>false,'error'=>'Valid expiry date is required.'],422);
    $issue=trim((string)($raw['issueDate']??''));if($issue!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$issue))tgfx_out(['ok'=>false,'error'=>'Issue date must be YYYY-MM-DD.'],422);
    return ['documentType'=>$type,'holder'=>$holder,'documentNumber'=>trim((string)($raw['documentNumber']??'')),'issueDate'=>$issue,'expiryDate'=>$expiry,'status'=>trim((string)($raw['status']??'Active'))?:'Active','notes'=>trim((string)($raw['notes']??'')),'renewalOf'=>trim((string)($raw['renewalOf']??''))];
}
function tg_alerts(array $docs): array {
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Dubai'));$alerts=[];$upcoming=[];
    foreach($docs as $d){if(!is_array($d)||strtolower((string)($d['status']??''))!=='active'||!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($d['expiryDate']??'')))continue;$exp=new DateTimeImmutable((string)$d['expiryDate'],new DateTimeZone('Asia/Dubai'));$days=(int)$today->diff($exp)->format('%r%a');$two=$exp->modify('-2 months');$fortyFive=$exp->modify('-45 days');$one=$exp->modify('-1 month');$phase='';$severity='';
        if($today>$exp){$phase='Expired — renewal required';$severity='critical';}
        elseif($today==$exp){$phase='Expires today';$severity='critical';}
        elseif($today>=$one){$phase=$today==$one?'1 month before expiry':'Daily renewal reminder';$severity=$days<=7?'critical':($days<=14?'high':'warning');}
        elseif($today==$fortyFive){$phase='45 days before expiry';$severity='warning';}
        elseif($today==$two){$phase='2 months before expiry';$severity='notice';}
        if($phase!=='')$alerts[]=['id'=>'TGEXP|'.$d['id'].'|'.$today->format('Y-m-d'),'source'=>'TG Master','documentId'=>$d['id'],'documentType'=>$d['documentType'],'holder'=>$d['holder'],'expiryDate'=>$d['expiryDate'],'daysRemaining'=>$days,'phase'=>$phase,'severity'=>$severity,'audience'=>['Directors','Super Admin'],'message'=>$d['documentType'].' — '.$d['holder'].' · '.$phase.' · expiry '.$d['expiryDate']];
        $upcoming[]=['documentId'=>$d['id'],'documentType'=>$d['documentType'],'holder'=>$d['holder'],'expiryDate'=>$d['expiryDate'],'daysRemaining'=>$days,'nextMilestones'=>['twoMonths'=>$two->format('Y-m-d'),'fortyFiveDays'=>$fortyFive->format('Y-m-d'),'oneMonth'=>$one->format('Y-m-d'),'dailyFrom'=>$one->format('Y-m-d')]];
    }
    usort($alerts,static fn($a,$b)=>($a['daysRemaining']<=>$b['daysRemaining']));usort($upcoming,static fn($a,$b)=>($a['daysRemaining']<=>$b['daysRemaining']));return ['today'=>$today->format('Y-m-d'),'current'=>$alerts,'upcoming'=>$upcoming];
}
function tg_save_uploaded_file(array $file): array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return ['stored'=>'','original'=>'','mime'=>''];if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)tgfx_out(['ok'=>false,'error'=>'Document upload failed.'],422);if((int)($file['size']??0)>10*1024*1024)tgfx_out(['ok'=>false,'error'=>'Document copy must be 10 MB or smaller.'],422);
    $tmp=(string)($file['tmp_name']??'');$orig=basename((string)($file['name']??'document'));$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);$allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];if(!isset($allowed[$mime]))tgfx_out(['ok'=>false,'error'=>'Upload PDF, JPG or PNG only.'],422);
    tt_ensure_data_dir();if(!is_dir(TT_TG_DOC_DIR)&&!mkdir(TT_TG_DOC_DIR,0700,true)&&!is_dir(TT_TG_DOC_DIR))throw new RuntimeException('TG document storage unavailable.');$stored=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$dest=TT_TG_DOC_DIR.'/'.$stored;if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('TG document could not be stored.');@chmod($dest,0600);return ['stored'=>$stored,'original'=>$orig,'mime'=>$mime];
}
function tg_doc_download(array $u,string $id): never {
    $docs=tg_docs();$d=null;foreach($docs as $x)if((string)$x['id']===$id){$d=$x;break;}if(!$d||empty($d['storedFile'])){http_response_code(404);exit('Document copy not found.');}$path=TT_TG_DOC_DIR.'/'.basename((string)$d['storedFile']);if(!is_file($path)){http_response_code(404);exit('Document file is unavailable.');}$mime=(string)($d['mime']??'application/octet-stream');$name=preg_replace('/[^A-Za-z0-9._ -]+/','_',basename((string)($d['originalFile']??'TG-document')))?:'TG-document';header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="'.$name.'"');readfile($path);exit;
}
function tgfx_payload(array $u): array {
    $rows=tgfx_seed($u);usort($rows,static function($a,$b){$av=(array)($a['values']??[]);$bv=(array)($b['values']??[]);return strcmp((string)($bv[6]??''),(string)($av[6]??''));});$docs=tg_docs();
    return ['ok'=>true,'editable'=>tgfx_can_edit($u),'masterName'=>'TG Master','type'=>TT_TG_FX_MASTER_TYPE,'company'=>tg_company(),'banks'=>tg_banks(),'documents'=>$docs,'alerts'=>tg_alerts($docs),'fields'=>['Rate set name','Code','Currency pair','Sell USD → Receive AED','Buy USD ← Pay AED','Final Accounts / Tax Rate','Effective from','Effective to','TG year-end (MM-DD)','Closing / reporting currency','Status','Notes'],'rows'=>$rows,'rule'=>'Daily TG USD and AED bank balances remain in their native currencies. Final Accounts / Tax Rate is used for AED closing/reporting translation. Compliance documents remain versioned and produce Directors/Super Admin renewal alerts.'];
}

try{
    $u=tt_require_login();if(!tgfx_can_view($u))tgfx_out(['ok'=>false,'error'=>'Directors or Super Admin access required.'],403);
    if(isset($_GET['download']))tg_doc_download($u,trim((string)$_GET['download']));
    if($_SERVER['REQUEST_METHOD']==='GET')tgfx_out(tgfx_payload($u));
    if($_SERVER['REQUEST_METHOD']!=='POST')tgfx_out(['ok'=>false,'error'=>'Method not allowed.'],405);if(!tgfx_can_edit($u))tgfx_out(['ok'=>false,'error'=>'Directors Create / Edit / Approve permission required.'],403);
    $isMultipart=str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data');
    if($isMultipart){$csrf=(string)($_POST['csrf']??'');if(!tt_verify_csrf($csrf))tgfx_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);$action=(string)($_POST['action']??'');if($action!=='save_document')tgfx_out(['ok'=>false,'error'=>'Unknown TG document action.'],422);$raw=['documentType'=>$_POST['documentType']??'','holder'=>$_POST['holder']??'','documentNumber'=>$_POST['documentNumber']??'','issueDate'=>$_POST['issueDate']??'','expiryDate'=>$_POST['expiryDate']??'','status'=>$_POST['status']??'Active','notes'=>$_POST['notes']??'','renewalOf'=>$_POST['renewalOf']??''];$clean=tg_doc_clean($raw);$existingId=trim((string)($_POST['id']??''));$existing=null;if($existingId!=='')foreach(tg_docs() as $x)if($x['id']===$existingId){$existing=$x;break;}$upload=tg_save_uploaded_file((array)($_FILES['file']??[]));$stored=$upload['stored']?:((string)($existing['storedFile']??''));$orig=$upload['original']?:((string)($existing['originalFile']??''));$mime=$upload['mime']?:((string)($existing['mime']??''));$values=[$clean['documentType'],$clean['holder'],$clean['documentNumber'],$clean['issueDate'],$clean['expiryDate'],$clean['status'],$stored,$orig,$mime,$clean['notes'],(string)($existing['createdAt']??gmdate('c')),(string)($existing['createdBy']??($u['full_name']??$u['username']??'Directors')),$clean['renewalOf']];if($existingId===''){$id=tt_create_master(TT_TG_DOC_MASTER_TYPE,$values);tt_audit((int)$u['id'],(string)$u['username'],'Created TG compliance document '.$clean['documentType'].' '.$clean['holder']);}else{$id=$existingId;tt_update_master(TT_TG_DOC_MASTER_TYPE,$id,$values);tt_audit((int)$u['id'],(string)$u['username'],'Updated TG compliance document '.$clean['documentType'].' '.$clean['holder']);}if($clean['renewalOf']!==''&&$clean['renewalOf']!==$id){foreach(tg_docs() as $old)if($old['id']===$clean['renewalOf']){$ov=[$old['documentType'],$old['holder'],$old['documentNumber'],$old['issueDate'],$old['expiryDate'],'Historical',$old['storedFile'],$old['originalFile'],$old['mime'],$old['notes'],$old['createdAt'],$old['createdBy'],$old['renewalOf']];tt_update_master(TT_TG_DOC_MASTER_TYPE,$old['id'],$ov);break;}}tgfx_out(tgfx_payload($u)+['savedDocumentId'=>$id]);}
    $b=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??'')))tgfx_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);$action=(string)($b['action']??'');$id=trim((string)($b['id']??''));
    if($action==='save_bank'){$vals=tg_bank_values($b['bank']??null);if($id===''){$id=tt_create_master('banks',$vals);tt_audit((int)$u['id'],(string)$u['username'],'Created TG bank master '.$vals[4].' '.$vals[7]);}else{tt_update_master('banks',$id,$vals);tt_audit((int)$u['id'],(string)$u['username'],'Updated TG bank master '.$vals[4].' '.$vals[7]);}tgfx_out(tgfx_payload($u)+['savedBankId'=>$id]);}
    if($action==='archive_document'){if($id==='')tgfx_out(['ok'=>false,'error'=>'Select a document.'],422);$found=null;foreach(tg_docs() as $x)if($x['id']===$id){$found=$x;break;}if(!$found)tgfx_out(['ok'=>false,'error'=>'TG document not found.'],404);$v=[$found['documentType'],$found['holder'],$found['documentNumber'],$found['issueDate'],$found['expiryDate'],'Historical',$found['storedFile'],$found['originalFile'],$found['mime'],$found['notes'],$found['createdAt'],$found['createdBy'],$found['renewalOf']];tt_update_master(TT_TG_DOC_MASTER_TYPE,$id,$v);tt_audit((int)$u['id'],(string)$u['username'],'Archived TG compliance document '.$id);tgfx_out(tgfx_payload($u));}
    if($action==='delete'){if($id==='')tgfx_out(['ok'=>false,'error'=>'Select a rate set.'],422);tt_delete_master(TT_TG_FX_MASTER_TYPE,$id);tt_audit((int)$u['id'],(string)$u['username'],'Deleted TG currency rate '.$id);tgfx_out(tgfx_payload($u));}
    if(in_array($action,['create','update'],true)){$v=tgfx_clean($b['values']??null);$ref=strtoupper($v[1]);if($action==='create'){$id=tt_create_master(TT_TG_FX_MASTER_TYPE,$v);tt_audit((int)$u['id'],(string)$u['username'],'Created TG currency rate '.$ref);}else{if($id==='')tgfx_out(['ok'=>false,'error'=>'Select a rate set.'],422);tt_update_master(TT_TG_FX_MASTER_TYPE,$id,$v);tt_audit((int)$u['id'],(string)$u['username'],'Updated TG currency rate '.$ref);}tgfx_out(tgfx_payload($u)+['savedId'=>$id]);}
    tgfx_out(['ok'=>false,'error'=>'Unknown TG master action.'],422);
}catch(Throwable $e){tgfx_out(['ok'=>false,'error'=>'The TG master action could not be completed.'],500);}

