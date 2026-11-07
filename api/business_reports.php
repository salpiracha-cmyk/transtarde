<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/business_reports_core.php';
require_once __DIR__.'/shipment_profitability_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function br_out(array $r,int $code=200):never {http_response_code($code);echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $user=tt_require_login();$origin=($_GET['origin']??'accounts')==='directors'?'Directors':'Accounts';
    if(!gm_permission($user,'DAILY','View',$origin))br_out(['ok'=>false,'error'=>'Group reporting permission and access to all three companies are required.'],403);
    if($_SERVER['REQUEST_METHOD']!=='GET')br_out(['ok'=>false,'error'=>'Reports are read-only.'],405);
    $date=gm_date($_GET['date']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'));
    $kind=dt_enum($_GET['report']??'arp',['arp','shipment'],'report');tt_release_read_session();
    $books=sp_read_books(TT_DATA_DIR.'/accounts.json');$ops=br_operations();$root=br_root($ops);$rates=dcd_group_rates($books,$date);
    if($kind==='arp')$result=br_arp($books,$ops,$root,$date,$rates);
    else {require_once __DIR__.'/shipment_statement_core.php';$result=ss_report($books,$ops,$root,$date,gm_text($_GET['shipment']??'',200),($_GET['goods']??'1')==='1',gm_text($_GET['costing']??'',200),$rates);}
    br_out(['ok'=>true,'report'=>$kind,'date'=>$date,'generatedAt'=>gmdate('c'),'accountsRevision'=>$books['revision']??0]+$result);
}catch(DomainException|JsonException $e){br_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Business reports: '.$e->getMessage());br_out(['ok'=>false,'error'=>'The report sources could not be verified. Refresh after resolving the source error.'],500);}
