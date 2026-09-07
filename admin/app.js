(() => {
  "use strict";

  const STORAGE_KEY = "transtrade_super_admin_v1";
  const STATE_VERSION = 3;
  const ACTIONS = ["View", "Create", "Edit", "Delete", "Print", "Approve", "Reports"];
  const ICON_ACTIONS = ["View", "Create", "Edit"];
  const MODULE_ICONS = {
    Mill: [
      ["stock","Stock"],["queue","Arrival List"],["arrival","Arrival / Pohanch"],["newbags","New Export Bags"],
      ["instructions","Exports Specifications"],["production","Production"],["export","Export Loading"],["local","Local Sales"],
      ["petty","Petty Cash"],["labour","Processing Expense"],["reprocessbill","Reprocessing Bill"],["oldbags","Used Bags (In & Out)"],["reports","Reports"]
    ],
    Exports: [
      ["active","Active Shipments"],["contracts","Sales Contracts"],["completed","Completed Shipments"],["cancelled","Cancelled"],
      ["fi","FI Register"],["reports","Reports & Registers"],["contract","Sales Contract"],["bags","Bag Order"],
      ["production","Production Instructions"],["loading","Loading Instructions"],["customs","Customs Documents"],["bl","B/L Documents"],
      ["commercial","Commercial Documents"],["coo","Certificate of Origin"],["certs","Certificates"],["cover","Bank Covering & Dispatch"],
      ["tg","TG Documents"],["lcdraft","L/C Exchange Draft"],["print","Document Output"],["history","History & Versions"]
    ],
    Accounts: [
      ["dashboard","Accounts Dashboard"],["vouchers","Vouchers"],["payments","Payments & Receipts"],["ledgers","Party Ledgers"],
      ["banking","Banking"],["receivables","Receivables"],["payables","Payables"],["expenses","Expenses"],["reports","Reports"]
    ]
  };
  const SESSION = window.TT_SESSION || { name: "Salman", username: "salman", role: "Super Admin", permissions: { Mill: "all", Exports: "all", Accounts: "all", Directors: "all" }, csrf: "" };
  const IS_SUPER_ADMIN = SESSION.role === "Super Admin";
  const MODULES = [
    { id: "milling", name: "Mill", code: "M", color: "#16815a", soft: "#e7f7f0", status: "Live trial", state: "green", version: "V3.3.5 Working", description: "Arrivals, stocks, production, bags, loading and mill operations.", href: "module.php?id=milling" },
    { id: "exports", name: "Exports", code: "E", color: "#1769d2", soft: "#eaf2ff", status: "Live trial", state: "green", version: "V2.6 Latest Stabilized", description: "Contracts, export orders, shipment planning and documentation.", href: "module.php?id=exports" },
    { id: "accounts", name: "Accounts", code: "A", color: "#8a55c7", soft: "#f3ecfb", status: "Awaiting module", state: "amber", version: "Not connected", description: "Purchases, ledgers, banking, receivables, payables and reporting." },
    { id: "directors", name: "Directors", code: "D", color: "#d17b0f", soft: "#fff3e2", status: "Awaiting module", state: "amber", version: "Not connected", description: "Consolidated oversight, Cashflow, alerts, approvals and reports." }
  ];

  const MASTER_TYPES = [
    {
      id: "companies", name: "Companies", description: "Define what each legal/group company means to Transtrade, not only its name.",
      fields: [
        { label: "Legal company name", required: true }, { label: "Short code", required: true }, { label: "Country" },
        { label: "Entity scope", type: "select", options: ["", "Pakistan", "Offshore", "Other"] },
        { label: "Company roles", type: "checks", full: true, options: ["Group Company", "Pakistan Operating Entity", "Offshore Export Contracting", "Exporter", "Mill / Processor", "Seller", "Buyer", "Intercompany", "Accounting Entity"] },
        { label: "TG special handling", type: "select", options: ["No", "Yes"] },
        { label: "System behaviour / notes", type: "textarea", full: true }
      ],
      rows: [
        ["Transtrade International", "TTI", "Pakistan", "Pakistan", "Group Company; Pakistan Operating Entity; Exporter; Seller; Buyer; Accounting Entity", "No", "Primary Pakistan operating/export entity."],
        ["Buksh Rice Mills", "BRM", "Pakistan", "Pakistan", "Group Company; Mill / Processor; Seller; Buyer; Accounting Entity", "No", "Mill/processing entity and authorized document identity."],
        ["Trans Grains Foodstuff Trading L.L.C", "TG", "United Arab Emirates", "Offshore", "Group Company; Offshore Export Contracting; Intercompany; Accounting Entity", "Yes", "TG-linked group workflow. Keep Pakistan and offshore accounting/legal records separated while allowing authorized group-owner visibility."]
      ]
    },
    {
      id: "commodities", name: "Commodity Master", description: "Expandable commodity setup used by Soda, quality, KAT and Accounts mappings.",
      fields: [
        { label: "Commodity", required: true }, { label: "Code", required: true }, { label: "Base unit" },
        { label: "Soda / contract enabled", type: "select", options: ["Yes", "No"] }, { label: "Quality / specification profile" },
        { label: "KAT / deduction profile" }, { label: "Default accounting mapping" }, { label: "Notes", type: "textarea", full: true }
      ],
      rows: [
        ["Rice", "RICE", "MT / KG", "Yes", "PSQCA PS:3342-2007 baseline + variety/contract profile", "Variety-specific purchase KAT", "Rice purchase / stock mappings", "PSQCA lists PS:3342-2007 Rice (1st Revision). Basmati products also use TDAP Basmati GI identity requirements where applicable. Product/contract defect limits remain profile-specific; export specs never create purchase KAT automatically."],
        ["Corn", "CORN", "MT / KG", "Yes", "Corn-specific", "Corn-specific", "Corn purchase / stock mappings", "Use the same expandable Soda framework as rice."],
        ["Sesame Seed", "SESAME", "MT / KG", "Yes", "To configure", "To configure", "To configure", "Future-ready commodity; may remain partly configured until activated."]
      ]
    },
    {
      id: "products", name: "Products & Quality", description: "Pre-filled Transtrade, Pakistan-origin trade and market-benchmark profiles. PSQCA PS:3342-2007 is stored as the general Pakistan rice baseline; numeric variety/grade limits remain source- and contract-specific. For Basmati, TDAP GI identity characteristics are retained in the source/basis notes. All fields remain editable.",
      fields: [
        { label: "Commodity", required: true }, { label: "Variety / product", required: true }, { label: "Processing / grade" }, { label: "Code", required: true },
        { label: "Origin" }, { label: "Profile / use" }, { label: "Avg. grain length" }, { label: "Broken" }, { label: "Moisture" },
        { label: "Damaged / Shriveled / Yellow" }, { label: "Chalky / Immature" }, { label: "Contrasting / Other varieties" },
        { label: "Foreign grains" }, { label: "Foreign matter" }, { label: "Paddy" }, { label: "Red kernels / Red rice" },
        { label: "Under-milled / Red-striped" }, { label: "Milling / polishing" },
        { label: "Additional quality wording", type: "textarea", full: true }, { label: "Source / basis", type: "textarea", full: true }
      ],
      rows: [
        ["Rice", "IRRI-6", "White Rice 5% Broken", "IR6-W5", "Pakistan", "Active Transtrade default", "6.0 mm", "5% max", "14% max", "2.5% max", "5% max", "4% max", "", "0.8% max", "0.5% max", "1% max", "2% max", "Well milled; double-polished; well sortexed", "Free from live insects, bad odour and rice fit for human consumption. New crop as stated in contract.", "Transtrade / TG 2026 specimen working specification. 6.0 mm grain-length reference cross-checked against current Pakistan market benchmark."],
        ["Rice", "IRRI-6", "White Rice 25% Broken", "IR6-W25", "Pakistan", "Historical Transtrade reference", "6.0 mm basis", "25% max", "14% max", "6.5% max", "12% max", "", "", "1.2% max", "0.8% max", "", "4% max combined red and/or undermilled", "Reasonably well milled", "Free from live insects, bad odour and rice fit for human consumption. 2/3 size and above counted as full grain on 6 mm basis.", "Transtrade SILAC 2009 specimen. Kept as editable historical/reference profile, not a silent current default."],
        ["Rice", "IRRI-6", "White Rice 5% Broken", "IR6-W5-SP", "Pakistan", "Reference market benchmark – inactive", "6.0 mm", "5% max", "14% max", "1.5% max", "2% max", "2% max", "0.5% max", "0.5% max", "1 per 100 grains max", "1% max", "1% max", "Well milled; min 40 Kett", "Free from live insects, bad odour and rice fit for human consumption.", "S&P Global Specifications Guide July 2026 — Pakistan Long Grain White Rice 5% Broken FOB assessment benchmark. Reference only; it does not replace Transtrade's active buyer/contract profile."],
        ["Rice", "IRRI-6", "White Rice 25% Broken", "IR6-W25-SP", "Pakistan", "Reference market benchmark – inactive", "6.0 mm", "25% max", "14% max", "4% max", "10% max", "9% max", "2.5% max", "1.2% max", "5 per 100 grains max", "3% max", "3% max", "Reasonably well milled; min 35 Kett", "Free from live insects, bad odour and rice fit for human consumption.", "S&P Global Specifications Guide July 2026 — Pakistan Long Grain White Rice 25% Broken FOB assessment benchmark. Reference only."],
        ["Rice", "IRRI-6", "100% Broken", "IR6-B100-SP", "Pakistan", "Reference market benchmark – inactive", "", "100% max", "14% max", "10% max", "20% max", "", "4% max", "2% max", "5 per 100 grains max", "6% max", "", "Well milled", "Free from live insects, bad odour and rice fit for human consumption.", "S&P Global Specifications Guide July 2026 — Pakistan Long Grain White Rice 100% Broken FOB assessment benchmark. Kept separate from Transtrade B2 Sortex by-product."],
        ["Rice", "IRRI-6", "Parboiled Rice 5% Broken", "IR6-P5", "Pakistan", "Active product – commercial reference", "6.0 mm", "5% max", "14% max", "1.5% max", "4% max", "", "0.5% max", "0.5% max", "0.2% max", "", "1.5% max", "Double silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption.", "Common Pakistan exporter commercial profile; review against buyer contract before use."],
        ["Rice", "C-9", "White Rice 5% Broken", "C9-W5", "Pakistan", "Active Transtrade product – reference profile", "6.8 mm", "5% max", "13.5% max", "1.5% max", "4% max", "7% max", "0.5% max", "0.5% max", "15 pcs/kg max", "", "2% max", "Double silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption.", "Pakistan exporter reference profile commonly sold as IRRI-9/C-9; TDAP identifies C-9 among Pakistan non-Basmati export varieties. Kept as C-9 in Transtrade because that is the business variety name."],
        ["Rice", "C-9", "Parboiled / Sella 5% Broken", "C9-P5", "Pakistan", "Active product – reference profile", "6.8 mm", "5% max", "13.5% max", "1.5% max", "4% max", "7% max", "0.5% max", "0.5% max", "15 pcs/kg max", "", "2% max", "Silky polished; colour sortexed; parboiled", "Free from live insects, bad odour and rice fit for human consumption.", "Commercial reference based on Pakistan IRRI-9/C-9 parboiled export profiles; confirm processing-specific buyer limits."],
        ["Rice", "PK-386", "White Rice", "PK386-W", "Pakistan", "Active Transtrade product – grade selectable", "6.8–6.85 mm", "2–5% max (grade dependent)", "13–14% max", "1.5% max", "3–4% max", "7% max", "0.5% max", "0.5% max", "0.2 per 100 grains max / profile dependent", "", "2% max", "Double / silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption.", "Pakistan exporter references show 2% premium and 5% common export grades. Exact grade must be selected per contract."],
        ["Rice", "PK-386", "Parboiled / Sella", "PK386-P", "Pakistan", "Active product – grade selectable", "6.8–6.85 mm", "2–5% max (grade dependent)", "13–14% max", "1.5% max", "3–4% max", "7% max", "0.5% max", "0.5% max", "0.2 per 100 grains max / profile dependent", "", "2% max", "Colour sortexed; parboiled / sella", "Free from live insects, bad odour and rice fit for human consumption.", "Commercial Pakistan PK-386 reference. Exact limits remain contract-specific and editable."],
        ["Rice", "Super Kernel Basmati", "White Rice", "SKB-W", "Pakistan", "Active Transtrade product – common export profile", "7.0–7.2 mm", "2% max", "13% max", "1% max", "3% max", "7% max", "0.1% max", "0.1% max", "0.2% max", "", "2% max", "Double silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.", "Common Pakistan exporter profile. Current S&P Pakistan benchmark uses a different assessment basket; contract selection remains authoritative."],
        ["Rice", "Super Kernel Basmati", "Parboiled / Sella", "SKB-P", "Pakistan", "Reference Pakistan benchmark", "7.2 mm", "4% max", "14% max", "1% max", "1% max", "7% max", "0.05% max", "0.1% max", "0.1 per 100 grains max", "", "2% max", "Very well milled", "Free from live insects, bad odour and rice fit for human consumption.", "S&P Global Specifications Guide — Pakistan Super Kernel Parboiled market assessment benchmark, July 2026. Editable; buyer contract may use tighter 2% grade."],
        ["Rice", "D-98 / PK-198", "White Rice", "D98-W", "Pakistan", "Active Transtrade product – common export profile", "6.8 mm", "2% max", "13% max", "1.5% max", "3% max", "7% max", "0.2% max", "0.1% max", "0.2% max", "", "2% max", "Double silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.", "REAP lists Basmati D-98 / PK-198. Limits pre-filled from common Pakistan exporter D-98 profiles."],
        ["Rice", "D-98 / PK-198", "Parboiled / Sella", "D98-P", "Pakistan", "Active product – reference profile", "6.8 mm", "2% max", "13% max", "1.5% max", "3% max", "7% max", "0.2% max", "0.1% max", "0.2% max", "", "2% max", "Colour sortexed; parboiled / sella", "Free from live insects, bad odour and rice fit for human consumption.", "Commercial D-98 reference profile; confirm buyer-specific parboiled limits."],
        ["Rice", "1121 Basmati", "White Rice", "1121-W", "Pakistan", "Active Transtrade product – common export profile", "8.0–8.2 mm", "2% max", "13% max", "1.5% max", "3% max", "7% max", "0.2% max", "0.1% max", "0.2% max", "", "2% max", "Double silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.", "Common Pakistan 1121 white export profile. TDAP Basmati GI Book lists PK 1121 Aromatic as a registered Pakistan Basmati variety; Punjab Agriculture lists 8.16 mm varietal kernel length. Commercial defect limits remain editable/contract-specific."],
        ["Rice", "1121 Basmati", "Steam 2% Broken", "1121-S", "Pakistan", "Reference Pakistan benchmark", "8.0 mm", "2% max", "13% max", "0.5% max", "3% max", "7% max", "", "", "", "0.5% max", "0.5% max", "Very well milled; min 38 Kett", "Free from live insects, bad odour and rice fit for human consumption.", "S&P Global Specifications Guide — Pakistan 1121 Steam Basmati market assessment benchmark, July 2026; includes 2% max ungelatinized kernels. TDAP Basmati GI Book lists PK 1121 Aromatic as a registered Pakistan Basmati variety."],
        ["Rice", "1121 Basmati", "Parboiled / Sella 2% Broken", "1121-P", "Pakistan", "Active product – Pakistan benchmark", "8.0 mm", "2% max", "13% max", "0.5% max", "4% max", "7% max", "0.05% max", "0.05% max", "0.05 per 100 grains max", "0.5% max", "1% max", "Very well milled; min 38 Kett", "Free from live insects, bad odour and rice fit for human consumption.", "S&P Global Specifications Guide — Pakistan 1121 Parboiled Basmati market assessment benchmark, July 2026. TDAP Basmati GI Book lists PK 1121 Aromatic as a registered Pakistan Basmati variety."],
        ["Rice", "B2 Sortex Broken", "By-product", "B2-S", "Pakistan", "Active Transtrade by-product", "", "By-product / contract specific", "", "", "", "", "", "", "", "", "", "Sortexed as instructed", "Fit for intended sale/use and free from infestation or bad odour where sold as food grade.", "Transtrade operational by-product. Final buyer specification remains sale-specific."],
        ["Rice", "Super Basmati", "White Rice", "SUPER-BAS-W", "Pakistan", "Reference only – inactive", "7.45 mm variety characteristic", "2% max", "13% max", "1–1.5% max", "3% max", "7% max", "0.2% max", "0.1–0.2% max", "0.2% max", "", "2% max", "Double / silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.", "TDAP Basmati GI Book lists Super Basmati as a registered Pakistan Basmati variety; Punjab Agriculture lists 7.45 mm varietal kernel length. Defect limits shown here are common exporter references, not the GI identity limits."],
        ["Rice", "Basmati 385 / PK-385", "White Rice", "PK385-W", "Pakistan", "Reference only – inactive", "6.73 mm variety characteristic", "2% max", "13% max", "1.5% max", "3% max", "7% max", "0.2% max", "0.1% max", "0.2% max", "", "2% max", "Colour sortexed", "Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.", "TDAP Basmati GI Book lists Basmati 385 as a registered Pakistan Basmati variety; Punjab Agriculture lists 6.73 mm varietal kernel length. Common exporter defect profile pre-filled for review."],
        ["Rice", "IRRI-9", "White Rice 5% Broken", "IR9-W5", "Pakistan", "Reference only – inactive", "6.8 mm", "5% max", "13.5–14% max", "1.5% max", "4% max", "7% max", "0.5% max", "0.5% max", "15 pcs/kg max", "", "2% max", "Double / silky polished; colour sortexed", "Free from live insects, bad odour and rice fit for human consumption.", "REAP lists IRRI-9. Common Pakistan exporter IRRI-9 profile pre-filled."],
        ["Rice", "Basmati 515", "White / processed", "BAS515", "Pakistan", "Reference only – inactive", "7.56 mm variety characteristic", "", "", "", "", "", "", "", "", "", "", "To configure by processing", "Free from live insects, bad odour and rice fit for human consumption. Natural Basmati aroma.", "TDAP Basmati GI Book lists Basmati 515 as a registered Pakistan Basmati variety; Punjab Agriculture lists 7.56 mm varietal kernel length. Export defect profile left blank pending an approved Transtrade/buyer standard."],
        ["Rice", "KS-282", "White / processed", "KS282", "Pakistan", "Reference only – inactive", "", "", "", "", "", "", "", "", "", "", "", "To configure by processing", "Free from live insects, bad odour and rice fit for human consumption.", "REAP lists KS-282 as a Pakistan rice type. Grain length and export defect limits intentionally left blank rather than conflating KS-282 with similarly named KSK varieties."]
      ]
    },
    {
      id: "purchase_kat", name: "Purchase KAT Rules", description: "Internal purchase deductions are kept separate from export specifications. Confirmed IRRI-6 rules are pre-filled; other varieties are created as review-required drafts so no unconfirmed KAT applies silently.",
      fields: [
        { label: "Commodity", required: true }, { label: "Variety / product", required: true }, { label: "Quality parameter", required: true },
        { label: "Free / default allowance" }, { label: "Deduction / KAT rule or slab", type: "textarea", full: true },
        { label: "Unit" }, { label: "Effective / seasonal profile" }, { label: "Rule status", type: "select", options: ["Active", "Draft – review required", "Inactive"] },
        { label: "Notes", type: "textarea", full: true }
      ],
      rows: [
        ["Rice", "IRRI-6", "Broken", "20% free", "20–30: 1 paisa/%; 31–35: 3 paisa/%; 36–40: 8 paisa/%; 41–45: 15 paisa/%; 46–50: 20 paisa/%; 51–55: 25 paisa/%; 56–60: 40 paisa/%", "paisa per %", "Default profile", "Active", "Known Transtrade purchase KAT rule."],
        ["Rice", "IRRI-6", "Chalky", "5% free / operational default", "Above free allowance: 10 paisa per excess percentage point.", "paisa per %", "Default profile", "Draft – review required", "5% is the current Arrival default. Earlier discussion included 4%; keep editable until Salman confirms final active free allowance."],
        ["Rice", "IRRI-6", "Damage / Yellow", "2% free", "Above 2% up to 5%: 10 paisa per excess percentage point; above 5%: 25 paisa per excess percentage point.", "paisa per %", "Default profile", "Active", "Known Transtrade purchase KAT rule."],
        ["Rice", "IRRI-6", "Moisture", "14% free", "14.1–14.5: 0.5% weight deduction; 14.6–15.0: 1% weight deduction; above 15.0 up to 16.0: 2% weight deduction.", "weight %", "Standard 14% profile", "Draft – review required", "Known discussed slab. Keep >16 handling manual/reject until explicitly approved."],
        ["Rice", "IRRI-6", "Moisture", "15% free / seasonal alternative", "Seasonal alternate discussed: 15% free, with optional half-kg treatment up to 15.4 depending on season. Exact slab above this point must be selected/confirmed before activation.", "weight / seasonal profile", "Seasonal 15% profile", "Draft – review required", "Do not infer or auto-switch seasonal moisture profile."],
        ["Rice", "IRRI-6", "Paddy", "80 grains operational default", "No final automatic KAT slab confirmed. Above-default handling remains manual until a rule is approved.", "No. of Grains", "Default profile", "Draft – review required", "Paddy is a plain grain count, not a percentage."],
        ["Rice", "C-9", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Variety profile", "Draft – review required", "Placeholder intentionally prevents IRRI-6 KAT from being silently reused."],
        ["Rice", "PK-386", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Variety profile", "Draft – review required", "Internal purchase KAT is not derived from export standard."],
        ["Rice", "Super Kernel Basmati", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Variety profile", "Draft – review required", "Internal purchase KAT is not derived from export standard."],
        ["Rice", "D-98 / PK-198", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Variety profile", "Draft – review required", "Internal purchase KAT is not derived from export standard."],
        ["Rice", "1121 Basmati", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Variety profile", "Draft – review required", "Internal purchase KAT is not derived from export standard."],
        ["Rice", "Basmati 385 / PK-385", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Reference variety", "Draft – review required", "Reference-only until Transtrade activates the variety."],
        ["Rice", "IRRI-9", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Reference variety", "Draft – review required", "Reference-only until Transtrade activates the variety."],
        ["Rice", "Basmati 515", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Reference variety", "Draft – review required", "Reference-only until Transtrade activates the variety."],
        ["Rice", "KS-282", "Broken / Chalky / Damage / Moisture / Paddy", "Not confirmed", "No automatic deduction. Complete variety-specific rule before activation.", "Profile", "Reference variety", "Draft – review required", "Reference-only until Transtrade activates the variety."]
      ]
    },
    { id: "parties", name: "Parties", description: "Buyers, suppliers, brokers and local parties stored once.", fields: [{label:"Party",required:true},{label:"Code / reference"},{label:"Type / notes"}], rows: [["Shams", "BRK-001", "Broker"], ["Sample Overseas Buyer", "BUY-001", "Export buyer"]] },
    { id: "mills", name: "Mills & Locations", description: "Own mill, external mills and stock locations.", fields: [{label:"Mill / location",required:true},{label:"Code / reference"},{label:"Type / notes"}], rows: [["TTI Rice Mill", "TTI-MILL", "Own mill"], ["Karachi Office", "KHI-OFF", "Office"]] },
    { id: "banks", name: "Banks & Accounts", description: "Company and personal bank accounts with ownership, document use and controlled module visibility.", fields: [
      {label:"Account type",required:true,type:"select",options:["Company Account","Personal Account"]},
      {label:"Linked company",type:"select",options:["","TTI — Transtrade International","BRM — Buksh Rice Mills","TG — Trans Grains Foodstuff Trading L.L.C","Other"]},
      {label:"Personal account owner"},{label:"Exact account title",required:true},{label:"Bank name",required:true},{label:"Branch"},
      {label:"Country"},{label:"Currency"},{label:"Account number"},{label:"IBAN"},{label:"SWIFT / BIC"},
      {label:"Purpose / classification"},{label:"Module visibility and document use",type:"textarea",full:true},{label:"Status / notes",type:"textarea",full:true}
    ], rows: [
      ["Company Account","TTI — Transtrade International","","Transtrade International","Meezan Bank Limited","Jodia Bazar Branch, Karachi","Pakistan","PKR","","","","Pakistan operating account","Accounts / Directors; document use to be confirmed","Incomplete — enter account number/IBAN and confirm use"],
      ["Company Account","BRM — Buksh Rice Mills","","Buksh Rice Mills","Meezan Bank Limited","Karachi","Pakistan","PKR","","","","Mill / operating account","Accounts / Directors; document use to be confirmed","Incomplete — enter branch/account number/IBAN"],
      ["Company Account","TG — Trans Grains Foodstuff Trading L.L.C","","Trans Grains Foodstuff Trading L.L.C","Habib Bank AG Zurich","Baniyas Square, Dubai","United Arab Emirates","USD","","","","TG offshore trading account","Authorized TG / Exports / Accounts / Directors only","Incomplete — enter account number/IBAN/SWIFT and confirm use"]
    ] }
  ];

  const DEFAULT_PERMISSIONS = {
    Mill: ["View", "Create", "Edit", "Print", "Approve", "Reports"],
    Exports: ["View", "Create", "Edit", "Print", "Approve", "Reports"],
    Accounts: ["View", "Create", "Edit", "Print", "Approve", "Reports"],
    Directors: ["View", "Approve", "Reports"]
  };

  const defaultState = {
    stateVersion: STATE_VERSION,
    users: [
      { id: "u-salman", name: "Salman", username: "salman", role: "Super Admin", location: "All locations", active: true, modules: ["Mill", "Exports", "Accounts", "Directors"], permissions: Object.fromEntries(["Mill", "Exports", "Accounts", "Directors"].map(m => [m, [...ACTIONS]])), lastActive: "Now" }
    ],
    locks: {
      milling: { locked: false, approvedBy: "—", changed: "Integration review open" },
      exports: { locked: false, approvedBy: "—", changed: "Finalization in progress" },
      accounts: { locked: false, approvedBy: "—", changed: "Module not yet connected" },
      directors: { locked: false, approvedBy: "—", changed: "Module not yet connected" }
    },
    masters: Object.fromEntries(MASTER_TYPES.map(t => [t.id, t.rows.map((row, index) => ({ id: `${t.id}-${index + 1}`, values: row }))])),
    audit: [
      { date: "06-09-2026 17:40", user: "Salman", area: "Module", action: "Reviewed", detail: "Milling module marked integration ready", ref: "MILL-V3.3.2" },
      { date: "06-09-2026 16:54", user: "System", area: "Module", action: "Stabilized", detail: "Exports module workflow build available", ref: "EXP-V2.6" },
      { date: "05-09-2026 18:02", user: "Jazib", area: "Exports", action: "Saved", detail: "Export sales contract draft", ref: "TTI-EXP-042" }
    ],
    permissionChanges: 0
  };

  let state = loadState();
  let currentMaster = "companies";

  function cloneDefault() { return JSON.parse(JSON.stringify(defaultState)); }
  function loadState() {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
      if (!saved || !saved.users || !saved.audit) return cloneDefault();
      const loaded = { ...cloneDefault(), ...saved };
      loaded.masters = ensureMasterSections(loaded.masters);
      if ((loaded.stateVersion || 1) < STATE_VERSION) {
        loaded.users = loaded.users.filter(user => {
          const identity = `${user.name || ""} ${user.username || ""}`.toLowerCase();
          return user.id !== "u-irfan" && !identity.includes("irfan");
        });
        loaded.audit = loaded.audit.filter(item => String(item.user).toLowerCase() !== "irfan");
        loaded.audit.unshift({ date: nowStamp(), user: "System", area: "User", action: "Removed", detail: "Accidental Irfan trial user removed", ref: "ADMIN-CLEANUP" });
        loaded.stateVersion = STATE_VERSION;
        localStorage.setItem(STORAGE_KEY, JSON.stringify(loaded));
      }
      return loaded;
    } catch (_) { return cloneDefault(); }
  }
  function saveState(message = "All changes saved") {
    const indicator = document.getElementById("saveState");
    indicator?.classList.add("saving");
    if (indicator) indicator.lastChild.textContent = " Saving…";
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    window.setTimeout(() => {
      indicator?.classList.remove("saving");
      if (indicator) indicator.lastChild.textContent = ` ${message}`;
    }, 280);
  }
  function initials(name) { return name.split(/\s+/).map(part => part[0]).slice(0, 2).join("").toUpperCase(); }
  function escapeHtml(value) { return String(value ?? "").replace(/[&<>'"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c])); }
  function nowStamp() {
    return new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: false }).format(new Date()).replace(",", "");
  }
  function addAudit(area, action, detail, ref = "ADMIN") {
    state.audit.unshift({ date: nowStamp(), user: SESSION.name, area, action, detail, ref });
  }

  async function apiRequest(body, endpoint = "users") {
    const options = body ? { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ...body, csrf: SESSION.csrf }) } : {};
    const response = await fetch(`api/${endpoint}.php`, options);
    const data = await response.json().catch(() => ({ ok: false, error: "The server returned an unreadable response." }));
    if (!response.ok || !data.ok) throw new Error(data.error || "The user action could not be completed.");
    return data;
  }

  function canOpenModule(name) {
    if (IS_SUPER_ADMIN) return true;
    const permission = SESSION.permissions?.[name];
    return permission === "all" || (Array.isArray(permission) && permission.length > 0);
  }

  function showCredentials(username, password) {
    document.getElementById("credentialUsername").value = username;
    document.getElementById("credentialPassword").value = password;
    document.getElementById("credentialDialog").showModal();
  }

  async function loadServerUsers() {
    if (!IS_SUPER_ADMIN) return;
    const data = await apiRequest();
    state.users = data.users;
    renderUsers();
  }

  async function loadServerMasters() {
    if (!IS_SUPER_ADMIN) return;
    const data = await apiRequest(null, "masters");
    state.masters = ensureMasterSections(data.masters);
    renderMasters();
  }

  function applySessionAccess() {
    document.querySelectorAll(".sidebar-user strong, .profile-chip strong").forEach(node => { node.textContent = SESSION.name; });
    document.querySelectorAll(".sidebar-user span, .profile-chip span").forEach(node => { node.textContent = SESSION.role; });
    document.querySelectorAll(".sidebar-user .avatar, .profile-chip .avatar").forEach(node => { node.textContent = initials(SESSION.name); });
    document.querySelectorAll("[data-module-link]").forEach(node => {
      const module = MODULES.find(item => item.id === node.dataset.moduleLink);
      node.hidden = module ? !canOpenModule(module.name) : false;
    });
    if (!IS_SUPER_ADMIN) {
      document.querySelectorAll('[data-view="users"], [data-view="masters"], [data-view="locks"], [data-view="audit"], [data-action="create-user"], [data-view-target="audit"], #addMasterRecord, #exportAudit, .dashboard-lower, #notificationButton').forEach(node => { node.hidden = true; });
    }
  }
  function toast(message) {
    const region = document.getElementById("toastRegion");
    const node = document.createElement("div");
    node.className = "toast";
    node.textContent = message;
    region.appendChild(node);
    window.setTimeout(() => node.remove(), 3200);
  }

  function moduleCard(module) {
    const palette = module.state === "green" ? ["#18864b", "#e9f8f0"] : module.state === "amber" ? ["#a66100", "#fff5df"] : ["#1769d2", "#eaf2ff"];
    return `<article class="module-card" style="--module-color:${module.color};--module-soft:${module.soft};--state-color:${palette[0]};--state-bg:${palette[1]}">
      <div class="module-card-head"><span class="module-badge">${module.code}</span><span class="state-pill">${escapeHtml(module.status)}</span></div>
      <h3>${escapeHtml(module.name)}</h3><p>${escapeHtml(module.description)}</p>
      <div class="module-meta"><span>${escapeHtml(module.version)}</span><button class="module-open" data-open-module="${module.id}">${module.href ? "Open module →" : "View status →"}</button></div>
    </article>`;
  }
  function renderModules() {
    const modules = MODULES.filter(module => canOpenModule(module.name));
    document.getElementById("dashboardModules").innerHTML = modules.map(moduleCard).join("");
    document.getElementById("allModules").innerHTML = modules.map(moduleCard).join("");
  }
  function openModule(id) {
    const module = MODULES.find(item => item.id === id);
    if (!module) return;
    if (!canOpenModule(module.name)) { toast("You do not have permission to open this module."); return; }
    if (module.href) {
      window.open(module.href, "_blank", "noopener");
      addAudit("Module", "Opened", `${module.name} workspace opened from Super Admin`, module.version);
      saveState();
      renderAudit();
    } else {
      toast(`${module.name} will become available when its module is connected.`);
      showView("modules");
    }
  }

  function renderUsers() {
    const query = document.getElementById("userSearch")?.value.toLowerCase() || "";
    const module = document.getElementById("moduleFilter")?.value || "all";
    const users = state.users.filter(user => {
      const matchesText = `${user.name} ${user.username} ${user.role}`.toLowerCase().includes(query);
      const matchesModule = module === "all" || user.modules.includes(module) || user.role === "Super Admin";
      return matchesText && matchesModule;
    });
    document.getElementById("userRows").innerHTML = users.map(user => `<tr>
      <td><div class="user-cell"><div class="avatar">${initials(user.name)}</div><div><strong>${escapeHtml(user.name)}</strong><small>@${escapeHtml(user.username)}</small></div></div></td>
      <td>${escapeHtml(user.role)}</td>
      <td><div class="tag-row">${user.modules.map(m => `<span class="tag ${user.role === "Super Admin" ? "super" : ""}">${m}</span>`).join("")}</div></td>
      <td>${escapeHtml(user.location)}</td><td><span class="status ${user.active ? "" : "inactive"}">${user.active ? "Active" : "Inactive"}</span></td>
      <td>${escapeHtml(user.lastActive)}</td><td>${user.role === "Super Admin" ? `<div class="row-actions"><a class="row-action" href="change-password.php">Change Password</a><span class="tag super">Protected owner</span></div>` : `<div class="row-actions"><button class="row-action" data-edit-user="${user.id}">Edit</button><button class="row-action" data-reset-user="${user.id}">Reset Password</button><button class="row-action delete" data-delete-user="${user.id}">Delete</button></div>`}</td>
    </tr>`).join("");
    document.getElementById("activeUserCount").textContent = state.users.filter(user => user.active).length;
    document.getElementById("permissionChangeCount").textContent = state.permissionChanges;
  }

  function permissionChecked(permissions,module,icon,action) {
    const saved=permissions?.[module];
    if (saved==="all") return true;
    if (Array.isArray(saved)) return saved.includes(action); // previous user records
    return Array.isArray(saved?.[icon]) && saved[icon].includes(action);
  }
  function permissionMatrix(permissions = {}) {
    return Object.entries(MODULE_ICONS).map(([module,icons]) => `<section class="permission-module">
      <div class="permission-module-head"><strong>${module}</strong><label><input type="checkbox" data-select-module="${module}"> Select all ${module}</label></div>
      <div class="permission-row header"><strong>Icon / Screen</strong>${ICON_ACTIONS.map(action=>`<span>${action}</span>`).join("")}</div>
      ${icons.map(([id,label])=>`<div class="permission-row"><strong>${label}</strong>${ICON_ACTIONS.map(action=>`<label title="${module} · ${label} · ${action}"><input type="checkbox" data-permission-module="${module}" data-permission-icon="${id}" value="${action}" ${permissionChecked(permissions,module,id,action)?"checked":""}></label>`).join("")}</div>`).join("")}
    </section>`).join("");
  }
  function openUserDialog(userId) {
    const dialog = document.getElementById("userDialog");
    const form = document.getElementById("userForm");
    form.reset();
    const user = state.users.find(item => item.id === userId);
    document.getElementById("userDialogTitle").textContent = user ? "Edit User" : "Create User";
    document.getElementById("editUserId").value = user?.id || "";
    document.getElementById("userName").value = user?.name || "";
    document.getElementById("username").value = user?.username || "";
    document.getElementById("userRole").value = user?.role || "";
    document.getElementById("userLocation").value = user?.location === "All locations" ? "All authorized locations" : user?.location || "All authorized locations";
    document.getElementById("userActive").checked = user?.active ?? true;
    document.getElementById("permissionMatrix").innerHTML = permissionMatrix(user?.permissions || {});
    if (user?.role === "Super Admin") {
      document.querySelectorAll("#userForm input, #userForm select").forEach(input => { if (input.id !== "editUserId") input.disabled = true; });
      document.getElementById("saveUserButton").disabled = true;
      document.getElementById("deleteUserButton").hidden = true;
      document.getElementById("resetPasswordButton").hidden = true;
      toast("The only Super Admin account cannot be reduced from this screen.");
    } else {
      document.querySelectorAll("#userForm input, #userForm select").forEach(input => input.disabled = false);
      document.getElementById("saveUserButton").disabled = false;
      document.getElementById("deleteUserButton").hidden = !user;
      document.getElementById("resetPasswordButton").hidden = !user;
    }
    dialog.showModal();
  }
  async function deleteUser(selectedId) {
    const id = String(selectedId || document.getElementById("editUserId").value);
    const user = state.users.find(item => item.id === id);
    if (!user || user.role === "Super Admin") return;
    if (!window.confirm(`Delete ${user.name}'s login? They will no longer be able to sign in.`)) return;
    try {
      const data = await apiRequest({ action: "delete", id });
      state.users = data.users; state.permissionChanges += 1;
      renderUsers(); document.getElementById("userDialog").close(); toast("User login deleted.");
    } catch (error) { toast(error.message); }
  }
  async function resetPassword(selectedId) {
    const id = String(selectedId || document.getElementById("editUserId").value);
    const user = state.users.find(item => item.id === id);
    if (!user || user.role === "Super Admin") return;
    if (!window.confirm(`Reset ${user.name}'s password and issue a new temporary password?`)) return;
    try {
      const data = await apiRequest({ action: "reset-password", id });
      document.getElementById("userDialog").close(); showCredentials(data.username, data.temporaryPassword);
    } catch (error) { toast(error.message); }
  }
  async function saveUser(event) {
    event.preventDefault();
    const form = document.getElementById("userForm");
    if (!form.reportValidity()) return;
    const id = document.getElementById("editUserId").value;
    const permissions = {};
    document.querySelectorAll("#permissionMatrix input[data-permission-icon]:checked").forEach(input => {
      const module = input.dataset.permissionModule;
      const icon = input.dataset.permissionIcon;
      ((permissions[module] ||= {})[icon] ||= []).push(input.value);
    });
    const modules = Object.keys(permissions).filter(module => Object.values(permissions[module]).some(actions=>actions.includes("View")));
    if (!modules.length) { toast("Select at least one module permission."); return; }
    const user = {
      id,
      name: document.getElementById("userName").value.trim(),
      username: document.getElementById("username").value.trim(),
      role: document.getElementById("userRole").value,
      location: document.getElementById("userLocation").value,
      active: document.getElementById("userActive").checked,
      modules, permissions,
      lastActive: id ? (state.users.find(item => item.id === id)?.lastActive || "Not activated") : "Not activated"
    };
    try {
      const data = await apiRequest({ action: id ? "update" : "create", ...user });
      state.users = data.users; state.permissionChanges += 1; renderUsers();
      document.getElementById("userDialog").close();
      if (data.temporaryPassword) showCredentials(data.username, data.temporaryPassword);
      else toast("User and permissions updated.");
    } catch (error) { toast(error.message); }
  }

  function ensureMasterSections(masters = {}) {
    const result = { ...(masters || {}) };
    MASTER_TYPES.forEach(type => {
      if (!Array.isArray(result[type.id])) {
        result[type.id] = type.rows.map((row, index) => ({ id: `${type.id}-${index + 1}`, values: [...row] }));
      }
    });

    const companyType = MASTER_TYPES.find(type => type.id === "companies");
    const companyDefaults = new Map((companyType?.rows || []).map(row => [String(row[1]).toUpperCase(), row]));
    const seenCompanies = new Set();
    result.companies = (result.companies || []).map(row => {
      const values = [...(row.values || [])];
      const code = String(values[1] || "").toUpperCase();
      if (code) seenCompanies.add(code);
      if (companyDefaults.has(code) && (values.length <= 3 || (code === "BRM" && values[0] === "BRM"))) return { ...row, values: [...companyDefaults.get(code)] };
      return row;
    });
    companyDefaults.forEach((values, code) => {
      if (!seenCompanies.has(code)) result.companies.push({ id: `companies-${code.toLowerCase()}-default`, values: [...values] });
    });

    const productType = MASTER_TYPES.find(type => type.id === "products");
    const productDefaults = new Map((productType?.rows || []).map(row => [String(row[3]).toUpperCase(), row]));
    const legacyProductCodes = new Map([["IR6-W","IR6-W5"],["IR6-P","IR6-P5"],["C9-W","C9-W5"]]);
    const seenProducts = new Set();
    result.products = (result.products || []).map(row => {
      const values = [...(row.values || [])];
      const legacyCode = String(values[1] || "").toUpperCase();
      const rawCode = values.length <= 3 ? legacyCode : String(values[3] || "").toUpperCase();
      const code = legacyProductCodes.get(rawCode) || rawCode;
      if (code) seenProducts.add(code);
      if (productDefaults.has(code) && values.length < 20) return { ...row, values: [...productDefaults.get(code)] };
      return row;
    });
    productDefaults.forEach((values, code) => {
      if (!seenProducts.has(code)) result.products.push({ id: `products-${code.toLowerCase().replace(/[^a-z0-9]+/g, "-")}-default`, values: [...values] });
    });
    return result;
  }
  function masterType() { return MASTER_TYPES.find(item => item.id === currentMaster); }
  function masterInputId(index) { return `masterField${index}`; }
  function masterFieldsHtml(type, values = []) {
    return type.fields.map((field, index) => {
      const value = String(values[index] ?? "");
      const full = field.full ? " full-span" : "";
      if (field.type === "checks") {
        const selected = new Set(value.split(";").map(v => v.trim()).filter(Boolean));
        return `<div class="check-field${full}"><span>${escapeHtml(field.label)}</span><div class="master-checks">${field.options.map(option => `<label><input type="checkbox" data-master-field-index="${index}" value="${escapeHtml(option)}" ${selected.has(option) ? "checked" : ""}>${escapeHtml(option)}</label>`).join("")}</div></div>`;
      }
      if (field.type === "select") {
        return `<label class="${full.trim()}">${escapeHtml(field.label)}<select id="${masterInputId(index)}" data-master-field-index="${index}" ${field.required ? "required" : ""}>${field.options.map(option => `<option value="${escapeHtml(option)}" ${option === value ? "selected" : ""}>${escapeHtml(option || "Select")}</option>`).join("")}</select></label>`;
      }
      if (field.type === "textarea") {
        return `<label class="${full.trim()}">${escapeHtml(field.label)}<textarea id="${masterInputId(index)}" data-master-field-index="${index}" rows="3" ${field.required ? "required" : ""}>${escapeHtml(value)}</textarea></label>`;
      }
      return `<label class="${full.trim()}">${escapeHtml(field.label)}<input id="${masterInputId(index)}" data-master-field-index="${index}" value="${escapeHtml(value)}" ${field.required ? "required" : ""} autocomplete="off"></label>`;
    }).join("");
  }
  function masterValuesFromForm(type) {
    return type.fields.map((field, index) => {
      if (field.type === "checks") {
        return [...document.querySelectorAll(`[data-master-field-index="${index}"]:checked`)].map(input => input.value).join("; ");
      }
      return document.getElementById(masterInputId(index))?.value.trim() || "";
    });
  }
  function displayColumns(type) {
    if (type.id === "companies") return [0, 1, 2, 3, 4, 5];
    if (type.id === "commodities") return [0, 1, 2, 3, 4, 5];
    if (type.id === "products") return [0, 1, 2, 3, 5, 6, 7, 8, 9, 10];
    if (type.id === "purchase_kat") return [0, 1, 2, 3, 4, 5, 6, 7];
    return type.fields.map((_, index) => index);
  }
  function masterRowStatus(type, row) {
    if (type.id === "products") {
      const profile = String(row.values?.[5] || "");
      if (/inactive|reference/i.test(profile)) return profile;
      return profile || "Active";
    }
    if (type.id === "purchase_kat") return String(row.values?.[7] || "Draft – review required");
    return "Active";
  }

  function renderMasters() {
    state.masters = ensureMasterSections(state.masters);
    document.getElementById("masterMenu").innerHTML = MASTER_TYPES.map(type => `<button class="${type.id === currentMaster ? "active" : ""}" data-master="${type.id}">${type.name}<span>${state.masters[type.id]?.length || 0}</span></button>`).join("");
    const type = masterType();
    document.getElementById("masterTitle").textContent = type.name;
    document.getElementById("masterDescription").textContent = type.description;
    document.getElementById("addMasterRecord").textContent = `+ Add ${type.id === "purchase_kat" ? "KAT Rule" : type.id === "companies" ? "Company" : type.id === "commodities" ? "Commodity" : type.id === "products" ? "Product" : "Record"}`;
    const columns = displayColumns(type);
    document.getElementById("masterTableHead").innerHTML = `<tr>${columns.map(index => `<th>${escapeHtml(type.fields[index].label)}</th>`).join("")}<th>Status</th><th>Super Admin actions</th></tr>`;
    const query = document.getElementById("masterSearch")?.value.toLowerCase() || "";
    const rows = (state.masters[currentMaster] || []).filter(row => row.values.join(" ").toLowerCase().includes(query));
    document.getElementById("masterTableBody").innerHTML = rows.length ? rows.map(row => `<tr>${columns.map(index => `<td>${escapeHtml(row.values[index] || "—")}</td>`).join("")}<td><span class="tag">${escapeHtml(masterRowStatus(type, row))}</span></td><td><div class="row-actions"><button class="row-action" data-edit-master="${escapeHtml(row.id)}">Edit</button><button class="row-action delete" data-delete-master="${escapeHtml(row.id)}">Delete</button></div></td></tr>`).join("") : `<tr><td colspan="${columns.length + 2}">No matching records.</td></tr>`;
  }
  function openMasterDialog(id = "") {
    const type = masterType();
    const row = (state.masters[currentMaster] || []).find(item => item.id === id);
    document.getElementById("masterForm").reset();
    document.getElementById("editMasterId").value = row?.id || "";
    document.getElementById("masterDialogTitle").textContent = `${row ? "Edit" : "Add"} ${type.name}`;
    document.getElementById("masterDialogHelp").textContent = type.description + " Complete as much information as available; only the essential identity fields are mandatory.";
    document.getElementById("masterFormFields").innerHTML = masterFieldsHtml(type, row?.values || []);
    document.getElementById("deleteMasterButton").hidden = !row;
    document.getElementById("saveMasterButton").textContent = row ? "Save Changes" : "Save Record";
    document.getElementById("masterDialog").showModal();
  }
  async function saveMasterRecord(event) {
    event.preventDefault();
    const form = document.getElementById("masterForm");
    if (!form.reportValidity()) return;
    const type = masterType();
    const id = document.getElementById("editMasterId").value;
    const values = masterValuesFromForm(type);
    const primary = values[0] || type.name;
    const ref = values[1] || currentMaster.toUpperCase();
    try {
      const data = await apiRequest({ action: id ? "update" : "create", type: currentMaster, id, values }, "masters");
      state.masters = ensureMasterSections(data.masters); addAudit("Master", id ? "Updated" : "Created", `${type.name}: ${primary}`, ref);
      saveState(); renderMasters(); renderAudit(); renderRecentActivity(); document.getElementById("masterDialog").close(); form.reset(); toast(id ? "Master record updated." : "Master record saved.");
    } catch (error) { toast(error.message); }
  }
  async function deleteMasterRecord(selectedId) {
    const id = String(selectedId || document.getElementById("editMasterId").value);
    const row = (state.masters[currentMaster] || []).find(item => item.id === id); if (!row) return;
    if (!window.confirm(`Delete ${row.values[0]} from ${masterType().name}?`)) return;
    try { const data = await apiRequest({ action: "delete", type: currentMaster, id }, "masters"); state.masters = ensureMasterSections(data.masters); saveState(); renderMasters(); document.getElementById("masterDialog").close(); toast("Master record deleted."); }
    catch (error) { toast(error.message); }
  }

  function renderLocks() {
    document.getElementById("lockGrid").innerHTML = MODULES.map(module => {
      const lock = state.locks[module.id];
      return `<article class="lock-card"><div class="lock-card-head"><h3>${module.name}</h3><span class="lock-status ${lock.locked ? "locked" : ""}">${lock.locked ? "Locked" : "Open for development"}</span></div>
        <p>${lock.locked ? "Approved workflow is protected. Reopening requires a documented impact request and Salman’s approval." : "The module may continue development until Salman gives final approval and locks it."}</p>
        <div class="lock-card-actions"><small>${escapeHtml(lock.changed)}</small><button class="button ${lock.locked ? "secondary" : "primary"}" data-toggle-lock="${module.id}">${lock.locked ? "Request Reopen" : "Lock After Approval"}</button></div></article>`;
    }).join("");
  }
  function toggleLock(id) {
    const lock = state.locks[id];
    const module = MODULES.find(item => item.id === id);
    if (lock.locked) {
      const confirmed=window.confirm(`Reopen the ${module.name} module for development? You will be able to change it again until you lock it.`);
      if(!confirmed) return;
      lock.locked=false; lock.approvedBy="—"; lock.changed=`Reopened by Salman · ${nowStamp()}`;
      addAudit("Module", "Reopened", `${module.name} reopened by Super Admin`, module.version);
      toast(`${module.name} is open for development.`);
    } else {
      const confirmed = window.confirm(`Lock the ${module.name} module? After locking, no cross-module change can alter it without your documented approval.`);
      if (!confirmed) return;
      lock.locked = true; lock.approvedBy = "Salman"; lock.changed = `Locked by Salman · ${nowStamp()}`;
      addAudit("Module", "Locked", `${module.name} approved and protected`, module.version);
      toast(`${module.name} is now locked.`);
    }
    saveState(); renderLocks(); renderAudit(); renderRecentActivity();
  }

  function renderAudit() {
    const query = document.getElementById("auditSearch")?.value.toLowerCase() || "";
    const filter = document.getElementById("auditFilter")?.value || "all";
    const rows = state.audit.filter(item => {
      const match = Object.values(item).join(" ").toLowerCase().includes(query);
      return match && (filter === "all" || item.area === filter);
    });
    document.getElementById("auditRows").innerHTML = rows.map(item => `<tr><td>${escapeHtml(item.date)}</td><td>${escapeHtml(item.user)}</td><td><span class="tag">${escapeHtml(item.area)}</span></td><td>${escapeHtml(item.action)}</td><td>${escapeHtml(item.detail)}</td><td>${escapeHtml(item.ref)}</td></tr>`).join("");
  }
  function renderRecentActivity() {
    document.getElementById("recentActivity").innerHTML = state.audit.slice(0, 4).map(item => `<div class="timeline-item"><span class="timeline-dot"></span><div><strong>${escapeHtml(item.detail)}</strong><small>${escapeHtml(item.user)} · ${escapeHtml(item.date)} · ${escapeHtml(item.ref)}</small></div></div>`).join("");
  }
  function exportAudit() {
    const header = ["Date & Time", "User", "Area", "Action", "Details", "Reference"];
    const data = [header, ...state.audit.map(item => [item.date, item.user, item.area, item.action, item.detail, item.ref])];
    const csv = data.map(row => row.map(cell => `"${String(cell).replaceAll('"', '""')}"`).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    const link = document.createElement("a"); link.href = url; link.download = `Transtrade_Audit_${new Date().toISOString().slice(0,10)}.csv`; link.click(); URL.revokeObjectURL(url);
    addAudit("Audit", "Exported", "Super Admin audit trail exported", "AUDIT-CSV"); saveState(); renderAudit(); toast("Audit file downloaded.");
  }

  function showView(id) {
    if (!IS_SUPER_ADMIN && !["dashboard", "modules"].includes(id)) { toast("Super Admin access required."); return; }
    document.querySelectorAll(".view").forEach(view => view.classList.toggle("active", view.id === `view-${id}`));
    document.querySelectorAll(".nav-item[data-view]").forEach(item => item.classList.toggle("active", item.dataset.view === id));
    document.getElementById("sidebar").classList.remove("open");
    document.getElementById("overlay").classList.remove("open");
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  function openNotifications(open = true) {
    const drawer = document.getElementById("notificationDrawer");
    drawer.classList.toggle("open", open); drawer.setAttribute("aria-hidden", String(!open));
    document.getElementById("overlay").classList.toggle("open", open);
  }
  function globalSearch(value) {
    const query = value.trim().toLowerCase();
    if (!query) return;
    const user = IS_SUPER_ADMIN ? state.users.find(item => `${item.name} ${item.username} ${item.role}`.toLowerCase().includes(query)) : null;
    const module = MODULES.find(item => `${item.name} ${item.description}`.toLowerCase().includes(query));
    const master = IS_SUPER_ADMIN ? MASTER_TYPES.find(item => `${item.name} ${item.description}`.toLowerCase().includes(query)) : null;
    if (user) { showView("users"); document.getElementById("userSearch").value = value; renderUsers(); }
    else if (module) { showView("modules"); toast(`${module.name} module found.`); }
    else if (master) { currentMaster = master.id; showView("masters"); renderMasters(); }
    else toast("No matching module, user or master record.");
  }

  document.addEventListener("click", event => {
    const viewButton = event.target.closest("[data-view]");
    const viewTarget = event.target.closest("[data-view-target]");
    const moduleButton = event.target.closest("[data-open-module], [data-module-link]");
    const editUser = event.target.closest("[data-edit-user]");
    const resetUser = event.target.closest("[data-reset-user]");
    const deleteUserButton = event.target.closest("[data-delete-user]");
    const masterButton = event.target.closest("[data-master]");
    const editMaster = event.target.closest("[data-edit-master]");
    const deleteMaster = event.target.closest("[data-delete-master]");
    const lockButton = event.target.closest("[data-toggle-lock]");
    const closeDialog = event.target.closest("[data-close-dialog]");
    if (viewButton) showView(viewButton.dataset.view);
    if (viewTarget) showView(viewTarget.dataset.viewTarget);
    if (moduleButton) openModule(moduleButton.dataset.openModule || moduleButton.dataset.moduleLink);
    if (event.target.closest('[data-action="create-user"]')) openUserDialog();
    if (editUser) openUserDialog(editUser.dataset.editUser);
    if (resetUser) resetPassword(resetUser.dataset.resetUser);
    if (deleteUserButton) deleteUser(deleteUserButton.dataset.deleteUser);
    if (masterButton) { currentMaster = masterButton.dataset.master; renderMasters(); }
    if (editMaster) openMasterDialog(editMaster.dataset.editMaster);
    if (deleteMaster) deleteMasterRecord(deleteMaster.dataset.deleteMaster);
    if (lockButton) toggleLock(lockButton.dataset.toggleLock);
    if (closeDialog) document.getElementById(closeDialog.dataset.closeDialog)?.close();
    if (event.target.closest('[data-action="close-notifications"]')) openNotifications(false);
    const selectModule=event.target.closest("[data-select-module]");
    if (selectModule) document.querySelectorAll(`#permissionMatrix input[data-permission-module="${selectModule.dataset.selectModule}"][data-permission-icon]`).forEach(input=>{input.checked=selectModule.checked;});
  });
  document.getElementById("permissionMatrix").addEventListener("change", event => {
    const input=event.target.closest("input[data-permission-icon]"); if(!input)return;
    const row=input.closest(".permission-row"),view=row?.querySelector('input[value="View"]');
    if(input.value!=="View"&&input.checked&&view)view.checked=true;
    if(input.value==="View"&&!input.checked)row?.querySelectorAll('input[value="Create"],input[value="Edit"]').forEach(x=>x.checked=false);
  });
  document.getElementById("userForm").addEventListener("submit", saveUser);
  document.getElementById("deleteUserButton").addEventListener("click", () => deleteUser());
  document.getElementById("resetPasswordButton").addEventListener("click", () => resetPassword());
  document.getElementById("deleteMasterButton").addEventListener("click", () => deleteMasterRecord());
  document.getElementById("copyCredentials").addEventListener("click", async () => {
    const text = `Transtrade login\nUsername: ${document.getElementById("credentialUsername").value}\nTemporary password: ${document.getElementById("credentialPassword").value}\nWebsite: https://app.transtradeinternational.com`;
    try { await navigator.clipboard.writeText(text); toast("Login details copied."); }
    catch (_) { document.getElementById("credentialPassword").select(); toast("Select and copy the login details."); }
  });
  document.getElementById("masterForm").addEventListener("submit", saveMasterRecord);
  document.getElementById("addMasterRecord").addEventListener("click", () => openMasterDialog());
  document.getElementById("userSearch").addEventListener("input", renderUsers);
  document.getElementById("moduleFilter").addEventListener("change", renderUsers);
  document.getElementById("masterSearch").addEventListener("input", renderMasters);
  document.getElementById("auditSearch").addEventListener("input", renderAudit);
  document.getElementById("auditFilter").addEventListener("change", renderAudit);
  document.getElementById("exportAudit").addEventListener("click", exportAudit);
  document.getElementById("notificationButton").addEventListener("click", () => openNotifications(true));
  document.getElementById("overlay").addEventListener("click", () => { openNotifications(false); document.getElementById("sidebar").classList.remove("open"); });
  document.getElementById("menuButton").addEventListener("click", () => { document.getElementById("sidebar").classList.add("open"); document.getElementById("overlay").classList.add("open"); });
  document.getElementById("globalSearch").addEventListener("keydown", event => { if (event.key === "Enter") globalSearch(event.target.value); });
  document.addEventListener("keydown", event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") { event.preventDefault(); document.getElementById("globalSearch").focus(); }
    if (event.key === "Escape") openNotifications(false);
  });

  async function initialize() {
    applySessionAccess(); renderModules(); renderUsers(); renderMasters(); renderLocks(); renderAudit(); renderRecentActivity();
    if (IS_SUPER_ADMIN) {
      try { await Promise.all([loadServerUsers(),loadServerMasters()]); } catch (error) { toast(error.message); }
    }
    saveState("All changes saved");
  }
  initialize();
})();
