<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_EXPORT_ACCOUNTS_FILE = TT_DATA_DIR . '/accounts.json';
const TT_EXPORT_MASTER_FILE = __DIR__ . '/../accounts/accounting_master_v1.json';
const TT_EXPORT_POLICY_FILE = __DIR__ . '/../accounts/revenue_policy_v1.json';

function exa_respond(array $data, int $status = 200): never {http_response_code($status);echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);exit;}
function exa_module_write(array $user, string $module): bool {if (($user['role'] ?? '') === 'Super Admin') return true;$p = $user['permissions'][$module] ?? null;if ($p === 'all') return true;if (!is_array($p)) return false;if (in_array('Create',$p,true) || in_array('Edit',$p,true)) return true;foreach ($p as $a) if (is_array($a) && (in_array('Create',$a,true) || in_array('Edit',$a,true))) return true;return false;}
function exa_json_file(string $path): array {$raw=is_file($path)?file_get_contents($path):false;$v=$raw?json_decode($raw,true):null;if(!is_array($v))throw new RuntimeException('Required Accounts configuration is unavailable.');return $v;}
function exa_default_store(): array {return ['revision'=>0,'journals'=>[],'events'=>[],'reminders'=>[],'masters'=>[],'commodityBills'=>[],'exportCandidates'=>[]];}
function exa_read(): array {tt_ensure_data_dir();if(!is_file(TT_EXPORT_ACCOUNTS_FILE))return exa_default_store();$h=fopen(TT_EXPORT_ACCOUNTS_FILE,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);} $s=$raw?json_decode($raw,true):null;return is_array($s)?array_replace_recursive(exa_default_store(),$s):exa_default_store();}
function exa_date(string $v,string $label='Date'): string {if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))exa_respond(['ok'=>false,'error'=>$label.' is required.'],422);return $v;}
function exa_positive(mixed $v,string $label): float {$n=round((float)$v,2);if($n<=0)exa_respond(['ok'=>false,'error'=>$label.' must be greater than zero.'],422);return $n;}
function exa_account_names(): array {$m=exa_json_file(TT_EXPORT_MASTER_FILE);$out=[];foreach((array)($m['chart']??[]) as $a)if(is_array($a)&&isset($a['code']))$out[(string)$a['code']]=(string)($a['name']??$a['code']);return $out;}
function exa_next_id(array $items,string $prefix): string {$n=count($items)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);$n++;}while(isset($items[$id]));return $id;}
function exa_line(string $account,float $dr,float $cr,array $names,array $extra=[]): array {if(!isset($names[$account]))exa_respond(['ok'=>false,'error'=>'Approved account '.$account.' is missing.'],422);return array_merge(['account'=>$account,'accountName'=>$names[$account],'debit'=>round($dr,2),'credit'=>round($cr,2)],$extra);}
function exa_candidate_id(string $entity,string $type,string $sourceKey): string {return $entity.'|'.$type.'|'.$sourceKey;}
function exa_clean_refs(mixed $v): array {if(!is_array($v))return [];return array_values(array_filter(array_map(static fn($x)=>trim((string)$x),$v),static fn($x)=>$x!==''));}

try {
    $user=tt_require_login();$policy=exa_json_file(TT_EXPORT_POLICY_FILE);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(!tt_user_can_open_module($user,'Accounts'))exa_respond(['ok'=>false,'error'=>'Accounts permission required.'],403);
        $s=exa_read();$entity=strtoupper(trim((string)($_GET['entity']??'')));$rows=array_values((array)$s['exportCandidates']);if($entity!=='')$rows=array_values(array_filter($rows,static fn($x)=>(string)($x['entity']??'')===$entity));usort($rows,static fn($a,$b)=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));exa_respond(['ok'=>true,'candidates'=>$rows,'policy'=>$policy,'serverNow'=>gmdate('c')]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')exa_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input')?:'',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))exa_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);$action=(string)($body['action']??'');

    if($action==='queue_candidate'){
        if(!exa_module_write($user,'Exports'))exa_respond(['ok'=>false,'error'=>'Exports Create or Edit permission required.'],403);
        $seller=strtoupper(trim((string)($body['sellerEntity']??'')));if(!in_array($seller,['TTI','TG'],true))exa_respond(['ok'=>false,'error'=>'Unsupported selling entity.'],422);
        $sourceKey=trim((string)($body['sourceKey']??''));if($sourceKey===''||strlen($sourceKey)>180)exa_respond(['ok'=>false,'error'=>'Stable export lot reference required.'],422);
        $onBoard=exa_date((string)($body['onBoardDate']??''),'Final B/L shipped-on-board date');$currency=strtoupper(trim((string)($body['currency']??'')));if(!preg_match('/^[A-Z]{3}$/',$currency))exa_respond(['ok'=>false,'error'=>'Valid transaction currency required.'],422);$amount=exa_positive($body['transactionAmount']??0,'Export transaction amount');$qty=round((float)($body['actualQtyMT']??0),3);if($qty<=0)exa_respond(['ok'=>false,'error'=>'Actual shipped quantity is required.'],422);
        $incoterm=strtoupper(trim((string)($body['incoterm']??'OTHER')))?:'OTHER';$customer=trim((string)($body['customer']??''));$contract=trim((string)($body['contractRef']??''));$lot=trim((string)($body['lotRef']??$sourceKey));$fi=exa_clean_refs($body['fiRefs']??[]);$gd=exa_clean_refs($body['gdRefs']??[]);$bl=trim((string)($body['blNo']??''));$tgInternal=round((float)($body['tgInternalValue']??0),2);
        $meta=['actualQtyMT'=>$qty,'incoterm'=>$incoterm,'customer'=>$customer,'contractRef'=>$contract,'lotRef'=>$lot,'blNo'=>$bl,'onBoardDate'=>$onBoard,'commercialInvoiceDate'=>trim((string)($body['commercialInvoiceDate']??'')),'fiRefs'=>$fi,'gdRefs'=>$gd,'product'=>trim((string)($body['product']??'')),'transactionCurrency'=>$currency,'sourceModule'=>'Exports'];
        tt_ensure_data_dir();$h=fopen(TT_EXPORT_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
        try{
            rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=exa_default_store();$store=array_replace_recursive(exa_default_store(),$store);
            $id=exa_candidate_id($seller,'CUSTOMER_EXPORT_SALE',$sourceKey);
            if(!isset($store['exportCandidates'][$id]))$store['exportCandidates'][$id]=['id'=>$id,'entity'=>$seller,'candidateType'=>'CUSTOMER_EXPORT_SALE','sourceKey'=>$sourceKey,'reference'=>$lot,'transactionCurrency'=>$currency,'transactionAmount'=>$amount,'suggestedRecognitionDate'=>$onBoard,'status'=>'Pending Accounting Recognition','controlConfirmed'=>false,'journalId'=>null,'costOfSalesStatus'=>'Pending inventory-cost layer','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Export Staff')];
            $linkedId=null;$tgPayableId=null;
            if($seller==='TG'&&$tgInternal>0){
                $linkedId=exa_candidate_id('TTI','TG_PAKISTAN_INTERCOMPANY',$sourceKey);$tgPayableId=exa_candidate_id('TG','TG_INTERCOMPANY_PAYABLE',$sourceKey);
                if(!isset($store['exportCandidates'][$linkedId]))$store['exportCandidates'][$linkedId]=['id'=>$linkedId,'entity'=>'TTI','candidateType'=>'TG_PAKISTAN_INTERCOMPANY','sourceKey'=>$sourceKey,'reference'=>$lot,'transactionCurrency'=>$currency,'transactionAmount'=>$tgInternal,'suggestedRecognitionDate'=>$onBoard,'status'=>'Pending Accounting Recognition','controlConfirmed'=>false,'journalId'=>null,'costOfSalesStatus'=>'Pending inventory-cost layer','linkedCandidateId'=>$id,'mirrorCandidateId'=>$tgPayableId,'counterparty'=>'TG','meta'=>array_merge($meta,['explicitTGInternalValue'=>$tgInternal]),'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Export Staff')];
                if(!isset($store['exportCandidates'][$tgPayableId]))$store['exportCandidates'][$tgPayableId]=['id'=>$tgPayableId,'entity'=>'TG','candidateType'=>'TG_INTERCOMPANY_PAYABLE','sourceKey'=>$sourceKey,'reference'=>$lot,'transactionCurrency'=>$currency,'transactionAmount'=>$tgInternal,'suggestedRecognitionDate'=>$onBoard,'status'=>'Pending Accounting Recognition','controlConfirmed'=>false,'journalId'=>null,'costOfSalesStatus'=>'Accounts cost classification required','linkedCandidateId'=>$linkedId,'customerCandidateId'=>$id,'counterparty'=>'TTI','meta'=>array_merge($meta,['explicitTGInternalValue'=>$tgInternal,'sourcePakistanEntity'=>'TTI','sourceRule'=>'Exact value from Exports; do not re-enter or infer']), 'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Export Staff')];
                $store['exportCandidates'][$id]['linkedCandidateId']=$linkedId;$store['exportCandidates'][$id]['tgPayableCandidateId']=$tgPayableId;$store['exportCandidates'][$linkedId]['mirrorCandidateId']=$tgPayableId;
            }
            $store['revision']=(int)($store['revision']??0)+1;rewind($h);if(!ftruncate($h,0))throw new RuntimeException('Accounts storage could not be updated.');$enc=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(fwrite($h,$enc)===false)throw new RuntimeException('Accounts storage could not be written.');fflush($h);$candidate=$store['exportCandidates'][$id];$linked=$linkedId?$store['exportCandidates'][$linkedId]:null;$tgPayable=$tgPayableId?$store['exportCandidates'][$tgPayableId]:null;
        } finally {flock($h,LOCK_UN);fclose($h);}
        exa_respond(['ok'=>true,'candidate'=>$candidate,'linkedCandidate'=>$linked,'tgPayableCandidate'=>$tgPayable]);
    }

    if($action==='recognize_candidate'){
        if(!exa_module_write($user,'Accounts'))exa_respond(['ok'=>false,'error'=>'Accounts Create or Edit permission required.'],403);
        $id=trim((string)($body['candidateId']??''));if($id==='')exa_respond(['ok'=>false,'error'=>'Recognition candidate is required.'],422);$recognitionDate=exa_date((string)($body['recognitionDate']??''),'Recognition date');$functionalCurrency=strtoupper(trim((string)($body['functionalCurrency']??'')));if(!preg_match('/^[A-Z]{3}$/',$functionalCurrency))exa_respond(['ok'=>false,'error'=>'Functional / reporting currency is required.'],422);$rate=exa_positive($body['functionalRate']??0,'Functional-currency conversion rate');$names=exa_account_names();
        tt_ensure_data_dir();$h=fopen(TT_EXPORT_ACCOUNTS_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
        try{
            rewind($h);$raw=stream_get_contents($h);$store=$raw?json_decode($raw,true):null;if(!is_array($store))$store=exa_default_store();$store=array_replace_recursive(exa_default_store(),$store);$c=$store['exportCandidates'][$id]??null;if(!is_array($c))exa_respond(['ok'=>false,'error'=>'Recognition candidate not found.'],404);if(!empty($c['journalId']))exa_respond(['ok'=>false,'error'=>'This candidate has already been recognized.'],409);
            $entity=(string)$c['entity'];$expected=$policy['entities'][$entity]['functionalCurrency']??null;if(is_string($expected)&&$expected!==''&&$functionalCurrency!==$expected)exa_respond(['ok'=>false,'error'=>'Functional / reporting currency for '.$entity.' is '.$expected.'.'],422);$functionalAmount=round((float)$c['transactionAmount']*$rate,2);if($functionalAmount<=0)exa_respond(['ok'=>false,'error'=>'Functional-currency amount is invalid.'],422);$type=(string)$c['candidateType'];$costAccount=null;
            if($type==='TG_INTERCOMPANY_PAYABLE'){
                if(($body['controlConfirmed']??false)!==true)exa_respond(['ok'=>false,'error'=>'Confirm the TG intercompany cost classification before posting.'],422);$costAccount=trim((string)($body['costAccount']??''));if(!in_array($costAccount,['1310','1320','5100'],true))exa_respond(['ok'=>false,'error'=>'Select TG Purchased Commodity Inventory, Finished Goods Inventory or Commodity Cost of Sales.'],422);$dr=$costAccount;$cr='2500';
            } else {
                if(($body['controlConfirmed']??false)!==true)exa_respond(['ok'=>false,'error'=>'Control-transfer confirmation is required before revenue can be recognized.'],422);
                if($type==='CUSTOMER_EXPORT_SALE'){$dr='1210';$cr='4100';}
                elseif($type==='TG_PAKISTAN_INTERCOMPANY'){$dr='1240';$cr='4500';}
                else exa_respond(['ok'=>false,'error'=>'Unsupported recognition candidate type.'],422);
            }
            $nativeMeta=['transactionCurrency'=>$c['transactionCurrency'],'transactionAmount'=>$c['transactionAmount'],'nativeCurrency'=>$c['transactionCurrency'],'nativeAmount'=>$c['transactionAmount'],'currentCarryingRate'=>$rate,'currentCarryingAsOf'=>$recognitionDate,'counterparty'=>(string)($c['counterparty']??($c['meta']['customer']??''))];$lines=[exa_line($dr,$functionalAmount,0,$names,$type==='TG_INTERCOMPANY_PAYABLE'?['sourceCandidateId'=>$id,'costClassification'=>$costAccount]:[]),exa_line($cr,0,$functionalAmount,$names,$type==='TG_INTERCOMPANY_PAYABLE'?array_merge(['sourceCandidateId'=>$id,'liabilityNativeAmount'=>$c['transactionAmount'],'liabilityCurrency'=>$c['transactionCurrency']],$nativeMeta):[])];
            $jid=exa_next_id((array)$store['journals'],'AUTO');$meta=array_merge((array)($c['meta']??[]),['candidateId'=>$id,'transactionCurrency'=>$c['transactionCurrency'],'transactionAmount'=>$c['transactionAmount'],'functionalCurrency'=>$functionalCurrency,'functionalRate'=>$rate,'functionalAmount'=>$functionalAmount,'controlConfirmed'=>true,'controlConfirmationNote'=>trim((string)($body['controlConfirmationNote']??'')),'costAccount'=>$costAccount]);
            $sourceType=$type==='CUSTOMER_EXPORT_SALE'?'EXPORT_SALE_RECOGNIZED':($type==='TG_PAKISTAN_INTERCOMPANY'?'TG_INTERCOMPANY_PAKISTAN':'TG_INTERCOMPANY_PAYABLE_RECOGNIZED');$narration=$type==='CUSTOMER_EXPORT_SALE'?'Export sale recognized — '.(string)($c['reference']??''):($type==='TG_PAKISTAN_INTERCOMPANY'?'Pakistan → TG intercompany sale recognized — '.(string)($c['reference']??''):'TG intercompany payable recognized from Pakistan-linked Export — '.(string)($c['reference']??''));
            $store['journals'][$jid]=['id'=>$jid,'entity'=>$entity,'date'=>$recognitionDate,'sourceType'=>$sourceType,'reference'=>(string)($c['reference']??$id),'narration'=>$narration,'lines'=>$lines,'totalDebit'=>$functionalAmount,'totalCredit'=>$functionalAmount,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts'),'userId'=>(int)($user['id']??0),'reversalOf'=>null];
            $store['exportCandidates'][$id]['journalId']=$jid;$store['exportCandidates'][$id]['status']='Recognized / Posted';$store['exportCandidates'][$id]['controlConfirmed']=true;$store['exportCandidates'][$id]['recognitionDate']=$recognitionDate;$store['exportCandidates'][$id]['functionalCurrency']=$functionalCurrency;$store['exportCandidates'][$id]['functionalRate']=$rate;$store['exportCandidates'][$id]['functionalAmount']=$functionalAmount;$store['exportCandidates'][$id]['recognizedAt']=gmdate('c');$store['exportCandidates'][$id]['recognizedBy']=(string)($user['full_name']??$user['username']??'Accounts');
            if($type==='TG_INTERCOMPANY_PAYABLE'){$store['exportCandidates'][$id]['costAccount']=$costAccount;$store['exportCandidates'][$id]['currentCarryingRate']=$rate;$store['exportCandidates'][$id]['currentCarryingAsOf']=$recognitionDate;$store['exportCandidates'][$id]['paidNative']=0;$store['exportCandidates'][$id]['costOfSalesStatus']=$costAccount==='5100'?'Cost of sales recognized':'Inventory recognized — COGS still pending when sold';}
            $store['revision']=(int)($store['revision']??0)+1;rewind($h);if(!ftruncate($h,0))throw new RuntimeException('Accounts storage could not be updated.');$enc=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(fwrite($h,$enc)===false)throw new RuntimeException('Accounts storage could not be written.');fflush($h);$candidate=$store['exportCandidates'][$id];$journal=$store['journals'][$jid];
        } finally {flock($h,LOCK_UN);fclose($h);}
        exa_respond(['ok'=>true,'candidate'=>$candidate,'journal'=>$journal]);
    }
    exa_respond(['ok'=>false,'error'=>'Unknown export accounting action.'],422);
} catch(Throwable $e) {exa_respond(['ok'=>false,'error'=>'The export accounting update could not be completed.'],500);}

