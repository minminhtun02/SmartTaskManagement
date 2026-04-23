const app = document.getElementById("app");
const API_BASE = window.__TASKAI_API_BASE__ || "/api";

const LOOKUPS = {
  categories: [
    { id: 1, name: "Academic" },
    { id: 2, name: "Work" },
    { id: 3, name: "Personal" },
    { id: 4, name: "Health" },
    { id: 5, name: "Finance" }
  ],
  priorities: [
    { id: 1, name: "High", key: "high" },
    { id: 2, name: "Medium", key: "medium" },
    { id: 3, name: "Low", key: "low" }
  ],
  statuses: [
    { id: 1, name: "To Do", key: "todo" },
    { id: 2, name: "In Progress", key: "in-progress" },
    { id: 3, name: "Completed", key: "completed" }
  ]
};

const state = {
  isAuthed: false,
  authMode: "login",
  screen: "dashboard",
  loading: false,
  user: null,
  tasks: [],
  summary: null,
  ai: {
    focusTask: null,
    productivityTip: null,
    prioritySuggestion: null,
    categorySuggestion: null,
    timeEstimate: null,
    insightsTaskId: null,
    insightsError: null
  },
  formAi: { loading: false, error: null, data: null },
  chatMessages: [],
  activeTaskId: null,
  deleteCandidateId: null,
  formErrors: {},
  formMode: "create",
  filters: {
    search: "",
    priority_id: "",
    category_id: "",
    status_id: "",
    sort: "due_date"
  },
  form: emptyForm()
};

function emptyForm() {
  return {
    title: "",
    description: "",
    due_date: "",
    category_id: "1",
    priority_id: "2",
    status_id: "1",
    estimated_minutes: "60"
  };
}

function resetFormAi() {
  state.formAi = { loading: false, error: null, data: null };
}

async function sendChatMessage() {
  const input = document.getElementById("chat-input");
  const text = String(input?.value || "").trim();
  if (!text) return;
  state.chatMessages = [...state.chatMessages, { role: "user", text }];
  if (input) input.value = "";
  render();
  const log = document.getElementById("chat-log");
  if (log) log.scrollTop = log.scrollHeight;
  try {
    const res = await api("/ai/chat", { method: "POST", body: { message: text } });
    const reply = res.data?.reply ?? "No reply.";
    state.chatMessages = [...state.chatMessages, { role: "assistant", text: reply }];
  } catch (err) {
    state.chatMessages = [
      ...state.chatMessages,
      { role: "assistant", text: err instanceof Error ? err.message : "Chat failed." }
    ];
  }
  render();
  const log2 = document.getElementById("chat-log");
  if (log2) log2.scrollTop = log2.scrollHeight;
}

async function api(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    method: options.method || "GET",
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
    body: options.body ? JSON.stringify(options.body) : null,
    credentials: "same-origin"
  });
  const json = await response.json().catch(() => ({
    success: false,
    message: "Invalid JSON response.",
    data: {}
  }));
  if (!response.ok) {
    throw new Error(json.message || "Request failed");
  }
  return json;
}

async function bootstrap() {
  try {
    const me = await api("/auth/me");
    state.isAuthed = true;
    state.user = me.data || null;
    await refreshAll();
    return;
  } catch {
    state.isAuthed = false;
  }
  render();
}

function render() {
  app.innerHTML = state.isAuthed ? renderApp() : renderAuth();
  bindEvents();
}

function renderAuth() {
  const isLogin = state.authMode === "login";
  return `
    <main class="auth-page">
      <section class="auth-card">
        <aside class="auth-brand">
          <div class="logo">T</div>
          <h1>TaskAI Smart Prioritization</h1>
          <p>Organize tasks, prioritize deadlines, and improve focus with AI-assisted recommendations.</p>
        </aside>
        <section class="auth-form-wrap">
          <div class="toggle-row">
            <button class="${isLogin ? "active" : ""}" data-auth-mode="login">Login</button>
            <button class="${!isLogin ? "active" : ""}" data-auth-mode="register">Register</button>
          </div>
          <h2>${isLogin ? "Welcome Back" : "Create Account"}</h2>
          <form id="auth-form" class="form-grid" novalidate>
            ${
              isLogin
                ? ""
                : `<div class="field"><label>Full Name</label><input name="full_name" placeholder="Jane Doe"/><small class="error" data-error-for="full_name"></small></div>`
            }
            <div class="field"><label>Email</label><input name="email" type="email" placeholder="jane@example.com"/><small class="error" data-error-for="email"></small></div>
            <div class="field"><label>Password</label><input name="password" type="password" placeholder="Minimum 8 characters"/><small class="error" data-error-for="password"></small></div>
            <button class="btn primary" type="submit">${isLogin ? "Login" : "Register"}</button>
          </form>
        </section>
      </section>
    </main>
  `;
}

function renderApp() {
  return `
    <div class="app-shell">
      ${renderSidebar()}
      <main class="content">
        ${renderTopbar()}
        <section class="screen">${renderScreen()}</section>
      </main>
    </div>
    ${renderDeleteDialog()}
  `;
}

function renderSidebar() {
  const links = [
    ["dashboard", "Dashboard"],
    ["tasks", "Tasks"],
    ["task-form", state.formMode === "edit" ? "Edit Task" : "Create Task"],
    ["task-detail", "Task Detail"],
    ["insights", "Insights"],
    ["ai", "AI Assistant"]
  ];
  return `
    <aside class="sidebar">
      <div class="brand">
        <div class="logo" style="background:#dbeafe;color:#1d4ed8;">T</div>
        <div><div class="name">TaskAI</div><div class="tag">Smart Priority</div></div>
      </div>
      <nav class="nav">
      ${links
        .map(
          ([id, label]) =>
            `<button class="nav-link ${state.screen === id ? "active" : ""}" data-screen="${id}">${label}</button>`
        )
        .join("")}
      </nav>
      <div class="sidebar-footer">
        <button class="nav-link" id="logout-btn">Logout</button>
      </div>
    </aside>
  `;
}

function renderTopbar() {
  return `
    <header class="topbar">
      <div class="search"><input id="global-search" placeholder="Search tasks..." value="${escapeHtml(state.filters.search)}"/></div>
      <div class="top-actions">
        <button class="btn primary" id="add-task-btn">+ Add Task</button>
        <div class="avatar">${state.user?.full_name?.slice(0, 1).toUpperCase() || "U"}</div>
      </div>
    </header>
  `;
}

function renderScreen() {
  if (state.loading) return `<div class="card panel"><p>Loading...</p></div>`;
  switch (state.screen) {
    case "dashboard":
      return renderDashboard();
    case "tasks":
      return renderTasks();
    case "task-form":
      return renderTaskForm();
    case "task-detail":
      return renderTaskDetail();
    case "insights":
      return renderInsights();
    case "ai":
      return renderAI();
    default:
      return renderDashboard();
  }
}

function renderDashboard() {
  const m = state.summary || {
    total_tasks: 0,
    tasks_due_today: 0,
    high_priority_tasks: 0,
    completed_tasks: 0,
    pending_tasks: 0
  };
  const upcoming = [...state.tasks]
    .filter((t) => t.status_id !== 3)
    .sort((a, b) => (a.due_date || "").localeCompare(b.due_date || ""))
    .slice(0, 5);

  return `
    <div class="screen-head"><h2>Main Dashboard</h2></div>
    <div class="summary-grid">
      ${summaryCard("Total Tasks", m.total_tasks || 0)}
      ${summaryCard("Due Today", m.tasks_due_today || 0)}
      ${summaryCard("High Priority Tasks", m.high_priority_tasks || 0)}
      ${summaryCard("Completed Tasks", m.completed_tasks || 0)}
    </div>
    <div class="content-grid">
      <div class="card panel">
        <h3>Upcoming Deadlines</h3>
        ${
          upcoming.length
            ? upcoming
                .map(
                  (t) => `
            <div class="task-row">
              <div>
                <strong>${escapeHtml(t.title)}</strong>
                <div class="task-meta">
                  <span>${escapeHtml(t.category_name || "")}</span>
                  <span>${formatDueBadge(t.due_date)}</span>
                  <span class="priority ${priorityClass(t.priority_name)}">${escapeHtml(t.priority_name)}</span>
                </div>
              </div>
              <button class="icon-btn" data-open-task="${t.id}">View</button>
            </div>`
                )
                .join("")
            : `<div class="empty"><h3>No tasks yet</h3><p>Add your first task to see upcoming deadlines.</p></div>`
        }
      </div>
      <div class="card panel">
        <h3>Progress Overview</h3>
        <div class="value">${completionPercent()}%</div>
        <div class="progress"><span style="width:${completionPercent()}%"></span></div>
        <div class="kpi-inline">
          <div><strong>${countByStatus(1)}</strong><div class="label">To Do</div></div>
          <div><strong>${countByStatus(2)}</strong><div class="label">In Progress</div></div>
          <div><strong>${countByStatus(3)}</strong><div class="label">Completed</div></div>
        </div>
      </div>
    </div>
  `;
}

function renderTasks() {
  const filtered = getFilteredLocalTasks();
  return `
    <div class="screen-head"><h2>Task Management</h2><button class="btn primary" data-screen="task-form">+ Add Task</button></div>
    <div class="card panel">
      <div class="toolbar">
        <input id="task-search" placeholder="Search by title or description..." value="${escapeHtml(state.filters.search)}"/>
        <select id="filter-priority">
          <option value="">All Priority</option>
          ${LOOKUPS.priorities.map((p) => `<option value="${p.id}" ${String(state.filters.priority_id) === String(p.id) ? "selected" : ""}>${p.name}</option>`).join("")}
        </select>
        <select id="filter-status">
          <option value="">All Status</option>
          ${LOOKUPS.statuses.map((s) => `<option value="${s.id}" ${String(state.filters.status_id) === String(s.id) ? "selected" : ""}>${s.name}</option>`).join("")}
        </select>
        <select id="filter-category">
          <option value="">All Category</option>
          ${LOOKUPS.categories.map((c) => `<option value="${c.id}" ${String(state.filters.category_id) === String(c.id) ? "selected" : ""}>${c.name}</option>`).join("")}
        </select>
        <select id="sort-by">
          <option value="due_date" ${state.filters.sort === "due_date" ? "selected" : ""}>Sort: Due Soon</option>
          <option value="priority" ${state.filters.sort === "priority" ? "selected" : ""}>Sort: Priority</option>
          <option value="category" ${state.filters.sort === "category" ? "selected" : ""}>Sort: Category</option>
          <option value="status" ${state.filters.sort === "status" ? "selected" : ""}>Sort: Status</option>
        </select>
      </div>
      ${
        filtered.length
          ? `<table class="task-table">
            <thead><tr><th>Title</th><th>Category</th><th>Due Date</th><th>Priority</th><th>Status</th><th>Progress</th><th>Actions</th></tr></thead>
            <tbody>
              ${filtered
                .map(
                  (t) => `<tr>
                    <td><strong>${escapeHtml(t.title)}</strong><div class="label">${escapeHtml((t.description || "").slice(0, 50))}</div></td>
                    <td>${escapeHtml(t.category_name || "")}</td>
                    <td>${t.due_date ? t.due_date.slice(0, 10) : "-"}</td>
                    <td><span class="priority ${priorityClass(t.priority_name)}">${escapeHtml(t.priority_name)}</span></td>
                    <td><span class="status ${statusClass(t.status_name)}">${escapeHtml(t.status_name)}</span></td>
                    <td><div class="progress"><span style="width:${t.progress_percent || 0}%"></span></div></td>
                    <td><div class="icon-row">
                      <button class="icon-btn" data-open-task="${t.id}">View</button>
                      <button class="icon-btn" data-edit-task="${t.id}">Edit</button>
                      <button class="icon-btn" data-delete-task="${t.id}" style="color:#b91c1c;">Delete</button>
                    </div></td>
                  </tr>`
                )
                .join("")}
            </tbody>
          </table>`
          : `<div class="empty"><h3>No tasks found</h3><p>Create a task to get started.</p><button class="btn primary" data-screen="task-form">Create Task</button></div>`
      }
    </div>
  `;
}

function renderTaskForm() {
  const f = state.form;
  const fa = state.formAi;
  const draftPanel =
    fa.loading || fa.error || fa.data
      ? `
    <div class="card panel" style="background:#f8fbff;border-style:dashed;margin-top:14px;">
      <h3 style="margin-top:0;">AI suggestions for this task</h3>
      ${
        fa.loading
          ? `<p class="label">Generating suggestions…</p>`
          : fa.error
            ? `<p class="label" style="color:#b91c1c;">${escapeHtml(fa.error)}</p>`
            : fa.data
              ? `
        <div class="insight-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
          <article class="card insight-card"><h4>Productivity</h4><p class="label">${escapeHtml(fa.data.productivity_tip || "")}</p></article>
          <article class="card insight-card"><h4>Smart priority</h4><p class="label">${escapeHtml(formatPriorityInsight(fa.data.priority))}</p></article>
          <article class="card insight-card"><h4>Category</h4><p class="label">${escapeHtml(formatCategoryInsight(fa.data.category))}</p></article>
          <article class="card insight-card"><h4>Time estimate</h4><p class="label">${escapeHtml(formatTimeInsight(fa.data.time_estimate))}</p></article>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
          <button type="button" class="btn primary" id="form-ai-apply">Apply suggestions to form</button>
        </div>`
              : ""
      }
    </div>`
      : "";

  return `
    <div class="screen-head"><h2>${state.formMode === "edit" ? "Edit Task" : "Create Task"}</h2></div>
    <div class="card form-card"><form id="task-form" class="panel" novalidate>
      <div class="field"><label>Title</label><input name="title" value="${escapeHtml(f.title)}"/><small class="error">${state.formErrors.title || ""}</small></div>
      <div class="field"><label>Description</label><textarea name="description" rows="4">${escapeHtml(f.description)}</textarea></div>
      <div class="form-two">
        <div class="field"><label>Due Date</label><input type="datetime-local" name="due_date" value="${toDateTimeLocal(f.due_date)}"/><small class="error">${state.formErrors.due_date || ""}</small></div>
        <div class="field"><label>Category</label><select name="category_id">${LOOKUPS.categories.map((c) => `<option value="${c.id}" ${String(f.category_id) === String(c.id) ? "selected" : ""}>${c.name}</option>`).join("")}</select></div>
        <div class="field"><label>Priority</label><select name="priority_id">${LOOKUPS.priorities.map((p) => `<option value="${p.id}" ${String(f.priority_id) === String(p.id) ? "selected" : ""}>${p.name}</option>`).join("")}</select></div>
        <div class="field"><label>Status</label><select name="status_id">${LOOKUPS.statuses.map((s) => `<option value="${s.id}" ${String(f.status_id) === String(s.id) ? "selected" : ""}>${s.name}</option>`).join("")}</select></div>
        <div class="field"><label>Estimated Minutes</label><input type="number" min="1" name="estimated_minutes" value="${escapeHtml(String(f.estimated_minutes || 60))}"/></div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
        <button type="button" class="btn secondary" id="btn-form-ai-suggestions">Get AI suggestions</button>
        <button type="button" class="btn secondary" data-screen="tasks">Cancel</button>
        <button type="submit" class="btn primary">${state.formMode === "edit" ? "Save Changes" : "Create Task"}</button>
      </div>
    </form>${draftPanel}</div>
  `;
}

function renderTaskDetail() {
  const task = state.tasks.find((t) => t.id === state.activeTaskId);
  if (!task) {
    return `<div class="empty"><h3>No task selected</h3><button class="btn primary" data-screen="tasks">Go to tasks</button></div>`;
  }
  return `
    <div class="screen-head"><h2>Task Detail</h2></div>
    <div class="detail-grid">
      <article class="card panel">
        <h3>${escapeHtml(task.title)}</h3>
        <p class="label">${escapeHtml(task.description || "")}</p>
        <div class="task-meta">
          <span class="priority ${priorityClass(task.priority_name)}">${escapeHtml(task.priority_name)}</span>
          <span class="status ${statusClass(task.status_name)}">${escapeHtml(task.status_name)}</span>
          <span>${task.due_date ? task.due_date.slice(0, 16).replace("T", " ") : "No due date"}</span>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button class="btn secondary" data-edit-task="${task.id}">Edit</button>
          <button class="btn ghost" style="color:#b91c1c;" data-delete-task="${task.id}">Delete</button>
        </div>
      </article>
      <aside class="card panel">
        <h3>Quick Status Update</h3>
        <div class="toolbar">
          ${LOOKUPS.statuses
            .map(
              (s) =>
                `<button class="btn secondary" data-update-status="${task.id}" data-status-id="${s.id}">${s.name}</button>`
            )
            .join("")}
        </div>
      </aside>
    </div>
  `;
}

function renderInsights() {
  const m = state.summary || {};
  return `
    <div class="screen-head"><h2>Insights</h2></div>
    <div class="stats-grid">
      ${summaryCard("Completion Rate", `${completionPercent()}%`)}
      ${summaryCard("Total Tasks", m.total_tasks || 0)}
      ${summaryCard("Pending Tasks", m.pending_tasks || 0)}
      ${summaryCard("Overdue Tasks", m.overdue_tasks || 0)}
    </div>
    <div class="card panel"><h3>Recent Progress Updates</h3>
      ${
        (m.recent_progress_updates || []).length
          ? m.recent_progress_updates
              .map(
                (r) =>
                  `<div class="task-row"><div><strong>${escapeHtml(r.task_title)}</strong><div class="label">${escapeHtml(r.old_status)} -> ${escapeHtml(r.new_status)}</div></div><span class="label">${escapeHtml(r.changed_at)}</span></div>`
              )
              .join("")
          : `<div class="empty">No progress logs yet.</div>`
      }
    </div>
  `;
}

function renderAI() {
  const a = state.ai;
  const focus = a.focusTask;
  const tip = a.productivityTip || "No suggestion yet.";
  const err = a.insightsError;

  const priorityText = formatPriorityInsight(a.prioritySuggestion);
  const categoryText = formatCategoryInsight(a.categorySuggestion);
  const timeText = formatTimeInsight(a.timeEstimate);

  return `
    <div class="screen-head"><h2>AI Assistant</h2><span class="label">Suggestions use your tasks via the API (OpenAI when configured, otherwise rule-based fallback).</span></div>
    ${err ? `<div class="card panel" style="border-color:#fecaca;background:#fef2f2;"><p class="label" style="color:#991b1b;">${escapeHtml(err)}</p></div>` : ""}
    <div class="card panel" style="background:#eef4ff;">
      <h3>Focus Now</h3>
      ${
        focus
          ? `<strong>${escapeHtml(focus.title)}</strong><p class="label">${escapeHtml(focus.description || "")}</p>`
          : `<p class="label">No open tasks available.</p>`
      }
    </div>
    <div class="insight-grid">
      <article class="card insight-card"><h4>Productivity Suggestion</h4><p class="label">${escapeHtml(tip)}</p></article>
      <article class="card insight-card"><h4>Smart Priority Suggestion</h4><p class="label">${escapeHtml(priorityText)}</p></article>
      <article class="card insight-card"><h4>Category Suggestion</h4><p class="label">${escapeHtml(categoryText)}</p></article>
      <article class="card insight-card"><h4>Time Estimate</h4><p class="label">${escapeHtml(timeText)}</p></article>
    </div>
    <div class="card panel chat-panel">
      <h3>AI Chat</h3>
      <p class="label">Ask questions about your tasks (uses your task list as context).</p>
      <div class="chat-log" id="chat-log">
        ${
          state.chatMessages.length
            ? state.chatMessages
                .map(
                  (m) =>
                    `<div class="chat-msg ${escapeHtml(m.role)}"><span class="chat-role">${m.role === "user" ? "You" : "TaskAI"}</span><p>${escapeHtml(m.text)}</p></div>`
                )
                .join("")
            : `<p class="label chat-empty">No messages yet. Try: “What should I focus on?”</p>`
        }
      </div>
      <div class="chat-input-row">
        <textarea id="chat-input" rows="2" placeholder="Ask about priorities, deadlines, or workload…"></textarea>
        <button type="button" class="btn primary" id="chat-send">Send</button>
      </div>
    </div>
  `;
}

function formatPriorityInsight(data) {
  if (!data) return "Add at least one task, then reload this page to generate a priority suggestion.";
  const p = data.priority ?? "";
  const r = data.reason ?? "";
  return r ? `Suggested: ${p}. ${r}` : `Suggested priority: ${p}.`;
}

function formatCategoryInsight(data) {
  if (!data) return "Add at least one task to get a category suggestion.";
  const c = data.category ?? "";
  const id = data.category_id != null ? ` (category_id ${data.category_id})` : "";
  return `Suggested category: ${c}${id}.`;
}

function formatTimeInsight(data) {
  if (!data) return "Add at least one task to get a time estimate.";
  const mins = data.estimated_minutes ?? "?";
  const basis = data.estimation_basis ?? "";
  return `${mins} minutes. ${basis}`;
}

function renderDeleteDialog() {
  const task = state.tasks.find((t) => t.id === state.deleteCandidateId);
  return `
    <div class="dialog-backdrop ${state.deleteCandidateId ? "open" : ""}">
      <div class="dialog">
        <h3>Delete task?</h3>
        <p class="label">${task ? escapeHtml(task.title) : ""}</p>
        <div class="dialog-actions">
          <button class="btn secondary" id="cancel-delete">Cancel</button>
          <button class="btn primary" id="confirm-delete" style="background:#dc2626;">Delete</button>
        </div>
      </div>
    </div>
  `;
}

function bindEvents() {
  document.querySelectorAll("[data-auth-mode]").forEach((b) =>
    b.addEventListener("click", () => {
      state.authMode = b.dataset.authMode;
      render();
    })
  );

  const authForm = document.getElementById("auth-form");
  if (authForm) {
    authForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      clearAuthErrors();
      const body = Object.fromEntries(new FormData(authForm));
      try {
        const route = state.authMode === "login" ? "/auth/login" : "/auth/register";
        await api(route, { method: "POST", body });
        if (state.authMode === "register") {
          state.authMode = "login";
          render();
          return;
        }
        state.isAuthed = true;
        state.user = null;
        await refreshAll();
      } catch (err) {
        setAuthFormError(err.message);
      }
    });
  }

  document.querySelectorAll("[data-screen]").forEach((b) =>
    b.addEventListener("click", async () => {
      state.screen = b.dataset.screen;
      if (state.screen === "task-form" && state.formMode === "create") {
        state.form = emptyForm();
        resetFormAi();
      }
      if (state.screen === "ai") await refreshAI();
      render();
    })
  );

  const addBtn = document.getElementById("add-task-btn");
  if (addBtn) {
    addBtn.addEventListener("click", () => {
      state.formMode = "create";
      state.form = emptyForm();
      resetFormAi();
      state.screen = "task-form";
      render();
    });
  }

  const logoutBtn = document.getElementById("logout-btn");
  if (logoutBtn) {
    logoutBtn.addEventListener("click", async () => {
      await api("/auth/logout", { method: "POST" }).catch(() => null);
      state.isAuthed = false;
      state.tasks = [];
      state.summary = null;
      state.chatMessages = [];
      resetFormAi();
      render();
    });
  }

  const search = document.getElementById("task-search");
  if (search) search.addEventListener("input", updateFilters);
  ["filter-priority", "filter-status", "filter-category", "sort-by"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener("change", updateFilters);
  });

  document.querySelectorAll("[data-open-task]").forEach((b) =>
    b.addEventListener("click", () => {
      state.activeTaskId = Number(b.dataset.openTask);
      state.screen = "task-detail";
      render();
    })
  );

  document.querySelectorAll("[data-edit-task]").forEach((b) =>
    b.addEventListener("click", () => {
      const task = state.tasks.find((t) => t.id === Number(b.dataset.editTask));
      if (!task) return;
      state.formMode = "edit";
      state.activeTaskId = task.id;
      state.form = {
        title: task.title || "",
        description: task.description || "",
        due_date: task.due_date || "",
        category_id: String(task.category_id || 1),
        priority_id: String(task.priority_id || 2),
        status_id: String(task.status_id || 1),
        estimated_minutes: String(task.estimated_minutes || 60)
      };
      resetFormAi();
      state.screen = "task-form";
      render();
    })
  );

  document.querySelectorAll("[data-delete-task]").forEach((b) =>
    b.addEventListener("click", () => {
      state.deleteCandidateId = Number(b.dataset.deleteTask);
      render();
    })
  );

  const cancelDelete = document.getElementById("cancel-delete");
  if (cancelDelete) {
    cancelDelete.addEventListener("click", () => {
      state.deleteCandidateId = null;
      render();
    });
  }

  const confirmDelete = document.getElementById("confirm-delete");
  if (confirmDelete) {
    confirmDelete.addEventListener("click", async () => {
      if (!state.deleteCandidateId) return;
      await api(`/tasks/${state.deleteCandidateId}`, { method: "DELETE" });
      state.deleteCandidateId = null;
      await refreshAll();
    });
  }

  const taskForm = document.getElementById("task-form");
  if (taskForm) {
    taskForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const payload = Object.fromEntries(new FormData(taskForm));
      state.formErrors = validateTaskForm(payload);
      if (Object.keys(state.formErrors).length) return render();
      payload.estimated_minutes = Number(payload.estimated_minutes || 0);
      payload.due_date = payload.due_date ? payload.due_date.replace("T", " ") + ":00" : null;
      if (state.formMode === "edit") {
        await api(`/tasks/${state.activeTaskId}`, { method: "PUT", body: payload });
      } else {
        await api("/tasks", { method: "POST", body: payload });
      }
      state.formMode = "create";
      state.form = emptyForm();
      resetFormAi();
      state.screen = "tasks";
      await refreshAll();
    });
  }

  const btnFormAi = document.getElementById("btn-form-ai-suggestions");
  if (btnFormAi) {
    btnFormAi.onclick = async () => {
      const form = document.getElementById("task-form");
      if (!form) return;
      const fd = new FormData(form);
      const title = String(fd.get("title") || "").trim();
      if (!title) {
        state.formErrors = { ...state.formErrors, title: "Enter a title to get AI suggestions." };
        render();
        return;
      }
      state.formErrors = {};
      const dueVal = fd.get("due_date");
      state.formAi = { loading: true, error: null, data: null };
      render();
      try {
        const body = {
          title,
          description: String(fd.get("description") || ""),
          due_date: dueVal ? String(dueVal) : "",
          priority_id: Number(fd.get("priority_id") || 2)
        };
        const res = await api("/ai/suggestions-draft", { method: "POST", body });
        state.formAi = { loading: false, error: null, data: res.data };
      } catch (err) {
        state.formAi = { loading: false, error: err instanceof Error ? err.message : "Request failed", data: null };
      }
      render();
    };
  }

  const applyAi = document.getElementById("form-ai-apply");
  if (applyAi) {
    applyAi.onclick = () => {
      const d = state.formAi?.data;
      if (!d?.category || !d?.priority || !d?.time_estimate) return;
      state.form.category_id = String(d.category.category_id);
      state.form.priority_id = String(d.priority.priority_id);
      state.form.estimated_minutes = String(d.time_estimate.estimated_minutes);
      render();
    };
  }

  const chatSend = document.getElementById("chat-send");
  if (chatSend) chatSend.onclick = () => void sendChatMessage();

  const chatInput = document.getElementById("chat-input");
  if (chatInput) {
    chatInput.onkeydown = (e) => {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        void sendChatMessage();
      }
    };
  }

  document.querySelectorAll("[data-update-status]").forEach((b) =>
    b.addEventListener("click", async () => {
      await api(`/tasks/${Number(b.dataset.updateStatus)}/status`, {
        method: "PATCH",
        body: { status_id: Number(b.dataset.statusId), note: "Updated from Task Detail" }
      });
      await refreshAll();
      state.screen = "task-detail";
      render();
    })
  );
}

async function refreshAll() {
  state.loading = true;
  render();
  try {
    const [tasksRes, summaryRes] = await Promise.all([
      api(`/tasks?sort=${encodeURIComponent(state.filters.sort)}`),
      api("/dashboard/summary")
    ]);
    state.tasks = tasksRes.data || [];
    state.summary = summaryRes.data || null;
    await refreshAI();
  } catch (err) {
    if (String(err.message).toLowerCase().includes("unauthorized")) {
      state.isAuthed = false;
      state.tasks = [];
      state.summary = null;
    } else {
      alert(err.message);
    }
  } finally {
    state.loading = false;
    render();
  }
}

async function refreshAI() {
  state.ai.insightsError = null;
  state.ai.prioritySuggestion = null;
  state.ai.categorySuggestion = null;
  state.ai.timeEstimate = null;
  state.ai.insightsTaskId = null;

  try {
    const [focus, tip] = await Promise.all([api("/ai/focus-task"), api("/ai/productivity-tip")]);
    state.ai.focusTask = focus.data?.id ? focus.data : null;
    state.ai.productivityTip = tip.data?.tip || null;

    const open = state.tasks.filter((t) => Number(t.status_id) !== 3);
    const seedTask =
      state.ai.focusTask ||
      open[0] ||
      state.tasks[0] ||
      null;
    if (!seedTask?.id) {
      return;
    }
    state.ai.insightsTaskId = seedTask.id;

    const taskId = seedTask.id;
    const [pri, cat, tim] = await Promise.all([
      api("/ai/priority", { method: "POST", body: { task_id: taskId } }),
      api("/ai/category", { method: "POST", body: { task_id: taskId } }),
      api("/ai/time-estimate", { method: "POST", body: { task_id: taskId } })
    ]);
    state.ai.prioritySuggestion = pri.data || null;
    state.ai.categorySuggestion = cat.data || null;
    state.ai.timeEstimate = tim.data || null;
  } catch (e) {
    state.ai.focusTask = state.ai.focusTask ?? null;
    state.ai.productivityTip = state.ai.productivityTip ?? null;
    state.ai.insightsError = e instanceof Error ? e.message : "AI insights could not be loaded.";
  }
}

function getFilteredLocalTasks() {
  const q = (state.filters.search || "").toLowerCase();
  return state.tasks.filter((t) => {
    const hitQ = !q || (t.title || "").toLowerCase().includes(q) || (t.description || "").toLowerCase().includes(q);
    const hitP = !state.filters.priority_id || String(t.priority_id) === String(state.filters.priority_id);
    const hitC = !state.filters.category_id || String(t.category_id) === String(state.filters.category_id);
    const hitS = !state.filters.status_id || String(t.status_id) === String(state.filters.status_id);
    return hitQ && hitP && hitC && hitS;
  });
}

function updateFilters() {
  state.filters.search = document.getElementById("task-search")?.value || "";
  state.filters.priority_id = document.getElementById("filter-priority")?.value || "";
  state.filters.status_id = document.getElementById("filter-status")?.value || "";
  state.filters.category_id = document.getElementById("filter-category")?.value || "";
  state.filters.sort = document.getElementById("sort-by")?.value || "due_date";
  render();
}

function validateTaskForm(data) {
  const errors = {};
  if (!String(data.title || "").trim()) errors.title = "Title is required.";
  if (data.due_date && new Date(data.due_date) < new Date(new Date().toDateString())) {
    errors.due_date = "Due date must be today or future.";
  }
  return errors;
}

function setAuthFormError(message) {
  const slot = document.querySelector("[data-error-for='email']");
  if (slot) slot.textContent = message;
}

function clearAuthErrors() {
  document.querySelectorAll("[data-error-for]").forEach((x) => (x.textContent = ""));
}

function countByStatus(statusId) {
  return state.tasks.filter((t) => Number(t.status_id) === Number(statusId)).length;
}

function completionPercent() {
  if (!state.tasks.length) return 0;
  return Math.round((countByStatus(3) / state.tasks.length) * 100);
}

function summaryCard(label, value) {
  return `<article class="card summary-card"><div class="label">${label}</div><div class="value">${value}</div></article>`;
}

function priorityClass(name = "") {
  const n = name.toLowerCase();
  if (n.includes("high")) return "high";
  if (n.includes("medium")) return "medium";
  return "low";
}

function statusClass(name = "") {
  const n = name.toLowerCase();
  if (n.includes("progress")) return "in-progress";
  if (n.includes("complete")) return "completed";
  return "todo";
}

function formatDueBadge(due) {
  if (!due) return "No due date";
  const today = new Date(new Date().toDateString());
  const target = new Date(due);
  const days = Math.floor((target - today) / 86400000);
  if (days === 0) return "Today";
  if (days === 1) return "Tomorrow";
  if (days > 1) return `In ${days} days`;
  return `${Math.abs(days)} days overdue`;
}

function toDateTimeLocal(value) {
  if (!value) return "";
  return String(value).slice(0, 16).replace(" ", "T");
}

function escapeHtml(str = "") {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

bootstrap();
