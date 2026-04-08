/**
 * Keyboard Shortcuts Module
 */

import { state } from './state.js';
import { newChat } from './chat.js';
import { toggleSidebar, exportCurrentChat } from './sidebar.js';
import { toggleDarkMode } from './ui.js';
import { closeMemoryPanel, toggleMemoryPanel } from './memory.js';

export function initShortcuts() {
    document.addEventListener('keydown', handleKeydown);

    document.getElementById('closeShortcuts')?.addEventListener('click', () => {
        document.getElementById('shortcutsModal').style.display = 'none';
    });
}

function handleKeydown(e) {
    const isInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);

    // Escape - close any open panel
    if (e.key === 'Escape') {
        const shortcuts = document.getElementById('shortcutsModal');
        if (shortcuts?.style.display !== 'none') {
            shortcuts.style.display = 'none';
            return;
        }
        if (state.memoryPanelOpen) {
            closeMemoryPanel();
            return;
        }
        const themeMenu = document.getElementById('themeMenu');
        if (themeMenu?.style.display !== 'none') {
            themeMenu.style.display = 'none';
            return;
        }
        return;
    }

    // Ctrl/Cmd shortcuts
    if (e.ctrlKey || e.metaKey) {
        switch (e.key.toLowerCase()) {
            case 'n': // New chat
                e.preventDefault();
                newChat();
                break;
            case 'b': // Toggle sidebar
                e.preventDefault();
                toggleSidebar();
                break;
            case 'd': // Toggle dark mode
                e.preventDefault();
                toggleDarkMode();
                break;
            case 'k': // Focus search
                e.preventDefault();
                const search = document.getElementById('searchInput');
                if (search) {
                    if (!state.sidebarOpen) toggleSidebar();
                    search.focus();
                }
                break;
            case 'u': // Upload image
                e.preventDefault();
                document.getElementById('imageUpload')?.click();
                break;
            case 'm': // Voice input (toggle)
                if (!isInput) {
                    e.preventDefault();
                    document.getElementById('voiceInputBtn')?.click();
                }
                break;
            case 'e': // Export
                e.preventDefault();
                exportCurrentChat('json');
                break;
            case '/': // Show shortcuts
                e.preventDefault();
                const modal = document.getElementById('shortcutsModal');
                if (modal) modal.style.display = modal.style.display === 'none' ? 'flex' : 'none';
                break;
        }
    }
}
