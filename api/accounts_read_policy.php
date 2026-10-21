<?php
declare(strict_types=1);

function acr_reports(array $user): bool {
    return tt_user_can_module_action($user,'Accounts','reports','View');
}
function acr_icon(array $user,array $icons): bool {
    foreach($icons as $icon)if(tt_user_can_module_action($user,'Accounts',$icon,'View'))return true;
    return false;
}
/** Unknown historical postings need the explicit financial-report reader. */
function acr_journal(array $user,array $store,array $journal,array $seen=[]): bool {
    $entity=(string)($journal['entity']??'');
    if(!in_array($entity,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($user,$entity,'View'))return false;
    if(acr_reports($user))return true;
    $icons=tt_post_workflow_icons($journal);
    $source=(string)($journal['meta']['originalSourceType']??$journal['sourceType']??'');
    $extras=['JV'=>['jv'],'JV_REVERSAL'=>['jv'],'EXPORT_FOREIGN_RECEIPT'=>['customer','cashbank'],
        'EXPORT_REALIZATION_BANK_DEBITS'=>['customer','cashbank'],'EXPORT_SALE_RECOGNIZED'=>['customer'],
        'EXPORT_INDENTOR_COMMISSION_ACCRUAL'=>['customer'],'EXPORT_RECEIPT_REVERSAL'=>['customer','cashbank']];
    $expenseSources=['DIRECT_EXPENSE_PAYMENT','DIRECT_EXPENSE_REVERSAL','SALARY_ALLOCATED_PAYMENT','SALARY_ALLOCATED_PAYMENT_REVERSAL','SALARY_ADVANCE_OPENING','SALARY_RECORD_CORRECTION_REVERSAL','MILL_BILL_LIABILITY','MILL_BILL_REVERSAL','CREDIT_CARD_BILL_CLASSIFICATION','PERSONAL_CASH_RECOVERY','PERSONAL_DEDUCTION_CORRECTION','PERSONAL_EXPENSE_ADJUSTMENT'];
    foreach($expenseSources as $type)$extras[$type]=['expenses'];
    foreach(['TG_REMITTANCE_CONFIRMED','TG_REMITTANCE_AMENDMENT_REVERSAL','TG_REMITTANCE_CONSOLIDATION_REVERSAL'] as $type)$extras[$type]=['tg'];
    if(!$icons)$icons=str_starts_with($source,'COMPANY_INVESTMENT_')?['assets']:($extras[$source]??[]);
    if($icons)return acr_icon($user,$icons);
    // A reversal retains the original workflow even in older posting formats.
    $parent=(string)($journal['reversalOf']??'');
    if($parent!==''&&!isset($seen[$parent])&&count($seen)<20&&is_array($store['journals'][$parent]??null)){
        $seen[$parent]=true;$prior=$store['journals'][$parent];
        return ($prior['entity']??'')===$entity&&acr_journal($user,$store,$prior,$seen);
    }
    return false;
}
/** Explicit transaction collections only: settings, audit logs and unknown arrays are private. */
function acr_collection_icons(string $collection,array $row): array {
    $groups=[
        'expenses'=>['expenses','generalExpenses','utilityInvoices','utilityBills','utilityPayments','expenseInvoices','expenseReimbursements','reimbursements','creditCardStatements','creditCardPayments','donationPayments','donations','rentPayments','rentAccruals','salaryPayments','salaryAccruals','salaryAdvances','salaryBatches'],
        'purchases'=>['purchaseSodas','commodityBills','bagSupplierBills','nonWovenBagSupplierBills','otherPurchases','productionInventoryTransfers','salesTaxRefunds'],
        'supplier'=>['supplierSettlements','supplierPayments','supplierAdvances','bagSupplierPayments','nonWovenBagSupplierPayments','otherSupplierPayments','brokerageTransactions','brokerageWhtDeposits'],
        'customer'=>['localSalesCandidates','localSalesPaymentCandidates','localCustomerReceipts','exportCandidates','exportTaxCertificates'],
        'cashbank'=>['bankDirectEntries','internalBankTransfers','bankTransfers','retentionRemittances','exportBankShortfalls'],
        'reconciliation'=>['bankReconciliations'],
        'transport'=>['transportBills','transportBillsV1'],
        'freight'=>['freightBills','freightBillsV1','freightAgreements'],
        'services'=>['serviceBills','exportServiceBillsV1'],
        'tg'=>['tgRemittances','tgIntercompanySettlements'],
        'assets'=>['investmentTransactions'],
        'jv'=>['jvDrafts'],
    ];
    if($collection==='exportReceipts')return ['customer','cashbank'];
    if($collection==='supplierBills'){
        $kind=strtoupper((string)($row['kind']??$row['billKind']??$row['category']??$row['sourceType']??''));
        if(str_contains($kind,'FREIGHT'))return ['freight'];
        if(str_contains($kind,'TRANSPORT'))return ['transport'];
        if(preg_match('/CLEARING|FUMIGATION|INSPECTION/',$kind))return ['services'];
        if(preg_match('/EXPENSE|UTILITY/',$kind))return ['expenses'];
        if(preg_match('/COMMODITY|BAG|PURCHASE/',$kind))return ['purchases'];
        return [];
    }
    foreach($groups as $icon=>$collections)if(in_array($collection,$collections,true))return [$icon];
    return [];
}
function acr_record(array $user,array $store,string $collection,string $key,array $row): bool {
    if($collection==='journals')return acr_journal($user,$store,$row);
    $icons=acr_collection_icons($collection,$row);
    if(!$icons&&$collection!=='supplierBills')return false;
    $entity=strtoupper((string)($row['entity']??$row['legalEntity']??''));
    if(!in_array($entity,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($user,$entity,'View'))return false;
    if(acr_reports($user)||acr_icon($user,$icons))return true;
    if($collection==='supplierBills')foreach((array)($row['postingJournalIds']??[]) as $id){$journal=$store['journals'][(string)$id]??null;if(is_array($journal)&&($journal['entity']??'')===$entity&&acr_journal($user,$store,$journal))return true;}
    return false;
}
function acr_project(array $user,array $store,bool $ledger=false): array {
    $visible=['revision'=>(int)($store['revision']??0)];
    foreach($store as $collection=>$records){
        if(!is_array($records))continue;
        foreach($records as $key=>$row)if(is_array($row)&&acr_record($user,$store,(string)$collection,(string)$key,$row))$visible[$collection][$key]=$row;
    }
    // Ledger helpers need names and correction links for visible postings;
    // these configuration records never become global search results.
    if($ledger){
        if(acr_icon($user,['expenses']))foreach(['salaryMasters','creditCardMasters','expensePersonalLinks'] as $collection){
            foreach((array)($store[$collection]??[]) as $key=>$row)if(is_array($row)&&in_array($row['entity']??'',['TTI','BRM','TG'],true)&&tt_user_can_access_entity($user,$row['entity'],'View'))$visible[$collection][$key]=$row;
        }
        foreach((array)($store['exMillPostReplacements']??[]) as $old=>$new)if(isset($visible['journals'][$old],$visible['journals'][$new]))$visible['exMillPostReplacements'][$old]=$new;
    }
    return $visible;
}
