<?php
declare(strict_types=1);

function tt_social_report_catalog(): array {
    $production='/accounts/production-reports.php?mode=';
    $accounts='/accounts/?area=reports&statement=';
    $arp='/accounts/business-reports.php?report=arp&origin=directors';
    return [
        'Mill'=>[
            'mill-daily-production'=>['Daily Production',$production.'production','Previous day · TTI and BRM','production'],
            'mill-daily-recovery'=>['Daily Recovery',$production.'daily','Previous day · TTI and BRM','daily'],
            'mill-brand-recovery'=>['Brand Recovery',$production.'brand','Previous day, grouped by brand · TTI and BRM','brand'],
            'mill-contract-recovery'=>['Contract Recovery',$production.'contract','Previous day, grouped by contract · TTI and BRM','contract'],
        ],
        'Exports'=>[
            'exports-loading'=>['Shipment Loading Progress',$arp,'Open loading programmes as of today'],
            'exports-bl-register'=>['B/L Register','/module.php?id=exports&report=bl','All non-cancelled export lots'],
        ],
        'Accounts'=>[
            'accounts-trial-balance'=>['Trial Balance',$accounts.'tb','Calendar year to date · each company'],
            'accounts-profit-loss'=>['Profit & Loss',$accounts.'pl','Financial year to date · each company'],
            'accounts-balance-sheet'=>['Balance Sheet',$accounts.'bs','As of today · each company'],
        ],
        'Directors'=>[
            'directors-trade-position'=>['Purchase and Sales Position',$arp,'Open SODAs and pending sales from the ARP Morning Overview'],
            'directors-importer-receivables'=>['Importer Receivables',$arp,'Issued final documents from the ARP Morning Overview'],
            'directors-bank-balances'=>['Bank Balances',$arp,'Group balances from the ARP Morning Overview'],
        ],
    ];
}

function tt_social_report_choices(array $selected): array {
    $catalog=array_merge(...array_values(tt_social_report_catalog()));
    foreach($selected as $id) {
        if(!is_string($id)||!isset($catalog[$id]))throw new DomainException('Choose reports from the available list.');
    }
    return array_values(array_unique($selected));
}

function tt_social_optional_jobs(array $selected, DateTimeImmutable $now): iterable {
    if($now->format('H:i')<'11:00')return;
    $date=$now->format('Y-m-d');
    foreach(tt_social_report_choices($selected) as $id) {
        yield ['key'=>'optional-report|'.$id.'|'.$date,'hash'=>'1','optionalReport'=>$id];
    }
}

function tt_social_report_table(string $title,array $headers,array $rows): array {
    $widths=array_fill(0,count($headers),intdiv(770,count($headers)));
    $widths[count($widths)-1]+=770-array_sum($widths);
    return ['title'=>$title,'headers'=>$headers,'widths'=>$widths,
        'rows'=>$rows?:[['No matching records',...array_fill(1,count($headers)-1,'')]]];
}

function tt_social_accounts_report_tables(string $id,array $report): array {
    $title=$report['entity'].' · '.$report['currency'];
    $amount=static fn($value)=>$value===null?'Review required':number_format((float)$value,2);
    if($id==='accounts-trial-balance') {
        $trial=$report['trialBalance'];
        $rows=array_map(static fn($r)=>[$r['account'],$r['name'],$r['class'],$amount($r['debit']),$amount($r['credit'])],$trial['rows']);
        $rows[]=['','Total',$trial['balanced']?'Balanced':'Review required',$amount($trial['totalDebit']),$amount($trial['totalCredit'])];
        return [tt_social_report_table($title,['Account','Name','Class','Debit','Credit'],$rows)];
    }
    $isProfit=$id==='accounts-profit-loss';
    $statement=$report[$isProfit?'profitAndLoss':'balanceSheet'];
    $sections=$isProfit?['income'=>'Income','expenses'=>'Expenses']:['assets'=>'Assets','liabilities'=>'Liabilities','equity'=>'Equity'];
    $rows=[];
    foreach($sections as $key=>$label) {
        foreach($statement[$key] as $row) $rows[]=[$label,$row['account'],$row['name'],$amount($row['amount'])];
    }
    if($isProfit) $rows[]=['Total','','Net profit / (loss)',$amount($statement['profit'])];
    else {
        $rows[]=['Total','','Assets',$amount($statement['totalAssets'])];
        $rows[]=['Total','','Liabilities and equity',$amount($statement['totalLiabilitiesAndEquity'])];
    }
    $tables=[tt_social_report_table($title,['Section','Account','Name','Amount'],$rows)];
    $notes=array_merge([$isProfit?$report['zakatReporting']['basis']:$statement['basis']],$statement['warnings']);
    $tables[]=tt_social_report_table($title.' · Basis and review notes',['Detail'],array_map(static fn($note)=>[$note],$notes));
    return $tables;
}

function tt_social_optional_report(string $id,DateTimeImmutable $now,array $ops,?array &$morning): array {
    $catalog=array_merge(...array_values(tt_social_report_catalog()));
    if(!isset($catalog[$id]))throw new DomainException('Choose a supported alert report.');
    [$title,,,$mode]=array_pad($catalog[$id],4,null);
    $date=$from=$now->format('Y-m-d');
    $entities='TTI + BRM + TG';
    $tables=[];
    $payload=[];
    if($mode!==null) {
        $date=$from=$now->modify('-1 day')->format('Y-m-d');
        $entities='TTI + BRM';
        $runs=tg_rows($ops,'tt30prod');
        $payload['recoveryReports']=[];
        foreach(['TTI','BRM'] as $entity) {
            $payload['recoveryReports'][]=mr_report($runs,['entity'=>$entity,'mode'=>$mode,'from'=>$from,'to'=>$date],stockValues:$ops['values']??[]);
        }
    } elseif(str_starts_with($id,'accounts-')) {
        require_once __DIR__.'/accounts_reports_core.php';
        require_once __DIR__.'/shipment_profitability_core.php';
        $books=sp_read_books(TT_DATA_DIR.'/accounts.json');
        $master=sp_read_books(dirname(__DIR__).'/accounts/accounting_master_v1.json');
        $from=$id==='accounts-profit-loss'
            ? ($now->format('n')<7?$now->modify('-1 year'):$now)->format('Y').'-07-01'
            : $now->format('Y').'-01-01';
        foreach(['TTI','BRM','TG'] as $entity) {
            $report=ar_build_report($books,$master,$entity,$from,$date,$entity==='TG'?'AED':null);
            array_push($tables,...tt_social_accounts_report_tables($id,$report));
        }
    } elseif($id==='exports-bl-register') {
        require_once __DIR__.'/business_reports_core.php';
        $root=br_root($ops);
        $rows=[];
        foreach($root['shipments'] as $lot) {
            if(($lot['kind']??'')!=='lot'||!empty($lot['cancelled']))continue;
            $bl=$lot['bl']??[];
            $rows[]=[$lot['contractRef']??'',$lot['lotId']??'',$bl['blNo']??'',$bl['onBoardDate']??'',$bl['vessel']??'',empty($bl['finalDocument'])?'No':'Yes'];
        }
        $tables[]=tt_social_report_table($title,['Contract','Lot','B/L','On Board','Vessel','Final Original'],$rows);
    } else {
        $morning??=tg_morning_pdf($date);
        $titles=match($id) {
            'exports-loading'=>['Shipments being loaded'],
            'directors-trade-position'=>['Remaining purchase SODAs','Pending sales - export and fellow exporter','Fellow-exporter sales and purchase coverage','Purchases against fellow-exporter sales'],
            'directors-importer-receivables'=>['Importer receivables - issued final documents'],
            'directors-bank-balances'=>['Bank balances'],
        };
        $tables=array_values(array_filter($morning['tables'],static fn($table)=>in_array($table['title'],$titles,true)));
        if(!$tables)throw new RuntimeException('The source report is unavailable.');
    }
    return ['entity'=>$entities,'title'=>$title,'reference'=>'As of '.$date.' | Pakistan time',
        'filters'=>['from'=>$from,'to'=>$date],'tables'=>$tables]+$payload;
}
