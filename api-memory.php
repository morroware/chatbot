<?php
/**
 * Memory System API
 * Manages long-term facts the bot remembers about the user
 */
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            handleList();
            break;

        case 'add':
            requireMethod('POST');
            handleAdd();
            break;

        case 'update':
            requireMethod('POST');
            handleUpdate();
            break;

        case 'delete':
            requireMethod('POST');
            handleDelete();
            break;

        case 'extract':
            requireMethod('POST');
            handleExtract();
            break;

        default:
            jsonError('Unknown action', 400);
    }
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}

function handleList() {
    $limit = intval($_GET['limit'] ?? 50);
    $memories = getActiveMemories($limit);
    jsonSuccess(['memories' => $memories]);
}

function handleAdd() {
    $data = getJsonInput();
    $fact = $data['fact'] ?? '';
    $type = $data['fact_type'] ?? 'general';
    $sourceId = $data['source_message_id'] ?? null;

    if (empty($fact)) jsonError('Missing fact', 400);

    $id = addMemory($fact, $type, $sourceId);
    jsonSuccess(['id' => $id]);
}

function handleUpdate() {
    $data = getJsonInput();
    $id = intval($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    unset($data['id']);
    updateMemory($id, $data);
    jsonSuccess(['updated' => true]);
}

function handleDelete() {
    $data = getJsonInput();
    $id = intval($data['id'] ?? 0);
    if (!$id) jsonError('Missing id', 400);

    deleteMemory($id);
    jsonSuccess(['deleted' => true]);
}

/**
 * Ask the AI to extract memorable facts from a conversation segment.
 * This is called periodically by the frontend after a few messages.
 */
function handleExtract() {
    $data = getJsonInput();
    $messages = $data['messages'] ?? [];
    $existingMemories = $data['existing_memories'] ?? [];

    if (empty($messages)) jsonError('No messages to extract from', 400);

    // Load config for API access
    $config = parse_ini_file(__DIR__ . '/config.ini', true);
    $apiKey = trim($config['api']['api_key'] ?? '');

    if (empty($apiKey) || $apiKey === 'YourKeyHere') {
        jsonError('API key not configured', 500);
    }

    // Build extraction prompt
    $existingList = '';
    if (!empty($existingMemories)) {
        $existingList = "\nAlready known facts:\n";
        foreach ($existingMemories as $mem) {
            $existingList .= "- [{$mem['fact_type']}] {$mem['fact']}\n";
        }
    }

    $extractionPrompt = <<<EOT
Analyze this conversation and extract any new facts worth remembering about the user. Only extract CONCRETE, SPECIFIC facts - not vague observations.

Categories:
- preference: Things the user likes/dislikes
- personal: Name, age, location, occupation, relationships
- interest: Hobbies, topics they care about
- context: Ongoing projects, goals, situations
- style: How they like to communicate

{$existingList}

Return ONLY a JSON array of new facts not already known. Each fact should be an object with "fact_type" and "fact" keys. If there are no new facts, return an empty array [].

Example: [{"fact_type": "personal", "fact": "User's name is Alex"}, {"fact_type": "preference", "fact": "Prefers concise answers"}]

IMPORTANT: Return ONLY the JSON array, nothing else. No markdown, no explanation.
EOT;

    // Build messages for extraction
    $extractMessages = [];
    foreach ($messages as $msg) {
        $content = '';
        if (is_array($msg['content'])) {
            foreach ($msg['content'] as $part) {
                if (isset($part['type']) && $part['type'] === 'text') {
                    $content .= $part['text'] . ' ';
                }
            }
        } else {
            $content = $msg['content'];
        }
        $role = $msg['role'] === 'user' ? 'User' : 'Assistant';
        $extractMessages[] = "$role: $content";
    }

    $conversationText = implode("\n", $extractMessages);

    $requestData = [
        'model' => $config['model_ids']['haiku'] ?? 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'system' => $extractionPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $conversationText]
        ]
    ];

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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        jsonSuccess(['facts' => [], 'note' => 'Extraction skipped']);
        return;
    }

    $responseData = json_decode($response, true);
    $text = '';

    if (isset($responseData['content'])) {
        foreach ($responseData['content'] as $block) {
            if ($block['type'] === 'text') {
                $text = trim($block['text']);
                break;
            }
        }
    }

    // Parse the JSON response
    $facts = json_decode($text, true);

    if (!is_array($facts)) {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/\[.*\]/s', $text, $matches)) {
            $facts = json_decode($matches[0], true);
        }
    }

    if (!is_array($facts)) {
        jsonSuccess(['facts' => []]);
        return;
    }

    // Save extracted facts
    $saved = [];
    foreach ($facts as $factData) {
        if (isset($factData['fact']) && isset($factData['fact_type'])) {
            $id = addMemory($factData['fact'], $factData['fact_type']);
            $saved[] = array_merge($factData, ['id' => $id]);
        }
    }

    jsonSuccess(['facts' => $saved]);
}

function requireMethod($method) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonError("Method $method required", 405);
    }
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON', 400);
    }
    return $data;
}

function jsonSuccess($data) {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}
