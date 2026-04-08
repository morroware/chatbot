/**
 * UI Module
 * Themes, emotions, dark mode, notifications, viewport helpers
 */

import { state } from './state.js';

// ============================================
// DARK / LIGHT MODE
// ============================================

export function initDarkMode() {
    const saved = localStorage.getItem('darkMode');
    state.darkMode = saved !== null ? saved === 'true' : true;
    applyDarkMode();

    document.getElementById('darkModeToggle')?.addEventListener('click', toggleDarkMode);
}

export function toggleDarkMode() {
    state.darkMode = !state.darkMode;
    localStorage.setItem('darkMode', String(state.darkMode));
    applyDarkMode();
    triggerHaptic('light');
}

function applyDarkMode() {
    document.documentElement.setAttribute('data-theme', state.darkMode ? 'dark' : 'light');
    const icon = document.getElementById('darkModeIcon');
    if (icon) icon.innerHTML = state.darkMode ? '&#9790;' : '&#9788;';
}

// ============================================
// THEME MANAGEMENT
// ============================================

export function applyTheme(themeName) {
    const theme = state.themes[themeName] || state.themes.default || Object.values(state.themes)[0];
    if (!theme) return;

    state.currentTheme = themeName;

    const root = document.documentElement;
    root.style.setProperty('--theme-primary', theme.primaryColor);
    root.style.setProperty('--theme-secondary', theme.secondaryColor);
    root.style.setProperty('--theme-accent', theme.accentColor);
    root.style.setProperty('--theme-background', theme.backgroundColor);
    root.style.setProperty('--theme-header-gradient', theme.headerGradient);

    const header = document.querySelector('.card-header');
    if (header) header.style.background = theme.headerGradient;

    const card = document.querySelector('.card');
    if (card) card.style.backgroundColor = theme.secondaryColor;

    const chat = document.getElementById('chatContainer');
    if (chat) chat.style.backgroundColor = theme.backgroundColor;

    const input = document.querySelector('.input-area');
    if (input) input.style.backgroundColor = theme.secondaryColor;
}

export function toggleThemeMenu() {
    const menu = document.getElementById('themeMenu');
    if (!menu) return;

    const isVisible = menu.style.display !== 'none';
    menu.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) renderThemeOptions();
}

function renderThemeOptions() {
    const container = document.getElementById('themeOptions');
    if (!container) return;
    container.innerHTML = '';

    // Emotion-theme link toggle
    const linkToggle = document.createElement('div');
    linkToggle.className = 'theme-link-toggle';
    linkToggle.innerHTML = `
        <label class="theme-link-label">
            <input type="checkbox" id="emotionThemeToggle" ${state.linkEmotionsToThemes ? 'checked' : ''}>
            <span>&#128279; Auto-theme with emotion</span>
        </label>
    `;
    container.appendChild(linkToggle);

    document.getElementById('emotionThemeToggle')?.addEventListener('change', () => {
        state.linkEmotionsToThemes = document.getElementById('emotionThemeToggle')?.checked ?? false;
        localStorage.setItem('linkEmotionsToThemes', String(state.linkEmotionsToThemes));
        triggerHaptic('light');
        showNotification(state.linkEmotionsToThemes ? 'Auto-theme enabled' : 'Auto-theme disabled');
    });

    for (const [key, theme] of Object.entries(state.themes)) {
        const option = document.createElement('div');
        option.className = `theme-option ${key === state.currentTheme ? 'active' : ''}`;
        option.addEventListener('click', () => {
            applyTheme(key);
            document.getElementById('themeMenu').style.display = 'none';
            triggerHaptic();
        });

        option.innerHTML = `
            <div class="theme-option-name">${theme.name}</div>
            <div class="theme-option-desc">${theme.description || ''}</div>
            <div class="theme-option-preview">
                <span class="theme-color-dot" style="background: ${theme.primaryColor}"></span>
                <span class="theme-color-dot" style="background: ${theme.accentColor}"></span>
                <span class="theme-color-dot" style="background: ${theme.backgroundColor}"></span>
            </div>
        `;
        container.appendChild(option);
    }
}

// ============================================
// EMOTION MANAGEMENT
// ============================================

export function updateEmotion(emotion) {
    const prev = state.currentEmotion;
    state.currentEmotion = emotion;

    const style = state.emotionStyles[emotion] || state.emotionStyles.neutral || {};

    const emotionEl = document.getElementById('currentEmotion');
    if (emotionEl) {
        emotionEl.textContent = `${style.emoji || ''} ${emotion}`;
        emotionEl.style.color = style.color || '#ffffff';
    }

    const avatar = document.getElementById('headerAvatar');
    if (avatar && style.filter && style.filter !== 'none') {
        avatar.style.filter = style.filter;
    } else if (avatar) {
        avatar.style.filter = '';
    }

    const header = document.querySelector('.card-header');
    if (header) {
        header.className = header.className.replace(/emotion-\S+/g, '').trim();
        header.classList.add(`emotion-${emotion}`);

        avatar?.classList.remove('shake-avatar', 'glow-avatar', 'intense-pulse');
        header.classList.remove('header-shake');

        if (style.shake) {
            header.classList.add('header-shake');
            triggerHaptic(style.intense ? 'heavy' : 'medium');
        }
    }

    if (style.intense) flashScreen(style.color);

    if (prev !== emotion && style.intense) {
        showEmotionNotification(emotion, style);
    }
}

function showEmotionNotification(emotion, style) {
    const notification = document.createElement('div');
    notification.className = 'emotion-notification';
    notification.innerHTML = `
        <span style="font-size: 1.5rem">${style.emoji || ''}</span>
        <span>${state.config?.general?.bot_name || 'Bot'} is ${emotion}!</span>
    `;
    notification.style.borderColor = style.color || '#ffffff';
    notification.style.color = style.color || '#ffffff';

    document.body.appendChild(notification);
    requestAnimationFrame(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translate(-50%, 0)';
    });

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translate(-50%, -20px)';
        setTimeout(() => notification.remove(), 300);
    }, 2000);
}

// ============================================
// TOKEN TRACKING
// ============================================

export function updateTokenCount(tokens) {
    if (tokens) {
        state.sessionTokens.input += tokens.input || 0;
        state.sessionTokens.output += tokens.output || 0;
    }

    const el = document.getElementById('tokenCount');
    if (el) {
        const total = state.sessionTokens.input + state.sessionTokens.output;
        if (total >= 1000000) {
            el.textContent = (total / 1000000).toFixed(1) + 'M';
        } else if (total >= 1000) {
            el.textContent = (total / 1000).toFixed(1) + 'k';
        } else {
            el.textContent = String(total);
        }
    }
}

// ============================================
// NOTIFICATIONS & HELPERS
// ============================================

export function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type === 'error' ? 'error' : ''}`;
    notification.textContent = message;
    document.body.appendChild(notification);

    requestAnimationFrame(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateX(-50%) translateY(0)';
    });

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(-50%) translateY(-20px)';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

export function triggerHaptic(intensity = 'light') {
    if (!navigator.vibrate) return;
    const patterns = { light: 10, medium: [15, 10, 15], heavy: [30, 20, 30, 20, 30] };
    navigator.vibrate(patterns[intensity] || patterns.light);
}

export function flashScreen(color) {
    const flash = document.createElement('div');
    flash.className = 'screen-flash';
    flash.style.backgroundColor = color;
    document.body.appendChild(flash);
    requestAnimationFrame(() => { flash.style.opacity = '0.3'; });
    setTimeout(() => {
        flash.style.opacity = '0';
        setTimeout(() => flash.remove(), 300);
    }, 100);
}

export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

export function formatTimestamp(date = new Date()) {
    const now = new Date();
    const diff = now - date;

    if (diff < 24 * 60 * 60 * 1000) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    if (date.getFullYear() === now.getFullYear()) {
        return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

export function preventOverscroll() {
    document.body.addEventListener('touchmove', (e) => {
        const chat = document.getElementById('chatContainer');
        if (chat && !chat.contains(e.target)) e.preventDefault();
    }, { passive: false });
}

export function adjustViewportHeight() {
    const setVH = () => {
        document.documentElement.style.setProperty('--vh', `${window.innerHeight * 0.01}px`);
    };
    setVH();
    window.addEventListener('resize', setVH);
    window.addEventListener('orientationchange', setVH);
}

export function setInputState(enabled) {
    for (const id of ['sendBtn', 'messageInput', 'imageUploadBtn', 'voiceInputBtn']) {
        const el = document.getElementById(id);
        if (el) el.disabled = !enabled;
    }

    const sendIcon = document.getElementById('sendIcon');
    const sendSpinner = document.getElementById('sendSpinner');
    if (sendIcon) sendIcon.classList.toggle('d-none', !enabled);
    if (sendSpinner) sendSpinner.classList.toggle('d-none', enabled);
}

// Make theme toggle available globally for inline onclick
window.toggleThemeMenu = toggleThemeMenu;
