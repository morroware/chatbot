<?php
/**
 * Streaming API Endpoint for Chatbot
 * Supports: SSE streaming, multi-model, memory injection, token tracking, conversation persistence
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

// ============================================
// SSE HEADERS
// ============================================
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level()) {
    ob_end_flush();
}

function sendEvent($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

function sendError($message, $code = 'ERROR') {
    sendEvent('error', ['error' => $message, 'code' => $code]);
    exit;
}

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
    sendError('Rate limit exceeded. Please wait a moment.', 'RATE_LIMIT_EXCEEDED');
}

// ============================================
// LOAD CONFIGURATIONS
// ============================================
$config = parse_ini_file(__DIR__ . '/config.ini', true);
$emotions = parse_ini_file(__DIR__ . '/emotions.ini', true);
$themes = parse_ini_file(__DIR__ . '/themes.ini', true);

if (!$config || !$emotions || !$themes) {
    sendError('Configuration files not found or invalid', 'CONFIG_ERROR');
}

// ============================================
// VALIDATE API KEY
// ============================================
$apiKey = trim($config['api']['api_key'] ?? '');

if (empty($apiKey) || $apiKey === 'YourKeyHere') {
    sendError('API key not configured. Please add your API key in the admin panel.', 'API_KEY_MISSING');
}

// ============================================
// GET AND VALIDATE INPUT
// ============================================
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON in request', 'INVALID_JSON');
}

if (!isset($data['messages']) && !isset($data['prompt'])) {
    sendError('Missing required fields', 'INVALID_REQUEST');
}

// ============================================
// RESOLVE MODEL
// ============================================
$requestedModel = $data['model'] ?? $config['general']['model'] ?? 'claude-sonnet-4-6';

// Check if it's a model key from our config
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

    // Inject memory context
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

You MUST end EVERY response with TWO tags on their own lines:

[THEME: theme_name]
[EMOTION: emotion_name]

AVAILABLE EMOTIONS:
$emotionText

AVAILABLE THEMES:
$themeText
$emotionThemeHints

RULES:
1. Match EMOTION to your current feeling about the conversation
2. Match THEME to the mood/atmosphere
3. Change emotions naturally as the conversation evolves
4. These tags are REQUIRED on every response - the UI depends on them
5. Tags must be the LAST two lines of your response
EOT;

    return $prompt;
}

// ============================================
// CONTEXT MANAGEMENT
// ============================================
function manageContext($messages, $config) {
    $maxMessages = intval($config['general']['max_context_messages'] ?? 20);
    $recentToKeep = intval($config['general']['recent_messages_to_keep'] ?? 10);

    if (count($messages) <= $maxMessages) {
        return $messages;
    }

    $recentMessages = array_slice($messages, -$recentToKeep);
    $oldMessages = array_slice($messages, 0, -$recentToKeep);

    $summaryParts = [];
    foreach ($oldMessages as $msg) {
        $role = $msg['role'] === 'user' ? 'User' : ($config['general']['bot_name'] ?? 'Assistant');
        $content = '';

        if (is_array($msg['content'])) {
            foreach ($msg['content'] as $part) {
                if ($part['type'] === 'text') {
                    $content = $part['text'];
                    break;
                } elseif ($part['type'] === 'image') {
                    $content = '[shared an image]';
                }
            }
        } else {
            $content = $msg['content'];
        }

        if (mb_strlen($content, 'UTF-8') > 200) {
            $content = mb_substr($content, 0, 200, 'UTF-8') . '...';
        }

        $summaryParts[] = "{$role}: {$content}";
    }

    $summaryText = implode("\n", $summaryParts);

    $contextMessage = [
        'role' => 'user',
        'content' => [[
            'type' => 'text',
            'text' => "[CONVERSATION CONTEXT - Earlier messages:\n{$summaryText}\n\nContinue naturally.]"
        ]]
    ];

    return array_merge([$contextMessage], $recentMessages);
}

// ============================================
// BUILD MESSAGES ARRAY
// ============================================
$messages = [];

if (isset($data['messages']) && is_array($data['messages'])) {
    foreach ($data['messages'] as $msg) {
        if (!isset($msg['role']) || !isset($msg['content'])) {
            sendError('Invalid message format', 'INVALID_MESSAGE_FORMAT');
        }
    }
    $messages = $data['messages'];
} else {
    $content = [];

    if (isset($data['image'])) {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $imgType = $data['imageType'] ?? 'image/jpeg';

        if (!in_array($imgType, $allowedTypes)) {
            sendError('Invalid image type', 'INVALID_IMAGE_TYPE');
        }

        $content[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $imgType,
                'data' => $data['image']
            ]
        ];
    }

    $content[] = ['type' => 'text', 'text' => $data['prompt']];
    $messages[] = ['role' => 'user', 'content' => $content];
}

// Save user message to database
$lastUserMessage = end($messages);
if ($lastUserMessage && $lastUserMessage['role'] === 'user') {
    $userMsgId = addMessage($conversationId, 'user', $lastUserMessage['content']);

    // Auto-title from first message
    $conv = getConversation($conversationId);
    if ($conv && $conv['message_count'] <= 1) {
        autoTitleConversation($conversationId, $lastUserMessage['content']);
    }
}

// Apply context management
$messages = manageContext($messages, $config);

// ============================================
// PREPARE STREAMING API REQUEST
// ============================================
$systemPrompt = buildSystemPrompt($config, $emotions, $themes);
$temperature = floatval($data['temperature'] ?? $config['general']['temperature'] ?? 0.7);

$requestData = [
    'model' => $modelId,
    'max_tokens' => (int)($data['max_tokens'] ?? $config['general']['max_tokens'] ?? 4096),
    'system' => $systemPrompt,
    'messages' => $messages,
    'stream' => true,
    'temperature' => $temperature
];

// ============================================
// MAKE STREAMING API CALL
// ============================================
$endpoint = $config['api']['endpoint'];
$ch = curl_init($endpoint);

$fullResponse = '';
$buffer = '';
$inputTokens = 0;
$outputTokens = 0;

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: ' . $config['api']['anthropic_version'],
        'Accept: text/event-stream'
    ],
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullResponse, &$buffer, &$inputTokens, &$outputTokens, $emotions, $themes, $config, $conversationId, $modelId) {
        $buffer .= $data;

        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $event = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $lines = explode("\n", $event);
            $eventType = '';
            $eventData = '';

            foreach ($lines as $line) {
                if (strpos($line, 'event: ') === 0) {
                    $eventType = trim(substr($line, 7));
                } elseif (strpos($line, 'data: ') === 0) {
                    $eventData = substr($line, 6);
                }
            }

            if ($eventData) {
                $parsed = json_decode($eventData, true);

                if ($parsed) {
                    // Track token usage from message_start
                    if ($eventType === 'message_start' && isset($parsed['message']['usage'])) {
                        $inputTokens = $parsed['message']['usage']['input_tokens'] ?? 0;
                    }

                    if ($eventType === 'content_block_delta' && isset($parsed['delta']['text'])) {
                        $text = $parsed['delta']['text'];
                        $fullResponse .= $text;
                        sendEvent('chunk', ['text' => $text]);
                    } elseif ($eventType === 'message_delta' && isset($parsed['usage'])) {
                        $outputTokens = $parsed['usage']['output_tokens'] ?? 0;
                    } elseif ($eventType === 'message_stop') {
                        $theme = $config['general']['default_theme'];
                        $emotion = $config['general']['default_emotion'];
                        $cleanText = $fullResponse;

                        if (preg_match('/\[THEME:\s*(\w+)\]/i', $cleanText, $matches)) {
                            $extracted = strtolower($matches[1]);
                            if (isset($themes[$extracted])) {
                                $theme = $extracted;
                            }
                            $cleanText = preg_replace('/\[THEME:\s*\w+\]/i', '', $cleanText);
                        }

                        if (preg_match('/\[EMOTION:\s*(\w+)\]/i', $cleanText, $matches)) {
                            $extracted = strtolower($matches[1]);
                            if (isset($emotions[$extracted])) {
                                $emotion = $extracted;
                            }
                            $cleanText = preg_replace('/\[EMOTION:\s*\w+\]/i', '', $cleanText);
                        }

                        $cleanText = trim($cleanText);

                        // Save assistant message to database
                        addMessage($conversationId, 'assistant', $cleanText, [
                            'emotion' => $emotion,
                            'theme' => $theme,
                            'tokens_in' => $inputTokens,
                            'tokens_out' => $outputTokens,
                            'model' => $modelId
                        ]);

                        sendEvent('done', [
                            'emotion' => $emotion,
                            'theme' => $theme,
                            'fullText' => $cleanText,
                            'conversation_id' => $conversationId,
                            'tokens' => [
                                'input' => $inputTokens,
                                'output' => $outputTokens,
                                'total' => $inputTokens + $outputTokens
                            ],
                            'model' => $modelId
                        ]);
                    } elseif ($eventType === 'error' || isset($parsed['error'])) {
                        sendEvent('error', [
                            'error' => $parsed['error']['message'] ?? 'API Error',
                            'code' => 'API_ERROR'
                        ]);
                    }
                }
            }
        }

        return strlen($data);
    }
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    sendError('Network error: ' . $curlError, 'NETWORK_ERROR');
}

if ($httpCode !== 200 && empty($fullResponse)) {
    sendError('API returned status ' . $httpCode, 'API_ERROR_' . $httpCode);
}
