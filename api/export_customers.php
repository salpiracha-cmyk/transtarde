<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function customer_respond(array $data, int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function customer_text(mixed $v,int $max=1200): string { if(is_array($v)||is_object($v)) return ''; return substr(trim((string)$v),0,$max); }
function customer_bool(mixed $v): bool { return $v===true||$v===1||$v==='1'||in_array(strtolower(trim((string)$v)),['yes','true','on'],true); }
function customer_roles(string $roles): string {
    $items=preg_split('/\s*[;,\n]+\s*/',$roles)?:[]; $items=array_values(array_unique(array_filter(array_map('trim',$items))));
    if(!array_filter($items,fn($r)=>preg_match('/\bexport\s*buyer\b/i',$r))) array_unshift($items,'Export Buyer');
    return implode('; ',$items);
}
function customer_notify_json(mixed $input): string {
    if(is_string($input)){
        $decoded=json_decode($input,true); if(is_array($decoded)) $input=$decoded;
        else { $rows=preg_split('/\r?\n/',$input)?:[]; $input=array_map(function($line){$p=array_map('trim',explode('|',(string)$line,2));return ['name'=>$p[0]??'','address'=>$p[1]??''];},$rows); }
    }
    if(!is_array($input)) return '[]'; $out=[];
    foreach($input as $row){ if(!is_array($row))continue; $name=customer_text($row['name']??'',180);$address=customer_text($row['address']??'',1200);if($name===''&&$address==='')continue;$out[]=['name'=>$name,'address'=>$address];if(count($out)>=12)break; }
    return json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'[]';
}
function customer_row(array $row): array {
    $v=array_values(is_array($row['values']??null)?$row['values']:[]); while(count($v)<16)$v[]='';
    $notifies=[]; if($v[9]!==''){ $d=json_decode((string)$v[9],true); if(is_array($d))$notifies=$d; }
    return [
        'masterId'=>(string)($row['id']??''),'name'=>(string)$v[0],'code'=>(string)$v[1],'roles'=>(string)$v[2],
        'address'=>(string)$v[3],'country'=>(string)$v[4],'email'=>(string)$v[5],'phone'=>(string)$v[6],'tax'=>(string)$v[7],
        'packingDefault'=>(string)($v[8]?:'KG'),'notifies'=>$notifies,'status'=>(string)($v[10]?:'Active'),'notes'=>(string)$v[11],
        'showCountry'=>customer_bool($v[12]),'showEmail'=>customer_bool($v[13]),'showPhone'=>customer_bool($v[14]),'showTax'=>customer_bool($v[15]),
    ];
}
function customer_can_write(array $user): bool {
    if(($user['role']??'')==='Super Admin')return true; $g=$user['permissions']['Exports']??[]; if($g==='all')return true;if(!is_array($g))return false;
    if(in_array('Create',$g,true)||in_array('Edit',$g,true))return true;
    foreach(['customers','contracts','contract'] as $icon){$a=$g[$icon]??[];if($a==='all')return true;if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)))return true;}return false;
}
function customer_db_env(string $name): string { $c='TT_'.$name;if(defined($c))return(string)constant($c);$v=getenv($c);return$v===false?'':(string)$v; }
function customer_export_state(): ?array {
    try{
        $host=customer_db_env('DB_HOST');$name=customer_db_env('DB_NAME');$dbUser=customer_db_env('DB_USER');$pass=customer_db_env('DB_PASS');
        if($host!==''&&$name!==''&&$dbUser!==''){
            $db=new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4",$dbUser,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $q=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LIMIT 1');$q->execute(['transtrade_export_v3_operational']);$payload=$q->fetchColumn();
            if(is_string($payload)&&$payload!==''){ $root=json_decode($payload,true);return is_array($root)?$root:null; }
        }
    }catch(Throwable $e){}
    $path=rtrim((string)TT_DATA_DIR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'operations.json'; if(!is_file($path))return null;$raw=@file_get_contents($path);if(!is_string($raw)||$raw==='')return null;
    $store=json_decode($raw,true);if(!is_array($store))return null;$payload=$store['values']['transtrade_export_v3_operational']??null;if(!is_string($payload)||$payload==='')return null;$root=json_decode($payload,true);return is_array($root)?$root:null;
}
function customer_is_used(array $masterRow): bool {
    $root=customer_export_state();if(!$root)return false;$v=$masterRow['values']??[];$mid=(string)($masterRow['id']??'');$name=strtolower(trim((string)($v[0]??'')));$code=strtoupper(trim((string)($v[1]??'')));$local=null;
    foreach((array)($root['customers']??[]) as $c){if(!is_array($c))continue;if(($mid!==''&&(string)($c['masterId']??'')===$mid)||($name!==''&&strtolower(trim((string)($c['name']??'')))===$name)||($code!==''&&strtoupper(trim((string)($c['code']??'')))===$code)){$local=$c;break;}}
    $localId=(string)($local['id']??'');foreach((array)($root['contracts']??[]) as $c){if(!is_array($c))continue;if(($localId!==''&&(string)($c['customerId']??'')===$localId)||($name!==''&&strtolower(trim((string)($c['customer']??'')))===$name))return true;}
    foreach((array)($root['shipments']??[]) as $s)if(is_array($s)&&$name!==''&&strtolower(trim((string)($s['buyer']??'')))===$name)return true;return false;
}
function customer_rows(): array { $m=tt_list_masters();$rows=is_array($m['export_customers']??null)?$m['export_customers']:[];$out=[];foreach($rows as $row)$out[]=customer_row($row);usort($out,fn($a,$b)=>strcasecmp($a['name'],$b['name']));return$out; }

try{
    $user=tt_require_login();if(($user['role']??'')!=='Super Admin'&&!tt_user_can_open_module($user,'Exports'))customer_respond(['ok'=>false,'error'=>'Exports access required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET')customer_respond(['ok'=>true,'customers'=>customer_rows()]);
    if($_SERVER['REQUEST_METHOD']!=='POST')customer_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!customer_can_write($user))customer_respond(['ok'=>false,'error'=>'Create or Edit permission for Customers / Sales Contracts is required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))customer_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');$masters=tt_list_masters();$partyRows=is_array($masters['export_customers']??null)?$masters['export_customers']:[];
    if($action==='upsert'){
        $input=is_array($body['customer']??null)?$body['customer']:[];$mode=(string)($body['mode']??'edit');$mid=customer_text($input['masterId']??'',80);$name=customer_text($input['name']??'',220);$address=customer_text($input['address']??'',1200);
        if($name===''||$address==='')throw new InvalidArgumentException('Customer name and address are required.');
        $code=strtoupper(preg_replace('/[^A-Z0-9-]/i','',customer_text($input['code']??'',24))?:'');if($code==='')$code=strtoupper(substr(preg_replace('/[^A-Za-z]/','',$name)?:'CUS',0,6));
        $incoming=[$name,$code,customer_roles(customer_text($input['roles']??'Export Buyer',500)),$address,customer_text($input['country']??'',180),customer_text($input['email']??'',220),customer_text($input['phone']??'',120),customer_text($input['tax']??'',220),strtoupper(customer_text($input['packingDefault']??'KG',8))==='LB'?'LB':'KG',customer_notify_json($input['notifies']??[]),in_array(strtolower(customer_text($input['status']??'Active',20)),['inactive','archived'],true)?'Inactive':'Active',customer_text($input['notes']??'',1200),customer_bool($input['showCountry']??false)?'Yes':'No',customer_bool($input['showEmail']??false)?'Yes':'No',customer_bool($input['showPhone']??false)?'Yes':'No',customer_bool($input['showTax']??false)?'Yes':'No'];
        $match=null;foreach($partyRows as $row){$v=$row['values']??[];if(($mid!==''&&(string)($row['id']??'')===$mid)||($mid===''&&strcasecmp((string)($v[0]??''),$name)===0)||($mid===''&&$code!==''&&strcasecmp((string)($v[1]??''),$code)===0)){$match=$row;break;}}
        $created=false;if($match){$existing=array_values(is_array($match['values']??null)?$match['values']:[]);while(count($existing)<16)$existing[]='';if($mode==='sync'){for($i=0;$i<16;$i++){if($incoming[$i]!==''&&!($i===10&&$existing[$i]!==''))$existing[$i]=$incoming[$i];}if($existing[2]==='')$existing[2]='Export Buyer';if($existing[8]==='')$existing[8]='KG';if($existing[10]==='')$existing[10]='Active';$values=$existing;}else$values=$incoming;tt_update_master('export_customers',(string)$match['id'],$values);$mid=(string)$match['id'];tt_audit((int)$user['id'],(string)$user['username'],'Updated Export customer master '.$name);}else{$mid=tt_create_master('export_customers',$incoming);$created=true;tt_audit((int)$user['id'],(string)$user['username'],'Created Export customer master '.$name);}
        customer_respond(['ok'=>true,'created'=>$created,'customer'=>customer_row(['id'=>$mid,'values'=>$values??$incoming]),'customers'=>customer_rows()]);
    }
    if($action==='delete'){
        $mid=customer_text($body['masterId']??'',80);if($mid==='')throw new InvalidArgumentException('Select a customer.');$match=null;foreach($partyRows as $row)if((string)($row['id']??'')===$mid){$match=$row;break;}if(!$match)throw new InvalidArgumentException('Customer master record not found.');
        $v=array_values(is_array($match['values']??null)?$match['values']:[]);while(count($v)<16)$v[]='';$used=customer_is_used($match)||!empty($body['used']);if($used){$v[10]='Inactive';tt_update_master('export_customers',$mid,$v);tt_audit((int)$user['id'],(string)$user['username'],'Archived used Export customer '.$v[0]);customer_respond(['ok'=>true,'archived'=>true,'message'=>'Customer is used in export history and was archived instead of erased.','customers'=>customer_rows()]);}
        tt_delete_master('export_customers',$mid);tt_audit((int)$user['id'],(string)$user['username'],'Deleted unused Export customer '.$v[0]);customer_respond(['ok'=>true,'deleted'=>true,'message'=>'Unused customer deleted.','customers'=>customer_rows()]);
    }
    customer_respond(['ok'=>false,'error'=>'Unknown action.'],400);
}catch(InvalidArgumentException $e){customer_respond(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Transtrade customer master: '.$e->getMessage());customer_respond(['ok'=>false,'error'=>'Customer master action could not be completed.'],500);}
