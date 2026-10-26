<?php
declare(strict_types=1);
// Owner-only read projections. This file never writes operational/accounting stores.
function bre_table(array $rows):string {
    $converted=[];
    foreach($rows as $row){$out=[];foreach((array)$row as $key=>$value){$label=ucwords((string)preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ',(string)$key));$out[$label]=$value;}$converted[]=$out;}
    return tt_backup_csv(tt_backup_table_from_rows($converted));
}
function bre_name(string $s):string {return tt_backup_safe_name($s);}
function tt_backup_readable_reports():array {
    $out=[];$warnings=[];$moduleWarnings=[];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $put=static function(string $name,array $rows)use(&$out):void{$out[$name]=bre_table($rows);};
    $accountsPath=TT_DATA_DIR.'/accounts.json';$chartPath=__DIR__.'/accounts/accounting_master_v1.json';
    $books=is_file($accountsPath)?json_decode((string)file_get_contents($accountsPath),true,512,JSON_THROW_ON_ERROR):[];
    $store=tt_read_store();$masters=(array)($store['masters']??[]);$ops=tt_backup_read_operations();$values=(array)($ops['values']??[]);
    // Match the committed SQL-backed source when it is configured.
    foreach(tt_backup_database_rows()['tt_operation_records']??[]as$row)$values[(string)$row['storage_key']]=(string)$row['payload'];
    $put('Accounts/Reports/Users and Roles.csv',array_map(static fn($u)=>array_intersect_key($u,array_flip(['id','full_name','username','role','active','location','permissions'])),(array)($store['users']??[])));
    if(is_file($chartPath)&&is_file($accountsPath)){
        require_once __DIR__.'/api/accounts_reports_core.php';
        $master=json_decode((string)file_get_contents($chartPath),true,512,JSON_THROW_ON_ERROR);
        foreach(['TTI','BRM','TG'] as $entity){
            $chart=sac_entity_chart($books,$entity);$subCatalog=sac_accounts($books,$entity);$hierarchy=[];
            foreach($chart as $code=>$r)$hierarchy[]=['entity'=>$entity,'code'=>(string)$code,'name'=>$r['name']??'','class'=>$r['class']??'','level'=>$r['level']??'','parentCode'=>$r['parent']??'','parentName'=>$chart[(string)($r['parent']??'')]['name']??''];
            foreach(sac_accounts($books,$entity)as$id=>$r)$hierarchy[]=['entity'=>$entity,'code'=>$r['parentCode']??'','name'=>$r['name']??'','class'=>'Subledger','level'=>'subledger','parentCode'=>$r['parentCode']??'','subledgerId'=>$id,'parentName'=>$chart[(string)($r['parentCode']??'')]['name']??'','parentSubledgerId'=>$r['parentId']??'','active'=>$r['active']??false];
            $put('Accounts/Reports/Chart of Accounts and Subledgers - '.$entity.'.csv',$hierarchy);
            foreach($entity==='TG'?['AED','USD']:['PKR'] as $currency){
                $suffix=$entity.' - '.$currency.' - '.$today;
                try{
                    $r=ar_build_report($books,$master,$entity,'0001-01-01',$today,$entity==='TG'&&$currency!=='AED'?$currency:null);
                    $put('Accounts/Reports/Trial Balance - '.$suffix.'.csv',$r['trialBalance']['rows']);
                    $put('Accounts/Reports/Trial Balance Totals - '.$suffix.'.csv',[['asOf'=>$today,'entity'=>$entity,'currency'=>$currency,'debit'=>$r['trialBalance']['totalDebit'],'credit'=>$r['trialBalance']['totalCredit'],'balanced'=>$r['trialBalance']['balanced'],'basis'=>$r['currencyBasis']]]);
                    $put('Accounts/Registers/Posted General Ledger - '.$suffix.'.csv',$r['generalLedger']);
                    $put('Accounts/Reports/Party Balances - '.$suffix.'.csv',$r['trialBalance']['partyRows']);
                    $put('Accounts/Reports/Receivables - '.$suffix.'.csv',array_values(array_filter($r['trialBalance']['partyRows'],static fn($x)=>str_starts_with((string)$x['account'],'12'))));
                    $put('Accounts/Reports/Payables - '.$suffix.'.csv',array_values(array_filter($r['trialBalance']['partyRows'],static fn($x)=>str_starts_with((string)$x['account'],'2'))));
                    $put('Accounts/Reports/Income and Expenses - All Recorded Periods - '.$suffix.'.csv',array_merge(array_map(static fn($x)=>['type'=>'Income']+$x,$r['profitAndLoss']['income']),array_map(static fn($x)=>['type'=>'Expense']+$x,$r['profitAndLoss']['expenses'])));
                    $balance=[];foreach(['assets','liabilities','equity'] as $section)foreach($r['balanceSheet'][$section]as$row)$balance[]=['section'=>$section]+$row;
                    $put('Accounts/Reports/Balance Sheet - '.$suffix.'.csv',$balance);
                    foreach(array_merge($r['profitAndLoss']['warnings'],$r['balanceSheet']['warnings'])as$w)$warnings[]=['module'=>'Accounts','report'=>$suffix,'issue'=>is_string($w)?$w:json_encode($w)];
                    if($r['dataQuality']['skippedMalformedLines'])$warnings[]=['module'=>'Accounts','report'=>$suffix,'issue'=>'Malformed lines excluded: '.$r['dataQuality']['skippedMalformedLines']];
                    foreach($r['dataQuality']['unclassifiedBalances']as$w)$warnings[]=['module'=>'Accounts','report'=>$suffix,'issue'=>'Unclassified balance: '.json_encode($w)];
                    if(!$r['trialBalance']['balanced'])$warnings[]=['module'=>'Accounts','report'=>$suffix,'issue'=>'Trial balance is out of balance. No balancing figure was inserted.'];
                    $ledgerGroups=[];foreach($r['generalLedger']as$line)$ledgerGroups[$line['account']][]=$line;
                    foreach($ledgerGroups as $code=>$lines){$name=bre_name((string)($chart[$code]['name']??$code));$put('Accounts/Ledgers/'.$entity.' - '.$currency.'/'.$name.' - '.$code.'.csv',$lines);}
                    $effective=alv_amendment_books($books);$hidden=alv_hidden($effective,$today);$subs=[];$bank=[];$posting=[];
                    foreach((array)($effective['journals']??[])as$j){if(($j['entity']??'')!==$entity||!alv_posted($j)||($j['date']??'')>$today||isset($hidden[(string)($j['id']??'')]))continue;
                        if($entity==='TG'&&$currency!=='AED')$j=tgn_present($j,$currency,$today);
                        $posting[]=['date'=>$j['date']??'','postId'=>tt_accounts_public_post($effective,$j),'sourceType'=>$j['sourceType']??'','reference'=>$j['reference']??'','narration'=>sac_journal_narration($effective,$j),'status'=>$j['status']??''];
                        foreach((array)($j['lines']??[])as$l){if(!is_array($l))continue;$id=sac_line_subaccount_id($effective,$j,$l);$row=['date'=>$j['date']??'','postId'=>tt_accounts_public_post($effective,$j),'narration'=>sac_journal_narration($effective,$j)]+$l;
                            if($id!==''){$a=$subCatalog[$id]??[];$label=($a['name']??$l['subaccountName']??$l['subledger']??'Subledger').' - '.$id;$subs[$label][]=$row;}
                            if(isset($l['bankAccountId']))$bank[($l['bankName']??'Bank').' - '.$l['bankAccountId']][]=$row;
                            elseif(isset($l['cashAccountId']))$bank['Petty Cash - '.$l['cashAccountId']][]=$row;
                        }
                    }
                    $put('Accounts/Registers/Posting Register - '.$suffix.'.csv',$posting);
                    foreach($subs as$name=>$lines)$put('Accounts/Ledgers/'.$entity.' - '.$currency.'/Subledgers/'.bre_name($name).'.csv',$lines);
                    foreach($bank as$name=>$lines)$put('Accounts/Registers/'.$entity.' - '.$currency.'/'.bre_name($name).'.csv',$lines);
                }catch(DomainException $e){$warnings[]=['module'=>'Accounts','report'=>$suffix,'issue'=>$e->getMessage()];}
            }
        }
    }else $warnings[]=['module'=>'Accounts','report'=>'Financial reports','issue'=>'No account store/catalog in this snapshot. Saved input exports are retained.'];
    if(is_file(__DIR__.'/api/rice_ledger_core.php')){
        require_once __DIR__.'/api/rice_ledger_core.php';require_once __DIR__.'/api/bag_inventory_core.php';require_once __DIR__.'/api/bag_transfers_store.php';
        $scopes=[];foreach(TT_INV_SOURCE_KEYS as$key)foreach(tt_inv_rows($values,$key)as$row){$scope=tt_inv_scope($row);if(in_array($scope['entity'],['TTI','BRM'],true))$scopes[$scope['entity'].'|'.tt_inv_location($scope)]=$scope;}
        foreach((array)($masters['mills']??[])as$m){$v=array_values((array)($m['values']??[]));if(empty($v[0]))continue;foreach(['TTI','BRM']as$entity){$scope=['entity'=>$entity,'millName'=>(string)$v[0],'locationId'=>(string)($m['id']??'')];$scopes[$entity.'|'.tt_inv_location($scope)]=$scope;}}
        foreach($scopes as$scope){$suffix=$scope['entity'].' - '.bre_name($scope['millName']);
            $r=rl_report($values,$scope,['from'=>'','to'=>$today,'movement'=>'All']);
            $put('Mill/Reports/Stock Balances - '.$suffix.'.csv',$r['summaries']);$put('Mill/Registers/Stock Inwards and Outwards - '.$suffix.'.csv',$r['rows']);$put('Mill/Registers/Undated Stock Movements - '.$suffix.'.csv',$r['undated']);
            foreach($r['summaries']as$row)if($row['status']!=='Recorded quantities')$warnings[]=['module'=>'Mill','report'=>$suffix,'issue'=>$row['product'].': '.$row['status']];
            $recovery=mr_report(tt_inv_rows($values,'tt30prod'),['entity'=>$scope['entity'],'mill'=>$scope['millName'],'to'=>$today],$books);
            $summaryRows=[];foreach($recovery['groups']as$g)$summaryRows[]=array_intersect_key($g,array_flip(['key','inputKg','readyKg','byProductKg','outputKg','recoveryPct','readyRecoveryPct','differenceKg','inputComplete','status']));
            $put('Mill/Reports/Production and Recovery - '.$suffix.'.csv',$summaryRows);
            $inputs=[];$outputs=[];foreach(tt_inv_rows($values,'tt30prod')as$run){if(!tt_inv_same_scope($scope,$run))continue;$meta=['date'=>$run['date']??'','runId'=>$run['id']??'','shift'=>$run['shift']??'','mill'=>$scope['millName']];foreach(mr_input_rows($run)as$row)$inputs[]=$meta+$row;foreach(mr_output_rows($run)as$row)$outputs[]=$meta+$row;}
            $put('Mill/Registers/Production Inputs - '.$suffix.'.csv',$inputs);$put('Mill/Registers/Production Outputs - '.$suffix.'.csv',$outputs);
            $put('Mill/Registers/Arrivals - '.$suffix.'.csv',array_values(array_filter(tt_inv_rows($values,'tt30slips'),static fn($r)=>tt_inv_same_scope($scope,$r))));
            $location=bti_location($masters,$scope['millName'],(string)($scope['locationId']??''));
            if($location){$b=bti_project($values,$masters,$location+['entity'=>$scope['entity']],$today,$today,bts_state($values));$put('Mill/Reports/Bags Stock - '.$suffix.'.csv',array_values($b['lots']));$put('Mill/Registers/Bags Movements - '.$suffix.'.csv',$b['movements']);foreach($b['warnings']as$w)$warnings[]=['module'=>'Mill','report'=>'Bags - '.$suffix,'issue'=>$w];}
            else $warnings[]=['module'=>'Mill','report'=>'Bags - '.$suffix,'issue'=>'Physical location could not be identified unambiguously; stock was not invented.'];
        }
    }
    foreach(['groupManagementRecords'=>'Cashflows Costing and Management Reports','exporterDeals'=>'Exporter Sales and Settlements','managedAssets'=>'Assets Register','directorRemuneration'=>'Directors Remuneration'] as$key=>$name)if(isset($books[$key])){
        $put('Directors/Reports/'.$name.'.csv',array_values((array)$books[$key]));$out['Directors/Reports/'.$name.' - All Fields.csv']=tt_backup_field_csv($books[$key]);
    }
    foreach(['group-management.json','assets.json']as$file)if(is_file(TT_DATA_DIR.'/'.$file)){$data=json_decode((string)file_get_contents(TT_DATA_DIR.'/'.$file),true,512,JSON_THROW_ON_ERROR);$out['Directors/Reports/'.($file==='assets.json'?'Assets Register':'Saved Cashflows Costing and Management Reports').' - All Fields.csv']=tt_backup_field_csv($data);}
    if(is_file(TT_DATA_DIR.'/group-management.json')){
        $management=json_decode((string)file_get_contents(TT_DATA_DIR.'/group-management.json'),true,512,JSON_THROW_ON_ERROR);
        require_once __DIR__.'/api/group_daily_core.php';
        foreach(['CASHFLOW'=>'Cashflows','COSTING'=>'Costing','DAILY'=>'Daily Financial Position','ZAKAT'=>'Zakat Assessments']as$kind=>$name){$records=array_values(array_filter((array)($management['groupManagementRecords']??[]),static fn($r)=>($r['kind']??'')===$kind));$put('Directors/Reports/'.$name.'.csv',$records);$out['Directors/Reports/'.$name.' - All Fields.csv']=tt_backup_field_csv($records);foreach($records as$record){$base='Directors/Reports/'.$name.'/'.bre_name(($record['date']??'Undated').' - '.($record['id']??'Record'));try{$summary=$kind==='DAILY'?gd_summary($record):gm_record_summary($record,array_replace($books,$management),$today);$scalars=[];foreach($summary as$key=>$value){if(!is_array($value))$scalars[]=['item'=>$key,'value'=>$value];else $put($base.'/'.bre_name(ucwords((string)preg_replace('/(?<=[a-z])(?=[A-Z])/',' ',$key))).'.csv',array_is_list($value)?$value:array_map(static fn($k,$v)=>['item'=>$k,'value'=>$v],array_keys($value),array_values($value)));}$put($base.'/Summary.csv',$scalars);}catch(DomainException $e){$warnings[]=['module'=>'Directors','report'=>$base,'issue'=>$e->getMessage()];}}}
    }
    $raw=$values['transtrade_export_v3_operational']??'{}';$exports=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;
    foreach(['shipments'=>'Shipment Register','contracts'=>'Sales Contracts','customers'=>'Customer Register','invoices'=>'Invoice Register','bookings'=>'Booking Register'] as$key=>$name)if(isset($exports[$key]))$put('Exports/Registers/'.$name.'.csv',array_values((array)$exports[$key]));
    foreach($warnings as$w){$module=$w['module'];$moduleWarnings[$module][]=$w;}
    foreach(['Accounts','Mill','Exports','Directors']as$module){$put($module.'/Report Notes.csv',$moduleWarnings[$module]??[]);$out[$module.'/READ ME.txt']='Snapshot date: '.$today."\nReports and registers use saved source records. Review Report Notes.csv for missing or provisional inputs. Saved Input Records preserves all raw nested fields, including drafts and reversals; those are distinct from posted financial reports.\n";}
    return $out;
}
