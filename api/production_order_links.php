<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/product_stage.php';
/** Read-only product identity; never infer a variety from the brand alone. */
function pol_parse_identity(string $text): ?array {
    if(!preg_match_all('/\b(IRRI[ -]?6|C[ -]?9|PK[ -]?386|1121|D[ -]?98|SUPER\s+KERNEL\s+BASMATI)\b/i',$text,$matches))return null;
    $names=array_unique(array_map(static fn($v)=>strtoupper(preg_replace('/[ -]+/','',$v)),$matches[1]));if(count($names)!==1)return null;$m=[null,$matches[1][0]];
    $types=0;foreach(['/\bWHITE\b/i','/\bPARBOIL(?:ED)?\b|\bSELLA\b/i','/\bSTEAM(?:ED)?\b/i'] as $pattern)if(preg_match($pattern,$text))$types++;if($types>1)return null;
    $base=strtoupper(preg_replace('/\s+/',' ',trim($m[1])));
    foreach(['IRRI'=>'IRRI-6','C'=>'C-9','PK'=>'PK-386','D'=>'D-98'] as $prefix=>$canonical)if(preg_match('/^'.$prefix.'[ -]?\d+$/',$base))$base=$canonical;
    if($base==='SUPER KERNEL BASMATI')$base='Super Kernel Basmati';
    $type=tt_product_type_from_name($text);if($type===''&&$base!=='Super Kernel Basmati')return null;
    $variety=$base.($type==='Parboiled / Sella'?' Parboil Rice':($type!==''?' '.$type.' Rice':''));
    return ['baseVariety'=>$base,'riceType'=>$type,'variety'=>$variety,'displayName'=>tt_product_display('RICE',$base,'FINISHED',$type)];
}
function pol_order_identity(array $order,array $values,array $scope): ?array {
    foreach(['productionVariety','productionStockName','product'] as $field){$identity=pol_parse_identity((string)($order[$field]??''));if($identity)return $identity+['stockName'=>$identity['displayName'].' — '.($order['brand']??''),'basis'=>'instruction'];}
    $exact=[];$brand=[];$runs=json_decode($values['tt30prod']??'[]',true)?:[];
    foreach($runs as $run){if(($run['entity']??'TTI')!==$scope['entity']||strcasecmp(trim((string)($run['millName']??$run['mill']??'TTI Rice Mills')),trim($scope['millName']))!==0)continue;
      foreach($run['rows']??[] as $row){if(!empty($row['noStockPost'])||!preg_match('/^Ready Rice\s*[—-]\s*(.+)$/iu',(string)($row['product']??''),$m)||strcasecmp(trim($m[1]),trim((string)($order['brand']??'')))!==0)continue;
        $identity=pol_parse_identity((string)($row['displayName']??''))??pol_parse_identity(trim(($row['baseVariety']??$run['baseVariety']??$run['variety']??'').' '.($row['riceType']??$run['riceType']??'')));
        if(!$identity)continue;$stock=($row['displayName']??$identity['displayName']).' — '.trim($m[1]);$identity+=['stockName'=>$stock,'basis'=>'saved production'];$brand[$stock]=$identity;
        $source=(string)($order['sourceId']??$order['id']??'');
        if($source!==''&&((($row['productionSodaId']??'')===$source||($row['exporterDealId']??'')===$source)||($order['sourceKind']??'')==='EXPORT'&&($row['contractRef']??'')===$source))$exact[$stock]=$identity;
      }
    }
    $matches=$exact?:$brand;return count($matches)===1?array_values($matches)[0]:null;
}
function pol_enrich_order(array $order,array $values,array $scope): array {
    $order['productionIdentity']=pol_order_identity($order,$values,$scope);
    $order['specification']=trim((string)($order['fullSpecification']??''))?:trim((string)($order['product']??''));return $order;
}
