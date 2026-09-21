<?php
declare(strict_types=1);
require_once __DIR__ . '/product_stage.php';

// Keep live credentials and master records outside public_html. Hostinger Git
// deployments replace the application directory, but must never replace the
// operational data created by Salman and his staff.
const TT_DATA_DIR = __DIR__ . '/../transtrade_private';
const TT_STORE_FILE = TT_DATA_DIR . '/auth.json';
const TT_AUTH_RATE_FILE = TT_DATA_DIR . '/auth-rate.json';
// High-entropy offline code. The public repository contains only a salted,
// deliberately slow password hash; the code itself is held by the owner.
const TT_ADMIN_RECOVERY_HASH = '$2y$12$wDBNVGUfS0iEbsurYsnff.kDAySgh2iRgBJ0wJWGnE/jR17Qp.Vl6';

function tt_default_masters(): array {
    $masters = [
        'companies'=>[
            ['id'=>'companies-1','values'=>['Transtrade International','TTI','Pakistan','Pakistan','Group Company; Pakistan Operating Entity; Exporter; Seller; Buyer; Accounting Entity','No','Primary Pakistan operating/export entity.']],
            ['id'=>'companies-2','values'=>['Buksh Rice Mills','BRM','Pakistan','Pakistan','Group Company; Mill / Processor; Seller; Buyer; Accounting Entity','No','Mill/processing entity and authorized document identity.']],
            ['id'=>'companies-3','values'=>['Trans Grains Foodstuff Trading L.L.C','TG','United Arab Emirates','Offshore','Group Company; Offshore Export Contracting; Intercompany; Accounting Entity','Yes','TG-linked group workflow. Keep Pakistan and offshore accounting/legal records separated while allowing authorized group-owner visibility.']],
        ],
        'commodities'=>[
            ['id'=>'commodities-1','values'=>['Rice','RICE','MT / KG','Yes','PSQCA PS:3342-2007 baseline + variety/contract profile','Variety-specific purchase KAT','Rice purchase / stock mappings','PSQCA lists PS:3342-2007 Rice (1st Revision). Basmati products also use TDAP Basmati GI identity requirements where applicable. Product/contract defect limits remain profile-specific; export specs never create purchase KAT automatically.']],
            ['id'=>'commodities-2','values'=>['Corn','CORN','MT / KG','Yes','Corn-specific','Corn-specific','Corn purchase / stock mappings','Uses the same expandable Soda framework as rice.']],
            ['id'=>'commodities-3','values'=>['Sesame Seed','SESAME','MT / KG','Yes','To configure','To configure','To configure','Future-ready commodity; partial setup is allowed.']],
        ],
        'product_settings'=>[
            ['id'=>'product-settings-1','values'=>['2025/2026']],
        ],
        'products'=>[
            ['id'=>'products-1','values'=>['Rice','IRRI-6','White Rice 5% Broken','IR6-W5','Pakistan','Active Transtrade default','6.0 mm','5% max','14% max','2.5% max','5% max','4% max','','0.8% max','0.5% max','1% max','2% max','Well milled; double-polished; well sortexed','Free from live insects, bad odour and rice fit for human consumption. New crop as stated in contract.','Transtrade / TG 2026 specimen working specification. 6.0 mm grain-length reference cross-checked against current Pakistan market benchmark.']],
            ['id'=>'products-2','values'=>['Rice','IRRI-6','White Rice 25% Broken','IR6-W25','Pakistan','Historical Transtrade reference','6.0 mm basis','25% max','14% max','6.5% max','12% max','','','1.2% max','0.8% max','','4% max combined red and/or undermilled','Reasonably well milled','Free from live insects, bad odour and rice fit for human consumption. 2/3 size and above counted as full grain on 6 mm basis.','Transtrade SILAC 2009 specimen. Kept as editable historical/reference profile, not a silent current default.']],
            ['id'=>'products-sp-ir6-5','values'=>['Rice','IRRI-6','White Rice 5% Broken','IR6-W5-SP','Pakistan','Reference market benchmark – inactive','6.0 mm','5% max','14% max','1.5% max','2% max','2% max','0.5% max','0.5% max','1 per 100 grains max','1% max','1% max','Well milled; min 40 Kett','Free from live insects, bad odour and rice fit for human consumption.','S&P Global Specifications Guide July 2026 — Pakistan Long Grain White Rice 5% Broken FOB assessment benchmark. Reference only; it does not replace Transtrade active buyer/contract profile.']],
            ['id'=>'products-sp-ir6-25','values'=>['Rice','IRRI-6','White Rice 25% Broken','IR6-W25-SP','Pakistan','Reference market benchmark – inactive','6.0 mm','25% max','14% max','4% max','10% max','9% max','2.5% max','1.2% max','5 per 100 grains max','3% max','3% max','Reasonably well milled; min 35 Kett','Free from live insects, bad odour and rice fit for human consumption.','S&P Global Specifications Guide July 2026 — Pakistan Long Grain White Rice 25% Broken FOB assessment benchmark. Reference only.']],
            ['id'=>'products-sp-ir6-100','values'=>['Rice','IRRI-6','100% Broken','IR6-B100-SP','Pakistan','Reference market benchmark – inactive','','100% max','14% max','10% max','20% max','','4% max','2% max','5 per 100 grains max','6% max','','Well milled','Free from live insects, bad odour and rice fit for human consumption.','S&P Global Specifications Guide July 2026 — Pakistan Long Grain White Rice 100% Broken FOB assessment benchmark. Kept separate from Transtrade B2 Sortex by-product.']],
            ['id'=>'products-3','values'=>['Rice','IRRI-6','Parboiled Rice 5% Broken','IR6-P5','Pakistan','Active product – commercial reference','6.0 mm','5% max','14% max','1.5% max','4% max','','0.5% max','0.5% max','0.2% max','','1.5% max','Double silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption.','Common Pakistan exporter commercial profile; review against buyer contract before use.']],
            ['id'=>'products-4','values'=>['Rice','C-9','White Rice 5% Broken','C9-W5','Pakistan','Active Transtrade product – reference profile','6.8 mm','5% max','13.5% max','1.5% max','4% max','7% max','0.5% max','0.5% max','15 pcs/kg max','','2% max','Double silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption.','Pakistan exporter reference profile commonly sold as IRRI-9/C-9; TDAP identifies C-9 among Pakistan non-Basmati export varieties. Kept as C-9 in Transtrade because that is the business variety name.']],
            ['id'=>'products-5','values'=>['Rice','C-9','Parboiled / Sella 5% Broken','C9-P5','Pakistan','Active product – reference profile','6.8 mm','5% max','13.5% max','1.5% max','4% max','7% max','0.5% max','0.5% max','15 pcs/kg max','','2% max','Silky polished; colour sortexed; parboiled','Free from live insects, bad odour and rice fit for human consumption.','Commercial reference based on Pakistan IRRI-9/C-9 parboiled export profiles; confirm processing-specific buyer limits.']],
            ['id'=>'products-6','values'=>['Rice','PK-386','White Rice','PK386-W','Pakistan','Active Transtrade product – grade selectable','6.8–6.85 mm','2–5% max (grade dependent)','13–14% max','1.5% max','3–4% max','7% max','0.5% max','0.5% max','0.2 per 100 grains max / profile dependent','','2% max','Double / silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption.','Pakistan exporter references show 2% premium and 5% common export grades. Exact grade must be selected per contract.']],
            ['id'=>'products-7','values'=>['Rice','PK-386','Parboiled / Sella','PK386-P','Pakistan','Active product – grade selectable','6.8–6.85 mm','2–5% max (grade dependent)','13–14% max','1.5% max','3–4% max','7% max','0.5% max','0.5% max','0.2 per 100 grains max / profile dependent','','2% max','Colour sortexed; parboiled / sella','Free from live insects, bad odour and rice fit for human consumption.','Commercial Pakistan PK-386 reference. Exact limits remain contract-specific and editable.']],
            ['id'=>'products-8','values'=>['Rice','Super Kernel Basmati','White Rice','SKB-W','Pakistan','Active Transtrade product – common export profile','7.0–7.2 mm','2% max','13% max','1% max','3% max','7% max','0.1% max','0.1% max','0.2% max','','2% max','Double silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.','Common Pakistan exporter profile. Current S&P Pakistan benchmark uses a different assessment basket; contract selection remains authoritative.']],
            ['id'=>'products-9','values'=>['Rice','Super Kernel Basmati','Parboiled / Sella','SKB-P','Pakistan','Reference Pakistan benchmark','7.2 mm','4% max','14% max','1% max','1% max','7% max','0.05% max','0.1% max','0.1 per 100 grains max','','2% max','Very well milled','Free from live insects, bad odour and rice fit for human consumption.','S&P Global Specifications Guide — Pakistan Super Kernel Parboiled market assessment benchmark, July 2026. Editable; buyer contract may use tighter 2% grade.']],
            ['id'=>'products-10','values'=>['Rice','D-98 / PK-198','White Rice','D98-W','Pakistan','Active Transtrade product – common export profile','6.8 mm','2% max','13% max','1.5% max','3% max','7% max','0.2% max','0.1% max','0.2% max','','2% max','Double silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.','REAP lists Basmati D-98 / PK-198. Limits pre-filled from common Pakistan exporter D-98 profiles.']],
            ['id'=>'products-11','values'=>['Rice','D-98 / PK-198','Parboiled / Sella','D98-P','Pakistan','Active product – reference profile','6.8 mm','2% max','13% max','1.5% max','3% max','7% max','0.2% max','0.1% max','0.2% max','','2% max','Colour sortexed; parboiled / sella','Free from live insects, bad odour and rice fit for human consumption.','Commercial D-98 reference profile; confirm buyer-specific parboiled limits.']],
            ['id'=>'products-12','values'=>['Rice','1121 Basmati','White Rice','1121-W','Pakistan','Active Transtrade product – common export profile','8.0–8.2 mm','2% max','13% max','1.5% max','3% max','7% max','0.2% max','0.1% max','0.2% max','','2% max','Double silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.','Common Pakistan 1121 white export profile. TDAP Basmati GI Book lists PK 1121 Aromatic as a registered Pakistan Basmati variety; Punjab Agriculture lists 8.16 mm varietal kernel length. Commercial defect limits remain editable/contract-specific.']],
            ['id'=>'products-13','values'=>['Rice','1121 Basmati','Steam 2% Broken','1121-S','Pakistan','Reference Pakistan benchmark','8.0 mm','2% max','13% max','0.5% max','3% max','7% max','','','','0.5% max','0.5% max','Very well milled; min 38 Kett','Free from live insects, bad odour and rice fit for human consumption.','S&P Global Specifications Guide — Pakistan 1121 Steam Basmati market assessment benchmark, July 2026; includes 2% max ungelatinized kernels. TDAP Basmati GI Book lists PK 1121 Aromatic as a registered Pakistan Basmati variety.']],
            ['id'=>'products-14','values'=>['Rice','1121 Basmati','Parboiled / Sella 2% Broken','1121-P','Pakistan','Active product – Pakistan benchmark','8.0 mm','2% max','13% max','0.5% max','4% max','7% max','0.05% max','0.05% max','0.05 per 100 grains max','0.5% max','1% max','Very well milled; min 38 Kett','Free from live insects, bad odour and rice fit for human consumption.','S&P Global Specifications Guide — Pakistan 1121 Parboiled Basmati market assessment benchmark, July 2026. TDAP Basmati GI Book lists PK 1121 Aromatic as a registered Pakistan Basmati variety.']],
            ['id'=>'products-15','values'=>['Rice','B2 Sortex Broken','By-product','B2-S','Pakistan','Active Transtrade by-product','','By-product / contract specific','','','','','','','','','','Sortexed as instructed','Fit for intended sale/use and free from infestation or bad odour where sold as food grade.','Transtrade operational by-product. Final buyer specification remains sale-specific.']],
            ['id'=>'products-16','values'=>['Rice','Super Basmati','White Rice','SUPER-BAS-W','Pakistan','Reference only – inactive','7.45 mm variety characteristic','2% max','13% max','1–1.5% max','3% max','7% max','0.2% max','0.1–0.2% max','0.2% max','','2% max','Double / silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.','TDAP Basmati GI Book lists Super Basmati as a registered Pakistan Basmati variety; Punjab Agriculture lists 7.45 mm varietal kernel length. Defect limits shown here are common exporter references, not the GI identity limits.']],
            ['id'=>'products-17','values'=>['Rice','Basmati 385 / PK-385','White Rice','PK385-W','Pakistan','Reference only – inactive','6.73 mm variety characteristic','2% max','13% max','1.5% max','3% max','7% max','0.2% max','0.1% max','0.2% max','','2% max','Colour sortexed','Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.','TDAP Basmati GI Book lists Basmati 385 as a registered Pakistan Basmati variety; Punjab Agriculture lists 6.73 mm varietal kernel length. Common exporter defect profile pre-filled for review.']],
            ['id'=>'products-18','values'=>['Rice','IRRI-9','White Rice 5% Broken','IR9-W5','Pakistan','Reference only – inactive','6.8 mm','5% max','13.5–14% max','1.5% max','4% max','7% max','0.5% max','0.5% max','15 pcs/kg max','','2% max','Double / silky polished; colour sortexed','Free from live insects, bad odour and rice fit for human consumption.','REAP lists IRRI-9. Common Pakistan exporter IRRI-9 profile pre-filled.']],
            ['id'=>'products-20','values'=>['Rice','Basmati 515','White / processed','BAS515','Pakistan','Reference only – inactive','7.56 mm variety characteristic','','','','','','','','','','','To configure by processing','Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.','TDAP Basmati GI Book lists Basmati 515 as a registered Pakistan Basmati variety; Punjab Agriculture lists 7.56 mm varietal kernel length. Export defect profile left blank pending an approved Transtrade/buyer standard.']],
            ['id'=>'products-21','values'=>['Rice','KS-282','White / processed','KS282','Pakistan','Reference only – inactive','','','','','','','','','','','','To configure by processing','Free from live insects, bad odour and rice fit for human consumption.','REAP lists KS-282 as a Pakistan rice type. Grain length and export defect limits intentionally left blank rather than conflating KS-282 with similarly named KSK varieties.']],
        ],
        // Purchase identity keeps variety, rice type and commercial condition
        // separate. RAW is bought for processing; READY is finished rice bought
        // from an ex-mill. FINAL is created only by own/reprocessing production.
        'purchase_products'=>[
            ['id'=>'purchase-products-rice-irri6-white-raw','values'=>['RICE','IRRI-6','White','RAW','KG','purchase-kat-rice-irri6-white-raw','','','Active','Externally purchased IRRI-6 White Raw Rice for processing at TTI or a selected reprocessing mill.']],
            ['id'=>'purchase-products-rice-irri6-white-ready','values'=>['RICE','IRRI-6','White','READY','KG','','','','Active','Finished IRRI-6 White Ready Rice purchased from an ex-mill; exportable without TTI/reprocessing conversion.']],
            ['id'=>'purchase-products-corn-raw','values'=>['CORN','Corn / Makai','','RAW','MAUND','CORN','','','Active','Karachi weighbridge weight is authoritative.']],
            ['id'=>'purchase-products-sesame-raw','values'=>['SESAME','Sesame','','RAW','MAUND','SESAME_RAW','','','Active','Raw sesame purchase.']],
            ['id'=>'purchase-products-sesame-ready','values'=>['SESAME','Sesame','','READY','MAUND','SESAME_READY','','','Active','Ready sesame purchase.']],
        ],
        'purchase_kat'=>[
            ['id'=>'purchase-kat-rice-irri6-white-raw','values'=>['RICE','IRRI-6','White','RAW','IRRI-6 White Raw KAT','','','Draft – review required','Only confirmed parameter/range rows may be activated. “Paddy grains in rice” is a quality count, not a paddy purchase.','[{"name":"Broken","freeAllowance":"20%","unit":"paisa per %","instruction":"","ranges":[{"from":"20","to":"30","value":"1","unit":"paisa per %"},{"from":"30","to":"35","value":"3","unit":"paisa per %"},{"from":"35","to":"40","value":"8","unit":"paisa per %"},{"from":"40","to":"45","value":"15","unit":"paisa per %"},{"from":"45","to":"50","value":"20","unit":"paisa per %"},{"from":"50","to":"55","value":"25","unit":"paisa per %"},{"from":"55","to":"60","value":"40","unit":"paisa per %"}]},{"name":"Chalky","freeAllowance":"5% operational default","unit":"paisa per %","instruction":"Earlier discussion included 4%; confirm before activation.","ranges":[{"from":"5","to":"","value":"10","unit":"paisa per %"}]},{"name":"Damage / Yellow","freeAllowance":"2%","unit":"paisa per %","instruction":"","ranges":[{"from":"2","to":"5","value":"10","unit":"paisa per %"},{"from":"5","to":"","value":"25","unit":"paisa per %"}]},{"name":"Moisture","freeAllowance":"14%","unit":"weight %","instruction":"Above 16% remains manual/reject until confirmed.","ranges":[{"from":"14","to":"14.5","value":"0.5","unit":"weight %"},{"from":"14.5","to":"15","value":"1","unit":"weight %"},{"from":"15","to":"16","value":"2","unit":"weight %"}]},{"name":"Paddy grains in rice","freeAllowance":"80 grains operational default","unit":"No. of Grains","instruction":"No final automatic KAT slab confirmed.","ranges":[]}]']],
        ],
        'export_documents'=>[
            ['id'=>'export-doc-1','values'=>['Commercial Invoice','3','0','ALL','Active']],
            ['id'=>'export-doc-2','values'=>['Commercial Packing List','3','0','ALL','Active']],
            ['id'=>'export-doc-3','values'=>['Full set clean on-board Bill of Lading','3','3','ALL','Active']],
            ['id'=>'export-doc-4','values'=>['Certificate of Origin','1','3','ALL','Active']],
            ['id'=>'export-doc-5','values'=>['e-Phyto issued by Department of Plant Protection, Government of Pakistan','1','0','ALL','Active']],
            ['id'=>'export-doc-6','values'=>['Fumigation Certificate','1','1','ALL','Active']],
            ['id'=>'export-doc-7','values'=>['Insurance Policy / Certificate','1','0','CIF','Active']],
        ],
        'export_terms'=>[
            ['id'=>'export-term-base-1','values'=>['BASE','All present and/or future customs taxes and/or duties/levies on the cargo in the country of origin shall be for Seller’s account. All present and/or future customs taxes and/or duties/levies on the cargo in the country of destination shall be for Buyer’s account.','Active']],
            ['id'=>'export-term-base-2','values'=>['BASE','Risk of weight and quality is transferred to Buyer once cargo is loaded on board the vessel from Pakistan.','Active']],
            ['id'=>'export-term-base-3','values'=>['BASE','Ownership of cargo is transferred to Buyer upon receipt of full payment of the invoice.','Active']],
            ['id'=>'export-term-base-4','values'=>['BASE','All other terms and conditions as per applicable GAFTA London rules, of which both parties admit full notice and knowledge. English law to apply.','Active']],
            ['id'=>'export-term-base-5','values'=>['BASE','Should any dispute arise which cannot be amicably settled between Buyer and Seller, the dispute shall be settled by arbitration in London as per applicable GAFTA rules.','Active']],
            ['id'=>'export-term-advance','values'=>['ADVANCE','Partial shipment allowed.','Active']],
            ['id'=>'export-term-cad','values'=>['CAD','Partial shipment allowed.','Active']],
        ],
        // Export customers and operational business parties are deliberately
        // separate masters. Legacy `parties` rows are migrated in
        // tt_normalize_masters() without changing their record IDs.
        'export_customers'=>[],
        'business_parties'=>[],
        'parties'=>[],
        'mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','TTI-MILL','Own Mill','','','Active','']],['id'=>'mills-2','values'=>['Karachi Office','KHI-OFF','Office','','','Active','']]],
        'banks'=>[
            ['id'=>'banks-tti','values'=>['Company Account','TTI — Transtrade International','','Transtrade International','Meezan Bank Limited','Jodia Bazar Branch, Karachi','Pakistan','PKR','','','','Pakistan operating account','Accounts / Directors; document use to be confirmed','Incomplete — enter account number/IBAN and confirm use']],
            ['id'=>'banks-brm','values'=>['Company Account','BRM — Buksh Rice Mills','','Buksh Rice Mills','Meezan Bank Limited','Karachi','Pakistan','PKR','','','','Mill / operating account','Accounts / Directors; document use to be confirmed','Incomplete — enter branch/account number/IBAN']],
            ['id'=>'banks-tg','values'=>['Company Account','TG — Trans Grains Foodstuff Trading L.L.C','','Trans Grains Foodstuff Trading L.L.C','Habib Bank AG Zurich','Baniyas Square, Dubai','United Arab Emirates','USD','','','','TG offshore trading account','Authorized TG / Exports / Accounts / Directors only','Incomplete — enter account number/IBAN/SWIFT and confirm use']],
        ],
    ];
    return $masters;
}

function tt_master_json_array(mixed $value): array {
    if (is_array($value)) return array_values($value);
    if (!is_string($value) || trim($value)==='') return [];
    $decoded=json_decode($value,true);
    return is_array($decoded) ? array_values($decoded) : [];
}

/** Keep the historical 14-column bank contract for existing module APIs. */
function tt_company_bank_legacy_rows(array $companies): array {
    $rows=[];
    foreach ($companies as $company) {
        $cv=array_values((array)($company['values'] ?? []));
        $companyName=trim((string)($cv[0] ?? ''));
        $companyCode=strtoupper(trim((string)($cv[1] ?? '')));
        foreach (tt_master_json_array($cv[13] ?? '') as $bank) {
            if (!is_array($bank)) continue;
            $id=trim((string)($bank['id'] ?? '')) ?: 'bank-'.substr(hash('sha256',$companyCode.'|'.json_encode($bank)),0,14);
            $rows[]=['id'=>$id,'values'=>[
                (string)($bank['accountType'] ?? 'Company Account'),
                trim($companyCode.' — '.$companyName,' —'),
                (string)($bank['label'] ?? ''),
                (string)($bank['accountTitle'] ?? $companyName),
                (string)($bank['bankName'] ?? ''),
                (string)($bank['branch'] ?? ''),
                (string)($bank['country'] ?? ($cv[2] ?? '')),
                (string)($bank['currency'] ?? 'PKR'),
                (string)($bank['accountNumber'] ?? ''),
                (string)($bank['iban'] ?? ''),
                (string)($bank['swift'] ?? ''),
                (string)($bank['purpose'] ?? ''),
                (string)($bank['visibility'] ?? ''),
                (string)($bank['notes'] ?? ''),
            ]];
        }
    }
    return $rows;
}

function tt_normalize_masters(array $masters): array {
    $defaults=tt_default_masters();
    foreach ($defaults as $type=>$rows) if (!isset($masters[$type]) || !is_array($masters[$type])) $masters[$type]=$rows;
    // Some early Super Admin saves persisted empty sections before their
    // default rows had been copied into the durable store.  An empty Product
    // or Location section must not leave every operational Soda selector
    // unusable.  Restore only the system-owned starter identities; user
    // entered rows and inactive history remain untouched.
    foreach (['purchase_products','mills'] as $requiredType) {
        if (count((array)$masters[$requiredType])===0) $masters[$requiredType]=$defaults[$requiredType];
    }

    // Remove only the old generated placeholders. Future owner-entered rules
    // for other varieties/corn are preserved when Salman defines them.
    $legacyKatPlaceholders=['purchase_kat-7','purchase_kat-8','purchase_kat-9','purchase_kat-10','purchase_kat-11','purchase_kat-12','purchase_kat-13','purchase_kat-15','purchase_kat-16'];
    $masters['purchase_kat']=array_values(array_filter((array)$masters['purchase_kat'],static function ($row) use ($legacyKatPlaceholders): bool {
        return !in_array((string)($row['id'] ?? ''),$legacyKatPlaceholders,true);
    }));

    $companyDefaults=[];
    foreach ($defaults['companies'] as $row) $companyDefaults[strtoupper((string)$row['values'][1])]=$row;
    $seen=[];
    foreach ($masters['companies'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        $code=strtoupper(trim((string)($row['values'][1] ?? ''))); if ($code==='') continue; $seen[$code]=true;
        if (isset($companyDefaults[$code]) && count($values)<=3) $values=$companyDefaults[$code]['values'];
        if ($code==='BRM' && (($values[0] ?? '')==='BRM')) $values=$companyDefaults['BRM']['values'];
        while (count($values)<15) $values[]='';
        if (($values[7] ?? '')==='') $values[7]='[{"name":"","share":100}]';
        if (($values[13] ?? '')==='') $values[13]='[]';
        if (($values[14] ?? '')==='') $values[14]='[]';
        $row['values']=array_slice($values,0,15);
    }
    unset($row);
    foreach ($companyDefaults as $code=>$row) if (empty($seen[$code])) $masters['companies'][]=$row;
    foreach ($masters['companies'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        while (count($values)<15) $values[]='';
        if (($values[7] ?? '')==='') $values[7]='[{"name":"","share":100}]';
        if (($values[13] ?? '')==='') $values[13]='[]';
        if (($values[14] ?? '')==='') $values[14]='[]';
        $row['values']=array_slice($values,0,15);
    }
    unset($row);

    // One-time, lossless migration: legacy top-level bank rows become nested
    // Company bank records. The derived `banks` reader below keeps all
    // Accounts/Exports/Milling consumers working during the UI transition.
    foreach ((array)($masters['banks'] ?? []) as $legacyBank) {
        $bv=array_values((array)($legacyBank['values'] ?? []));
        while (count($bv)<14) $bv[]='';
        $hint=strtoupper((string)(($bv[1] ?? '').' '.($bv[3] ?? '')));
        $targetCode=str_contains($hint,'BRM')||str_contains($hint,'BUKSH')?'BRM':(str_contains($hint,'TG')||str_contains($hint,'TRANS GRAINS')?'TG':'TTI');
        foreach ($masters['companies'] as &$company) {
            if (strtoupper((string)($company['values'][1] ?? ''))!==$targetCode) continue;
            $banks=tt_master_json_array($company['values'][13] ?? '');
            $legacyId=(string)($legacyBank['id'] ?? '');
            $exists=false;
            foreach ($banks as $bank) if (($legacyId!==''&&(string)($bank['id'] ?? '')===$legacyId)||(($bv[9]??'')!==''&&(string)($bank['iban']??'')===(string)$bv[9])||(($bv[8]??'')!==''&&(string)($bank['accountNumber']??'')===(string)$bv[8])) {$exists=true;break;}
            if (!$exists) $banks[]=['id'=>$legacyId ?: 'bank-'.substr(hash('sha256',json_encode($bv)),0,14),'accountType'=>$bv[0],'label'=>$bv[2],'accountTitle'=>$bv[3],'bankName'=>$bv[4],'branch'=>$bv[5],'country'=>$bv[6],'currency'=>$bv[7],'accountNumber'=>$bv[8],'iban'=>$bv[9],'swift'=>$bv[10],'purpose'=>$bv[11],'visibility'=>$bv[12],'notes'=>$bv[13],'status'=>'Active'];
            $company['values'][13]=json_encode($banks,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            break;
        }
        unset($company);
    }

    // Split the old mixed Party master without losing IDs or operational
    // references. Buyer records become Export Customers; all other roles
    // become reusable Business Parties.
    foreach ((array)($masters['parties'] ?? []) as $legacyParty) {
        $pv=array_values((array)($legacyParty['values'] ?? []));
        $roles=(string)($pv[2] ?? '');
        $target=preg_match('/\b(export\s*)?buyer\b/i',$roles)?'export_customers':'business_parties';
        $already=false;
        foreach ((array)$masters[$target] as $row) if ((string)($row['id']??'')===(string)($legacyParty['id']??'')) {$already=true;break;}
        if (!$already) $masters[$target][]=$legacyParty;
    }

    $productDefaults=[];
    foreach ($defaults['products'] as $row) $productDefaults[strtoupper((string)$row['values'][3])]=$row;
    $legacyProductCodes=['IR6-W'=>'IR6-W5','IR6-P'=>'IR6-P5','C9-W'=>'C9-W5'];
    $seenProducts=[];
    foreach ($masters['products'] as &$row) {
        $values=(array)($row['values'] ?? []);
        $legacyCode=strtoupper(trim((string)($values[1] ?? '')));
        $rawCode=count($values)<=3 ? $legacyCode : strtoupper(trim((string)($values[3] ?? '')));
        $code=$legacyProductCodes[$rawCode] ?? $rawCode;
        if ($code!=='') $seenProducts[$code]=true;
        if ($code!=='' && count($values)<20 && isset($productDefaults[$code])) $row['values']=$productDefaults[$code]['values'];
    }
    unset($row);
    foreach ($masters['purchase_products'] as &$row) {
        $row['values']=tt_purchase_product_values((array)($row['values'] ?? []));
        // Brokery belongs to the Broker profile and stock accounts are system mapped.
        // Clear the retired product-level fields while preserving row shape and history.
        $row['values'][6]='';
        $row['values'][7]='';
    }
    unset($row);
    foreach ($productDefaults as $code=>$row) if (empty($seenProducts[$code])) $masters['products'][]=$row;

    // Upgrade every previously saved product to the structured Product Identity.
    // Older records stored values such as "White Rice 5% Broken" together in
    // Rice type. Keep the percentage authoritative in Broken and remove it
    // from Rice type so Sales Contracts cannot receive two conflicting values.
    foreach ($masters['products'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        while (count($values)<22) $values[]='';
        $legacyType=trim((string)($values[2] ?? ''));
        if (preg_match('/^(.*?)\s*(\d+(?:\.\d+)?\s*%)(?:\s*MAX)?\s*BROKEN$/i',$legacyType,$match)) {
            $riceType=trim((string)$match[1]);
            $values[2]=$riceType!==''?$riceType:'White Rice';
            if (trim((string)($values[7] ?? ''))==='') $values[7]=preg_replace('/\s+/', '', (string)$match[2]);
        }
        $row['values']=array_slice($values,0,22);
    }
    unset($row);

    foreach (['business_parties'] as $simpleType) {
        foreach ($masters[$simpleType] as &$row) {
            $values=array_values((array)($row['values'] ?? []));
            if(count($values)<=4){$values=[$values[0]??'',$values[1]??'',$values[2]??'','','','','','','','','Active',$values[3]??'','{"buying":[],"selling":[]}'];}
            while (count($values)<13) $values[]='';
            if(trim((string)$values[10])==='')$values[10]='Active';
            $profile=json_decode((string)$values[12],true);if(!is_array($profile))$profile=[];$profile['buying']=array_values(is_array($profile['buying']??null)?$profile['buying']:[]);$profile['selling']=array_values(is_array($profile['selling']??null)?$profile['selling']:[]);$values[12]=json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $row['values']=array_slice($values,0,13);
        }
        unset($row);
    }
    foreach ($masters['mills'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        // Legacy layout: name, code, type, notes.
        if (count($values)<=4) $values=[$values[0]??'',$values[1]??'',$values[2]??'','','','Active',$values[3]??''];
        while (count($values)<7) $values[]='';
        $values[2]=tt_normalize_location_type((string)$values[2]);
        if ((string)($row['id'] ?? '') === 'mills-1' && strcasecmp(trim((string)$values[0]), 'TTI Rice Mill') === 0) $values[0]='TTI Rice Mills';
        if (trim((string)$values[5])==='') $values[5]='Active';
        $row['values']=array_slice($values,0,7);
    }
    unset($row);
    foreach ($masters['export_customers'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        while (count($values)<22) $values[]='';
        $row['values']=$values;
    }
    unset($row);
    // One KAT master row now represents one exact purchase product. Migrate
    // the earlier scattered parameter rows without discarding their wording.
    $katRows=[];$legacyKat=[];
    foreach ((array)$masters['purchase_kat'] as $row) {
        $values=array_values((array)($row['values'] ?? []));while(count($values)<10)$values[]='';
        $isGrouped=in_array(tt_product_stage((string)$values[3]),['RAW','READY'],true)&&is_array(json_decode((string)$values[9],true));
        if ($isGrouped) {
            $values[0]=strtoupper((string)$values[0]);$values[1]=tt_product_base((string)$values[1]);$values[2]=tt_product_type((string)$values[2]);$values[3]=tt_product_stage((string)$values[3]);
            $katRows[]=['id'=>(string)($row['id']??''),'values'=>array_slice($values,0,10)];continue;
        }
        $commodity=strtoupper((string)($values[0]?:'RICE'));$base=tt_product_base((string)$values[1]);$key=strtolower($commodity.'|'.$base.'|white|raw');
        if (!isset($legacyKat[$key])) $legacyKat[$key]=['commodity'=>$commodity,'base'=>$base,'parameters'=>[],'draft'=>false,'notes'=>[]];
        $name=trim((string)$values[2]);if(strcasecmp($name,'Paddy')===0)$name='Paddy grains in rice';
        if (str_contains(strtolower((string)$values[6]),'seasonal')) $name.=' — '.trim((string)$values[6]);
        $ranges=json_decode((string)$values[9],true);if(!is_array($ranges))$ranges=[];
        if (!$ranges) {
            $parameter=strtolower((string)$values[2]);$variety=strtolower($base);
            if (str_contains($variety,'irri-6')&&$parameter==='broken')$ranges=[['from'=>'20','to'=>'30','value'=>'1','unit'=>'paisa per %'],['from'=>'30','to'=>'35','value'=>'3','unit'=>'paisa per %'],['from'=>'35','to'=>'40','value'=>'8','unit'=>'paisa per %'],['from'=>'40','to'=>'45','value'=>'15','unit'=>'paisa per %'],['from'=>'45','to'=>'50','value'=>'20','unit'=>'paisa per %'],['from'=>'50','to'=>'55','value'=>'25','unit'=>'paisa per %'],['from'=>'55','to'=>'60','value'=>'40','unit'=>'paisa per %']];
            elseif(str_contains($variety,'irri-6')&&str_contains($parameter,'damage'))$ranges=[['from'=>'2','to'=>'5','value'=>'10','unit'=>'paisa per %'],['from'=>'5','to'=>'','value'=>'25','unit'=>'paisa per %']];
            elseif(str_contains($variety,'irri-6')&&$parameter==='chalky')$ranges=[['from'=>'5','to'=>'','value'=>'10','unit'=>'paisa per %']];
            elseif(str_contains($variety,'irri-6')&&$parameter==='moisture'&&str_contains((string)$values[6],'14%'))$ranges=[['from'=>'14','to'=>'14.5','value'=>'0.5','unit'=>'weight %'],['from'=>'14.5','to'=>'15','value'=>'1','unit'=>'weight %'],['from'=>'15','to'=>'16','value'=>'2','unit'=>'weight %']];
        }
        $legacyKat[$key]['parameters'][]=['name'=>$name,'freeAllowance'=>(string)$values[3],'unit'=>(string)$values[5],'instruction'=>(string)$values[8],'legacyCalculation'=>(string)$values[4],'ranges'=>array_values($ranges)];
        if (stripos((string)$values[7],'Draft')!==false)$legacyKat[$key]['draft']=true;
        if(trim((string)$values[8])!=='')$legacyKat[$key]['notes'][]=trim((string)$values[8]);
    }
    foreach($legacyKat as $group){$id='purchase-kat-'.substr(hash('sha256',strtolower($group['commodity'].'|'.$group['base'].'|white|raw')),0,16);if($group['commodity']==='RICE'&&strcasecmp($group['base'],'IRRI-6')===0)$id='purchase-kat-rice-irri6-white-raw';$katRows[]=['id'=>$id,'values'=>[$group['commodity'],$group['base'],'White','RAW',$group['base'].' White Raw KAT','','',$group['draft']?'Draft – review required':'Active',implode(' ',array_unique($group['notes'])),json_encode($group['parameters'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]];}
    $masters['purchase_kat']=$katRows;
    $katByIdentity=[];$katIds=[];
    foreach($katRows as$katRow){$kv=(array)($katRow['values']??[]);$katId=(string)($katRow['id']??'');$katIds[$katId]=true;$katByIdentity[strtolower((string)($kv[0]??'').'|'.(string)($kv[1]??'').'|'.(string)($kv[2]??'').'|'.(string)($kv[3]??''))]=$katId;}
    foreach($masters['purchase_products']as&$productRow){$pv=tt_purchase_product_values((array)($productRow['values']??[]));$identity=strtolower($pv[0].'|'.$pv[1].'|'.$pv[2].'|'.$pv[3]);if($pv[0]==='RICE'&&!isset($katIds[(string)$pv[5]])&&isset($katByIdentity[$identity]))$pv[5]=$katByIdentity[$identity];$productRow['values']=$pv;}unset($productRow);
    foreach ($masters['products'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        while (count($values)<22) $values[]='';
        $row['values']=$values;
    }
    unset($row);
    $masters['banks']=tt_company_bank_legacy_rows($masters['companies']);
    return $masters;
}

function tt_visible_masters(array $masters): array {
    $visible=['companies','export_customers','business_parties','commodities','product_settings','products','purchase_products','purchase_kat','export_documents','export_terms','mills','banks'];
    return array_intersect_key(tt_normalize_masters($masters),array_flip($visible));
}

/** Read historical multi-category Business Party values consistently. */
function tt_business_party_categories(mixed $value): array {
    $parts=preg_split('/\s*(?:;|,|\/|\|)\s*/u',trim((string)$value),-1,PREG_SPLIT_NO_EMPTY) ?: [];
    $canonical=[];
    foreach($parts as$part){$part=trim(preg_replace('/\s+/u',' ',(string)$part)??'');if($part==='')continue;$key=strtolower($part);if(!isset($canonical[$key]))$canonical[$key]=$part;}
    return array_values($canonical);
}

function tt_business_party_has_category(mixed $value,string $category): bool {
    foreach(tt_business_party_categories($value)as$item)if(strcasecmp($item,$category)===0)return true;
    return false;
}

/** Active broker profiles used by Soda and accounting. Brokery is owned here, never by a product or KAT rule. */
function tt_broker_profiles(?string $onDate=null,string $kind='buying'): array {
    $date=$onDate!==null&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$onDate)?$onDate:(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $kind=$kind==='selling'?'selling':'buying';$out=[];
    foreach((array)(tt_list_masters()['business_parties']??[])as$row){if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<13)$v[]='';if(!tt_business_party_has_category($v[2],'Broker')||strcasecmp((string)$v[10],'Inactive')===0)continue;$profile=json_decode((string)$v[12],true);$rates=is_array($profile)&&is_array($profile[$kind]??null)?$profile[$kind]:[];$active=[];foreach($rates as$rate){if(!is_array($rate)||strcasecmp((string)($rate['status']??'Active'),'Inactive')===0)continue;$from=(string)($rate['effectiveFrom']??'');if($from!==''&&$from<=$date)$active[]=$rate;}usort($active,static fn($a,$b)=>strcmp((string)($b['effectiveFrom']??''),(string)($a['effectiveFrom']??'')));$rate=$active[0]??null;$out[]=['id'=>(string)($row['id']??''),'name'=>(string)$v[0],'code'=>(string)$v[1],'kind'=>$kind,'rate'=>$rate,'status'=>(string)$v[10]];}
    usort($out,static fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));return$out;
}

/** Resolve a broker exactly as stored in Business Parties; free-text near matches never post. */
function tt_broker_profile(string $name,?string $onDate=null,string $kind='buying'): ?array {
    $needle=trim($name);if($needle==='')return null;
    foreach(tt_broker_profiles($onDate,$kind)as$profile)if(strcasecmp(trim((string)($profile['name']??'')),$needle)===0)return$profile;
    return null;
}

/** Resolve an active export Indentor exactly as stored in Business Parties. Commission remains deal-specific. */
function tt_indentor_profile(string $name): ?array {
    $needle=trim($name);if($needle==='')return null;
    foreach((array)(tt_list_masters()['business_parties']??[])as$row){if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<13)$v[]='';if(!tt_business_party_has_category($v[2],'Indentor')||strcasecmp((string)$v[10],'Inactive')===0)continue;if(strcasecmp(trim((string)$v[0]),$needle)===0)return['id'=>(string)($row['id']??''),'name'=>(string)$v[0],'code'=>(string)$v[1],'status'=>(string)$v[10]];}
    return null;
}

/** Calculate Buying/Selling Brokery from the effective Broker-profile rate. */
function tt_brokery_amount(?array $rate,float $weightKg,float $bags=0): float {
    if(!$rate)return 0.0;$figure=(float)($rate['amount']??0);$basis=(string)($rate['basis']??'');
    $units=match($basis){'PER_100_KG'=>$weightKg/100,'PER_50_KG_BAG'=>$weightKg/50,'PER_BAG'=>$bags,'PER_MAUND'=>$weightKg/40,'PER_TON'=>$weightKg/1000,default=>0};
    return round(max(0,$units*$figure),2);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('TRANSTRADE_SESSION');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true, 'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function tt_default_master_options(): array {
    return [
        'party_roles'=>['Buyer','Supplier','Broker','Indentor','Export Buyer','Local Buyer','Customer','Agent','Service Provider','Other'],
        'product_commodities'=>['Rice','Corn','Sesame Seed'],
        'product_varieties'=>['IRRI-6','C-9','PK-386','Super Kernel Basmati','D-98','1121'],
        'product_rice_types'=>['White','Parboiled','Steam'],
        'product_broken'=>['5% max','10% max','15-20%','25% max','100%'],
        'product_finishes'=>['Well milled, silky polished and well sortexed','Well milled, double polished and well sortexed','Reasonably well milled'],
        'product_origins'=>['Pakistan'],
        'product_profiles'=>['Active Transtrade default','Contract specific','Historical reference'],
        'currencies'=>['USD','EUR','GBP','AED','PKR'],
        'inspection_companies'=>['No','Any inspection company','SGS Pakistan Private Limited','Intertek'],
        'packing_types'=>['P.P. Bags','BOPP Laminated Bags','Cotton Bags','Non-Woven Bags','Jute Bags'],
        'payment_options'=>['100% Advance','100% CAD','Advance + CAD','L/C at Sight','Usance L/C','Open Account'],
    ];
}

function tt_default_party_roles(): array { return tt_default_master_options()['party_roles']; }

function tt_master_options(): array {
    $data=tt_read_store();
    $defaults=tt_default_master_options();
    $stored=is_array($data['master_options'] ?? null) ? $data['master_options'] : [];
    $disabled=is_array($data['master_options_disabled'] ?? null) ? $data['master_options_disabled'] : [];
    $productFields=['product_commodities'=>0,'product_varieties'=>1,'product_rice_types'=>2,'product_broken'=>7,'product_finishes'=>17,'product_origins'=>4,'product_profiles'=>5];
    $out=[];
    foreach ($defaults as $key=>$base) {
        $values=array_merge($base,(array)($stored[$key] ?? []));
        if (isset($productFields[$key])) {
            foreach ((array)($data['masters']['products'] ?? []) as $row) {
                $value=trim((string)(($row['values'] ?? [])[$productFields[$key]] ?? ''));
                if ($value!=='') $values[]=$value;
            }
        }
        $blocked=array_map(static fn($v)=>strtolower(trim((string)$v)),(array)($disabled[$key] ?? []));
        $unique=[];
        foreach ($values as $value) {
            $value=trim(preg_replace('/\s+/',' ',(string)$value) ?? '');
            if ($value==='' || in_array(strtolower($value),$blocked,true)) continue;
            $norm=strtolower($value);
            if (!isset($unique[$norm])) $unique[$norm]=$value;
        }
        $out[$key]=array_values($unique);
        sort($out[$key],SORT_NATURAL|SORT_FLAG_CASE);
    }
    return $out;
}

function tt_manage_master_option(string $key,string $action,string $value,string $old=''): string {
    $defaults=tt_default_master_options();
    if (!array_key_exists($key,$defaults)) throw new InvalidArgumentException('Select a valid option list.');
    $clean=static fn(string $v): string=>trim(preg_replace('/\s+/',' ',$v) ?? '');
    $value=$clean($value); $old=$clean($old);
    if (!in_array($action,['add','rename','delete'],true)) throw new InvalidArgumentException('Select add, rename or delete.');
    if ($action!=='delete' && ($value==='' || strlen($value)>120)) throw new InvalidArgumentException('Enter a valid option.');
    if ($action!=='add' && $old==='') throw new InvalidArgumentException('Select the option to change.');
    tt_mutate_store(function (&$data) use ($key,$action,$value,$old,$clean): void {
        if (!isset($data['master_options']) || !is_array($data['master_options'])) $data['master_options']=[];
        if (!isset($data['master_options_disabled']) || !is_array($data['master_options_disabled'])) $data['master_options_disabled']=[];
        $active=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),(array)($data['master_options'][$key] ?? []))));
        $disabled=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),(array)($data['master_options_disabled'][$key] ?? []))));
        $removeCaseInsensitive=static function(array $rows,string $needle): array {
            return array_values(array_filter($rows,static fn($v)=>strcasecmp((string)$v,$needle)!==0));
        };
        if ($action==='add') {
            $disabled=$removeCaseInsensitive($disabled,$value);
            foreach ($active as $existing) if (strcasecmp((string)$existing,$value)===0) { $data['master_options_disabled'][$key]=$disabled; return; }
            $active[]=$value;
        } else {
            $active=$removeCaseInsensitive($active,$old);
            if (!array_filter($disabled,static fn($v)=>strcasecmp((string)$v,$old)===0)) $disabled[]=$old;
            if ($action==='rename') {
                $disabled=$removeCaseInsensitive($disabled,$value);
                if (!array_filter($active,static fn($v)=>strcasecmp((string)$v,$value)===0)) $active[]=$value;
            }
        }
        sort($active,SORT_NATURAL|SORT_FLAG_CASE); sort($disabled,SORT_NATURAL|SORT_FLAG_CASE);
        $data['master_options'][$key]=$active;
        $data['master_options_disabled'][$key]=$disabled;
    });
    return $action==='delete' ? $old : $value;
}

function tt_add_party_role_option(string $role): string {
    return tt_manage_master_option('party_roles','add',$role);
}

function tt_normalize_location_type(string $type): string {
    $t=strtolower(trim($type));
    if (str_contains($t,'reprocess')) return 'Reprocessing Mill';
    if (str_contains($t,'external') || str_contains($t,'ex-mill') || str_contains($t,'ex mill')) return 'External Mill';
    if (str_contains($t,'own') || str_contains($t,'tti rice')) return 'Own Mill';
    if (str_contains($t,'warehouse')) return 'Warehouse';
    if (str_contains($t,'office')) return 'Office';
    if (str_contains($t,'stock')) return 'Stock Location';
    return trim($type) ?: 'Other';
}

function tt_location_identity(string $value): string {
    return strtolower((string)preg_replace('/[^a-z0-9]+/i','',trim($value)));
}

/** Return the existing location when a proposed name is the same or confusingly close. */
function tt_find_location_duplicate(string $name,?array $rows=null): ?array {
    $needle=tt_location_identity($name);if($needle==='')return null;
    $rows=$rows??tt_active_location_masters();
    foreach($rows as$row){if(!is_array($row))continue;$values=array_values((array)($row['values']??[]));$existing=tt_location_identity((string)($values[0]??''));if($existing==='')continue;
        $distance=levenshtein($needle,$existing);$contains=(str_contains($needle,$existing)||str_contains($existing,$needle))&&abs(strlen($needle)-strlen($existing))<=4;
        if($existing===$needle||($distance<=2&&min(strlen($needle),strlen($existing))>=5)||$contains)return$row;
    }
    return null;
}

function tt_upsert_location_master(string $name,string $type,string $source='System',string $notes=''): array {
    $name=trim(preg_replace('/\\s+/',' ',$name) ?? '');
    if ($name==='' || strlen($name)>160) throw new InvalidArgumentException('Enter a valid mill / location name.');
    $type=tt_normalize_location_type($type);
    $source=trim($source) ?: 'System';
    return tt_mutate_store(function (&$data) use ($name,$type,$source,$notes): array {
        if (!isset($data['masters']['mills']) || !is_array($data['masters']['mills'])) $data['masters']['mills']=[];
        $identity=static fn(string $value): string=>tt_location_identity($value);
        $near=tt_find_location_duplicate($name,(array)$data['masters']['mills']);
        if(is_array($near)&&$identity((string)(($near['values']??[])[0]??''))!==$identity($name))throw new InvalidArgumentException('A similar location already exists: '.(string)(($near['values']??[])[0]??'').'. Select the existing location or amend its master record.');
        foreach ($data['masters']['mills'] as &$row) {
            $values=array_values((array)($row['values'] ?? []));
            if(count($values)<=4)$values=[$values[0]??'',$values[1]??'',$values[2]??'','','','Active',$values[3]??''];
            while (count($values)<7) $values[]='';
            if ($identity((string)$values[0])!==$identity($name)) continue;
            if (($values[2] ?? '')==='' || strcasecmp((string)$values[2],'Other')===0) $values[2]=$type;
            $values[5]='Active';
            $autoNote='Linked automatically from '.$source.'.';
            $existing=trim((string)($values[6] ?? ''));
            if ($notes!=='') $autoNote.=' '.trim($notes);
            if ($existing==='' || !str_contains($existing,$autoNote)) $values[6]=trim($existing.' '.$autoNote);
            $row['values']=$values;
            $out=$row;
            unset($row);
            return $out;
        }
        unset($row);
        $id='mills-auto-'.substr(hash('sha256',strtolower($name)),0,12);
        $note='Linked automatically from '.$source.'.'.($notes!==''?' '.trim($notes):'');
        $row=['id'=>$id,'values'=>[$name,'',$type,'','','Active',$note]];
        $data['masters']['mills'][]=$row;
        return $row;
    });
}

function tt_deactivate_location_master(string $id): array {
    return tt_mutate_store(function (&$data) use ($id): array {
        if(!isset($data['masters']['mills'])||!is_array($data['masters']['mills']))$data['masters']['mills']=[];
        foreach ($data['masters']['mills'] as &$row) {
            if ((string)($row['id']??'')!==$id) continue;
            $values=array_values((array)($row['values']??[]));
            if(count($values)<=4)$values=[$values[0]??'',$values[1]??'',$values[2]??'','','','Active',$values[3]??''];
            while(count($values)<7)$values[]='';$values[5]='Inactive';$row['values']=$values;$out=$row;unset($row);return $out;
        }
        unset($row);throw new InvalidArgumentException('Location not found.');
    });
}

function tt_active_location_masters(): array {
    return array_values(array_filter((array)(tt_list_masters()['mills']??[]),static fn($row):bool=>strcasecmp((string)(($row['values']??[])[5]??'Active'),'Inactive')!==0));
}

function tt_ensure_data_dir(): void {
    if (!is_dir(TT_DATA_DIR) && !mkdir(TT_DATA_DIR, 0700, true) && !is_dir(TT_DATA_DIR)) throw new RuntimeException('The secure data folder could not be created.');
}

function tt_auth_rate_mutate(callable $callback): mixed {
    tt_ensure_data_dir();
    $handle=fopen(TT_AUTH_RATE_FILE,'c+');
    if($handle===false||!flock($handle,LOCK_EX))throw new RuntimeException('Authentication protection is unavailable.');
    try{
        rewind($handle);$raw=stream_get_contents($handle);$data=$raw?json_decode($raw,true):null;
        if(!is_array($data))$data=[];
        $result=$callback($data);
        rewind($handle);if(!ftruncate($handle,0))throw new RuntimeException('Authentication protection could not be updated.');
        if(fwrite($handle,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Authentication protection could not be saved.');
        fflush($handle);return$result;
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}

function tt_auth_rate_key(string $scope,string $identity): string {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    return hash('sha256',$scope.'|'.strtolower(trim($identity)).'|'.$ip);
}

function tt_auth_retry_after(string $scope,string $identity,int $limit=5,int $window=900): int {
    return tt_auth_rate_mutate(function (&$data) use ($scope,$identity,$limit,$window): int {
        $now=time();$key=tt_auth_rate_key($scope,$identity);$row=is_array($data[$key]??null)?$data[$key]:[];
        $attempts=array_values(array_filter((array)($row['attempts']??[]),static fn($at):bool=>(int)$at>$now-$window));
        $lockedUntil=(int)($row['locked_until']??0);
        if($lockedUntil<=$now&&count($attempts)<$limit){if($attempts)$data[$key]=['attempts'=>$attempts,'locked_until'=>0];else unset($data[$key]);return 0;}
        return max(1,$lockedUntil-$now);
    });
}

function tt_auth_record_failure(string $scope,string $identity,int $limit=5,int $window=900,int $lockSeconds=900): void {
    tt_auth_rate_mutate(function (&$data) use ($scope,$identity,$limit,$window,$lockSeconds): void {
        $now=time();$key=tt_auth_rate_key($scope,$identity);$row=is_array($data[$key]??null)?$data[$key]:[];
        $attempts=array_values(array_filter((array)($row['attempts']??[]),static fn($at):bool=>(int)$at>$now-$window));
        $attempts[]=$now;$lockedUntil=(int)($row['locked_until']??0);
        if(count($attempts)>=$limit)$lockedUntil=max($lockedUntil,$now+$lockSeconds);
        $data[$key]=['attempts'=>$attempts,'locked_until'=>$lockedUntil];
    });
}

function tt_auth_clear_failures(string $scope,string $identity): void {
    tt_auth_rate_mutate(function (&$data) use ($scope,$identity): void {unset($data[tt_auth_rate_key($scope,$identity)]);});
}

function tt_read_store(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_STORE_FILE)) return ['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>tt_default_master_options(), 'master_options_disabled'=>[]];
    $raw = file_get_contents(TT_STORE_FILE);
    $data = $raw === false || $raw === '' ? null : json_decode($raw, true);
    if (!is_array($data)) return ['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>tt_default_master_options(), 'master_options_disabled'=>[]];
    $data=array_merge(['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>tt_default_master_options(), 'master_options_disabled'=>[]], $data);
    $data['masters']=tt_normalize_masters(is_array($data['masters'] ?? null) ? $data['masters'] : []);
    return $data;
}

function tt_mutate_store(callable $callback): mixed {
    $backupLib=__DIR__ . '/backup_lib.php';
    if (is_file($backupLib)) { require_once $backupLib; if (function_exists('tt_maybe_auto_backup')) tt_maybe_auto_backup(); }
    tt_ensure_data_dir();
    $handle = fopen(TT_STORE_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Secure storage is unavailable.');
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = ['users' => [], 'audit' => [], 'masters'=>tt_default_masters()];
        $data = array_merge(['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>tt_default_master_options(), 'master_options_disabled'=>[]], $data);
        $data['masters']=tt_normalize_masters(is_array($data['masters'] ?? null) ? $data['masters'] : []);
        $result = $callback($data);
        rewind($handle);
        if (!ftruncate($handle, 0)) throw new RuntimeException('Secure storage could not be updated.');
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $encoded) === false) throw new RuntimeException('Secure storage could not be written.');
        fflush($handle);
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function tt_has_admin(): bool {
    foreach (tt_read_store()['users'] as $user) if (($user['role'] ?? '') === 'Super Admin') return true;
    return false;
}

function tt_find_user_by_id(int $id): ?array {
    foreach (tt_read_store()['users'] as $user) if ((int)($user['id'] ?? 0) === $id) return $user;
    return null;
}

function tt_find_user_by_username(string $username): ?array {
    foreach (tt_read_store()['users'] as $user) if (strcasecmp((string)($user['username'] ?? ''), $username) === 0) return $user;
    return null;
}

function tt_create_admin(string $username, string $password): int {
    return tt_mutate_store(function (&$data) use ($username, $password): int {
        foreach ($data['users'] as $user) if (($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('Super Admin already exists.');
        $id = random_int(100000, 999999999);
        $data['users'][] = [
            'id' => $id, 'full_name' => 'Salman', 'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'Super Admin',
            'permissions' => ['Mill'=>'all','Exports'=>'all','Accounts'=>'all','Directors'=>'all'],
            'active' => true, 'must_change_password' => false,
            'created_at' => gmdate('c'), 'last_login_at' => null,
        ];
        return $id;
    });
}

function tt_set_last_login(int $id): void {
    tt_mutate_store(function (&$data) use ($id): void {
        foreach ($data['users'] as &$user) if ((int)($user['id'] ?? 0) === $id) { $user['last_login_at'] = gmdate('c'); break; }
        unset($user);
    });
}

function tt_list_public_users(): array {
    return array_map(static function (array $user): array {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        return [
            'id' => (string)$user['id'], 'name' => (string)($user['full_name'] ?? ''),
            'username' => (string)($user['username'] ?? ''), 'role' => (string)($user['role'] ?? ''),
            'location' => (string)($user['location'] ?? 'All authorized locations'),
            'active' => !empty($user['active']), 'modules' => array_keys($permissions),
            'permissions' => $permissions,
            'masterAccess' => !empty($user['master_access']),
            'masterPermissions' => is_array($user['master_permissions'] ?? null) ? $user['master_permissions'] : [],
            'lastActive' => empty($user['last_login_at']) ? 'Not activated' : (string)$user['last_login_at'],
            'mustChangePassword' => !empty($user['must_change_password']),
        ];
    }, tt_read_store()['users']);
}

function tt_generate_temporary_password(): string {
    return 'Tt' . random_int(10, 99) . '-' . bin2hex(random_bytes(4)) . 'A';
}

function tt_create_staff_user(array $input): array {
    $temporaryPassword = tt_generate_temporary_password();
    $id = tt_mutate_store(function (&$data) use ($input, $temporaryPassword): int {
        foreach ($data['users'] as $user) if (strcasecmp((string)($user['username'] ?? ''), $input['username']) === 0) throw new InvalidArgumentException('That username already exists.');
        $id = random_int(100000, 999999999);
        $data['users'][] = [
            'id'=>$id, 'full_name'=>$input['name'], 'username'=>$input['username'],
            'password_hash'=>password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'role'=>$input['role'], 'location'=>$input['location'], 'permissions'=>$input['permissions'],
            'master_access'=>!empty($input['masterAccess']), 'master_permissions'=>$input['masterPermissions'] ?? [],
            'active'=>$input['active'], 'must_change_password'=>true,
            'created_at'=>gmdate('c'), 'last_login_at'=>null,
        ];
        return $id;
    });
    return ['id'=>$id, 'temporaryPassword'=>$temporaryPassword];
}

function tt_update_staff_user(int $id, array $input): void {
    tt_mutate_store(function (&$data) use ($id, $input): void {
        foreach ($data['users'] as $existing) if ((int)($existing['id'] ?? 0) !== $id && strcasecmp((string)($existing['username'] ?? ''), $input['username']) === 0) throw new InvalidArgumentException('That username already exists.');
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            if (($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('The Super Admin account cannot be changed here.');
            $user['full_name']=$input['name']; $user['username']=$input['username'];
            $user['role']=$input['role']; $user['location']=$input['location'];
            $user['permissions']=$input['permissions']; $user['active']=$input['active'];
            $user['master_access']=!empty($input['masterAccess']);
            $user['master_permissions']=$input['masterPermissions'] ?? [];
            unset($user); return;
        }
        unset($user);
        throw new RuntimeException('User not found.');
    });
}

function tt_delete_staff_user(int $id): void {
    tt_mutate_store(function (&$data) use ($id): void {
        foreach ($data['users'] as $user) if ((int)($user['id'] ?? 0) === $id && ($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('The Super Admin account cannot be deleted.');
        $before=count($data['users']);
        $data['users']=array_values(array_filter($data['users'], static fn(array $user): bool => (int)($user['id'] ?? 0) !== $id));
        if ($before === count($data['users'])) throw new RuntimeException('User not found.');
    });
}

function tt_reset_staff_password(int $id): string {
    $temporaryPassword=tt_generate_temporary_password();
    tt_mutate_store(function (&$data) use ($id, $temporaryPassword): void {
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            if (($user['role'] ?? '') === 'Super Admin') throw new RuntimeException('Use the private password-change screen for Super Admin.');
            $user['password_hash']=password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $user['must_change_password']=true; unset($user); return;
        }
        unset($user); throw new RuntimeException('User not found.');
    });
    return $temporaryPassword;
}

function tt_change_own_password(int $id, string $newPassword): void {
    tt_mutate_store(function (&$data) use ($id, $newPassword): void {
        foreach ($data['users'] as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) continue;
            $user['password_hash']=password_hash($newPassword, PASSWORD_DEFAULT);
            $user['must_change_password']=false; unset($user); return;
        }
        unset($user); throw new RuntimeException('User not found.');
    });
}

function tt_recovery_code_valid(string $code): bool {
    $normalized=strtoupper((string)preg_replace('/[^A-Z0-9]/i','',$code));
    return strlen($normalized)>=24 && password_verify($normalized,TT_ADMIN_RECOVERY_HASH);
}

function tt_reset_admin_with_recovery(string $newPassword): int {
    return tt_mutate_store(function (&$data) use ($newPassword): int {
        foreach ($data['users'] as &$user) {
            if (($user['role'] ?? '')!=='Super Admin') continue;
            $user['password_hash']=password_hash($newPassword,PASSWORD_DEFAULT);
            $user['must_change_password']=false;
            $user['recovered_at']=gmdate('c');
            $id=(int)$user['id']; unset($user); return $id;
        }
        unset($user); throw new RuntimeException('Super Admin account not found.');
    });
}

function tt_user_can_open_module(array $user, string $module): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $permissions=$user['permissions'][$module] ?? [];
    if ($permissions === 'all') return true;
    if (!is_array($permissions)) return false;
    if (in_array('View', $permissions, true)) return true; // legacy records
    foreach ($permissions as $actions) if (is_array($actions) && in_array('View', $actions, true)) return true;
    return false;
}

function tt_user_can_access_masters(array $user): bool {
    if (($user['role'] ?? '')==='Super Admin') return true;
    if (empty($user['master_access'])) return false;
    foreach ((array)($user['master_permissions'] ?? []) as $actions) {
        if (is_array($actions) && array_intersect(['View','Create','Edit','Deactivate','View Documents','Download Documents'],$actions)) return true;
    }
    return false;
}

function tt_user_can_master(array $user,string $type,string $action='View'): bool {
    if (($user['role'] ?? '')==='Super Admin') return true;
    if (!tt_user_can_access_masters($user)) return false;
    if (in_array($type,['purchase_kat','commodities'],true)) $type='purchase_products';
    $actions=(array)($user['master_permissions'][$type] ?? []);
    if (in_array($action,$actions,true)) return true;
    return $action==='View' && (bool)array_intersect(['Create','Edit','Deactivate','View Documents','Download Documents'],$actions);
}

function tt_user_visible_masters(array $user): array {
    $masters=tt_list_masters();
    if (($user['role'] ?? '')==='Super Admin') return $masters;
    $out=[];
    foreach ($masters as $type=>$rows) if (tt_user_can_master($user,$type,'View')) $out[$type]=$rows;
    return $out;
}

/**
 * Entity access is stored inside the Accounts permission matrix as
 * entity-tti/entity-brm/entity-tg rows. Existing owner records using `all`
 * retain full access; restricted staff must be granted each legal book
 * explicitly. This keeps entity scope in the same audited user record as the
 * per-screen permissions and prevents a client-supplied entity from widening
 * access at an API endpoint.
 */
function tt_user_can_access_entity(array $user, string $entity, string $action = 'View'): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $entity = strtoupper(trim($entity));
    if (!in_array($entity, ['TTI','BRM','TG'], true)) return false;
    $permissions = $user['permissions']['Accounts'] ?? null;
    if ($permissions === 'all') return true;
    if (!is_array($permissions)) return false;
    $row = $permissions['entity-' . strtolower($entity)] ?? [];
    if (!is_array($row)) return false;
    return in_array($action, $row, true)
        || ($action === 'View' && (in_array('Create', $row, true) || in_array('Edit', $row, true) || in_array('Approve', $row, true)));
}

function tt_user_accounts_entities(array $user): array {
    return array_values(array_filter(['TTI','BRM','TG'], static fn(string $entity): bool => tt_user_can_access_entity($user, $entity, 'View')));
}

function tt_user_landing_url(array $user): string {
    // The owner-level Super Admin always starts in the Control Centre.
    // Operational users continue directly to their assigned workspace.
    if (($user['role'] ?? '') === 'Super Admin') return 'index.php';
    if (tt_user_can_access_masters($user)) return 'index.php?view=masters';
    if (tt_user_can_open_module($user, 'Accounts')) return 'accounts/index.php';
    if (tt_user_can_open_module($user, 'Directors')) return 'directors/index.php';
    foreach (['Mill'=>'milling','Exports'=>'exports'] as $name=>$id) {
        if (tt_user_can_open_module($user, $name)) return 'module.php?id=' . $id;
    }
    return 'staff-home.php';
}

function tt_list_masters(): array { return tt_visible_masters(tt_read_store()['masters']); }

function tt_create_master(string $type, array $values): string {
    return tt_mutate_store(function (&$data) use ($type,$values): string {
        if (!isset($data['masters'][$type]) || !is_array($data['masters'][$type])) $data['masters'][$type]=[];
        $id=$type.'-'.random_int(100000,999999999);
        $data['masters'][$type][]= ['id'=>$id,'values'=>$values];
        return $id;
    });
}

function tt_update_master(string $type, string $id, array $values): void {
    tt_mutate_store(function (&$data) use ($type,$id,$values): void {
        if (!isset($data['masters'][$type]) || !is_array($data['masters'][$type])) throw new RuntimeException('Master type not found.');
        foreach ($data['masters'][$type] as &$row) if (($row['id'] ?? '')===$id) { $row['values']=$values; unset($row); return; }
        unset($row); throw new RuntimeException('Master record not found.');
    });
}

function tt_delete_master(string $type, string $id): void {
    tt_mutate_store(function (&$data) use ($type,$id): void {
        if (!isset($data['masters'][$type]) || !is_array($data['masters'][$type])) throw new RuntimeException('Master type not found.');
        $before=count($data['masters'][$type]);
        $data['masters'][$type]=array_values(array_filter($data['masters'][$type],static fn(array $row): bool => ($row['id'] ?? '')!==$id));
        if ($before===count($data['masters'][$type])) throw new RuntimeException('Master record not found.');
    });
}

function tt_current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $user = tt_find_user_by_id((int)$_SESSION['user_id']);
    return ($user && !empty($user['active'])) ? $user : null;
}

function tt_api_json_error(int $status,string $message): never {
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode(['ok'=>false,'error'=>$message]);exit;
}

function tt_api_entity_policy(string $path): array {
    $endpoint=basename($path);
    $entityIndependent=['operations.php','operations.mysql.php','export_documents.php','export_customers.php','masters.php','master_documents.php','users.php','backup.php','accounts_bulk_test_cleanup.php','location-master.php','commodity_lookup.php','bag_bill_file.php','bridge_outbox.php'];
    if(in_array($endpoint,$entityIndependent,true))return['required'=>false,'fixed'=>''];
    if(str_starts_with($endpoint,'tg_'))return['required'=>true,'fixed'=>'TG'];
    return['required'=>true,'fixed'=>''];
}

function tt_require_login(): array {
    $user = tt_current_user();
    $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if (!$user) {
        $_SESSION = [];
        if (str_starts_with($path,'/api/')) {
            tt_api_json_error(401,'Your session has expired. Sign in again.');
        }
        header('Location: /login.php');
        exit;
    }
    if(str_starts_with($path,'/api/')&&tt_user_can_open_module($user,'Accounts')){
        $policy=tt_api_entity_policy($path);
        $entity=strtoupper(trim((string)($_GET['entity']??$_POST['entity']??'')));
        if($entity===''&&strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){
            $raw=file_get_contents('php://input')?:'';
            $body=$raw!==''?json_decode($raw,true):null;
            if(is_array($body))$entity=strtoupper(trim((string)($body['entity']??'')));
        }
        if($entity===''&&$policy['fixed']!=='')$entity=$policy['fixed'];
        if($entity===''&&!empty($policy['required'])&&($user['role']??'')!=='Super Admin')tt_api_json_error(403,'An authorized legal entity is required.');
        if($entity!==''&&!in_array($entity,['TTI','BRM','TG'],true))tt_api_json_error(403,'Select a valid legal entity.');
        if($entity!==''){
            $read=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='GET';
            $allowed=$read?tt_user_can_access_entity($user,$entity,'View'):(tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')||tt_user_can_access_entity($user,$entity,'Approve'));
            if(!$allowed)tt_api_json_error(403,'You do not have permission for this legal entity.');
        }
    }
    return $user;
}

function tt_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function tt_verify_csrf(string $token): bool {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function tt_audit(?int $userId, string $username, string $action): void {
    tt_mutate_store(function (&$data) use ($userId, $username, $action): void {
        array_unshift($data['audit'], ['user_id'=>$userId, 'username'=>$username, 'action'=>$action, 'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '', 'created_at'=>gmdate('c')]);
        if (count($data['audit']) > 5000) $data['audit'] = array_slice($data['audit'], 0, 5000);
    });
}
