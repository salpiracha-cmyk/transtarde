<?php
declare(strict_types=1);
require_once __DIR__.'/other_exporter_bags_core.php';

/** Current instructed work only; historical sources and financial terms stay untouched. */
function msp_closed(array $row): bool {
    return !empty($row['cancelled'])||!empty($row['completed'])||!empty($row['closed'])||!empty($row['archived'])||
        (bool)preg_match('/^(completed|closed|cancelled|canceled|archived|deleted)$/i',trim((string)($row['status']??'')));
}
function msp_rows(array $values,array $books,array $scope,string $date): array {
    $root=json_decode((string)($values['transtrade_export_v3_operational']??'{}'),true,512,JSON_THROW_ON_ERROR);
    $rows=[];
    foreach((array)($root['millSync']['productionInstructions']??[]) as $instruction){
        $ref=(string)($instruction['contractRef']??'');
        $matches=array_values(array_filter((array)($root['contracts']??[]),static fn($c)=>(string)($c['ref']??'')===$ref));
        if($ref===''||count($matches)!==1||msp_closed($matches[0])||msp_closed($instruction))continue;
        $contract=$matches[0];$production=(array)($instruction['production']??[]);
        $lots=array_values(array_filter((array)($root['shipments']??[]),static fn($s)=>($s['kind']??'')==='lot'&&($s['contractRef']??'')===$ref));
        $lotId=(string)($instruction['shipmentId']??'');$lotRef=(string)($instruction['lotId']??'');
        if($lotId!==''||$lotRef!==''){
            $linked=array_values(array_filter($lots,static fn($s)=>$lotId!==''?(string)($s['id']??'')===$lotId:(string)($s['lotId']??'')===$lotRef));
            if(count($linked)!==1||msp_closed($linked[0]))continue;
        }elseif($lots&&!array_filter($lots,static fn($s)=>!msp_closed($s)))continue;
        $entity=strtoupper((string)($contract['seller']??'TTI'));
        if($entity==='TG'){
            $process=array_values(array_filter((array)($root['shipments']??[]),static fn($s)=>($s['kind']??'')!=='lot'&&($s['contractRef']??'')===$ref));
            $entity=strtoupper((string)($process[0]['customs']['exporter']??$lots[0]['customs']['exporter']??'TTI'));
        }
        $mill=(string)($production['productionMillName']??$production['millName']??'TTI Rice Mills');
        if($entity!==$scope['entity']||bti_norm($mill)!==bti_norm($scope['millName']))continue;
        foreach((array)($instruction['packings']??[]) as $index=>$packing){
            $quantity=(float)($packing['containers']??0)*(float)($packing['weightPer']??0);
            $rows[]=['id'=>'EXPORT|'.$ref.'|'.$index,'sourceKind'=>'EXPORT','contractRef'=>$ref,'ref'=>$ref,
                'brand'=>(string)($packing['brand']??''),'variety'=>(string)($instruction['product']??$contract['product']??''),
                'quality'=>(string)($instruction['quality']??$contract['quality']??''),'quantityMT'=>$quantity,
                'packing'=>trim((string)($packing['size']??'').' '.(string)($contract['packingUnit']??'KG').' '.(string)($packing['type']??'')),
                'requiredBy'=>(string)($production['requiredBy']??''),'specifications'=>array_map(static fn($s)=>['name'=>(string)($s['name']??''),'value'=>(string)($s['value']??'')],(array)($instruction['specifications']??[])),
                'notes'=>(string)($production['qualityNotes']??''),'inspection'=>(string)($production['inspection']??$contract['inspection']??''),
                'dpp'=>(string)($production['dpp']??''),'sentAt'=>(string)($instruction['sentAt']??'')];
        }
    }
    foreach((array)($books['exporterDeals']??[]) as $deal){
        if(msp_closed($deal)||!oeb_active($deal,$root)||($deal['date']??'')>$date)continue;
        try{oeb_deal($books,$scope,(string)($deal['id']??''));}catch(DomainException){continue;}
        $safe=oeb_instruction_projection($deal);
        $rows[]=['id'=>'FELLOW|'.$safe['id'],'sourceKind'=>'FELLOW','ref'=>(string)($safe['reference']??''),
            'party'=>(string)($safe['party']??''),'brand'=>(string)($safe['brand']??''),'variety'=>(string)($safe['product']??''),
            'quantityMT'=>$safe['quantityMT']??null,'packing'=>(string)($safe['packing']??''),'requiredBy'=>'',
            'quality'=>(string)(($safe['specificationMode']??'')==='FULL'?($safe['fullSpecification']??''):($safe['product']??'')),
            'specifications'=>[],'notes'=>(string)($safe['productionNotes']??''),'sentAt'=>(string)($deal['productionReadyAt']??$deal['productionSentAt']??$deal['date']??'')];
    }
    return $rows;
}
