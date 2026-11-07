<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__,2).'/auth_store.php');
$start=strpos($src,'function tt_append_audit(');$end=strpos($src,'function tt_audit(',$start);eval(substr($src,$start,$end-$start));
$d=['audit'=>[['username'=>'salman','action'=>'Business sentinel','created_at'=>'2026-10-20T00:00:00Z']]];
for($i=0;$i<5100;$i++)tt_append_audit($d,2,'qa.assistant','Signed in');
if(count($d['audit'])!==1||$d['audit'][0]['action']!=='Business sentinel'||count($d['qaAudit'])!==5000)throw new RuntimeException('QA evicted a business entry or exceeded its own retention.');
$d=['audit'=>[['username'=>'qa.assistant','action'=>'old QA','created_at'=>'2026-10-20T00:00:00Z'],['username'=>'jazib','action'=>'Export saved','created_at'=>'2026-10-20T00:00:00Z']]];
tt_append_audit($d,1,'salman','Master amended');
if(count($d['audit'])!==2||count($d['qaAudit'])!==1||$d['audit'][1]['username']!=='jazib')throw new RuntimeException('Legacy mixed audit migration lost business data.');
echo "PASS QA isolation, bounded QA retention and legacy business history preservation\n";
