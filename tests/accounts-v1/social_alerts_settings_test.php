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
 echo "PASS backward-compatible Telegram defaults, independent settings and receipts, disabled worker, private credential files and restricted Discord URL\n";
} finally {
 foreach(glob(TT_DATA_DIR.'/*')?:[] as $file) unlink($file);
 rmdir(TT_DATA_DIR);
}
