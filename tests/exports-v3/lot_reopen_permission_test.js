'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const source=fs.readFileSync(path.join(__dirname,'../../api/operations.mysql.php'),'utf8');
const start=source.indexOf('function operations_validate_lot_reopening('),end=source.indexOf('\nfunction operations_merge_export(',start);
assert.ok(start>=0&&end>start);assert.equal((source.match(/operations_validate_lot_reopening\(\$old/g)||[]).length,2,'both server stores enforce reopening authorization');
const script=`<?php
function operations_respond($body,$status=200){throw new RuntimeException($body['error'],$status);}
${source.slice(start,end)}
$old=json_encode(['shipments'=>[['id'=>'L1','kind'=>'lot','completed'=>true,'reopenedAt'=>'old']]]);
function run_case($old,$lot,$role,$module,$expected){try{operations_validate_lot_reopening($old,json_encode(['shipments'=>[$lot]]),['role'=>$role],$module);$actual=200;}catch(RuntimeException $e){$actual=$e->getCode();}if($actual!==$expected)throw new RuntimeException('Expected '.$expected.' got '.$actual);}
$lot=['id'=>'L1','kind'=>'lot','completed'=>false,'reopenedAt'=>'new','reopenReason'=>'Correction'];
run_case($old,$lot,'Staff','Exports',403);
run_case($old,$lot,'Staff','Super Admin',403);
run_case($old,$lot,'Super Admin','Exports',200);
$lot['reopenReason']=' ';run_case($old,$lot,'Super Admin','Exports',422);
$lot['reopenReason']='Correction';$lot['reopenedAt']='old';run_case($old,$lot,'Super Admin','Exports',422);
$lot['completed']=true;run_case($old,$lot,'Staff','Exports',200);
$lot['completed']=false;run_case($old,$lot,'Staff','Milling',200);run_case($old,$lot,'Staff','Accounts',200);
echo "PASS server reopening role, mandatory new reason/action, and existing Milling/Accounts merge routes\\n";
`;
const result=spawnSync('php',{input:script,encoding:'utf8'});if(result.error)throw result.error;assert.equal(result.status,0,result.stderr||result.stdout);console.log(result.stdout.trim());
