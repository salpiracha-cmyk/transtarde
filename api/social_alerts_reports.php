<?php
declare(strict_types=1);

function tt_social_report_catalog(): array {
    return [
        'Mill'=>['mill-daily-recovery'=>'Daily Production and Recovery'],
        'Exports'=>['exports-loading'=>'Shipment Loading Progress'],
        'Accounts'=>['accounts-trial-balance'=>'Trial Balance by Company'],
        'Directors'=>['directors-trade-position'=>'Purchase and Sales Position'],
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

function tt_social_optional_report(string $id,DateTimeImmutable $now,array $ops,?array &$morning): array {
    $catalog=array_merge(...array_values(tt_social_report_catalog()));
    if(!isset($catalog[$id]))throw new DomainException('Choose a supported alert report.');
    $date=$now->format('Y-m-d');
    $entities='TTI + BRM + TG';
    $num=static fn($value,int $places=2)=>$value===null?'Review required':number_format((float)$value,$places);
    if($id==='mill-daily-recovery') {
        $date=$now->modify('-1 day')->format('Y-m-d');
        $entities='TTI + BRM';
        $runs=tg_rows($ops,'tt30prod');
        $tables=[];
        foreach(['TTI','BRM'] as $entity) {
            $report=mr_report($runs,['entity'=>$entity,'mode'=>'daily','from'=>$date,'to'=>$date]);
            $rows=[];
            foreach($report['groups'] as $g) {
                $rows[]=[$g['key'],$num($g['inputComplete']?$g['inputKg']:null,3),$num($g['readyKg'],3),$num($g['byProductKg'],3),$num($g['recoveryPct'],3),$g['status']];
            }
            $tables[]=tt_social_report_table($entity.' daily recovery',['Date','Input kg','Ready rice kg','By-products kg','Recovery %','Status'],$rows);
        }
    } elseif($id==='accounts-trial-balance') {
        require_once __DIR__.'/accounts_reports_core.php';
        require_once __DIR__.'/shipment_profitability_core.php';
        $books=sp_read_books(TT_DATA_DIR.'/accounts.json');
        $master=sp_read_books(dirname(__DIR__).'/accounts/accounting_master_v1.json');
        $tables=[];
        foreach(['TTI','BRM','TG'] as $entity) {
            $report=ar_build_report($books,$master,$entity,substr($date,0,4).'-01-01',$date);
            $trial=$report['trialBalance'];
            $rows=array_map(static fn($r)=>[$r['account'],$r['name'],$r['class'],$num($r['debit']),$num($r['credit'])],$trial['rows']);
            $rows[]=['','Total',$trial['balanced']?'Balanced':'Review required',$num($trial['totalDebit']),$num($trial['totalCredit'])];
            $tables[]=tt_social_report_table($entity.' trial balance | '.$report['currency'],['Account','Name','Class','Debit','Credit'],$rows);
        }
    } else {
        $morning??=tg_morning_pdf($date);
        $titles=match($id) {
            'exports-loading'=>['Shipments being loaded'],
            'directors-trade-position'=>['Remaining purchase SODAs','Pending sales - export and fellow exporter','Fellow-exporter sales and purchase coverage','Purchases against fellow-exporter sales'],
        };
        $tables=array_values(array_filter($morning['tables'],static fn($table)=>in_array($table['title'],$titles,true)));
        if(!$tables)throw new RuntimeException('The source report is unavailable.');
    }
    return ['entity'=>$entities,'title'=>$catalog[$id],'reference'=>'As of '.$date.' | Pakistan time',
        'filters'=>['from'=>$date,'to'=>$date],'tables'=>$tables];
}
