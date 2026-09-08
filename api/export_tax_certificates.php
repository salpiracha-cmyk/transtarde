<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_EXPORT_TAX_CERT_FILE = TT_DATA_DIR . '/accounts.json';

function etc_respond(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function etc_can_edit(array $user): bool {
    if(($user['role']??'')==='Super Admin')return true;$p=$user['permissions']['Accounts']??null;if($p==='all')return true;if(!is_array($p))return false;
    if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true))return true;
    foreach($p as $a)if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)||in_array('Approve',$a,true)))return true;return false;
}
function etc_tax_codes(): array {return ['EXP-WHT-FTR','EXP-WHT-FIXED','EXP-AWT-NTR'];}
function etc_default_store(): array {return ['revision'=>0,'journals'=>[],'exportTaxCertificates'=>[],'exportTaxCertificateMatches'=>[]];}
function etc_read(): array {tt_ensure_data_dir();if(!is_file(TT_EXPORT_TAX_CERT_FILE))return etc_default_store();$h=fopen(TT_EXPORT_TAX_CERT_FILE,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}$s=$raw?json_decode($raw,true):null;return is_array($s)?array_replace_recursive(etc_default_store(),$s):etc_default_store();}
function etc_entity(string $v): string {$v=strtoupper(trim($v));if(!in_array($v,['TTI','BRM'],true))etc_respond(['ok'=>false,'error'=>'Export tax certificates apply to Pakistan TTI / BRM books.'],422);return $v;}
function etc_date(string $v,string $label,bool $optional=false): string {if($optional&&$v==='')return '';if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))etc_respond(['ok'=>false,'error'=>$label.' is required.'],422);return $v;}
function etc_money(mixed $v,string $label): float {$n=round((float)$v,2);if($n<0)etc_respond(['ok'=>false,'error'=>$label.' cannot be negative.'],422);return $n;}
function etc_fy(string $date): string {$y=(int)substr($date,0,4);$m=(int)substr($date,5,2);$start=$m>=7?$y:$y-1;return sprintf('%d-%02d',$start,($start+1)%100);}
function etc_next_id(array $items,string $prefix): string {$n=count($items)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;}while(isset($items[$id]));return $id;}
function etc_tax_label(string $code): string {return match($code){'EXP-AWT-NTR'=>'Advance Withholding Tax — NTR / Adjustable','EXP-WHT-FIXED'=>'Fixed Withholding Tax — Non-adjustable','EXP-WHT-FTR'=>'Withholding Tax — FTR Historical',default=>$code};}
function etc_deductions(array $store,string $entity,string $fy): array {
    $rows=[];$matches=(array)($store['exportTaxCertificateMatches']??[]);$allowed=etc_tax_codes();
    foreach((array)($store['journals']??[]) as $jid=>$j){
        if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$entity)continue;$date=(string)($j['date']??'');if($date===''||etc_fy($date)!==$fy)continue;$meta=is_array($j['meta']??null)?$j['meta']:[];$bankId=(string)($meta['bankAccountId']??'');$bankName=(string)($meta['bankName']??'');
        $seen=[];$taxRows=is_array($meta['exportTaxDeductions']??null)?$meta['exportTaxDeductions']:[];
        foreach($taxRows as $i=>$t){if(!is_array($t))continue;$code=strtoupper(trim((string)($t['taxCode']??'')));if(!in_array($code,$allowed,true))continue;$amount=round((float)($t['amount']??0),2);if($amount<=0)continue;$sig=$code.'|'.number_format($amount,2,'.','');$seen[$sig]=true;$key=$jid.'|M|'.(string)$i;$rows[]=['key'=>$key,'journalId'=>$jid,'date'=>$date,'reference'=>(string)($j['reference']??''),'taxCode'=>$code,'taxLabel'=>etc_tax_label($code),'taxSection'=>(string)($t['taxSection']??''),'bankAccountId'=>(string)($t['bankAccountId']??$bankId),'bankName'=>(string)($t['bankName']??$bankName),'amount'=>$amount,'foreignCurrency'=>(string)($meta['transactionCurrency']??''),'foreignAmount'=>(float)($meta['transactionAmount']??0),'fiRefs'=>(array)($meta['fiRefs']??[]),'invoiceRefs'=>(array)($meta['invoiceRefs']??[]),'certificateId'=>(string)($matches[$key]??'')];}
        foreach((array)($j['lines']??[]) as $i=>$line){if(!is_array($line))continue;$code=strtoupper(trim((string)($line['exportDeductionCode']??'')));if(!in_array($code,$allowed,true))continue;$amount=round((float)($line['debit']??0),2);if($amount<=0)continue;$sig=$code.'|'.number_format($amount,2,'.','');if(isset($seen[$sig]))continue;$key=$jid.'|L|'.(string)$i;$rows[]=['key'=>$key,'journalId'=>$jid,'date'=>$date,'reference'=>(string)($j['reference']??''),'taxCode'=>$code,'taxLabel'=>etc_tax_label($code),'taxSection'=>'','bankAccountId'=>$bankId,'bankName'=>$bankName,'amount'=>$amount,'foreignCurrency'=>(string)($meta['transactionCurrency']??''),'foreignAmount'=>(float)($meta['transactionAmount']??0),'fiRefs'=>(array)($meta['fiRefs']??[]),'invoiceRefs'=>(array)($meta['invoiceRefs']??[]),'certificateId'=>(string)($matches[$key]??'')];}
    }
    usort($rows,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:strcmp((string)$a['key'],(string)$b['key']));return $rows;
}
function etc_payload(array $store,string $entity,string $fy): array {
    $ded=etc_deductions($store,$entity,$fy);$certs=[];$matches=(array)($store['exportTaxCertificateMatches']??[]);
    foreach((array)($store['exportTaxCertificates']??[]) as $id=>$c){if(!is_array($c)||($c['entity']??'')!==$entity||($c['financialYear']??'')!==$fy)continue;$matched=0.0;foreach($ded as $d)if(($matches[$d['key']]??'')===$id)$matched+=(float)$d['amount'];$amount=round((float)($c['certificateAmount']??0),2);$diff=round($amount-$matched,2);$status=$matched<=0.005?'Awaiting Receipt Match':(abs($diff)<=0.5?'Matched':'Difference / Review');$x=$c;$x['taxLabel']=etc_tax_label((string)($c['taxCode']??''));$x['matchedReceiptTaxAmount']=round($matched,2);$x['difference']=$diff;$x['status']=$status;$certs[]=$x;}
    usort($certs,static fn($a,$b)=>strcmp((string)($a['certificateDate']??''),(string)($b['certificateDate']??'')));
    $totalDed=round(array_sum(array_column($ded,'amount')),2);$totalCert=round(array_sum(array_column($certs,'certificateAmount')),2);$matched=round(array_sum(array_column($certs,'matchedReceiptTaxAmount')),2);
    $byType=[];foreach(etc_tax_codes() as $code)$byType[$code]=['label'=>etc_tax_label($code),'receiptTax'=>0.0,'certificateTotal'=>0.0,'matchedTax'=>0.0];foreach($ded as $d)$byType[$d['taxCode']]['receiptTax']=round($byType[$d['taxCode']]['receiptTax']+(float)$d['amount'],2);foreach($certs as $c){$code=(string)$c['taxCode'];$byType[$code]['certificateTotal']=round($byType[$code]['certificateTotal']+(float)$c['certificateAmount'],2);$byType[$code]['matchedTax']=round($byType[$code]['matchedTax']+(float)$c['matchedReceiptTaxAmount'],2);}
    return ['ok'=>true,'entity'=>$entity,'financialYear'=>$fy,'editable'=>true,'deductions'=>$ded,'certificates'=>$certs,'byTaxType'=>$byType,'summary'=>['receiptTax'=>$totalDed,'certificateTotal'=>$totalCert,'matchedTax'=>$matched,'unmatchedReceiptTax'=>round($totalDed-$matched,2),'certificateDifference'=>round($totalCert-$matched,2)]];
}

try{
    $user=tt_require_login();if(!tt_user_can_open_module($user,'Accounts'))etc_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){$entity=etc_entity((string)($_GET['entity']??'TTI'));$fy=trim((string)($_GET['fy']??''));if(!preg_match('/^\d{4}-\d{2}$/',$fy))$fy=etc_fy(gmdate('Y-m-d'));$store=etc_read();etc_respond(etc_payload($store,$entity,$fy));}
    if($_SERVER['REQUEST_METHOD']!=='POST')etc_respond(['ok'=>false,'error'=>'Method not allowed.'],405);if(!etc_can_edit($user))etc_respond(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
    $body=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))etc_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action']??'');$entity=etc_entity((string)($body['entity']??''));$fy=trim((string)($body['financialYear']??''));if(!preg_match('/^\d{4}-\d{2}$/',$fy))etc_respond(['ok'=>false,'error'=>'Financial year must be YYYY-YY.'],422);
    tt_ensure_data_dir();$h=fopen(TT_EXPORT_TAX_CERT_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=etc_default_store();$store=array_replace_recursive(etc_default_store(),$store);
        if($action==='save_certificate'){
            $id=trim((string)($body['id']??''));$bankId=trim((string)($body['bankAccountId']??''));$bankName=trim((string)($body['bankName']??''));$certNo=trim((string)($body['certificateNo']??''));if($bankName===''||$certNo==='')etc_respond(['ok'=>false,'error'=>'Bank and certificate number are required.'],422);
            $taxCode=strtoupper(trim((string)($body['taxCode']??'')));if(!in_array($taxCode,etc_tax_codes(),true))etc_respond(['ok'=>false,'error'=>'Select Advance WHT, Fixed WHT, or historical FTR WHT.'],422);
            if($id==='')$id=etc_next_id((array)$store['exportTaxCertificates'],'ETC');
            $store['exportTaxCertificates'][$id]=['id'=>$id,'entity'=>$entity,'financialYear'=>$fy,'bankAccountId'=>$bankId,'bankName'=>$bankName,'certificateNo'=>$certNo,'certificateDate'=>etc_date((string)($body['certificateDate']??''),'Certificate date'),'periodFrom'=>etc_date((string)($body['periodFrom']??''),'Period from',true),'periodTo'=>etc_date((string)($body['periodTo']??''),'Period to',true),'taxCode'=>$taxCode,'taxSectionAsPrinted'=>trim((string)($body['taxSectionAsPrinted']??'')),'certificateAmount'=>etc_money($body['certificateAmount']??0,'Certificate amount'),'fileReference'=>trim((string)($body['fileReference']??'')),'notes'=>trim((string)($body['notes']??'')),'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Accounts')];
        }elseif($action==='match_deductions'){
            $certificateId=trim((string)($body['certificateId']??''));$cert=$store['exportTaxCertificates'][$certificateId]??null;if(!is_array($cert)||($cert['entity']??'')!==$entity||($cert['financialYear']??'')!==$fy)etc_respond(['ok'=>false,'error'=>'Certificate was not found in this entity/year.'],404);
            $keys=is_array($body['deductionKeys']??null)?$body['deductionKeys']:[];$available=array_column(etc_deductions($store,$entity,$fy),null,'key');foreach($keys as $key){$key=(string)$key;if(!isset($available[$key]))continue;if(($available[$key]['taxCode']??'')!==($cert['taxCode']??''))etc_respond(['ok'=>false,'error'=>'A selected deduction uses a different tax type from the certificate. Fixed and advance withholding cannot be combined during certificate matching.'],422);if(($cert['bankAccountId']??'')!==''&&($available[$key]['bankAccountId']??'')!==''&&($available[$key]['bankAccountId']??'')!==($cert['bankAccountId']??''))etc_respond(['ok'=>false,'error'=>'A selected deduction belongs to a different bank account.'],422);$store['exportTaxCertificateMatches'][$key]=$certificateId;}
        }elseif($action==='unmatch_deductions'){
            $keys=is_array($body['deductionKeys']??null)?$body['deductionKeys']:[];foreach($keys as $key)unset($store['exportTaxCertificateMatches'][(string)$key]);
        }else etc_respond(['ok'=>false,'error'=>'Unknown tax certificate action.'],422);
        $store['revision']=(int)($store['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
    }finally{flock($h,LOCK_UN);fclose($h);}
    etc_respond(etc_payload($store,$entity,$fy)+['revision'=>$store['revision']]);
}catch(Throwable $e){etc_respond(['ok'=>false,'error'=>'The export tax certificate action could not be completed.'],500);}
