<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/inventory_reconciliation.php';

/** Read-only management allowance. Never writes a stock movement or journal. */
function eg_report(array $values,array $entities,string $to,string $from='',string $mill=''):array {
    if(!mr_valid_date($to)||($from!==''&&(!mr_valid_date($from)||$from>$to)))throw new DomainException('Choose valid Ghati report dates.');
    $inward=0.;$undated=0;$missingWeight=0;
    $matches=static fn(array $r):bool=>in_array(tt_inv_entity($r),$entities,true)&&($mill===''||tt_inv_norm((string)(tt_inv_scope($r)['millName']??''))===tt_inv_norm($mill));
    foreach(tt_inv_rows($values,'tt30slips') as $r){
        if(!$matches($r))continue;
        // Rice arrivals only. Production, opening balances and Paltai are not arrivals.
        $commodity=strtoupper(trim((string)($r['commodity']??'')));
        $name=(string)($r['displayName']??$r['variety']??$r['product']??'');
        if(($commodity!==''&&!in_array($commodity,['RICE','IRRI','IRRI-6','BASMATI'],true))||preg_match('/\b(?:CORN|MAIZE|MAKAI|WHEAT|SESAME)\b/i',$name))continue;
        $date=(string)($r['unloadingDate']??$r['arrivalDate']??$r['date']??'');
        if(!mr_valid_date($date)){$undated++;continue;}
        if($date>$to||($from!==''&&$date<$from))continue;
        $kg=null;foreach(['karachiWeight','cargoGrossKg'] as $field)if(isset($r[$field])&&is_numeric($r[$field])&&(float)$r[$field]>0){$kg=(float)$r[$field];break;}
        if($kg===null){$missingWeight++;continue;}$inward+=$kg;
    }
    $events=array_values(array_filter(tt_inv_rows($values,'tt34ghati'),static function($r)use($matches,$from,$to){$date=(string)($r['date']??'');return $matches($r)&&mr_valid_date($date)&&$date<=$to&&($from===''||$date>=$from);}));
    usort($events,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date']));
    $filtered=$values;$filtered['tt34ghati']=tt_inv_json($events);$actual=0.;
    foreach($entities as $entity)$actual+=tt_inv_statement($filtered,$entity)['ghatiKg'];
    $estimated=round($inward*.0025,3);$actual=round($actual,3);
    return ['label'=>'Estimated Ghati','ratePct'=>.25,'from'=>$from,'to'=>$to,'inwardKg'=>round($inward,3),'estimatedKg'=>$estimated,'permanentKg'=>$actual,'remainingEstimateKg'=>round(max(0,$estimated-$actual),3),'totalKg'=>max($estimated,$actual),'undatedArrivals'=>$undated,'missingWeightArrivals'=>$missingWeight,'informationalOnly'=>true];
}
function eg_line(array $g):string {
    $n=static fn($v)=>number_format((float)$v,3,'.',',');
    return 'Estimated Ghati: '.$n($g['totalKg']).' kg (0.25% of '.$n($g['inwardKg']).' kg arrivals = '.$n($g['estimatedKg']).' kg; permanent Ghati '.$n($g['permanentKg']).' kg included; remaining estimate '.$n($g['remainingEstimateKg']).' kg)'.(($g['missingWeightArrivals']+$g['undatedArrivals'])?' — '.($g['missingWeightArrivals']+$g['undatedArrivals']).' arrivals need weight/date review':'');
}
