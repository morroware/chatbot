<?php
/**
 * Scheduled Tasks API
 * Create, manage, and run scheduled AI tasks
 * Tasks can be run via cron (see scheduler.php) or triggered manually
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function jsonError($msg, $code = 400) {
    jsonResponse(['success' => false, 'error' => $msg], $code);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        jsonResponse(['success' => true, 'tasks' => listScheduledTasks()]);
        break;

    case 'create':
        if ($method !== 'POST') jsonError('POST required');
        handleCreate();
        break;

    case 'update':
        if ($method !== 'POST') jsonError('POST required');
        handleUpdate();
        break;

    case 'delete':
        if ($method !== 'POST') jsonError('POST required');
        handleDelete();
        break;

    case 'run':
        if ($method !== 'POST') jsonError('POST required');
        handleRun();
        break;

    case 'toggle':
        if ($method !== 'POST') jsonError('POST required');
        handleToggle();
        break;

    default:
        jsonError('Unknown action');
}

function handleCreate() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonError('Invalid JSON');

    $name = trim($input['name'] ?? '');
    $scheduleType = trim($input['schedule_type'] ?? '');
    $prompt = trim($input['prompt'] ?? '');

    if (empty($name)) jsonError('Task name is required');
    if (empty($scheduleType)) jsonError('Schedule type is required');
    if (empty($prompt)) jsonError('Task prompt is required');

    $validScheduleTypes = ['once', 'interval', 'daily', 'weekly', 'monthly'];
    if (!in_array($scheduleType, $validScheduleTypes)) {
        jsonError('Invalid schedule type. Use: ' . implode(', ', $validScheduleTypes));
    }

    // Compute next_run
    $scheduleValue = trim($input['schedule_value'] ?? '');
    $nextRun = null;

    if ($scheduleType === 'once') {
        $nextRun = $scheduleValue ?: date('Y-m-d H:i:s', time() + 60);
    } elseif ($scheduleType === 'interval') {
        $minutes = intval($scheduleValue) ?: 60;
        $scheduleValue = (string)$minutes;
        $nextRun = date('Y-m-d H:i:s', time() + $minutes * 60);
    } else {
        $nextRun = computeNextRun($scheduleType, $scheduleValue);
    }

    $data = [
        'name' => $name,
        'description' => trim($input['description'] ?? ''),
        'task_type' => 'prompt',
        'prompt' => $prompt,
        'schedule_type' => $scheduleType,
        'schedule_value' => $scheduleValue,
        'next_run' => $nextRun,
        'enabled' => 1,
        'model' => $input['model'] ?? null,
    ];

    $id = createScheduledTask($data);
    $task = getScheduledTask($id);
    jsonResponse(['success' => true, 'task' => $task]);
}

function handleUpdate() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonError('Invalid JSON');
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');

    $task = getScheduledTask($id);
    if (!$task) jsonError('Task not found', 404);

    $updates = [];
    $fields = ['name', 'description', 'prompt', 'schedule_type', 'schedule_value', 'enabled', 'model'];
    foreach ($fields as $f) {
        if (isset($input[$f])) $updates[$f] = $input[$f];
    }

    // Recompute next_run if schedule changed
    $schedType = $updates['schedule_type'] ?? $task['schedule_type'];
    $schedVal  = $updates['schedule_value'] ?? $task['schedule_value'];
    if (isset($updates['schedule_type']) || isset($updates['schedule_value'])) {
        if ($schedType === 'once') {
            $updates['next_run'] = $schedVal ?: date('Y-m-d H:i:s', time() + 60);
        } else {
            $updates['next_run'] = computeNextRun($schedType, $schedVal);
        }
    }

    updateScheduledTask($id, $updates);
    jsonResponse(['success' => true, 'task' => getScheduledTask($id)]);
}

function handleDelete() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    $ok = deleteScheduledTask($id);
    jsonResponse(['success' => (bool)$ok]);
}

function handleToggle() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    $task = getScheduledTask($id);
    if (!$task) jsonError('Task not found', 404);
    $newEnabled = $task['enabled'] ? 0 : 1;
    updateScheduledTask($id, ['enabled' => $newEnabled]);
    jsonResponse(['success' => true, 'enabled' => (bool)$newEnabled]);
}

function handleRun() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');

    $task = getScheduledTask($id);
    if (!$task) jsonError('Task not found', 404);

    $result = executeTask($task);
    jsonResponse(['success' => true, 'result' => $result]);
}

// ============================================
// TASK EXECUTION (also used by scheduler.php)
// ============================================

function executeTask($task) {
    $config = parse_ini_file(__DIR__ . '/config.ini', true);
    if (!$config) return ['error' => 'Config not found'];

    $apiKey = trim($config['api']['api_key'] ?? '');
    if (empty($apiKey) || $apiKey === 'YourKeyHere') {
        return ['error' => 'API key not configured'];
    }

    $modelKey = $task['model'] ?: ($config['general']['model'] ?? 'claude-sonnet-4-6');
    $modelId = $config['model_ids'][$modelKey] ?? $modelKey;

    $requestData = [
        'model' => $modelId,
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => $task['prompt']]
        ],
    ];

    $ch = curl_init($config['api']['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . ($config['api']['anthropic_version'] ?? '2023-06-01'),
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['error' => 'Network error: ' . $curlError];

    $data = json_decode($response, true);
    if ($httpCode !== 200 || !$data) {
        return ['error' => "API error $httpCode: " . ($data['error']['message'] ?? 'Unknown')];
    }

    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $text .= $block['text'];
    }

    // Update task
    $nextRun = computeNextRun($task['schedule_type'], $task['schedule_value']);
    updateScheduledTask($task['id'], [
        'last_run' => date('Y-m-d H:i:s'),
        'last_result' => mb_substr($text, 0, 1000, 'UTF-8'),
        'run_count' => $task['run_count'] + 1,
        'next_run' => $nextRun,
        // Disable one-time tasks after running
        'enabled' => $task['schedule_type'] === 'once' ? 0 : 1,
    ]);

    return ['output' => $text, 'model' => $modelId];
}
