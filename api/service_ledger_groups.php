<?php
declare(strict_types=1);
function slg_groups():array{return ['CLEARING'=>'Customs & Clearing','TRANSPORT'=>'Transport / Cartage','FREIGHT'=>'Freight & Logistics','OTHER'=>'Other / Unclassified Services'];}
function slg_kind(string $kind):string{return match(strtoupper($kind)){'CLEARING','CUSTOMS','INSPECTION'=>'CLEARING','TRANSPORT','CARTAGE'=>'TRANSPORT','FREIGHT','FORWARDING','SHIPPING'=>'FREIGHT',default=>'OTHER'};}
function slg_normal(string $value):string{return strtolower(preg_replace('/[^\p{L}\p{N}]/u','',$value)??'');}
function slg_party_kind(string $name,array $masters):string {
    $found=[];foreach((array)($masters['business_parties']??[]) as $r){$v=(array)($r['values']??[]);if(!in_array(slg_normal($name),array_filter([slg_normal((string)($v[0]??'')),slg_normal((string)($v[1]??'')),slg_normal((string)($r['id']??''))]),true))continue;
        $roles=function_exists('tt_business_party_categories')?tt_business_party_categories($v[2]??''):preg_split('/[,;|]/',(string)($v[2]??''));
        foreach($roles as $role){$kind=match(trim($role)){'Clearing Agent'=>'CLEARING','Transporter'=>'TRANSPORT','Freight Forwarder','Shipping Line / Carrier'=>'FREIGHT',default=>''};if($kind!=='')$found[$kind]=true;}
    }return count($found)===1?(string)array_key_first($found):'OTHER';
}
function slg_bill_kind(array $s,string $id):string {
    $b=$s['supplierBills'][$id]??null;if($b)return slg_kind((string)($b['category']??$b['sourceRecord']['kind']??''));
    foreach(['transportBillsV1'=>'TRANSPORT','freightBillsV1'=>'FREIGHT','exportServiceBillsV1'=>'OTHER'] as $collection=>$fallback)if(isset($s[$collection][$id]))return slg_kind((string)($s[$collection][$id]['kind']??$fallback));return 'OTHER';
}
function slg_line_kind(array $s,array $j,array $l,array $masters):string {
    if(!in_array($l['account']??'',['2130','1250','5510','5520','5530'],true))return 'OTHER';
    if(!empty($l['serviceGroup']))return $l['serviceGroup'];$kind=slg_kind((string)($l['category']??$j['meta']['kind']??''));if($kind!=='OTHER')return $kind;
    $id=(string)($l['supplierBillId']??$j['meta']['supplierBillId']??'');if($id!==''){$kind=slg_bill_kind($s,$id);if($kind!=='OTHER')return $kind;}
    foreach(['TRANSPORT'=>'TRANSPORT','CLEARING'=>'CLEARING','FREIGHT'=>'FREIGHT'] as $word=>$kind)if(str_contains((string)($j['sourceType']??''),$word))return $kind;
    $expense=match((string)($l['account']??'')){'5510'=>'TRANSPORT','5520'=>'FREIGHT','5530'=>'CLEARING',default=>''};if($expense!=='')return $expense;
    return slg_party_kind((string)($l['supplier']??$l['counterparty']??$l['party']??$l['broker']??$j['meta']['broker']??$j['meta']['supplier']??$l['subledger']??''),$masters);
}
/** Split only the existing creditor line; never add a monetary posting. */
function slg_lines(array $s,array $j,array $masters):array {
    $out=[];$source=$j;if(!empty($j['reversalOf'])&&isset($s['journals'][$j['reversalOf']]))$source=$s['journals'][$j['reversalOf']];
    $alloc=(array)($source['meta']['allocations']??[]);
    foreach((array)($j['lines']??[]) as $l){
        if(($l['account']??'')==='2130'&&$alloc){$parts=[];$total=0;
            foreach($alloc as $a){if(($a['payableAccount']??'2130')!=='2130')continue;$amount=round((float)($a['logisticsAmount']??$a['amount']??0),2);if($amount<=0)continue;$kind=slg_kind((string)($a['category']??''));if($kind==='OTHER')$kind=slg_bill_kind($s,(string)($a['billId']??''));$parts[]=[$kind,$amount];$total=round($total+$amount,2);}
            if($parts&&abs($total-abs((float)($l['debit']??0)-(float)($l['credit']??0)))<.005){foreach($parts as [$kind,$amount]){$fragment=$l;$fraction=$amount/$total;foreach(['debit','credit','nativeDebit','nativeCredit'] as $field)if(isset($fragment[$field]))$fragment[$field]=round((float)$fragment[$field]*$fraction,2);$fragment['serviceGroup']=$kind;$out[]=$fragment;}continue;}
        }
        $l['serviceGroup']=slg_line_kind($s,$j,$l,$masters);$out[]=$l;
    }return $out;
}
function slg_reviews(array $s,string $e,array $masters):array {
    $out=[];foreach((array)($s['supplierBills']??[]) as $b){if(($b['entity']??'')!==$e)continue;$posted=slg_kind((string)($b['category']??''));if($posted==='OTHER')continue;$party=(string)($b['vendor']??$b['broker']??'');$role=slg_party_kind($party,$masters);
        if($role!=='OTHER'&&$role!==$posted)$out[]=['billId'=>$b['id'],'billNo'=>$b['billNo']??'','party'=>$party,'postedService'=>$posted,'providerService'=>$role];
    }return $out;
}
