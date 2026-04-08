/**
 * Chatbot Frontend Application
 * Features: Streaming responses, timestamps, context window management
 */

// ============================================
// GLOBAL STATE
// ============================================
let CONFIG = null;
let EMOTIONS = null;
let THEMES = {};
let EMOTION_STYLES = {};
let EMOTION_THEME_MAP = {};
let LINK_EMOTIONS_TO_THEMES = true;

// Conversation state
let conversationHistory = [];
let messageMetadata = [];
let currentEmotion = 'neutral';
let currentTheme = 'default';
let uploadedImage = null;
let imageType = null;
let recognition = null;
let isListening = false;

// Streaming state
let currentStreamingMessage = null;
let streamingTextBuffer = '';

// Context management settings
const CONTEXT_CONFIG = {
    maxMessages: 20,          // Max messages before summarization
    recentToKeep: 10,         // Recent messages to keep verbatim
    maxSummaryLength: 150     // Max chars per message in summary
};

// Image processing configuration
const IMAGE_CONFIG = {
    maxWidth: 1024,
    maxHeight: 1024,
    quality: 0.75,
    maxFileSizeKB: 200,
    minQuality: 0.4
};

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const configLoaded = await loadConfiguration();
        
        if (!configLoaded) {
            console.error('Configuration failed to load');
            return;
        }
        
        setupEventListeners();
        loadConversationHistory();
        preventOverscroll();
        adjustViewportHeight();
        initializeVoiceRecognition();
        configureMarked();
    } catch (error) {
        console.error('Initialization error:', error);
        showError('Failed to initialize application');
    }
});

// ============================================
// CONFIGURATION LOADING
// ============================================
async function loadConfiguration() {
    try {
        const response = await fetch('get-config.php');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load configuration');
        }
        
        CONFIG = data.config;
        EMOTIONS = data.emotions;
        THEMES = {};
        EMOTION_THEME_MAP = data.emotion_theme_map || {};
        LINK_EMOTIONS_TO_THEMES = CONFIG.general.link_emotions_to_themes === 'true' || 
                                   CONFIG.general.link_emotions_to_themes === true;
        
        validateConfiguration();
        processThemes(data.themes);
        processEmotions();
        
        currentEmotion = CONFIG.general.default_emotion;
        currentTheme = CONFIG.general.default_theme;
        
        updatePageWithConfig();
        
        return true;
    } catch (error) {
        console.error('Error loading configuration:', error);
        const welcomeTitle = document.getElementById('welcomeTitle');
        const welcomeMessage = document.getElementById('welcomeMessage');
        
        if (welcomeTitle) welcomeTitle.textContent = 'Configuration Error';
        if (welcomeMessage) welcomeMessage.textContent = 'Failed to load: ' + error.message;
        
        return false;
    }
}

function validateConfiguration() {
    const required = {
        'general': ['bot_name', 'bot_title', 'bot_description', 'default_emotion', 'default_theme', 'avatar_image'],
        'personality': ['base_description', 'speaking_style']
    };
    
    for (const [section, fields] of Object.entries(required)) {
        if (!CONFIG[section]) {
            throw new Error(`Missing config section: ${section}`);
        }
        for (const field of fields) {
            if (!CONFIG[section][field]) {
                throw new Error(`Missing field: ${section}.${field}`);
            }
        }
    }
}

function processThemes(themes) {
    Object.keys(themes).forEach(key => {
        const theme = themes[key];
        THEMES[key] = {
            name: theme.name,
            primaryColor: theme.primary_color,
            secondaryColor: theme.secondary_color,
            accentColor: theme.accent_color,
            backgroundColor: theme.background_color,
            headerGradient: theme.header_gradient,
            avatarFilter: theme.avatar_filter,
            description: theme.description
        };
    });
    
    if (!THEMES[CONFIG.general.default_theme]) {
        CONFIG.general.default_theme = Object.keys(THEMES)[0] || 'default';
    }
}

function processEmotions() {
    EMOTION_STYLES = {};
    Object.keys(EMOTIONS).forEach(key => {
        const emotion = EMOTIONS[key];
        EMOTION_STYLES[key] = {
            color: emotion.color,
            emoji: emotion.emoji,
            filter: emotion.filter,
            shake: emotion.shake === 'true' || emotion.shake === true,
            glow: emotion.glow === 'true' || emotion.glow === true,
            intense: emotion.intense === 'true' || emotion.intense === true,
            theme: EMOTION_THEME_MAP[key] || null
        };
    });
    
    if (!EMOTIONS[CONFIG.general.default_emotion]) {
        CONFIG.general.default_emotion = Object.keys(EMOTIONS)[0] || 'neutral';
    }
}

function updatePageWithConfig() {
    const elements = {
        'pageTitle': CONFIG.general.bot_title,
        'headerTitle': CONFIG.general.bot_title,
        'botNameEmotion': CONFIG.general.bot_name,
        'welcomeTitle': CONFIG.general.welcome_title,
        'welcomeMessage': CONFIG.general.welcome_message,
        'footerText': CONFIG.general.footer_text
    };
    
    Object.entries(elements).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    });
    
    const appTitle = document.getElementById('appTitle');
    const metaDesc = document.getElementById('metaDescription');
    if (appTitle) appTitle.content = CONFIG.general.bot_title;
    if (metaDesc) metaDesc.content = CONFIG.general.bot_description;
    
    const avatarSrc = CONFIG.general.avatar_image;
    ['headerAvatar', 'welcomeAvatar'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.src = avatarSrc;
            el.alt = CONFIG.general.bot_name;
        }
    });
    
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.placeholder = `Message ${CONFIG.general.bot_name}...`;
    }
    
    applyTheme(currentTheme);
    updateEmotion(currentEmotion);
}

// ============================================
// TIMESTAMP FORMATTING
// ============================================
function formatTimestamp(date = new Date()) {
    const now = new Date();
    const diff = now - date;
    
    // If less than 24 hours, show time
    if (diff < 24 * 60 * 60 * 1000) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    
    // If this year, show date without year
    if (date.getFullYear() === now.getFullYear()) {
        return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    
    // Otherwise show full date
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
}

// ============================================
// CONTEXT WINDOW MANAGEMENT
// ============================================
function manageContextWindow(messages) {
    if (messages.length <= CONTEXT_CONFIG.maxMessages) {
        return messages;
    }
    
    const recentMessages = messages.slice(-CONTEXT_CONFIG.recentToKeep);
    const oldMessages = messages.slice(0, -CONTEXT_CONFIG.recentToKeep);
    
    // Build summary of older messages
    const summaryParts = oldMessages.map(msg => {
        const role = msg.role === 'user' ? 'User' : CONFIG.general.bot_name;
        let content = '';
        
        if (Array.isArray(msg.content)) {
            for (const part of msg.content) {
                if (part.type === 'text') {
                    content = part.text;
                    break;
                } else if (part.type === 'image') {
                    content = '[shared an image]';
                }
            }
        } else {
            content = msg.content;
        }
        
        // Truncate long messages
        if (content.length > CONTEXT_CONFIG.maxSummaryLength) {
            content = content.substring(0, CONTEXT_CONFIG.maxSummaryLength) + '...';
        }
        
        return `${role}: ${content}`;
    });
    
    const summaryText = summaryParts.join('\n');
    
    // Create context summary as a system-style user message
    const contextMessage = {
        role: 'user',
        content: [{
            type: 'text',
            text: `[CONVERSATION CONTEXT - Earlier messages summarized:\n${summaryText}\n\nPlease continue naturally, keeping this context in mind.]`
        }]
    };
    
    // Return context summary + recent messages
    return [contextMessage, ...recentMessages];
}

function getContextStats() {
    const total = conversationHistory.length;
    const managed = total > CONTEXT_CONFIG.maxMessages;
    return {
        total,
        managed,
        summarized: managed ? total - CONTEXT_CONFIG.recentToKeep : 0,
        recent: managed ? CONTEXT_CONFIG.recentToKeep : total
    };
}

// ============================================
// MARKDOWN CONFIGURATION
// ============================================
function configureMarked() {
    if (typeof marked === 'undefined') return;
    
    marked.setOptions({
        breaks: true,
        gfm: true,
        highlight: function(code, lang) {
            if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                try {
                    return hljs.highlight(code, { language: lang }).value;
                } catch (err) {
                    console.warn('Highlight error:', err);
                }
            }
            return code;
        }
    });
}

function renderMarkdown(text) {
    if (typeof marked === 'undefined') {
        return escapeHtml(text);
    }
    
    try {
        return marked.parse(text);
    } catch (error) {
        console.warn('Markdown parsing error:', error);
        return escapeHtml(text);
    }
}

// ============================================
// VOICE RECOGNITION
// ============================================
function initializeVoiceRecognition() {
    const voiceBtn = document.getElementById('voiceInputBtn');
    
    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        if (voiceBtn) voiceBtn.style.display = 'none';
        return;
    }
    
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = 'en-US';

    recognition.onresult = function(event) {
        const transcript = event.results[0][0].transcript;
        const messageInput = document.getElementById('messageInput');
        if (messageInput) messageInput.value = transcript;
        stopListening();
    };

    recognition.onerror = function(event) {
        console.error('Speech recognition error:', event.error);
        stopListening();
        
        const errorMessages = {
            'no-speech': 'No speech detected',
            'audio-capture': 'Microphone not accessible',
            'not-allowed': 'Microphone permission denied',
            'network': 'Network error',
            'aborted': 'Recognition aborted'
        };
        
        if (event.error !== 'no-speech' && event.error !== 'aborted') {
            showNotification(errorMessages[event.error] || 'Voice input error', 'error');
        }
    };

    recognition.onend = stopListening;
}

function startListening() {
    if (!recognition) return;
    
    isListening = true;
    const voiceBtn = document.getElementById('voiceInputBtn');
    if (voiceBtn) {
        voiceBtn.classList.add('listening');
        voiceBtn.innerHTML = '🔴';
        voiceBtn.title = 'Listening... Click to stop';
    }
    
    try {
        recognition.start();
        triggerHaptic('medium');
    } catch (e) {
        console.error('Failed to start recognition:', e);
        stopListening();
    }
}

function stopListening() {
    if (!recognition) return;
    
    isListening = false;
    const voiceBtn = document.getElementById('voiceInputBtn');
    if (voiceBtn) {
        voiceBtn.classList.remove('listening');
        voiceBtn.innerHTML = '🎤';
        voiceBtn.title = 'Voice input';
    }
    
    try {
        recognition.stop();
    } catch (e) {
        // Already stopped
    }
}

// ============================================
// UI HELPERS
// ============================================
function preventOverscroll() {
    document.body.addEventListener('touchmove', function(e) {
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer && !chatContainer.contains(e.target)) {
            e.preventDefault();
        }
    }, { passive: false });
}

function adjustViewportHeight() {
    const setVH = () => {
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
    };
    
    setVH();
    window.addEventListener('resize', setVH);
    window.addEventListener('orientationchange', setVH);
}

function showNotification(message, type = 'success') {
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

function triggerHaptic(intensity = 'light') {
    if (!navigator.vibrate) return;
    
    const patterns = {
        light: 10,
        medium: [15, 10, 15],
        heavy: [30, 20, 30, 20, 30]
    };
    
    navigator.vibrate(patterns[intensity] || patterns.light);
}

function flashScreen(color) {
    const flash = document.createElement('div');
    flash.className = 'screen-flash';
    flash.style.backgroundColor = color;
    document.body.appendChild(flash);
    
    requestAnimationFrame(() => {
        flash.style.opacity = '0.3';
    });
    
    setTimeout(() => {
        flash.style.opacity = '0';
        setTimeout(() => flash.remove(), 300);
    }, 100);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// EVENT LISTENERS
// ============================================
function setupEventListeners() {
    document.getElementById('imageUploadBtn')?.addEventListener('click', () => {
        document.getElementById('imageUpload')?.click();
    });
    
    document.getElementById('imageUpload')?.addEventListener('change', handleImageUpload);
    document.getElementById('removeImage')?.addEventListener('click', removeImage);
    
    document.getElementById('voiceInputBtn')?.addEventListener('click', () => {
        isListening ? stopListening() : startListening();
    });
    
    document.getElementById('sendBtn')?.addEventListener('click', sendMessage);
    
    document.getElementById('messageInput')?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    document.getElementById('messageInput')?.addEventListener('focus', function() {
        setTimeout(() => {
            this.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 300);
    });
    
    document.getElementById('clearChat')?.addEventListener('click', clearChat);
    document.getElementById('exportChat')?.addEventListener('click', exportChat);
    
    document.addEventListener('click', (e) => {
        const switcher = document.getElementById('themeSwitcher');
        const menu = document.getElementById('themeMenu');
        if (switcher && menu && !switcher.contains(e.target) && menu.style.display !== 'none') {
            menu.style.display = 'none';
        }
    });
}

// ============================================
// IMAGE HANDLING
// ============================================
function handleImageUpload(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        showNotification('Please upload a valid image file', 'error');
        return;
    }

    processImage(file);
}

async function processImage(file) {
    try {
        const img = new Image();
        const reader = new FileReader();

        reader.onload = (e) => { img.src = e.target.result; };

        img.onload = async function() {
            let width = img.width;
            let height = img.height;
            const originalSize = (file.size / 1024).toFixed(2);
            
            const ratio = Math.min(
                IMAGE_CONFIG.maxWidth / width,
                IMAGE_CONFIG.maxHeight / height,
                1
            );
            
            width = Math.floor(width * ratio);
            height = Math.floor(height * ratio);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            
            const ctx = canvas.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(img, 0, 0, width, height);

            const result = await findBestFormat(canvas, width, height);
            
            uploadedImage = result.data;
            imageType = result.format;

            displayImagePreview(result, width, height, originalSize);
        };

        img.onerror = () => {
            showNotification('Failed to load image', 'error');
        };

        reader.readAsDataURL(file);
    } catch (error) {
        showNotification('Error processing image: ' + error.message, 'error');
    }
}

async function findBestFormat(canvas, width, height) {
    const formats = [
        { format: 'image/webp', name: 'WebP' },
        { format: 'image/jpeg', name: 'JPEG' }
    ];
    
    let bestResult = null;
    let bestSize = Infinity;

    for (const { format, name } of formats) {
        let quality = IMAGE_CONFIG.quality;
        let data = canvas.toDataURL(format, quality);
        let size = getBase64Size(data);

        while (size > IMAGE_CONFIG.maxFileSizeKB && quality > IMAGE_CONFIG.minQuality) {
            quality -= 0.05;
            data = canvas.toDataURL(format, quality);
            size = getBase64Size(data);
        }

        if (size < bestSize) {
            bestSize = size;
            bestResult = { data, format, formatName: name, quality, size };
        }

        if (size <= IMAGE_CONFIG.maxFileSizeKB) break;
    }

    return bestResult;
}

function displayImagePreview(result, width, height, originalSize) {
    const container = document.getElementById('imagePreviewContainer');
    const preview = document.getElementById('imagePreview');
    const stats = document.getElementById('imageStats');
    
    if (preview) preview.src = result.data;
    
    if (stats) {
        const tokens = estimateImageTokens(width, height);
        stats.innerHTML = `<small class="text-muted">${width}×${height} • ${result.size.toFixed(1)}KB • ~${tokens} tokens</small>`;
    }
    
    container?.classList.remove('d-none');
}

function removeImage() {
    triggerHaptic();
    uploadedImage = null;
    imageType = null;
    document.getElementById('imagePreviewContainer')?.classList.add('d-none');
    const upload = document.getElementById('imageUpload');
    if (upload) upload.value = '';
}

function getBase64Size(base64String) {
    const base64Length = base64String.length - (base64String.indexOf(',') + 1);
    const padding = base64String.endsWith('==') ? 2 : base64String.endsWith('=') ? 1 : 0;
    return (base64Length * 0.75 - padding) / 1024;
}

function estimateImageTokens(width, height) {
    const tilesWide = Math.ceil(width / 512);
    const tilesHigh = Math.ceil(height / 512);
    return tilesWide * tilesHigh * 85;
}

// ============================================
// STREAMING MESSAGE HANDLING
// ============================================
async function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    const message = messageInput?.value.trim() || '';
    
    if (!message && !uploadedImage) return;
    if (isListening) stopListening();

    triggerHaptic();
    setInputState(false);

    const tempImage = uploadedImage;
    const tempImageType = imageType;
    const timestamp = new Date();
    
    // Build message content
    const userContent = [];
    
    if (uploadedImage) {
        userContent.push({
            type: 'image',
            source: {
                type: 'base64',
                media_type: imageType,
                data: uploadedImage.split(',')[1]
            }
        });
    }
    
    if (message) {
        userContent.push({ type: 'text', text: message });
    }

    // Add user message to UI with timestamp
    addMessageToChat('user', message, uploadedImage, null, null, timestamp);

    // Add to history
    conversationHistory.push({ role: 'user', content: userContent });
    messageMetadata.push({ role: 'user', emotion: currentEmotion, theme: currentTheme, timestamp: timestamp.toISOString() });

    // Clear inputs
    if (messageInput) messageInput.value = '';
    removeImage();

    // Create streaming bot message placeholder
    const botTimestamp = new Date();
    const streamingDiv = createStreamingMessage(botTimestamp);
    streamingTextBuffer = '';

    try {
        // Use streaming endpoint
        await streamResponse(streamingDiv, botTimestamp);
    } catch (error) {
        console.error('Streaming error:', error);
        
        // Remove streaming placeholder
        streamingDiv?.remove();
        
        // Restore image on error
        restoreImage(tempImage, tempImageType);
        showError('Connection error: ' + error.message);
    } finally {
        setInputState(true);
        currentStreamingMessage = null;
    }
}

function createStreamingMessage(timestamp) {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer || !CONFIG) return null;
    
    // Remove welcome message
    chatContainer.querySelector('.welcome-message')?.remove();

    const messageDiv = document.createElement('div');
    messageDiv.className = 'message brainy-message streaming';
    messageDiv.id = 'streamingMessage';
    
    messageDiv.innerHTML = `
        <img src="${CONFIG.general.avatar_image}" alt="${CONFIG.general.bot_name}" class="avatar">
        <div class="message-content">
            <div class="message-text markdown-content"></div>
            <div class="message-timestamp">${formatTimestamp(timestamp)}</div>
            <button class="btn-copy-message" onclick="copyMessage(this)" title="Copy" style="display:none;">📋</button>
        </div>
    `;
    
    chatContainer.appendChild(messageDiv);
    currentStreamingMessage = messageDiv;
    
    chatContainer.scrollTo({ top: chatContainer.scrollHeight, behavior: 'smooth' });
    
    return messageDiv;
}

async function streamResponse(messageDiv, timestamp) {
    // Manage context window before sending
    const managedMessages = manageContextWindow(conversationHistory);
    
    const response = await fetch('api-stream.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: managedMessages })
    });

    if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        
        if (done) break;
        
        buffer += decoder.decode(value, { stream: true });
        
        // Process complete SSE events
        const events = buffer.split('\n\n');
        buffer = events.pop() || ''; // Keep incomplete event in buffer
        
        for (const event of events) {
            if (!event.trim()) continue;
            
            const lines = event.split('\n');
            let eventType = '';
            let eventData = '';
            
            for (const line of lines) {
                if (line.startsWith('event: ')) {
                    eventType = line.substring(7);
                } else if (line.startsWith('data: ')) {
                    eventData = line.substring(6);
                }
            }
            
            if (eventData) {
                try {
                    const data = JSON.parse(eventData);
                    handleStreamEvent(eventType, data, messageDiv, timestamp);
                } catch (e) {
                    console.warn('Failed to parse SSE data:', e);
                }
            }
        }
    }
}

function handleStreamEvent(eventType, data, messageDiv, timestamp) {
    const chatContainer = document.getElementById('chatContainer');
    const textEl = messageDiv?.querySelector('.message-text');
    
    switch (eventType) {
        case 'chunk':
            if (data.text && textEl) {
                streamingTextBuffer += data.text;
                
                // Filter out emotion/theme tags from display during streaming
                let displayText = streamingTextBuffer
                    .replace(/\[THEME:\s*\w+\]/gi, '')
                    .replace(/\[EMOTION:\s*\w+\]/gi, '');
                
                textEl.innerHTML = renderMarkdown(displayText);
                
                // Scroll to bottom
                if (chatContainer) {
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                }
            }
            break;
            
        case 'done':
            if (messageDiv) {
                messageDiv.classList.remove('streaming');
                
                // Show copy button
                const copyBtn = messageDiv.querySelector('.btn-copy-message');
                if (copyBtn) copyBtn.style.display = '';
                
                // Update text with final clean version
                if (textEl && data.fullText) {
                    textEl.innerHTML = renderMarkdown(data.fullText);
                    
                    // Highlight code blocks
                    if (typeof hljs !== 'undefined') {
                        textEl.querySelectorAll('pre code').forEach(block => {
                            hljs.highlightElement(block);
                        });
                    }
                }
                
                // Apply emotion and theme
                const newEmotion = data.emotion || currentEmotion;
                let newTheme = data.theme || currentTheme;
                
                // Apply emotion-theme linking
                if (LINK_EMOTIONS_TO_THEMES && newEmotion !== currentEmotion) {
                    const linkedTheme = EMOTION_STYLES[newEmotion]?.theme || EMOTION_THEME_MAP[newEmotion];
                    if (linkedTheme && THEMES[linkedTheme]) {
                        newTheme = linkedTheme;
                    }
                }
                
                messageDiv.classList.add(`emotion-${newEmotion}`);
                
                if (newEmotion !== currentEmotion) updateEmotion(newEmotion);
                if (newTheme !== currentTheme && THEMES[newTheme]) applyTheme(newTheme);
                
                // Add to history
                conversationHistory.push({
                    role: 'assistant',
                    content: [{ type: 'text', text: data.fullText || streamingTextBuffer }]
                });
                messageMetadata.push({
                    role: 'assistant',
                    emotion: newEmotion,
                    theme: newTheme,
                    timestamp: timestamp.toISOString()
                });
                
                saveConversationHistory();
            }
            break;
            
        case 'error':
            messageDiv?.remove();
            showError(data.error || 'Streaming error');
            break;
    }
}

function restoreImage(image, type) {
    if (!image) return;
    
    uploadedImage = image;
    imageType = type;
    
    const img = new Image();
    img.onload = function() {
        const result = {
            data: image,
            format: type,
            formatName: type.split('/')[1].toUpperCase(),
            size: getBase64Size(image)
        };
        displayImagePreview(result, img.width, img.height, result.size);
    };
    img.src = image;
}

function addMessageToChat(sender, text, image, emotion, theme, timestamp = new Date()) {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer) return;
    
    chatContainer.querySelector('.welcome-message')?.remove();

    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}-message`;

    if (sender === 'brainy') {
        const emotionStyle = EMOTION_STYLES[emotion] || EMOTION_STYLES[currentEmotion] || {};
        messageDiv.classList.add(`emotion-${emotion || currentEmotion}`);
        
        if (emotionStyle.shake) messageDiv.classList.add('message-shake');
        
        const renderedText = renderMarkdown(text);
        
        messageDiv.innerHTML = `
            <img src="${CONFIG.general.avatar_image}" alt="${CONFIG.general.bot_name}" class="avatar">
            <div class="message-content">
                <div class="message-text markdown-content">${renderedText}</div>
                <div class="message-timestamp">${formatTimestamp(timestamp)}</div>
                <button class="btn-copy-message" onclick="copyMessage(this)" title="Copy">📋</button>
            </div>
        `;

        if (typeof hljs !== 'undefined') {
            messageDiv.querySelectorAll('pre code').forEach(block => {
                hljs.highlightElement(block);
            });
        }
    } else {
        let content = '';
        if (image) content += `<img src="${image}" alt="User image" class="message-image">`;
        if (text) content += `<div class="message-text">${escapeHtml(text)}</div>`;
        
        messageDiv.innerHTML = `
            <div class="message-content">
                ${content}
                <div class="message-timestamp">${formatTimestamp(timestamp)}</div>
            </div>
        `;
    }

    chatContainer.appendChild(messageDiv);
    
    requestAnimationFrame(() => {
        chatContainer.scrollTo({ top: chatContainer.scrollHeight, behavior: 'smooth' });
    });
}

function copyMessage(button) {
    const messageText = button.parentElement.querySelector('.message-text');
    const text = messageText?.innerText || messageText?.textContent || '';
    
    navigator.clipboard.writeText(text).then(() => {
        button.innerText = '✓';
        button.style.color = '#00ff88';
        triggerHaptic('light');
        
        setTimeout(() => {
            button.innerText = '📋';
            button.style.color = '';
        }, 2000);
    }).catch(() => {
        button.innerText = '✗';
        setTimeout(() => { button.innerText = '📋'; }, 2000);
    });
}

function showError(message) {
    triggerHaptic();
    addMessageToChat('brainy', `Sorry, I ran into an issue: ${message}`, null, 'confused');
}

function setInputState(enabled) {
    const elements = {
        'sendBtn': enabled,
        'messageInput': enabled,
        'imageUploadBtn': enabled,
        'voiceInputBtn': enabled
    };
    
    Object.entries(elements).forEach(([id, state]) => {
        const el = document.getElementById(id);
        if (el) el.disabled = !state;
    });
    
    const sendIcon = document.getElementById('sendIcon');
    const sendSpinner = document.getElementById('sendSpinner');
    
    if (sendIcon) sendIcon.classList.toggle('d-none', !enabled);
    if (sendSpinner) sendSpinner.classList.toggle('d-none', enabled);
}

// ============================================
// THEME MANAGEMENT
// ============================================
function toggleThemeMenu() {
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
    
    // Add emotion-theme link toggle
    const linkToggle = document.createElement('div');
    linkToggle.className = 'theme-link-toggle';
    linkToggle.innerHTML = `
        <label class="theme-link-label">
            <input type="checkbox" id="emotionThemeToggle" ${LINK_EMOTIONS_TO_THEMES ? 'checked' : ''} 
                   onchange="toggleEmotionThemeLink()">
            <span>🔗 Auto-change theme with emotion</span>
        </label>
    `;
    container.appendChild(linkToggle);
    
    // Show context stats
    const stats = getContextStats();
    if (stats.managed) {
        const statsDiv = document.createElement('div');
        statsDiv.className = 'context-stats';
        statsDiv.innerHTML = `<small style="color: rgba(255,255,255,0.5); display: block; padding: 0.5rem 0.75rem; font-size: 0.75rem;">
            📊 ${stats.total} messages (${stats.summarized} summarized, ${stats.recent} recent)
        </small>`;
        container.appendChild(statsDiv);
    }
    
    // Add themes
    Object.entries(THEMES).forEach(([key, theme]) => {
        const option = document.createElement('div');
        option.className = `theme-option ${key === currentTheme ? 'active' : ''}`;
        option.onclick = () => switchToTheme(key);
        
        option.innerHTML = `
            <div class="theme-option-name">${theme.name}</div>
            <div class="theme-option-desc">${theme.description || ''}</div>
            <div class="theme-option-preview">
                <span class="theme-color-dot" style="background: ${theme.primaryColor}"></span>
                <span class="theme-color-dot" style="background: ${theme.secondaryColor}"></span>
                <span class="theme-color-dot" style="background: ${theme.accentColor}"></span>
            </div>
        `;
        
        container.appendChild(option);
    });
}

function toggleEmotionThemeLink() {
    const toggle = document.getElementById('emotionThemeToggle');
    LINK_EMOTIONS_TO_THEMES = toggle?.checked ?? false;
    
    try {
        localStorage.setItem('linkEmotionsToThemes', String(LINK_EMOTIONS_TO_THEMES));
    } catch (e) {
        console.warn('Failed to save preference:', e);
    }
    
    triggerHaptic('light');
    showNotification(LINK_EMOTIONS_TO_THEMES ? 'Auto-theme enabled' : 'Auto-theme disabled');
}

function switchToTheme(themeName) {
    applyTheme(themeName);
    document.getElementById('themeMenu').style.display = 'none';
    saveConversationHistory();
    triggerHaptic();
}

function applyTheme(themeName) {
    const theme = THEMES[themeName] || THEMES.default || Object.values(THEMES)[0];
    if (!theme) return;
    
    currentTheme = themeName;
    
    const root = document.documentElement;
    root.style.setProperty('--theme-primary', theme.primaryColor);
    root.style.setProperty('--theme-secondary', theme.secondaryColor);
    root.style.setProperty('--theme-accent', theme.accentColor);
    root.style.setProperty('--theme-background', theme.backgroundColor);
    root.style.setProperty('--theme-header-gradient', theme.headerGradient);
    
    const cardHeader = document.querySelector('.card-header');
    if (cardHeader) cardHeader.style.background = theme.headerGradient;
    
    const card = document.querySelector('.card');
    if (card) card.style.backgroundColor = theme.secondaryColor;
    
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer) chatContainer.style.backgroundColor = theme.backgroundColor;
    
    const inputArea = document.querySelector('.input-area');
    if (inputArea) inputArea.style.backgroundColor = theme.secondaryColor;
}

// ============================================
// EMOTION MANAGEMENT
// ============================================
function updateEmotion(emotion) {
    const previousEmotion = currentEmotion;
    currentEmotion = emotion;
    
    const style = EMOTION_STYLES[emotion] || EMOTION_STYLES.neutral || {};
    
    const emotionEl = document.getElementById('currentEmotion');
    if (emotionEl) {
        emotionEl.textContent = `${style.emoji || '😐'} ${emotion}`;
        emotionEl.style.color = style.color || '#ffffff';
    }
    
    const headerAvatar = document.getElementById('headerAvatar');
    if (headerAvatar && style.filter) {
        headerAvatar.style.filter = style.filter;
    }
    
    const cardHeader = document.querySelector('.card-header');
    if (cardHeader) {
        cardHeader.className = cardHeader.className.replace(/emotion-\S+/g, '').trim();
        cardHeader.classList.add(`emotion-${emotion}`);
        
        headerAvatar?.classList.remove('shake-avatar', 'glow-avatar', 'intense-pulse');
        cardHeader.classList.remove('header-shake');
        
        if (style.shake) {
            cardHeader.classList.add('header-shake');
            triggerHaptic(style.intense ? 'heavy' : 'medium');
        }
    }
    
    if (style.intense) flashScreen(style.color);
    
    if (previousEmotion !== emotion && ['frightened', 'confused', 'frustrated', 'horrified', 'mad'].includes(emotion)) {
        showEmotionNotification(emotion, style);
    }
}

function showEmotionNotification(emotion, style) {
    const notification = document.createElement('div');
    notification.innerHTML = `
        <span style="font-size: 1.5rem">${style.emoji || '😐'}</span>
        <span>${CONFIG?.general?.bot_name || 'Bot'} is ${emotion}!</span>
    `;
    notification.style.cssText = `
        position: fixed;
        top: 100px;
        left: 50%;
        transform: translate(-50%, -20px);
        background: rgba(26, 26, 26, 0.95);
        border: 2px solid ${style.color || '#ffffff'};
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        color: ${style.color || '#ffffff'};
        font-weight: 600;
        z-index: 10000;
        opacity: 0;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    `;
    
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
// CONVERSATION MANAGEMENT
// ============================================
function clearChat() {
    const botName = CONFIG?.general?.bot_name || 'the bot';
    
    if (!confirm(`Clear your conversation with ${botName}? This cannot be undone.`)) return;
    
    conversationHistory = [];
    messageMetadata = [];
    currentEmotion = CONFIG?.general?.default_emotion || 'neutral';
    currentTheme = CONFIG?.general?.default_theme || 'default';
    
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer && CONFIG) {
        chatContainer.innerHTML = `
            <div class="welcome-message">
                <img src="${CONFIG.general.avatar_image}" alt="${CONFIG.general.bot_name}" class="avatar-large">
                <h4>${CONFIG.general.welcome_title}</h4>
                <p>${CONFIG.general.welcome_message}</p>
            </div>
        `;
    }
    
    updateEmotion(currentEmotion);
    applyTheme(currentTheme);
    saveConversationHistory();
}

function exportChat() {
    if (conversationHistory.length === 0) {
        showNotification('No conversation to export', 'error');
        return;
    }
    
    const botName = CONFIG?.general?.bot_name || 'Bot';
    
    let textContent = `Chat with ${botName}\n`;
    textContent += `Exported: ${new Date().toLocaleString()}\n`;
    textContent += `Total messages: ${conversationHistory.length}\n`;
    textContent += `${'='.repeat(50)}\n\n`;
    
    conversationHistory.forEach((msg, index) => {
        const role = msg.role === 'user' ? 'You' : botName;
        const meta = messageMetadata[index];
        const time = meta?.timestamp ? new Date(meta.timestamp).toLocaleString() : '';
        
        textContent += `${role}${time ? ` (${time})` : ''}:\n`;
        
        msg.content.forEach(item => {
            if (item.type === 'text') textContent += `${item.text}\n`;
            else if (item.type === 'image') textContent += `[Image attached]\n`;
        });
        
        textContent += `\n${'-'.repeat(50)}\n\n`;
    });
    
    downloadFile(textContent, `chat-${botName.toLowerCase().replace(/\s+/g, '-')}-${Date.now()}.txt`, 'text/plain');
    triggerHaptic();
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

function saveConversationHistory() {
    try {
        localStorage.setItem('chatHistory', JSON.stringify({
            history: conversationHistory,
            metadata: messageMetadata,
            emotion: currentEmotion,
            theme: currentTheme
        }));
    } catch (e) {
        console.warn('Failed to save conversation:', e);
    }
}

function loadConversationHistory() {
    try {
        const savedLink = localStorage.getItem('linkEmotionsToThemes');
        if (savedLink !== null) {
            LINK_EMOTIONS_TO_THEMES = savedLink === 'true';
        }
        
        const saved = localStorage.getItem('chatHistory');
        if (!saved) return;
        
        const data = JSON.parse(saved);
        conversationHistory = data.history || [];
        messageMetadata = data.metadata || [];
        currentEmotion = data.emotion || CONFIG?.general?.default_emotion || 'neutral';
        currentTheme = data.theme || CONFIG?.general?.default_theme || 'default';
        
        applyTheme(currentTheme);
        
        if (conversationHistory.length > 0) {
            const chatContainer = document.getElementById('chatContainer');
            if (chatContainer) chatContainer.innerHTML = '';
            
            conversationHistory.forEach((msg, index) => {
                const meta = messageMetadata[index];
                const timestamp = meta?.timestamp ? new Date(meta.timestamp) : new Date();
                
                if (msg.role === 'user') {
                    let text = '';
                    let image = null;
                    
                    msg.content.forEach(item => {
                        if (item.type === 'text') text = item.text;
                        else if (item.type === 'image' && item.source) {
                            image = `data:${item.source.media_type};base64,${item.source.data}`;
                        }
                    });
                    
                    addMessageToChat('user', text, image, null, null, timestamp);
                } else if (msg.role === 'assistant') {
                    const text = msg.content[0]?.text || '';
                    const emotion = meta?.emotion || currentEmotion;
                    addMessageToChat('brainy', text, null, emotion, null, timestamp);
                }
            });
            
            updateEmotion(currentEmotion);
        }
    } catch (e) {
        console.warn('Failed to load conversation:', e);
    }
}

// Make functions available globally for inline onclick handlers
window.toggleThemeMenu = toggleThemeMenu;
window.toggleEmotionThemeLink = toggleEmotionThemeLink;
window.copyMessage = copyMessage;