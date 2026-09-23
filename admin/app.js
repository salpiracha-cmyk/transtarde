(() => {
  "use strict";

  const STORAGE_KEY = "transtrade_super_admin_v1";
  const STATE_VERSION = 5;
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
  const MASTER_PERMISSION_ACTIONS = ["Use","View","Create","Edit","Deactivate","View Documents","Download Documents"];
  const hasMasterAccess = IS_SUPER_ADMIN || !!SESSION.masterAccess;
  const canMaster = (type, action="View") => {const permissionType=["purchase_kat","commodities"].includes(type)?"purchase_products":type;return IS_SUPER_ADMIN || (hasMasterAccess && (SESSION.masterPermissions?.[permissionType] || []).includes(action));};
  // Accounts V1 opens through its protected standalone workspace route.
  const MODULES = [
    { id: "milling", name: "Mill", code: "M", color: "#16815a", soft: "#e7f7f0", status: "Live", state: "green", version: "V3.3.2 Audited", description: "Arrivals, stocks, production, bags, loading and mill operations.", href: "module.php?id=milling" },
    { id: "exports", name: "Exports", code: "E", color: "#1769d2", soft: "#eaf2ff", status: "Live", state: "green", version: "V3 Clean Operational", description: "Contracts, export orders, shipment planning and documentation.", href: "module.php?id=exports" },
    { id: "accounts", name: "Accounts", code: "A", color: "#8a55c7", soft: "#f3ecfb", status: "Live", state: "green", version: "Accounts V1 Live", description: "Purchases, ledgers, banking, receivables, payables and reporting.", href: "accounts/index.php" },
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
        { label: "System behaviour / notes", type: "textarea", full: true },
        { label: "Owners / partners", type: "textarea", full: true },
        { label: "KCCI membership no." }, { label: "REAP membership no." },
        { label: "NTN number" }, { label: "Sales tax number" }, { label: "Company number" },
        { label: "Bank accounts", type: "hidden" }, { label: "Document identities", type: "hidden" }
      ],
      rows: [
        ["Transtrade International", "TTI", "Pakistan", "Pakistan", "Group Company; Pakistan Operating Entity; Exporter; Seller; Buyer; Accounting Entity", "No", "Primary Pakistan operating/export entity.", '[{"name":"","share":100}]', "36453", "", "", "", ""],
        ["Buksh Rice Mills", "BRM", "Pakistan", "Pakistan", "Group Company; Mill / Processor; Seller; Buyer; Accounting Entity", "No", "Mill/processing entity and authorized document identity.", '[{"name":"","share":100}]', "", "", "", "", ""],
        ["Trans Grains Foodstuff Trading L.L.C", "TG", "United Arab Emirates", "Offshore", "Group Company; Offshore Export Contracting; Intercompany; Accounting Entity", "Yes", "TG-linked group workflow. Keep Pakistan and offshore accounting/legal records separated while allowing authorized group-owner visibility.", '[{"name":"","share":100}]', "", "", "", "", "", ""]
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
      id: "products", name: "Export Quality & Specs", description: "Commercial export products, approved quality wording and specifications. Purchase stages and KAT remain in Purchase Commodities & KAT.",
      fields: [
        { label: "Commodity", required: true }, { label: "Variety", required: true }, { label: "Rice type", required: true }, { label: "Code", required: true },
        { label: "Origin" }, { label: "Profile / use" }, { label: "Avg. grain length" }, { label: "Broken" }, { label: "Moisture" },
        { label: "Damaged / Shriveled / Yellow" }, { label: "Chalky / Immature" }, { label: "Contrasting / Other varieties" },
        { label: "Foreign grains" }, { label: "Foreign matter" }, { label: "Paddy" }, { label: "Red kernels / Red rice" },
        { label: "Under-milled / Red-striped" }, { label: "Finish" },
        { label: "Additional quality wording", type: "textarea", full: true }, { label: "Source / basis", type: "textarea", full: true },
        { label: "Custom specifications", type: "hidden", full: true }, { label: "HS Code" }
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
      id: "purchase_products", name: "Purchase Commodities & KAT", description: "Choose the commodity, RAW or READY stage, variety and type. Every exact purchase product keeps its own stock identity, Soda history and KAT rules.",
      fields: [
        { label: "Commodity", required: true, type: "select", options: ["RICE", "CORN", "SESAME"] },
        { label: "Base variety / product", required: true },
        { label: "Rice type" },
        { label: "Purchase classification", required: true, type: "select", options: ["RAW", "READY"] },
        { label: "Purchase unit", required: true, type: "select", options: ["KG", "MAUND", "MT"] },
        { label: "KAT profile" }, { label: "Legacy brokery rule", type: "hidden" }, { label: "Legacy inventory account", type: "hidden" },
        { label: "Status", type: "select", options: ["Active", "Draft – review required", "Inactive"] },
        { label: "Notes", type: "textarea", full: true }
      ],
      rows: []
    },
    {
      id: "purchase_kat", name: "Purchase KAT Profiles", description: "One complete KAT page per exact variety, rice type and RAW/READY purchase classification. Parameters and ranges stay together.",
      fields: [
        { label: "Commodity", required: true }, { label: "Base variety", required: true }, { label: "Rice type" },
        { label: "Purchase classification", required: true }, { label: "Profile name", required: true },
        { label: "Effective from", type: "date" }, { label: "Effective to", type: "date" },
        { label: "Status", type: "select", options: ["Active", "Draft – review required", "Inactive"] },
        { label: "Notes", type: "textarea", full: true }, { label: "Quality parameters", type: "hidden", full: true }
      ],
      rows: []
    },
    { id: "export_documents", name: "Export Documents Presented", description: "Authoritative Sales Contract document rows shared by Super Admin and Exports.", fields: [{label:"Document Name",required:true},{label:"Original",required:true},{label:"Copies",required:true},{label:"Applies To",type:"select",options:["ALL","FOB","CFR","CIF","LC_SIGHT","LC_USANCE"]},{label:"Status",type:"select",options:["Active","Inactive"]}], rows: [["Commercial Invoice","3","0","ALL","Active"],["Commercial Packing List","3","0","ALL","Active"],["Full set clean on-board Bill of Lading","3","3","ALL","Active"],["Certificate of Origin","1","3","ALL","Active"],["e-Phyto issued by Department of Plant Protection, Government of Pakistan","1","0","ALL","Active"],["Fumigation Certificate","1","1","ALL","Active"],["Insurance Policy / Certificate","1","0","CIF","Active"]] },
    { id: "export_terms", name: "Export Other Terms", description: "Authoritative reusable Sales Contract terms. Locked L/C clauses remain protected in the Export workflow.", fields: [{label:"Payment Group",required:true,type:"select",options:["BASE","ADVANCE","CAD","LC_SIGHT","LC_USANCE","CUSTOM"]},{label:"Term Text",required:true,type:"textarea",full:true},{label:"Status",type:"select",options:["Active","Inactive"]}], rows: [["BASE","All present and/or future customs taxes and/or duties/levies on the cargo in the country of origin shall be for Seller’s account. All present and/or future customs taxes and/or duties/levies on the cargo in the country of destination shall be for Buyer’s account.","Active"],["BASE","Risk of weight and quality is transferred to Buyer once cargo is loaded on board the vessel from Pakistan.","Active"],["BASE","Ownership of cargo is transferred to Buyer upon receipt of full payment of the invoice.","Active"],["BASE","All other terms and conditions as per applicable GAFTA London rules, of which both parties admit full notice and knowledge. English law to apply.","Active"],["BASE","Should any dispute arise which cannot be amicably settled between Buyer and Seller, the dispute shall be settled by arbitration in London as per applicable GAFTA rules.","Active"],["ADVANCE","Partial shipment allowed.","Active"],["CAD","Partial shipment allowed.","Active"]] },
    { id: "export_customers", name: "Export Customers", description: "Buyer identity, document addresses, contacts, consignee and notify parties. Saved shipments retain their historical snapshot.", fields: [{label:"Customer name",required:true},{label:"Code"},{label:"Roles"},{label:"Primary document address",required:true,type:"textarea",full:true},{label:"Country"},{label:"Email"},{label:"Phone"},{label:"Tax / registration"},{label:"Packing default"},{label:"Notify parties JSON",type:"textarea",full:true},{label:"Status",type:"select",options:["Active","Inactive"]},{label:"Notes",type:"textarea",full:true},{label:"Show country"},{label:"Show email"},{label:"Show phone"},{label:"Show tax"},{label:"Contacts JSON",type:"textarea",full:true},{label:"Consignees JSON",type:"textarea",full:true},{label:"Additional notify parties JSON",type:"textarea",full:true},{label:"Additional document addresses JSON",type:"textarea",full:true},{label:"Default currency",type:"select",options:["","USD","EUR","GBP","AED","PKR"]},{label:"Default payment / customer instructions",type:"textarea",full:true}], rows: [] },
    { id: "business_parties", name: "Business Parties", description: "Suppliers, brokers, indentors, service providers, local buyers and other third parties stored once and reused in operational forms. A local broker keeps Buying Brokery and Selling Brokery inside the broker profile; an export Indentor's commission is entered deal-by-deal in the Sales Contract and is not stored here.", fields: [{label:"Party name",required:true},{label:"Code / reference"},{label:"Categories",required:true,type:"checks",full:true,options:["Supplier","Broker","Indentor","Clearing Agent","Freight Forwarder","Shipping Line / Carrier","Transporter","Inspection","Fumigation","Service Provider","Local Buyer","Agent","Other"]},{label:"Address",type:"textarea",full:true},{label:"Country"},{label:"Contact person"},{label:"Phone"},{label:"Email"},{label:"NTN / tax number"},{label:"Payment terms"},{label:"Status",type:"select",options:["Active","Inactive"]},{label:"Notes",type:"textarea",full:true},{label:"Brokery profile",type:"hidden",full:true}], rows: [] },
    { id: "reference_lists", name: "Reference Lists", description: "Small controlled dropdown choices used throughout Transtrade. Add a choice once; forms reuse it with type-ahead search.", fields: [{label:"List",required:true,type:"select",options:["currencies","packing_types","inspection_companies","payment_options","party_roles","product_rice_types","product_finishes"]},{label:"Option",required:true}], rows: [] },
    { id: "mills", name: "Mills & Locations", description: "One central list reused by Soda, Milling, Exports and stock reports.", fields: [{label:"Mill / location",required:true},{label:"Code / reference"},{label:"Location type",type:"select",options:["Own Mill","External Mill","Reprocessing Mill","Warehouse","Office","Stock Location","Other"]},{label:"Full address",type:"textarea",full:true},{label:"Contact details"},{label:"Status",type:"select",options:["Active","Inactive"]},{label:"Notes",type:"textarea",full:true}], rows: [["TTI Rice Mills", "TTI-MILL", "Own Mill", "", "", "Active", ""], ["Karachi Office", "KHI-OFF", "Office", "", "", "Active", ""]] }
  ];
  const MASTER_GROUPS = [
    ["Companies",["companies"]],
    ["Export Customers",["export_customers"]],
    ["Business Parties",["business_parties"]],
    ["Products & Procurement",["products","purchase_products"]],
    ["Mills & Locations",["mills"]],
    ["Setup",["product_settings","export_documents","export_terms","salary_staff"]],
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
    audit: [],
    permissionChanges: 0,
    masterOptions: { party_roles: ["Buyer","Supplier","Broker","Export Buyer","Local Buyer","Customer","Agent","Service Provider","Other"] }
  };

  let state = loadState();
  let currentMaster = "companies";
  let currentPurchaseTab = "RICE_RAW";
  let currentPurchaseSection = "PRODUCTS";
  let pendingKatProductId = "";

  function cloneDefault() { return JSON.parse(JSON.stringify(defaultState)); }
  function loadState() {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
      if (!saved || !saved.users || !saved.audit) return cloneDefault();
      const loaded = { ...cloneDefault(), ...saved };
      loaded.masters = ensureMasterSections(loaded.masters,loaded.masterOptions);
      if ((loaded.stateVersion || 1) < STATE_VERSION) {
        loaded.users = loaded.users.filter(user => {
          const identity = `${user.name || ""} ${user.username || ""}`.toLowerCase();
          return user.id !== "u-irfan" && !identity.includes("irfan");
        });
        const demoRefs = new Set(["MILL-V3.3.2", "EXP-V2.6", "TTI-EXP-042"]);
        const qaMarker = /(^|[\s\/_-])(QA|TEST|DUMMY|DEMO|SAMPLE|BULK)([\s\/_-]|$)/i;
        loaded.audit = loaded.audit.filter(item => String(item.user).toLowerCase() !== "irfan" && !demoRefs.has(String(item.ref || "")) && !qaMarker.test(Object.values(item).join(" ")));
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
    return permission === "all" || (Array.isArray(permission) && permission.length > 0) || (permission&&typeof permission==="object"&&Object.values(permission).some(actions=>Array.isArray(actions)&&actions.includes("View")));
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
    if (!hasMasterAccess) return;
    const data = await apiRequest(null, "masters");
    state.masters = ensureMasterSections(data.masters,data.options||state.masterOptions);
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
      document.querySelectorAll('[data-view="users"], [data-view="locks"], [data-view="audit"], [data-view="backup"], [data-action="create-user"], [data-view-target="audit"], #exportAudit, .dashboard-lower, #notificationButton').forEach(node => { node.hidden = true; });
      document.querySelectorAll('[data-view="masters"]').forEach(node=>{node.hidden=!hasMasterAccess});
      if (hasMasterAccess) showView("masters");
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
  function masterPermissionMatrix(user = {}) {
    const saved=user.masterPermissions||{};
    const visibleMasterPermissions=MASTER_TYPES.filter(type=>type.id!=="reference_lists"&&type.id!=="commodities"&&type.id!=="purchase_kat");
    return `<div class="permission-row header master-permission-row"><strong>Master</strong>${MASTER_PERMISSION_ACTIONS.map(action=>`<span>${action.replace(' Documents',' Docs')}</span>`).join("")}</div>`+visibleMasterPermissions.map(type=>`<div class="permission-row master-permission-row"><strong>${escapeHtml(type.name)}</strong>${MASTER_PERMISSION_ACTIONS.map(action=>`<label title="${escapeHtml(type.name)} · ${action}"><input type="checkbox" data-master-permission="${type.id}" value="${action}" ${Array.isArray(saved[type.id])&&saved[type.id].includes(action)?"checked":""}></label>`).join("")}</div>`).join("");
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
    document.getElementById("userMasterAccess").checked=!!user?.masterAccess;
    document.getElementById("masterPermissionMatrix").innerHTML=masterPermissionMatrix(user||{});
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
    const masterPermissions={};
    document.querySelectorAll('#masterPermissionMatrix input[data-master-permission]:checked').forEach(input=>((masterPermissions[input.dataset.masterPermission]||=[]).push(input.value)));
    const masterAccess=document.getElementById('userMasterAccess').checked;
    const modules = Object.keys(permissions).filter(module => Object.values(permissions[module]).some(actions=>actions.includes("View")));
    if (!modules.length && !masterAccess) { toast("Select at least one module or Master Records permission."); return; }
    const user = {
      id,
      name: document.getElementById("userName").value.trim(),
      username: document.getElementById("username").value.trim(),
      role: document.getElementById("userRole").value,
      location: document.getElementById("userLocation").value,
      active: document.getElementById("userActive").checked,
      modules, permissions,
      masterAccess, masterPermissions,
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

  function ensureMasterSections(masters = {},masterOptions = {}) {
    const result = { ...(masters || {}) };
    MASTER_TYPES.forEach(type => {
      if (!Array.isArray(result[type.id])) {
        result[type.id] = type.rows.map((row, index) => ({ id: `${type.id}-${index + 1}`, values: [...row] }));
      }
    });
    result.reference_lists=Object.entries(masterOptions||{}).flatMap(([key,items])=>(["currencies","packing_types","inspection_companies","payment_options","party_roles","product_rice_types","product_finishes"].includes(key)?(items||[]).map(value=>({id:`${key}::${value}`,values:[key,value]})):[]));

    const companyType = MASTER_TYPES.find(type => type.id === "companies");
    const companyDefaults = new Map((companyType?.rows || []).map(row => [String(row[1]).toUpperCase(), row]));
    const seenCompanies = new Set();
    result.companies = (result.companies || []).map(row => {
      const values = [...(row.values || [])];
      const code = String(values[1] || "").toUpperCase();
      if (code) seenCompanies.add(code);
      if (companyDefaults.has(code) && (values.length <= 3 || (code === "BRM" && values[0] === "BRM"))) return { ...row, values: [...companyDefaults.get(code)] };
      const defaults=companyDefaults.get(code)||[];
      while (values.length < 13) values.push(defaults[values.length] || "");
      if (!values[7]) values[7]='[{"name":"","share":100}]';
      if (code === "TTI" && !values[8]) values[8]="36453";
      return { ...row, values };
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
      while (values.length < 22) values.push("");
      return { ...row, values };
    });
    return result;
  }
  function masterType() { return MASTER_TYPES.find(item => item.id === currentMaster); }
  function activeMasterType() {
    if (currentMaster !== "purchase_products") return masterType();
    const sectionType = currentPurchaseSection === "KAT" ? "purchase_kat" : currentPurchaseSection === "COMMODITIES" ? "commodities" : "purchase_products";
    return MASTER_TYPES.find(item => item.id === sectionType);
  }
  function masterInputId(index) { return `masterField${index}`; }

  function companyMasterFieldsHtml(values = []) {
    const roles = ["Group Company", "Pakistan Operating Entity", "Offshore Export Contracting", "Exporter", "Mill / Processor", "Seller", "Buyer", "Intercompany", "Accounting Entity"];
    const selected = new Set(String(values[4] || "").split(";").map(v => v.trim()).filter(Boolean));
    let owners=[];try{owners=JSON.parse(values[7]||"[]")}catch{}if(!Array.isArray(owners)||!owners.length)owners=[{name:"",share:100}];
    let banks=[];try{banks=JSON.parse(values[13]||"[]")}catch{}if(!Array.isArray(banks))banks=[];
    let documents=[];try{documents=JSON.parse(values[14]||"[]")}catch{}if(!Array.isArray(documents))documents=[];
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Company identity</h3><p>The list shows only identity. Legal and workflow detail stays inside Edit.</p></div></div><div class="master-identity-grid">
      <label>Legal company name<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label>
      <label>Short code<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}" required></label>
      <label>Country<input id="${masterInputId(2)}" data-master-field-index="2" value="${escapeHtml(values[2] || "")}"></label>
      <label>Entity scope<select id="${masterInputId(3)}" data-master-field-index="3">${["","Pakistan","Offshore","Other"].map(x => `<option value="${escapeHtml(x)}" ${x===String(values[3]||"")?"selected":""}>${escapeHtml(x||"Select")}</option>`).join("")}</select></label>
    </div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Company roles</h3><p>These roles tell Transtrade where the entity may be used.</p></div></div><div class="master-checks">${roles.map(role => `<label><input type="checkbox" data-master-field-index="4" value="${escapeHtml(role)}" ${selected.has(role)?"checked":""}>${escapeHtml(role)}</label>`).join("")}</div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Ownership</h3><p>Single-owner companies default to 100%. Partnership shares must total 100%.</p></div><button type="button" class="button secondary" id="addCompanyOwner">+ Add owner</button></div><input type="hidden" id="${masterInputId(7)}" data-master-field-index="7" value="${escapeHtml(values[7]||'')}"><div id="companyOwnerRows">${owners.map((owner,index)=>`<div class="master-identity-grid company-owner-row"><label>Owner / partner name<input data-company-owner-name value="${escapeHtml(owner.name||'')}"></label><label>Share %<input data-company-owner-share type="number" min="0" max="100" step="0.01" value="${Number(owner.share??(owners.length===1?100:0))}"></label><button type="button" class="button danger" data-remove-company-owner="${index}">Remove</button></div>`).join('')}</div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Registrations & memberships</h3><p>Pakistan registrations and offshore company identity remain with the legal entity.</p></div></div><div class="master-form-grid"><label>KCCI membership no.<input id="${masterInputId(8)}" data-master-field-index="8" value="${escapeHtml(values[8]||'')}"></label><label>REAP membership no.<input id="${masterInputId(9)}" data-master-field-index="9" value="${escapeHtml(values[9]||'')}"></label><label>NTN number<input id="${masterInputId(10)}" data-master-field-index="10" value="${escapeHtml(values[10]||'')}"></label><label>Sales tax number<input id="${masterInputId(11)}" data-master-field-index="11" value="${escapeHtml(values[11]||'')}"></label><label>Company number — non-Pakistan companies<input id="${masterInputId(12)}" data-master-field-index="12" value="${escapeHtml(values[12]||'')}"></label></div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Bank accounts</h3><p>Accounts belong to this company. Existing module bank lists are derived automatically.</p></div><button type="button" class="button secondary" id="addCompanyBank">+ Add bank account</button></div><input type="hidden" id="${masterInputId(13)}" data-master-field-index="13"><div id="companyBankRows">${banks.map(bank=>companyBankRow(bank)).join("")}</div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Document identity</h3><p>Headers, footers, signatures and stamps remain versioned under the legal company.</p></div><button type="button" class="button secondary" id="addCompanyDocument">+ Add document identity</button></div><input type="hidden" id="${masterInputId(14)}" data-master-field-index="14"><div id="companyDocumentRows">${documents.map(doc=>companyDocumentRow(doc)).join("")}</div></section>
    <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Special workflow & behaviour</h3><p>Keep exceptional entity handling separate from ordinary identity.</p></div></div><div class="master-form-grid"><label>TG special handling<select id="${masterInputId(5)}" data-master-field-index="5">${["No","Yes"].map(x=>`<option ${x===String(values[5]||"No")?"selected":""}>${x}</option>`).join("")}</select></label><label class="full-span">System behaviour / notes<textarea id="${masterInputId(6)}" data-master-field-index="6" rows="4">${escapeHtml(values[6] || "")}</textarea></label></div></section>`;
  }
  function companyBankRow(bank={}) { return `<div class="master-form-grid company-bank-row"><input type="hidden" data-bank-json value="${escapeHtml(JSON.stringify(bank))}"><input type="hidden" data-bank-id value="${escapeHtml(bank.id||"")}"><label>Bank name<input data-bank-name value="${escapeHtml(bank.bankName||"")}" required></label><label>Account title<input data-bank-title value="${escapeHtml(bank.accountTitle||"")}" required></label><label>Currency<input data-bank-currency value="${escapeHtml(bank.currency||"PKR")}"></label><label>Account number<input data-bank-number value="${escapeHtml(bank.accountNumber||"")}"></label><label>IBAN<input data-bank-iban value="${escapeHtml(bank.iban||"")}"></label><label>SWIFT / BIC<input data-bank-swift value="${escapeHtml(bank.swift||"")}"></label><label>Branch<input data-bank-branch value="${escapeHtml(bank.branch||"")}"></label><label>Purpose<input data-bank-purpose value="${escapeHtml(bank.purpose||"")}"></label><label><input type="checkbox" data-bank-retention ${bank.retentionAccount?"checked":""}> Foreign retention account (TTI/BRM, USD or other foreign currency)</label><button type="button" class="button danger" data-remove-company-bank>Remove</button></div>`; }
  function companyDocumentRow(doc={}) { const controls=doc.id?`<div class="row-actions">${canMaster('companies','View Documents')?'<button type="button" class="row-action" data-preview-company-document>Preview</button>':''}${canMaster('companies','Download Documents')?'<button type="button" class="row-action" data-download-company-document>Download</button>':''}<span class="tag">Version ${escapeHtml(doc.version||1)}</span></div>`:`<label>PNG, JPG, WebP or PDF<input type="file" data-document-file accept="image/png,image/jpeg,image/webp,application/pdf" required></label>`;return `<div class="master-form-grid company-document-row"><input type="hidden" data-document-json value="${escapeHtml(JSON.stringify(doc))}"><input type="hidden" data-document-id value="${escapeHtml(doc.id||"")}"><label>Document type<select data-document-type>${["Header","Footer","Signature","Stamp","Letterhead","Other"].map(x=>`<option ${x===String(doc.type||"")?"selected":""}>${x}</option>`).join("")}</select></label><label>Label<input data-document-label value="${escapeHtml(doc.label||"")}" required></label><label>Version<input data-document-version value="${escapeHtml(doc.version||"1")}" readonly></label><label>Status<select data-document-status><option ${String(doc.status||"Active")==="Active"?"selected":""}>Active</option><option ${String(doc.status||"")==="Inactive"?"selected":""}>Inactive</option></select></label><label><input type="checkbox" data-document-default ${doc.isDefault?"checked":""}> Default for this type</label>${controls}<button type="button" class="button danger" data-remove-company-document>Deactivate</button></div>`; }
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
  const BROKERY_BASIS_LABELS = {
    PER_100_KG:"Per 100 kgs",PER_50_KG_BAG:"Per 50 kgs bag",PER_BAG:"Per bag",PER_MAUND:"Per maund",PER_TON:"Per ton"
  };
  function brokeryProfile(values = []) {
    try {
      const parsed=JSON.parse(String(values[12]||"{}"));
      return {buying:Array.isArray(parsed.buying)?parsed.buying:[],selling:Array.isArray(parsed.selling)?parsed.selling:[]};
    } catch (_) { return {buying:[],selling:[]}; }
  }
  function brokeryRateRow(rate = {}, kind = "buying") {
    const basis=String(rate.basis||"PER_100_KG");
    return `<div class="brokery-rate-row" data-brokery-rate data-brokery-kind="${kind}"><input type="hidden" data-brokery-id value="${escapeHtml(rate.id||"")}"><label>Figure<input data-brokery-amount type="number" min="0" step="0.01" value="${escapeHtml(rate.amount??"")}" placeholder="0.00"></label><label>Calculation basis<select data-brokery-basis>${Object.entries(BROKERY_BASIS_LABELS).map(([value,label])=>`<option value="${value}" ${value===basis?"selected":""}>${label}</option>`).join("")}</select></label><label>Effective from<input data-brokery-from type="date" value="${escapeHtml(rate.effectiveFrom||"")}"></label><label>Status<select data-brokery-status><option ${String(rate.status||"Active")==="Active"?"selected":""}>Active</option><option ${String(rate.status||"")==="Inactive"?"selected":""}>Inactive</option></select></label><button type="button" class="row-action delete" data-remove-brokery-rate>Delete</button></div>`;
  }
  function businessPartyMasterFieldsHtml(values = []) {
    const categories=new Set(String(values[2]||"").split(";").map(x=>x.trim()).filter(Boolean));
    const profile=brokeryProfile(values),isBroker=categories.has("Broker");
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Party identity</h3><p>Store each business party once and select every role it performs.</p></div></div><div class="master-identity-grid"><label>Party name<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0]||"")}" required></label><label>Code / reference<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1]||"")}"></label><label>Country<input id="${masterInputId(4)}" data-master-field-index="4" value="${escapeHtml(values[4]||"")}"></label><label>Status<select id="${masterInputId(10)}" data-master-field-index="10"><option ${String(values[10]||"Active")==="Active"?"selected":""}>Active</option><option ${String(values[10]||"")==="Inactive"?"selected":""}>Inactive</option></select></label></div><div class="check-field full-span"><span>Categories</span><div class="master-checks">${MASTER_TYPES.find(x=>x.id==="business_parties").fields[2].options.map(option=>`<label><input type="checkbox" data-master-field-index="2" value="${escapeHtml(option)}" ${categories.has(option)?"checked":""}>${escapeHtml(option)}</label>`).join("")}</div></div></section>
    <section class="master-editor-section"><div class="master-form-grid"><label class="full-span">Address<textarea id="${masterInputId(3)}" data-master-field-index="3" rows="3">${escapeHtml(values[3]||"")}</textarea></label><label>Contact person<input id="${masterInputId(5)}" data-master-field-index="5" value="${escapeHtml(values[5]||"")}"></label><label>Phone<input id="${masterInputId(6)}" data-master-field-index="6" value="${escapeHtml(values[6]||"")}"></label><label>Email<input id="${masterInputId(7)}" data-master-field-index="7" value="${escapeHtml(values[7]||"")}"></label><label>NTN / tax number<input id="${masterInputId(8)}" data-master-field-index="8" value="${escapeHtml(values[8]||"")}"></label><label>Payment terms<input id="${masterInputId(9)}" data-master-field-index="9" value="${escapeHtml(values[9]||"")}"></label><label class="full-span">Notes<textarea id="${masterInputId(11)}" data-master-field-index="11" rows="3">${escapeHtml(values[11]||"")}</textarea></label></div></section>
    <section class="master-editor-section brokery-profile-section" id="brokeryProfileSection" ${isBroker?"":"hidden"}><div class="master-editor-heading"><div><h3>Brokery</h3><p>Brokery belongs to this broker. The Soda date selects the active Buying Brokery; sale workflows use Selling Brokery.</p></div></div><div class="tt-modebar brokery-tabs"><button type="button" class="active" data-brokery-tab="buying">Buying Brokery</button><button type="button" data-brokery-tab="selling">Selling Brokery</button></div><div data-brokery-panel="buying"><div id="buyingBrokeryRows">${profile.buying.map(rate=>brokeryRateRow(rate,"buying")).join("")}</div><button type="button" class="button secondary" data-add-brokery-rate="buying">+ Add Buying Brokery</button></div><div data-brokery-panel="selling" hidden><div id="sellingBrokeryRows">${profile.selling.map(rate=>brokeryRateRow(rate,"selling")).join("")}</div><button type="button" class="button secondary" data-add-brokery-rate="selling">+ Add Selling Brokery</button></div><p class="master-inline-note">Calculation basis options: Per 100 kgs, Per 50 kgs bag, Per bag, Per maund and Per ton.</p></section>`;
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
    const types = ["","Own Mill","Reprocessing Mill","External Mill","Office","Warehouse","Stock Location","Other"];
    const statuses=["Active","Inactive"];
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Location identity</h3><p>This is the central location list used by Soda, Milling and stock movement. Use one record per real place.</p></div></div><div class="master-identity-grid"><label>Mill / location<input id="${masterInputId(0)}" data-master-field-index="0" value="${escapeHtml(values[0] || "")}" required></label><label>Code / reference<input id="${masterInputId(1)}" data-master-field-index="1" value="${escapeHtml(values[1] || "")}"></label><label>Location type<select id="${masterInputId(2)}" data-master-field-index="2">${types.map(x=>`<option value="${escapeHtml(x)}" ${x===String(values[2]||"")?"selected":""}>${escapeHtml(x||"Select")}</option>`).join("")}</select></label><label>Status<select id="${masterInputId(5)}" data-master-field-index="5">${statuses.map(x=>`<option ${x===String(values[5]||"Active")?"selected":""}>${x}</option>`).join("")}</select></label></div></section><section class="master-editor-section"><div class="master-form-grid"><label class="full-span">Address<textarea id="${masterInputId(3)}" data-master-field-index="3" rows="3">${escapeHtml(values[3] || "")}</textarea></label><label>Contact / phone<input id="${masterInputId(4)}" data-master-field-index="4" value="${escapeHtml(values[4] || "")}"></label><label class="full-span">Notes<textarea id="${masterInputId(6)}" data-master-field-index="6" rows="3">${escapeHtml(values[6] || "")}</textarea></label></div></section>`;
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
    [6, "Avg. grain length"], [8, "Moisture"], [9, "Damaged / Shriveled / Yellow"],
    [10, "Chalky / Immature"], [11, "Contrasting / Other varieties"], [12, "Foreign grains"],
    [13, "Foreign matter"], [14, "Paddy"], [15, "Red kernels / Red rice"],
    [16, "Under-milled / Red-striped"]
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
  const PRODUCT_OPTION_FIELDS = {
    0:["Commodity","product_commodities"],1:["Variety","product_varieties"],2:["Rice type","product_rice_types"],
    7:["Broken","product_broken"],17:["Finish","product_finishes"],4:["Origin","product_origins"],5:["Profile / use","product_profiles"]
  };
  function productOptionSelect(index,label,key,value,required=false) {
    const options=[...new Set([...(state.masterOptions?.[key]||[]),...(value?[value]:[])])].filter(Boolean).sort((a,b)=>a.localeCompare(b));
    const listId=`productOptionList${index}`;
    return `<label class="managed-option-field">${escapeHtml(label)}<span class="master-option-control"><input id="${masterInputId(index)}" data-master-field-index="${index}" data-product-option="${key}" data-product-option-label="${escapeHtml(label)}" data-product-existing-value="${escapeHtml(value||"")}" list="${listId}" value="${escapeHtml(value||"")}" placeholder="Type to search" autocomplete="off" ${required?"required":""}><button type="button" class="master-option-manage" data-manage-product-option aria-label="Manage ${escapeHtml(label)} options">Options</button></span><datalist id="${listId}">${options.map(x=>`<option value="${escapeHtml(x)}"></option>`).join("")}</datalist><span class="master-option-menu" data-product-option-menu hidden></span></label>`;
  }
  function wireProductOptionFields() {
    const refresh = input => {
      const options=[...new Set(state.masterOptions?.[input.dataset.productOption]||[])].filter(Boolean).sort((a,b)=>a.localeCompare(b));
      const list=document.getElementById(input.getAttribute("list"));
      if(list)list.innerHTML=options.map(value=>`<option value="${escapeHtml(value)}"></option>`).join("");
      const menu=input.closest(".managed-option-field")?.querySelector("[data-product-option-menu]");
      if(menu)menu.innerHTML=`<button type="button" class="managed-option-add" data-add-product-option>+ Add new option</button>${options.map(value=>`<span class="managed-option-row"><button type="button" data-choose-product-option="${escapeHtml(value)}">${escapeHtml(value)}</button><button type="button" class="managed-option-delete" data-delete-product-option="${escapeHtml(value)}" aria-label="Deactivate ${escapeHtml(value)}">Delete</button></span>`).join("")}`;
    };
    document.querySelectorAll("[data-product-option]").forEach(input=>{
      const field=input.closest(".managed-option-field"),menu=field?.querySelector("[data-product-option-menu]"),manage=field?.querySelector("[data-manage-product-option]");
      refresh(input);
      if(manage)manage.onclick=()=>{menu.hidden=!menu.hidden;if(!menu.hidden)menu.querySelector("button")?.focus()};
      if(menu)menu.onclick=async event=>{
        const choose=event.target.closest("[data-choose-product-option]");
        if(choose){input.value=choose.dataset.chooseProductOption||"";menu.hidden=true;return}
        const add=event.target.closest("[data-add-product-option]");
        const remove=event.target.closest("[data-delete-product-option]");
        if(add){
        const label=input.dataset.productOptionLabel||"option";
        const value=window.prompt(`Add ${label}`,input.value.trim());
        if(value===null||!value.trim())return;
        add.disabled=true;
        try{const data=await apiRequest({action:"manage-option",type:"products",optionAction:"add",optionKey:input.dataset.productOption,value:value.trim()},"masters");if(data.options)state.masterOptions=data.options;refresh(input);input.value=data.value||value.trim();menu.hidden=true;toast(`${label} option added.`)}catch(error){toast(error.message)}finally{add.disabled=false}
        return;
        }
        if(remove){
        const label=input.dataset.productOptionLabel||"option",old=remove.dataset.deleteProductOption||"";
        if(!window.confirm(`Deactivate “${old}” from the ${label} dropdown? Saved historical records will keep their original value.`))return;
        remove.disabled=true;
        try{const data=await apiRequest({action:"manage-option",type:"products",optionAction:"delete",optionKey:input.dataset.productOption,old,value:""},"masters");if(data.options)state.masterOptions=data.options;if(input.value===old)input.value="";refresh(input);toast(`${label} option deactivated. Historical records were not changed.`)}catch(error){toast(error.message)}finally{remove.disabled=false}
        }
      };
    });
  }
  async function resolveProductOptionsBeforeSave(type) {
    if (!["products","purchase_products","purchase_kat"].includes(type.id)) return;
    for (const input of document.querySelectorAll("[data-product-option]")) {
      const value=input.value.trim();
      if(!value)continue;
      const active=(state.masterOptions?.[input.dataset.productOption]||[]).some(option=>String(option).toLowerCase()===value.toLowerCase());
      const historical=String(input.dataset.productExistingValue||"").toLowerCase()===value.toLowerCase();
      if(!active&&!historical)throw new Error(`Open Options and add “${value}” to ${input.dataset.productOptionLabel||"the dropdown"} before saving.`);
    }
  }
  function productMasterFieldsHtml(values = []) {
    const legacyType=String(values[2]||"").replace(/\s+\d+(?:\.\d+)?%\s*(?:MAX\s*)?BROKEN\b/i,"").trim() || (/\d+(?:\.\d+)?%\s*(?:MAX\s*)?BROKEN/i.test(String(values[2]||"")) ? "White Rice" : String(values[2]||""));
    const cropYear=String(state.masters?.product_settings?.[0]?.values?.[0]||"2025/2026");
    const selected={...values,2:legacyType};
    const identity = [0,1,2,7,17,4,5].map(index=>productOptionSelect(index,PRODUCT_OPTION_FIELDS[index][0],PRODUCT_OPTION_FIELDS[index][1],selected[index],[0,1,2,7,17].includes(index))).join("")+
      `<label>Code<input id="${masterInputId(3)}" data-master-field-index="3" value="${escapeHtml(values[3]||"")}" required autocomplete="off"></label><label>HS Code<input id="${masterInputId(21)}" data-master-field-index="21" value="${escapeHtml(values[21]||"")}" autocomplete="off"></label>`;
    const core = PRODUCT_CORE_SPECS.map(([index,name]) => productSpecRow(name, values[index] || "", false, index)).join("");
    const custom = productCustomSpecs(values).map(row => productSpecRow(row.name, row.limit, true)).join("");
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>Export product identity</h3><p>Type to search each approved list. Open Options inside a field to add a genuine choice or deactivate an incorrect one; historical records remain unchanged.</p></div></div><div class="master-identity-grid">${identity}<label>Current Crop Year<input value="${escapeHtml(cropYear)}" readonly title="Change this once from Export Quality & Specs"></label></div></section>
      <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Specifications & limits</h3><p>One specification per line. Leave a limit blank when it is not confirmed.</p></div></div><div class="spec-editor-wrap"><table class="spec-editor-table"><thead><tr><th>Specification</th><th>Limit / Requirement</th><th></th></tr></thead><tbody id="productSpecRows">${core}${custom}</tbody></table></div><button class="button secondary add-spec-button" id="addProductSpecification" type="button">+ Add Specification</button></section>
      <section class="master-editor-section"><div class="master-editor-heading"><div><h3>Wording & source</h3><p>Quality wording and reference source stay separate from the numeric specification table.</p></div></div><div class="master-form-grid"><label class="full-span">Additional quality wording<textarea id="${masterInputId(18)}" data-master-field-index="18" rows="3">${escapeHtml(values[18] || "")}</textarea></label><label class="full-span">Source / basis<textarea id="${masterInputId(19)}" data-master-field-index="19" rows="3">${escapeHtml(values[19] || "")}</textarea></label></div></section>`;
  }
  function katParameters(values = []) {
    try {
      const parsed=JSON.parse(String(values[9]||"[]"));
      return Array.isArray(parsed)?parsed.filter(row=>row&&typeof row==="object"):[];
    } catch (_) { return []; }
  }
  function katRangeRow(row = {}) {
    const units = ["paisa per %","rupees per %","weight %","kg per MT","manual / note only"];
    const unit = String(row.unit || "paisa per %");
    return `<tr class="kat-range-row"><td><input data-kat-from inputmode="decimal" value="${escapeHtml(row.from || "")}" placeholder="e.g. 20"></td><td><input data-kat-to inputmode="decimal" value="${escapeHtml(row.to || "")}" placeholder="blank = and above"></td><td><input data-kat-value inputmode="decimal" value="${escapeHtml(row.value || "")}" placeholder="e.g. 1"></td><td><select data-kat-unit>${units.map(x => `<option ${x===unit?"selected":""}>${x}</option>`).join("")}</select></td><td><button class="row-action delete" type="button" data-remove-kat-range>Remove</button></td></tr>`;
  }
  function katParameterCard(parameter = {}, index = 0) {
    const ranges=Array.isArray(parameter.ranges)?parameter.ranges:[];
    return `<article class="kat-parameter-card" data-kat-parameter><div class="kat-parameter-head"><label>Quality parameter<input data-kat-parameter-name value="${escapeHtml(parameter.name||"")}" placeholder="e.g. Broken" required></label><button class="row-action delete" type="button" data-remove-kat-parameter>− Remove Parameter</button></div><div class="master-form-grid"><label>Free / default allowance<input data-kat-free value="${escapeHtml(parameter.freeAllowance||"")}" placeholder="e.g. 20% free"></label><label>Default deduction unit<select data-kat-default-unit>${["paisa per %","rupees per %","weight %","kg per MT","manual / note only"].map(x=>`<option ${x===String(parameter.unit||"paisa per %")?"selected":""}>${x}</option>`).join("")}</select></label><label class="full-span">Staff instruction<input data-kat-instruction value="${escapeHtml(parameter.instruction||"")}" placeholder="Plain-language instruction shown during purchase checking"></label></div><div class="kat-range-wrap"><table class="kat-range-table"><thead><tr><th>Above</th><th>Up to</th><th>Deduction</th><th>Unit</th><th></th></tr></thead><tbody data-kat-range-list>${ranges.map(katRangeRow).join("")}</tbody></table></div><button class="button secondary add-spec-button" type="button" data-add-kat-range>+ Add Range</button></article>`;
  }
  function katMasterFieldsHtml(values = []) {
    if(!values.length&&pendingKatProductId){const product=(state.masters?.purchase_products||[]).find(row=>row.id===pendingKatProductId);if(product){const p=product.values||[];values=[p[0]||"",p[1]||"",p[2]||"",p[3]||"RAW",`${p[1]||p[0]} ${p[2]||""} ${String(p[3]||"RAW").toLowerCase()} KAT`.replace(/\s+/g," ").trim(),"","","Draft – review required","","[]"]}}
    const statusOptions = ["Active", "Draft – review required", "Inactive"];
    const parameters=katParameters(values);
    return `<section class="master-editor-section"><div class="master-editor-heading"><div><h3>KAT profile identity</h3><p>One complete profile for one exact commodity, variety, rice type and purchase classification.</p></div></div><div class="master-identity-grid">
      <label>Commodity<select id="${masterInputId(0)}" data-master-field-index="0" required>${["Rice","Corn","Sesame"].map(option=>`<option ${option.toLowerCase()===String(values[0]||"Rice").toLowerCase()?"selected":""}>${option}</option>`).join("")}</select></label>
      ${productOptionSelect(1,"Base variety / product","product_varieties",values[1]||"",true)}
      ${productOptionSelect(2,"Rice type","product_rice_types",values[2]||"",String(values[0]||"Rice").toLowerCase()==="rice")}
      <label>Purchase classification<select id="${masterInputId(3)}" data-master-field-index="3" required>${["RAW","READY"].map(option=>`<option ${option===String(values[3]||"RAW").toUpperCase()?"selected":""}>${option}</option>`).join("")}</select></label>
      <label>Profile name<input id="${masterInputId(4)}" data-master-field-index="4" value="${escapeHtml(values[4]||"")}" placeholder="IRRI-6 White Raw KAT" required></label>
      <label>Effective from<input type="date" id="${masterInputId(5)}" data-master-field-index="5" value="${escapeHtml(values[5]||"")}"></label>
      <label>Effective to<input type="date" id="${masterInputId(6)}" data-master-field-index="6" value="${escapeHtml(values[6]||"")}"></label>
      <label>Rule status<select id="${masterInputId(7)}" data-master-field-index="7">${statusOptions.map(option => `<option ${option === String(values[7] || "") ? "selected" : ""}>${option}</option>`).join("")}</select></label>
    </div></section>
    <section class="master-editor-section kat-calc-section"><div class="master-editor-heading"><div><h3>Quality parameters and ranges</h3><p>Add or remove parameters here. Each parameter keeps its own allowance, instruction and non-overlapping deduction ranges.</p></div><button class="button secondary" id="addKatParameter" type="button">+ Add Quality Parameter</button></div><div id="katParameterCards">${parameters.map(katParameterCard).join("")}</div></section>
    <section class="master-editor-section kat-message-section"><label class="full-span">Profile notes<textarea id="${masterInputId(8)}" data-master-field-index="8" rows="4">${escapeHtml(values[8] || "")}</textarea></label></section>`;
  }

  function purchaseProductMasterFieldsHtml(values = []) {
    const defaults={RICE_RAW:["RICE","","White","RAW"],RICE_READY:["RICE","","White","READY"],CORN_RAW:["CORN","Corn / Makai","","RAW"],CORN_READY:["CORN","Corn / Makai","","READY"],SESAME_RAW:["SESAME","Sesame","","RAW"],SESAME_READY:["SESAME","Sesame","","READY"]};
    const v=[...values];
    const selected=defaults[currentPurchaseTab]||defaults.RICE_RAW;
    if(!v.length){v[0]=selected[0];v[1]=selected[1];v[2]=selected[2];v[3]=selected[3];v[4]=selected[0]==="RICE"?"KG":"MAUND";v[8]="Active"}
    const options=(items,value)=>items.map(option=>`<option value="${escapeHtml(option)}" ${option===String(value||"")?"selected":""}>${escapeHtml(option)}</option>`).join("");
    const katProfiles=(state.masters?.purchase_kat||[]).filter(row=>String(row.values?.[7]||"")!=="Inactive");
    const katOptions=[`<option value="">No KAT profile</option>`,...katProfiles.map(row=>`<option value="${escapeHtml(row.id)}" ${row.id===String(v[5]||"")?"selected":""}>${escapeHtml(row.values?.[4]||row.id)}</option>`)].join("");
    return `<section class="master-editor-section purchase-product-identity-section"><div class="master-editor-heading"><div><h3>Purchase product identity</h3><p>RAW is bought for processing. READY is fully finished product bought from outside. FINAL is created only when TTI-owned RAW is processed at the own or reprocessing mill; paddy is not part of this workflow.</p></div></div><div class="master-identity-grid">
      <label>Commodity<select id="${masterInputId(0)}" data-master-field-index="0" required>${options(["RICE","CORN","SESAME"],v[0])}</select></label>
      ${productOptionSelect(1,"Shared base variety / product","product_varieties",v[1]||"",true)}
      ${productOptionSelect(2,"Rice type","product_rice_types",v[2]||"",String(v[0]||"").toUpperCase()==="RICE")}
      <label>Purchased as<select id="${masterInputId(3)}" data-master-field-index="3" required>${options(["RAW","READY"],v[3])}</select></label>
      <label>Purchase unit<select id="${masterInputId(4)}" data-master-field-index="4" required>${options(["KG","MAUND","MT"],v[4])}</select></label>
      <label>KAT profile<select id="${masterInputId(5)}" data-master-field-index="5">${katOptions}</select></label>
      <label>Status<select id="${masterInputId(8)}" data-master-field-index="8">${options(["Active","Draft – review required","Inactive"],v[8]||"Active")}</select></label>
    </div></section>
    <section class="master-editor-section purchase-product-use-section"><div class="master-editor-heading"><div><h3>How this is used</h3><p>Every variety, type and stage keeps its own stock identity and KAT. Supplier, broker, Brokery and movement location come from the Soda and broker profile. Accounts mapping is automatic and is not entered here.</p></div></div><div class="master-form-grid"><label class="full-span">Notes<textarea id="${masterInputId(9)}" data-master-field-index="9" rows="3">${escapeHtml(v[9]||"")}</textarea></label></div></section>`;
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
    if (type.id === "business_parties") return businessPartyMasterFieldsHtml(values);
    if (type.id === "commodities") return commodityMasterFieldsHtml(values);
    if (type.id === "mills") return millMasterFieldsHtml(values);
    if (type.id === "banks") return bankMasterFieldsHtml(values);
    if (type.id === "products") return productMasterFieldsHtml(values);
    if (type.id === "purchase_products") return purchaseProductMasterFieldsHtml(values);
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
    if (type.id === "companies") {
      const values=Array(15).fill("");
      [0,1,2,3,5,6,8,9,10,11,12].forEach(index=>{values[index]=document.getElementById(masterInputId(index))?.value.trim()||""});
      values[4]=[...document.querySelectorAll('[data-master-field-index="4"]:checked')].map(input=>input.value).join("; ");
      const owners=[...document.querySelectorAll('.company-owner-row')].map(row=>({name:row.querySelector('[data-company-owner-name]')?.value.trim()||"",share:Number(row.querySelector('[data-company-owner-share]')?.value||0)})).filter(owner=>owner.name||owner.share);
      values[7]=JSON.stringify(owners.length?owners:[{name:"",share:100}]);
      values[13]=JSON.stringify([...document.querySelectorAll('.company-bank-row')].map((row,index)=>({
        ...JSON.parse(row.querySelector('[data-bank-json]')?.value||'{}'),id:row.querySelector('[data-bank-id]')?.value||`bank-${Date.now()}-${index}`,
        accountType:'Company Account',bankName:row.querySelector('[data-bank-name]')?.value.trim()||'',accountTitle:row.querySelector('[data-bank-title]')?.value.trim()||'',currency:row.querySelector('[data-bank-currency]')?.value.trim()||'PKR',accountNumber:row.querySelector('[data-bank-number]')?.value.trim()||'',iban:row.querySelector('[data-bank-iban]')?.value.trim()||'',swift:row.querySelector('[data-bank-swift]')?.value.trim()||'',branch:row.querySelector('[data-bank-branch]')?.value.trim()||'',purpose:row.querySelector('[data-bank-purpose]')?.value.trim()||'',retentionAccount:(Object.prototype.hasOwnProperty.call(JSON.parse(row.querySelector('[data-bank-json]')?.value||'{}'),'retentionAccount')||!row.querySelector('[data-bank-id]')?.value||row.querySelector('[data-bank-retention]')?.checked)?!!row.querySelector('[data-bank-retention]')?.checked:undefined,status:'Active'
      })).filter(bank=>bank.bankName||bank.accountTitle||bank.accountNumber||bank.iban));
      values[14]=JSON.stringify([...document.querySelectorAll('.company-document-row')].map(row=>({
        ...JSON.parse(row.querySelector('[data-document-json]')?.value||'{}'),id:row.querySelector('[data-document-id]')?.value||'',type:row.querySelector('[data-document-type]')?.value||'Other',label:row.querySelector('[data-document-label]')?.value.trim()||'',version:row.querySelector('[data-document-version]')?.value.trim()||'1',status:row.querySelector('[data-document-status]')?.value||'Active',isDefault:!!row.querySelector('[data-document-default]')?.checked
      })).filter(doc=>doc.id&&doc.label));
      return values;
    }
    if (type.id === "products") {
      const values = Array(22).fill("");
      for (let index = 0; index < 20; index += 1) values[index] = document.querySelector(`[data-master-field-index="${index}"]`)?.value.trim() || "";
      const custom = [...document.querySelectorAll("#productSpecRows .custom-spec-row")].map(row => ({
        name: row.querySelector("[data-custom-spec-name]")?.value.trim() || "",
        limit: row.querySelector("[data-custom-spec-limit]")?.value.trim() || ""
      })).filter(row => row.name || row.limit);
      values[20] = JSON.stringify(custom);
      values[21] = document.querySelector('[data-master-field-index="21"]')?.value.trim() || "";
      return values;
    }
    if (type.id === "business_parties") {
      const values=Array(13).fill("");
      [0,1,3,4,5,6,7,8,9,10,11].forEach(index=>{values[index]=document.getElementById(masterInputId(index))?.value.trim()||""});
      values[2]=[...document.querySelectorAll('[data-master-field-index="2"]:checked')].map(input=>input.value).join("; ");
      const collect=kind=>[...document.querySelectorAll(`[data-brokery-rate][data-brokery-kind="${kind}"]`)].map((row,index)=>({
        id:row.querySelector('[data-brokery-id]')?.value||`${kind}-${Date.now()}-${index}`,
        amount:Number(row.querySelector('[data-brokery-amount]')?.value||0),basis:row.querySelector('[data-brokery-basis]')?.value||"PER_100_KG",
        effectiveFrom:row.querySelector('[data-brokery-from]')?.value||"",status:row.querySelector('[data-brokery-status]')?.value||"Active"
      })).filter(rate=>rate.amount>0||rate.effectiveFrom);
      values[12]=JSON.stringify({buying:collect("buying"),selling:collect("selling")});
      return values;
    }
    if (["commodities","mills"].includes(type.id)) {
      return type.fields.map((field, index) => {
        if (field.type === "checks" || (type.id === "companies" && index === 4)) {
          return [...document.querySelectorAll(`[data-master-field-index="${index}"]:checked`)].map(input => input.value).join("; ");
        }
        return document.getElementById(masterInputId(index))?.value.trim() || "";
      });
    }
    if (type.id === "purchase_kat") {
      const values = Array(10).fill("");
      [0,1,2,3,4,5,6,7,8].forEach(index => { values[index] = document.getElementById(masterInputId(index))?.value.trim() || ""; });
      values[9] = JSON.stringify([...document.querySelectorAll("[data-kat-parameter]")].map(card=>{
        const defaultUnit=card.querySelector("[data-kat-default-unit]")?.value.trim()||"paisa per %";
        return {
          name:card.querySelector("[data-kat-parameter-name]")?.value.trim()||"",
          freeAllowance:card.querySelector("[data-kat-free]")?.value.trim()||"",
          unit:defaultUnit,
          instruction:card.querySelector("[data-kat-instruction]")?.value.trim()||"",
          ranges:[...card.querySelectorAll(".kat-range-row")].map(row=>({
            from:row.querySelector("[data-kat-from]")?.value.trim()||"",
            to:row.querySelector("[data-kat-to]")?.value.trim()||"",
            value:row.querySelector("[data-kat-value]")?.value.trim()||"",
            unit:row.querySelector("[data-kat-unit]")?.value.trim()||defaultUnit
          })).filter(row=>row.from||row.to||row.value)
        };
      }).filter(parameter=>parameter.name||parameter.ranges.length));
      return values;
    }
    if (type.id === "purchase_products") {
      const values=Array(10).fill("");
      [0,1,2,3,4,5,8,9].forEach(index=>{values[index]=document.getElementById(masterInputId(index))?.value.trim()||""});
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
    if (type.id === "purchase_products") return [0, 1, 2, 3, 4, 5, 8];
    if (type.id === "purchase_kat") return [0, 1, 2, 3, 4, 7];
    if (type.id === "export_customers") return [0, 1, 4, 10];
    if (type.id === "business_parties") return [0, 1, 2, 4, 10];
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
    const statusFields={export_customers:10,business_parties:10,purchase_products:8,purchase_kat:7,mills:5,export_documents:4,export_terms:2};
    if (Object.hasOwn(statusFields,type.id)) return String(row.values?.[statusFields[type.id]] || (type.id.startsWith("purchase_") ? "Draft – review required" : "Active"));
    return "Active";
  }
  function currentProductCropYear() {
    return String(state.masters?.product_settings?.[0]?.values?.[0] || "2025/2026");
  }
  async function saveCurrentProductCropYear() {
    const input=document.getElementById("currentProductCropYear");
    const value=String(input?.value||"").trim();
    const match=value.match(/^(\d{4})\/(\d{4})$/);
    if (!match || Number(match[2])!==Number(match[1])+1) return toast("Enter consecutive Crop Years as YYYY/YYYY, for example 2025/2026.");
    const existing=state.masters?.product_settings?.[0];
    try {
      const data=await apiRequest({action:existing?"update":"create",type:"product_settings",id:existing?.id||"",values:[value]},"masters");
      state.masters=ensureMasterSections(data.masters,data.options||state.masterOptions);
      saveState();
      renderMasters();
      toast("Current Crop Year updated for new Sales Contracts.");
    } catch (error) { toast(error.message); }
  }

  function renderMasters() {
    state.masters = ensureMasterSections(state.masters,state.masterOptions);
    if(currentMaster==="purchase_products"&&currentPurchaseSection!=="KAT")currentPurchaseSection="PRODUCTS";
    const allowedTypes=MASTER_TYPES.filter(type=>canMaster(type.id,"View"));
    if (!allowedTypes.some(type=>type.id===currentMaster)) currentMaster=allowedTypes[0]?.id||"companies";
    if(currentMaster==="purchase_products"&&!canMaster(activeMasterType()?.id||"purchase_products","View"))currentPurchaseSection="PRODUCTS";
    document.getElementById("masterMenu").innerHTML = MASTER_GROUPS.map(([group,ids])=>{const types=ids.map(id=>allowedTypes.find(type=>type.id===id)).filter(Boolean);return types.length?`<div class="master-menu-group"><small>${group}</small>${types.map(type=>{const count=state.masters[type.id]?.length||0;return `<button class="${type.id === currentMaster ? "active" : ""}" data-master="${type.id}">${type.name}<span>${count}</span></button>`}).join("")}</div>`:""}).join("");
    const menuType = masterType();
    const type = activeMasterType();
    document.getElementById("masterTitle").textContent = menuType.name;
    document.getElementById("masterDescription").textContent = menuType.description;
    document.getElementById("productCropYearControl")?.remove();
    document.getElementById("purchaseWorkspaceTabs")?.remove();
    document.getElementById("purchaseProductTabs")?.remove();
    if (type.id === "products") {
      document.getElementById("masterDescription").insertAdjacentHTML("afterend", `<section id="productCropYearControl" class="master-editor-section"><div class="master-editor-heading"><div><h3>Current Crop Year</h3><p>Change this once a year. New Sales Contracts use it automatically; saved contracts keep their original crop year.</p></div></div><div class="master-identity-grid"><label>Crop Year<input id="currentProductCropYear" value="${escapeHtml(currentProductCropYear())}" placeholder="2025/2026"></label><div><button class="button primary" id="saveCurrentProductCropYear" type="button">Save Crop Year</button></div></div></section>`);
      document.getElementById("saveCurrentProductCropYear").onclick=saveCurrentProductCropYear;
    }
    if (type.id === "purchase_products") {
      const [commodity,stage]=currentPurchaseTab.split("_");
      document.getElementById("masterDescription").insertAdjacentHTML("afterend",`<div id="purchaseWorkspaceTabs" class="purchase-hierarchy"><div><small>PRODUCT</small><div class="tt-modebar">${[["RICE","Rice"],["CORN","Corn / Makai"],["SESAME","Sesame"]].map(([id,label])=>`<button type="button" data-purchase-commodity="${id}" class="${id===commodity?'active':''}">${label}</button>`).join("")}</div></div><div><small>PURCHASED AS</small><div class="tt-modebar">${[["RAW","Raw"],["READY","Ready"]].map(([id,label])=>`<button type="button" data-purchase-stage="${id}" class="${id===stage?'active':''}">${label}</button>`).join("")}</div></div><p>${commodity==="RICE"?(stage==="RAW"?"Rice bought from outside for processing. Each variety and type has its own KAT and stock.":"Finished/exportable rice bought from an ex-mill or outside source."):"Every product and stage keeps its own Soda, KAT and stock identity."}</p></div>`);
      document.querySelectorAll('[data-purchase-commodity]').forEach(button=>button.onclick=()=>{currentPurchaseTab=`${button.dataset.purchaseCommodity}_${stage}`;renderMasters()});
      document.querySelectorAll('[data-purchase-stage]').forEach(button=>button.onclick=()=>{currentPurchaseTab=`${commodity}_${button.dataset.purchaseStage}`;renderMasters()});
    }
    document.getElementById("addMasterRecord").hidden=!canMaster(type.id,"Create");
    document.getElementById("addMasterRecord").textContent = `+ Add ${type.id === "salary_staff" ? "Staff" : type.id === "purchase_kat" ? "KAT Profile" : type.id === "purchase_products" ? "Purchase Product" : type.id === "companies" ? "Company" : type.id === "commodities" ? "Commodity" : type.id === "products" ? "Export Product" : "Record"}`;
    const columns = displayColumns(type);
    if(type.id==="purchase_products"){
      document.getElementById("masterTableHead").innerHTML="<tr><th>Variety / Product</th><th>Type</th><th>Stage</th><th>Purchase Unit</th><th>KAT</th><th>Status</th><th>Actions</th></tr>";
      const query=document.getElementById("masterSearch")?.value.toLowerCase()||"",[commodity,stage]=currentPurchaseTab.split("_");
      const rows=(state.masters.purchase_products||[]).filter(row=>String(row.values?.[0]||"").toUpperCase()===commodity&&String(row.values?.[3]||"").toUpperCase()===stage&&row.values.join(" ").toLowerCase().includes(query)).sort((a,b)=>String(a.values?.[1]||"").localeCompare(String(b.values?.[1]||""))||String(a.values?.[2]||"").localeCompare(String(b.values?.[2]||"")));
      document.getElementById("masterTableBody").innerHTML=rows.length?rows.map(row=>{const kat=(state.masters.purchase_kat||[]).find(x=>x.id===String(row.values?.[5]||"")),inactive=masterRowStatus(type,row)==="Inactive";return `<tr><td><b>${escapeHtml(row.values?.[1]||"—")}</b></td><td>${escapeHtml(row.values?.[2]||"—")}</td><td>${escapeHtml(row.values?.[3]||"—")}</td><td>${escapeHtml(row.values?.[4]||"—")}</td><td><span class="tag">${escapeHtml(kat?.values?.[4]||(kat?"KAT saved":"Not set"))}</span></td><td><span class="tag ${inactive?'inactive':''}">${inactive?'Deactivated':'Active'}</span></td><td><div class="row-actions">${canMaster(type.id,"Edit")?`<button class="row-action" data-edit-master="${escapeHtml(row.id)}">Edit Product</button>`:""}${canMaster("purchase_kat",kat?"Edit":"Create")?`<button class="row-action" data-edit-product-kat="${escapeHtml(row.id)}">${kat?"KAT Rules":"Add KAT"}</button>`:""}${canMaster(type.id,"Deactivate")?(inactive?`<button class="row-action deactivated" type="button" disabled>Deactivated</button>`:`<button class="row-action delete" data-delete-master="${escapeHtml(row.id)}">Deactivate</button>`):""}${IS_SUPER_ADMIN?`<button class="row-action purge" data-purge-master="${escapeHtml(row.id)}">Delete Permanently</button>`:""}</div></td></tr>`}).join(""):'<tr><td colspan="7">No matching purchase products. Use Add Purchase Product for this product and stage.</td></tr>';
      return;
    }
    document.getElementById("masterTableHead").innerHTML = `<tr>${columns.map(index => `<th>${escapeHtml(type.fields[index].label)}</th>`).join("")}<th>Status</th><th>Actions</th></tr>`;
    const query = document.getElementById("masterSearch")?.value.toLowerCase() || "";
    const rows = (state.masters[type.id] || []).filter(row => {
      if(!row.values.join(" ").toLowerCase().includes(query))return false;
      return true;
    });
    const permanentlyDeletable=new Set(["export_customers","business_parties","purchase_products","purchase_kat","mills","export_documents","export_terms"]);
    document.getElementById("masterTableBody").innerHTML = rows.length ? rows.map(row => {const inactive=masterRowStatus(type,row)==="Inactive";return `<tr>${columns.map(index => `<td>${escapeHtml(row.values[index] || "—")}</td>`).join("")}<td><span class="tag ${inactive?'inactive':''}">${escapeHtml(inactive?'Deactivated':masterRowStatus(type, row))}</span></td><td><div class="row-actions">${canMaster(type.id,"Edit")?`<button class="row-action" data-edit-master="${escapeHtml(row.id)}">Edit</button>`:""}${type.id!=="commodities"&&canMaster(type.id,"Deactivate")?(inactive?`<button class="row-action deactivated" type="button" disabled>Deactivated</button>`:`<button class="row-action delete" data-delete-master="${escapeHtml(row.id)}">Deactivate</button>`):""}${permanentlyDeletable.has(type.id)&&IS_SUPER_ADMIN?`<button class="row-action purge" data-purge-master="${escapeHtml(row.id)}">Delete Permanently</button>`:""}</div></td></tr>`}).join("") : `<tr><td colspan="${columns.length + 2}">No matching records.</td></tr>`;
  }
  function openKatForPurchaseProduct(productId) {
    const product=(state.masters?.purchase_products||[]).find(row=>row.id===productId);
    if(!product)return toast("Purchase product could not be found.");
    pendingKatProductId=productId;
    const existing=(state.masters?.purchase_kat||[]).find(row=>row.id===String(product.values?.[5]||""))||(state.masters?.purchase_kat||[]).find(row=>String(row.values?.[0]||"").toUpperCase()===String(product.values?.[0]||"").toUpperCase()&&String(row.values?.[1]||"").toLowerCase()===String(product.values?.[1]||"").toLowerCase()&&String(row.values?.[2]||"").toLowerCase()===String(product.values?.[2]||"").toLowerCase()&&String(row.values?.[3]||"").toUpperCase()===String(product.values?.[3]||"").toUpperCase());
    currentPurchaseSection="KAT";
    openMasterDialog(existing?.id||"");
  }
  function openMasterDialog(id = "") {
    const type = activeMasterType();
    const row = (state.masters[type.id] || []).find(item => item.id === id);
    document.getElementById("masterForm").reset();
    document.getElementById("editMasterId").value = row?.id || "";
    document.getElementById("masterDialogTitle").textContent = `${row ? "Edit" : "Add"} ${type.name}`;
    document.getElementById("masterDialogHelp").textContent = type.description + " Complete as much information as available; only the essential identity fields are mandatory.";
    document.getElementById("masterFormFields").innerHTML = masterFieldsHtml(type, row?.values || []);
    if (type.id === "companies") {
      const container=document.getElementById("companyOwnerRows");
      const wireOwners=()=>{container.querySelectorAll('[data-remove-company-owner]').forEach(button=>button.onclick=()=>{button.closest('.company-owner-row')?.remove();if(!container.children.length)addOwner();if(container.children.length===1)container.querySelector('[data-company-owner-share]').value="100";wireOwners()})};
      const addOwner=()=>{if(container.children.length===1){const share=container.querySelector('[data-company-owner-share]');if(share&&Number(share.value)===100)share.value=""}const row=document.createElement('div');row.className='master-identity-grid company-owner-row';row.innerHTML='<label>Owner / partner name<input data-company-owner-name></label><label>Share %<input data-company-owner-share type="number" min="0" max="100" step="0.01"></label><button type="button" class="button danger" data-remove-company-owner>Remove</button>';container.appendChild(row);wireOwners()};
      document.getElementById("addCompanyOwner").onclick=addOwner;wireOwners();
      const bankRows=document.getElementById('companyBankRows');
      const documentRows=document.getElementById('companyDocumentRows');
      const wireNested=()=>{
        bankRows?.querySelectorAll('[data-remove-company-bank]').forEach(button=>button.onclick=()=>button.closest('.company-bank-row')?.remove());
        documentRows?.querySelectorAll('[data-remove-company-document]').forEach(button=>button.onclick=()=>{const row=button.closest('.company-document-row');if(row){row.querySelector('[data-document-status]').value='Inactive';row.hidden=true}});
        documentRows?.querySelectorAll('[data-preview-company-document],[data-download-company-document]').forEach(button=>button.onclick=()=>{const documentId=button.closest('.company-document-row')?.querySelector('[data-document-id]')?.value;if(!documentId||!row?.id)return;const download=button.hasAttribute('data-download-company-document')?'&download=1':'';window.open(`api/master_documents.php?companyId=${encodeURIComponent(row.id)}&documentId=${encodeURIComponent(documentId)}${download}`,'_blank','noopener')});
      };
      document.getElementById('addCompanyBank').onclick=()=>{bankRows.insertAdjacentHTML('beforeend',companyBankRow({accountTitle:document.getElementById(masterInputId(0))?.value||'',currency:'PKR'}));wireNested()};
      document.getElementById('addCompanyDocument').onclick=()=>{documentRows.insertAdjacentHTML('beforeend',companyDocumentRow({version:'1',status:'Active'}));wireNested()};
      wireNested();
    }
    if (["products","purchase_products","purchase_kat"].includes(type.id)) wireProductOptionFields();
    if(type.id==="business_parties"){
      const syncBrokerSection=()=>{const checked=[...document.querySelectorAll('[data-master-field-index="2"]:checked')].some(input=>input.value==="Broker");const section=document.getElementById("brokeryProfileSection");if(section)section.hidden=!checked};
      document.querySelectorAll('[data-master-field-index="2"]').forEach(input=>input.addEventListener("change",syncBrokerSection));
      document.querySelectorAll('[data-brokery-tab]').forEach(button=>button.onclick=()=>{document.querySelectorAll('[data-brokery-tab]').forEach(x=>x.classList.toggle("active",x===button));document.querySelectorAll('[data-brokery-panel]').forEach(panel=>panel.hidden=panel.dataset.brokeryPanel!==button.dataset.brokeryTab)});
      document.querySelectorAll('[data-add-brokery-rate]').forEach(button=>button.onclick=()=>{const kind=button.dataset.addBrokeryRate;document.getElementById(kind==="buying"?"buyingBrokeryRows":"sellingBrokeryRows")?.insertAdjacentHTML("beforeend",brokeryRateRow({effectiveFrom:new Date().toISOString().slice(0,10)},kind))});
      document.getElementById("masterFormFields")?.addEventListener("click",event=>{const remove=event.target.closest('[data-remove-brokery-rate]');if(remove)remove.closest('[data-brokery-rate]')?.remove()});
      syncBrokerSection();
    }
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
    document.getElementById("deleteMasterButton").hidden = !row || type.id === "commodities" || (type.id === "salary_staff" && String(row.values?.[10] || "Active") === "Inactive");
    document.getElementById("deleteMasterButton").textContent = type.id === "salary_staff" ? "Remove Staff" : "Deactivate Record";
    document.getElementById("saveMasterButton").textContent = row ? "Save Changes" : "Save Record";
    document.getElementById("masterDialog").showModal();
  }
  async function saveMasterRecord(event) {
    event.preventDefault();
    const form = document.getElementById("masterForm");
    if (!form.reportValidity()) return;
    const type = activeMasterType();
    const id = document.getElementById("editMasterId").value;
    if(type.id==="companies"){const shares=[...document.querySelectorAll('[data-company-owner-share]')].map(input=>Number(input.value||0)),total=shares.reduce((sum,value)=>sum+value,0);if(shares.length===1&&shares[0]===0)document.querySelector('[data-company-owner-share]').value="100";else if(Math.abs(total-100)>.001){toast("Owner / partner shares must total 100%.");return}}
    if(type.id==="companies"&&[...document.querySelectorAll('.company-bank-row')].some(row=>row.querySelector('[data-bank-retention]')?.checked)){
      const code=String(document.getElementById(masterInputId(1))?.value||'').trim().toUpperCase();
      if(!['TTI','BRM'].includes(code)){toast('Foreign retention accounts must belong to TTI or BRM.');return}
      if([...document.querySelectorAll('.company-bank-row')].some(row=>row.querySelector('[data-bank-retention]')?.checked&&String(row.querySelector('[data-bank-currency]')?.value||'PKR').trim().toUpperCase()==='PKR')){toast('A retention account must use a foreign currency, such as USD.');return}
    }
    try { await resolvePartyRoleBeforeSave(type); await resolveProductOptionsBeforeSave(type); } catch (error) { toast(error.message); return; }
    const values = masterValuesFromForm(type);
    const primary = values[0] || type.name;
    const ref = values[1] || type.id.toUpperCase();
    try {
      if(type.id==="reference_lists"){
        const existing=id?(state.masters.reference_lists||[]).find(row=>row.id===id):null;
        const data=await apiRequest({action:"manage-option",type:"reference_lists",optionAction:existing?"rename":"add",optionKey:values[0],old:existing?.values?.[1]||"",value:values[1]},"masters");
        if(data.options)state.masterOptions=data.options;state.masters=ensureMasterSections(data.masters,state.masterOptions);saveState();renderMasters();document.getElementById("masterDialog").close();form.reset();toast(existing?"Reference option updated.":"Reference option added.");return;
      }
      const pendingDocuments=type.id==="companies"?[...document.querySelectorAll('.company-document-row')].filter(row=>!row.querySelector('[data-document-id]')?.value&&row.querySelector('[data-document-file]')?.files?.[0]):[];
      const data = await apiRequest({ action: id ? "update" : "create", type: type.id, id, values }, "masters");
      const companyId=data.id||id;
      for (const row of pendingDocuments) {
        const payload=new FormData();payload.append('csrf',SESSION.csrf);payload.append('action','upload');payload.append('companyId',companyId);payload.append('type',row.querySelector('[data-document-type]').value);payload.append('label',row.querySelector('[data-document-label]').value.trim());payload.append('isDefault',row.querySelector('[data-document-default]').checked?'1':'');payload.append('file',row.querySelector('[data-document-file]').files[0]);
        const response=await fetch('api/master_documents.php',{method:'POST',body:payload});const uploaded=await response.json().catch(()=>({ok:false,error:'Document upload returned an unreadable response.'}));if(!response.ok||!uploaded.ok)throw new Error(uploaded.error||'Company document upload failed.');
      }
      if (pendingDocuments.length) { const refreshed=await apiRequest(null,"masters");data.masters=refreshed.masters; }
      if (data.options) state.masterOptions=data.options; state.masters = ensureMasterSections(data.masters,state.masterOptions); addAudit("Master", id ? "Updated" : "Created", `${type.name}: ${primary}`, ref);
      if(type.id==="purchase_kat"){currentPurchaseSection="PRODUCTS";pendingKatProductId=""}
      saveState(); renderMasters(); renderAudit(); renderRecentActivity(); document.getElementById("masterDialog").close(); form.reset(); toast(id ? "Master record updated." : "Master record saved.");
    } catch (error) { toast(error.message); }
  }
  async function deleteMasterRecord(selectedId) {
    const type=activeMasterType();
    const id = String(selectedId || document.getElementById("editMasterId").value);
    const row = (state.masters[type.id] || []).find(item => item.id === id); if (!row) return;
    const removingStaff=type.id==="salary_staff";
    if (!window.confirm(`${removingStaff ? "Remove" : "Deactivate"} ${row.values[0]} ${removingStaff ? "from future Salary Sheets" : `in ${type.name}`}? Historical transactions will remain unchanged.`)) return;
    try { if(type.id==="reference_lists"){const data=await apiRequest({action:"manage-option",type:"reference_lists",optionAction:"delete",optionKey:row.values[0],old:row.values[1],value:""},"masters");if(data.options)state.masterOptions=data.options;state.masters=ensureMasterSections(data.masters,state.masterOptions);saveState();renderMasters();document.getElementById("masterDialog").close();toast("Reference option deactivated.");return;} const data = await apiRequest({ action: "delete", type: type.id, id }, "masters"); state.masters = ensureMasterSections(data.masters,state.masterOptions); saveState(); renderMasters(); document.getElementById("masterDialog").close(); toast(removingStaff ? "Staff removed from future Salary Sheets." : "Master record deactivated."); }
    catch (error) { toast(error.message); }
  }
  async function purgeMasterRecord(selectedId) {
    const type=activeMasterType();
    const id=String(selectedId||"");
    const row=(state.masters[type.id]||[]).find(item=>item.id===id);if(!row)return;
    if(!window.confirm(`Permanently delete ${row.values[0]}? This is allowed only when the record is unused. It cannot be recovered.`))return;
    try{const data=await apiRequest({action:"purge",type:type.id,id},"masters");state.masters=ensureMasterSections(data.masters,state.masterOptions);saveState();renderMasters();toast("Unused master record permanently deleted.");}
    catch(error){toast(error.message);}
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
    if (!IS_SUPER_ADMIN && !["dashboard", "modules"].includes(id) && !(id==="masters"&&hasMasterAccess)) { toast("Super Admin access required."); return; }
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
    const visibleMasterIds=new Set(MASTER_GROUPS.flatMap(([,ids])=>ids));
    const master = hasMasterAccess ? MASTER_TYPES.filter(item=>visibleMasterIds.has(item.id)&&canMaster(item.id,"View")).find(item => `${item.name} ${item.description}`.toLowerCase().includes(query)) : null;
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
    const editProductKat = event.target.closest("[data-edit-product-kat]");
    const deleteMaster = event.target.closest("[data-delete-master]");
    const purgeMaster = event.target.closest("[data-purge-master]");
    const lockButton = event.target.closest("[data-toggle-lock]");
    const removeProductSpec = event.target.closest("[data-remove-product-spec]");
    const removeKatParameter = event.target.closest("[data-remove-kat-parameter]");
    const addKatRange = event.target.closest("[data-add-kat-range]");
    const removeKatRange = event.target.closest("[data-remove-kat-range]");
    const closeDialog = event.target.closest("[data-close-dialog]");
    if (viewButton) showView(viewButton.dataset.view);
    if (viewTarget) showView(viewTarget.dataset.viewTarget);
    if (moduleButton) openModule(moduleButton.dataset.openModule || moduleButton.dataset.moduleLink);
    if (event.target.closest('[data-action="create-user"]')) openUserDialog();
    if (editUser) openUserDialog(editUser.dataset.editUser);
    if (resetUser) resetPassword(resetUser.dataset.resetUser);
    if (deleteUserButton) deleteUser(deleteUserButton.dataset.deleteUser);
    if (masterButton) { currentMaster = masterButton.dataset.master; if(currentMaster==="purchase_products")currentPurchaseSection="PRODUCTS"; renderMasters(); }
    if (editMaster) openMasterDialog(editMaster.dataset.editMaster);
    if (editProductKat) openKatForPurchaseProduct(editProductKat.dataset.editProductKat);
    if (deleteMaster) deleteMasterRecord(deleteMaster.dataset.deleteMaster);
    if (purgeMaster) purgeMasterRecord(purgeMaster.dataset.purgeMaster);
    if (lockButton) toggleLock(lockButton.dataset.toggleLock);
    if (event.target.closest("#addProductSpecification")) document.getElementById("productSpecRows")?.insertAdjacentHTML("beforeend", productSpecRow("", "", true));
    if (removeProductSpec) removeProductSpec.closest("tr")?.remove();
    if (event.target.closest("#addKatParameter")) document.getElementById("katParameterCards")?.insertAdjacentHTML("beforeend",katParameterCard({},document.querySelectorAll("[data-kat-parameter]").length));
    if (removeKatParameter) removeKatParameter.closest("[data-kat-parameter]")?.remove();
    if (addKatRange) { const card=addKatRange.closest("[data-kat-parameter]"); card?.querySelector("[data-kat-range-list]")?.insertAdjacentHTML("beforeend",katRangeRow({unit:card.querySelector("[data-kat-default-unit]")?.value||"paisa per %"})); }
    if (removeKatRange) removeKatRange.closest("tr")?.remove();
    if (closeDialog) { document.getElementById(closeDialog.dataset.closeDialog)?.close(); if(closeDialog.dataset.closeDialog==="masterDialog"&&currentPurchaseSection==="KAT"){currentPurchaseSection="PRODUCTS";pendingKatProductId="";renderMasters();} }
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
  document.getElementById("masterPermissionMatrix").addEventListener("change", event=>{
    const input=event.target.closest('input[data-master-permission]');if(!input)return;
    const row=input.closest('.permission-row'),view=row?.querySelector('input[value="View"]');
    if(!["Use","View"].includes(input.value)&&input.checked&&view)view.checked=true;
    if(input.value==="View"&&!input.checked)row?.querySelectorAll('input:not([value="Use"]):not([value="View"])').forEach(box=>box.checked=false);
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
    if (new URLSearchParams(location.search).get('view')==='masters' && hasMasterAccess) showView('masters');
    if (IS_SUPER_ADMIN) {
      try { await Promise.all([loadServerUsers(),loadServerMasters()]); } catch (error) { toast(error.message); }
    } else if (hasMasterAccess) {
      try { await loadServerMasters(); } catch (error) { toast(error.message); }
    }
    saveState("All changes saved");
  }
  initialize();
})();
