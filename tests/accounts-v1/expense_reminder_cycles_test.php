<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/expense_reminders.php';
function expectReminder(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function atReminder(string $date):DateTimeImmutable {return new DateTimeImmutable($date,new DateTimeZone('Asia/Karachi'));}
$store=['creditCardMasters'=>[],'creditCardStatements'=>[],'utilityMasters'=>[],'utilityPayments'=>[],'supplierBills'=>[],'supplierSettlements'=>[]];
// Same October statement / November due-date pattern reported by the owner.
foreach([['A','2026-11-04'],['F','2026-11-03'],['H','2026-11-02']] as [$id,$due]){
    $store['creditCardMasters'][$id]=['id'=>$id,'entity'=>'TTI','cardName'=>'Fixture '.$id,'status'=>'Active','startMonth'=>'2026-10','dueDay'=>4,'remindDays'=>7];
    $store['creditCardStatements'][$id]=['id'=>$id,'entity'=>'TTI','cardMasterId'=>$id,'statementMonth'=>'2026-10','statementDate'=>'2026-10-15','dueDate'=>$due,'paymentDate'=>'2026-10-28','status'=>'Paid','total'=>100];
}
$before=serialize($store);
expectReminder(tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28'))===[],'Paid October statements generated phantom November bills');
$future=array_column(tt_expense_monthly_reminders($store,'TTI','2026-11',atReminder('2026-10-28')),null,'masterId');
foreach(['A'=>'04','F'=>'03','H'=>'02'] as $id=>$day)expectReminder($future[$id]['dueDate']==='2026-12-'.$day&&$future[$id]['estimatedDueDate'],'Next statement must follow recorded cycle and due day, not stale master defaults');
$pending=$store;$pending['creditCardStatements']['F']['status']='Pending';
$rows=tt_expense_due_reminders($pending,'TTI',atReminder('2026-10-28'));
expectReminder(count($rows)===1&&$rows[0]['statementId']==='F'&&$rows[0]['month']==='2026-10'&&$rows[0]['dueDate']==='2026-11-03'&&!$rows[0]['estimatedDueDate'],'Real unpaid October statement must remain with its actual November due date');
$rows=tt_expense_due_reminders($store,'TTI',atReminder('2026-11-28'));
expectReminder(count($rows)===3&&!array_filter($rows,fn($r)=>$r['month']!=='2026-11'||!str_starts_with($r['dueDate'],'2026-12')),'Next genuine cycle still reminds on time');
$store['utilityMasters']['U']=['id'=>'U','entity'=>'TTI','utilityType'=>'ELECTRICITY','locationName'=>'Fixture Office','status'=>'Active','startMonth'=>'2026-10','dueDay'=>4,'remindDays'=>7];
$bill=['id'=>'B','entity'=>'TTI','expenseInvoice'=>true,'invoiceKind'=>'UTILITY','utilityMasterId'=>'U','billMonth'=>'2026-10','billDate'=>'2026-10-15','dueDate'=>'2026-11-02','vendor'=>'Fixture utility','supplierPayableTotal'=>1000,'status'=>'Posted'];
$store['supplierBills']['B']=$bill;
$rows=tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28'));
expectReminder(count($rows)===1&&$rows[0]['invoiceId']==='B'&&$rows[0]['dueDate']==='2026-11-02','Captured utility uses actual bill due date across months');
$store['supplierSettlements']['P']=['entity'=>'TTI','status'=>'Posted','chequeStatus'=>'Cleared','allocations'=>[['billId'=>'B','amount'=>500,'paidAmount'=>500]]];
expectReminder(count(tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28')))===1,'Partial utility payment must remain due');
$store['supplierSettlements']['P']['allocations'][0]['amount']=1000;$store['supplierSettlements']['P']['allocations'][0]['paidAmount']=1000;$store['supplierSettlements']['P']['chequeStatus']='Issued';
expectReminder(count(tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28')))===1,'Issued cheque must await clearance');
$store['supplierSettlements']['P']['chequeStatus']='Cleared';
expectReminder(tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28'))===[],'Fully paid utility generated another November reminder');
$store['supplierBills']['B2']=array_replace($bill,['id'=>'B2','billDate'=>'2026-10-01','dueDate'=>'2026-10-20']);
$rows=tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28'));
expectReminder(count($rows)===1&&$rows[0]['invoiceId']==='B2'&&$rows[0]['dueDate']==='2026-10-20','Newer paid invoice cannot conceal older unpaid invoice');
unset($store['supplierBills']['B2']);$store['supplierBills']['B']['status']='Cancelled';
$store['utilityPayments']['UP']=['id'=>'UP','entity'=>'TTI','utilityMasterId'=>'U','billMonth'=>'2026-10','paymentDate'=>'2026-10-28','amount'=>1000];
expectReminder(tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28'))===[],'Direct utility payment must clear its cycle without an invented future bill');
$store['utilityPayments']['UP']['status']='Deleted';
expectReminder(count(tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28')))===1,'Deleted utility payment cannot suppress reminder');
// A real future bill may remind early; a missing future forecast may not.
$futureStore=['utilityMasters'=>[['id'=>'U','entity'=>'TTI','utilityType'=>'GAS','status'=>'Active','startMonth'=>'2026-11','dueDay'=>2,'remindDays'=>7]],'supplierBills'=>['B'=>array_replace($bill,['billMonth'=>'2026-11','billDate'=>'2026-10-27'])]];
expectReminder(count(tt_expense_due_reminders($futureStore,'TTI',atReminder('2026-10-28')))===1,'Recorded early bill must remain visible before its billing month');
unset($futureStore['supplierBills']);expectReminder(tt_expense_due_reminders($futureStore,'TTI',atReminder('2026-10-28'))===[],'No unpaid future bill may be invented from a master alone');
$historical=$pending;$historical['creditCardStatements']['F']['statementMonth']='2026-09';$historical['creditCardStatements']['F']['dueDate']='2026-10-03';
$oldRows=tt_expense_due_reminders($historical,'TTI',atReminder('2026-10-28'));
expectReminder(count(array_filter($oldRows,fn($r)=>$r['statementId']==='F'&&$r['dueDate']==='2026-10-03'))===1,'Master setup month cannot hide a genuine older unpaid statement');
$historical=$futureStore;$historical['supplierBills']['B']=array_replace($bill,['billMonth'=>'2026-09','dueDate'=>'2026-10-02']);
expectReminder(count(tt_expense_due_reminders($historical,'TTI',atReminder('2026-10-28')))===1,'Master setup month cannot hide an older recorded utility invoice');
// Year / leap-month boundaries, another company and deleted records.
$edge=['creditCardStatements'=>[['entity'=>'TTI','cardMasterId'=>'A','statementMonth'=>'2026-12','dueDate'=>'2027-01-04','status'=>'Paid'],['entity'=>'BRM','cardMasterId'=>'A','statementMonth'=>'2027-01','dueDate'=>'2027-02-20','status'=>'Paid'],['entity'=>'TTI','cardMasterId'=>'A','statementMonth'=>'2027-01','dueDate'=>'2027-02-21','status'=>'Deleted']]];
expectReminder(tt_expense_cycle_due($edge,'TTI','2027-02',$store['creditCardMasters']['A'],'CREDIT_CARD')==='2027-03-04','Cycle crosses year without other-company/deleted contamination');
$edge['creditCardStatements']=[['entity'=>'TTI','cardMasterId'=>'A','statementMonth'=>'2028-01','dueDate'=>'2028-02-29','status'=>'Paid']];
expectReminder(tt_expense_cycle_due($edge,'TTI','2028-02',array_replace($store['creditCardMasters']['A'],['dueDay'=>31]),'CREDIT_CARD')==='2028-03-31','February cap must not permanently change a day-31 cycle');
$readonly=serialize($store);tt_expense_due_reminders($store,'TTI',atReminder('2026-10-28'));tt_expense_monthly_reminders($store,'TTI','2026-11');expectReminder(serialize($store)===$readonly,'Reminder reads must not change payments, masters, invoices or journals');
echo "PASS actual due cycles for all credit cards/utilities, paid and pending statements, partial/cleared utility payments, future forecasts, real early bills, invoice ordering, company/date boundaries and immutable reads\n";
