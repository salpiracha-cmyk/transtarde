<?php
declare(strict_types=1);
// Disposable localhost-only walkthrough fixture. Never run against hosting.
if (getenv('QA_VIDEO_LOCAL') !== '1' || getenv('TT_DB_HOST')) {
    fwrite(STDERR, "Local QA video fixture only.\n"); exit(1);
}
require dirname(__DIR__, 2).'/auth_store.php';
$password=(string)getenv('QA_VIDEO_PASSWORD');
if (strlen($password)<12) throw new RuntimeException('Generate a local video password.');
tt_ensure_data_dir();
$masters=tt_default_masters();
$masters['mills'][]=['id'=>'QA-VIDEO-EXTERNAL-MILL','values'=>['QA Outside Mill','QA-EXT','External Mill','Karachi','','Active','']];
foreach ([
    ['Paklink QA Forwarder','Freight Forwarder'],
    ['QA Clearing Agent','Clearing Agent'],
    ['QA Transporter','Transporter'],
    ['QA Fumigator','Fumigation'],
    ['QA Inspector','Inspection'],
    ['QA Rice Supplier','Supplier'],
    ['QA Corn Supplier','Supplier'],
    ['QA Purchase Broker','Broker'],
] as $i=>$party) {
    $values=array_fill(0,13,'');
    $values[0]=$party[0];$values[1]='QAV'.($i+1);$values[2]=$party[1];$values[10]='Active';
    $masters['business_parties'][]=['id'=>'QA-VIDEO-PARTY-'.($i+1),'values'=>$values];
}
$companyValues=array_pad($masters['companies'][0]['values'],14,'');
$banks=tt_master_json_array($companyValues[13]??'');
$banks[]=['id'=>'QA-VIDEO-BANK','accountType'=>'Company Account','label'=>'QA Video PKR','accountTitle'=>'Transtrade International','bankName'=>'QA Demonstration Bank','branch'=>'Karachi','country'=>'Pakistan','currency'=>'PKR','accountNumber'=>'QA-VIDEO-0001','iban'=>'PK00QAVIDEO00000000000001','swift'=>'','purpose'=>'Operating','visibility'=>'Accounts','notes'=>'Disposable local fixture','status'=>'Active','allowReceipts'=>true,'allowPayments'=>true];
$companyValues[13]=json_encode($banks,JSON_UNESCAPED_SLASHES);
$masters['companies'][0]['values']=$companyValues;
$user=['id'=>92001,'full_name'=>'QA Video Operator','username'=>'qa.video','role'=>'QA Tester','location'=>'All authorized locations','permissions'=>['Accounts'=>'all','Exports'=>'all','Mill'=>'all'],'master_access'=>false,'master_permissions'=>[],'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)];
$admin=['id'=>92002,'full_name'=>'Local QA Admin','username'=>'qa.local.admin','role'=>'Super Admin','location'=>'Local QA','permissions'=>['Accounts'=>'all','Exports'=>'all','Mill'=>'all'],'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)];
file_put_contents(TT_STORE_FILE,json_encode(['users'=>[$user,$admin],'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'master_options'=>tt_default_master_options(),'audit'=>[]],JSON_THROW_ON_ERROR));
$ref='QA/TTI/LGT/07';$lot=$ref.'/L01';
$root=[
 'customers'=>[['id'=>'QA-LGT','name'=>'Ladoo General Trading LLC','code'=>'LGT','address'=>'Dubai, United Arab Emirates']],
 'contracts'=>[['id'=>'QA-CONTRACT-LGT','ref'=>$ref,'seller'=>'TTI','customerId'=>'QA-LGT','customer'=>'Ladoo General Trading LLC','pol'=>'Karachi Port, Pakistan','podPort'=>'Jebel Ali, United Arab Emirates','packings'=>[['containers'=>10,'size'=>20,'brand'=>'marvarid','weightPer'=>27]],'currency'=>'USD','paymentCode'=>'ADV_CAD','shippingLine'=>'QA Shipping Line','product'=>'Basmati Rice','qty'=>270,'received'=>true,'status'=>'Issued']],
 'shipments'=>[[ 'id'=>'QA-PROC-LGT','kind'=>'process','contractRef'=>$ref,'buyer'=>'Ladoo General Trading LLC','seller'=>'TTI','plannedQty'=>270,'bagOrders'=>[['id'=>'QA-BAG-ORDER']],'production'=>['sentToMill'=>true],'loading'=>['lots'=>[['lotId'=>$lot,'lotRecordId'=>'QA-SHIP-LGT-01','totalMT'=>54]],'draft'=>null] ],[
   'id'=>'QA-SHIP-LGT-01','kind'=>'lot','parentProcessId'=>'QA-PROC-LGT','plannedQty'=>54,'containers'=>2,'seller'=>'TTI','contractRef'=>$ref,'lotId'=>$lot,'buyer'=>'Ladoo General Trading LLC','loadingProgrammeNo'=>'QA-LP-LGT-07',
   'loadingPlan'=>['loadingProgrammeNo'=>'QA-LP-LGT-07','shippingLine'=>'QA Shipping Line','intendedVessel'=>'ruby','voyage'=>'123','portOfLoading'=>'Karachi Port, Pakistan'],
   'millActuals'=>[],
   'customs'=>['invoiceNo'=>'QA-CUSTOMS-LGT-07','gdRefs'=>[['number'=>'QA-GD-LGT-07']]],
   'bl'=>['blNo'=>'QA-BL-LGT-07','vessel'=>'ruby','voyage'=>'123'],
   'commercial'=>['invoiceNo'=>$ref,'saved'=>true,'date'=>'2026-09-17','total'=>108000,'currency'=>'USD']
 ]],
 'fi'=>[],'accountsReceipts'=>[],'millSync'=>['newExportBags'=>[],'productionInstructions'=>[],'exportLoading'=>[ ['contractRef'=>$ref,'shipmentId'=>'QA-SHIP-LGT-01','processId'=>'QA-PROC-LGT','lotId'=>$lot,'loadingProgrammeNo'=>'QA-LP-LGT-07','plan'=>['allocations'=>[['packIndex'=>0,'name'=>'TTI Rice Mills','type'=>'TTI','containers'=>2,'weightPer'=>27]],'loadingProgrammeNo'=>'QA-LP-LGT-07'],'production'=>['qualityNotes'=>'QA reconstructed export allocation'],'sentAt'=>'2026-09-17T00:00:00Z'] ]]
];
$key='transtrade_export_v3_operational';
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>1,'values'=>[$key=>json_encode($root,JSON_THROW_ON_ERROR)],'meta'=>[$key=>['version'=>1,'updatedAt'=>gmdate('c'),'updatedBy'=>'QA Video','module'=>'Exports']]],JSON_THROW_ON_ERROR));
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>[],'supplierBills'=>[],'exportCandidates'=>['EXP|QA-SHIP-LGT-01'=>['id'=>'EXP|QA-SHIP-LGT-01','entity'=>'TTI','candidateType'=>'CUSTOMER_EXPORT_SALE','reference'=>$ref,'counterparty'=>'Ladoo General Trading LLC','transactionCurrency'=>'USD','transactionAmount'=>108000,'functionalAmount'=>30240000,'journalId'=>'QA-CI-JRN-01','status'=>'Posted','meta'=>['contractRef'=>$ref,'customer'=>'Ladoo General Trading LLC','transactionCurrency'=>'USD','fiRefs'=>[],'gdRefs'=>[['number'=>'QA-GD-LGT-07']],'blNo'=>'QA-BL-LGT-07']]],'freightAgreements'=>[],'freightBillsV1'=>[],'exportServiceBillsV1'=>[],'transportBillsV1'=>[],'loadingProgrammes'=>['TTI|QA-LP-LGT-07'=>['id'=>'TTI|QA-LP-LGT-07','entity'=>'TTI','loadingProgrammeNo'=>'QA-LP-LGT-07','contractRef'=>$ref,'lotRef'=>$lot,'loadedContainers'=>2,'containerNumbers'=>['QAVU000001-6','QAVU000002-1']]]],JSON_THROW_ON_ERROR));
$qaAccounts=json_decode(file_get_contents(TT_DATA_DIR.'/accounts.json'),true,512,JSON_THROW_ON_ERROR);
$qaAccounts['purchaseSodas']=[
 'QA-SODA-RAW'=>['id'=>'QA-SODA-RAW','entity'=>'TTI','sodaNo'=>'26901','sodaDate'=>'2026-09-18','commodity'=>'RICE','productStage'=>'RAW','purchaseProductId'=>'purchase-products-rice-irri6-white-raw','broker'=>'QA Purchase Broker','supplierId'=>'QA-VIDEO-PARTY-6','party'=>'QA Rice Supplier','paymentTermType'=>'CASH','creditDays'=>0,'rate'=>125,'rateUnit'=>'KG','qtyFromKg'=>27000,'qtyToKg'=>27000,'expectedTrucks'=>1,'status'=>'Open'],
 'QA-SODA-READY'=>['id'=>'QA-SODA-READY','entity'=>'TTI','sodaNo'=>'26902','sodaDate'=>'2026-09-18','commodity'=>'RICE','productStage'=>'READY','purchaseProductId'=>'purchase-products-rice-irri6-white-ready','broker'=>'','supplierId'=>'QA-VIDEO-PARTY-6','party'=>'QA Rice Supplier','paymentTermType'=>'CASH','creditDays'=>0,'rate'=>155,'rateUnit'=>'KG','qtyFromKg'=>54000,'qtyToKg'=>54000,'expectedTrucks'=>2,'readyRoute'=>'EX_MILL','status'=>'Open']
];
foreach([['QA-RAW-POH-01','26901','VIDEO-RAW-TRUCK-01','QA-POH-01','RAW',27000,125,'QA Purchase Broker'],['EXMILL|QA-READY-01','26902','VIDEO-READY-CONT-01','VIDEO-READY-CONT-01','READY',27000,155,''],['EXMILL|QA-READY-02','26902','VIDEO-READY-CONT-02','VIDEO-READY-CONT-02','READY',27000,155,'']] as $index=>$item){
 [$source,$soda,$truck,$pohanch,$stage,$kg,$rate,$broker]=$item;
 $eventId='TTI|COMMODITY_RECEIPT_ACCEPTED|'.$source;$journalId='QA-PROV-'.($index+1);$amount=$kg*$rate;
 $qaAccounts['events'][$eventId]=['id'=>$eventId,'eventType'=>'COMMODITY_RECEIPT_ACCEPTED','entity'=>'TTI','sourceKey'=>$source,'journalId'=>$journalId,'commodity'=>'RICE'];
 $qaAccounts['journals'][$journalId]=['id'=>$journalId,'entity'=>'TTI','date'=>'2026-09-20','reference'=>$pohanch,'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>['soda'=>$soda,'pohanch'=>$pohanch,'truck'=>$truck,'broker'=>$broker,'party'=>'QA Rice Supplier','commodity'=>'RICE','variety'=>'IRRI-6','baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>$stage,'displayName'=>'IRRI-6 White '.$stage,'bags'=>540,'payableWeightKg'=>$kg,'grossRatePerKg'=>$rate,'katPaisaPerKg'=>0,'provisionalNetRatePerKg'=>$rate]];
}
$qaAccounts['journals']['QA-EXPORT-REVENUE']=['id'=>'QA-EXPORT-REVENUE','entity'=>'TTI','date'=>'2026-09-20','status'=>'Posted','reference'=>$ref,'lines'=>[['account'=>'1220','debit'=>30240000,'credit'=>0],['account'=>'4100','debit'=>0,'credit'=>30240000]],'totalDebit'=>30240000,'totalCredit'=>30240000,'meta'=>['shipmentId'=>'QA-SHIP-LGT-01']];
$qaAccounts['localSalesCandidates']=['TTI|LOCAL_SALE|QA-GP-01'=>['id'=>'TTI|LOCAL_SALE|QA-GP-01','entity'=>'TTI','sourceKey'=>'QA-GP-01','soda'=>'QA-LOCAL-SODA-01','saleDate'=>'2026-09-20','party'=>'QA Local Rice Buyer','broker'=>'','product'=>'IRRI-6 White Rice','productStage'=>'FINISHED','displayName'=>'IRRI-6 White Rice','loadedKg'=>1000,'bags'=>20,'ratePerKg'=>140,'amount'=>140000,'truck'=>'QA-LOCAL-TRUCK-01','gatePass'=>'QA-GP-01','status'=>'Pending Accounts Approval','createdAt'=>'2026-09-20T00:00:00Z','createdBy'=>'Disposable Mill fixture']];
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode($qaAccounts,JSON_THROW_ON_ERROR));
echo "Local QA video fixture ready.\n";
