<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
const BL_FILE=TT_DATA_DIR.'/accounts.json';
function bl_out(array $x,int $s=200):never{http_response_code($s);echo json_encode($x,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function bl_default():array{return ['revision'=>0,'commodityBills'=>[],'supplierSettlements'=>[],'brokerageBills'=>[],'brokeragePayments'=>[],'brokerageWhtDeposits'=>[]];}
function bl_read():array{tt_ensure_data_dir();if(!is_file(BL_FILE))return bl_default();$h=fopen(BL_FILE,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('store');try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);} $s=$raw?json_decode($raw,true):null;return is_array($s)?array_replace_recursive(bl_default(),$s):bl_default();}
function bl_money(mixed $v):float{return round((float)$v,2);}
function bl_rows(array $s,string $entity):array{
    $out=[];
    foreach((array)($s['commodityBills']??[]) as $id=>$b){
        if(!is_array($b)||($b['entity']??'')!==$entity)continue;
        $gross=bl_money($b['brokerageGross']??0);if($gross<=0)continue;
        $wht=bl_money($b['brokerageWithholding']??0);$net=round($gross-$wht,2);
        $out[]=['id'=>'AUTO|'.(string)$id,'source'=>'Purchase Bill','sourceId'=>(string)$id,'date'=>(string)($b['billDate']??''),'broker'=>(string)($b['broker']??''),'commodity'=>(string)($b['commodity']??''),'product'=>(string)($b['product']??$b['variety']??''),'sodas'=>array_values((array)($b['sodas']??[])),'gross'=>$gross,'wht'=>$wht,'net'=>$net,'paid'=>0.0,'outstanding'=>$net,'status'=>'Outstanding'];
    }
    foreach((array)($s['brokerageBills']??[]) as $id=>$b){
        if(!is_array($b)||($b['entity']??'')!==$entity||($b['status']??'Posted')==='Cancelled')continue;
        $gross=bl_money($b['acceptedBrokerage']??$b['grossBrokerage']??0);$wht=bl_money($b['whtAmount']??0);$net=round($gross-$wht,2);
        $out[]=['id'=>'SEPARATE|'.(string)$id,'source'=>'Separate Brokerage Bill','sourceId'=>(string)$id,'date'=>(string)($b['billDate']??''),'broker'=>(string)($b['broker']??''),'commodity'=>(string)($b['commodity']??''),'product'=>(string)($b['productSummary']??''),'sodas'=>array_values((array)($b['sodas']??[])),'gross'=>$gross,'wht'=>$wht,'net'=>$net,'paid'=>0.0,'outstanding'=>$net,'status'=>'Outstanding'];
    }

    $paid=[];
    // Automatic brokerage is settled together with the commodity supplier bill.
    // Supplier settlements already store the brokerage component for each selected bill/truck allocation.
    foreach((array)($s['supplierSettlements']??[]) as $p){
        if(!is_array($p)||($p['entity']??'')!==$entity||!in_array((string)($p['status']??''),['Posted','Approved / Posted','Approved'],true))continue;
        foreach((array)($p['allocations']??[]) as $a){
            if(!is_array($a))continue;
            $billId=trim((string)($a['billId']??''));if($billId==='')continue;
            $amt=bl_money($a['brokerageAmount']??0);if($amt<=0)continue;
            $k='AUTO|'.$billId;$paid[$k]=round(($paid[$k]??0)+$amt,2);
        }
    }
    // Separate brokerage invoices remain separately payable and use the brokerage payment workflow.
    foreach((array)($s['brokeragePayments']??[]) as $p){
        if(!is_array($p)||($p['entity']??'')!==$entity||!in_array((string)($p['status']??''),['Posted','Approved / Posted','Approved'],true))continue;
        $k=(string)($p['brokerageSourceId']??'');if($k!=='')$paid[$k]=round(($paid[$k]??0)+bl_money($p['amount']??0),2);
    }
    foreach($out as &$r){$p=min($r['net'],bl_money($paid[$r['id']]??0));$r['paid']=$p;$r['outstanding']=max(0,round($r['net']-$p,2));$r['status']=$r['outstanding']<=.005?'Paid':($p>0?'Part Paid':'Outstanding');}unset($r);
    usort($out,static fn($a,$b)=>strcmp((string)$b['date'],(string)$a['date'])?:strcmp((string)$a['broker'],(string)$b['broker']));return $out;
}
function bl_summary(array $rows,array $s,string $entity):array{$by=[];foreach($rows as $r){$b=trim((string)$r['broker'])?:'Unspecified';if(!isset($by[$b]))$by[$b]=['broker'=>$b,'gross'=>0.0,'wht'=>0.0,'net'=>0.0,'paid'=>0.0,'outstanding'=>0.0,'whtDeposited'=>0.0,'whtOutstanding'=>0.0];foreach(['gross','wht','net','paid','outstanding'] as $k)$by[$b][$k]=round($by[$b][$k]+bl_money($r[$k]??0),2);}foreach((array)($s['brokerageWhtDeposits']??[]) as $d){if(!is_array($d)||($d['entity']??'')!==$entity||!in_array((string)($d['status']??'Posted'),['Posted','Approved / Posted','Approved'],true))continue;$b=trim((string)($d['broker']??''))?:'Unspecified';if(!isset($by[$b]))$by[$b]=['broker'=>$b,'gross'=>0.0,'wht'=>0.0,'net'=>0.0,'paid'=>0.0,'outstanding'=>0.0,'whtDeposited'=>0.0,'whtOutstanding'=>0.0];$by[$b]['whtDeposited']=round($by[$b]['whtDeposited']+bl_money($d['amount']??0),2);}foreach($by as &$x)$x['whtOutstanding']=max(0,round($x['wht']-$x['whtDeposited'],2));unset($x);ksort($by);return array_values($by);}
try{$u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))bl_out(['ok'=>false,'error'=>'Accounts permission required.'],403);if($_SERVER['REQUEST_METHOD']!=='GET')bl_out(['ok'=>false,'error'=>'Method not allowed.'],405);$entity=strtoupper(trim((string)($_GET['entity']??'TTI')));if(!in_array($entity,['TTI','BRM'],true))bl_out(['ok'=>false,'error'=>'Brokerage ledger is available for TTI or BRM.'],422);$s=bl_read();$rows=bl_rows($s,$entity);bl_out(['ok'=>true,'entity'=>$entity,'rows'=>$rows,'brokers'=>bl_summary($rows,$s,$entity),'revision'=>(int)($s['revision']??0)]);}catch(Throwable $e){bl_out(['ok'=>false,'error'=>'Brokerage ledger is temporarily unavailable.'],500);}
