<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-readable-reports-'.bin2hex(random_bytes(7));mkdir($root,0700);
define('TT_DATA_DIR',$root);define('TT_STORE_FILE',$root.'/auth.json');
function tt_ensure_data_dir():void{}
function tt_read_store():array{return ['users'=>[['id'=>1,'username'=>'fixture','password_hash'=>'NEVER-IN-READABLE-REPORTS','role'=>'Super Admin']],'masters'=>['mills'=>[['id'=>'M1','values'=>['Fixture Mill','','','','','Active']]]]];}
function tt_master_options():array{return ['currencies'=>['PKR','AED','USD']];}
require __DIR__.'/../../backup_lib.php';require __DIR__.'/../../backup_report_exports.php';
function require_report(bool $ok,string $s):void{if(!$ok)throw new RuntimeException($s);}
$books=['revision'=>1,'journals'=>[
 'OPEN'=>['id'=>'OPEN','entity'=>'TTI','date'=>'2026-10-01','status'=>'Posted','lines'=>[['account'=>'1110','debit'=>100,'credit'=>0,'bankAccountId'=>'B1','bankName'=>'Fixture Bank'],['account'=>'3100','debit'=>0,'credit'=>100]]],
 'PAY'=>['id'=>'PAY','entity'=>'TTI','date'=>'2026-10-02','status'=>'Posted','lines'=>[['account'=>'6100','debit'=>25,'credit'=>0,'subaccountId'=>'S1'],['account'=>'1110','debit'=>0,'credit'=>25,'bankAccountId'=>'B1','bankName'=>'Fixture Bank']]],
 'DRAFT'=>['id'=>'DRAFT','entity'=>'TTI','date'=>'2026-10-02','status'=>'Draft','lines'=>[['account'=>'1110','debit'=>999,'credit'=>0]]]],
 'accountSubaccounts'=>['S1'=>['id'=>'S1','name'=>'Fixture Expense','parentCode'=>'6100','entity'=>'TTI','active'=>true]]];
$ops=['values'=>['tt30slips'=>json_encode([['id'=>'ARR1','entity'=>'TTI','millName'=>'Fixture Mill','date'=>'2026-10-01','payableWeight'=>100,'displayName'=>'Raw Rice']]),'tt30prod'=>json_encode([['id'=>'RUN1','entity'=>'TTI','millName'=>'Fixture Mill','date'=>'2026-10-02','recoveryVersion'=>1,'actualInputKg'=>60,'inputStockName'=>'Raw Rice','rows'=>[['product'=>'READY RICE','systemKg'=>40],['product'=>'Bran','systemKg'=>20]]]])]];
try{
 file_put_contents($root.'/accounts.json',json_encode($books));file_put_contents($root.'/operations.json',json_encode($ops));$before=[hash_file('sha256',$root.'/accounts.json'),hash_file('sha256',$root.'/operations.json')];
 $out=tt_backup_readable_reports();$date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
 $report=ar_build_report($books,json_decode((string)file_get_contents(__DIR__.'/../../accounts/accounting_master_v1.json'),true),'TTI','0001-01-01',$date);
 $trial=$out['Accounts/Reports/Trial Balance - TTI - PKR - '.$date.'.csv'];
 foreach($report['trialBalance']['rows']as$row)require_report(str_contains($trial,(string)$row['name']),'Trial names use canonical report engine');
 require_report($report['trialBalance']['totalDebit']===100.0&&$report['trialBalance']['balanced'],'Posted-only exact footings');
 $gl=$out['Accounts/Registers/Posted General Ledger - TTI - PKR - '.$date.'.csv'];require_report(!str_contains($gl,'DRAFT')&&str_contains($gl,'PAY'),'Drafts stay out of posted reports');
 require_report(isset($out['Accounts/Ledgers/TTI - PKR/Subledgers/Fixture Expense - S1.csv']),'Named subledgers');
 require_report(isset($out['Mill/Reports/Stock Balances - TTI - Fixture Mill.csv']),'Stock summary');
 require_report(str_contains($out['Mill/Registers/Arrivals - TTI - Fixture Mill.csv'],'ARR1'),'Arrivals named and retained');
 require_report(str_contains($out['Mill/Registers/Production Inputs - TTI - Fixture Mill.csv'],'RUN1'),'Production source linkage');
 require_report(!str_contains(implode('',array_values($out)),'NEVER-IN-READABLE-REPORTS'),'No user password hashes in readable reports');
 require_report($before===[hash_file('sha256',$root.'/accounts.json'),hash_file('sha256',$root.'/operations.json')],'All read projections leave original books/stock unchanged');
 echo "PASS canonical trial/ledger equality, draft exclusion, stock/production registers, named subledgers, secrets excluded and zero source writes\n";
}finally{foreach(glob($root.'/*')as$p)unlink($p);rmdir($root);}
