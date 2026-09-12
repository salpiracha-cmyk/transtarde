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
      ["entity-tti","Legal Book · TTI"],["entity-brm","Legal Book · BRM"],["entity-tg","Legal Book · TG"],
      ["dashboard","Needs Attention / Dashboard"],["purchases","Purchases / Sodas"],["due","Due Date Report"],
      ["supplier","Supplier Ledgers & Payments"],["customer","Customer Ledgers & Receipts"],["expenses","Expenses & Overheads"],
      ["transport","Transport"],["freight","Freight"],["services","Export Service Bills"],["cashbank","Cash & Bank"],
      ["jv","Journal Voucher"],["reconciliation","Reconciliation"],["tg","TG / Intercompany"],["reports","Reports"],["masters","Accounts Masters"]
    ]
  };
  const SESSION = window.TT_SESSION || { name: "Salman", username: "salman", role: "Super Admin", permissions: { Mill: "all", Exports: "all", Accounts: "all", Directors: "all" }, csrf: "" };
  const IS_SUPER_ADMIN = SESSION.role === "Super Admin";
  const MODULES = [
    { id: "milling", name: "Mill", code: "M", color: "#16815a", soft: "#e7f7f0", status: "Live trial", state: "green", version: "V3.3.2 Audited", description: "Arrivals, stocks, production, bags, loading and mill operations.", href: "module.php?id=milling" },
    { id: "exports", name: "Exports", code: "E", color: "#1769d2", soft: "#eaf2ff", status: "Live trial", state: "green", version: "V3 Clean Operational", description: "Contracts, export orders, shipment planning and documentation.", href: "module.php?id=exports" },
    { id: "accounts", name: "Accounts", code: "A", color: "#8a55c7", soft: "#f3ecfb", status: "Live trial", state: "green", version: "Accounts V1 Live", description: "Purchases, ledgers, banking, receivables, payables and reporting.", href: "accounts/index.php" },
    { id: "directors", name: "Directors", code: "D", color: "#d17b0f", soft: "#fff3e2", status: "Awaiting module", state: "amber", version: "Not connected", description: "Consolidated oversight, Cashflow, alerts, approvals and reports." }
  ];

  const MASTER_TYPES = [
    {
      id: "salary_staff", name: "Salary & Staff", description: "The owner's recurring Salary Master shared with Accounts. Keep only net salary or remuneration, Zakat and other recurring allowances here. Mill salaries belong to TTI only.",
      fields: [
        { label: "Staff / person name", required: true },
        { label: "Legal book", required: true, type: "select", options: ["TTI", "BRM"] },
        { label: "Salary group", required: true, type: "select", options: ["MILL_STAFF", "OFFICE_STAFF", "HOME_STAFF", "DIRECTOR_REMUNERATION", "HOME_MONTHLY_GIVE"] },
        { label: "Net salary / remuneration (Rs)", type: "number" },
        { label: "Zakat (Rs)", type: "number" },
        { label: "Other recurring allowances (Rs)", type: "number" },
        { label: "Effective from", required: true, type: "date" },
        { label: "Effective to", type: "date" },
        { label: "Accounts treatment", required: true, type: "select", options: ["STAFF_COST", "FAMILY_ALLOCATION", "TO_CONFIRM"] },
        { label: "Include in Mill production cost", type: "select", options: ["No", "Yes"] },
        { label: "Status", type: "select", options: ["Active", "Inactive"] },
        { label: "Notes", type: "textarea", full: true }
      ],
      rows: []
    },
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
      id: "commodities", name: "Commodity Master", description: "Expandable commodity setup used by Soda, quality and Accounts mappings. KAT rules are defined separately for each approved commodity and variety.",
      fields: [
        { label: "Commodity", required: true }, { label: "Code", required: true }, { label: "Base unit" },
        { label: "Soda / contract enabled", type: "select", options: ["Yes", "No"] }, { label: "Quality / specification profile" },
        { label: "KAT / deduction profile" }, { label: "Default accounting mapping" }, { label: "Staff instruction / message", type: "textarea", full: true }
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
        { label: "Additional quality wording", type: "textarea", full: true }, { label: "Source / basis", type: "textarea", full: true },
        { label: "Custom specifications", type: "hidden", full: true }
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
      id: "purchase_kat", name: "Purchase KAT Rules", description: "Owner-controlled purchase deductions shared with Accounts. Only IRRI-6 rules are currently defined; other rice varieties and corn remain blank until Salman enters their separate KAT systems.",
      fields: [
        { label: "Commodity", required: true }, { label: "Variety / product", required: true }, { label: "Quality parameter", required: true },
        { label: "Free / default allowance" }, { label: "KAT calculation / slab", type: "textarea", full: true },
        { label: "Unit" }, { label: "Effective / seasonal profile" }, { label: "Rule status", type: "select", options: ["Active", "Draft – review required", "Inactive"] },
        { label: "Notes", type: "textarea", full: true }, { label: "Structured KAT ranges", type: "hidden", full: true }
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
      ].filter(row => row[1] === "IRRI-6")
    },
    { id: "export_documents", name: "Export Documents Presented", description: "Authoritative Sales Contract document rows shared by Super Admin and Exports.", fields: [{label:"Document Name",required:true},{label:"Original",required:true},{label:"Copies",required:true},{label:"Applies To",type:"select",options:["ALL","FOB","CFR","CIF","LC_SIGHT","LC_USANCE"]},{label:"Status",type:"select",options:["Active","Inactive"]}], rows: [["Commercial Invoice","3","0","ALL","Active"],["Commercial Packing List","3","0","ALL","Active"],["Full set clean on-board Bill of Lading","3","3","ALL","Active"],["Certificate of Origin","1","3","ALL","Active"],["e-Phyto issued by Department of Plant Protection, Government of Pakistan","1","0","ALL","Active"],["Fumigation Certificate","1","1","ALL","Active"],["Insurance Policy / Certificate","1","0","CIF","Active"]] },
    { id: "export_terms", name: "Export Other Terms", description: "Authoritative reusable Sales Contract terms. Locked L/C clauses remain protected in the Export workflow.", fields: [{label:"Payment Group",required:true,type:"select",options:["BASE","ADVANCE","CAD","LC_SIGHT","LC_USANCE","CUSTOM"]},{label:"Term Text",required:true,type:"textarea",full:true},{label:"Status",type:"select",options:["Active","Inactive"]}], rows: [["BASE","All present and/or future customs taxes and/or duties/levies on the cargo in the country of origin shall be for Seller’s account. All present and/or future customs taxes and/or duties/levies on the cargo in the country of destination shall be for Buyer’s account.","Active"],["BASE","Risk of weight and quality is transferred to Buyer once cargo is loaded on board the vessel from Pakistan.","Active"],["BASE","Ownership of cargo is transferred to Buyer upon receipt of full payment of the invoice.","Active"],["BASE","All other terms and conditions as per applicable GAFTA London rules, of which both parties admit full notice and knowledge. English law to apply.","Active"],["BASE","Should any dispute arise which cannot be amicably settled between Buyer and Seller, the dispute shall be settled by arbitration in London as per applicable GAFTA rules.","Active"],["ADVANCE","Partial shipment allowed.","Active"],["CAD","Partial shipment allowed.","Active"]] },
    { id: "parties", name: "Parties", description: "Buyers, suppliers, brokers and other parties stored once and reused across authorized modules.", fields: [{label:"Party name",required:true},{label:"Code / reference"},{label:"Party role"},{label:"Notes",type:"textarea",full:true}], rows: [["Shams", "BRK-001", "Broker", ""], ["Sample Overseas Buyer", "BUY-001", "Export Buyer", ""]] },
    { id: "mills", name: "Mills & Locations", description: "Own mill, external mills, offices and stock locations used by authorized modules.", fields: [{label:"Mill / location",required:true},{label:"Code / reference"},{label:"Location type"},{label:"Notes",type:"textarea",full:true}], rows: [["TTI Rice Mill", "TTI-MILL", "Own Mill", ""], ["Karachi Office", "KHI-OFF", "Office", ""]] },
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
      accounts: { locked: false, approvedBy: "—", changed: "Connected to Accounts V1 live workspace" },
      directors: { locked: false, approvedBy: "—", changed: "Module not yet connected" }
    },
    masters: Object.fromEntries(MASTER_TYPES.map(t => [t.id, t.rows.map((row, index) => ({ id: `${t.id}-${index + 1}`, values: row }))])),
    audit: [
      { date: "06-09-2026 17:40", user: "Salman", area: "Module", action: "Reviewed", detail: "Milling module marked integration ready", ref: "MILL-V3.3.2" },
      { date: "06-09-2026 16:54", user: "System", area: "Module", action: "Stabilized", detail: "Exports module workflow build available", ref: "EXP-V2.6" },
      { date: "05-09-2026 18:02", user: "Jazib", area: "Exports", action: "Saved", detail: "Export sales contract draft", ref: "TTI-EXP-042" }
    ],
    permissionChanges: 0,
    masterOptions: { party_roles: ["Buyer","Supplier","Broker","Export Buyer","Local Buyer","Customer","Agent","Service Provider","Other"] }
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
    if (data.options) state.masterOptions = data.options;
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
      document.querySelectorAll('[data-view="users"], [data-view="masters"], [data-view="locks"], [data-view="audit"], [data-view="backup"], [data-action="create-user"], [data-view-target="audit"], #addMasterRecord, #exportAudit, .dashboard-lower, #notificationButton').forEach(node => { node.hidden = true; });
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
      <div class="permission-row header"><strong>Icon / Screen</strong>${(module==="Accounts"?["View","Create","Edit","Approve","Reports"]:ICON_ACTIONS).map(action=>`<span>${action}</span>`).join("")}</div>
      ${icons.map(([id,label])=>`<div class="permission-row"><strong>${label}</strong>${(module==="Accounts"?["View","Create","Edit","Approve","Reports"]:ICON_ACTIONS).map(action=>`<label title="${module} · ${label} · ${action}"><input type="checkbox" data-permission-module="${module}" data-permission-icon="${id}" value="${action}" ${permissionChecked(permissions,module,id,action)?"checked":""}></label>`).join("")}</div>`).join("")}
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
    result.products = (result.products || []).map(row => {
      const values = [...(row.values || [])];
      while (values.length < 21) values.push("");
      return { ...row, values };
    });
    return result;
  }
  function masterType() { return MASTER_TYPES.find(item => item.id === currentMaster); }
  function masterInputId(index) { return `masterField${index}`; }

  function companyMasterFieldsHtml(values = []) {
    const roles = ["Group Company", "Pakistan Operating Entity", "Offshore Export Contracting", "Exporter", "Mill / Processor", "Seller", "Buyer", "Intercompany", "Accounting Entity"];
    const selected = new Set(String(values[4] || "").split(";").map(v => v.trim()).filter(Boolean));
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Company identity</h3><p>The list shows only identity. Legal and workflow detail stays inside Edit.</p></div></div><div class="master-identity-grid">
      <label>Legal company name<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label>
      <label>Short code<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}" required></label>
      <label>Country<input id="${masterInputId(2)}" data-master-field-index="2" value="${escapeHtml(values[2] || "")}"></label>
      <label>Entity scope<select id="${masterInputId(3)}" data-master-field-index="3">${["","Pakistan","Offshore","Other"].map(x => `<option value="${escapeHtml(x)}" ${x===String(values[3]||"")?"selected":""}>${escapeHtml(x||"Select")}</option>`).join("")}</select></label>
    </div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Company roles</h3><p>These roles tell Transtrade where the entity may be used.</p></div></div><div class="master-checks">${roles.map(role => `<label><input type="checkbox" data-master-field-index="4" value="${escapeHtml(role)}" ${selected.has(role)?"checked":""}>${escapeHtml(role)}</label>`).join("")}</div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Special workflow & behaviour</h3><p>Keep exceptional entity handling separate from ordinary identity.</p></div></div><div class="master-form-grid"><label>TG special handling<select id="${masterInputId(5)}" data-master-field-index="5">${["No","Yes"].map(x=>`<option ${x===String(values[5]||"No")?"selected":""}>${x}</option>`).join("")}</select></label><label class="full-span">System behaviour / notes<textarea id="${masterInputId(6)}" data-master-field-index="6" rows="4">${escapeHtml(values[6] || "")}</textarea></label></div></section>`;
  }
  function commodityMasterFieldsHtml(values = []) {
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Commodity identity</h3><p>Basic identity stays separate from rules and accounting setup.</p></div></div><div class="master-identity-grid">
      <label>Commodity<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label>
      <label>Code<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}" required></label>
      <label>Base unit<input id="${masterInputId(2)}" data-master-field-index="2" value="${escapeHtml(values[2] || "")}"></label>
      <label>Soda / contract enabled<select id="${masterInputId(3)}" data-master-field-index="3">${["Yes","No"].map(x=>`<option ${x===String(values[3]||"Yes")?"selected":""}>${x}</option>`).join("")}</select></label>
    </div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Operational rules</h3><p>References to quality and KAT profiles belong here, not across the main list.</p></div></div><div class="master-form-grid"><label>Quality / specification profile<input id="${masterInputId(4)}" data-master-field-index="4" value="${escapeHtml(values[4] || "")}"></label><label>KAT / deduction profile<input id="${masterInputId(5)}" data-master-field-index="5" value="${escapeHtml(values[5] || "")}"></label></div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Accounts linkage</h3><p>Accounting mapping is kept distinct so it can later follow the approved master ownership rule.</p></div></div><div class="master-form-grid"><label class="full-span">Default accounting mapping<input id="${masterInputId(6)}" data-master-field-index="6" value="${escapeHtml(values[6] || "")}"></label><label class="full-span">Notes<textarea id="${masterInputId(7)}" data-master-field-index="7" rows="4">${escapeHtml(values[7] || "")}</textarea></label></div></section>`;
  }
  function partyRoleOptions(current = "") {
    const defaults=["Buyer","Supplier","Broker","Export Buyer","Local Buyer","Customer","Agent","Service Provider","Other"];
    const roles=[...new Set([...(state.masterOptions?.party_roles||defaults),...(current?[current]:[])])].filter(Boolean).sort((a,b)=>a.localeCompare(b));
    return roles.map(role=>`<option value="${escapeHtml(role)}" ${role===current?"selected":""}>${escapeHtml(role)}</option>`).join("");
  }
  function partyMasterFieldsHtml(values = []) {
    const current=String(values[2] || "");
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Party identity</h3><p>Keep the party simple; choose one role from the saved list.</p></div></div><div class="master-identity-grid">
      <label>Party name<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label>
      <label>Code / reference<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}"></label>
      <label>Party Role<select id="${masterInputId(2)}" data-master-field-index="2" onchange="toggleNewPartyRole(this.value)"><option value="">Select role</option>${partyRoleOptions(current)}<option value="__ADD_NEW__">+ Add New Role…</option></select></label>
      <label id="newPartyRoleWrap" ${current?"hidden":"hidden"}>New Role<input id="newPartyRole" maxlength="80" placeholder="Enter new role"></label>
    </div></section>
    <section class="master-editor-section"><label class="full-span">Notes<textarea id="${masterInputId(3)}" data-master-field-index="3" rows="4">${escapeHtml(values[3] || "")}</textarea></label></section>`;
  }
  function toggleNewPartyRole(value) {
    const wrap=document.getElementById("newPartyRoleWrap");
    if (!wrap) return;
    wrap.hidden=value!=="__ADD_NEW__";
    if (!wrap.hidden) document.getElementById("newPartyRole")?.focus();
  }
  async function resolvePartyRoleBeforeSave(type) {
    if (type.id!=="parties") return null;
    const select=document.getElementById(masterInputId(2));
    if (!select || select.value!=="__ADD_NEW__") return select?.value || "";
    const role=document.getElementById("newPartyRole")?.value.trim() || "";
    if (!role) throw new Error("Enter the new Party Role.");
    const data=await apiRequest({action:"add-party-role",role},"masters");
    if (data.options) state.masterOptions=data.options;
    select.innerHTML=`<option value="">Select role</option>${partyRoleOptions(role)}<option value="__ADD_NEW__">+ Add New Role…</option>`;
    select.value=role;
    toggleNewPartyRole(role);
    return role;
  }
  function millMasterFieldsHtml(values = []) {
    const types = ["","Own Mill","External Mill","Office","Warehouse","Stock Location","Other"];
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Location identity</h3><p>Keep operational location identity short and clear.</p></div></div><div class="master-identity-grid"><label>Mill / location<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label><label>Code / reference<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}"></label><label>Location type<select id="${masterInputId(2)}" data-master-field-index="2">${types.map(x=>`<option value="${escapeHtml(x)}" ${x===String(values[2]||"")?"selected":""}>${escapeHtml(x||"Select")}</option>`).join("")}</select></label></div></section><section class="master-editor-section"><label class="full-span">Notes<textarea id="${masterInputId(3)}" data-master-field-index="3" rows="4">${escapeHtml(values[3] || "")}</textarea></label></section>`;
  }
  function linkedCompanyOptions(current = "") {
    const companies=(state.masters?.companies||[]).map(row=>{
      const name=String(row.values?.[0]||"").trim();
      const code=String(row.values?.[1]||"").trim();
      return name ? [code,name].filter(Boolean).join(" — ") : "";
    }).filter(Boolean);
    const options=["",...new Set([...companies,...(current?[current]:[])]),"Other"];
    return options.map(option=>`<option value="${escapeHtml(option)}" ${option===current?"selected":""}>${escapeHtml(option||"Select")}</option>`).join("");
  }
  function bankMasterFieldsHtml(values = []) {
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Ownership</h3><p>Who owns the account and which legal entity it belongs to.</p></div></div><div class="master-identity-grid">
      <label>Account type<select id="${masterInputId(0)}" data-master-field-index="0">${["Company Account","Personal Account"].map(x=>`<option ${x===String(values[0]||"")?"selected":""}>${x}</option>`).join("")}</select></label>
      <label>Linked company<select id="${masterInputId(1)}" data-master-field-index="1">${linkedCompanyOptions(String(values[1]||""))}</select></label>
      <label>Personal account owner<input id="${masterInputId(2)}" data-master-field-index="2" value="${escapeHtml(values[2] || "")}"></label>
    </div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Bank details</h3><p>The main register will not expose all banking identifiers.</p></div></div><div class="master-identity-grid">
      <label>Exact account title<input id="${masterInputId(3)}" data-master-field-index="3" value="${escapeHtml(values[3] || "")}" required></label>
      <label>Bank name<input id="${masterInputId(4)}" data-master-field-index="4" value="${escapeHtml(values[4] || "")}" required></label>
      <label>Branch<input id="${masterInputId(5)}" data-master-field-index="5" value="${escapeHtml(values[5] || "")}"></label>
      <label>Country<input id="${masterInputId(6)}" data-master-field-index="6" value="${escapeHtml(values[6] || "")}"></label>
      <label>Currency<input id="${masterInputId(7)}" data-master-field-index="7" value="${escapeHtml(values[7] || "")}"></label>
    </div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Account identifiers</h3><p>Full identifiers remain inside the protected Edit view.</p></div></div><div class="master-identity-grid"><label>Account number<input id="${masterInputId(8)}" data-master-field-index="8" value="${escapeHtml(values[8] || "")}"></label><label>IBAN<input id="${masterInputId(9)}" data-master-field-index="9" value="${escapeHtml(values[9] || "")}"></label><label>SWIFT / BIC<input id="${masterInputId(10)}" data-master-field-index="10" value="${escapeHtml(values[10] || "")}"></label></div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Usage, visibility & status</h3><p>Operational/document visibility is kept away from banking identifiers.</p></div></div><div class="master-form-grid"><label>Purpose / classification<input id="${masterInputId(11)}" data-master-field-index="11" value="${escapeHtml(values[11] || "")}"></label><label class="full-span">Module visibility and document use<textarea id="${masterInputId(12)}" data-master-field-index="12" rows="4">${escapeHtml(values[12] || "")}</textarea></label><label class="full-span">Status / notes<textarea id="${masterInputId(13)}" data-master-field-index="13" rows="4">${escapeHtml(values[13] || "")}</textarea></label></div></section>`;
  }

  const PRODUCT_CORE_SPECS = [
    [6, "Avg. grain length"], [7, "Broken"], [8, "Moisture"], [9, "Damaged / Shriveled / Yellow"],
    [10, "Chalky / Immature"], [11, "Contrasting / Other varieties"], [12, "Foreign grains"],
    [13, "Foreign matter"], [14, "Paddy"], [15, "Red kernels / Red rice"],
    [16, "Under-milled / Red-striped"], [17, "Milling / polishing"]
  ];
  function productCustomSpecs(values = []) {
    try {
      const parsed = JSON.parse(String(values[20] || "[]"));
      return Array.isArray(parsed) ? parsed.filter(row => row && typeof row === "object").map(row => ({ name: String(row.name || ""), limit: String(row.limit || "") })) : [];
    } catch (_) { return []; }
  }
  function productSpecRow(name = "", limit = "", custom = true, fieldIndex = null) {
    if (!custom) {
      return `<tr class="spec-editor-row"><td><strong>${escapeHtml(name)}</strong></td><td><input data-master-field-index="${fieldIndex}" value="${escapeHtml(limit)}" autocomplete="off"></td><td></td></tr>`;
    }
    return `<tr class="spec-editor-row custom-spec-row"><td><input data-custom-spec-name value="${escapeHtml(name)}" placeholder="Specification"></td><td><input data-custom-spec-limit value="${escapeHtml(limit)}" placeholder="Limit / requirement"></td><td><button class="row-action delete" type="button" data-remove-product-spec aria-label="Remove specification">Remove</button></td></tr>`;
  }
  function productMasterFieldsHtml(values = []) {
    const identity = [
      [0,"Commodity",true],[1,"Variety / product",true],[2,"Processing / grade",false],[3,"Code",true],[4,"Origin",false],[5,"Profile / use",false]
    ].map(([index,label,required]) => `<label>${label}<input id="${masterInputId(index)}" data-master-field-index="${index}" value="${escapeHtml(values[index] || "")}" ${required ? "required" : ""} autocomplete="off"></label>`).join("");
    const core = PRODUCT_CORE_SPECS.map(([index,name]) => productSpecRow(name, values[index] || "", false, index)).join("");
    const custom = productCustomSpecs(values).map(row => productSpecRow(row.name, row.limit, true)).join("");
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Product identity</h3><p>Keep the master list short; edit the detailed quality only here.</p></div></div><div class="master-identity-grid">${identity}</div></section>
      <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Specifications & limits</h3><p>One specification per line. Leave a limit blank when it is not confirmed.</p></div></div><div class="spec-editor-wrap"><table class="spec-editor-table"><thead><tr><th>Specification</th><th>Limit / Requirement</th><th></th></tr></thead><tbody id="productSpecRows">${core}${custom}</tbody></table></div><button class="button secondary add-spec-button" id="addProductSpecification" type="button">+ Add Specification</button></section>
      <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Wording & source</h3><p>Quality wording and reference source stay separate from the numeric specification table.</p></div></div><div class="master-form-grid"><label class="full-span">Additional quality wording<textarea id="${masterInputId(18)}" data-master-field-index="18" rows="3">${escapeHtml(values[18] || "")}</textarea></label><label class="full-span">Source / basis<textarea id="${masterInputId(19)}" data-master-field-index="19" rows="3">${escapeHtml(values[19] || "")}</textarea></label></div></section>`;
  }
  function katRangeDefaults(values = []) {
    const saved = String(values[9] || "");
    if (saved) {
      try {
        const parsed = JSON.parse(saved);
        if (Array.isArray(parsed)) return parsed.map(row => ({
          from: String(row.from ?? ""), to: String(row.to ?? ""), value: String(row.value ?? ""), unit: String(row.unit ?? values[5] ?? "paisa per %")
        }));
      } catch (_) {}
    }
    const parameter = String(values[2] || "").toLowerCase();
    const variety = String(values[1] || "").toLowerCase();
    if (variety.includes("irri-6") && parameter === "broken") return [
      {from:"20",to:"30",value:"1",unit:"paisa per %"}, {from:"30",to:"35",value:"3",unit:"paisa per %"},
      {from:"35",to:"40",value:"8",unit:"paisa per %"}, {from:"40",to:"45",value:"15",unit:"paisa per %"},
      {from:"45",to:"50",value:"20",unit:"paisa per %"}, {from:"50",to:"55",value:"25",unit:"paisa per %"},
      {from:"55",to:"60",value:"40",unit:"paisa per %"}
    ];
    if (variety.includes("irri-6") && parameter.includes("damage")) return [
      {from:"2",to:"5",value:"10",unit:"paisa per %"}, {from:"5",to:"",value:"25",unit:"paisa per %"}
    ];
    if (variety.includes("irri-6") && parameter === "chalky") return [
      {from:"5",to:"",value:"10",unit:"paisa per %"}
    ];
    if (variety.includes("irri-6") && parameter === "moisture" && String(values[6] || "").includes("14%")) return [
      {from:"14",to:"14.5",value:"0.5",unit:"weight %"}, {from:"14.5",to:"15",value:"1",unit:"weight %"}, {from:"15",to:"16",value:"2",unit:"weight %"}
    ];
    return [];
  }
  function katRangeRow(row = {}) {
    const units = ["paisa per %","rupees per %","weight %","kg per MT","manual / note only"];
    const unit = String(row.unit || "paisa per %");
    return `<tr class="kat-range-row"><td><input data-kat-from inputmode="decimal" value="${escapeHtml(row.from || "")}" placeholder="e.g. 20"></td><td><input data-kat-to inputmode="decimal" value="${escapeHtml(row.to || "")}" placeholder="blank = and above"></td><td><input data-kat-value inputmode="decimal" value="${escapeHtml(row.value || "")}" placeholder="e.g. 1"></td><td><select data-kat-unit>${units.map(x => `<option ${x===unit?"selected":""}>${x}</option>`).join("")}</select></td><td><button class="row-action delete" type="button" data-remove-kat-range>Remove</button></td></tr>`;
  }
  function katMasterFieldsHtml(values = []) {
    const statusOptions = ["Active", "Draft – review required", "Inactive"];
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>KAT identity</h3><p>Define which quality measurement this rule belongs to.</p></div></div><div class="master-identity-grid">
      <label>Commodity<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label>
      <label>Variety / product<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}" required></label>
      <label>Quality parameter<input id="${masterInputId(2)}" data-master-field-index="2" value="${escapeHtml(values[2] || "")}" required></label>
      <label>Effective / seasonal profile<input id="${masterInputId(6)}" data-master-field-index="6" value="${escapeHtml(values[6] || "")}"></label>
      <label>Rule status<select id="${masterInputId(7)}" data-master-field-index="7">${statusOptions.map(option => `<option ${option === String(values[7] || "") ? "selected" : ""}>${option}</option>`).join("")}</select></label>
    </div></section>
    <section class="master-editor-section kat-calc-section"><div class="master-editor-heading"><div><h3>KAT calculation</h3><p>Enter the percentage bands as rows. “Above” is exclusive and “Up to” is inclusive. Leave “Up to” blank for an open-ended final slab.</p></div></div><div class="master-form-grid"><label>Free / default allowance<input id="${masterInputId(3)}" data-master-field-index="3" value="${escapeHtml(values[3] || "")}"></label><label>Default deduction unit<input id="${masterInputId(5)}" data-master-field-index="5" value="${escapeHtml(values[5] || "paisa per %")}"></label></div><div class="kat-range-wrap"><table class="kat-range-table"><thead><tr><th>Above %</th><th>Up to %</th><th>Deduction</th><th>Unit</th><th></th></tr></thead><tbody id="katRangeRows">${katRangeDefaults(values).map(katRangeRow).join("")}</tbody></table></div><button class="button secondary add-spec-button" id="addKatRange" type="button">+ Add Range</button><input type="hidden" id="${masterInputId(4)}" data-master-field-index="4" value="${escapeHtml(values[4] || "")}"></section>
    <section class="master-editor-section kat-message-section"><div class="master-editor-heading"><div><h3>Staff instruction / message</h3><p>Plain-language instruction shown to operational staff when this KAT rule is relevant.</p></div></div><label class="full-span">Message<textarea id="${masterInputId(8)}" data-master-field-index="8" rows="4" placeholder="Example: Automatic KAT disabled until this profile is approved.">${escapeHtml(values[8] || "")}</textarea></label></section>`;
  }

  function masterFieldsHtml(type, values = []) {
    if (type.id === "salary_staff") {
      const today = new Date().toISOString().slice(0, 10);
      const v = [...values];
      v[1] = v[1] || "TTI"; v[2] = v[2] || "MILL_STAFF"; v[6] = v[6] || today;
      v[8] = v[8] || (v[2] === "HOME_MONTHLY_GIVE" ? "FAMILY_ALLOCATION" : v[2] === "DIRECTOR_REMUNERATION" ? "TO_CONFIRM" : "STAFF_COST");
      v[9] = v[9] || (v[2] === "MILL_STAFF" ? "Yes" : "No"); v[10] = v[10] || "Active";
      return type.fields.map((field, index) => {
        const value=String(v[index]??""),full=field.full?" full-span":"";
        if(field.type==="select") return `<label class="${full.trim()}">${escapeHtml(field.label)}<select id="${masterInputId(index)}" data-master-field-index="${index}" ${field.required?"required":""}>${field.options.map(option=>`<option value="${escapeHtml(option)}" ${option===value?"selected":""}>${escapeHtml(option.replaceAll("_"," "))}</option>`).join("")}</select></label>`;
        if(field.type==="textarea") return `<label class="${full.trim()}">${escapeHtml(field.label)}<textarea id="${masterInputId(index)}" data-master-field-index="${index}" rows="3">${escapeHtml(value)}</textarea></label>`;
        const inputType=["number","date"].includes(field.type)?field.type:"text",numberRules=inputType==="number"?' min="0" step="0.01"':"";
        return `<label class="${full.trim()}">${escapeHtml(field.label)}<input type="${inputType}"${numberRules} id="${masterInputId(index)}" data-master-field-index="${index}" value="${escapeHtml(value)}" ${field.required?"required":""}></label>`;
      }).join("");
    }
    if (type.id === "companies") return companyMasterFieldsHtml(values);
    if (type.id === "commodities") return commodityMasterFieldsHtml(values);
    if (type.id === "parties") return partyMasterFieldsHtml(values);
    if (type.id === "mills") return millMasterFieldsHtml(values);
    if (type.id === "banks") return bankMasterFieldsHtml(values);
    if (type.id === "products") return productMasterFieldsHtml(values);
    if (type.id === "purchase_kat") return katMasterFieldsHtml(values);
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
      const inputType = ["number", "date"].includes(field.type) ? field.type : "text";
      const numberRules = inputType === "number" ? ' min="0" step="0.01"' : "";
      return `<label class="${full.trim()}">${escapeHtml(field.label)}<input type="${inputType}"${numberRules} id="${masterInputId(index)}" data-master-field-index="${index}" value="${escapeHtml(value)}" ${field.required ? "required" : ""} autocomplete="off"></label>`;
    }).join("");
  }
  function masterValuesFromForm(type) {
    if (type.id === "products") {
      const values = Array(21).fill("");
      for (let index = 0; index < 20; index += 1) values[index] = document.querySelector(`[data-master-field-index="${index}"]`)?.value.trim() || "";
      const custom = [...document.querySelectorAll("#productSpecRows .custom-spec-row")].map(row => ({
        name: row.querySelector("[data-custom-spec-name]")?.value.trim() || "",
        limit: row.querySelector("[data-custom-spec-limit]")?.value.trim() || ""
      })).filter(row => row.name || row.limit);
      values[20] = JSON.stringify(custom);
      return values;
    }
    if (["companies","commodities","parties","mills","banks"].includes(type.id)) {
      return type.fields.map((field, index) => {
        if (field.type === "checks" || (type.id === "companies" && index === 4)) {
          return [...document.querySelectorAll(`[data-master-field-index="${index}"]:checked`)].map(input => input.value).join("; ");
        }
        return document.getElementById(masterInputId(index))?.value.trim() || "";
      });
    }
    if (type.id === "purchase_kat") {
      const values = Array(10).fill("");
      [0,1,2,3,5,6,7,8].forEach(index => { values[index] = document.getElementById(masterInputId(index))?.value.trim() || ""; });
      const ranges = [...document.querySelectorAll("#katRangeRows .kat-range-row")].map(row => ({
        from: row.querySelector("[data-kat-from]")?.value.trim() || "",
        to: row.querySelector("[data-kat-to]")?.value.trim() || "",
        value: row.querySelector("[data-kat-value]")?.value.trim() || "",
        unit: row.querySelector("[data-kat-unit]")?.value.trim() || values[5] || "paisa per %"
      })).filter(row => row.from || row.to || row.value);
      values[9] = JSON.stringify(ranges);
      values[4] = ranges.map(row => `Above ${row.from || "start"}%${row.to ? ` up to ${row.to}%` : " and above"}: ${row.value || "—"} ${row.unit}`).join("; ");
      return values;
    }
    return type.fields.map((field, index) => {
      if (field.type === "checks") {
        return [...document.querySelectorAll(`[data-master-field-index="${index}"]:checked`)].map(input => input.value).join("; ");
      }
      return document.getElementById(masterInputId(index))?.value.trim() || "";
    });
  }
  function displayColumns(type) {
    if (type.id === "companies") return [1, 0, 3, 2];
    if (type.id === "commodities") return [1, 0, 2, 3];
    if (type.id === "products") return [0, 1, 2, 3, 5];
    if (type.id === "purchase_kat") return [0, 1, 2, 3, 6, 7];
    if (type.id === "parties") return [0, 1, 2];
    if (type.id === "mills") return [0, 1, 2];
    if (type.id === "banks") return [3, 4, 0, 7, 11];
    return type.fields.map((_, index) => index).slice(0, 5);
  }
  function masterRowStatus(type, row) {
    if (type.id === "salary_staff") return String(row.values?.[10] || "Active");
    if (type.id === "products") {
      const profile = String(row.values?.[5] || "");
      if (/inactive|reference/i.test(profile)) return profile;
      return profile || "Active";
    }
    if (type.id === "purchase_kat") return String(row.values?.[7] || "Draft – review required");
    if (type.id === "banks") { const note=String(row.values?.[13] || ""); return /incomplete/i.test(note) ? "Incomplete" : (/inactive/i.test(note) ? "Inactive" : "Active"); }
    return "Active";
  }

  function renderMasters() {
    state.masters = ensureMasterSections(state.masters);
    document.getElementById("masterMenu").innerHTML = MASTER_TYPES.map(type => `<button class="${type.id === currentMaster ? "active" : ""}" data-master="${type.id}">${type.name}<span>${state.masters[type.id]?.length || 0}</span></button>`).join("");
    const type = masterType();
    document.getElementById("masterTitle").textContent = type.name;
    document.getElementById("masterDescription").textContent = type.description;
    document.getElementById("addMasterRecord").textContent = `+ Add ${type.id === "salary_staff" ? "Staff" : type.id === "purchase_kat" ? "KAT Rule" : type.id === "companies" ? "Company" : type.id === "commodities" ? "Commodity" : type.id === "products" ? "Product" : "Record"}`;
    const columns = displayColumns(type);
    document.getElementById("masterTableHead").innerHTML = `<tr>${columns.map(index => `<th>${escapeHtml(type.fields[index].label)}</th>`).join("")}<th>Status</th><th>Actions</th></tr>`;
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
    if (type.id === "salary_staff") {
      const legalBook=document.getElementById(masterInputId(1));
      const salaryGroup=document.getElementById(masterInputId(2));
      const syncSalaryEntity=()=>{
        const isMill=salaryGroup?.value==="MILL_STAFF";
        if (isMill && legalBook) legalBook.value="TTI";
        if (legalBook) {
          legalBook.disabled=isMill;
          legalBook.title=isMill?"Mill salaries belong to TTI only.":"";
        }
      };
      salaryGroup?.addEventListener("change", event => {
        const cat=event.target.value;
        document.getElementById(masterInputId(8)).value=cat==="HOME_MONTHLY_GIVE"?"FAMILY_ALLOCATION":"STAFF_COST";
        document.getElementById(masterInputId(9)).value=cat==="MILL_STAFF"?"Yes":"No";
        syncSalaryEntity();
      });
      syncSalaryEntity();
    }
    document.getElementById("deleteMasterButton").hidden = !row || (type.id === "salary_staff" && String(row.values?.[10] || "Active") === "Inactive");
    document.getElementById("deleteMasterButton").textContent = type.id === "salary_staff" ? "Remove Staff" : "Delete Record";
    document.getElementById("saveMasterButton").textContent = row ? "Save Changes" : "Save Record";
    document.getElementById("masterDialog").showModal();
  }
  async function saveMasterRecord(event) {
    event.preventDefault();
    const form = document.getElementById("masterForm");
    if (!form.reportValidity()) return;
    const type = masterType();
    const id = document.getElementById("editMasterId").value;
    try { await resolvePartyRoleBeforeSave(type); } catch (error) { toast(error.message); return; }
    const values = masterValuesFromForm(type);
    const primary = values[0] || type.name;
    const ref = values[1] || currentMaster.toUpperCase();
    try {
      const data = await apiRequest({ action: id ? "update" : "create", type: currentMaster, id, values }, "masters");
      state.masters = ensureMasterSections(data.masters); if (data.options) state.masterOptions=data.options; addAudit("Master", id ? "Updated" : "Created", `${type.name}: ${primary}`, ref);
      saveState(); renderMasters(); renderAudit(); renderRecentActivity(); document.getElementById("masterDialog").close(); form.reset(); toast(id ? "Master record updated." : "Master record saved.");
    } catch (error) { toast(error.message); }
  }
  async function deleteMasterRecord(selectedId) {
    const id = String(selectedId || document.getElementById("editMasterId").value);
    const row = (state.masters[currentMaster] || []).find(item => item.id === id); if (!row) return;
    const removingStaff=currentMaster==="salary_staff";
    if (!window.confirm(`${removingStaff ? "Remove" : "Delete"} ${row.values[0]} ${removingStaff ? "from future Salary Sheets" : `from ${masterType().name}`}?`)) return;
    try { const data = await apiRequest({ action: "delete", type: currentMaster, id }, "masters"); state.masters = ensureMasterSections(data.masters); saveState(); renderMasters(); document.getElementById("masterDialog").close(); toast(removingStaff ? "Staff removed from future Salary Sheets." : "Master record deleted."); }
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


  function backupFriendlyDate(value) {
    if (!value) return "Not yet";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: false }).format(date);
  }
  function backupBytes(value) {
    let n = Number(value || 0); const units = ["B", "KB", "MB", "GB"]; let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i += 1; }
    return `${n >= 10 || i === 0 ? n.toFixed(0) : n.toFixed(1)} ${units[i]}`;
  }
  function backupFileName(response, fallback) {
    const cd = response.headers.get("Content-Disposition") || "";
    const match = cd.match(/filename="?([^";]+)"?/i);
    return match?.[1] || fallback;
  }
  async function backupFetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ ok: false, error: "The backup service returned an unreadable response." }));
    if (!response.ok || !data.ok) throw new Error(data.error || "The backup action could not be completed.");
    return data;
  }
  async function loadBackupStatus() {
    if (!IS_SUPER_ADMIN) return;
    try {
      const data = await backupFetchJson("api/backup.php?action=status");
      document.getElementById("backupLastAuto").textContent = backupFriendlyDate(data.lastAutoBackup);
      document.getElementById("backupAutoDetail").textContent = data.automaticAvailable ? "Automatic secure snapshots are active" : "Automatic snapshot support is unavailable on this server";
      document.getElementById("backupSnapshotCount").textContent = String(data.snapshotCount ?? 0);
      document.getElementById("backupSnapshotSize").textContent = `${backupBytes(data.snapshotBytes)} stored privately on server`;
      document.getElementById("backupLastOwner").textContent = data.lastOwnerDownload ? backupFriendlyDate(data.lastOwnerDownload) : "Not downloaded yet";
    } catch (error) { toast(error.message); }
  }
  async function downloadBackup(kind) {
    const isFull = kind === "full-download";
    const password = isFull ? document.getElementById("recoveryBackupPassword").value : "";
    if (isFull && password.length < 12) { toast("Enter a recovery ZIP password of at least 12 characters."); return; }
    const button = document.getElementById(isFull ? "downloadFullBackup" : "downloadBusinessBackup");
    const old = button.textContent; button.disabled = true; button.textContent = "Preparing backup…";
    try {
      const response = await fetch("api/backup.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: kind, password, csrf: SESSION.csrf }) });
      if (!response.ok) {
        const data = await response.json().catch(() => ({})); throw new Error(data.error || "The backup could not be prepared.");
      }
      const blob = await response.blob();
      const url = URL.createObjectURL(blob); const link = document.createElement("a");
      link.href = url; link.download = backupFileName(response, isFull ? "TRANSTRADE_FULL_BACKUP.zip" : "TRANSTRADE_BUSINESS_DATA.zip");
      document.body.appendChild(link); link.click(); link.remove(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      addAudit("Backup", "Downloaded", isFull ? "Complete Transtrade recovery backup downloaded" : "Readable business-data backup downloaded", isFull ? "FULL-BACKUP" : "BUSINESS-DATA");
      saveState(); renderAudit(); toast(isFull ? "Complete encrypted backup downloaded." : "Business data downloaded.");
      await loadBackupStatus();
    } catch (error) { toast(error.message); }
    finally { button.disabled = false; button.textContent = old; }
  }
  async function createServerSnapshot() {
    const button = document.getElementById("createServerSnapshot"); const old = button.textContent; button.disabled = true; button.textContent = "Creating…";
    try {
      await backupFetchJson("api/backup.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ action: "snapshot", csrf: SESSION.csrf }) });
      toast("Private server recovery snapshot created."); await loadBackupStatus();
    } catch (error) { toast(error.message); }
    finally { button.disabled = false; button.textContent = old; }
  }
  let verifiedRestoreSignature = "";
  async function verifyBackupForRestore() {
    const file = document.getElementById("restoreBackupFile").files?.[0];
    const password = document.getElementById("restoreBackupPassword").value;
    const box = document.getElementById("restoreVerification"); const confirmArea = document.getElementById("restoreConfirmArea");
    verifiedRestoreSignature = ""; confirmArea.hidden = true; box.hidden = false; box.classList.remove("error"); box.textContent = "Verifying backup…";
    if (!file) { box.classList.add("error"); box.textContent = "Select a complete Transtrade backup ZIP first."; return; }
    const form = new FormData(); form.append("action", "verify"); form.append("csrf", SESSION.csrf); form.append("password", password); form.append("backup", file);
    try {
      const data = await backupFetchJson("api/backup.php", { method: "POST", body: form });
      verifiedRestoreSignature = `${file.name}|${file.size}|${file.lastModified}`;
      box.innerHTML = `<strong>Verified Transtrade recovery backup.</strong><br>Created: ${escapeHtml(backupFriendlyDate(data.createdAt))}<br>Application version: ${escapeHtml(data.appVersion || "Recorded in manifest")}<br>Recovery files: ${escapeHtml(data.recoveryFileCount)}<br>Integrity manifest: valid`;
      confirmArea.hidden = false; document.getElementById("restoreConfirmation").value = ""; document.getElementById("restoreBackupNow").disabled = true;
    } catch (error) { box.classList.add("error"); box.textContent = error.message; }
  }
  function updateRestoreEnablement() {
    const file = document.getElementById("restoreBackupFile").files?.[0];
    const signature = file ? `${file.name}|${file.size}|${file.lastModified}` : "";
    document.getElementById("restoreBackupNow").disabled = !(verifiedRestoreSignature && signature === verifiedRestoreSignature && document.getElementById("restoreConfirmation").value.trim().toUpperCase() === "RESTORE");
  }
  async function restoreBackupNow() {
    const file = document.getElementById("restoreBackupFile").files?.[0]; const password = document.getElementById("restoreBackupPassword").value;
    if (!file || document.getElementById("restoreBackupNow").disabled) return;
    if (!window.confirm("Restore this verified backup? Transtrade will first create a safety snapshot of the current data, then replace the recovery data from the selected backup.")) return;
    const button = document.getElementById("restoreBackupNow"); const old = button.textContent; button.disabled = true; button.textContent = "Restoring…";
    const form = new FormData(); form.append("action", "restore"); form.append("csrf", SESSION.csrf); form.append("password", password); form.append("confirmation", "RESTORE"); form.append("backup", file);
    try {
      const data = await backupFetchJson("api/backup.php", { method: "POST", body: form });
      addAudit("Backup", "Restored", `Complete backup restored from ${file.name}`, "FULL-RESTORE"); saveState();
      alert(`Transtrade backup restored successfully.\n\nSafety snapshot: ${data.safetySnapshot || "created"}\n\nThe page will now reload.`);
      window.location.reload();
    } catch (error) { toast(error.message); button.disabled = false; button.textContent = old; }
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
    const removeProductSpec = event.target.closest("[data-remove-product-spec]");
    const removeKatRange = event.target.closest("[data-remove-kat-range]");
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
    if (event.target.closest("#addProductSpecification")) document.getElementById("productSpecRows")?.insertAdjacentHTML("beforeend", productSpecRow("", "", true));
    if (removeProductSpec) removeProductSpec.closest("tr")?.remove();
    if (event.target.closest("#addKatRange")) document.getElementById("katRangeRows")?.insertAdjacentHTML("beforeend", katRangeRow({unit: document.getElementById(masterInputId(5))?.value || "paisa per %"}));
    if (removeKatRange) removeKatRange.closest("tr")?.remove();
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
  document.getElementById("refreshBackupStatus").addEventListener("click", loadBackupStatus);
  document.getElementById("downloadBusinessBackup").addEventListener("click", () => downloadBackup("business-download"));
  document.getElementById("downloadFullBackup").addEventListener("click", () => downloadBackup("full-download"));
  document.getElementById("createServerSnapshot").addEventListener("click", createServerSnapshot);
  document.getElementById("verifyBackupFile").addEventListener("click", verifyBackupForRestore);
  document.getElementById("restoreConfirmation").addEventListener("input", updateRestoreEnablement);
  document.getElementById("restoreBackupFile").addEventListener("change", () => { verifiedRestoreSignature = ""; document.getElementById("restoreConfirmArea").hidden = true; document.getElementById("restoreVerification").hidden = true; updateRestoreEnablement(); });
  document.getElementById("restoreBackupNow").addEventListener("click", restoreBackupNow);
  document.getElementById("notificationButton").addEventListener("click", () => openNotifications(true));
  document.getElementById("overlay").addEventListener("click", () => { openNotifications(false); document.getElementById("sidebar").classList.remove("open"); });
  document.getElementById("menuButton").addEventListener("click", () => { document.getElementById("sidebar").classList.add("open"); document.getElementById("overlay").classList.add("open"); });
  document.getElementById("globalSearch").addEventListener("keydown", event => { if (event.key === "Enter") globalSearch(event.target.value); });
  document.addEventListener("keydown", event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") { event.preventDefault(); document.getElementById("globalSearch").focus(); }
    if (event.key === "Escape") openNotifications(false);
  });

  async function initialize() {
    applySessionAccess(); renderModules(); renderUsers(); renderMasters(); renderLocks(); renderAudit(); renderRecentActivity(); loadBackupStatus();
    if (IS_SUPER_ADMIN) {
      try { await Promise.all([loadServerUsers(),loadServerMasters()]); } catch (error) { toast(error.message); }
    }
    saveState("All changes saved");
  }
  initialize();
})();
