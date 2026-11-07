<?php
declare(strict_types=1);
require_once __DIR__.'/operations_scope.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
/** Resolve historical payment locations from saved Mill sales, never the request. */
function lss_location(array $row,array $store,array $values): string {
    $direct=trim((string)($row['stockLocation']??''));if($direct!=='')return $direct;
    $soda=(string)($row['soda']??'');$source=(string)($row['sourceKey']??'');$matches=[];
    foreach(tt_inv_rows($values,'tt35localsales') as $sale){
        if($soda===''||(string)($sale['soda']??'')!==$soda)continue;
        foreach((array)($sale['pay']??[]) as $payment)if($source==='LOCALPAY|'.$soda.'|'.(string)($payment['bridgeId']??'')){
            $location=trim((string)($sale['millName']??$sale['locationName']??$sale['mill']??$sale['stockLocation']??'TTI Rice Mills'));
            if($location!=='')$matches[$location]=true;
        }
    }
    if(count($matches)===1)return (string)array_key_first($matches);
    // Legacy records without a bridge link can only inherit an unambiguous
    // saved loading location for the same company and Soda.
    if(!$matches)foreach((array)($store['localSalesCandidates']??[]) as $sale)if(($sale['soda']??'')===$soda&&($sale['entity']??'')===($row['entity']??'')&&trim((string)($sale['stockLocation']??''))!=='')$matches[(string)$sale['stockLocation']]=true;
    return count($matches)===1?(string)array_key_first($matches):'';
}
function lss_allowed(array $user,array $row,array $store,array $values,string $action='View'): bool {
    return tt_user_business_entity($user,(string)($row['entity']??''),$action)&&os_location($user,lss_location($row,$store,$values));
}
