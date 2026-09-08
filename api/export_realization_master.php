<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_EXPORT_REALIZATION_MASTER_TYPE = 'export_realization_charges';
const TT_EXPORT_REALIZATION_MASTER_FIELDS = 15;

function erm_respond(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function erm_can_edit(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $p=$user['permissions']['Accounts']??null;
    if ($p==='all') return true;
    if (!is_array($p)) return false;
    if (in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true)) return true;
    foreach($p as $actions) if(is_array($actions)&&(in_array('Create',$actions,true)||in_array('Edit',$actions,true)||in_array('Approve',$actions,true))) return true;
    return false;
}
function erm_defaults(): array {
    return [
        ['Export Withholding Tax — FTR (Historical)','EXP-WHT-FTR','Income Tax','FTR — historical','Export proceeds / realization as applicable','','','','Final / source tax expense — legacy FTR treatment','7200','Yes','TTI; BRM','Historical','Historical FTR row. Preserve old treatment and certificates. Do not silently use for current NTR receipts. Capture the exact tax section shown by the bank/certificate on each receipt.','Fixed / final / non-adjustable tax'],
        ['Advance Withholding Tax on Export Proceeds','EXP-AWT-NTR','Income Tax','NTR — current','As configured from applicable tax rule / bank advice','','','','Advance income tax / tax deducted at source recoverable','1260','Yes','TTI; BRM','Active','Current NTR treatment. Do not hard-code the legal calculation base until confirmed. Actual bank deduction is authoritative for posting; Accounts may edit base, rate and amount on the receipt.','Advance / adjustable tax'],
        ['Fixed Withholding Tax on Export Proceeds','EXP-WHT-FIXED','Income Tax','NTR — fixed component / historical when applicable','As configured from applicable tax rule / bank advice','1','','','Fixed / non-adjustable withholding tax expense when applicable','7200','Yes','TTI; BRM','Historical','Historical example supplied by management: a 1% fixed withholding component existed alongside a separate 1% advance withholding component. Exact effective dates / tax section remain to be confirmed before activating for any period.','Fixed / final / non-adjustable tax'],
        ['Export Development Surcharge (EDS)','EXP-EDS','Surcharge','Any','Export realization / prescribed base','0 / currently not charged','','','Export development surcharge expense','6830','No / bank advice evidence','TTI; BRM','Inactive / currently not charged','Keep the row because the surcharge can change with annual budget policy. Activate a new effective-dated row if it becomes applicable again.','Surcharge / levy'],
        ['Foreign Banking Shortfall / Correspondent Charge','EXP-BANK-SHORTFALL','Bank Fee','Any','Actual invoice / transfer shortfall','Actual','','','Foreign banking charges expense','6810','No','TTI; BRM','Active','Use only for a genuine small difference between invoice / TG transfer amount and amount finally received, where Accounts confirms the difference is a foreign/correspondent banking charge. Do not silently write off differences.','Bank charge / settlement shortfall'],
        ['Export FDBC / Collection / Negotiation Fee','EXP-BANK-FDBC','Bank Fee','Any','Actual bank advice','Actual','','','Export realization / bank charges expense','6810','No','TTI; BRM','Active','Use for export collection, FDBC, negotiation or similar bank fees where separately identified.','Bank charge / expense'],
        ['Export Service / Handling Charges','EXP-BANK-SVC','Bank Fee','Any','Actual bank advice','Actual','','','Export realization / bank charges expense','6810','No','TTI; BRM','Active','Bank service, handling, processing or realization-related charges.','Bank charge / expense'],
        ['FED / Indirect Tax on Export Bank Charges','EXP-FED-BANK','FED / Indirect Tax','Any','Underlying taxable bank charge / actual advice','Actual','','','FED / indirect tax on bank charges','6820','No','TTI; BRM','Active','Record separately from the underlying bank fee. If tax treatment changes or becomes recoverable, update the effective-dated master mapping rather than rewriting history.','Indirect tax'],
        ['Courier / Document Handling on Export Realization','EXP-COURIER','Document / Courier Charge','Any','Actual bank advice','Actual','','','Bank / export document charge expense','6810','No','TTI; BRM','Active','Use where courier/document handling is deducted or separately charged by the bank.','Bank charge / expense'],
        ['Other Export Realization Charge','EXP-OTHER','Other','Any','Actual bank advice','Actual','','','Bank & finance charges / approved mapped account','6800','No','TTI; BRM','Active','Fallback only when the bank advice contains a genuine charge not covered by another active master row. Accounts should add a dedicated row if the charge recurs.','Other deduction / charge']
    ];
}
function erm_seed_if_needed(array $user): array {
    $masters=tt_list_masters();
    $rows=is_array($masters[TT_EXPORT_REALIZATION_MASTER_TYPE]??null)?$masters[TT_EXPORT_REALIZATION_MASTER_TYPE]:[];
    $seen=[];
    foreach($rows as $row){$v=(array)($row['values']??[]);$code=strtoupper(trim((string)($v[1]??'')));if($code!=='')$seen[$code]=true;}
    $created=0;
    foreach(erm_defaults() as $values){$code=strtoupper((string)$values[1]);if(isset($seen[$code]))continue;tt_create_master(TT_EXPORT_REALIZATION_MASTER_TYPE,$values);$seen[$code]=true;$created++;}
    if($created>0) tt_audit((int)($user['id']??0),(string)($user['username']??'system'),'Seeded export realization taxes/charges master defaults');
    $masters=tt_list_masters();
    return is_array($masters[TT_EXPORT_REALIZATION_MASTER_TYPE]??null)?$masters[TT_EXPORT_REALIZATION_MASTER_TYPE]:[];
}
function erm_clean_values(mixed $raw): array {
    if(!is_array($raw))erm_respond(['ok'=>false,'error'=>'Enter the master record details.'],422);
    $values=[];
    foreach(array_slice($raw,0,TT_EXPORT_REALIZATION_MASTER_FIELDS) as $value){
        if(is_array($value)||is_object($value))erm_respond(['ok'=>false,'error'=>'Master fields must contain text values.'],422);
        $value=trim((string)$value);if(strlen($value)>1600)erm_respond(['ok'=>false,'error'=>'One of the master fields is too long.'],422);$values[]=$value;
    }
    while(count($values)<TT_EXPORT_REALIZATION_MASTER_FIELDS)$values[]='';
    if($values[0]===''||$values[1]==='')erm_respond(['ok'=>false,'error'=>'Charge / tax name and code are required.'],422);
    return $values;
}
function erm_payload(array $user): array {
    $rows=erm_seed_if_needed($user);
    usort($rows,static function($a,$b){$av=(array)($a['values']??[]);$bv=(array)($b['values']??[]);return strcmp((string)($av[12]??''),(string)($bv[12]??''))?:strcmp((string)($av[0]??''),(string)($bv[0]??''));});
    return ['ok'=>true,'type'=>TT_EXPORT_REALIZATION_MASTER_TYPE,'editable'=>erm_can_edit($user),'rows'=>$rows,'fields'=>[
        'Charge / tax name','Code','Category','Tax regime / context','Calculation base','Rate % / formula','Effective from','Effective to','Accounting treatment','GL account code','Bank certificate required','Applies to entities','Status','Notes','Deduction nature / tax character'
    ]];
}

try{
    $user=tt_require_login();
    if(($user['role']??'')!=='Super Admin'&&!tt_user_can_open_module($user,'Accounts'))erm_respond(['ok'=>false,'error'=>'Accounts or Super Admin access required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET')erm_respond(erm_payload($user));
    if($_SERVER['REQUEST_METHOD']!=='POST')erm_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!erm_can_edit($user))erm_respond(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'{}',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))erm_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');$id=trim((string)($body['id']??''));
    if($action==='delete'){
        if($id==='')erm_respond(['ok'=>false,'error'=>'Select a master record.'],422);
        tt_delete_master(TT_EXPORT_REALIZATION_MASTER_TYPE,$id);tt_audit((int)$user['id'],(string)$user['username'],'Deleted export realization master '.$id);erm_respond(erm_payload($user));
    }
    $values=erm_clean_values($body['values']??null);$ref=strtoupper($values[1]);
    if($action==='create'){$id=tt_create_master(TT_EXPORT_REALIZATION_MASTER_TYPE,$values);tt_audit((int)$user['id'],(string)$user['username'],'Created export realization master '.$ref);erm_respond(erm_payload($user)+['savedId'=>$id]);}
    if($action==='update'){
        if($id==='')erm_respond(['ok'=>false,'error'=>'Select a master record.'],422);
        tt_update_master(TT_EXPORT_REALIZATION_MASTER_TYPE,$id,$values);tt_audit((int)$user['id'],(string)$user['username'],'Updated export realization master '.$ref);erm_respond(erm_payload($user)+['savedId'=>$id]);
    }
    erm_respond(['ok'=>false,'error'=>'Unknown master action.'],422);
}catch(Throwable $e){erm_respond(['ok'=>false,'error'=>'The export realization master action could not be completed.'],500);}
