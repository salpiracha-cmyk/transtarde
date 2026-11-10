<?php
declare(strict_types=1);
define('TT_DATA_DIR',sys_get_temp_dir().'/tt-social-fixture-'.bin2hex(random_bytes(6)));
function tt_ensure_data_dir(): void {if (!is_dir(TT_DATA_DIR)) mkdir(TT_DATA_DIR,0700);}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
require dirname(__DIR__,2).'/api/telegram_dispatch_core.php';
try {
 tt_ensure_data_dir();
 file_put_contents(tt_social_file('telegram','alerts'),json_encode(['token'=>'fixture','chatId'=>'-123']));
 check(tt_social_enabled('telegram',tt_social_config('telegram')),'Existing Telegram needs to stay enabled.');
 tt_social_update_config('telegram',['enabled'=>false]);
 check(tt_social_config('telegram')===['enabled'=>false,'token'=>'fixture','chatId'=>'-123'],'Turning off Telegram must retain credentials.');
 check(tg_dispatch(false,'telegram')===['disabled'=>true],'Disabled Telegram must return before loading sources.');
 $secret='https://discord.com/api/webhooks/'.str_repeat('1',18).'/'.str_repeat('a',40);
 check(str_contains(tt_discord_webhook_url($secret),'/v10/'),'Webhook uses canonical endpoint.');
 foreach ([$secret.'?wait=true',str_replace('discord.com','discord.com.example.com',$secret),str_replace('https:','http:',$secret)] as $invalid) {
  try {tt_discord_webhook_url($invalid);throw new RuntimeException('Accepted unsafe URL.');} catch(DomainException){}
 }
 tt_social_update_config('discord',['webhook'=>$secret,'channelId'=>'fixture','enabled'=>false]);
 check((fileperms(tt_social_file('discord','alerts'))&0777)===0600,'Discord credentials must be private.');
 check(tt_social_dispatch_all()===['platforms'=>['telegram'=>['disabled'=>true],'discord'=>['disabled'=>true]],'errors'=>0],'Disabled platforms should never touch alert sources.');
 tt_social_update_config('discord',['enabled'=>true]);
 check(tt_social_enabled('discord',tt_social_config('discord'))&&!tt_social_enabled('telegram',tt_social_config('telegram')),'Discord switch must be independent.');
 tt_social_update_config('discord',['enabled'=>false]);
 check(tt_social_config('discord')['webhook']===$secret,'Turning off Discord must retain the webhook.');
 check(tt_social_file('telegram','dispatch')!==tt_social_file('discord','dispatch'),'Delivery receipts need independent storage.');
 $before=new DateTimeImmutable('2026-11-10 10:59:59',new DateTimeZone('Asia/Karachi'));
 $due=$before->modify('+1 second');
 check(iterator_to_array(tt_social_optional_jobs(['exports-loading'],$before))===[],'Optional PDFs must wait until 11 a.m.');
 check(iterator_to_array(tt_social_optional_jobs([],$due))===[],'Unchecked reports must not be sent.');
 $jobs=iterator_to_array(tt_social_optional_jobs(['exports-loading','exports-loading'],$due));
 check(count($jobs)===1&&$jobs[0]['key']==='optional-report|exports-loading|2026-11-10','Selected reports need a stable, deduplicated daily identity.');
 try{tt_social_report_choices(['unknown-report']);throw new RuntimeException('Accepted unknown report.');}catch(DomainException){}
 tt_social_update_config('discord',['reports'=>['exports-loading']]);
 check(tt_social_config('discord')['reports']===['exports-loading']&&!isset(tt_social_config('telegram')['reports']),'Report selections must be independent.');
 $morning=['tables'=>[tt_social_report_table('Shipments being loaded',['Contract'],[['C1']]),tt_social_report_table('Bank balances',['Bank'],[['Private fixture']])]];
 $report=tt_social_optional_report('exports-loading',$due,[],$morning);
 check(count($report['tables'])===1&&$report['tables'][0]['rows']===[['C1']],'Loading PDF must include only its selected section.');
 $recovery=tt_social_optional_report('mill-daily-recovery',$due,['values'=>['tt30prod'=>'[]']],$morning);
 check($recovery['filters']['from']==='2026-11-09'&&array_column($recovery['recoveryReports'],'entity')===['TTI','BRM'],'Daily recovery needs the previous Pakistan calendar day for both mills.');
 require_once dirname(__DIR__,2).'/api/mill_recovery_pdf.php';
 foreach(tt_social_report_catalog()['Mill'] as $id=>[$title,,,$mode]) {
  $report=tt_social_optional_report($id,$due,['values'=>['tt30prod'=>'[]']],$morning);
  check($report['recoveryReports'][0]['mode']===$mode&&str_contains(mr_pdf_render($report),'TRANSTRADE INTERNATIONAL'),'Mill delivery must use the selected source mode and company PDF.');
 }
 $root=['contracts'=>[],'shipments'=>[
  ['kind'=>'lot','contractRef'=>'C1','lotId'=>'L1','bl'=>['blNo'=>'BL-1','finalDocument'=>true]],
  ['kind'=>'lot','contractRef'=>'C2','cancelled'=>true],
 ]];
 $report=tt_social_optional_report('exports-bl-register',$due,['values'=>['transtrade_export_v3_operational'=>json_encode($root)]],$morning);
 check($report['tables'][0]['rows']===[['C1','L1','BL-1','','','Yes']],'B/L Register must use original non-cancelled lots.');
 echo "PASS backward-compatible Telegram defaults, independent settings and receipts, disabled worker, private credential files and restricted Discord URL\n";
} finally {
 foreach(glob(TT_DATA_DIR.'/*')?:[] as $file) unlink($file);
 rmdir(TT_DATA_DIR);
}
