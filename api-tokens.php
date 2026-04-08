<?php
/**
 * API Token Management
 * Create and manage tokens for external access / embedded widget
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Require admin session for management
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

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

// Token validation endpoint is public
if ($action === 'validate') {
    handleValidate();
}

// All management actions require admin
if (!$isAdmin) {
    jsonError('Admin authentication required', 403);
}

switch ($action) {
    case 'list':
        jsonResponse(['success' => true, 'tokens' => listApiTokens()]);
        break;
    case 'create':
        if ($method !== 'POST') jsonError('POST required');
        handleCreate();
        break;
    case 'revoke':
        if ($method !== 'POST') jsonError('POST required');
        handleRevoke();
        break;
    case 'delete':
        if ($method !== 'POST') jsonError('POST required');
        handleDelete();
        break;
    default:
        jsonError('Unknown action');
}

function handleValidate() {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (empty($token)) jsonError('No token provided', 401);
    $tokenData = validateApiToken($token);
    if (!$tokenData) jsonError('Invalid or expired token', 401);
    jsonResponse(['success' => true, 'permissions' => $tokenData['permissions']]);
}

function handleCreate() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) jsonError('Invalid JSON');

    $name = trim($input['name'] ?? '');
    if (empty($name)) jsonError('Token name is required');

    $permissions = $input['permissions'] ?? 'chat';
    $validPerms = ['chat', 'read', 'admin'];
    if (!in_array($permissions, $validPerms)) jsonError('Invalid permissions');

    $expiresAt = null;
    if (!empty($input['expires_days'])) {
        $days = intval($input['expires_days']);
        $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);
    }

    $rateLimit = intval($input['rate_limit'] ?? 60);

    $result = createApiToken($name, $permissions, $expiresAt, $rateLimit);
    jsonResponse([
        'success' => true,
        'id' => $result['id'],
        'token' => $result['token'], // Only shown once!
        'message' => 'Save this token securely - it will not be shown again.',
    ]);
}

function handleRevoke() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    revokeApiToken($id);
    jsonResponse(['success' => true]);
}

function handleDelete() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    deleteApiToken($id);
    jsonResponse(['success' => true]);
}
