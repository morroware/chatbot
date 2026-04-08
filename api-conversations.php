<?php
/**
 * Conversation Management API
 * CRUD operations for conversations and messages
 */
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        // ========== CONVERSATIONS ==========
        case 'list':
            handleList();
            break;

        case 'create':
            requireMethod('POST');
            handleCreate();
            break;

        case 'get':
            handleGet();
            break;

        case 'update':
            requireMethod('POST');
            handleUpdate();
            break;

        case 'delete':
            requireMethod('POST');
            handleDelete();
            break;

        case 'messages':
            handleGetMessages();
            break;

        case 'search':
            handleSearch();
            break;

        // ========== MESSAGE ACTIONS ==========
        case 'edit_message':
            requireMethod('POST');
            handleEditMessage();
            break;

        case 'delete_message':
            requireMethod('POST');
            handleDeleteMessage();
            break;

        case 'bookmark_message':
            requireMethod('POST');
            handleBookmarkMessage();
            break;

        case 'export':
            handleExport();
            break;

        default:
            jsonError('Unknown action', 400);
    }
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}

// ============================================
// HANDLERS
// ============================================

function handleList() {
    $search = $_GET['search'] ?? null;
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);

    $conversations = listConversations($limit, $offset, $search);
    jsonSuccess(['conversations' => $conversations]);
}

function handleCreate() {
    $data = getJsonInput();
    $title = $data['title'] ?? 'New Chat';
    $model = $data['model'] ?? null;

    $id = createConversation($title, $model);
    $conversation = getConversation($id);

    jsonSuccess(['conversation' => $conversation]);
}

function handleGet() {
    $id = $_GET['id'] ?? '';
    if (empty($id)) jsonError('Missing conversation ID', 400);

    $conversation = getConversation($id);
    if (!$conversation) jsonError('Conversation not found', 404);

    $messages = getMessages($id);

    jsonSuccess([
        'conversation' => $conversation,
        'messages' => $messages
    ]);
}

function handleUpdate() {
    $data = getJsonInput();
    $id = $data['id'] ?? '';
    if (empty($id)) jsonError('Missing conversation ID', 400);

    unset($data['id']);
    updateConversation($id, $data);

    jsonSuccess(['updated' => true]);
}

function handleDelete() {
    $data = getJsonInput();
    $id = $data['id'] ?? '';
    if (empty($id)) jsonError('Missing conversation ID', 400);

    deleteConversation($id);
    jsonSuccess(['deleted' => true]);
}

function handleGetMessages() {
    $id = $_GET['id'] ?? '';
    if (empty($id)) jsonError('Missing conversation ID', 400);

    $limit = intval($_GET['limit'] ?? 100);
    $offset = intval($_GET['offset'] ?? 0);

    $messages = getMessages($id, $limit, $offset);
    jsonSuccess(['messages' => $messages]);
}

function handleSearch() {
    $query = $_GET['q'] ?? '';
    if (empty($query)) jsonError('Missing search query', 400);

    $conversationId = $_GET['conversation_id'] ?? null;
    $results = searchMessages($query, $conversationId);

    jsonSuccess(['results' => $results]);
}

function handleEditMessage() {
    $data = getJsonInput();
    $messageId = intval($data['message_id'] ?? 0);
    $content = $data['content'] ?? '';

    if (!$messageId || empty($content)) jsonError('Missing message_id or content', 400);

    updateMessage($messageId, ['content' => $content, 'edited' => 1]);
    jsonSuccess(['updated' => true]);
}

function handleDeleteMessage() {
    $data = getJsonInput();
    $messageId = intval($data['message_id'] ?? 0);
    if (!$messageId) jsonError('Missing message_id', 400);

    $deleteAfter = $data['delete_after'] ?? false;
    $conversationId = $data['conversation_id'] ?? '';

    if ($deleteAfter && $conversationId) {
        deleteMessagesAfter($conversationId, $messageId);
    }

    deleteMessage($messageId);
    jsonSuccess(['deleted' => true]);
}

function handleBookmarkMessage() {
    $data = getJsonInput();
    $messageId = intval($data['message_id'] ?? 0);
    $bookmarked = $data['bookmarked'] ?? true;

    if (!$messageId) jsonError('Missing message_id', 400);

    updateMessage($messageId, ['bookmarked' => $bookmarked ? 1 : 0]);
    jsonSuccess(['bookmarked' => $bookmarked]);
}

function handleExport() {
    $id = $_GET['id'] ?? '';
    $format = $_GET['format'] ?? 'json';
    if (empty($id)) jsonError('Missing conversation ID', 400);

    $conversation = getConversation($id);
    if (!$conversation) jsonError('Conversation not found', 404);

    $messages = getMessages($id, 10000);

    switch ($format) {
        case 'markdown':
            exportMarkdown($conversation, $messages);
            break;
        case 'txt':
            exportText($conversation, $messages);
            break;
        case 'json':
        default:
            exportJson($conversation, $messages);
            break;
    }
}

// ============================================
// EXPORT FUNCTIONS
// ============================================

function exportJson($conversation, $messages) {
    jsonSuccess([
        'conversation' => $conversation,
        'messages' => $messages,
        'exported_at' => date('c')
    ]);
}

function exportMarkdown($conversation, $messages) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($conversation['title']) . '.md"');

    echo "# {$conversation['title']}\n\n";
    echo "**Exported:** " . date('Y-m-d H:i:s') . "  \n";
    echo "**Messages:** {$conversation['message_count']}  \n";
    echo "**Tokens:** {$conversation['total_tokens_in']} in / {$conversation['total_tokens_out']} out\n\n";
    echo "---\n\n";

    foreach ($messages as $msg) {
        $role = $msg['role'] === 'user' ? 'You' : 'Assistant';
        $time = $msg['created_at'];
        $bookmark = $msg['bookmarked'] ? ' :star:' : '';

        echo "### {$role}{$bookmark}\n";
        echo "*{$time}*\n\n";

        $content = $msg['content'];
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            foreach ($decoded as $part) {
                if (isset($part['type']) && $part['type'] === 'text') {
                    echo $part['text'] . "\n";
                } elseif (isset($part['type']) && $part['type'] === 'image') {
                    echo "[Image attached]\n";
                }
            }
        } else {
            echo $content . "\n";
        }

        echo "\n---\n\n";
    }
    exit;
}

function exportText($conversation, $messages) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($conversation['title']) . '.txt"');

    echo "Chat: {$conversation['title']}\n";
    echo "Exported: " . date('Y-m-d H:i:s') . "\n";
    echo "Messages: {$conversation['message_count']}\n";
    echo str_repeat('=', 60) . "\n\n";

    foreach ($messages as $msg) {
        $role = $msg['role'] === 'user' ? 'You' : 'Assistant';
        $time = $msg['created_at'];

        echo "{$role} ({$time}):\n";

        $content = $msg['content'];
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            foreach ($decoded as $part) {
                if (isset($part['type']) && $part['type'] === 'text') {
                    echo $part['text'] . "\n";
                } elseif (isset($part['type']) && $part['type'] === 'image') {
                    echo "[Image attached]\n";
                }
            }
        } else {
            echo $content . "\n";
        }

        echo "\n" . str_repeat('-', 60) . "\n\n";
    }
    exit;
}

// ============================================
// HELPERS
// ============================================

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

function sanitizeFilename($name) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
}
