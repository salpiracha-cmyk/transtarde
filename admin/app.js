(() => {
  "use strict";

  const STORAGE_KEY = "transtrade_super_admin_v1";
  const STATE_VERSION = 2;
  const ACTIONS = ["View", "Create", "Edit", "Delete", "Print", "Approve", "Reports"];
  const MODULES = [
    { id: "milling", name: "Mill", code: "M", color: "#16815a", soft: "#e7f7f0", status: "Integration ready", state: "green", version: "V3.3.2 Audited", description: "Arrivals, stocks, production, bags, loading and mill operations.", href: "module.php?id=milling" },
    { id: "exports", name: "Exports", code: "E", color: "#1769d2", soft: "#eaf2ff", status: "Being finalized", state: "blue", version: "V2.6 Stabilized", description: "Contracts, export orders, shipment planning and documentation.", href: "module.php?id=exports" },
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
      { id: "u-salman", name: "Salman", username: "salman", role: "Super Admin", location: "All locations", active: true, modules: ["Mill", "Exports", "Accounts", "Directors"], permissions: Object.fromEntries(["Mill", "Exports", "Accounts", "Directors"].map(m => [m, [...ACTIONS]])), lastActive: "Now" },
      { id: "u-jazib", name: "Jazib", username: "jazib.exports", role: "Exports", location: "Karachi Office", active: true, modules: ["Exports"], permissions: { Exports: ["View", "Create", "Edit", "Print"] }, lastActive: "Today, 3:40 PM" },
      { id: "u-yar", name: "Mr. Yar Azam", username: "yarazam.mill", role: "Mill Manager", location: "TTI Rice Mill", active: true, modules: ["Mill"], permissions: { Mill: ["View", "Create", "Edit", "Print", "Approve", "Reports"] }, lastActive: "Yesterday" },
      { id: "u-accounts", name: "Accounts User", username: "accounts", role: "Accounts", location: "Karachi Office", active: false, modules: ["Accounts"], permissions: { Accounts: ["View", "Create", "Edit", "Print", "Reports"] }, lastActive: "Not activated" }
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
    state.audit.unshift({ date: nowStamp(), user: "Salman", area, action, detail, ref });
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
    document.getElementById("dashboardModules").innerHTML = MODULES.map(moduleCard).join("");
    document.getElementById("allModules").innerHTML = MODULES.map(moduleCard).join("");
  }
  function openModule(id) {
    const module = MODULES.find(item => item.id === id);
    if (!module) return;
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
      <td>${escapeHtml(user.lastActive)}</td><td><button class="row-menu" data-edit-user="${user.id}" aria-label="Edit ${escapeHtml(user.name)}">•••</button></td>
    </tr>`).join("");
    document.getElementById("activeUserCount").textContent = state.users.filter(user => user.active).length;
    document.getElementById("permissionChangeCount").textContent = state.permissionChanges;
  }

  function permissionMatrix(permissions = {}) {
    return `<div class="permission-row header"><strong>Module</strong>${ACTIONS.map(action => `<span>${action}</span>`).join("")}</div>` +
      ["Mill", "Exports", "Accounts", "Directors"].map(module => `<div class="permission-row"><strong>${module}</strong>${ACTIONS.map(action => `<label title="${module}: ${action}"><input type="checkbox" data-permission-module="${module}" value="${action}" ${permissions[module]?.includes(action) ? "checked" : ""}></label>`).join("")}</div>`).join("");
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
      toast("The only Super Admin account cannot be reduced from this screen.");
    } else {
      document.querySelectorAll("#userForm input, #userForm select").forEach(input => input.disabled = false);
      document.getElementById("saveUserButton").disabled = false;
      document.getElementById("deleteUserButton").hidden = !user;
    }
    dialog.showModal();
  }
  function deleteUser() {
    const id = document.getElementById("editUserId").value;
    const user = state.users.find(item => item.id === id);
    if (!user || user.role === "Super Admin") return;
    if (!window.confirm(`Delete ${user.name}'s trial user record?`)) return;
    state.users = state.users.filter(item => item.id !== id);
    addAudit("User", "Deleted", `${user.name} trial user removed`, user.username);
    state.permissionChanges += 1;
    saveState(); renderUsers(); renderAudit(); renderRecentActivity();
    document.getElementById("userDialog").close();
    toast("User removed.");
  }
  function saveUser(event) {
    event.preventDefault();
    const form = document.getElementById("userForm");
    if (!form.reportValidity()) return;
    const id = document.getElementById("editUserId").value;
    const permissions = {};
    document.querySelectorAll("#permissionMatrix input:checked").forEach(input => {
      const module = input.dataset.permissionModule;
      (permissions[module] ||= []).push(input.value);
    });
    const modules = Object.keys(permissions).filter(module => permissions[module].includes("View") || permissions[module].length);
    if (!modules.length) { toast("Select at least one module permission."); return; }
    const user = {
      id: id || `u-${Date.now()}`,
      name: document.getElementById("userName").value.trim(),
      username: document.getElementById("username").value.trim(),
      role: document.getElementById("userRole").value,
      location: document.getElementById("userLocation").value,
      active: document.getElementById("userActive").checked,
      modules, permissions,
      lastActive: id ? (state.users.find(item => item.id === id)?.lastActive || "Not activated") : "Not activated"
    };
    const duplicate = state.users.some(item => item.username.toLowerCase() === user.username.toLowerCase() && item.id !== id);
    if (duplicate) { toast("This username already exists."); return; }
    if (id) {
      const index = state.users.findIndex(item => item.id === id);
      state.users[index] = user;
      addAudit("Permission", "Updated", `Permissions updated for ${user.name}`, user.username);
    } else {
      state.users.push(user);
      addAudit("User", "Created", `${user.name} created with ${modules.join(", ")} access`, user.username);
    }
    state.permissionChanges += 1;
    saveState(); renderUsers(); renderAudit(); renderRecentActivity();
    document.getElementById("userDialog").close();
    toast(id ? "User and permissions updated." : "User created successfully.");
  }

  function renderMasters() {
    document.getElementById("masterMenu").innerHTML = MASTER_TYPES.map(type => `<button class="${type.id === currentMaster ? "active" : ""}" data-master="${type.id}">${type.name}<span>${state.masters[type.id]?.length || 0}</span></button>`).join("");
    const type = MASTER_TYPES.find(item => item.id === currentMaster);
    document.getElementById("masterTitle").textContent = type.name;
    document.getElementById("masterDescription").textContent = type.description;
    document.getElementById("masterTableHead").innerHTML = `<tr>${type.columns.map(col => `<th>${col}</th>`).join("")}<th>Status</th></tr>`;
    const query = document.getElementById("masterSearch")?.value.toLowerCase() || "";
    const rows = (state.masters[currentMaster] || []).filter(row => row.values.join(" ").toLowerCase().includes(query));
    document.getElementById("masterTableBody").innerHTML = rows.length ? rows.map(row => `<tr>${type.columns.map((_, i) => `<td>${escapeHtml(row.values[i] || "—")}</td>`).join("")}<td><span class="status">Active</span></td></tr>`).join("") : `<tr><td colspan="${type.columns.length + 1}">No matching records.</td></tr>`;
  }
  function saveMasterRecord(event) {
    event.preventDefault();
    const form = document.getElementById("masterForm");
    if (!form.reportValidity()) return;
    const type = MASTER_TYPES.find(item => item.id === currentMaster);
    const name = document.getElementById("masterRecordName").value.trim();
    const code = document.getElementById("masterRecordCode").value.trim().toUpperCase();
    const notes = document.getElementById("masterRecordNotes").value.trim() || "General";
    state.masters[currentMaster].push({ id: `${currentMaster}-${Date.now()}`, values: [name, code, notes] });
    addAudit("Master", "Created", `${type.name}: ${name}`, code);
    saveState(); renderMasters(); renderAudit(); renderRecentActivity();
    document.getElementById("masterDialog").close(); form.reset(); toast("Master record saved.");
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
      toast(`${module.name} remains locked. Create an impact request before reopening.`);
      addAudit("Module", "Reopen blocked", `${module.name} requires documented impact approval`, module.version);
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
    const user = state.users.find(item => `${item.name} ${item.username} ${item.role}`.toLowerCase().includes(query));
    const module = MODULES.find(item => `${item.name} ${item.description}`.toLowerCase().includes(query));
    const master = MASTER_TYPES.find(item => `${item.name} ${item.description}`.toLowerCase().includes(query));
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
    const masterButton = event.target.closest("[data-master]");
    const lockButton = event.target.closest("[data-toggle-lock]");
    const closeDialog = event.target.closest("[data-close-dialog]");
    if (viewButton) showView(viewButton.dataset.view);
    if (viewTarget) showView(viewTarget.dataset.viewTarget);
    if (moduleButton) openModule(moduleButton.dataset.openModule || moduleButton.dataset.moduleLink);
    if (event.target.closest('[data-action="create-user"]')) openUserDialog();
    if (editUser) openUserDialog(editUser.dataset.editUser);
    if (masterButton) { currentMaster = masterButton.dataset.master; renderMasters(); }
    if (lockButton) toggleLock(lockButton.dataset.toggleLock);
    if (closeDialog) document.getElementById(closeDialog.dataset.closeDialog)?.close();
    if (event.target.closest('[data-action="close-notifications"]')) openNotifications(false);
  });
  document.getElementById("userForm").addEventListener("submit", saveUser);
  document.getElementById("deleteUserButton").addEventListener("click", deleteUser);
  document.getElementById("masterForm").addEventListener("submit", saveMasterRecord);
  document.getElementById("addMasterRecord").addEventListener("click", () => document.getElementById("masterDialog").showModal());
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

  renderModules(); renderUsers(); renderMasters(); renderLocks(); renderAudit(); renderRecentActivity(); saveState("All changes saved");
})();
