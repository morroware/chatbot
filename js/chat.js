/**
 * Chat Module
 * Message display, streaming, message actions (edit, regenerate, delete, bookmark, TTS, copy)
 */

import { state, CONTEXT_CONFIG, SUGGESTED_PROMPTS } from './state.js';
import * as api from './api.js';
import { applyTheme, updateEmotion, updateTokenCount, showNotification, triggerHaptic, escapeHtml, formatTimestamp, setInputState } from './ui.js';
import { refreshConversationList } from './sidebar.js';
import { triggerMemoryExtraction } from './memory.js';

// ============================================
// MARKDOWN
// ============================================

export function configureMarked() {
    if (typeof marked === 'undefined') return;

    marked.setOptions({
        breaks: true,
        gfm: true,
        highlight(code, lang) {
            if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                try { return hljs.highlight(code, { language: lang }).value; }
                catch (e) { /* fallback */ }
            }
            return code;
        },
    });
}

function renderMarkdown(text) {
    if (typeof marked === 'undefined') return escapeHtml(text);
    try { return marked.parse(text); }
    catch (e) { return escapeHtml(text); }
}

// ============================================
// CONTEXT WINDOW MANAGEMENT
// ============================================

function manageContextWindow(messages) {
    if (messages.length <= CONTEXT_CONFIG.maxMessages) return messages;

    const recent = messages.slice(-CONTEXT_CONFIG.recentToKeep);
    const old = messages.slice(0, -CONTEXT_CONFIG.recentToKeep);

    const summary = old.map(msg => {
        const role = msg.role === 'user' ? 'User' : (state.config?.general?.bot_name || 'Assistant');
        let content = '';
        if (Array.isArray(msg.content)) {
            for (const p of msg.content) {
                if (p.type === 'text') { content = p.text; break; }
                if (p.type === 'image') { content = '[image]'; }
            }
        } else {
            content = msg.content;
        }
        if (content.length > CONTEXT_CONFIG.maxSummaryLength) {
            content = content.substring(0, CONTEXT_CONFIG.maxSummaryLength) + '...';
        }
        return `${role}: ${content}`;
    }).join('\n');

    return [{
        role: 'user',
        content: [{ type: 'text', text: `[CONVERSATION CONTEXT:\n${summary}\n\nContinue naturally.]` }],
    }, ...recent];
}

// ============================================
// SEND MESSAGE
// ============================================

export async function sendMessage(text = null) {
    const input = document.getElementById('messageInput');
    const message = text || input?.value.trim() || '';

    if (!message && !state.uploadedImage) return;
    if (state.isListening) {
        const { stopListening } = await import('./media.js');
        stopListening();
    }

    triggerHaptic();
    setInputState(false);

    const tempImage = state.uploadedImage;
    const tempImageType = state.imageType;
    const timestamp = new Date();

    // Build content
    const userContent = [];
    if (state.uploadedImage) {
        const base64Data = state.uploadedImage.includes(',') ? state.uploadedImage.split(',')[1] : state.uploadedImage;
        userContent.push({
            type: 'image',
            source: { type: 'base64', media_type: state.imageType, data: base64Data },
        });
    }
    if (message) {
        userContent.push({ type: 'text', text: message });
    }

    // Add to UI
    addMessageToChat('user', message, state.uploadedImage, null, null, timestamp);

    // Add to history
    state.conversationHistory.push({ role: 'user', content: userContent });
    state.messageMetadata.push({ role: 'user', emotion: state.currentEmotion, theme: state.currentTheme, timestamp: timestamp.toISOString() });

    // Clear input
    if (input) input.value = '';
    autoResizeTextarea(input);
    const { removeImage } = await import('./media.js');
    removeImage();

    // Show typing indicator
    showTypingIndicator(true);

    // Create streaming placeholder
    const botTimestamp = new Date();
    const streamingDiv = createStreamingMessage(botTimestamp);
    state.streamingTextBuffer = '';

    try {
        await streamResponse(streamingDiv, botTimestamp);
        state.messagesSinceLastExtract++;
        const interval = parseInt(state.config?.general?.memory_extract_interval || '6', 10);
        if (state.config?.general?.enable_memory === 'true' && state.messagesSinceLastExtract >= interval) {
            triggerMemoryExtraction();
            state.messagesSinceLastExtract = 0;
        }
    } catch (error) {
        console.error('Streaming error:', error);
        streamingDiv?.remove();
        if (tempImage) {
            state.uploadedImage = tempImage;
            state.imageType = tempImageType;
        }
        showError('Connection error: ' + error.message);
    } finally {
        setInputState(true);
        showTypingIndicator(false);
        state.currentStreamingMessage = null;
        // Focus back on input
        input?.focus();
    }
}

// ============================================
// STREAMING
// ============================================

function createStreamingMessage(timestamp) {
    const chat = document.getElementById('chatContainer');
    if (!chat || !state.config) return null;

    chat.querySelector('.welcome-message')?.remove();

    const div = document.createElement('div');
    div.className = 'message bot-message streaming';
    div.id = 'streamingMessage';

    div.innerHTML = `
        <img src="${state.config.general.avatar_image || 'avatar.svg'}" alt="${state.config.general.bot_name}" class="avatar">
        <div class="message-content">
            <div class="message-text markdown-content"></div>
            <div class="message-timestamp">${formatTimestamp(timestamp)}</div>
        </div>
    `;

    chat.appendChild(div);
    state.currentStreamingMessage = div;
    chat.scrollTo({ top: chat.scrollHeight, behavior: 'smooth' });

    return div;
}

async function streamResponse(messageDiv, timestamp) {
    const managed = manageContextWindow(state.conversationHistory);

    const response = await api.streamChat(managed, {
        conversationId: state.currentConversationId,
        model: state.currentModel,
        extendedThinking: state.extendedThinking,
        enableTools: state.enableTools,
        enableKB: state.enableKB,
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });

        const events = buffer.split('\n\n');
        buffer = events.pop() || '';

        for (const event of events) {
            if (!event.trim()) continue;

            let eventType = '', eventData = '';
            for (const line of event.split('\n')) {
                if (line.startsWith('event: ')) eventType = line.substring(7);
                else if (line.startsWith('data: ')) eventData = line.substring(6);
            }

            if (eventData) {
                try {
                    const data = JSON.parse(eventData);
                    handleStreamEvent(eventType, data, messageDiv, timestamp);
                } catch (e) { /* skip unparseable */ }
            }
        }
    }
}

function handleStreamEvent(eventType, data, messageDiv, timestamp) {
    const chat = document.getElementById('chatContainer');
    const textEl = messageDiv?.querySelector('.message-text');

    switch (eventType) {
        case 'chunk':
            if (data.text && textEl) {
                state.streamingTextBuffer += data.text;
                let display = state.streamingTextBuffer
                    .replace(/\[THEME:\s*\w+\]/gi, '')
                    .replace(/\[EMOTION:\s*\w+\]/gi, '');
                textEl.innerHTML = renderMarkdown(display);
                if (chat) chat.scrollTop = chat.scrollHeight;
            }
            break;

        case 'done':
            showTypingIndicator(false);
            if (messageDiv) {
                messageDiv.classList.remove('streaming');

                if (textEl && data.fullText) {
                    textEl.innerHTML = renderMarkdown(data.fullText);
                    if (typeof hljs !== 'undefined') {
                        textEl.querySelectorAll('pre code').forEach(b => hljs.highlightElement(b));
                    }
                }

                // Update conversation ID if we got one back
                if (data.conversation_id && !state.currentConversationId) {
                    state.currentConversationId = data.conversation_id;
                }
                if (data.conversation_id) {
                    state.currentConversationId = data.conversation_id;
                }

                // Emotion & theme
                const newEmotion = data.emotion || state.currentEmotion;
                let newTheme = data.theme || state.currentTheme;

                if (state.linkEmotionsToThemes && newEmotion !== state.currentEmotion) {
                    const linked = state.emotionStyles[newEmotion]?.theme || state.emotionThemeMap[newEmotion];
                    if (linked && state.themes[linked]) newTheme = linked;
                }

                messageDiv.classList.add(`emotion-${newEmotion}`);

                if (newEmotion !== state.currentEmotion) updateEmotion(newEmotion);
                if (newTheme !== state.currentTheme && state.themes[newTheme]) applyTheme(newTheme);

                // Token tracking
                if (data.tokens) updateTokenCount(data.tokens);

                // KB/tool indicator
                if (data.kb_chunks_used > 0 || data.tool_calls > 0) {
                    const infoEl = document.createElement('div');
                    infoEl.className = 'message-ai-info';
                    const parts = [];
                    if (data.kb_chunks_used > 0) parts.push(`📚 ${data.kb_chunks_used} KB chunks`);
                    if (data.tool_calls > 0) parts.push(`🔧 ${data.tool_calls} tool${data.tool_calls > 1 ? 's' : ''}`);
                    if (data.tokens?.cache_read > 0) parts.push(`⚡ cached`);
                    infoEl.textContent = parts.join(' · ');
                    messageDiv.querySelector('.message-content')?.appendChild(infoEl);
                }

                // Add message actions
                addMessageActions(messageDiv, data.fullText || state.streamingTextBuffer, 'assistant');

                // History
                state.conversationHistory.push({
                    role: 'assistant',
                    content: [{ type: 'text', text: data.fullText || state.streamingTextBuffer }],
                });
                state.messageMetadata.push({
                    role: 'assistant',
                    emotion: newEmotion,
                    theme: newTheme,
                    timestamp: timestamp.toISOString(),
                });

                // Refresh sidebar to show updated conversation
                refreshConversationList();

                // Suggested replies
                if (state.config?.general?.enable_suggested_replies === 'true') {
                    showSuggestedReplies(data.fullText || state.streamingTextBuffer);
                }
            }
            break;

        case 'tool_start':
            if (messageDiv) {
                let toolContainer = messageDiv.querySelector('.tool-calls');
                if (!toolContainer) {
                    toolContainer = document.createElement('div');
                    toolContainer.className = 'tool-calls';
                    messageDiv.querySelector('.message-content')?.prepend(toolContainer);
                }
                const toolEl = document.createElement('div');
                toolEl.className = 'tool-call pending';
                toolEl.dataset.toolId = data.id;
                toolEl.innerHTML = `<span class="tool-icon">🔧</span> <span class="tool-name">${escapeHtml(data.tool)}</span> <span class="tool-status">calling…</span>`;
                toolContainer.appendChild(toolEl);
                if (chat) chat.scrollTop = chat.scrollHeight;
            }
            break;

        case 'tool_running':
            if (messageDiv) {
                const runEl = messageDiv.querySelector(`[data-tool-id="${data.id}"]`);
                if (runEl) runEl.querySelector('.tool-status').textContent = 'running…';
            }
            break;

        case 'tool_result':
            if (messageDiv) {
                const resultEl = messageDiv.querySelector(`[data-tool-id="${data.id}"]`);
                if (resultEl) {
                    resultEl.classList.remove('pending');
                    resultEl.classList.add('done');
                    resultEl.querySelector('.tool-status').textContent = '✓ done';
                    resultEl.title = data.result_preview || '';
                }
            }
            break;

        case 'thinking_start':
            if (messageDiv) {
                const thinkDiv = document.createElement('div');
                thinkDiv.className = 'thinking-block';
                thinkDiv.innerHTML = '<span class="thinking-label">💭 Thinking…</span><div class="thinking-text"></div>';
                messageDiv.querySelector('.message-content')?.prepend(thinkDiv);
            }
            break;

        case 'thinking_chunk':
            if (messageDiv && data.text) {
                const thinkText = messageDiv.querySelector('.thinking-text');
                if (thinkText) thinkText.textContent += data.text;
            }
            break;

        case 'error':
            showTypingIndicator(false);
            messageDiv?.remove();
            showError(data.error || 'Streaming error');
            break;
    }
}

// ============================================
// DISPLAY MESSAGES
// ============================================

export function addMessageToChat(sender, text, image, emotion, theme, timestamp = new Date(), dbMessageId = null) {
    const chat = document.getElementById('chatContainer');
    if (!chat) return;

    chat.querySelector('.welcome-message')?.remove();
    removeSuggestedReplies();

    const div = document.createElement('div');
    div.className = `message ${sender === 'user' ? 'user-message' : 'bot-message'}`;
    if (dbMessageId) div.dataset.messageId = dbMessageId;

    if (sender === 'user') {
        let content = '';
        if (image) content += `<img src="${image}" alt="User image" class="message-image">`;
        if (text) content += `<div class="message-text">${escapeHtml(text)}</div>`;

        div.innerHTML = `
            <div class="message-content">
                ${content}
                <div class="message-timestamp">${formatTimestamp(timestamp)}</div>
            </div>
        `;

        addMessageActions(div, text, 'user');
    } else {
        const emotionStyle = state.emotionStyles[emotion] || state.emotionStyles[state.currentEmotion] || {};
        div.classList.add(`emotion-${emotion || state.currentEmotion}`);

        const rendered = renderMarkdown(text);
        div.innerHTML = `
            <img src="${state.config?.general?.avatar_image || 'avatar.svg'}" alt="${state.config?.general?.bot_name || 'Bot'}" class="avatar">
            <div class="message-content">
                <div class="message-text markdown-content">${rendered}</div>
                <div class="message-timestamp">${formatTimestamp(timestamp)}</div>
            </div>
        `;

        if (typeof hljs !== 'undefined') {
            div.querySelectorAll('pre code').forEach(b => hljs.highlightElement(b));
        }

        addMessageActions(div, text, 'assistant');
    }

    chat.appendChild(div);
    requestAnimationFrame(() => {
        chat.scrollTo({ top: chat.scrollHeight, behavior: 'smooth' });
    });
}

// ============================================
// MESSAGE ACTIONS
// ============================================

function addMessageActions(messageDiv, text, role) {
    const existing = messageDiv.querySelector('.message-actions');
    if (existing) existing.remove();

    const actions = document.createElement('div');
    actions.className = 'message-actions';

    // Copy
    const copyBtn = createActionBtn('&#128203;', 'Copy', () => {
        navigator.clipboard.writeText(text).then(() => {
            copyBtn.innerHTML = '&#10003;';
            triggerHaptic('light');
            setTimeout(() => { copyBtn.innerHTML = '&#128203;'; }, 1500);
        }).catch(() => {
            showNotification('Failed to copy to clipboard', 'error');
        });
    });
    actions.appendChild(copyBtn);

    // TTS (bot messages only)
    if (role === 'assistant' && state.config?.general?.enable_tts === 'true') {
        const ttsBtn = createActionBtn('&#128266;', 'Read aloud', () => {
            speakText(text, ttsBtn);
        });
        actions.appendChild(ttsBtn);
    }

    // Regenerate (bot messages only)
    if (role === 'assistant') {
        const regenBtn = createActionBtn('&#8635;', 'Regenerate', () => {
            regenerateResponse(messageDiv);
        });
        actions.appendChild(regenBtn);
    }

    // Bookmark
    const bookmarkBtn = createActionBtn('&#9734;', 'Bookmark', () => {
        const isBookmarked = bookmarkBtn.classList.toggle('active');
        bookmarkBtn.innerHTML = isBookmarked ? '&#9733;' : '&#9734;';
        const msgId = messageDiv.dataset.messageId;
        if (msgId) api.bookmarkMessage(parseInt(msgId), isBookmarked);
        triggerHaptic('light');
    });
    actions.appendChild(bookmarkBtn);

    // Delete
    const delBtn = createActionBtn('&#128465;', 'Delete message', () => {
        if (!confirm('Delete this message?')) return;

        // Find index before removal to sync local history
        const allMessages = Array.from(document.querySelectorAll('#chatContainer .message'));
        const idx = allMessages.indexOf(messageDiv);
        if (idx >= 0 && idx < state.conversationHistory.length) {
            state.conversationHistory.splice(idx, 1);
            state.messageMetadata.splice(idx, 1);
        }

        messageDiv.remove();

        // Delete from DB if we have an ID
        const msgId = messageDiv.dataset.messageId;
        if (msgId) api.deleteMessage(parseInt(msgId));
    });
    actions.appendChild(delBtn);

    const content = messageDiv.querySelector('.message-content');
    if (content) content.appendChild(actions);
}

function createActionBtn(icon, title, handler) {
    const btn = document.createElement('button');
    btn.className = 'msg-action-btn';
    btn.innerHTML = icon;
    btn.title = title;
    btn.addEventListener('click', handler);
    return btn;
}

// ============================================
// REGENERATE
// ============================================

async function regenerateResponse(messageDiv) {
    // Remove the bot message
    messageDiv.remove();

    // Remove last assistant message from history
    if (state.conversationHistory.length > 0 && state.conversationHistory[state.conversationHistory.length - 1].role === 'assistant') {
        state.conversationHistory.pop();
        state.messageMetadata.pop();
    }

    setInputState(false);
    showTypingIndicator(true);

    const botTimestamp = new Date();
    const streamingDiv = createStreamingMessage(botTimestamp);
    state.streamingTextBuffer = '';

    try {
        await streamResponse(streamingDiv, botTimestamp);
    } catch (error) {
        streamingDiv?.remove();
        showError('Regeneration failed: ' + error.message);
    } finally {
        setInputState(true);
        showTypingIndicator(false);
        state.currentStreamingMessage = null;
    }
}

// ============================================
// TTS (Text-to-Speech)
// ============================================

function speakText(text, btn) {
    if (!('speechSynthesis' in window)) {
        showNotification('Text-to-speech not supported', 'error');
        return;
    }

    // Stop if already speaking
    if (speechSynthesis.speaking) {
        speechSynthesis.cancel();
        if (btn) btn.innerHTML = '&#128266;';
        return;
    }

    // Strip markdown for TTS
    const clean = text
        .replace(/```[\s\S]*?```/g, ' [code block] ')
        .replace(/`[^`]+`/g, (m) => m.slice(1, -1))
        .replace(/\*\*([^*]+)\*\*/g, '$1')
        .replace(/\*([^*]+)\*/g, '$1')
        .replace(/#{1,6}\s/g, '')
        .replace(/[[\]()]/g, '')
        .replace(/\n+/g, '. ')
        .trim();

    const utterance = new SpeechSynthesisUtterance(clean);
    utterance.rate = 1.0;
    utterance.pitch = 1.0;

    utterance.onstart = () => { if (btn) btn.innerHTML = '&#9209;'; };
    utterance.onend = () => { if (btn) btn.innerHTML = '&#128266;'; };
    utterance.onerror = () => { if (btn) btn.innerHTML = '&#128266;'; };

    speechSynthesis.speak(utterance);
}

// ============================================
// TYPING INDICATOR
// ============================================

function showTypingIndicator(show) {
    const el = document.getElementById('typingIndicator');
    if (el) el.style.display = show ? 'flex' : 'none';
}

// ============================================
// SUGGESTED REPLIES
// ============================================

function showSuggestedReplies(responseText) {
    removeSuggestedReplies();

    // Simple heuristic-based suggestions
    const suggestions = [];

    if (responseText.includes('?')) {
        suggestions.push('Yes', 'No', 'Tell me more');
    } else if (responseText.length > 500) {
        suggestions.push('Summarize that', 'Give me an example', 'What else?');
    } else {
        suggestions.push('Tell me more', 'Thanks!', 'Can you elaborate?');
    }

    const chat = document.getElementById('chatContainer');
    if (!chat) return;

    const container = document.createElement('div');
    container.className = 'suggested-replies';
    container.id = 'suggestedRepliesContainer';

    for (const text of suggestions) {
        const btn = document.createElement('button');
        btn.className = 'suggested-reply-btn';
        btn.textContent = text;
        btn.addEventListener('click', () => {
            removeSuggestedReplies();
            sendMessage(text);
        });
        container.appendChild(btn);
    }

    chat.appendChild(container);
    chat.scrollTo({ top: chat.scrollHeight, behavior: 'smooth' });
}

function removeSuggestedReplies() {
    document.getElementById('suggestedRepliesContainer')?.remove();
}

// ============================================
// WELCOME SUGGESTED PROMPTS
// ============================================

export function setupSuggestedPrompts() {
    const container = document.getElementById('suggestedPrompts');
    if (!container) return;

    container.innerHTML = '';

    // Pick 3 random prompts
    const shuffled = [...SUGGESTED_PROMPTS].sort(() => Math.random() - 0.5).slice(0, 3);

    for (const prompt of shuffled) {
        const btn = document.createElement('button');
        btn.className = 'suggested-prompt-btn';
        btn.textContent = prompt;
        btn.addEventListener('click', () => sendMessage(prompt));
        container.appendChild(btn);
    }
}

// ============================================
// ERROR DISPLAY
// ============================================

function showError(message) {
    triggerHaptic();
    addMessageToChat('bot', `Sorry, I ran into an issue: ${message}`, null, 'concerned');
}

// ============================================
// LOAD CONVERSATION FROM DB
// ============================================

export async function loadConversation(conversationId) {
    if (!conversationId) return;

    const data = await api.getConversation(conversationId);
    if (!data) return;

    state.currentConversationId = conversationId;
    state.conversationHistory = [];
    state.messageMetadata = [];

    const chat = document.getElementById('chatContainer');
    if (chat) chat.innerHTML = '';

    if (data.messages.length === 0) {
        showWelcome();
        return;
    }

    for (const msg of data.messages) {
        let content, text = '', image = null;

        // Try to parse JSON content
        try {
            content = JSON.parse(msg.content);
        } catch (e) {
            content = msg.content;
        }

        if (Array.isArray(content)) {
            for (const part of content) {
                if (part.type === 'text') text = part.text;
                else if (part.type === 'image' && part.source) {
                    image = `data:${part.source.media_type};base64,${part.source.data}`;
                }
            }
            state.conversationHistory.push({ role: msg.role, content });
        } else {
            text = typeof content === 'string' ? content : JSON.stringify(content);
            state.conversationHistory.push({
                role: msg.role,
                content: [{ type: 'text', text }],
            });
        }

        const timestamp = new Date(msg.created_at + 'Z');
        state.messageMetadata.push({
            role: msg.role,
            emotion: msg.emotion || state.currentEmotion,
            theme: msg.theme || state.currentTheme,
            timestamp: timestamp.toISOString(),
        });

        if (msg.role === 'user') {
            addMessageToChat('user', text, image, null, null, timestamp, msg.id);
        } else {
            addMessageToChat('bot', text, null, msg.emotion, msg.theme, timestamp, msg.id);
            if (msg.emotion) updateEmotion(msg.emotion);
            if (msg.theme && state.themes[msg.theme]) applyTheme(msg.theme);
        }
    }
}

export function showWelcome() {
    const chat = document.getElementById('chatContainer');
    if (!chat || !state.config) return;

    const gen = state.config.general;
    chat.innerHTML = `
        <div class="welcome-message" id="welcomeMessage">
            <img id="welcomeAvatar" src="${gen.avatar_image || 'avatar.svg'}" alt="${gen.bot_name}" class="avatar-large">
            <h4 id="welcomeTitle">${gen.welcome_title || 'Welcome!'}</h4>
            <p id="welcomeText">${gen.welcome_message || 'How can I help you today?'}</p>
            <div class="suggested-prompts" id="suggestedPrompts"></div>
        </div>
    `;
    setupSuggestedPrompts();
}

// ============================================
// NEW CHAT
// ============================================

export async function newChat() {
    state.currentConversationId = null;
    state.conversationHistory = [];
    state.messageMetadata = [];
    state.currentEmotion = state.config?.general?.default_emotion || 'neutral';
    state.currentTheme = state.config?.general?.default_theme || 'default';

    updateEmotion(state.currentEmotion);
    applyTheme(state.currentTheme);
    showWelcome();
    refreshConversationList();

    document.getElementById('messageInput')?.focus();
}

// ============================================
// TEXTAREA AUTO-RESIZE
// ============================================

export function autoResizeTextarea(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 150) + 'px';
}
