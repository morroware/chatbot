let configData = null;
let emotionsData = null;
let themesData = null;
let emotionThemeMap = {};
let modelsData = {};
let modelIdsData = {};

// Check authentication on load
window.addEventListener('DOMContentLoaded', async () => {
    try {
        const response = await fetch('admin-auth.php');
        const data = await response.json();
        
        if (!data.logged_in) {
            window.location.href = 'admin-login.html';
            return;
        }
        
        await loadConfigurations();
    } catch (error) {
        showMessage('Authentication check failed: ' + error.message, 'danger');
    }
});

async function loadConfigurations() {
    try {
        const response = await fetch('admin-get-config.php');
        const data = await response.json();
        
        if (!data.success) {
            showMessage('Failed to load configuration: ' + data.error, 'danger');
            return;
        }
        
        configData = data.config;
        emotionsData = data.emotions;
        themesData = data.themes;
        emotionThemeMap = data.emotion_theme_map || {};
        modelsData = data.config.models || configData.models || {};
        modelIdsData = data.config.model_ids || configData.model_ids || {};
        
        populateForms();
        showMessage('Configuration loaded successfully', 'success');
    } catch (error) {
        showMessage('Error loading configuration: ' + error.message, 'danger');
    }
}

function populateForms() {
    // General settings
    const generalForm = document.getElementById('generalForm');
    if (configData.general) {
        Object.keys(configData.general).forEach(key => {
            const input = generalForm.querySelector(`[name="${key}"]`);
            if (input && configData.general[key] !== undefined) {
                if (input.type === 'checkbox') {
                    input.checked = configData.general[key] === 'true' || configData.general[key] === true;
                } else {
                    input.value = configData.general[key];
                }
            }
        });
    }
    
    // Personality settings
    const personalityForm = document.getElementById('personalityForm');
    if (configData.personality) {
        Object.keys(configData.personality).forEach(key => {
            const input = personalityForm.querySelector(`[name="${key}"]`);
            if (input && configData.personality[key] !== undefined) {
                input.value = configData.personality[key];
            }
        });
    }
    
    // API settings
    const apiForm = document.getElementById('apiForm');
    if (configData.api) {
        const endpointInput = apiForm.querySelector('[name="endpoint"]');
        const versionInput = apiForm.querySelector('[name="anthropic_version"]');
        const apiKeyInput = apiForm.querySelector('[name="api_key"]');
        
        if (endpointInput) endpointInput.value = configData.api.endpoint || 'https://api.anthropic.com/v1/messages';
        if (versionInput) versionInput.value = configData.api.anthropic_version || '2023-06-01';
        if (apiKeyInput) {
            if (configData.api.api_key_display) {
                apiKeyInput.placeholder = `Current: ${configData.api.api_key_display} (leave blank to keep)`;
            } else {
                apiKeyInput.placeholder = 'Enter your Anthropic API key';
            }
        }
    }
    
    // Admin settings
    const adminForm = document.getElementById('adminForm');
    if (configData.admin) {
        const usernameInput = adminForm.querySelector('[name="username"]');
        if (usernameInput) usernameInput.value = configData.admin.username || 'admin';
    }
    
    // Render emotions, themes, and emotion-theme mappings
    renderEmotions();
    renderThemes();
    renderEmotionThemeMappings();
}

function renderEmotions() {
    const container = document.getElementById('emotionsList');
    container.innerHTML = '';
    
    Object.keys(emotionsData).forEach(key => {
        const emotion = emotionsData[key];
        const div = document.createElement('div');
        div.className = 'emotion-item';
        div.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 style="color: ${emotion.color}">${emotion.emoji} ${key}</h5>
                <button type="button" class="btn btn-remove" onclick="removeEmotion('${key}')">Remove</button>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Label</label>
                    <input type="text" class="form-control" data-emotion="${key}" data-field="label" value="${emotion.label || ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Emoji</label>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-secondary emoji-preview" style="font-size: 2rem; padding: 0.5rem 1rem; min-width: 60px;" onclick="openEmojiPicker('${key}')">${emotion.emoji || '😀'}</button>
                        <input type="text" class="form-control emoji-input" data-emotion="${key}" data-field="emoji" value="${emotion.emoji || ''}" readonly style="flex: 1;">
                    </div>
                </div>
            </div>
            <label class="form-label">Description</label>
            <input type="text" class="form-control" data-emotion="${key}" data-field="description" value="${emotion.description || ''}">
            
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control color-picker" data-emotion="${key}" data-field="color" value="${emotion.color || '#ffffff'}" style="width: 80px; height: 45px; padding: 2px;">
                        <input type="text" class="form-control color-text" data-emotion="${key}" data-field="color" value="${emotion.color || '#ffffff'}" style="flex: 1;">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter</label>
                    <input type="text" class="form-control" data-emotion="${key}" data-field="filter" value="${emotion.filter || 'brightness(1.0)'}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Shake</label>
                    <select class="form-select" data-emotion="${key}" data-field="shake">
                        <option value="false" ${!emotion.shake || emotion.shake === 'false' ? 'selected' : ''}>No</option>
                        <option value="true" ${emotion.shake === 'true' || emotion.shake === true ? 'selected' : ''}>Yes</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Glow</label>
                    <select class="form-select" data-emotion="${key}" data-field="glow">
                        <option value="false" ${!emotion.glow || emotion.glow === 'false' ? 'selected' : ''}>No</option>
                        <option value="true" ${emotion.glow === 'true' || emotion.glow === true ? 'selected' : ''}>Yes</option>
                    </select>
                </div>
            </div>
        `;
        container.appendChild(div);
    });
    
    // Sync color pickers with text inputs
    syncColorInputs();
}

function renderThemes() {
    const container = document.getElementById('themesList');
    container.innerHTML = '';
    
    Object.keys(themesData).forEach(key => {
        const theme = themesData[key];
        const div = document.createElement('div');
        div.className = 'theme-item';
        div.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 style="color: ${theme.primary_color}">${theme.name}</h5>
                <button type="button" class="btn btn-remove" onclick="removeTheme('${key}')">Remove</button>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Theme Name</label>
                    <input type="text" class="form-control" data-theme="${key}" data-field="name" value="${theme.name || ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" data-theme="${key}" data-field="description" value="${theme.description || ''}">
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <label class="form-label">Primary Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control color-picker" data-theme="${key}" data-field="primary_color" value="${theme.primary_color || '#ffffff'}" style="width: 60px; height: 45px; padding: 2px;">
                        <input type="text" class="form-control color-text" data-theme="${key}" data-field="primary_color" value="${theme.primary_color || '#ffffff'}" style="flex: 1;">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Secondary Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control color-picker" data-theme="${key}" data-field="secondary_color" value="${theme.secondary_color || '#eeeeee'}" style="width: 60px; height: 45px; padding: 2px;">
                        <input type="text" class="form-control color-text" data-theme="${key}" data-field="secondary_color" value="${theme.secondary_color || '#eeeeee'}" style="flex: 1;">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Accent Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control color-picker" data-theme="${key}" data-field="accent_color" value="${theme.accent_color || '#dddddd'}" style="width: 60px; height: 45px; padding: 2px;">
                        <input type="text" class="form-control color-text" data-theme="${key}" data-field="accent_color" value="${theme.accent_color || '#dddddd'}" style="flex: 1;">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Background Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control color-picker" data-theme="${key}" data-field="background_color" value="${theme.background_color || '#cccccc'}" style="width: 60px; height: 45px; padding: 2px;">
                        <input type="text" class="form-control color-text" data-theme="${key}" data-field="background_color" value="${theme.background_color || '#cccccc'}" style="flex: 1;">
                    </div>
                </div>
            </div>
            <label class="form-label">Header Gradient</label>
            <input type="text" class="form-control" data-theme="${key}" data-field="header_gradient" value="${theme.header_gradient || 'linear-gradient(135deg, #ffffff 0%, #eeeeee 100%)'}">
            
            <label class="form-label">Avatar Filter</label>
            <input type="text" class="form-control" data-theme="${key}" data-field="avatar_filter" value="${theme.avatar_filter || 'brightness(1.0)'}">
        `;
        container.appendChild(div);
    });
    
    // Sync color pickers with text inputs
    syncColorInputs();
}

function renderEmotionThemeMappings() {
    const container = document.getElementById('emotionThemeMappingList');
    if (!container) return;
    
    container.innerHTML = '';
    
    const themeOptions = Object.keys(themesData).map(key => {
        return `<option value="${key}">${themesData[key].name}</option>`;
    }).join('');
    
    Object.keys(emotionsData).forEach(emotionKey => {
        const emotion = emotionsData[emotionKey];
        const mappedTheme = emotionThemeMap[emotionKey] || '';
        
        const div = document.createElement('div');
        div.className = 'emotion-theme-mapping-item';
        div.innerHTML = `
            <div class="row align-items-center">
                <div class="col-md-4">
                    <span class="emotion-badge" style="background: ${emotion.color}">
                        ${emotion.emoji} ${emotionKey}
                    </span>
                </div>
                <div class="col-md-1 text-center">
                    <span style="font-size: 1.5rem; color: #00ff88;">â†’</span>
                </div>
                <div class="col-md-7">
                    <select class="form-select" data-mapping-emotion="${emotionKey}">
                        <option value="">No theme (use default)</option>
                        ${themeOptions}
                    </select>
                </div>
            </div>
        `;
        
        container.appendChild(div);
        
        // Set the current value, but validate theme still exists
        const select = div.querySelector('select');
        if (mappedTheme) {
            if (themesData[mappedTheme]) {
                select.value = mappedTheme;
            } else {
                // Theme was deleted, clear the mapping
                emotionThemeMap[emotionKey] = '';
                select.value = '';
            }
        }
    });
}

function addEmotion() {
    const key = prompt('Enter emotion key (lowercase, no spaces):');
    if (!key || emotionsData[key]) {
        alert('Invalid or duplicate emotion key');
        return;
    }
    
    emotionsData[key] = {
        label: key.toUpperCase(),
        description: 'New emotion',
        color: '#ffffff',
        emoji: '😀',
        filter: 'brightness(1.0)',
        shake: 'false',
        glow: 'false'
    };
    
    renderEmotions();
    renderEmotionThemeMappings();
}

function removeEmotion(key) {
    if (confirm(`Remove emotion "${key}"?`)) {
        delete emotionsData[key];
        delete emotionThemeMap[key];
        renderEmotions();
        renderEmotionThemeMappings();
    }
}

function addTheme() {
    const key = prompt('Enter theme key (lowercase, no spaces):');
    if (!key || themesData[key]) {
        alert('Invalid or duplicate theme key');
        return;
    }
    
    themesData[key] = {
        name: 'New Theme',
        primary_color: '#ffffff',
        secondary_color: '#eeeeee',
        accent_color: '#dddddd',
        background_color: '#cccccc',
        header_gradient: 'linear-gradient(135deg, #ffffff 0%, #eeeeee 100%)',
        avatar_filter: 'brightness(1.0)',
        description: 'New theme'
    };
    
    renderThemes();
    renderEmotionThemeMappings();
}

function removeTheme(key) {
    if (confirm(`Remove theme "${key}"?`)) {
        delete themesData[key];
        // Remove any mappings to this theme
        Object.keys(emotionThemeMap).forEach(emotionKey => {
            if (emotionThemeMap[emotionKey] === key) {
                delete emotionThemeMap[emotionKey];
            }
        });
        renderThemes();
        renderEmotionThemeMappings();
    }
}

async function saveEmotions() {
    // Collect data from inputs
    document.querySelectorAll('[data-emotion]').forEach(input => {
        const emotion = input.dataset.emotion;
        const field = input.dataset.field;
        emotionsData[emotion][field] = input.value;
    });
    
    const iniContent = generateEmotionsINI();
    await saveConfig('emotions.ini', iniContent);
}

async function saveThemes() {
    // Collect data from inputs
    document.querySelectorAll('[data-theme]').forEach(input => {
        const theme = input.dataset.theme;
        const field = input.dataset.field;
        themesData[theme][field] = input.value;
    });
    
    const iniContent = generateThemesINI();
    await saveConfig('themes.ini', iniContent);
}

async function saveEmotionThemeMappings() {
    // Collect mappings from selects
    document.querySelectorAll('[data-mapping-emotion]').forEach(select => {
        const emotionKey = select.dataset.mappingEmotion;
        const themeKey = select.value;
        
        if (themeKey) {
            emotionThemeMap[emotionKey] = themeKey;
        } else {
            delete emotionThemeMap[emotionKey];
        }
    });
    
    // Save to config.ini
    const stored = await getStoredValues();
    const content = generateConfigINI(stored);
    await saveConfig('config.ini', content);
}

function escapeINIValue(value) {
    if (typeof value !== 'string') return value;
    return value
        .replace(/\\/g, '\\\\')  // Escape backslashes first
        .replace(/"/g, '\\"')    // Escape quotes
        .replace(/\$/g, '\\$')   // Escape $ (PHP parse_ini_file interprets $ in double quotes)
        .replace(/\n/g, '\\n')   // Escape newlines
        .replace(/\r/g, '\\r')   // Escape carriage returns
        .replace(/\t/g, '\\t');  // Escape tabs
}

function generateEmotionsINI() {
    let content = '';
    Object.keys(emotionsData).forEach(key => {
        const emotion = emotionsData[key];
        content += `[${key}]\n`;
        content += `label = "${escapeINIValue(emotion.label || '')}"\n`;
        content += `description = "${escapeINIValue(emotion.description || '')}"\n`;
        content += `color = "${escapeINIValue(emotion.color || '#ffffff')}"\n`;
        content += `emoji = "${escapeINIValue(emotion.emoji || '😀')}"\n`;
        content += `filter = "${escapeINIValue(emotion.filter || 'brightness(1.0)')}"\n`;
        content += `shake = ${emotion.shake || 'false'}\n`;
        content += `glow = ${emotion.glow || 'false'}\n`;
        if (emotion.intense) content += `intense = ${emotion.intense}\n`;
        content += '\n';
    });
    return content;
}

function generateThemesINI() {
    let content = '';
    Object.keys(themesData).forEach(key => {
        const theme = themesData[key];
        content += `[${key}]\n`;
        content += `name = "${escapeINIValue(theme.name || '')}"\n`;
        content += `primary_color = "${escapeINIValue(theme.primary_color || '#ffffff')}"\n`;
        content += `secondary_color = "${escapeINIValue(theme.secondary_color || '#eeeeee')}"\n`;
        content += `accent_color = "${escapeINIValue(theme.accent_color || '#dddddd')}"\n`;
        content += `background_color = "${escapeINIValue(theme.background_color || '#cccccc')}"\n`;
        content += `header_gradient = "${escapeINIValue(theme.header_gradient || 'linear-gradient(135deg, #ffffff 0%, #eeeeee 100%)')}"\n`;
        content += `avatar_filter = "${escapeINIValue(theme.avatar_filter || 'brightness(1.0)')}"\n`;
        content += `description = "${escapeINIValue(theme.description || '')}"\n`;
        content += '\n';
    });
    return content;
}

async function getStoredValues() {
    try {
        const response = await fetch('admin-get-stored.php');
        const data = await response.json();
        return data.success ? data : { api_key: '', password_hash: '', endpoint: '', version: '' };
    } catch (error) {
        console.error('Failed to get stored values:', error);
        return { api_key: '', password_hash: '', endpoint: '', version: '' };
    }
}

function generateConfigINI(stored) {
    let content = '[general]\n';
    Object.keys(configData.general || {}).forEach(key => {
        const value = configData.general[key];
        content += `${key} = "${escapeINIValue(value || '')}"\n`;
    });

    // Preserve [models] section
    if (Object.keys(modelsData).length > 0) {
        content += '\n[models]\n';
        Object.keys(modelsData).forEach(key => {
            const value = typeof modelsData[key] === 'string'
                ? modelsData[key]
                : `${modelsData[key].name || key}|${modelsData[key].provider || 'anthropic'}`;
            content += `${key} = "${escapeINIValue(value)}"\n`;
        });
    }

    // Preserve [model_ids] section
    if (Object.keys(modelIdsData).length > 0) {
        content += '\n[model_ids]\n';
        Object.keys(modelIdsData).forEach(key => {
            content += `${key} = "${escapeINIValue(modelIdsData[key])}"\n`;
        });
    }

    // Add emotion-theme mapping section
    if (Object.keys(emotionThemeMap).length > 0) {
        content += '\n[emotion_theme_map]\n';
        Object.keys(emotionThemeMap).forEach(emotionKey => {
            content += `${emotionKey} = "${emotionThemeMap[emotionKey]}"\n`;
        });
    }

    content += '\n[personality]\n';
    Object.keys(configData.personality || {}).forEach(key => {
        content += `${key} = "${escapeINIValue(configData.personality[key] || '')}"\n`;
    });

    content += '\n[admin]\n';
    content += `username = "${escapeINIValue(configData.admin?.username || 'admin')}"\n`;
    content += `password = "${escapeINIValue(stored.password_hash)}"\n`;

    content += '\n[api]\n';
    content += `api_key = "${escapeINIValue(stored.api_key)}"\n`;
    content += `endpoint = "${escapeINIValue(stored.endpoint || 'https://api.anthropic.com/v1/messages')}"\n`;
    content += `anthropic_version = "${escapeINIValue(stored.version || '2023-06-01')}"\n`;

    return content;
}

// Form submissions
document.getElementById('generalForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    if (!configData.general) configData.general = {};
    
    // Get all form inputs including checkboxes
    const form = e.target;
    const inputs = form.querySelectorAll('input, select, textarea');
    
    inputs.forEach(input => {
        const name = input.name;
        if (!name) return;
        
        if (input.type === 'checkbox') {
            // Explicitly handle checkbox - it should always be set
            configData.general[name] = input.checked ? 'true' : 'false';
        } else {
            configData.general[name] = input.value;
        }
    });
    
    const stored = await getStoredValues();
    const content = generateConfigINI(stored);
    await saveConfig('config.ini', content);
});

document.getElementById('personalityForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    // Update config data
    for (let [key, value] of formData.entries()) {
        if (!configData.personality) configData.personality = {};
        configData.personality[key] = value;
    }
    
    const stored = await getStoredValues();
    const content = generateConfigINI(stored);
    await saveConfig('config.ini', content);
});

document.getElementById('apiForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    const stored = await getStoredValues();
    
    // Update API key if provided
    const newApiKey = formData.get('api_key');
    if (newApiKey && newApiKey.trim()) {
        stored.api_key = newApiKey.trim();
    }
    
    // Update endpoint and version
    stored.endpoint = formData.get('endpoint') || stored.endpoint;
    stored.version = formData.get('anthropic_version') || stored.version;
    
    const content = generateConfigINI(stored);
    await saveConfig('config.ini', content);
});

document.getElementById('adminForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    const stored = await getStoredValues();
    
    // Update username
    const username = formData.get('username');
    if (!configData.admin) configData.admin = {};
    configData.admin.username = username;
    
    // Hash new password if provided
    const password = formData.get('password');
    if (password && password.trim()) {
        const hashResponse = await fetch('admin-hash-password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password.trim() })
        });
        const hashData = await hashResponse.json();
        if (hashData.success) {
            stored.password_hash = hashData.hash;
        }
    }
    
    const content = generateConfigINI(stored);
    await saveConfig('config.ini', content);
});

async function saveConfig(file, content) {
    try {
        const response = await fetch('admin-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file, content })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showMessage(`${file} saved successfully!`, 'success');
            setTimeout(() => loadConfigurations(), 1000);
        } else {
            showMessage('Error: ' + data.error, 'danger');
        }
    } catch (error) {
        showMessage('Save failed: ' + error.message, 'danger');
    }
}

function showMessage(message, type) {
    const messageArea = document.getElementById('messageArea');
    messageArea.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
    setTimeout(() => {
        messageArea.innerHTML = '';
    }, 5000);
}

// Sync color picker with text input
function syncColorInputs() {
    // Handle color picker changes
    document.querySelectorAll('.color-picker').forEach(picker => {
        picker.addEventListener('input', (e) => {
            const emotion = e.target.dataset.emotion;
            const theme = e.target.dataset.theme;
            const field = e.target.dataset.field;
            const value = e.target.value;
            
            // Find corresponding text input and update it
            const textInput = e.target.parentElement.querySelector('.color-text');
            if (textInput) {
                textInput.value = value;
            }
            
            // Update data
            if (emotion) {
                emotionsData[emotion][field] = value;
            } else if (theme) {
                themesData[theme][field] = value;
            }
        });
    });
    
    // Handle text input changes
    document.querySelectorAll('.color-text').forEach(textInput => {
        textInput.addEventListener('input', (e) => {
            const emotion = e.target.dataset.emotion;
            const theme = e.target.dataset.theme;
            const field = e.target.dataset.field;
            const value = e.target.value;
            
            // Validate hex color
            if (/^#[0-9A-F]{6}$/i.test(value)) {
                // Find corresponding color picker and update it
                const picker = e.target.parentElement.querySelector('.color-picker');
                if (picker) {
                    picker.value = value;
                }
                
                // Update data
                if (emotion) {
                    emotionsData[emotion][field] = value;
                } else if (theme) {
                    themesData[theme][field] = value;
                }
            }
        });
    });
}

// Emoji picker functionality
const COMMON_EMOJIS = [
    '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃',
    '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😙',
    '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭', '🤫', '🤔',
    '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '🤥',
    '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🤢', '🤮',
    '🤧', '🥵', '🥶', '🥴', '😵', '🤯', '🤠', '🥳', '😎', '🤓',
    '🧐', '😕', '😟', '🙁', '☹️', '😮', '😯', '😲', '😳', '🥺',
    '😦', '😧', '😨', '😰', '😥', '😢', '😭', '😱', '😖', '😣',
    '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬', '😈',
    '👿', '💀', '☠️', '💩', '🤡', '👹', '👺', '👻', '👽', '👾',
    '🤖', '😺', '😸', '😹', '😻', '😼', '😽', '🙀', '😿', '😾',
    '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
    '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️',
    '✝️', '☪️', '🕉️', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐',
    '⚛️', '🕉️', '⚡', '🔥', '💥', '💫', '⭐', '🌟', '✨', '⚡',
    '☄️', '💦', '💧', '🌊', '🎭', '🎨', '🎬', '🎤', '🎧', '🎼',
    '🎹', '🥁', '🎷', '🎺', '🎸', '🪕', '🎻', '🎲', '♟️', '🎯',
    '🎰', '🎱', '🔮', '🪄', '🧿', '🎮', '🕹️', '🎳', '🖼️', '🎨'
];

let currentEmojiTarget = null;

function openEmojiPicker(emotionKey) {
    currentEmojiTarget = emotionKey;
    
    // Create modal if it doesn't exist
    let modal = document.getElementById('emojiPickerModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'emojiPickerModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        `;
        
        const content = document.createElement('div');
        content.style.cssText = `
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 2px solid #ff6b35;
            border-radius: 20px;
            padding: 2rem;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(255, 107, 53, 0.5);
        `;
        
        content.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="color: #ff6b35; margin: 0;">Select Emoji</h3>
                <button onclick="closeEmojiPicker()" style="background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #ef4444; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer; font-size: 1.2rem;">✕</button>
            </div>
            <div id="emojiGrid" style="display: grid; grid-template-columns: repeat(10, 1fr); gap: 0.5rem;">
                ${COMMON_EMOJIS.map(emoji => `
                    <button class="emoji-btn" onclick="selectEmoji('${emoji}')" style="
                        background: rgba(0, 0, 0, 0.3);
                        border: 1px solid rgba(255, 107, 53, 0.3);
                        border-radius: 8px;
                        padding: 0.75rem;
                        font-size: 1.8rem;
                        cursor: pointer;
                        transition: all 0.2s;
                    " onmouseover="this.style.background='rgba(255, 107, 53, 0.3)'; this.style.transform='scale(1.2)'" onmouseout="this.style.background='rgba(0, 0, 0, 0.3)'; this.style.transform='scale(1)'">${emoji}</button>
                `).join('')}
            </div>
        `;
        
        modal.appendChild(content);
        document.body.appendChild(modal);
    }
    
    modal.style.display = 'flex';
}

function closeEmojiPicker() {
    const modal = document.getElementById('emojiPickerModal');
    if (modal) {
        modal.style.display = 'none';
    }
    currentEmojiTarget = null;
}

function selectEmoji(emoji) {
    if (currentEmojiTarget && emotionsData[currentEmojiTarget]) {
        emotionsData[currentEmojiTarget].emoji = emoji;
        renderEmotions();
        closeEmojiPicker();
    }
}