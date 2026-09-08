<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_TG_FX_MASTER_TYPE = 'tg_currency_rates';
const TT_TG_FX_MASTER_FIELDS = 12;

function tgfx_out(array $d,int $s=200): never {http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function tgfx_can_edit(array $u): bool {
    if(($u['role']??'')==='Super Admin') return true;
    $p=$u['permissions']['Accounts']??null;
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
    $m=tt_list_masters();$rows=is_array($m[TT_TG_FX_MASTER_TYPE]??null)?$m[TT_TG_FX_MASTER_TYPE]:[];$seen=[];
    foreach($rows as $r){$v=(array)($r['values']??[]);$c=strtoupper(trim((string)($v[1]??'')));if($c!=='')$seen[$c]=true;}
    foreach(tgfx_defaults() as $v){$c=strtoupper($v[1]);if(isset($seen[$c]))continue;tt_create_master(TT_TG_FX_MASTER_TYPE,$v);$seen[$c]=true;tt_audit((int)($u['id']??0),(string)($u['username']??'system'),'Seeded TG currency master '.$c);}
    $m=tt_list_masters();return is_array($m[TT_TG_FX_MASTER_TYPE]??null)?$m[TT_TG_FX_MASTER_TYPE]:[];
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
function tgfx_payload(array $u): array {
    $rows=tgfx_seed($u);usort($rows,static function($a,$b){$av=(array)($a['values']??[]);$bv=(array)($b['values']??[]);return strcmp((string)($bv[6]??''),(string)($av[6]??''));});
    return ['ok'=>true,'editable'=>tgfx_can_edit($u),'type'=>TT_TG_FX_MASTER_TYPE,'fields'=>[
        'Rate set name','Code','Currency pair','Sell USD → Receive AED','Buy USD ← Pay AED','Final Accounts / Tax Rate','Effective from','Effective to','TG year-end (MM-DD)','Closing / reporting currency','Status','Notes'
    ],'rows'=>$rows,'rule'=>'Daily TG USD and AED bank balances remain in their native currencies. The Final Accounts / Tax Rate is used only for AED closing/reporting translation unless an actual transaction specifically uses that rate.'];
}

try{
    $u=tt_require_login();if(($u['role']??'')!=='Super Admin'&&!tt_user_can_open_module($u,'Accounts'))tgfx_out(['ok'=>false,'error'=>'Accounts or Super Admin access required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET')tgfx_out(tgfx_payload($u));
    if($_SERVER['REQUEST_METHOD']!=='POST')tgfx_out(['ok'=>false,'error'=>'Method not allowed.'],405);if(!tgfx_can_edit($u))tgfx_out(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
    $b=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??'')))tgfx_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($b['action']??'');$id=trim((string)($b['id']??''));
    if($action==='delete'){if($id==='')tgfx_out(['ok'=>false,'error'=>'Select a rate set.'],422);tt_delete_master(TT_TG_FX_MASTER_TYPE,$id);tt_audit((int)$u['id'],(string)$u['username'],'Deleted TG currency master '.$id);tgfx_out(tgfx_payload($u));}
    $v=tgfx_clean($b['values']??null);$ref=strtoupper($v[1]);
    if($action==='create'){$id=tt_create_master(TT_TG_FX_MASTER_TYPE,$v);tt_audit((int)$u['id'],(string)$u['username'],'Created TG currency master '.$ref);tgfx_out(tgfx_payload($u)+['savedId'=>$id]);}
    if($action==='update'){if($id==='')tgfx_out(['ok'=>false,'error'=>'Select a rate set.'],422);tt_update_master(TT_TG_FX_MASTER_TYPE,$id,$v);tt_audit((int)$u['id'],(string)$u['username'],'Updated TG currency master '.$ref);tgfx_out(tgfx_payload($u)+['savedId'=>$id]);}
    tgfx_out(['ok'=>false,'error'=>'Unknown TG currency master action.'],422);
}catch(Throwable $e){tgfx_out(['ok'=>false,'error'=>'The TG currency master action could not be completed.'],500);}
