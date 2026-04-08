/**
 * API Layer
 * All server communication in one place
 */

// ============================================
// CONFIG
// ============================================

export async function fetchConfig() {
    const res = await fetch('get-config.php');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Config load failed');
    return data;
}

// ============================================
// CONVERSATIONS
// ============================================

export async function fetchConversations(search = null) {
    const params = new URLSearchParams({ action: 'list' });
    if (search) params.set('search', search);
    const res = await fetch(`api-conversations.php?${params}`);
    if (!res.ok) return [];
    const data = await res.json();
    return data.success ? data.conversations : [];
}

export async function createConversation(title = 'New Chat', model = null) {
    const res = await fetch('api-conversations.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title, model }),
    });
    const data = await res.json();
    return data.success ? data.conversation : null;
}

export async function getConversation(id) {
    const res = await fetch(`api-conversations.php?action=get&id=${encodeURIComponent(id)}`);
    const data = await res.json();
    return data.success ? data : null;
}

export async function updateConversation(id, updates) {
    await fetch('api-conversations.php?action=update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, ...updates }),
    });
}

export async function deleteConversation(id) {
    await fetch('api-conversations.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
    });
}

export async function searchConversations(query) {
    const res = await fetch(`api-conversations.php?action=search&q=${encodeURIComponent(query)}`);
    const data = await res.json();
    return data.success ? data.results : [];
}

export async function exportConversation(id, format = 'json') {
    const res = await fetch(`api-conversations.php?action=export&id=${encodeURIComponent(id)}&format=${format}`);
    if (format === 'json') {
        return await res.json();
    }
    return await res.text();
}

// ============================================
// MESSAGES
// ============================================

export async function editMessage(messageId, content) {
    await fetch('api-conversations.php?action=edit_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message_id: messageId, content }),
    });
}

export async function deleteMessage(messageId, conversationId = null, deleteAfter = false) {
    await fetch('api-conversations.php?action=delete_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message_id: messageId, conversation_id: conversationId, delete_after: deleteAfter }),
    });
}

export async function bookmarkMessage(messageId, bookmarked = true) {
    await fetch('api-conversations.php?action=bookmark_message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message_id: messageId, bookmarked }),
    });
}

// ============================================
// MEMORY
// ============================================

export async function fetchMemories() {
    const res = await fetch('api-memory.php?action=list');
    const data = await res.json();
    return data.success ? data.memories : [];
}

export async function addMemory(fact, factType = 'general') {
    const res = await fetch('api-memory.php?action=add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fact, fact_type: factType }),
    });
    const data = await res.json();
    return data.success ? data.id : null;
}

export async function deleteMemoryApi(id) {
    await fetch('api-memory.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
    });
}

export async function extractMemories(messages, existingMemories = []) {
    const res = await fetch('api-memory.php?action=extract', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages, existing_memories: existingMemories }),
    });
    const data = await res.json();
    return data.success ? data.facts : [];
}

// ============================================
// STREAMING CHAT
// ============================================

export async function streamChat(messages, options = {}) {
    const body = {
        messages,
        conversation_id: options.conversationId || null,
        model: options.model || null,
        temperature: options.temperature || undefined,
        max_tokens: options.maxTokens || undefined,
    };

    const response = await fetch('api-stream.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`HTTP error: ${response.status}`);
    }

    return response;
}
