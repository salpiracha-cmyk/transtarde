<?php
declare(strict_types=1);
require_once __DIR__.'/operations_scope.php';
require_once __DIR__.'/export_permission_policy.php';
/** Physical bag records inherit the saved PO destination; general invoices
 * without a physical source remain entity-scoped accounting documents. */
function bag_read_scope(array $store,array $user): array {
    if(os_location($user,''))return $store;
    $root=ep_operations_root();$locations=[];$shipmentLocations=[];
    foreach((array)($root['shipments']??[]) as $shipment)foreach((array)($shipment['bagOrders']??[]) as $po){
        $location=trim((string)($po['deliverTo']??''));$locations[(string)($po['poNo']??'')][$location]=true;
        $shipmentLocations[(string)($shipment['id']??'')][$location]=true;
    }
    $allowed=static function(array $targets)use($user):bool{if(!$targets)return false;foreach(array_keys($targets) as $location)if(!os_location($user,$location))return false;return true;};
    $store['bagPurchaseOrders']=array_filter((array)($store['bagPurchaseOrders']??[]),static fn($r)=>$allowed($locations[(string)($r['poNo']??'')]??[]));
    $store['bagSupplierBills']=array_filter((array)($store['bagSupplierBills']??[]),static function($r)use($allowed,$locations):bool{
        $po=trim((string)($r['poNo']??''));
        if($po!=='')return $allowed($locations[$po]??[]);
        return ($r['billPurpose']??'')==='GENERAL'||!empty($r['historical']);
    });
    $store['bagExportUsage']=array_filter((array)($store['bagExportUsage']??[]),static fn($r)=>$allowed($shipmentLocations[(string)($r['shipmentRef']??'')]??[]));
    return $store;
}
