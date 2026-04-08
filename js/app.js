/**
 * App Entry Point
 * Initializes all modules and wires everything together
 */

import { state } from './state.js';
import { loadConfiguration } from './config.js';
import { initDarkMode, applyTheme, updateEmotion, preventOverscroll, adjustViewportHeight } from './ui.js';
import { sendMessage, configureMarked, showWelcome, autoResizeTextarea, newChat } from './chat.js';
import { initSidebar } from './sidebar.js';
import { initMemoryPanel } from './memory.js';
import { initImageUpload, initDragDrop, initVoiceRecognition } from './media.js';
import { initShortcuts } from './shortcuts.js';

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', async () => {
    try {
        initDarkMode();
        configureMarked();

        const loaded = await loadConfiguration();
        if (!loaded) {
            console.error('Configuration failed');
            return;
        }

        // Apply initial theme and emotion
        applyTheme(state.currentTheme);
        updateEmotion(state.currentEmotion);

        // Initialize all modules
        initSidebar();
        initMemoryPanel();
        initImageUpload();
        initDragDrop();
        initVoiceRecognition();
        initShortcuts();
        setupEventListeners();

        // Show welcome screen
        showWelcome();

        // Viewport helpers
        preventOverscroll();
        adjustViewportHeight();

        // Restore link preference
        const savedLink = localStorage.getItem('linkEmotionsToThemes');
        if (savedLink !== null) state.linkEmotionsToThemes = savedLink === 'true';

    } catch (error) {
        console.error('Init error:', error);
        const title = document.getElementById('welcomeTitle');
        const msg = document.getElementById('welcomeText');
        if (title) title.textContent = 'Configuration Error';
        if (msg) msg.textContent = 'Failed to load: ' + error.message;
    }
});

// ============================================
// EVENT LISTENERS
// ============================================

function setupEventListeners() {
    const input = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');

    // Send on Enter, newline on Shift+Enter
    input?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Auto-resize textarea
    input?.addEventListener('input', () => autoResizeTextarea(input));

    sendBtn?.addEventListener('click', () => sendMessage());

    // Close theme menu on outside click
    document.addEventListener('click', (e) => {
        const switcher = document.getElementById('themeSwitcher');
        const menu = document.getElementById('themeMenu');
        if (switcher && menu && !switcher.contains(e.target) && menu.style.display !== 'none') {
            menu.style.display = 'none';
        }
    });

    // Settings toggle (placeholder - could open admin or a settings panel)
    document.getElementById('settingsToggle')?.addEventListener('click', () => {
        window.open('admin-login.html', '_blank');
    });
}

// ============================================
// SERVICE WORKER (optional PWA)
// ============================================

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {
        // SW registration is optional
    });
}
