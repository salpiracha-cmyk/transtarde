(() => {
  "use strict";

  const STORAGE_KEY = "transtrade_super_admin_v1";
  const STATE_VERSION = 2;
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
    { id: "companies", name: "Companies", description: "Pakistan and authorized group company identities used in documents.", columns: ["Company", "Code", "Use"], rows: [["Transtrade International", "TTI", "Pakistan operations"], ["BRM", "BRM", "Authorized documents"]] },
    { id: "parties", name: "Parties", description: "Buyers, suppliers, brokers and local parties stored once.", columns: ["Party", "Code", "Type"], rows: [["Shams", "BRK-001", "Broker"], ["Sample Overseas Buyer", "BUY-001", "Export buyer"]] },
    { id: "products", name: "Products & Quality", description: "Rice varieties, outputs and approved quality standards.", columns: ["Product", "Code", "Category"], rows: [["IRRI-6 White Rice", "IR6-W", "Ready rice"], ["IRRI-6 Parboiled Rice", "IR6-P", "Ready rice"], ["B2 Sortex Broken", "B2-S", "By-product"]] },
    { id: "mills", name: "Mills & Locations", description: "Own mill, external mills and stock locations.", columns: ["Mill / Location", "Code", "Type"], rows: [["TTI Rice Mill", "TTI-MILL", "Own mill"], ["Karachi Office", "KHI-OFF", "Office"]] },
    { id: "banks", name: "Banks", description: "Authorized accounts and module-level visibility settings.", columns: ["Bank", "Code", "Visibility"], rows: [["Sample Operating Bank ••••• 12345", "BANK-01", "Accounts / Directors"], ["Sample Collection Bank ••••• 48291", "BANK-02", "Accounts only"]] },
    { id: "bags", name: "Bags & Brands", description: "New export bags, used-bag sources and approved brands.", columns: ["Bag / Brand", "Code", "Type"], rows: [["Generic 25 KG Export Bag", "BAG-25", "New export bag"], ["Arrival Used Bags", "USED-ARR", "Used bag source"], ["Outside Used Bags", "USED-EXT", "Separate used bag source"]] },
    { id: "ports", name: "Ports & Shipping", description: "Ports, shipping lines, agents and document defaults.", columns: ["Record", "Code", "Type"], rows: [["Port Qasim", "PKBQM", "Port"], ["Karachi Port", "PKKHI", "Port"]] }
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
    state.masters = data.masters;
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

  function renderMasters() {
    document.getElementById("masterMenu").innerHTML = MASTER_TYPES.map(type => `<button class="${type.id === currentMaster ? "active" : ""}" data-master="${type.id}">${type.name}<span>${state.masters[type.id]?.length || 0}</span></button>`).join("");
    const type = MASTER_TYPES.find(item => item.id === currentMaster);
    document.getElementById("masterTitle").textContent = type.name;
    document.getElementById("masterDescription").textContent = type.description;
    document.getElementById("masterTableHead").innerHTML = `<tr>${type.columns.map(col => `<th>${col}</th>`).join("")}<th>Status</th><th>Super Admin actions</th></tr>`;
    const query = document.getElementById("masterSearch")?.value.toLowerCase() || "";
    const rows = (state.masters[currentMaster] || []).filter(row => row.values.join(" ").toLowerCase().includes(query));
    document.getElementById("masterTableBody").innerHTML = rows.length ? rows.map(row => `<tr>${type.columns.map((_, i) => `<td>${escapeHtml(row.values[i] || "—")}</td>`).join("")}<td><span class="status">Active</span></td><td><div class="row-actions"><button class="row-action" data-edit-master="${escapeHtml(row.id)}">Edit</button><button class="row-action delete" data-delete-master="${escapeHtml(row.id)}">Delete</button></div></td></tr>`).join("") : `<tr><td colspan="${type.columns.length + 2}">No matching records.</td></tr>`;
  }
  function openMasterDialog(id = "") {
    const row=(state.masters[currentMaster] || []).find(item => item.id === id);
    document.getElementById("masterForm").reset();
    document.getElementById("editMasterId").value=row?.id || "";
    document.getElementById("masterRecordName").value=row?.values?.[0] || "";
    document.getElementById("masterRecordCode").value=row?.values?.[1] || "";
    document.getElementById("masterRecordNotes").value=row?.values?.[2] || "";
    document.getElementById("deleteMasterButton").hidden=!row;
    document.getElementById("saveMasterButton").textContent=row ? "Save Changes" : "Save Record";
    document.getElementById("masterDialog").showModal();
  }
  async function saveMasterRecord(event) {
    event.preventDefault();
    const form = document.getElementById("masterForm");
    if (!form.reportValidity()) return;
    const type = MASTER_TYPES.find(item => item.id === currentMaster);
    const id=document.getElementById("editMasterId").value;
    const name = document.getElementById("masterRecordName").value.trim();
    const code = document.getElementById("masterRecordCode").value.trim().toUpperCase();
    const notes = document.getElementById("masterRecordNotes").value.trim() || "General";
    try {
      const data=await apiRequest({action:id ? "update" : "create",type:currentMaster,id,name,code,notes},"masters");
      state.masters=data.masters; addAudit("Master",id ? "Updated" : "Created",`${type.name}: ${name}`,code);
      saveState(); renderMasters(); renderAudit(); renderRecentActivity(); document.getElementById("masterDialog").close(); form.reset(); toast(id ? "Master record updated." : "Master record saved.");
    } catch(error) { toast(error.message); }
  }
  async function deleteMasterRecord(selectedId) {
    const id=String(selectedId || document.getElementById("editMasterId").value);
    const row=(state.masters[currentMaster] || []).find(item => item.id===id); if(!row) return;
    if(!window.confirm(`Delete ${row.values[0]} from ${MASTER_TYPES.find(item=>item.id===currentMaster).name}?`)) return;
    try { const data=await apiRequest({action:"delete",type:currentMaster,id},"masters"); state.masters=data.masters; saveState(); renderMasters(); document.getElementById("masterDialog").close(); toast("Master record deleted."); }
    catch(error) { toast(error.message); }
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
