/**
 * Memory Module
 * Long-term memory panel and automatic extraction
 */

import { state } from './state.js';
import * as api from './api.js';
import { showNotification, triggerHaptic } from './ui.js';

// ============================================
// MEMORY PANEL
// ============================================

export function initMemoryPanel() {
    document.getElementById('memoryBtn')?.addEventListener('click', toggleMemoryPanel);
    document.getElementById('closeMemoryPanel')?.addEventListener('click', () => closeMemoryPanel());
    document.getElementById('addMemoryBtn')?.addEventListener('click', handleAddMemory);

    document.getElementById('newMemoryInput')?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') handleAddMemory();
    });
}

export function toggleMemoryPanel() {
    state.memoryPanelOpen = !state.memoryPanelOpen;
    const panel = document.getElementById('memoryPanel');
    if (panel) {
        panel.style.display = state.memoryPanelOpen ? 'flex' : 'none';
        if (state.memoryPanelOpen) refreshMemoryList();
    }
}

export function closeMemoryPanel() {
    state.memoryPanelOpen = false;
    const panel = document.getElementById('memoryPanel');
    if (panel) panel.style.display = 'none';
}

async function refreshMemoryList() {
    const container = document.getElementById('memoryList');
    if (!container) return;

    container.innerHTML = '<div class="memory-loading">Loading memories...</div>';

    try {
        const memories = await api.fetchMemories();
        renderMemoryList(memories, container);
    } catch (e) {
        container.innerHTML = '<div class="memory-empty">Failed to load memories</div>';
    }
}

function renderMemoryList(memories, container) {
    container.innerHTML = '';

    if (memories.length === 0) {
        container.innerHTML = '<div class="memory-empty">No memories yet. The bot will learn about you as you chat!</div>';
        return;
    }

    // Group by type
    const groups = {};
    for (const mem of memories) {
        const type = mem.fact_type || 'general';
        if (!groups[type]) groups[type] = [];
        groups[type].push(mem);
    }

    for (const [type, facts] of Object.entries(groups)) {
        const groupEl = document.createElement('div');
        groupEl.className = 'memory-group';

        const label = type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' ');
        const icon = getTypeIcon(type);
        groupEl.innerHTML = `<div class="memory-group-label">${icon} ${label}</div>`;

        for (const mem of facts) {
            const item = document.createElement('div');
            item.className = 'memory-item';
            item.innerHTML = `
                <span class="memory-fact">${escapeHtml(mem.fact)}</span>
                <button class="memory-delete-btn" title="Forget this">&times;</button>
            `;

            item.querySelector('.memory-delete-btn')?.addEventListener('click', async () => {
                await api.deleteMemoryApi(mem.id);
                item.remove();
                showNotification('Memory removed');
            });

            groupEl.appendChild(item);
        }

        container.appendChild(groupEl);
    }
}

function getTypeIcon(type) {
    const icons = {
        general: '&#128161;',
        preference: '&#10084;',
        personal: '&#128100;',
        interest: '&#11088;',
        context: '&#128196;',
        style: '&#127912;',
    };
    return icons[type] || '&#128161;';
}

async function handleAddMemory() {
    const input = document.getElementById('newMemoryInput');
    const typeSelect = document.getElementById('newMemoryType');

    const fact = input?.value.trim();
    const type = typeSelect?.value || 'general';

    if (!fact) return;

    try {
        await api.addMemory(fact, type);
        if (input) input.value = '';
        refreshMemoryList();
        showNotification('Memory saved');
        triggerHaptic('light');
    } catch (e) {
        showNotification('Failed to save memory', 'error');
    }
}

// ============================================
// AUTOMATIC MEMORY EXTRACTION
// ============================================

export async function triggerMemoryExtraction() {
    if (state.conversationHistory.length < 4) return;

    try {
        const recentMessages = state.conversationHistory.slice(-8);
        const existingMemories = await api.fetchMemories();

        const newFacts = await api.extractMemories(recentMessages, existingMemories);

        if (newFacts.length > 0) {
            console.log(`Extracted ${newFacts.length} new memories`);
        }
    } catch (e) {
        console.warn('Memory extraction failed:', e);
    }
}

// ============================================
// HELPERS
// ============================================

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
