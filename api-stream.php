<?php
/**
 * Streaming API Endpoint for Chatbot
 * Supports Server-Sent Events (SSE) for real-time response streaming
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================
// SSE HEADERS
// ============================================
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Flush any existing output buffers
while (ob_get_level()) {
    ob_end_flush();
}

// Helper to send SSE events
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
    $limit = 10;
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
$config = parse_ini_file('config.ini', true);
$emotions = parse_ini_file('emotions.ini', true);
$themes = parse_ini_file('themes.ini', true);

if (!$config || !$emotions || !$themes) {
    sendError('Configuration files not found or invalid', 'CONFIG_ERROR');
}

// ============================================
// VALIDATE API KEY
// ============================================
$apiKey = trim($config['api']['api_key'] ?? '');

if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    sendError('API key not configured', 'API_KEY_MISSING');
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
        $exampleText .= "- \"$example\"\n";
    }
    
    $emotionThemeHints = '';
    if (isset($config['emotion_theme_map']) && is_array($config['emotion_theme_map'])) {
        $emotionThemeHints = "\n\nEMOTION-THEME PAIRINGS:\n";
        foreach ($config['emotion_theme_map'] as $emotionKey => $themeKey) {
            if (isset($emotions[$emotionKey]) && isset($themes[$themeKey])) {
                $emotionThemeHints .= "- {$emotionKey} → {$themeKey}\n";
            }
        }
    }
    
    $prompt = <<<EOT
$baseDesc {$pers['speaking_style']}

Your personality: {$pers['special_trait']}

{$pers['formatting_note']} Examples:
$exampleText

FORMATTING GUIDELINES:
- Use **bold** for emphasis
- Use `code blocks` for technical terms
- Use tables, bullet points, numbered lists as needed
- Use > blockquotes for quotes
- {$pers['brevity_note']}

═══════════════════════════════════════════════════════════════
🎭 CRITICAL INSTRUCTION - EMOTION AND THEME TAGS 🎭
═══════════════════════════════════════════════════════════════

You MUST end EVERY response with TWO special lines:

[THEME: theme_name]
[EMOTION: emotion_name]

AVAILABLE EMOTIONS:
$emotionText

AVAILABLE THEMES:
$themeText
$emotionThemeHints

IMPORTANT: These tags are REQUIRED for EVERY response.
EOT;
    
    return $prompt;
}

// ============================================
// CONTEXT MANAGEMENT - Summarize old messages
// ============================================
function manageContext($messages, $maxMessages = 20, $config) {
    if (count($messages) <= $maxMessages) {
        return $messages;
    }
    
    // Keep the most recent messages
    $recentCount = 10;
    $recentMessages = array_slice($messages, -$recentCount);
    $oldMessages = array_slice($messages, 0, -$recentCount);
    
    // Create a summary of old messages
    $summaryParts = [];
    foreach ($oldMessages as $msg) {
        $role = $msg['role'] === 'user' ? 'User' : $config['general']['bot_name'];
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
        
        // Truncate long messages in summary
        if (strlen($content) > 150) {
            $content = substr($content, 0, 150) . '...';
        }
        
        $summaryParts[] = "{$role}: {$content}";
    }
    
    $summaryText = implode("\n", $summaryParts);
    
    // Create a context summary message
    $contextMessage = [
        'role' => 'user',
        'content' => [[
            'type' => 'text',
            'text' => "[CONVERSATION CONTEXT - Earlier in our conversation:\n{$summaryText}\n\nPlease continue our conversation naturally, keeping this context in mind.]"
        ]]
    ];
    
    // Insert context summary followed by recent messages
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
        $imageType = $data['imageType'] ?? 'image/jpeg';
        
        if (!in_array($imageType, $allowedTypes)) {
            sendError('Invalid image type', 'INVALID_IMAGE_TYPE');
        }
        
        $content[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $imageType,
                'data' => $data['image']
            ]
        ];
    }
    
    $content[] = ['type' => 'text', 'text' => $data['prompt']];
    $messages[] = ['role' => 'user', 'content' => $content];
}

// Apply context management
$messages = manageContext($messages, 20, $config);

// ============================================
// PREPARE STREAMING API REQUEST
// ============================================
$systemPrompt = buildSystemPrompt($config, $emotions, $themes);

$requestData = [
    'model' => $config['general']['model'],
    'max_tokens' => (int)$config['general']['max_tokens'],
    'system' => $systemPrompt,
    'messages' => $messages,
    'stream' => true  // Enable streaming
];

// ============================================
// MAKE STREAMING API CALL
// ============================================
$ch = curl_init($config['api']['endpoint']);

$fullResponse = '';
$buffer = '';

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
    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullResponse, &$buffer, $emotions, $themes, $config) {
        $buffer .= $data;
        
        // Process complete SSE events
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $event = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            
            // Parse the event
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
                    // Handle different event types
                    if ($eventType === 'content_block_delta' && isset($parsed['delta']['text'])) {
                        $text = $parsed['delta']['text'];
                        $fullResponse .= $text;
                        
                        // Send chunk to client (but filter out emotion/theme tags from display)
                        // We'll extract them at the end
                        sendEvent('chunk', ['text' => $text]);
                    } elseif ($eventType === 'message_stop') {
                        // Message complete - extract emotion and theme
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
                        
                        sendEvent('done', [
                            'emotion' => $emotion,
                            'theme' => $theme,
                            'fullText' => trim($cleanText)
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
?>