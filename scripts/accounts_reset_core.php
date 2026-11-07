<?php
declare(strict_types=1);

/** Preserve configuration and upstream proposals, clearing only Accounts postings/workflows. */
function tt_accounts_reset_store(array $old, string $resetId): array {
    $preserve = [
        'masters', 'commodityKatMaster', 'bankAccountSettings', 'salaryMasters',
        'rentMasters', 'utilityMasters', 'creditCardMasters', 'brokerageMaster',
        'bagTaxRates', 'inventoryCostRates', 'localSalesCostRates', 'transportMaster',
    ];
    $fresh = ['revision'=>(int)($old['revision']??0)+1];
    foreach ($preserve as $key) if(array_key_exists($key,$old)) $fresh[$key]=$old[$key];
    // Exports/Milling still own these source documents. Their old Accounts recognition
    // must be removed together with the journals, so a new receipt never pays a ghost balance.
    foreach (['exportCandidates','localSalesCandidates','localSalesPaymentCandidates'] as $key) {
        if(!isset($old[$key])||!is_array($old[$key]))continue;
        $fresh[$key]=[];
        foreach ($old[$key] as $id=>$candidate) {
            if(!is_array($candidate))continue;
            if(isset($candidate['meta'])&&is_array($candidate['meta'])){
                foreach(array_keys($candidate['meta']) as $metaKey)
                    if(preg_match('/(?:journalid|receiptid|paid|recognized|carrying)/i',(string)$metaKey))unset($candidate['meta'][$metaKey]);
            }
            unset($candidate['journalId'],$candidate['recognizedAt'],$candidate['recognizedBy'],
                $candidate['recognitionDate'],$candidate['functionalAmount'],$candidate['functionalRate'],
                $candidate['currentCarryingRate'],$candidate['currentCarryingAsOf'],
                $candidate['paidNative'],$candidate['controlConfirmed']);
            if(isset($candidate['status'])&&preg_match('/recogniz|post|paid/i',(string)$candidate['status']))
                $candidate['status']='Pending Accounting Recognition';
            $fresh[$key][$id]=$candidate;
        }
    }
    $fresh['lastAccountsResetId']=$resetId;
    $fresh['lastAccountsResetAt']=gmdate('c');
    return $fresh;
}

function tt_accounts_reset_export_root(array $root): array {
    $root['accountsReceipts']=array_values(array_filter((array)($root['accountsReceipts']??[]),static fn($row)=>!is_array($row)||((string)($row['_accountsReceiptId']??'')===''&&(string)($row['source']??'')!=='Accounts Bank Receipt')));
    return $root;
}
