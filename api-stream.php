<?php
/**
 * Streaming API Endpoint
 * Features: SSE streaming, tool use (agentic), knowledge base RAG,
 *           extended thinking, prompt caching, multi-model, memory injection
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

while (ob_get_level()) ob_end_flush();

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

    if (!isset($_SESSION['api_requests'])) $_SESSION['api_requests'] = [];

    $_SESSION['api_requests'] = array_filter(
        $_SESSION['api_requests'],
        fn($ts) => ($now - $ts) < $window
    );

    if (count($_SESSION['api_requests']) >= $limit) return false;

    $_SESSION['api_requests'][] = $now;
    return true;
}

// Check token-based auth (for embedded widget access)
$bearerToken = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $bearerToken = $m[1];
}

$tokenData = null;
if ($bearerToken) {
    $tokenData = validateApiToken($bearerToken);
    if (!$tokenData) sendError('Invalid or expired API token', 'AUTH_ERROR');
} else {
    if (!checkRateLimit()) {
        sendError('Rate limit exceeded. Please wait a moment.', 'RATE_LIMIT_EXCEEDED');
    }
}

// ============================================
// LOAD CONFIGURATION
// ============================================
$config = parse_ini_file(__DIR__ . '/config.ini', true);
$emotions = parse_ini_file(__DIR__ . '/emotions.ini', true);
$themes = parse_ini_file(__DIR__ . '/themes.ini', true);

if (!$config || !$emotions || !$themes) {
    sendError('Configuration files not found or invalid', 'CONFIG_ERROR');
}

$apiKey = trim($config['api']['api_key'] ?? '');
if (empty($apiKey) || $apiKey === 'YourKeyHere') {
    sendError('API key not configured. Please add your API key in the admin panel.', 'API_KEY_MISSING');
}

// ============================================
// PARSE INPUT
// ============================================
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) sendError('Invalid JSON in request', 'INVALID_JSON');
if (!isset($data['messages']) && !isset($data['prompt'])) sendError('Missing required fields', 'INVALID_REQUEST');

// Feature flags from request
$useExtendedThinking = !empty($data['extended_thinking']);
$thinkingBudget = intval($data['thinking_budget'] ?? $config['general']['thinking_budget'] ?? 8000);
$enableTools = isset($data['enable_tools']) ? (bool)$data['enable_tools'] : ($config['general']['enable_tools'] ?? 'true') === 'true';
$enableKB = isset($data['enable_kb']) ? (bool)$data['enable_kb'] : ($config['general']['enable_kb'] ?? 'true') === 'true';
$enableCache = ($config['general']['enable_prompt_cache'] ?? 'true') === 'true';

// ============================================
// RESOLVE MODEL
// ============================================
$requestedModel = $data['model'] ?? $config['general']['model'] ?? 'claude-sonnet-4-6';
$modelId = $config['model_ids'][$requestedModel] ?? $requestedModel;

// Extended thinking requires claude-sonnet-4-6 or opus
if ($useExtendedThinking && !preg_match('/sonnet|opus/i', $modelId)) {
    $modelId = 'claude-sonnet-4-6';
}

// ============================================
// CONVERSATION PERSISTENCE
// ============================================
$conversationId = $data['conversation_id'] ?? null;
if ($conversationId) {
    $conversation = getConversation($conversationId);
    if (!$conversation) $conversationId = createConversation('New Chat', $modelId);
} else {
    $conversationId = createConversation('New Chat', $modelId);
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
        if (!in_array($imgType, $allowedTypes)) sendError('Invalid image type', 'INVALID_IMAGE_TYPE');
        $content[] = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $imgType, 'data' => $data['image']],
        ];
    }
    $content[] = ['type' => 'text', 'text' => $data['prompt']];
    $messages[] = ['role' => 'user', 'content' => $content];
}

// Save user message
$lastUserMessage = end($messages);
if ($lastUserMessage && $lastUserMessage['role'] === 'user') {
    $userMsgId = addMessage($conversationId, 'user', $lastUserMessage['content']);
    $conv = getConversation($conversationId);
    if ($conv && $conv['message_count'] <= 1) {
        autoTitleConversation($conversationId, $lastUserMessage['content']);
    }
}

// ============================================
// KNOWLEDGE BASE CONTEXT INJECTION (RAG)
// ============================================
$kbContext = '';
$kbQueryCount = 0;
if ($enableKB) {
    // Extract search query from last user message
    $userQuery = '';
    if (is_array($lastUserMessage['content'])) {
        foreach ($lastUserMessage['content'] as $part) {
            if ($part['type'] === 'text') { $userQuery = $part['text']; break; }
        }
    } else {
        $userQuery = $lastUserMessage['content'];
    }

    if (!empty($userQuery) && mb_strlen($userQuery, 'UTF-8') > 10) {
        $kbResults = searchKnowledgeBase($userQuery, 4);
        if (!empty($kbResults)) {
            $kbContext = "\n\n════════════════════════════════════════\nKNOWLEDGE BASE CONTEXT\n════════════════════════════════════════\nThe following excerpts from your knowledge base are relevant to this conversation:\n\n";
            foreach ($kbResults as $i => $chunk) {
                $source = $chunk['original_name'] ?? 'Document';
                $kbContext .= "**Source: {$source}**\n" . $chunk['content'] . "\n\n";
            }
            $kbContext .= "Use the above knowledge base context to inform your response when relevant.\n";
            $kbQueryCount = count($kbResults);
        }
    }
}

// ============================================
// CONTEXT MANAGEMENT
// ============================================
function manageContext($messages, $config) {
    $maxMessages = intval($config['general']['max_context_messages'] ?? 20);
    $recentToKeep = intval($config['general']['recent_messages_to_keep'] ?? 10);
    if (count($messages) <= $maxMessages) return $messages;

    $recent = array_slice($messages, -$recentToKeep);
    $old = array_slice($messages, 0, -$recentToKeep);

    $summaryParts = [];
    foreach ($old as $msg) {
        $role = $msg['role'] === 'user' ? 'User' : ($config['general']['bot_name'] ?? 'Assistant');
        $content = '';
        if (is_array($msg['content'])) {
            foreach ($msg['content'] as $part) {
                if (($part['type'] ?? '') === 'text') { $content = $part['text']; break; }
            }
        } else {
            $content = $msg['content'];
        }
        if (mb_strlen($content, 'UTF-8') > 200) $content = mb_substr($content, 0, 200, 'UTF-8') . '...';
        $summaryParts[] = "{$role}: {$content}";
    }

    return array_merge([
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => "[Earlier context:\n" . implode("\n", $summaryParts) . "\n\nContinue naturally.]"]]]
    ], $recent);
}

$messages = manageContext($messages, $config);

// ============================================
// BUILD SYSTEM PROMPT
// ============================================
function buildSystemPrompt($config, $emotions, $themes, $kbContext = '', $enableCache = true) {
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

    $themeList = [];
    foreach ($themes as $key => $theme) {
        $themeList[] = "- **{$key}**: {$theme['description']}";
    }

    $emotionThemeHints = '';
    if (isset($config['emotion_theme_map']) && is_array($config['emotion_theme_map'])) {
        $emotionThemeHints = "\nEMOTION-THEME PAIRINGS:\n";
        foreach ($config['emotion_theme_map'] as $ek => $tk) {
            if (isset($emotions[$ek]) && isset($themes[$tk])) {
                $emotionThemeHints .= "- {$ek} → {$tk}\n";
            }
        }
    }

    $memoryContext = buildMemoryContext();
    $examples = explode('|', $pers['trait_examples']);
    $exampleText = implode("\n", array_map(fn($e) => "- $e", $examples));

    $currentDateTime = date('l, F j, Y \a\t g:i A T');

    $prompt = <<<EOT
$baseDesc

{$pers['speaking_style']}

Your personality: {$pers['special_trait']}

Behavioral examples:
$exampleText

{$pers['formatting_note']}
{$pers['brevity_note']}

CURRENT DATE & TIME: $currentDateTime
$memoryContext
$kbContext
═══════════════════════════════════════════════════════════════
EMOTION AND THEME TAGS (REQUIRED)
═══════════════════════════════════════════════════════════════

You MUST end EVERY response with TWO tags on their own lines:

[THEME: theme_name]
[EMOTION: emotion_name]

AVAILABLE EMOTIONS:
EOT;

    foreach ($emotions as $key => $emotion) {
        $prompt .= "\n- **{$key}** ({$emotion['emoji']} {$emotion['label']}): {$emotion['description']}";
    }

    $prompt .= "\n\nAVAILABLE THEMES:";
    foreach ($themes as $key => $theme) {
        $prompt .= "\n- **{$key}**: {$theme['description']}";
    }

    $prompt .= "\n$emotionThemeHints\nRULES:\n1. Match EMOTION to your current feeling about the conversation\n2. Match THEME to the mood/atmosphere\n3. Change emotions naturally as the conversation evolves\n4. Tags are REQUIRED on every response - the UI depends on them\n5. Tags must be the LAST two lines of your response";

    return $prompt;
}

// ============================================
// TOOL DEFINITIONS
// ============================================
function getToolDefinitions($config) {
    $tools = [];

    // Always available
    $tools[] = [
        'name' => 'get_datetime',
        'description' => 'Get the current date, time, and timezone information.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'timezone' => ['type' => 'string', 'description' => 'Optional timezone (e.g. "America/New_York")'],
                'format' => ['type' => 'string', 'description' => 'Format like "date", "time", "datetime", "unix"', 'default' => 'datetime'],
            ],
            'required' => [],
        ],
    ];

    $tools[] = [
        'name' => 'calculate',
        'description' => 'Perform mathematical calculations. Supports basic arithmetic, powers, percentages, and common math functions.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'expression' => ['type' => 'string', 'description' => 'Math expression to evaluate (e.g., "2 + 2", "15% of 200", "sqrt(144)")'],
            ],
            'required' => ['expression'],
        ],
    ];

    $tools[] = [
        'name' => 'search_knowledge_base',
        'description' => 'Search the uploaded knowledge base documents for relevant information. Use this to find specific facts, procedures, or data from uploaded files.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'max_results' => ['type' => 'integer', 'description' => 'Max results to return (1-10)', 'default' => 3],
            ],
            'required' => ['query'],
        ],
    ];

    $tools[] = [
        'name' => 'search_memory',
        'description' => 'Search through stored long-term memories and facts about the user.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to search for in memories'],
            ],
            'required' => ['query'],
        ],
    ];

    $tools[] = [
        'name' => 'remember',
        'description' => 'Store an important fact or piece of information to long-term memory for future conversations.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fact' => ['type' => 'string', 'description' => 'The fact to remember'],
                'category' => ['type' => 'string', 'description' => 'Category like "preference", "goal", "personal", "work", "general"', 'default' => 'general'],
            ],
            'required' => ['fact'],
        ],
    ];

    $tools[] = [
        'name' => 'fetch_url',
        'description' => 'Fetch and read content from a web URL. Useful for looking up current information, reading articles, or checking web pages.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
                'extract_text' => ['type' => 'boolean', 'description' => 'Extract just the text (true) or get raw HTML (false)', 'default' => true],
            ],
            'required' => ['url'],
        ],
    ];

    $tools[] = [
        'name' => 'create_task',
        'description' => 'Create a scheduled task or reminder that will run at a specified time.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Task name/title'],
                'prompt' => ['type' => 'string', 'description' => 'What the AI should do when the task runs'],
                'schedule_type' => ['type' => 'string', 'description' => '"once", "daily", "interval", "weekly", or "monthly"'],
                'schedule_value' => ['type' => 'string', 'description' => 'For "once": datetime string. For "daily": HH:MM. For "interval": minutes.'],
            ],
            'required' => ['name', 'prompt', 'schedule_type'],
        ],
    ];

    return $tools;
}

// ============================================
// TOOL EXECUTION
// ============================================
function executeTool($toolName, $toolInput, $conversationId, $config) {
    $start = microtime(true);
    $result = '';
    $success = true;

    try {
        switch ($toolName) {
            case 'get_datetime':
                $tz = $toolInput['timezone'] ?? date_default_timezone_get();
                $format = $toolInput['format'] ?? 'datetime';
                try {
                    $dt = new DateTime('now', new DateTimeZone($tz));
                } catch (Exception $e) {
                    $dt = new DateTime('now');
                }
                switch ($format) {
                    case 'date': $result = $dt->format('l, F j, Y'); break;
                    case 'time': $result = $dt->format('g:i:s A T'); break;
                    case 'unix': $result = (string)$dt->getTimestamp(); break;
                    default:
                        $result = $dt->format('l, F j, Y \a\t g:i:s A') . ' (' . $dt->getTimezone()->getName() . ')';
                }
                break;

            case 'calculate':
                $expr = $toolInput['expression'] ?? '';
                $result = safeCalculate($expr);
                break;

            case 'search_knowledge_base':
                $query = $toolInput['query'] ?? '';
                $maxR = min(intval($toolInput['max_results'] ?? 3), 10);
                $chunks = searchKnowledgeBase($query, $maxR);
                if (empty($chunks)) {
                    $result = 'No relevant documents found in the knowledge base for: ' . $query;
                } else {
                    $parts = [];
                    foreach ($chunks as $chunk) {
                        $parts[] = "**From: {$chunk['original_name']}**\n" . $chunk['content'];
                    }
                    $result = implode("\n\n---\n\n", $parts);
                }
                break;

            case 'search_memory':
                $query = strtolower($toolInput['query'] ?? '');
                $memories = getActiveMemories(50);
                $matches = array_filter($memories, function($m) use ($query) {
                    return stripos($m['fact'], $query) !== false || stripos($m['fact_type'], $query) !== false;
                });
                if (empty($matches)) {
                    $result = 'No matching memories found for: ' . $toolInput['query'];
                } else {
                    $lines = array_map(fn($m) => "[{$m['fact_type']}] {$m['fact']}", array_values($matches));
                    $result = implode("\n", $lines);
                }
                break;

            case 'remember':
                $fact = trim($toolInput['fact'] ?? '');
                $category = trim($toolInput['category'] ?? 'general');
                if (empty($fact)) {
                    $result = 'Error: fact is required';
                    $success = false;
                    break;
                }
                $id = addMemory($fact, $category);
                $result = "Remembered: \"{$fact}\" (category: {$category}, id: {$id})";
                break;

            case 'fetch_url':
                $url = filter_var(trim($toolInput['url'] ?? ''), FILTER_VALIDATE_URL);
                if (!$url) {
                    $result = 'Error: Invalid URL provided';
                    $success = false;
                    break;
                }
                // Block private/local IPs for security
                $host = parse_url($url, PHP_URL_HOST);
                if (preg_match('/^(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|localhost|0\.0\.0\.0)/i', $host)) {
                    $result = 'Error: Cannot access private/local network addresses';
                    $success = false;
                    break;
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ChatbotAgent/1.0)',
                    CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
                ]);
                $body = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);

                if ($body === false || $httpCode >= 400) {
                    $result = "Error fetching URL (HTTP {$httpCode})";
                    $success = false;
                    break;
                }

                if (($toolInput['extract_text'] ?? true) && strpos($contentType, 'html') !== false) {
                    $body = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $body);
                    $body = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $body);
                    $body = strip_tags($body);
                    $body = preg_replace('/\s+/', ' ', $body);
                    $body = trim($body);
                }

                $result = mb_substr($body, 0, 8000, 'UTF-8');
                if (strlen($body) > 8000) $result .= "\n[Content truncated to 8000 chars]";
                break;

            case 'create_task':
                $taskData = [
                    'name' => $toolInput['name'] ?? 'AI Task',
                    'description' => 'Created by AI assistant',
                    'task_type' => 'prompt',
                    'prompt' => $toolInput['prompt'] ?? '',
                    'schedule_type' => $toolInput['schedule_type'] ?? 'once',
                    'schedule_value' => $toolInput['schedule_value'] ?? '',
                    'enabled' => 1,
                    'model' => $config['general']['model'] ?? null,
                ];
                $nextRun = computeNextRun($taskData['schedule_type'], $taskData['schedule_value']);
                $taskData['next_run'] = $nextRun;
                $taskId = createScheduledTask($taskData);
                $result = "Task created successfully (ID: {$taskId}). Name: \"{$taskData['name']}\", Schedule: {$taskData['schedule_type']}, Next run: " . ($nextRun ?? 'on demand');
                break;

            default:
                $result = "Unknown tool: $toolName";
                $success = false;
        }
    } catch (Exception $e) {
        $result = "Tool error: " . $e->getMessage();
        $success = false;
    }

    $durationMs = round((microtime(true) - $start) * 1000);
    logToolCall($conversationId, $toolName, $toolInput, $result, $durationMs, $success);

    return $result;
}

// ============================================
// SAFE CALCULATOR
// ============================================
function safeCalculate($expr) {
    // Handle percentage shorthand
    $expr = preg_replace('/(\d+(?:\.\d+)?)\s*%\s*of\s*(\d+(?:\.\d+)?)/i', '($1/100)*$2', $expr);
    $expr = preg_replace('/(\d+(?:\.\d+)?)\s*%/', '$1/100', $expr);

    // Handle sqrt, abs, ceil, floor, round functions
    $expr = preg_replace('/\bsqrt\s*\(/i', 'sqrt(', $expr);
    $expr = preg_replace('/\babs\s*\(/i', 'abs(', $expr);
    $expr = preg_replace('/\bceil\s*\(/i', 'ceil(', $expr);
    $expr = preg_replace('/\bfloor\s*\(/i', 'floor(', $expr);
    $expr = preg_replace('/\bround\s*\(/i', 'round(', $expr);
    $expr = preg_replace('/\bpi\b/i', 'M_PI', $expr);
    $expr = preg_replace('/\be\b/', 'M_E', $expr);
    $expr = str_replace(['^', '**'], '**', $expr);

    // Whitelist: only allow safe math characters
    if (!preg_match('/^[\d\s\+\-\*\/\%\(\)\.\,sqrt abs ceil floor round M_PI M_E]+$/u', preg_replace('/sqrt|abs|ceil|floor|round|M_PI|M_E|\*\*/', '', $expr))) {
        return "Invalid expression: only mathematical operations are supported";
    }

    // Handle power operator
    $expr = preg_replace('/(\S+)\s*\*\*\s*(\S+)/', 'pow($1,$2)', $expr);

    try {
        // Only allow known safe functions
        $allowedFunctions = ['sqrt', 'abs', 'ceil', 'floor', 'round', 'pow', 'log', 'exp'];
        $result = @eval('return ' . $expr . ';');
        if ($result === null || $result === false) return "Could not evaluate: $expr";
        if (is_float($result) && (is_nan($result) || is_infinite($result))) return "Mathematical error (division by zero or undefined)";
        return is_float($result) ? rtrim(rtrim(number_format($result, 10), '0'), '.') : (string)$result;
    } catch (ParseError $e) {
        return "Invalid expression";
    } catch (Throwable $e) {
        return "Calculation error";
    }
}

// ============================================
// MULTI-TURN AGENTIC LOOP WITH STREAMING
// ============================================
$systemPrompt = buildSystemPrompt($config, $emotions, $themes, $kbContext, $enableCache);
$temperature = floatval($data['temperature'] ?? $config['general']['temperature'] ?? 0.7);
$maxTokens = intval($data['max_tokens'] ?? $config['general']['max_tokens'] ?? 4096);
$tools = $enableTools ? getToolDefinitions($config) : [];

$fullResponse = '';
$inputTokens = 0;
$outputTokens = 0;
$cacheReadTokens = 0;
$cacheWriteTokens = 0;
$toolCallCount = 0;
$maxToolIterations = 5; // Prevent infinite loops

// Build system prompt with optional caching
$systemContent = [];
if ($enableCache) {
    // Static personality (highly cacheable)
    $staticPart = buildSystemPrompt($config, $emotions, $themes, '', false);
    $systemContent[] = [
        'type' => 'text',
        'text' => $staticPart,
        'cache_control' => ['type' => 'ephemeral'],
    ];
    // Dynamic KB context (cacheable for this session if same content)
    if ($kbContext) {
        $systemContent[] = [
            'type' => 'text',
            'text' => $kbContext,
            'cache_control' => ['type' => 'ephemeral'],
        ];
    }
} else {
    $systemContent = $systemPrompt;
}

// Agentic loop
$currentMessages = $messages;
for ($iteration = 0; $iteration <= $maxToolIterations; $iteration++) {
    $requestData = [
        'model' => $modelId,
        'max_tokens' => $maxTokens,
        'system' => $systemContent,
        'messages' => $currentMessages,
        'stream' => true,
        'temperature' => $temperature,
    ];

    if (!empty($tools)) {
        $requestData['tools'] = $tools;
    }

    // Extended thinking
    if ($useExtendedThinking) {
        $requestData['thinking'] = [
            'type' => 'enabled',
            'budget_tokens' => $thinkingBudget,
        ];
        // Extended thinking requires temperature=1
        $requestData['temperature'] = 1;
        // Extended thinking uses betas header
    }

    // Extra headers for extended thinking and prompt caching
    $extraHeaders = [];
    if ($useExtendedThinking || $enableCache) {
        $betaFeatures = [];
        if ($useExtendedThinking) $betaFeatures[] = 'interleaved-thinking-2025-05-14';
        if ($enableCache) $betaFeatures[] = 'prompt-caching-2024-07-31';
        if (!empty($betaFeatures)) {
            $extraHeaders[] = 'anthropic-beta: ' . implode(',', $betaFeatures);
        }
    }

    // Make API call
    $endpoint = $config['api']['endpoint'];
    $ch = curl_init($endpoint);

    $iterationBuffer = '';
    $iterationFullText = '';
    $toolUseBlocks = [];
    $currentToolBlock = null;
    $currentContentBlockIndex = -1;
    $stopReason = '';
    $iterTokensIn = 0;
    $iterTokensOut = 0;

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: ' . ($config['api']['anthropic_version'] ?? '2023-06-01'),
        'Accept: text/event-stream',
    ];
    $headers = array_merge($headers, $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function($ch, $rawData) use (&$iterationBuffer, &$iterationFullText, &$toolUseBlocks, &$currentToolBlock, &$currentContentBlockIndex, &$stopReason, &$iterTokensIn, &$iterTokensOut, &$cacheReadTokens, &$cacheWriteTokens) {
            $iterationBuffer .= $rawData;

            while (($pos = strpos($iterationBuffer, "\n\n")) !== false) {
                $event = substr($iterationBuffer, 0, $pos);
                $iterationBuffer = substr($iterationBuffer, $pos + 2);

                $lines = explode("\n", $event);
                $eventType = '';
                $eventData = '';
                foreach ($lines as $line) {
                    if (strpos($line, 'event: ') === 0) $eventType = trim(substr($line, 7));
                    elseif (strpos($line, 'data: ') === 0) $eventData = substr($line, 6);
                }

                if (!$eventData) continue;
                $parsed = json_decode($eventData, true);
                if (!$parsed) continue;

                switch ($eventType) {
                    case 'message_start':
                        if (isset($parsed['message']['usage'])) {
                            $iterTokensIn = $parsed['message']['usage']['input_tokens'] ?? 0;
                            $cacheReadTokens += $parsed['message']['usage']['cache_read_input_tokens'] ?? 0;
                            $cacheWriteTokens += $parsed['message']['usage']['cache_creation_input_tokens'] ?? 0;
                        }
                        break;

                    case 'content_block_start':
                        $currentContentBlockIndex = $parsed['index'] ?? -1;
                        $block = $parsed['content_block'] ?? [];
                        if ($block['type'] === 'tool_use') {
                            $currentToolBlock = [
                                'id' => $block['id'],
                                'name' => $block['name'],
                                'input_json' => '',
                            ];
                            // Notify frontend a tool is being called
                            sendEvent('tool_start', ['tool' => $block['name'], 'id' => $block['id']]);
                        } elseif ($block['type'] === 'thinking') {
                            sendEvent('thinking_start', []);
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $parsed['delta'] ?? [];
                        if ($delta['type'] === 'text_delta') {
                            $text = $delta['text'] ?? '';
                            $iterationFullText .= $text;
                            sendEvent('chunk', ['text' => $text]);
                        } elseif ($delta['type'] === 'input_json_delta' && $currentToolBlock !== null) {
                            $currentToolBlock['input_json'] .= $delta['partial_json'] ?? '';
                        } elseif ($delta['type'] === 'thinking_delta') {
                            sendEvent('thinking_chunk', ['text' => $delta['thinking'] ?? '']);
                        }
                        break;

                    case 'content_block_stop':
                        if ($currentToolBlock !== null) {
                            $toolInput = json_decode($currentToolBlock['input_json'], true) ?? [];
                            $toolUseBlocks[] = [
                                'type' => 'tool_use',
                                'id' => $currentToolBlock['id'],
                                'name' => $currentToolBlock['name'],
                                'input' => $toolInput,
                            ];
                            $currentToolBlock = null;
                        }
                        break;

                    case 'message_delta':
                        if (isset($parsed['usage'])) {
                            $iterTokensOut = $parsed['usage']['output_tokens'] ?? 0;
                        }
                        $stopReason = $parsed['delta']['stop_reason'] ?? $stopReason;
                        break;
                }
            }

            return strlen($rawData);
        },
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) sendError('Network error: ' . $curlError, 'NETWORK_ERROR');

    $inputTokens += $iterTokensIn;
    $outputTokens += $iterTokensOut;

    // If no tool calls, we're done
    if (empty($toolUseBlocks) || $stopReason !== 'tool_use') {
        $fullResponse = $iterationFullText;
        break;
    }

    // Execute tools and build tool results
    $assistantContentBlocks = [];
    if ($iterationFullText) {
        $assistantContentBlocks[] = ['type' => 'text', 'text' => $iterationFullText];
    }
    foreach ($toolUseBlocks as $tb) {
        $assistantContentBlocks[] = $tb;
    }

    // Add assistant's tool-use response to message history
    $currentMessages[] = ['role' => 'assistant', 'content' => $assistantContentBlocks];

    // Execute each tool and collect results
    $toolResultContent = [];
    foreach ($toolUseBlocks as $tb) {
        $toolCallCount++;
        sendEvent('tool_running', ['tool' => $tb['name'], 'id' => $tb['id']]);

        $toolResult = executeTool($tb['name'], $tb['input'], $conversationId, $config);

        sendEvent('tool_result', [
            'tool' => $tb['name'],
            'id' => $tb['id'],
            'result_preview' => mb_substr(is_string($toolResult) ? $toolResult : json_encode($toolResult), 0, 200, 'UTF-8'),
        ]);

        $toolResultContent[] = [
            'type' => 'tool_result',
            'tool_use_id' => $tb['id'],
            'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult),
        ];
    }

    $currentMessages[] = ['role' => 'user', 'content' => $toolResultContent];
    $toolUseBlocks = [];
}

// ============================================
// PARSE EMOTION/THEME FROM RESPONSE
// ============================================
$theme = $config['general']['default_theme'];
$emotion = $config['general']['default_emotion'];
$cleanText = $fullResponse;

if (preg_match('/\[THEME:\s*(\w+)\]/i', $cleanText, $m)) {
    $extracted = strtolower($m[1]);
    if (isset($themes[$extracted])) $theme = $extracted;
    $cleanText = preg_replace('/\[THEME:\s*\w+\]/i', '', $cleanText);
}

if (preg_match('/\[EMOTION:\s*(\w+)\]/i', $cleanText, $m)) {
    $extracted = strtolower($m[1]);
    if (isset($emotions[$extracted])) $emotion = $extracted;
    $cleanText = preg_replace('/\[EMOTION:\s*\w+\]/i', '', $cleanText);
}

$cleanText = trim($cleanText);

// ============================================
// SAVE RESPONSE & RECORD STATS
// ============================================
addMessage($conversationId, 'assistant', $cleanText, [
    'emotion' => $emotion,
    'theme' => $theme,
    'tokens_in' => $inputTokens,
    'tokens_out' => $outputTokens,
    'model' => $modelId,
]);

recordUsageStat($modelId, $inputTokens, $outputTokens, 1, $toolCallCount, $kbQueryCount);

sendEvent('done', [
    'emotion' => $emotion,
    'theme' => $theme,
    'fullText' => $cleanText,
    'conversation_id' => $conversationId,
    'tokens' => [
        'input' => $inputTokens,
        'output' => $outputTokens,
        'total' => $inputTokens + $outputTokens,
        'cache_read' => $cacheReadTokens,
        'cache_write' => $cacheWriteTokens,
    ],
    'model' => $modelId,
    'tool_calls' => $toolCallCount,
    'kb_chunks_used' => $kbQueryCount,
    'extended_thinking' => $useExtendedThinking,
]);
