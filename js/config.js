/**
 * Configuration Loading & Processing
 */

import { state } from './state.js';
import * as api from './api.js';

export async function loadConfiguration() {
    const data = await api.fetchConfig();

    state.config = data.config;
    state.emotions = data.emotions;
    state.emotionThemeMap = data.emotion_theme_map || {};
    state.availableModels = data.models || {};

    const gen = state.config.general;
    state.linkEmotionsToThemes = gen.link_emotions_to_themes === 'true' || gen.link_emotions_to_themes === true;
    state.currentEmotion = gen.default_emotion || 'neutral';
    state.currentTheme = gen.default_theme || 'default';
    state.currentModel = gen.model || 'claude-sonnet-4-6';

    validateConfiguration();
    processThemes(data.themes);
    processEmotions();
    populateModelSelector();
    updatePageWithConfig();

    return true;
}

function validateConfiguration() {
    const required = {
        general: ['bot_name', 'bot_title', 'default_emotion', 'default_theme'],
        personality: ['base_description', 'speaking_style'],
    };

    for (const [section, fields] of Object.entries(required)) {
        if (!state.config[section]) throw new Error(`Missing config section: ${section}`);
        for (const field of fields) {
            if (!state.config[section][field]) throw new Error(`Missing: ${section}.${field}`);
        }
    }
}

function processThemes(themes) {
    state.themes = {};
    for (const [key, theme] of Object.entries(themes)) {
        state.themes[key] = {
            name: theme.name,
            primaryColor: theme.primary_color,
            secondaryColor: theme.secondary_color,
            accentColor: theme.accent_color,
            backgroundColor: theme.background_color,
            headerGradient: theme.header_gradient,
            avatarFilter: theme.avatar_filter,
            description: theme.description,
        };
    }

    if (!state.themes[state.config.general.default_theme]) {
        state.config.general.default_theme = Object.keys(state.themes)[0] || 'default';
    }
}

function processEmotions() {
    state.emotionStyles = {};
    for (const [key, emotion] of Object.entries(state.emotions)) {
        state.emotionStyles[key] = {
            color: emotion.color,
            emoji: emotion.emoji,
            filter: emotion.filter,
            shake: emotion.shake === 'true' || emotion.shake === true,
            glow: emotion.glow === 'true' || emotion.glow === true,
            intense: emotion.intense === 'true' || emotion.intense === true || emotion.intense === '1',
            theme: state.emotionThemeMap[key] || null,
        };
    }

    if (!state.emotions[state.config.general.default_emotion]) {
        state.config.general.default_emotion = Object.keys(state.emotions)[0] || 'neutral';
    }
}

function populateModelSelector() {
    const selector = document.getElementById('modelSelector');
    if (!selector) return;

    selector.innerHTML = '';

    for (const [key, model] of Object.entries(state.availableModels)) {
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = model.name;
        if (key === state.currentModel || state.config.general.model?.includes(key)) {
            opt.selected = true;
        }
        selector.appendChild(opt);
    }

    // If no models from config, add a default
    if (selector.options.length === 0) {
        const opt = document.createElement('option');
        opt.value = state.config.general.model || 'claude-sonnet-4-6';
        opt.textContent = 'Default Model';
        opt.selected = true;
        selector.appendChild(opt);
    }

    selector.addEventListener('change', () => {
        state.currentModel = selector.value;
        localStorage.setItem('selectedModel', state.currentModel);
    });

    // Restore saved model
    const savedModel = localStorage.getItem('selectedModel');
    if (savedModel) {
        for (const opt of selector.options) {
            if (opt.value === savedModel) {
                opt.selected = true;
                state.currentModel = savedModel;
                break;
            }
        }
    }
}

function updatePageWithConfig() {
    const gen = state.config.general;

    const textUpdates = {
        pageTitle: gen.bot_title,
        headerTitle: gen.bot_title,
        botNameEmotion: gen.bot_name,
        welcomeTitle: gen.welcome_title,
        welcomeText: gen.welcome_message,
        footerText: gen.footer_text,
    };

    for (const [id, value] of Object.entries(textUpdates)) {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '';
    }

    const appTitle = document.getElementById('appTitle');
    const metaDesc = document.getElementById('metaDescription');
    if (appTitle) appTitle.content = gen.bot_title;
    if (metaDesc) metaDesc.content = gen.bot_description || '';

    const avatarSrc = gen.avatar_image || 'avatar.svg';
    for (const id of ['headerAvatar', 'welcomeAvatar', 'typingAvatar']) {
        const el = document.getElementById(id);
        if (el) {
            el.src = avatarSrc;
            el.alt = gen.bot_name;
        }
    }

    const msgInput = document.getElementById('messageInput');
    if (msgInput) msgInput.placeholder = `Message ${gen.bot_name}...`;
}
