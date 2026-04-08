/**
 * Knowledge Base Module
 * Manage uploaded documents for RAG (Retrieval-Augmented Generation)
 */

import { state } from './state.js';
import { showNotification, escapeHtml, formatTimestamp } from './ui.js';

const ALLOWED_TYPES = ['.txt', '.md', '.pdf', '.docx', '.doc', '.csv', '.json', '.html', '.htm', '.rtf'];
const MAX_SIZE_MB = 20;

// ============================================
// PANEL INIT
// ============================================

export function initKnowledgePanel() {
    const btn = document.getElementById('knowledgeBtn');
    const closeBtn = document.getElementById('closeKnowledge');
    const panel = document.getElementById('knowledgePanel');

    btn?.addEventListener('click', () => toggleKnowledgePanel());
    closeBtn?.addEventListener('click', () => closeKnowledgePanel());

    // File upload
    const uploadArea = document.getElementById('kbUploadArea');
    const fileInput = document.getElementById('kbFileInput');
    const uploadBtn = document.getElementById('kbUploadBtn');

    uploadArea?.addEventListener('click', () => fileInput?.click());
    uploadArea?.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('drag-over'); });
    uploadArea?.addEventListener('dragleave', () => uploadArea.classList.remove('drag-over'));
    uploadArea?.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('drag-over');
        const files = Array.from(e.dataTransfer.files);
        if (files.length) handleFileUpload(files[0]);
    });

    fileInput?.addEventListener('change', () => {
        if (fileInput.files.length) handleFileUpload(fileInput.files[0]);
        fileInput.value = '';
    });

    uploadBtn?.addEventListener('click', () => fileInput?.click());

    // Search
    const searchInput = document.getElementById('kbSearchInput');
    let searchTimeout;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const q = searchInput.value.trim();
            if (q.length >= 2) searchKB(q);
            else renderFileList();
        }, 400);
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (panel && !panel.contains(e.target) && btn && !btn.contains(e.target)) {
            if (state.knowledgePanelOpen) closeKnowledgePanel();
        }
    });
}

export function toggleKnowledgePanel() {
    const panel = document.getElementById('knowledgePanel');
    if (!panel) return;
    if (state.knowledgePanelOpen) {
        closeKnowledgePanel();
    } else {
        openKnowledgePanel();
    }
}

function openKnowledgePanel() {
    const panel = document.getElementById('knowledgePanel');
    if (!panel) return;
    panel.classList.add('open');
    state.knowledgePanelOpen = true;
    document.getElementById('knowledgeBtn')?.classList.add('active');
    loadFileList();
}

function closeKnowledgePanel() {
    const panel = document.getElementById('knowledgePanel');
    if (!panel) return;
    panel.classList.remove('open');
    state.knowledgePanelOpen = false;
    document.getElementById('knowledgeBtn')?.classList.remove('active');
}

// ============================================
// FILE LIST
// ============================================

async function loadFileList() {
    try {
        const res = await fetch('api-knowledge.php?action=list');
        const data = await res.json();
        if (data.success) {
            state.knowledgeFiles = data.files || [];
            renderFileList();
        }
    } catch (e) {
        console.error('KB load error:', e);
    }
}

function renderFileList() {
    const container = document.getElementById('kbFileList');
    if (!container) return;

    const files = state.knowledgeFiles || [];

    if (!files.length) {
        container.innerHTML = `
            <div class="kb-empty">
                <div class="kb-empty-icon">📚</div>
                <div class="kb-empty-text">No documents yet</div>
                <div class="kb-empty-sub">Upload PDFs, Word docs, text files, and more</div>
            </div>`;
        return;
    }

    container.innerHTML = files.map(file => createFileCard(file)).join('');

    // Bind delete buttons
    container.querySelectorAll('[data-delete-file]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(btn.dataset.deleteFile);
            deleteFile(id);
        });
    });

    // Bind view buttons
    container.querySelectorAll('[data-view-file]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(btn.dataset.viewFile);
            viewFileDetails(id);
        });
    });

    updateKBBadge(files.length);
}

function createFileCard(file) {
    const icon = getFileIcon(file.file_type);
    const size = formatFileSize(file.file_size);
    const date = new Date(file.created_at).toLocaleDateString();
    const tags = file.tags ? file.tags.split(',').map(t => `<span class="kb-tag">${escapeHtml(t.trim())}</span>`).join('') : '';
    const status = file.status === 'ready'
        ? '<span class="kb-status ready">Ready</span>'
        : '<span class="kb-status processing">Processing</span>';

    return `
        <div class="kb-file-card" data-file-id="${file.id}">
            <div class="kb-file-icon">${icon}</div>
            <div class="kb-file-info">
                <div class="kb-file-name" title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</div>
                <div class="kb-file-meta">
                    ${status}
                    <span class="kb-chunks">${file.chunk_count} chunks</span>
                    <span class="kb-size">${size}</span>
                    <span class="kb-date">${date}</span>
                </div>
                ${file.description ? `<div class="kb-file-desc">${escapeHtml(file.description)}</div>` : ''}
                ${tags ? `<div class="kb-tags">${tags}</div>` : ''}
            </div>
            <div class="kb-file-actions">
                <button class="kb-btn-icon" data-view-file="${file.id}" title="View details">🔍</button>
                <button class="kb-btn-icon danger" data-delete-file="${file.id}" title="Delete">🗑️</button>
            </div>
        </div>`;
}

function getFileIcon(ext) {
    const icons = {
        pdf: '📕', docx: '📘', doc: '📘', txt: '📄', md: '📝',
        csv: '📊', json: '⚙️', html: '🌐', htm: '🌐', rtf: '📃',
    };
    return icons[ext] || '📄';
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function updateKBBadge(count) {
    const badge = document.getElementById('kbBadge');
    if (!badge) return;
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// ============================================
// FILE UPLOAD
// ============================================

async function handleFileUpload(file) {
    const ext = '.' + file.name.split('.').pop().toLowerCase();
    if (!ALLOWED_TYPES.includes(ext)) {
        showNotification(`File type ${ext} not supported. Allowed: ${ALLOWED_TYPES.join(', ')}`, 'error');
        return;
    }

    if (file.size > MAX_SIZE_MB * 1024 * 1024) {
        showNotification(`File too large. Max ${MAX_SIZE_MB}MB`, 'error');
        return;
    }

    const description = document.getElementById('kbDescription')?.value?.trim() || '';
    const tags = document.getElementById('kbTags')?.value?.trim() || '';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'upload');
    if (description) formData.append('description', description);
    if (tags) formData.append('tags', tags);

    // Show progress
    const progressEl = document.getElementById('kbUploadProgress');
    const progressBar = document.getElementById('kbProgressBar');
    if (progressEl) progressEl.style.display = 'block';
    if (progressBar) progressBar.style.width = '20%';

    try {
        // Simulate progress while uploading
        let progress = 20;
        const progressInterval = setInterval(() => {
            progress = Math.min(progress + 10, 85);
            if (progressBar) progressBar.style.width = progress + '%';
        }, 300);

        const res = await fetch('api-knowledge.php', {
            method: 'POST',
            body: formData,
        });

        clearInterval(progressInterval);
        if (progressBar) progressBar.style.width = '100%';

        const data = await res.json();

        if (data.success) {
            showNotification(`✅ "${file.name}" uploaded (${data.chunks_created} chunks created)`, 'success');
            if (document.getElementById('kbDescription')) document.getElementById('kbDescription').value = '';
            if (document.getElementById('kbTags')) document.getElementById('kbTags').value = '';
            await loadFileList();
        } else {
            showNotification('Upload failed: ' + data.error, 'error');
        }
    } catch (e) {
        showNotification('Upload error: ' + e.message, 'error');
    } finally {
        setTimeout(() => {
            if (progressEl) progressEl.style.display = 'none';
            if (progressBar) progressBar.style.width = '0%';
        }, 800);
    }
}

// ============================================
// FILE OPERATIONS
// ============================================

async function deleteFile(id) {
    const file = (state.knowledgeFiles || []).find(f => f.id === id);
    const name = file?.original_name || 'this file';

    if (!confirm(`Delete "${name}"? This will remove all its content from the knowledge base.`)) return;

    try {
        const res = await fetch('api-knowledge.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        const data = await res.json();
        if (data.success) {
            showNotification('File deleted', 'success');
            await loadFileList();
        } else {
            showNotification('Delete failed: ' + data.error, 'error');
        }
    } catch (e) {
        showNotification('Error: ' + e.message, 'error');
    }
}

async function viewFileDetails(id) {
    try {
        const res = await fetch(`api-knowledge.php?action=get&id=${id}`);
        const data = await res.json();
        if (!data.success) return;

        const file = data.file;
        const chunks = data.chunks || [];

        const modal = document.getElementById('kbDetailModal');
        const body = document.getElementById('kbDetailBody');
        if (!modal || !body) return;

        body.innerHTML = `
            <h3>${escapeHtml(file.original_name)}</h3>
            <div class="kb-detail-meta">
                <span>${getFileIcon(file.file_type)} ${file.file_type.toUpperCase()}</span>
                <span>${formatFileSize(file.file_size)}</span>
                <span>${file.chunk_count} chunks</span>
                <span>Added ${new Date(file.created_at).toLocaleDateString()}</span>
            </div>
            ${file.description ? `<p class="kb-detail-desc">${escapeHtml(file.description)}</p>` : ''}
            ${file.tags ? `<div class="kb-tags">${file.tags.split(',').map(t => `<span class="kb-tag">${escapeHtml(t.trim())}</span>`).join('')}</div>` : ''}
            <h4>Content Preview (first ${chunks.length} chunks)</h4>
            <div class="kb-chunks-preview">
                ${chunks.map((c, i) => `
                    <div class="kb-chunk-item">
                        <div class="kb-chunk-header">Chunk ${c.chunk_index + 1} · ${c.word_count} words</div>
                        <div class="kb-chunk-text">${escapeHtml(c.preview)}${c.preview.length >= 200 ? '…' : ''}</div>
                    </div>`).join('')}
            </div>`;

        modal.classList.add('open');
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        }, { once: true });

        document.getElementById('kbDetailClose')?.addEventListener('click', () => modal.classList.remove('open'), { once: true });
    } catch (e) {
        showNotification('Error loading details', 'error');
    }
}

// ============================================
// SEARCH
// ============================================

async function searchKB(query) {
    const container = document.getElementById('kbFileList');
    if (!container) return;

    try {
        const res = await fetch(`api-knowledge.php?action=search&q=${encodeURIComponent(query)}&limit=10`);
        const data = await res.json();

        if (!data.success || !data.results.length) {
            container.innerHTML = `<div class="kb-empty"><div class="kb-empty-text">No results for "${escapeHtml(query)}"</div></div>`;
            return;
        }

        container.innerHTML = `
            <div class="kb-search-header">Search results for "<strong>${escapeHtml(query)}</strong>" (${data.results.length})</div>
            ${data.results.map(r => `
                <div class="kb-search-result">
                    <div class="kb-result-source">${getFileIcon(r.file_type || 'txt')} ${escapeHtml(r.original_name)}</div>
                    <div class="kb-result-excerpt">${escapeHtml(r.content.substring(0, 300))}…</div>
                </div>`).join('')}`;
    } catch (e) {
        console.error('KB search error:', e);
    }
}
