/**
 * Media Module
 * Image upload, drag & drop, voice recognition
 */

import { state, IMAGE_CONFIG } from './state.js';
import { showNotification, triggerHaptic } from './ui.js';

// ============================================
// IMAGE HANDLING
// ============================================

export function initImageUpload() {
    document.getElementById('imageUploadBtn')?.addEventListener('click', () => {
        document.getElementById('imageUpload')?.click();
    });
    document.getElementById('imageUpload')?.addEventListener('change', handleImageUpload);
    document.getElementById('removeImage')?.addEventListener('click', removeImage);
}

function handleImageUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        showNotification('Please upload a valid image', 'error');
        return;
    }
    processImage(file);
}

async function processImage(file) {
    try {
        const img = new Image();
        const reader = new FileReader();

        reader.onload = (e) => { img.src = e.target.result; };
        img.onload = async function () {
            let width = img.width, height = img.height;
            const originalSize = (file.size / 1024).toFixed(2);

            const ratio = Math.min(IMAGE_CONFIG.maxWidth / width, IMAGE_CONFIG.maxHeight / height, 1);
            width = Math.floor(width * ratio);
            height = Math.floor(height * ratio);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(img, 0, 0, width, height);

            const result = await findBestFormat(canvas);
            state.uploadedImage = result.data;
            state.imageType = result.format;
            displayImagePreview(result, width, height, originalSize);
        };
        img.onerror = () => showNotification('Failed to load image', 'error');
        reader.readAsDataURL(file);
    } catch (error) {
        showNotification('Error processing image: ' + error.message, 'error');
    }
}

async function findBestFormat(canvas) {
    const formats = [
        { format: 'image/webp', name: 'WebP' },
        { format: 'image/jpeg', name: 'JPEG' },
    ];

    let best = null, bestSize = Infinity;

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
            best = { data, format, formatName: name, quality, size };
        }
        if (size <= IMAGE_CONFIG.maxFileSizeKB) break;
    }

    return best;
}

function displayImagePreview(result, width, height, originalSize) {
    const container = document.getElementById('imagePreviewContainer');
    const preview = document.getElementById('imagePreview');
    const stats = document.getElementById('imageStats');

    if (preview) preview.src = result.data;
    if (stats) {
        const tokens = estimateImageTokens(width, height);
        stats.innerHTML = `<small class="text-muted">${width}x${height} &bull; ${result.size.toFixed(1)}KB &bull; ~${tokens} tokens</small>`;
    }
    container?.classList.remove('d-none');
}

export function removeImage() {
    state.uploadedImage = null;
    state.imageType = null;
    document.getElementById('imagePreviewContainer')?.classList.add('d-none');
    const upload = document.getElementById('imageUpload');
    if (upload) upload.value = '';
}

function getBase64Size(base64) {
    const len = base64.length - (base64.indexOf(',') + 1);
    const padding = base64.endsWith('==') ? 2 : base64.endsWith('=') ? 1 : 0;
    return (len * 0.75 - padding) / 1024;
}

function estimateImageTokens(w, h) {
    return Math.ceil(w / 512) * Math.ceil(h / 512) * 85;
}

// ============================================
// DRAG & DROP
// ============================================

export function initDragDrop() {
    const chat = document.getElementById('chatContainer');
    if (!chat) return;

    // Prevent default drag behaviors on the whole document
    for (const evt of ['dragenter', 'dragover', 'dragleave', 'drop']) {
        document.addEventListener(evt, (e) => e.preventDefault());
    }
}

// These are called from inline handlers in HTML
window.handleDragOver = function (e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('dragOverlay')?.classList.add('active');
};

window.handleDrop = function (e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('dragOverlay')?.classList.remove('active');

    const files = e.dataTransfer?.files;
    if (files && files.length > 0) {
        const file = files[0];
        if (file.type.startsWith('image/')) {
            processImage(file);
        } else {
            showNotification('Only image files are supported', 'error');
        }
    }
};

window.handleDragLeave = function (e) {
    e.preventDefault();
    e.stopPropagation();
    // Only hide if we're leaving the container entirely
    const rect = document.getElementById('chatContainer')?.getBoundingClientRect();
    if (rect && (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom)) {
        document.getElementById('dragOverlay')?.classList.remove('active');
    }
};

// ============================================
// VOICE RECOGNITION
// ============================================

export function initVoiceRecognition() {
    const btn = document.getElementById('voiceInputBtn');

    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        if (btn) btn.style.display = 'none';
        return;
    }

    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    state.recognition = new SR();
    state.recognition.continuous = false;
    state.recognition.interimResults = false;
    state.recognition.lang = 'en-US';

    state.recognition.onresult = (event) => {
        const transcript = event.results[0][0].transcript;
        const input = document.getElementById('messageInput');
        if (input) input.value = transcript;
        stopListening();
    };

    state.recognition.onerror = (event) => {
        console.error('Speech error:', event.error);
        stopListening();
        if (event.error !== 'no-speech' && event.error !== 'aborted') {
            const msgs = {
                'audio-capture': 'Microphone not accessible',
                'not-allowed': 'Microphone permission denied',
                'network': 'Network error',
            };
            showNotification(msgs[event.error] || 'Voice input error', 'error');
        }
    };

    state.recognition.onend = stopListening;

    btn?.addEventListener('click', () => {
        state.isListening ? stopListening() : startListening();
    });
}

export function startListening() {
    if (!state.recognition) return;
    state.isListening = true;

    const btn = document.getElementById('voiceInputBtn');
    if (btn) {
        btn.classList.add('listening');
        btn.innerHTML = '&#128308;';
        btn.title = 'Listening... Click to stop';
    }

    try {
        state.recognition.start();
        triggerHaptic('medium');
    } catch (e) {
        stopListening();
    }
}

export function stopListening() {
    if (!state.recognition) return;
    state.isListening = false;

    const btn = document.getElementById('voiceInputBtn');
    if (btn) {
        btn.classList.remove('listening');
        btn.innerHTML = '&#127908;';
        btn.title = 'Voice input';
    }

    try { state.recognition.stop(); }
    catch (e) { /* already stopped */ }
}
