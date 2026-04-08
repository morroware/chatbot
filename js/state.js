/**
 * Global State & Constants
 * Central state store for the entire application
 */

export const state = {
    // Configuration (loaded from server)
    config: null,
    emotions: null,
    themes: {},
    emotionStyles: {},
    emotionThemeMap: {},
    availableModels: {},
    linkEmotionsToThemes: true,

    // Current session
    currentConversationId: null,
    currentEmotion: 'neutral',
    currentTheme: 'default',
    currentModel: null,
    darkMode: true,

    // Conversation data
    conversationHistory: [],
    messageMetadata: [],
    conversations: [],

    // Media state
    uploadedImage: null,
    imageType: null,
    recognition: null,
    isListening: false,
    ttsEnabled: false,
    ttsSpeaking: false,

    // Streaming
    currentStreamingMessage: null,
    streamingTextBuffer: '',

    // UI state
    sidebarOpen: true,
    memoryPanelOpen: false,

    // Token tracking
    sessionTokens: { input: 0, output: 0 },

    // Memory extraction tracking
    messagesSinceLastExtract: 0,
};

export const CONTEXT_CONFIG = {
    maxMessages: 20,
    recentToKeep: 10,
    maxSummaryLength: 200,
};

export const IMAGE_CONFIG = {
    maxWidth: 1024,
    maxHeight: 1024,
    quality: 0.75,
    maxFileSizeKB: 200,
    minQuality: 0.4,
};

export const SUGGESTED_PROMPTS = [
    "What can you help me with?",
    "Tell me something interesting",
    "Help me brainstorm ideas",
    "Explain a complex topic simply",
    "Write something creative",
    "Help me solve a problem",
];
