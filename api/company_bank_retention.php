<?php
declare(strict_types=1);

/** The tick on a PKR bank creates a separate USD ledger, never changes that bank's currency. */
function tt_company_retention_banks(array $banks, array $values, array $previous=[]): array {
    $eligible=in_array(strtoupper(trim((string)($values[1]??''))),['TTI','BRM'],true)
        &&(strcasecmp(trim((string)($values[2]??'')),'Pakistan')===0||strcasecmp(trim((string)($values[3]??'')),'Pakistan')===0);
    // Linked ledger identities survive a stale editor and cannot be silently deleted.
    $ids=[];foreach($banks as $bank)if(is_array($bank))$ids[(string)($bank['id']??'')]=true;
    foreach($previous as $bank)if(is_array($bank)&&!empty($bank['retentionParentBankId'])&&!isset($ids[(string)$bank['id']]))$banks[]=$bank;
    $byId=[];$children=[];
    foreach($banks as $i=>$bank){
        if(!is_array($bank))throw new InvalidArgumentException('A bank account row is invalid.');
        $id=trim((string)($bank['id']??''));
        if($id===''||isset($byId[$id]))throw new InvalidArgumentException('Every bank account requires a unique stable ID.');
        $byId[$id]=$i;
        $parent=trim((string)($bank['retentionParentBankId']??''));
        if($parent!==''){
            if(isset($children[$parent]))throw new InvalidArgumentException('A bank may have only one linked retention account.');
            $children[$parent]=$i;
        }
    }
    foreach($children as $parent=>$i){
        if(!isset($byId[$parent]))throw new InvalidArgumentException('Keep the parent bank of a retention account; deactivate it instead of removing its history.');
        $p=$banks[$byId[$parent]];
        if(!$eligible||!empty($p['retentionParentBankId'])||($p['accountType']??'Company Account')!=='Company Account'||strtoupper((string)($p['currency']??'PKR'))!=='PKR'
            ||($banks[$i]['accountType']??'')!=='Company Account'||strtoupper((string)($banks[$i]['currency']??''))!=='USD')
            throw new InvalidArgumentException('A linked USD retention account requires a TTI or BRM Pakistan company PKR bank.');
        if(!empty($banks[$i]['isDefault']))throw new InvalidArgumentException('The linked retention ledger cannot replace the normal default bank.');
    }
    $parentIndexes=array_values($byId);
    foreach($parentIndexes as $i){
        if(!empty($banks[$i]['retentionParentBankId']))continue;
        $p=$banks[$i];$pkr=strtoupper((string)($p['currency']??'PKR'))==='PKR';
        $requested=!empty($p['retentionEnabled'])||($pkr&&!empty($p['retentionAccount']));
        if($requested&&(!$eligible||($p['accountType']??'Company Account')!=='Company Account'||!$pkr))
            throw new InvalidArgumentException('Select a TTI or BRM Pakistan company PKR bank for the linked USD retention account.');
        if(!$pkr)continue; // Existing physical foreign-currency retention accounts retain their identity.
        $banks[$i]['retentionEnabled']=$requested;
        $banks[$i]['retentionAccount']=false;
        $parent=(string)$p['id'];
        if($requested&&!isset($children[$parent])){
            if(trim((string)($p['accountNumber']??''))===''&&trim((string)($p['iban']??''))==='')
                throw new InvalidArgumentException('Complete the parent bank account number or IBAN before creating its retention account.');
            $id='bank-retention-'.substr(hash('sha256',strtoupper((string)$values[1]).'|'.$parent),0,24);
            if(isset($byId[$id]))throw new InvalidArgumentException('The retention account ID is already used by another bank.');
            $name=trim((string)($p['bankName']??''));
            $name=preg_replace('/\\s+(Limited|Ltd\\.?)$/i','',$name)??$name;
            if($name==='')throw new InvalidArgumentException('Enter the parent bank name.');
            $children[$parent]=count($banks);
            $banks[]=['id'=>$id,'retentionParentBankId'=>$parent,'bankName'=>$name.' Retention Account',
                'accountTitle'=>(string)($p['accountTitle']??$values[0]??''),'branch'=>(string)($p['branch']??''),
                'country'=>(string)($p['country']??'Pakistan'),'currency'=>'USD','accountNumber'=>'','iban'=>'','swift'=>'',
                'accountType'=>'Company Account','depositType'=>(string)($p['depositType']??''),
                'isDefault'=>false,'retentionAccount'=>true,'status'=>'Active'];
        }
        if(isset($children[$parent])){
            $child=$children[$parent];
            $banks[$child]['retentionAccount']=$requested;
            $banks[$i]['retentionBankId']=(string)$banks[$child]['id'];
        }
    }
    return array_values($banks);
}

/** Only a validated linked USD ledger can operate without its own account number. */
function tt_linked_retention_bank_ids(array $companies): array {
    $out=[];
    foreach($companies as $company){
        $v=(array)($company['values']??[]);
        if(!in_array(strtoupper(trim((string)($v[1]??''))),['TTI','BRM'],true)
            ||(strcasecmp(trim((string)($v[2]??'')),'Pakistan')!==0&&strcasecmp(trim((string)($v[3]??'')),'Pakistan')!==0))continue;
        $banks=json_decode((string)($v[13]??'[]'),true);if(!is_array($banks))continue;
        $byId=[];foreach($banks as $bank)if(is_array($bank))$byId[(string)($bank['id']??'')]=$bank;
        foreach($banks as $bank){
            if(!is_array($bank))continue;
            $p=$byId[(string)($bank['retentionParentBankId']??'')]??null;
            if(!$p||!empty($p['retentionParentBankId'])||($p['accountType']??'Company Account')!=='Company Account'
                ||strtoupper((string)($p['currency']??'PKR'))!=='PKR'||strcasecmp((string)($p['status']??'Active'),'Active')!==0
                ||(trim((string)($p['accountNumber']??''))===''&&trim((string)($p['iban']??''))===''))continue;
            if(($bank['accountType']??'')==='Company Account'&&strtoupper((string)($bank['currency']??''))==='USD'
                &&empty($bank['isDefault']))$out[(string)$bank['id']]=true;
        }
    }
    return $out;
}
