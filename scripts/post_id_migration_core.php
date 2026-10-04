<?php
declare(strict_types=1);

function tt_post_migration_year(string $legacyId, array $journal): int {
    if (preg_match('/^[A-Z][A-Z0-9_]*-(\d{4})-\d+$/i', $legacyId, $match)) return (int)$match[1];
    foreach (['date', 'createdAt'] as $field) {
        if (preg_match('/^(\d{4})-/', (string)($journal[$field] ?? ''), $match)) return (int)$match[1];
    }
    return (int)gmdate('Y');
}

function tt_post_migration_number(string $legacyId): ?int {
    if (!preg_match('/^[A-Z][A-Z0-9_]*-\d{4}-(\d+)$/i', $legacyId, $match)) return null;
    $number=(int)$match[1];
    return $number > 0 && $number <= 99999 ? $number : null;
}

function tt_post_migration_format(int $year, int $number): string {
    if ($number < 1 || $number > 99999) throw new RuntimeException('Yearly Post ID limit reached during migration.');
    return 'POST-'.$year.'-'.str_pad((string)$number, 5, '0', STR_PAD_LEFT);
}

function tt_post_migration_plan(array $accounts): array {
    $journals=is_array($accounts['journals'] ?? null) ? $accounts['journals'] : [];
    $used=[];$maximum=[];$legacy=[];
    foreach ($journals as $key=>$journal) {
        if (!is_array($journal)) throw new RuntimeException('A journal record is invalid.');
        $id=(string)($journal['id'] ?? $key);
        if ($id==='' || (string)$key!==$id) throw new RuntimeException('A journal storage key does not match its ID: '.(string)$key);
        if (preg_match('/^POST-(\d{4})-(\d{5})$/', $id, $match)) {
            if (isset($used[$id])) throw new RuntimeException('Duplicate Post ID found before migration: '.$id);
            $used[$id]=true;
            $maximum[(int)$match[1]]=max((int)($maximum[(int)$match[1]] ?? 0),(int)$match[2]);
        } else {
            $legacy[]=['key'=>(string)$key,'id'=>$id,'journal'=>$journal];
        }
    }
    usort($legacy,static function(array $a,array $b):int {
        $aAuto=str_starts_with($a['id'],'AUTO-')?0:1;$bAuto=str_starts_with($b['id'],'AUTO-')?0:1;
        return $aAuto<=>$bAuto ?: strcmp((string)($a['journal']['createdAt'] ?? $a['journal']['date'] ?? ''),(string)($b['journal']['createdAt'] ?? $b['journal']['date'] ?? '')) ?: strcmp($a['id'],$b['id']);
    });
    $map=[];
    foreach ($legacy as $entry) {
        $old=$entry['id'];$year=tt_post_migration_year($old,$entry['journal']);
        // AUTO IDs came from the first universal sequence and retain their
        // number. Module-local IDs are assigned above the universal maximum.
        $preferred=str_starts_with($old,'AUTO-')?tt_post_migration_number($old):null;
        if ($preferred !== null) {
            $candidate=tt_post_migration_format($year,$preferred);
            if (isset($used[$candidate])) $preferred=null;
        }
        if ($preferred === null) {
            $preferred=(int)($maximum[$year] ?? 0)+1;
            do {$candidate=tt_post_migration_format($year,$preferred++);} while(isset($used[$candidate]));
        }
        $used[$candidate]=true;$maximum[$year]=max((int)($maximum[$year] ?? 0),(int)substr($candidate,-5));
        if (isset($map[$old]) && $map[$old] !== $candidate) throw new RuntimeException('Duplicate legacy journal ID found: '.$old);
        $map[$old]=$candidate;
    }
    ksort($maximum);
    return ['map'=>$map,'maximumByYear'=>$maximum,'journalCount'=>count($journals)];
}

function tt_post_migration_is_link_field(string $field): bool {
    if($field==='journals'||$field==='legacyPostId')return false;
    return $field==='reversalOf'||(bool)preg_match('/(?:JournalIds?|Journals|PostIds?|Posts)$/i',$field);
}

function tt_post_migration_replace_links(mixed $value, array $map, bool $linkContext=false): mixed {
    if (is_string($value)) return $linkContext&&isset($map[$value])?$map[$value]:$value;
    if (!is_array($value)) return $value;
    $out=[];
    foreach ($value as $key=>$item) {
        $newKey=$linkContext&&is_string($key)&&isset($map[$key])?$map[$key]:$key;
        if (array_key_exists($newKey,$out)) throw new RuntimeException('Post ID migration would create a duplicate data key: '.$newKey);
        $childContext=$linkContext||(is_string($key)&&tt_post_migration_is_link_field($key));
        $out[$newKey]=tt_post_migration_replace_links($item,$map,$childContext);
    }
    return $out;
}

function tt_post_migration_apply(array $accounts, array $plan): array {
    $map=(array)($plan['map'] ?? []);
    if (!$map) return $accounts;
    $migrated=tt_post_migration_replace_links($accounts,$map);
    $journals=[];
    foreach ((array)($migrated['journals'] ?? []) as $oldKey=>$journal) {
        $newKey=$map[(string)$oldKey]??(string)$oldKey;
        if(isset($journals[$newKey]))throw new RuntimeException('Post ID migration would create a duplicate journal: '.$newKey);
        $journals[$newKey]=$journal;
    }
    $migrated['journals']=$journals;
    foreach ($map as $old=>$new) {
        if (!is_array($migrated['journals'][$new] ?? null)) throw new RuntimeException('Migrated journal is missing: '.$new);
        $migrated['journals'][$new]['id']=$new;
        $migrated['journals'][$new]['legacyPostId']=(string)$old;
    }
    $migrated['settings']=is_array($migrated['settings'] ?? null)?$migrated['settings']:[];
    $migrated['settings']['postIdSchema']=2;
    $migrated['settings']['postIdMigratedAt']=gmdate('c');
    $migrated['revision']=(int)($migrated['revision'] ?? 0)+1;
    return $migrated;
}

function tt_post_migration_validate(array $accounts, array $plan): void {
    $journals=is_array($accounts['journals'] ?? null)?$accounts['journals']:[];
    if (count($journals)!==(int)($plan['journalCount'] ?? -1)) throw new RuntimeException('Journal count changed during Post ID migration.');
    foreach ($journals as $key=>$journal) {
        if (!is_array($journal)||!preg_match('/^POST-\d{4}-\d{5}$/',(string)$key)||(string)($journal['id'] ?? '')!==(string)$key) {
            throw new RuntimeException('A migrated journal does not have a valid Post ID.');
        }
    }
    foreach ((array)($plan['map'] ?? []) as $old=>$new) {
        if (!isset($journals[$new])) throw new RuntimeException('Expected migrated Post ID is missing: '.$new);
    }
    if(tt_post_migration_replace_links($accounts,(array)($plan['map']??[]))!==$accounts)throw new RuntimeException('A legacy Post ID link remains after migration.');
}
