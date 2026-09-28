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
foreach ([
    ['Paklink QA Forwarder','Freight Forwarder'],
    ['QA Clearing Agent','Clearing Agent'],
    ['QA Transporter','Transporter'],
    ['QA Fumigator','Fumigation'],
    ['QA Inspector','Inspection'],
] as $i=>$party) {
    $values=array_fill(0,13,'');
    $values[0]=$party[0];$values[1]='QAV'.($i+1);$values[2]=$party[1];$values[10]='Active';
    $masters['business_parties'][]=['id'=>'QA-VIDEO-PARTY-'.($i+1),'values'=>$values];
}
$banks=tt_master_json_array($masters['companies'][0]['values'][13]??'');
$banks[]=['id'=>'QA-VIDEO-BANK','accountType'=>'Company Account','label'=>'QA Video PKR','accountTitle'=>'Transtrade International','bankName'=>'QA Demonstration Bank','branch'=>'Karachi','country'=>'Pakistan','currency'=>'PKR','accountNumber'=>'QA-VIDEO-0001','iban'=>'PK00QAVIDEO00000000000001','swift'=>'','purpose'=>'Operating','visibility'=>'Accounts','notes'=>'Disposable local fixture','status'=>'Active','allowReceipts'=>true,'allowPayments'=>true];
$masters['companies'][0]['values'][13]=json_encode($banks,JSON_UNESCAPED_SLASHES);
$user=['id'=>92001,'full_name'=>'QA Video Operator','username'=>'qa.video','role'=>'QA Tester','location'=>'All authorized locations','permissions'=>['Accounts'=>'all','Exports'=>'all','Mill'=>'all'],'master_access'=>false,'master_permissions'=>[],'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)];
$admin=['id'=>92002,'full_name'=>'Local QA Admin','username'=>'qa.local.admin','role'=>'Super Admin','location'=>'Local QA','permissions'=>['Accounts'=>'all','Exports'=>'all','Mill'=>'all'],'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)];
file_put_contents(TT_STORE_FILE,json_encode(['users'=>[$user,$admin],'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'master_options'=>tt_default_master_options(),'audit'=>[]],JSON_THROW_ON_ERROR));
$ref='QA/TTI/LGT/07';$lot=$ref.'/L01';
$root=[
 'customers'=>[['id'=>'QA-LGT','name'=>'Ladoo General Trading LLC','code'=>'LGT','address'=>'Dubai, United Arab Emirates']],
 'contracts'=>[['id'=>'QA-CONTRACT-LGT','ref'=>$ref,'seller'=>'TTI','customerId'=>'QA-LGT','customer'=>'Ladoo General Trading LLC','pol'=>'Karachi Port, Pakistan','podPort'=>'Jebel Ali, United Arab Emirates','packings'=>[['containers'=>10,'size'=>20,'brand'=>'marvarid','weightPer'=>27]],'currency'=>'USD','paymentCode'=>'ADV_CAD']],
 'shipments'=>[[
   'id'=>'QA-SHIP-LGT-01','kind'=>'lot','seller'=>'TTI','contractRef'=>$ref,'lotId'=>$lot,'buyer'=>'Ladoo General Trading LLC','loadingProgrammeNo'=>'QA-LP-LGT-07',
   'loadingPlan'=>['loadingProgrammeNo'=>'QA-LP-LGT-07','shippingLine'=>'QA Shipping Line','intendedVessel'=>'ruby','voyage'=>'123','portOfLoading'=>'Karachi Port, Pakistan'],
   'millActuals'=>[['number'=>'QA-VIDEO-CONT-01','bags'=>1350,'netKg'=>27000],['number'=>'QA-VIDEO-CONT-02','bags'=>1350,'netKg'=>27000]],
   'customs'=>['invoiceNo'=>'QA-CUSTOMS-LGT-07','gdRefs'=>[['number'=>'QA-GD-LGT-07']]],
   'bl'=>['blNo'=>'QA-BL-LGT-07','vessel'=>'ruby','voyage'=>'123'],
   'commercial'=>['invoiceNo'=>$ref,'saved'=>true,'date'=>'2026-09-17','total'=>108000,'currency'=>'USD']
 ]],
 'fi'=>[],'accountsReceipts'=>[],'millSync'=>['newExportBags'=>[],'productionInstructions'=>[],'exportLoading'=>[ ['contractRef'=>$ref,'shipmentId'=>'QA-SHIP-LGT-01','processId'=>'QA-PROC-LGT','lotId'=>$lot,'loadingProgrammeNo'=>'QA-LP-LGT-07','plan'=>['allocations'=>[['packIndex'=>0,'name'=>'TTI Rice Mills','type'=>'TTI','containers'=>2,'weightPer'=>27]],'loadingProgrammeNo'=>'QA-LP-LGT-07'],'production'=>['qualityNotes'=>'QA reconstructed export allocation'],'sentAt'=>'2026-09-17T00:00:00Z'] ]]
];
$key='transtrade_export_v3_operational';
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>1,'values'=>[$key=>json_encode($root,JSON_THROW_ON_ERROR)],'meta'=>[$key=>['version'=>1,'updatedAt'=>gmdate('c'),'updatedBy'=>'QA Video','module'=>'Exports']]],JSON_THROW_ON_ERROR));
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>[],'supplierBills'=>[],'freightAgreements'=>[],'freightBillsV1'=>[],'exportServiceBillsV1'=>[],'transportBillsV1'=>[],'loadingProgrammes'=>['TTI|QA-LP-LGT-07'=>['id'=>'TTI|QA-LP-LGT-07','entity'=>'TTI','loadingProgrammeNo'=>'QA-LP-LGT-07','contractRef'=>$ref,'lotRef'=>$lot,'loadedContainers'=>2,'containerNumbers'=>['QA-VIDEO-CONT-01','QA-VIDEO-CONT-02']]]],JSON_THROW_ON_ERROR));
echo "Local QA video fixture ready.\n";
