<?php
declare(strict_types=1);

/** Select Business Parties for new SODAs and preserve the identity of existing SODAs. */
function fe_parties(array $store, string $entity): array {
    $out = [];
    $aliases = [];
    foreach (dt_legacy_parties($entity) as $party) {
        if (!$party['isExporter']) continue;
        $partyAliases = (array)($party['aliases'][$entity] ?? []);
        unset($party['aliases']);
        $out[$party['id']] = $party;
        foreach ($partyAliases as $alias) $aliases[$alias] = $party;
    }
    foreach ((array)($store['exporterDeals'] ?? []) as $deal) {
        if (($deal['entity'] ?? '') !== $entity || ($deal['status'] ?? '') === 'Deleted') continue;
        $id = (string)($deal['partyId'] ?? '');
        if ($id === '' || isset($out[$id])) continue;
        $out[$id] = array_replace($aliases[$id] ?? ['name'=>(string)$deal['party']], [
            'id'=>$id, 'isExporter'=>false, 'historicalOnly'=>true,
        ]);
    }
    $out = array_values($out);
    usort($out, static fn($a,$b) => strnatcasecmp($a['name'],$b['name']));
    return $out;
}

/** Adopt legacy exporter masters without rewriting any commercial references. */
function fe_adopt_legacy_parties(array &$parties, array $legacy): int {
    $adopted = 0;
    foreach ($legacy as $exporter) {
        $id = (string)($exporter['id'] ?? '');
        $entity = (string)($exporter['entity'] ?? '');
        $name = trim((string)($exporter['name'] ?? ''));
        if ($id === '' || $name === '' || !in_array($entity,['TTI','BRM'],true)) continue;
        $matches = [];
        $alreadyLinked = false;
        foreach ($parties as $index => $party) {
            if (in_array($id,(array)($party['fellowExporterAliases'][$entity]??[]),true)) $alreadyLinked = true;
            if (($party['id']??'') === $id) {
                $matches = [$index];
                break;
            }
            if (tt_master_name_identity((string)($party['values'][0]??''),'business_parties') === tt_master_name_identity($name,'business_parties')) $matches[] = $index;
        }
        if ($alreadyLinked || count($matches)>1) continue;
        if (!$matches) {
            if (empty($exporter['active'])) continue;
            $values = array_fill(0,13,'');
            $values[0] = $name;
            $values[1] = (string)($exporter['code']??'');
            $values[5] = (string)($exporter['contact']??'');
            $values[10] = 'Active';
            $values[11] = (string)($exporter['notes']??'');
            $values[12] = json_encode(['accountingCompanies'=>[$entity]],JSON_THROW_ON_ERROR);
            $parties[] = ['id'=>$id,'values'=>$values,'importedFellowExporter'=>true];
            $matches = [array_key_last($parties)];
        }
        $party = &$parties[$matches[0]];
        $party['fellowExporterAliases'][$entity][] = $id;
        if (!empty($exporter['active'])) {
            $roles = tt_business_party_categories($party['values'][2]??'');
            if (!tt_business_party_has_category($party['values'][2]??'','Fellow Exporter')) $roles[] = 'Fellow Exporter';
            $party['values'][2] = implode('; ',$roles);
            foreach ([1=>'code',5=>'contact',11=>'notes'] as $field=>$legacyField) {
                if (trim((string)($party['values'][$field]??''))==='') $party['values'][$field]=(string)($exporter[$legacyField]??'');
            }
            if (!empty($party['importedFellowExporter'])) {
                $profile = json_decode((string)$party['values'][12],true,512,JSON_THROW_ON_ERROR);
                if (isset($profile['accountingCompanies']) && is_array($profile['accountingCompanies'])) {
                    $profile['accountingCompanies'] = array_values(array_unique([...$profile['accountingCompanies'],$entity]));
                    $party['values'][12] = json_encode($profile,JSON_THROW_ON_ERROR);
                }
            }
        }
        unset($party);
        $adopted++;
    }
    return $adopted;
}
function fe_delete_issue(array $s,array $d,array $nativeSales,array $bagSources=[]):string {
    if(($d['status']??'')==='Deleted')return 'This SODA has already been deleted.';
    if(!empty($d['loadings'])||!empty($d['settlementPosts'])||!empty($d['payments']))return 'This SODA has loading or financial history. Correct its linked loading, settlement or receipt through the original workflow first.';
    if(!empty($d['millSaleRef']))return 'This SODA is linked to an original Mill record. Correct that link before deleting it.';
    foreach($bagSources as $source)if(($source['exporterDealId']??'')===$d['id']&&!empty($source['received']))return 'Other exporter bag receipts are linked to this SODA. Preserve their original custody and production history.';
    foreach($nativeSales as $r)if(dt_native_parent_matches($d,$r))return 'A Mill loading or dispatch record is linked to this SODA. Correct the original Mill record first.';
    foreach(['purchaseSodas','purchaseSodasV2'] as $key)foreach((array)($s[$key]??[]) as $purchase)foreach((array)($purchase['buyingForAllocations']??[]) as $allocation)if(($allocation['targetId']??'')==='EXPORTER|'.$d['id'])return 'A purchase is allocated to this SODA. Remove or correct that original buying-for allocation first.';
    foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$d['entity']&&($j['meta']['exporterDealId']??'')===$d['id'])return 'This SODA has accounting history. Use the original posting correction workflow first.';
    return '';
}
function fe_delete_deal(array &$s,array $d,array $b,array $u,array $nativeSales,array $bagSources=[]):array {
    $issue=fe_delete_issue($s,$d,$nativeSales,$bagSources);if($issue!=='')throw new DomainException($issue);
    $reason=gm_text($b['reason']??'',500);if(strlen($reason)<5)throw new DomainException('Enter a deletion reason of at least five characters.');
    $before=$d;$d['status']='Deleted';$d['version']++;$d['deletedAt']=gmdate('c');$d['deletedBy']=$u['username']??'';$d['deletionReason']=$reason;
    $d['history'][]=['action'=>'delete_deal','before'=>array_diff_key($before,['history'=>true]),'reason'=>$reason,'by'=>$u['username']??'','at'=>gmdate('c')];$s['exporterDeals'][$d['id']]=$d;
    return ['id'=>$d['id'],'deleted'=>true];
}
