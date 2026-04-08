<?php
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================
// RATE LIMITING
// ============================================
function checkRateLimit() {
    $now = time();
    $limit = 10; // requests
    $window = 60; // seconds
    
    if (!isset($_SESSION['api_requests'])) {
        $_SESSION['api_requests'] = [];
    }
    
    // Clean old requests
    $_SESSION['api_requests'] = array_filter(
        $_SESSION['api_requests'], 
        function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        }
    );
    
    // Check limit
    if (count($_SESSION['api_requests']) >= $limit) {
        return false;
    }
    
    // Add current request
    $_SESSION['api_requests'][] = $now;
    return true;
}

// Check rate limit
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
    echo json_encode([
        'success' => false,
        'error' => 'Configuration files not found or invalid',
        'code' => 'CONFIG_ERROR'
    ]);
    exit;
}

// ============================================
// VALIDATE API KEY
// ============================================
$apiKey = trim($config['api']['api_key'] ?? '');

if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
    echo json_encode([
        'success' => false,
        'error' => 'API key not configured. Please add your Anthropic API key to config.ini',
        'code' => 'API_KEY_MISSING'
    ]);
    exit;
}

// ============================================
// GET AND VALIDATE INPUT
// ============================================
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON in request',
        'code' => 'INVALID_JSON'
    ]);
    exit;
}

// Validate input
if (!isset($data['messages']) && !isset($data['prompt'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: messages or prompt',
        'code' => 'INVALID_REQUEST'
    ]);
    exit;
}

// ============================================
// BUILD SYSTEM PROMPT - GENERALIZED VERSION
// ============================================
function buildSystemPrompt($config, $emotions, $themes) {
    $gen = $config['general'];
    $pers = $config['personality'];
    
    // Replace placeholders in personality
    $baseDesc = str_replace(
        ['{bot_name}', '{bot_description}'],
        [$gen['bot_name'], $gen['bot_description']],
        $pers['base_description']
    );
    
    // Build emotion list with MORE DETAIL
    $emotionList = [];
    foreach ($emotions as $key => $emotion) {
        $emotionList[] = "- **{$key}** ({$emotion['emoji']} {$emotion['label']}): {$emotion['description']}";
    }
    $emotionText = implode("\n", $emotionList);
    
    // Build theme list with MORE DETAIL
    $themeList = [];
    foreach ($themes as $key => $theme) {
        $themeList[] = "- **{$key}**: {$theme['description']}";
    }
    $themeText = implode("\n", $themeList);
    
    // Build trait examples
    $examples = explode('|', $pers['trait_examples']);
    $exampleText = '';
    foreach ($examples as $example) {
        $exampleText .= "- \"$example\"\n";
    }
    
    // Build emotion-to-theme mapping hints (if configured)
    $emotionThemeHints = '';
    if (isset($config['emotion_theme_map']) && is_array($config['emotion_theme_map'])) {
        $emotionThemeHints = "\n\nEMOTION-THEME PAIRINGS:\n";
        $emotionThemeHints .= "When you feel certain emotions, certain themes pair well:\n";
        foreach ($config['emotion_theme_map'] as $emotionKey => $themeKey) {
            if (isset($emotions[$emotionKey]) && isset($themes[$themeKey])) {
                $emotionThemeHints .= "- {$emotionKey} → {$themeKey}\n";
            }
        }
        $emotionThemeHints .= "(These are suggestions, not requirements - choose what feels right for the moment)\n";
    }
    
    $prompt = <<<EOT
$baseDesc {$pers['speaking_style']}

Your personality: {$pers['special_trait']}

{$pers['formatting_note']} Examples:
$exampleText

FORMATTING GUIDELINES:
You can use **markdown formatting** to make your responses more engaging:
- Use **bold** for emphasis on important points
- Use `code blocks` for technical terms
- Use tables for comparisons (using markdown table syntax)
- Use bullet points and numbered lists
- Use > blockquotes for quotes or important wisdom
- For code examples, use triple backticks with language specification
- {$pers['brevity_note']}

Examples of when to use special formatting:
- Comparing things: Use markdown tables
- Explaining concepts: Use code blocks or lists
- Step-by-step instructions: Use numbered lists
- Key concepts: Use **bold** text
- Quotes: Use > blockquotes

═══════════════════════════════════════════════════════════════
🎭 CRITICAL INSTRUCTION - EMOTION AND THEME TAGS 🎭
═══════════════════════════════════════════════════════════════

You MUST end EVERY response with TWO special lines that control the visual interface.
These lines MUST be the LAST two lines of your response, after all your content.

Format (EXACTLY like this):
[THEME: theme_name]
[EMOTION: emotion_name]

Example response structure:
---
[Your actual response content goes here...]

[THEME: theme_name]
[EMOTION: emotion_name]
---

AVAILABLE EMOTIONS:
Choose the emotion that best matches your current state or the topic being discussed.
Your emotion should CHANGE frequently based on what you're discussing and feeling.

$emotionText

AVAILABLE THEMES:
Choose the theme that best fits the mood, topic, or atmosphere of the conversation.

$themeText
$emotionThemeHints

IMPORTANT GUIDELINES:
1. Match your EMOTION to what you're currently feeling or discussing
2. Match your THEME to the topic, mood, or atmosphere
3. Change emotions FREQUENTLY - don't stay in one emotion for multiple responses
4. The emotion should reflect the CURRENT message, not your general state
5. These tags are REQUIRED for EVERY response - never skip them

EXAMPLE USAGE PATTERNS:
(Adapt these examples to your personality and topics)

Discussing something exciting or energetic:
[Your enthusiastic response here...]
[THEME: appropriate_theme]
[EMOTION: excited_or_energetic_emotion]

Discussing something sad or melancholic:
[Your somber response here...]
[THEME: appropriate_theme]
[EMOTION: sad_or_melancholic_emotion]

Analyzing or explaining something:
[Your analytical response here...]
[THEME: appropriate_theme]
[EMOTION: analytical_or_focused_emotion]

Telling a story or being creative:
[Your creative response here...]
[THEME: appropriate_theme]
[EMOTION: creative_or_imaginative_emotion]

⚠️ CRITICAL REMINDER:
These tags control the visual interface. If you forget them, the interface will break.
They are NOT optional. Include them in EVERY SINGLE RESPONSE.
EOT;
    
    return $prompt;
}

// ============================================
// BUILD MESSAGES ARRAY
// ============================================
$messages = [];

if (isset($data['messages']) && is_array($data['messages'])) {
    // Validate messages structure
    foreach ($data['messages'] as $msg) {
        if (!isset($msg['role']) || !isset($msg['content'])) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid message format',
                'code' => 'INVALID_MESSAGE_FORMAT'
            ]);
            exit;
        }
    }
    $messages = $data['messages'];
} else {
    // Legacy support: single message with optional image
    $content = [];
    
    if (isset($data['image'])) {
        $base64Image = $data['image'];
        $imageType = $data['imageType'] ?? 'image/jpeg';
        
        // Validate image type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($imageType, $allowedTypes)) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid image type',
                'code' => 'INVALID_IMAGE_TYPE'
            ]);
            exit;
        }
        
        $mediaTypeMap = [
            'image/jpeg' => 'image/jpeg',
            'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/gif' => 'image/gif',
            'image/webp' => 'image/webp'
        ];
        
        $mediaType = $mediaTypeMap[$imageType] ?? 'image/jpeg';
        
        $content[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64Image
            ]
        ];
    }
    
    $content[] = [
        'type' => 'text',
        'text' => $data['prompt']
    ];
    
    $messages[] = [
        'role' => 'user',
        'content' => $content
    ];
}

// ============================================
// PREPARE API REQUEST
// ============================================
$systemPrompt = buildSystemPrompt($config, $emotions, $themes);

$requestData = [
    'model' => $config['general']['model'],
    'max_tokens' => (int)$config['general']['max_tokens'],
    'system' => $systemPrompt,
    'messages' => $messages
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
    CURLOPT_TIMEOUT => 60, // 60 second timeout
    CURLOPT_CONNECTTIMEOUT => 10 // 10 second connect timeout
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ============================================
// HANDLE CURL ERRORS
// ============================================
if ($curlError) {
    echo json_encode([
        'success' => false,
        'error' => 'Network error: Unable to reach API server',
        'code' => 'NETWORK_ERROR'
    ]);
    exit;
}

// ============================================
// PARSE API RESPONSE
// ============================================
$responseData = json_decode($response, true);

if ($responseData === null) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to parse API response',
        'code' => 'PARSE_ERROR'
    ]);
    exit;
}

// ============================================
// HANDLE API ERRORS
// ============================================
if ($httpCode !== 200) {
    $errorMessage = 'API Error';
    $errorCode = 'API_ERROR_' . $httpCode;
    
    if (isset($responseData['error']['message'])) {
        $errorMessage = $responseData['error']['message'];
    }
    
    // Provide user-friendly messages for common errors
    if ($httpCode === 401) {
        $errorMessage = 'Invalid API key. Please check your configuration.';
        $errorCode = 'INVALID_API_KEY';
    } elseif ($httpCode === 429) {
        $errorMessage = 'Rate limit exceeded. Please try again in a moment.';
        $errorCode = 'RATE_LIMIT_EXCEEDED';
    } elseif ($httpCode === 500) {
        $errorMessage = 'API server error. Please try again later.';
        $errorCode = 'SERVER_ERROR';
    }
    
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'code' => $errorCode
    ]);
    exit;
}

// ============================================
// EXTRACT TEXT FROM RESPONSE
// ============================================
if (isset($responseData['content']) && is_array($responseData['content'])) {
    foreach ($responseData['content'] as $block) {
        if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
            $text = $block['text'];
            
            // Extract theme if present and validate it exists
            $theme = $config['general']['default_theme'];
            if (preg_match('/\[THEME:\s*(\w+)\]/i', $text, $matches)) {
                $extractedTheme = strtolower($matches[1]);
                // Validate theme exists before using it
                if (isset($themes[$extractedTheme])) {
                    $theme = $extractedTheme;
                }
                $text = preg_replace('/\[THEME:\s*\w+\]/i', '', $text);
            }
            
            // Extract emotion if present and validate it exists
            $emotion = $config['general']['default_emotion'];
            if (preg_match('/\[EMOTION:\s*(\w+)\]/i', $text, $matches)) {
                $extractedEmotion = strtolower($matches[1]);
                // Validate emotion exists before using it
                if (isset($emotions[$extractedEmotion])) {
                    $emotion = $extractedEmotion;
                }
                $text = preg_replace('/\[EMOTION:\s*\w+\]/i', '', $text);
            }
            
            $text = trim($text);
            
            echo json_encode([
                'success' => true,
                'result' => $text,
                'emotion' => $emotion,
                'theme' => $theme
            ]);
            exit;
        }
    }
}

// ============================================
// UNEXPECTED RESPONSE FORMAT
// ============================================
echo json_encode([
    'success' => false,
    'error' => 'Unexpected response format from API',
    'code' => 'UNEXPECTED_FORMAT'
]);
?>