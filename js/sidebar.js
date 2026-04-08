/**
 * Sidebar Module
 * Conversation list, search, sidebar toggle
 */

import { state } from './state.js';
import * as api from './api.js';
import { loadConversation, newChat } from './chat.js';
import { showNotification, triggerHaptic } from './ui.js';

let searchDebounceTimer = null;

// ============================================
// SIDEBAR TOGGLE
// ============================================

export function initSidebar() {
    const saved = localStorage.getItem('sidebarOpen');
    state.sidebarOpen = saved !== null ? saved === 'true' : window.innerWidth > 768;
    applySidebarState();

    document.getElementById('sidebarToggle')?.addEventListener('click', toggleSidebar);
    document.getElementById('sidebarCloseBtn')?.addEventListener('click', () => {
        state.sidebarOpen = false;
        applySidebarState();
    });
    document.getElementById('newChatBtn')?.addEventListener('click', () => {
        newChat();
        triggerHaptic();
        // On mobile, close sidebar after creating new chat
        if (window.innerWidth <= 768) {
            state.sidebarOpen = false;
            applySidebarState();
        }
    });

    // Search
    const searchInput = document.getElementById('searchInput');
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            refreshConversationList(searchInput.value.trim());
        }, 300);
    });

    refreshConversationList();
}

export function toggleSidebar() {
    state.sidebarOpen = !state.sidebarOpen;
    applySidebarState();
    triggerHaptic('light');
}

function applySidebarState() {
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('mainContent');
    if (sidebar) sidebar.classList.toggle('open', state.sidebarOpen);
    if (main) main.classList.toggle('sidebar-open', state.sidebarOpen);
    localStorage.setItem('sidebarOpen', String(state.sidebarOpen));
}

// ============================================
// CONVERSATION LIST
// ============================================

export async function refreshConversationList(search = null) {
    const container = document.getElementById('conversationList');
    if (!container) return;

    try {
        const conversations = await api.fetchConversations(search);
        state.conversations = conversations;
        renderConversationList(conversations, container);
    } catch (e) {
        console.warn('Failed to load conversations:', e);
    }
}

function renderConversationList(conversations, container) {
    container.innerHTML = '';

    if (conversations.length === 0) {
        container.innerHTML = '<div class="sidebar-empty">No conversations yet</div>';
        return;
    }

    // Group by date
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const lastWeek = new Date(today);
    lastWeek.setDate(lastWeek.getDate() - 7);

    const groups = { 'Today': [], 'Yesterday': [], 'This Week': [], 'Older': [] };

    for (const conv of conversations) {
        const date = new Date(conv.updated_at + 'Z');
        if (date >= today) groups['Today'].push(conv);
        else if (date >= yesterday) groups['Yesterday'].push(conv);
        else if (date >= lastWeek) groups['This Week'].push(conv);
        else groups['Older'].push(conv);
    }

    for (const [label, convs] of Object.entries(groups)) {
        if (convs.length === 0) continue;

        const groupEl = document.createElement('div');
        groupEl.className = 'conversation-group';
        groupEl.innerHTML = `<div class="conversation-group-label">${label}</div>`;

        for (const conv of convs) {
            const item = createConversationItem(conv);
            groupEl.appendChild(item);
        }

        container.appendChild(groupEl);
    }
}

function createConversationItem(conv) {
    const item = document.createElement('div');
    item.className = `conversation-item ${conv.id === state.currentConversationId ? 'active' : ''}`;
    item.dataset.id = conv.id;

    const pinIcon = conv.pinned ? '<span class="pin-icon">&#128204;</span>' : '';
    const tokenInfo = (conv.total_tokens_in + conv.total_tokens_out) > 0
        ? `<span class="conv-tokens">${formatTokens(conv.total_tokens_in + conv.total_tokens_out)}</span>`
        : '';

    item.innerHTML = `
        <div class="conv-main" title="${escapeAttr(conv.title)}">
            ${pinIcon}
            <span class="conv-title">${escapeHtmlInline(conv.title)}</span>
        </div>
        <div class="conv-meta">
            <span class="conv-count">${conv.message_count || 0} msgs</span>
            ${tokenInfo}
        </div>
        <div class="conv-actions">
            <button class="conv-action-btn" data-action="pin" title="${conv.pinned ? 'Unpin' : 'Pin'}">&#128204;</button>
            <button class="conv-action-btn" data-action="rename" title="Rename">&#9998;</button>
            <button class="conv-action-btn conv-action-delete" data-action="delete" title="Delete">&#128465;</button>
        </div>
    `;

    // Click to load
    item.querySelector('.conv-main').addEventListener('click', async () => {
        await loadConversation(conv.id);
        refreshConversationList();
        // Close sidebar on mobile
        if (window.innerWidth <= 768) {
            state.sidebarOpen = false;
            applySidebarState();
        }
    });

    // Pin
    item.querySelector('[data-action="pin"]')?.addEventListener('click', async (e) => {
        e.stopPropagation();
        await api.updateConversation(conv.id, { pinned: conv.pinned ? 0 : 1 });
        refreshConversationList();
    });

    // Rename
    item.querySelector('[data-action="rename"]')?.addEventListener('click', async (e) => {
        e.stopPropagation();
        const newTitle = prompt('Rename conversation:', conv.title);
        if (newTitle && newTitle.trim()) {
            await api.updateConversation(conv.id, { title: newTitle.trim() });
            refreshConversationList();
        }
    });

    // Delete
    item.querySelector('[data-action="delete"]')?.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm(`Delete "${conv.title}"?`)) return;
        await api.deleteConversation(conv.id);
        if (state.currentConversationId === conv.id) {
            newChat();
        }
        refreshConversationList();
        showNotification('Conversation deleted');
    });

    return item;
}

// ============================================
// EXPORT
// ============================================

export async function exportCurrentChat(format = 'json') {
    if (!state.currentConversationId) {
        showNotification('No active conversation to export', 'error');
        return;
    }

    try {
        if (format === 'json') {
            const data = await api.exportConversation(state.currentConversationId, 'json');
            downloadFile(JSON.stringify(data, null, 2), 'chat-export.json', 'application/json');
        } else if (format === 'markdown') {
            const text = await api.exportConversation(state.currentConversationId, 'markdown');
            downloadFile(text, 'chat-export.md', 'text/markdown');
        } else {
            const text = await api.exportConversation(state.currentConversationId, 'txt');
            downloadFile(text, 'chat-export.txt', 'text/plain');
        }
        showNotification(`Exported as ${format.toUpperCase()}`);
        triggerHaptic();
    } catch (e) {
        showNotification('Export failed', 'error');
    }
}

function downloadFile(content, filename, mimeType) {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ============================================
// HELPERS
// ============================================

function formatTokens(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

function escapeHtmlInline(text) {
    const div = document.createElement('span');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttr(text) {
    return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
