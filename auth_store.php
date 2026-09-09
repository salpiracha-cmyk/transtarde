<?php
declare(strict_types=1);

// Keep live credentials and master records outside public_html. Hostinger Git
// deployments replace the application directory, but must never replace the
// operational data created by Salman and his staff.
const TT_DATA_DIR = __DIR__ . '/../transtrade_private';
const TT_STORE_FILE = TT_DATA_DIR . '/auth.json';

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
        'purchase_kat'=>[
            ['id'=>'purchase_kat-1','values'=>['Rice','IRRI-6','Broken','20% free','20–30: 1 paisa/%; 31–35: 3 paisa/%; 36–40: 8 paisa/%; 41–45: 15 paisa/%; 46–50: 20 paisa/%; 51–55: 25 paisa/%; 56–60: 40 paisa/%','paisa per %','Default profile','Active','Known Transtrade purchase KAT rule.']],
            ['id'=>'purchase_kat-2','values'=>['Rice','IRRI-6','Chalky','5% free / operational default','Above free allowance: 10 paisa per excess percentage point.','paisa per %','Default profile','Draft – review required','5% is the current Arrival default. Earlier discussion included 4%; keep editable until Salman confirms final active free allowance.']],
            ['id'=>'purchase_kat-3','values'=>['Rice','IRRI-6','Damage / Yellow','2% free','Above 2% up to 5%: 10 paisa per excess percentage point; above 5%: 25 paisa per excess percentage point.','paisa per %','Default profile','Active','Known Transtrade purchase KAT rule.']],
            ['id'=>'purchase_kat-4','values'=>['Rice','IRRI-6','Moisture','14% free','14.1–14.5: 0.5% weight deduction; 14.6–15.0: 1% weight deduction; above 15.0 up to 16.0: 2% weight deduction.','weight %','Standard 14% profile','Draft – review required','Known discussed slab. Keep >16 handling manual/reject until explicitly approved.']],
            ['id'=>'purchase_kat-5','values'=>['Rice','IRRI-6','Moisture','15% free / seasonal alternative','Seasonal alternate discussed: 15% free, with optional half-kg treatment up to 15.4 depending on season. Exact slab above this point must be selected/confirmed before activation.','weight / seasonal profile','Seasonal 15% profile','Draft – review required','Do not infer or auto-switch seasonal moisture profile.']],
            ['id'=>'purchase_kat-6','values'=>['Rice','IRRI-6','Paddy','80 grains operational default','No final automatic KAT slab confirmed. Above-default handling remains manual until a rule is approved.','No. of Grains','Default profile','Draft – review required','Paddy is a plain grain count, not a percentage.']],
            ['id'=>'purchase_kat-7','values'=>['Rice','C-9','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Variety profile','Draft – review required','Placeholder intentionally prevents IRRI-6 KAT from being silently reused.']],
            ['id'=>'purchase_kat-8','values'=>['Rice','PK-386','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Variety profile','Draft – review required','Internal purchase KAT is not derived from export standard.']],
            ['id'=>'purchase_kat-9','values'=>['Rice','Super Kernel Basmati','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Variety profile','Draft – review required','Internal purchase KAT is not derived from export standard.']],
            ['id'=>'purchase_kat-10','values'=>['Rice','D-98 / PK-198','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Variety profile','Draft – review required','Internal purchase KAT is not derived from export standard.']],
            ['id'=>'purchase_kat-11','values'=>['Rice','1121 Basmati','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Variety profile','Draft – review required','Internal purchase KAT is not derived from export standard.']],
            ['id'=>'purchase_kat-12','values'=>['Rice','Basmati 385 / PK-385','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Reference variety','Draft – review required','Reference-only until Transtrade activates the variety.']],
            ['id'=>'purchase_kat-13','values'=>['Rice','IRRI-9','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Reference variety','Draft – review required','Reference-only until Transtrade activates the variety.']],
            ['id'=>'purchase_kat-15','values'=>['Rice','Basmati 515','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Reference variety','Draft – review required','Reference-only until Transtrade activates the variety.']],
            ['id'=>'purchase_kat-16','values'=>['Rice','KS-282','Broken / Chalky / Damage / Moisture / Paddy','Not confirmed','No automatic deduction. Complete variety-specific rule before activation.','Profile','Reference variety','Draft – review required','Reference-only until Transtrade activates the variety.']],
        ],
        'parties'=>[['id'=>'parties-1','values'=>['Shams','BRK-001','Broker']],['id'=>'parties-2','values'=>['Sample Overseas Buyer','BUY-001','Export buyer']]],
        'mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mill','TTI-MILL','Own mill']],['id'=>'mills-2','values'=>['Karachi Office','KHI-OFF','Office']]],
        'banks'=>[
            ['id'=>'banks-tti','values'=>['Company Account','TTI — Transtrade International','','Transtrade International','Meezan Bank Limited','Jodia Bazar Branch, Karachi','Pakistan','PKR','','','','Pakistan operating account','Accounts / Directors; document use to be confirmed','Incomplete — enter account number/IBAN and confirm use']],
            ['id'=>'banks-brm','values'=>['Company Account','BRM — Buksh Rice Mills','','Buksh Rice Mills','Meezan Bank Limited','Karachi','Pakistan','PKR','','','','Mill / operating account','Accounts / Directors; document use to be confirmed','Incomplete — enter branch/account number/IBAN']],
            ['id'=>'banks-tg','values'=>['Company Account','TG — Trans Grains Foodstuff Trading L.L.C','','Trans Grains Foodstuff Trading L.L.C','Habib Bank AG Zurich','Baniyas Square, Dubai','United Arab Emirates','USD','','','','TG offshore trading account','Authorized TG / Exports / Accounts / Directors only','Incomplete — enter account number/IBAN/SWIFT and confirm use']],
        ],
    ];
    // Only the approved IRRI-6 purchase KAT is seeded. Other rice varieties
    // and corn require their own owner-approved rule sets and must never
    // inherit IRRI-6 deductions implicitly.
    $masters['purchase_kat']=array_values(array_filter(
        $masters['purchase_kat'],
        static fn(array $row): bool => strcasecmp((string)($row['values'][1] ?? ''),'IRRI-6')===0
    ));
    return $masters;
}

function tt_normalize_masters(array $masters): array {
    $defaults=tt_default_masters();
    foreach ($defaults as $type=>$rows) if (!isset($masters[$type]) || !is_array($masters[$type])) $masters[$type]=$rows;

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
        $code=strtoupper(trim((string)($row['values'][1] ?? ''))); if ($code==='') continue; $seen[$code]=true;
        if (isset($companyDefaults[$code]) && count((array)($row['values'] ?? []))<=3) $row['values']=$companyDefaults[$code]['values'];
        if ($code==='BRM' && (($row['values'][0] ?? '')==='BRM')) $row['values']=$companyDefaults['BRM']['values'];
    }
    unset($row);
    foreach ($companyDefaults as $code=>$row) if (empty($seen[$code])) $masters['companies'][]=$row;

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
    foreach ($productDefaults as $code=>$row) if (empty($seenProducts[$code])) $masters['products'][]=$row;

    foreach (['parties','mills'] as $simpleType) {
        foreach ($masters[$simpleType] as &$row) {
            $values=array_values((array)($row['values'] ?? []));
            if (count($values)===3) $values=[$values[0] ?? '',$values[1] ?? '',$values[2] ?? '',''];
            while (count($values)<4) $values[]='';
            $row['values']=$values;
        }
        unset($row);
    }
    foreach ($masters['banks'] as &$row) {
        $values=(array)($row['values'] ?? []);
        if (count($values)<=3) $row['values']=['Company Account','','',$values[0] ?? '','','','','','', '', '', '',$values[2] ?? '','Incomplete legacy bank record · reference '.($values[1] ?? '')];
    }
    unset($row);
    foreach ($masters['purchase_kat'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        while (count($values)<10) $values[]='';
        if (($values[9] ?? '')==='') {
            $parameter=strtolower((string)($values[2] ?? ''));
            $variety=strtolower((string)($values[1] ?? ''));
            $ranges=[];
            if (str_contains($variety,'irri-6') && $parameter==='broken') $ranges=[
                ['from'=>'20','to'=>'30','value'=>'1','unit'=>'paisa per %'],['from'=>'30','to'=>'35','value'=>'3','unit'=>'paisa per %'],
                ['from'=>'35','to'=>'40','value'=>'8','unit'=>'paisa per %'],['from'=>'40','to'=>'45','value'=>'15','unit'=>'paisa per %'],
                ['from'=>'45','to'=>'50','value'=>'20','unit'=>'paisa per %'],['from'=>'50','to'=>'55','value'=>'25','unit'=>'paisa per %'],
                ['from'=>'55','to'=>'60','value'=>'40','unit'=>'paisa per %']
            ];
            elseif (str_contains($variety,'irri-6') && str_contains($parameter,'damage')) $ranges=[
                ['from'=>'2','to'=>'5','value'=>'10','unit'=>'paisa per %'],['from'=>'5','to'=>'','value'=>'25','unit'=>'paisa per %']
            ];
            elseif (str_contains($variety,'irri-6') && $parameter==='chalky') $ranges=[['from'=>'5','to'=>'','value'=>'10','unit'=>'paisa per %']];
            elseif (str_contains($variety,'irri-6') && $parameter==='moisture' && str_contains((string)($values[6] ?? ''),'14%')) $ranges=[
                ['from'=>'14','to'=>'14.5','value'=>'0.5','unit'=>'weight %'],['from'=>'14.5','to'=>'15','value'=>'1','unit'=>'weight %'],['from'=>'15','to'=>'16','value'=>'2','unit'=>'weight %']
            ];
            if ($ranges) $values[9]=json_encode($ranges,JSON_UNESCAPED_SLASHES);
        }
        $row['values']=$values;
    }
    unset($row);
    foreach ($masters['products'] as &$row) {
        $values=array_values((array)($row['values'] ?? []));
        while (count($values)<21) $values[]='';
        $row['values']=$values;
    }
    unset($row);
    return $masters;
}

function tt_visible_masters(array $masters): array {
    $visible=['companies','commodities','products','purchase_kat','parties','mills','banks'];
    return array_intersect_key(tt_normalize_masters($masters),array_flip($visible));
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

function tt_default_party_roles(): array {
    return ['Buyer','Supplier','Broker','Export Buyer','Local Buyer','Customer','Agent','Service Provider','Other'];
}

function tt_master_options(): array {
    $data=tt_read_store();
    $options=is_array($data['master_options'] ?? null) ? $data['master_options'] : [];
    $roles=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),(array)($options['party_roles'] ?? [])))));
    foreach (tt_default_party_roles() as $role) if (!in_array($role,$roles,true)) $roles[]=$role;
    sort($roles,SORT_NATURAL|SORT_FLAG_CASE);
    return ['party_roles'=>$roles];
}

function tt_add_party_role_option(string $role): string {
    $role=trim(preg_replace('/\\s+/',' ',$role) ?? '');
    if ($role==='' || strlen($role)>80) throw new InvalidArgumentException('Enter a valid Party Role.');
    tt_mutate_store(function (&$data) use ($role): void {
        if (!isset($data['master_options']) || !is_array($data['master_options'])) $data['master_options']=[];
        $roles=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),(array)($data['master_options']['party_roles'] ?? []))));
        foreach ($roles as $existing) if (strcasecmp($existing,$role)===0) return;
        $roles[]=$role;
        sort($roles,SORT_NATURAL|SORT_FLAG_CASE);
        $data['master_options']['party_roles']=$roles;
    });
    return $role;
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

function tt_upsert_location_master(string $name,string $type,string $source='System',string $notes=''): array {
    $name=trim(preg_replace('/\\s+/',' ',$name) ?? '');
    if ($name==='' || strlen($name)>160) throw new InvalidArgumentException('Enter a valid mill / location name.');
    $type=tt_normalize_location_type($type);
    $source=trim($source) ?: 'System';
    return tt_mutate_store(function (&$data) use ($name,$type,$source,$notes): array {
        if (!isset($data['masters']['mills']) || !is_array($data['masters']['mills'])) $data['masters']['mills']=[];
        foreach ($data['masters']['mills'] as &$row) {
            $values=array_values((array)($row['values'] ?? []));
            while (count($values)<4) $values[]='';
            if (strcasecmp(trim((string)$values[0]),$name)!==0) continue;
            if (($values[2] ?? '')==='' || strcasecmp((string)$values[2],'Other')===0) $values[2]=$type;
            $autoNote='Linked automatically from '.$source.'.';
            $existing=trim((string)($values[3] ?? ''));
            if ($notes!=='') $autoNote.=' '.trim($notes);
            if ($existing==='' || !str_contains($existing,$autoNote)) $values[3]=trim($existing.' '.$autoNote);
            $row['values']=$values;
            $out=$row;
            unset($row);
            return $out;
        }
        unset($row);
        $id='mills-auto-'.substr(hash('sha256',strtolower($name)),0,12);
        $note='Linked automatically from '.$source.'.'.($notes!==''?' '.trim($notes):'');
        $row=['id'=>$id,'values'=>[$name,'',$type,$note]];
        $data['masters']['mills'][]=$row;
        return $row;
    });
}

function tt_ensure_data_dir(): void {
    if (!is_dir(TT_DATA_DIR) && !mkdir(TT_DATA_DIR, 0700, true) && !is_dir(TT_DATA_DIR)) throw new RuntimeException('The secure data folder could not be created.');
}

function tt_read_store(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_STORE_FILE)) return ['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>['party_roles'=>tt_default_party_roles()]];
    $raw = file_get_contents(TT_STORE_FILE);
    $data = $raw === false || $raw === '' ? null : json_decode($raw, true);
    if (!is_array($data)) return ['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>['party_roles'=>tt_default_party_roles()]];
    $data=array_merge(['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>['party_roles'=>tt_default_party_roles()]], $data);
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
        $data = array_merge(['users' => [], 'audit' => [], 'masters'=>tt_default_masters(), 'master_options'=>['party_roles'=>tt_default_party_roles()]], $data);
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
    return hash_equals('e008e96b8c1bebbe165ebd3132afc540a9c237ca6dc1d2ac0b47c98edf12648c',hash('sha256',$normalized));
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

function tt_user_landing_url(array $user): string {
    if (($user['role'] ?? '') === 'Super Admin') return 'index.php';
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
        foreach ($data['masters'][$type] as &$row) if (($row['id'] ?? '')===$id) { $row['values']=$values; unset($row); return; }
        unset($row); throw new RuntimeException('Master record not found.');
    });
}

function tt_delete_master(string $type, string $id): void {
    tt_mutate_store(function (&$data) use ($type,$id): void {
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

function tt_require_login(): array {
    $user = tt_current_user();
    if (!$user) { $_SESSION = []; header('Location: /login.php'); exit; }
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
