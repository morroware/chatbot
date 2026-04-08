<?php
/**
 * Non-Streaming API Endpoint for Chatbot
 * Supports: multi-model, memory injection, token tracking, conversation persistence
 */
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

// ============================================
// RATE LIMITING
// ============================================
function checkRateLimit() {
    $now = time();
    $limit = 15;
    $window = 60;

    if (!isset($_SESSION['api_requests'])) {
        $_SESSION['api_requests'] = [];
    }

    $_SESSION['api_requests'] = array_filter(
        $_SESSION['api_requests'],
        function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        }
    );

    if (count($_SESSION['api_requests']) >= $limit) {
        return false;
    }

    $_SESSION['api_requests'][] = $now;
    return true;
}

if (!checkRateLimit()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit exceeded. Please wait a moment.',
        'code' => 'RATE_LIMIT_EXCEEDED'
    ]);
    exit;
}

// ============================================
// LOAD CONFIGURATIONS
// ============================================
$config = parse_ini_file('config.ini', true);
$emotions = parse_ini_file('emotions.ini', true);
$themes = parse_ini_file('themes.ini', true);

if (!$config || !$emotions || !$themes) {
    echo json_encode(['success' => false, 'error' => 'Configuration files not found', 'code' => 'CONFIG_ERROR']);
    exit;
}

$apiKey = trim($config['api']['api_key'] ?? '');

if (empty($apiKey) || $apiKey === 'YourKeyHere') {
    echo json_encode(['success' => false, 'error' => 'API key not configured', 'code' => 'API_KEY_MISSING']);
    exit;
}

// ============================================
// GET AND VALIDATE INPUT
// ============================================
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON', 'code' => 'INVALID_JSON']);
    exit;
}

if (!isset($data['messages']) && !isset($data['prompt'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields', 'code' => 'INVALID_REQUEST']);
    exit;
}

// ============================================
// RESOLVE MODEL
// ============================================
$requestedModel = $data['model'] ?? $config['general']['model'] ?? 'claude-sonnet-4-6';
if (isset($config['model_ids'][$requestedModel])) {
    $modelId = $config['model_ids'][$requestedModel];
} else {
    $modelId = $requestedModel;
}

// ============================================
// CONVERSATION PERSISTENCE
// ============================================
$conversationId = $data['conversation_id'] ?? null;

if ($conversationId) {
    $conversation = getConversation($conversationId);
    if (!$conversation) {
        $conversationId = createConversation('New Chat', $modelId);
    }
} else {
    $conversationId = createConversation('New Chat', $modelId);
}

// ============================================
// BUILD SYSTEM PROMPT
// ============================================
function buildSystemPrompt($config, $emotions, $themes) {
    $gen = $config['general'];
    $pers = $config['personality'];

    $baseDesc = str_replace(
        ['{bot_name}', '{bot_description}'],
        [$gen['bot_name'], $gen['bot_description']],
        $pers['base_description']
    );

    $emotionList = [];
    foreach ($emotions as $key => $emotion) {
        $emotionList[] = "- **{$key}** ({$emotion['emoji']} {$emotion['label']}): {$emotion['description']}";
    }
    $emotionText = implode("\n", $emotionList);

    $themeList = [];
    foreach ($themes as $key => $theme) {
        $themeList[] = "- **{$key}**: {$theme['description']}";
    }
    $themeText = implode("\n", $themeList);

    $examples = explode('|', $pers['trait_examples']);
    $exampleText = '';
    foreach ($examples as $example) {
        $exampleText .= "- $example\n";
    }

    $emotionThemeHints = '';
    if (isset($config['emotion_theme_map']) && is_array($config['emotion_theme_map'])) {
        $emotionThemeHints = "\nEMOTION-THEME PAIRINGS:\n";
        foreach ($config['emotion_theme_map'] as $emotionKey => $themeKey) {
            if (isset($emotions[$emotionKey]) && isset($themes[$themeKey])) {
                $emotionThemeHints .= "- {$emotionKey} → {$themeKey}\n";
            }
        }
    }

    $memoryContext = buildMemoryContext();

    $prompt = <<<EOT
$baseDesc

{$pers['speaking_style']}

Your personality: {$pers['special_trait']}

Behavioral examples:
$exampleText

{$pers['formatting_note']}
{$pers['brevity_note']}
$memoryContext
═══════════════════════════════════════════════════════════════
EMOTION AND THEME TAGS (REQUIRED)
═══════════════════════════════════════════════════════════════

You MUST end EVERY response with:
[THEME: theme_name]
[EMOTION: emotion_name]

AVAILABLE EMOTIONS:
$emotionText

AVAILABLE THEMES:
$themeText
$emotionThemeHints

These tags are REQUIRED. The UI depends on them.
EOT;

    return $prompt;
}

// ============================================
// BUILD MESSAGES
// ============================================
$messages = [];

if (isset($data['messages']) && is_array($data['messages'])) {
    foreach ($data['messages'] as $msg) {
        if (!isset($msg['role']) || !isset($msg['content'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid message format']);
            exit;
        }
    }
    $messages = $data['messages'];
} else {
    $content = [];

    if (isset($data['image'])) {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $imageType = $data['imageType'] ?? 'image/jpeg';
        if (!in_array($imageType, $allowedTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid image type']);
            exit;
        }
        $content[] = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $imageType, 'data' => $data['image']]
        ];
    }

    $content[] = ['type' => 'text', 'text' => $data['prompt']];
    $messages[] = ['role' => 'user', 'content' => $content];
}

// Save user message
$lastUserMessage = end($messages);
if ($lastUserMessage && $lastUserMessage['role'] === 'user') {
    addMessage($conversationId, 'user', $lastUserMessage['content']);
    $conv = getConversation($conversationId);
    if ($conv && $conv['message_count'] <= 1) {
        autoTitleConversation($conversationId, $lastUserMessage['content']);
    }
}

// ============================================
// PREPARE API REQUEST
// ============================================
$systemPrompt = buildSystemPrompt($config, $emotions, $themes);
$temperature = floatval($data['temperature'] ?? $config['general']['temperature'] ?? 0.7);

$requestData = [
    'model' => $modelId,
    'max_tokens' => (int)($data['max_tokens'] ?? $config['general']['max_tokens'] ?? 4096),
    'system' => $systemPrompt,
    'messages' => $messages,
    'temperature' => $temperature
];

// ============================================
// MAKE API CALL
// ============================================
$ch = curl_init($config['api']['endpoint']);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: ' . $config['api']['anthropic_version']
    ],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['success' => false, 'error' => 'Network error', 'code' => 'NETWORK_ERROR']);
    exit;
}

$responseData = json_decode($response, true);

if ($responseData === null) {
    echo json_encode(['success' => false, 'error' => 'Failed to parse API response', 'code' => 'PARSE_ERROR']);
    exit;
}

if ($httpCode !== 200) {
    $errorMessage = $responseData['error']['message'] ?? 'API Error';
    if ($httpCode === 401) $errorMessage = 'Invalid API key.';
    elseif ($httpCode === 429) $errorMessage = 'Rate limit exceeded. Try again shortly.';
    elseif ($httpCode === 500) $errorMessage = 'API server error. Try again later.';

    echo json_encode(['success' => false, 'error' => $errorMessage, 'code' => 'API_ERROR_' . $httpCode]);
    exit;
}

// ============================================
// EXTRACT RESPONSE
// ============================================
$inputTokens = $responseData['usage']['input_tokens'] ?? 0;
$outputTokens = $responseData['usage']['output_tokens'] ?? 0;

if (isset($responseData['content']) && is_array($responseData['content'])) {
    foreach ($responseData['content'] as $block) {
        if ($block['type'] === 'text') {
            $text = $block['text'];

            $theme = $config['general']['default_theme'];
            if (preg_match('/\[THEME:\s*(\w+)\]/i', $text, $matches)) {
                $extracted = strtolower($matches[1]);
                if (isset($themes[$extracted])) $theme = $extracted;
                $text = preg_replace('/\[THEME:\s*\w+\]/i', '', $text);
            }

            $emotion = $config['general']['default_emotion'];
            if (preg_match('/\[EMOTION:\s*(\w+)\]/i', $text, $matches)) {
                $extracted = strtolower($matches[1]);
                if (isset($emotions[$extracted])) $emotion = $extracted;
                $text = preg_replace('/\[EMOTION:\s*\w+\]/i', '', $text);
            }

            $text = trim($text);

            // Save assistant message
            addMessage($conversationId, 'assistant', $text, [
                'emotion' => $emotion,
                'theme' => $theme,
                'tokens_in' => $inputTokens,
                'tokens_out' => $outputTokens,
                'model' => $modelId
            ]);

            echo json_encode([
                'success' => true,
                'result' => $text,
                'emotion' => $emotion,
                'theme' => $theme,
                'conversation_id' => $conversationId,
                'tokens' => [
                    'input' => $inputTokens,
                    'output' => $outputTokens,
                    'total' => $inputTokens + $outputTokens
                ],
                'model' => $modelId
            ]);
            exit;
        }
    }
}

echo json_encode(['success' => false, 'error' => 'Unexpected response format', 'code' => 'UNEXPECTED_FORMAT']);
