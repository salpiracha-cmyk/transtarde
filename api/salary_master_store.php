<?php
declare(strict_types=1);

// Salary Master is stored in the Accounts store so Accounts and the owner's
// Master Records screen always edit the same records.
function sm_file(): string { return TT_DATA_DIR . '/accounts.json'; }
function sm_seed_masters(): array {
    $path=dirname(__DIR__).'/accounts/salary_master_seed_v1.json';
    if (!is_file($path)) return [];
    $decoded=json_decode((string)file_get_contents($path),true);
    return is_array($decoded['salaryMasters']??null)?$decoded['salaryMasters']:[];
}
function sm_default_store(): array { return ['revision'=>0,'salaryMasters'=>sm_seed_masters()]; }
function sm_read_store(): array {
    tt_ensure_data_dir();
    if (!is_file(sm_file())) return sm_default_store();
    $h=fopen(sm_file(),'r');
    if ($h===false || !flock($h,LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try { $raw=stream_get_contents($h); } finally { flock($h,LOCK_UN); fclose($h); }
    $s=$raw?json_decode($raw,true):null;
    return is_array($s)?array_replace_recursive(sm_default_store(),$s):sm_default_store();
}
function sm_mutate(callable $callback): mixed {
    tt_ensure_data_dir();
    $h=fopen(sm_file(),'c+');
    if ($h===false || !flock($h,LOCK_EX)) throw new RuntimeException('Accounts storage unavailable.');
    try {
        rewind($h); $raw=stream_get_contents($h); $s=$raw?json_decode($raw,true):null;
        if (!is_array($s)) $s=sm_default_store();
        $s=array_replace_recursive(sm_default_store(),$s);
        $result=$callback($s);
        $s['revision']=(int)($s['revision']??0)+1;
        rewind($h); ftruncate($h,0);
        fwrite($h,json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        fflush($h);
        return $result;
    } finally { flock($h,LOCK_UN); fclose($h); }
}
function sm_amount(mixed $value,string $label): float {
    $n=round((float)$value,2);
    if ($n<0) throw new InvalidArgumentException($label.' cannot be negative.');
    return $n;
}
function sm_date(string $value,string $label,bool $optional=false): string {
    $value=trim($value);
    if ($optional && $value==='') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)) throw new InvalidArgumentException($label.' is required.');
    return $value;
}
function sm_category(string $value): string {
    $value=strtoupper(trim($value));
    if (!in_array($value,['MILL_STAFF','OFFICE_STAFF','HOME_STAFF','DIRECTOR_REMUNERATION','HOME_MONTHLY_GIVE'],true)) throw new InvalidArgumentException('Select a valid salary group.');
    return $value;
}
function sm_treatment(string $value,string $category): string {
    $value=strtoupper(trim($value));
    if ($value==='') $value=$category==='HOME_MONTHLY_GIVE'?'FAMILY_ALLOCATION':'STAFF_COST';
    if (!in_array($value,['STAFF_COST','FAMILY_ALLOCATION','TO_CONFIRM'],true)) throw new InvalidArgumentException('Select a valid Accounts treatment.');
    return $value;
}
function sm_to_values(array $m): array {
    return [
        (string)($m['name']??''),(string)($m['entity']??''),(string)($m['category']??''),
        (string)($m['monthlyAmount']??0),(string)($m['zakatAmount']??0),(string)($m['otherAllowance']??0),
        (string)($m['effectiveFrom']??''),(string)($m['effectiveTo']??''),(string)($m['accountingTreatment']??''),
        !empty($m['productionCostEligible'])?'Yes':'No',(string)($m['status']??'Active'),(string)($m['notes']??'')
    ];
}
function sm_master_rows(): array {
    $rows=[];
    foreach ((array)(sm_read_store()['salaryMasters']??[]) as $id=>$m) {
        if (!is_array($m)) continue;
        $rows[]=['id'=>(string)($m['id']??$id),'values'=>sm_to_values($m)];
    }
    usort($rows,static fn($a,$b)=>strcmp((string)($a['values'][1]??''),(string)($b['values'][1]??''))?:strcmp((string)($a['values'][2]??''),(string)($b['values'][2]??''))?:strcasecmp((string)($a['values'][0]??''),(string)($b['values'][0]??'')));
    return $rows;
}
function sm_from_values(array $values,array $user,string $id=''): array {
    while (count($values)<12) $values[]='';
    $name=trim((string)$values[0]);
    if ($name==='') throw new InvalidArgumentException('Staff / person name is required.');
    $entity=strtoupper(trim((string)$values[1]));
    if (!in_array($entity,['TTI','BRM'],true)) throw new InvalidArgumentException('Select TTI or BRM as the legal book.');
    $category=sm_category((string)$values[2]);
    if ($category==='MILL_STAFF' && $entity!=='TTI') throw new InvalidArgumentException('Mill salaries belong to TTI only.');
    $net=sm_amount($values[3],'Net salary / remuneration');
    $zakat=sm_amount($values[4],'Zakat');
    $allowance=sm_amount($values[5],'Other allowance');
    if (round($net+$zakat+$allowance,2)<=0) throw new InvalidArgumentException('Enter a net salary, Zakat or other allowance amount.');
    $from=sm_date((string)$values[6],'Effective from');
    $to=sm_date((string)$values[7],'Effective to',true);
    if ($to!=='' && $to<$from) throw new InvalidArgumentException('Effective to cannot be before Effective from.');
    $treatment=sm_treatment((string)$values[8],$category);
    $status=in_array((string)$values[10],['Active','Inactive'],true)?(string)$values[10]:'Active';
    return ['id'=>$id,'entity'=>$entity,'name'=>$name,'category'=>$category,'monthlyAmount'=>$net,'zakatAmount'=>$zakat,'otherAllowance'=>$allowance,'effectiveFrom'=>$from,'effectiveTo'=>$to,'accountingTreatment'=>$treatment,'productionCostEligible'=>$category==='MILL_STAFF'&&$treatment==='STAFF_COST'&&strcasecmp((string)$values[9],'Yes')===0,'status'=>$status,'notes'=>trim((string)$values[11]),'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Super Admin')];
}
function sm_save_master(array $values,array $user,string $id=''): string {
    return sm_mutate(function(array &$s) use($values,$user,$id): string {
        if ($id==='') {
            $id='SALM-'.gmdate('Y').'-'.str_pad((string)(count((array)$s['salaryMasters'])+1),6,'0',STR_PAD_LEFT);
            while (isset($s['salaryMasters'][$id])) $id='SALM-'.gmdate('Y').'-'.random_int(100000,999999);
            $m=sm_from_values($values,$user,$id); $m['createdAt']=gmdate('c'); $m['createdBy']=$m['updatedBy'];
            $s['salaryMasters'][$id]=$m; return $id;
        }
        $old=$s['salaryMasters'][$id]??null;
        if (!is_array($old)) throw new InvalidArgumentException('Salary Master record not found.');
        $s['salaryMasters'][$id]=array_merge($old,sm_from_values($values,$user,$id));
        return $id;
    });
}
function sm_deactivate_master(string $id,array $user): void {
    sm_mutate(function(array &$s) use($id,$user): void {
        $m=$s['salaryMasters'][$id]??null;
        if (!is_array($m)) throw new InvalidArgumentException('Salary Master record not found.');
        $s['salaryMasters'][$id]['status']='Inactive';
        if (empty($s['salaryMasters'][$id]['effectiveTo'])) $s['salaryMasters'][$id]['effectiveTo']=gmdate('Y-m-d');
        $s['salaryMasters'][$id]['updatedAt']=gmdate('c');
        $s['salaryMasters'][$id]['updatedBy']=(string)($user['full_name']??$user['username']??'Super Admin');
    });
}
