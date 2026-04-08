/**
 * Scheduled Tasks Module
 * Create and manage automated AI tasks
 */

import { state } from './state.js';
import { showNotification, escapeHtml } from './ui.js';

// ============================================
// PANEL INIT
// ============================================

export function initTasksPanel() {
    const btn = document.getElementById('tasksBtn');
    const closeBtn = document.getElementById('closeTasks');
    const panel = document.getElementById('tasksPanel');

    btn?.addEventListener('click', () => toggleTasksPanel());
    closeBtn?.addEventListener('click', () => closeTasksPanel());

    // New task form
    document.getElementById('createTaskBtn')?.addEventListener('click', () => openTaskForm());
    document.getElementById('cancelTaskForm')?.addEventListener('click', () => closeTaskForm());
    document.getElementById('saveTaskBtn')?.addEventListener('click', () => saveTask());

    // Schedule type change
    document.getElementById('taskScheduleType')?.addEventListener('change', updateScheduleHint);

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (panel && !panel.contains(e.target) && btn && !btn.contains(e.target)) {
            if (state.tasksPanelOpen) closeTasksPanel();
        }
    });
}

export function toggleTasksPanel() {
    if (state.tasksPanelOpen) closeTasksPanel();
    else openTasksPanel();
}

function openTasksPanel() {
    const panel = document.getElementById('tasksPanel');
    if (!panel) return;
    panel.classList.add('open');
    state.tasksPanelOpen = true;
    document.getElementById('tasksBtn')?.classList.add('active');
    loadTasks();
}

function closeTasksPanel() {
    const panel = document.getElementById('tasksPanel');
    if (!panel) return;
    panel.classList.remove('open');
    state.tasksPanelOpen = false;
    document.getElementById('tasksBtn')?.classList.remove('active');
    closeTaskForm();
}

// ============================================
// LOAD & RENDER TASKS
// ============================================

async function loadTasks() {
    try {
        const res = await fetch('api-tasks.php?action=list');
        const data = await res.json();
        if (data.success) {
            state.scheduledTasks = data.tasks || [];
            renderTaskList();
        }
    } catch (e) {
        console.error('Tasks load error:', e);
    }
}

function renderTaskList() {
    const container = document.getElementById('taskList');
    if (!container) return;

    const tasks = state.scheduledTasks || [];

    if (!tasks.length) {
        container.innerHTML = `
            <div class="kb-empty">
                <div class="kb-empty-icon">⏰</div>
                <div class="kb-empty-text">No scheduled tasks</div>
                <div class="kb-empty-sub">Create tasks that run automatically on a schedule</div>
            </div>`;
        updateTasksBadge(0);
        return;
    }

    container.innerHTML = tasks.map(task => createTaskCard(task)).join('');

    // Bind events
    container.querySelectorAll('[data-run-task]').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); runTask(parseInt(btn.dataset.runTask)); });
    });
    container.querySelectorAll('[data-delete-task]').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); deleteTask(parseInt(btn.dataset.deleteTask)); });
    });
    container.querySelectorAll('[data-toggle-task]').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); toggleTask(parseInt(btn.dataset.toggleTask)); });
    });
    container.querySelectorAll('[data-edit-task]').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); editTask(parseInt(btn.dataset.editTask)); });
    });

    updateTasksBadge(tasks.filter(t => t.enabled).length);
}

function createTaskCard(task) {
    const enabled = task.enabled == 1;
    const nextRun = task.next_run ? new Date(task.next_run).toLocaleString() : '—';
    const lastRun = task.last_run ? new Date(task.last_run).toLocaleString() : 'Never';
    const scheduleLabel = getScheduleLabel(task.schedule_type, task.schedule_value);
    const statusClass = enabled ? 'enabled' : 'disabled';

    return `
        <div class="task-card ${statusClass}" data-task-id="${task.id}">
            <div class="task-header">
                <span class="task-icon">${enabled ? '▶️' : '⏸️'}</span>
                <span class="task-name">${escapeHtml(task.name)}</span>
                <div class="task-actions">
                    <button class="kb-btn-icon" data-run-task="${task.id}" title="Run now">⚡</button>
                    <button class="kb-btn-icon" data-edit-task="${task.id}" title="Edit">✏️</button>
                    <button class="kb-btn-icon" data-toggle-task="${task.id}" title="${enabled ? 'Disable' : 'Enable'}">${enabled ? '⏸️' : '▶️'}</button>
                    <button class="kb-btn-icon danger" data-delete-task="${task.id}" title="Delete">🗑️</button>
                </div>
            </div>
            <div class="task-body">
                ${task.description ? `<div class="task-desc">${escapeHtml(task.description)}</div>` : ''}
                <div class="task-prompt-preview">${escapeHtml(task.prompt.substring(0, 120))}${task.prompt.length > 120 ? '…' : ''}</div>
                <div class="task-meta">
                    <span class="task-schedule">🕐 ${escapeHtml(scheduleLabel)}</span>
                    <span class="task-next">Next: ${escapeHtml(nextRun)}</span>
                    <span class="task-runs">Runs: ${task.run_count}</span>
                </div>
                ${task.last_result ? `
                    <div class="task-last-result">
                        <span class="task-last-run-label">Last run (${escapeHtml(lastRun)}):</span>
                        <div class="task-result-text">${escapeHtml(task.last_result.substring(0, 200))}${task.last_result.length > 200 ? '…' : ''}</div>
                    </div>` : ''}
            </div>
        </div>`;
}

function getScheduleLabel(type, value) {
    switch (type) {
        case 'once': return value ? `Once at ${new Date(value).toLocaleString()}` : 'Once';
        case 'interval': return `Every ${value} minute${value == 1 ? '' : 's'}`;
        case 'daily': return `Daily at ${value || '09:00'}`;
        case 'weekly': return 'Weekly';
        case 'monthly': return 'Monthly';
        default: return type;
    }
}

function updateTasksBadge(count) {
    const badge = document.getElementById('tasksBadge');
    if (!badge) return;
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// ============================================
// TASK FORM
// ============================================

let editingTaskId = null;

function openTaskForm(taskData = null) {
    const form = document.getElementById('taskForm');
    if (!form) return;
    form.style.display = 'block';
    editingTaskId = taskData?.id || null;

    // Reset or populate
    document.getElementById('taskName').value = taskData?.name || '';
    document.getElementById('taskDescription').value = taskData?.description || '';
    document.getElementById('taskPrompt').value = taskData?.prompt || '';
    document.getElementById('taskScheduleType').value = taskData?.schedule_type || 'daily';
    document.getElementById('taskScheduleValue').value = taskData?.schedule_value || '';
    document.getElementById('taskModel').value = taskData?.model || '';
    document.getElementById('saveTaskBtn').textContent = editingTaskId ? 'Update Task' : 'Create Task';

    updateScheduleHint();
    document.getElementById('taskName').focus();
}

function closeTaskForm() {
    const form = document.getElementById('taskForm');
    if (form) form.style.display = 'none';
    editingTaskId = null;
}

function editTask(id) {
    const task = (state.scheduledTasks || []).find(t => t.id === id);
    if (task) openTaskForm(task);
}

function updateScheduleHint() {
    const type = document.getElementById('taskScheduleType')?.value;
    const hint = document.getElementById('scheduleValueHint');
    const input = document.getElementById('taskScheduleValue');
    if (!hint || !input) return;

    const hints = {
        once: 'Date/time to run (e.g., 2026-04-15 14:30:00)',
        interval: 'Minutes between runs (e.g., 30)',
        daily: 'Time of day HH:MM (e.g., 09:00)',
        weekly: 'Optional: leave blank to use same time as creation',
        monthly: 'Optional: leave blank to use same day as creation',
    };

    hint.textContent = hints[type] || '';
    input.placeholder = hints[type] || '';
}

async function saveTask() {
    const name = document.getElementById('taskName')?.value.trim();
    const prompt = document.getElementById('taskPrompt')?.value.trim();
    const scheduleType = document.getElementById('taskScheduleType')?.value;
    const scheduleValue = document.getElementById('taskScheduleValue')?.value.trim();

    if (!name) { showNotification('Task name is required', 'error'); return; }
    if (!prompt) { showNotification('Task prompt is required', 'error'); return; }

    const taskData = {
        name,
        description: document.getElementById('taskDescription')?.value.trim() || '',
        prompt,
        schedule_type: scheduleType,
        schedule_value: scheduleValue,
        model: document.getElementById('taskModel')?.value.trim() || '',
    };

    if (editingTaskId) taskData.id = editingTaskId;

    const action = editingTaskId ? 'update' : 'create';
    const saveBtn = document.getElementById('saveTaskBtn');
    if (saveBtn) saveBtn.disabled = true;

    try {
        const res = await fetch(`api-tasks.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(taskData),
        });
        const data = await res.json();

        if (data.success) {
            showNotification(editingTaskId ? 'Task updated' : 'Task created', 'success');
            closeTaskForm();
            await loadTasks();
        } else {
            showNotification('Error: ' + data.error, 'error');
        }
    } catch (e) {
        showNotification('Error: ' + e.message, 'error');
    } finally {
        if (saveBtn) saveBtn.disabled = false;
    }
}

// ============================================
// TASK OPERATIONS
// ============================================

async function runTask(id) {
    const task = (state.scheduledTasks || []).find(t => t.id === id);
    const name = task?.name || 'task';

    showNotification(`Running "${name}"...`, 'info');

    try {
        const res = await fetch('api-tasks.php?action=run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        const data = await res.json();

        if (data.success) {
            const preview = (data.result?.output || '').substring(0, 100);
            showNotification(`✅ Task ran: "${preview || 'done'}"`, 'success');
            await loadTasks();
        } else {
            showNotification('Task failed: ' + (data.result?.error || data.error), 'error');
        }
    } catch (e) {
        showNotification('Error: ' + e.message, 'error');
    }
}

async function deleteTask(id) {
    const task = (state.scheduledTasks || []).find(t => t.id === id);
    if (!confirm(`Delete task "${task?.name || 'this task'}"?`)) return;

    try {
        const res = await fetch('api-tasks.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        const data = await res.json();
        if (data.success) {
            showNotification('Task deleted', 'success');
            await loadTasks();
        } else {
            showNotification('Error: ' + data.error, 'error');
        }
    } catch (e) {
        showNotification('Error: ' + e.message, 'error');
    }
}

async function toggleTask(id) {
    try {
        const res = await fetch('api-tasks.php?action=toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        const data = await res.json();
        if (data.success) {
            await loadTasks();
        }
    } catch (e) {
        showNotification('Error: ' + e.message, 'error');
    }
}
