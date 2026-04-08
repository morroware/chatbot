<?php
/**
 * Database Layer - SQLite for conversations, messages, and memory
 * Provides persistent storage for the chatbot platform
 */

define('DB_PATH', __DIR__ . '/data/chatbot.db');

function getDB() {
    static $db = null;
    if ($db !== null) return $db;

    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $isNew = !file_exists(DB_PATH);
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    if ($isNew) {
        initializeDatabase($db);
    }

    return $db;
}

function initializeDatabase($db) {
    $db->exec('
        CREATE TABLE IF NOT EXISTS conversations (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL DEFAULT "New Chat",
            created_at TEXT NOT NULL DEFAULT (datetime("now")),
            updated_at TEXT NOT NULL DEFAULT (datetime("now")),
            pinned INTEGER NOT NULL DEFAULT 0,
            model TEXT DEFAULT NULL,
            total_tokens_in INTEGER NOT NULL DEFAULT 0,
            total_tokens_out INTEGER NOT NULL DEFAULT 0,
            message_count INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ("user", "assistant", "system")),
            content TEXT NOT NULL,
            emotion TEXT DEFAULT NULL,
            theme TEXT DEFAULT NULL,
            tokens_in INTEGER DEFAULT 0,
            tokens_out INTEGER DEFAULT 0,
            model TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime("now")),
            edited INTEGER NOT NULL DEFAULT 0,
            bookmarked INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fact_type TEXT NOT NULL DEFAULT "general",
            fact TEXT NOT NULL,
            source_message_id INTEGER DEFAULT NULL,
            confidence REAL NOT NULL DEFAULT 1.0,
            created_at TEXT NOT NULL DEFAULT (datetime("now")),
            updated_at TEXT NOT NULL DEFAULT (datetime("now")),
            active INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            conversation_id TEXT NOT NULL,
            note TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime("now")),
            FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_messages_bookmarked ON messages(bookmarked) WHERE bookmarked = 1;
        CREATE INDEX IF NOT EXISTS idx_memory_active ON memory(active) WHERE active = 1;
        CREATE INDEX IF NOT EXISTS idx_conversations_updated ON conversations(updated_at DESC);
        CREATE INDEX IF NOT EXISTS idx_conversations_pinned ON conversations(pinned DESC, updated_at DESC);
    ');
}

// ============================================
// CONVERSATION FUNCTIONS
// ============================================

function createConversation($title = 'New Chat', $model = null) {
    $db = getDB();
    $id = generateId();

    $stmt = $db->prepare('INSERT INTO conversations (id, title, model) VALUES (:id, :title, :model)');
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':model', $model, SQLITE3_TEXT);
    $stmt->execute();

    return $id;
}

function getConversation($id) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM conversations WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function listConversations($limit = 50, $offset = 0, $search = null) {
    $db = getDB();

    if ($search) {
        $stmt = $db->prepare('
            SELECT c.*,
                   (SELECT content FROM messages WHERE conversation_id = c.id AND role = "user" ORDER BY created_at ASC LIMIT 1) as first_message
            FROM conversations c
            WHERE c.title LIKE :search
               OR c.id IN (SELECT DISTINCT conversation_id FROM messages WHERE content LIKE :search)
            ORDER BY c.pinned DESC, c.updated_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':search', "%$search%", SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('
            SELECT c.*,
                   (SELECT content FROM messages WHERE conversation_id = c.id AND role = "user" ORDER BY created_at ASC LIMIT 1) as first_message
            FROM conversations c
            ORDER BY c.pinned DESC, c.updated_at DESC
            LIMIT :limit OFFSET :offset
        ');
    }

    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    $conversations = [];
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $conversations[] = $row;
    }
    return $conversations;
}

function updateConversation($id, $data) {
    $db = getDB();
    $sets = [];
    $params = [];

    $allowed = ['title', 'pinned', 'model', 'total_tokens_in', 'total_tokens_out', 'message_count'];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    if (empty($sets)) return false;

    $sets[] = 'updated_at = datetime("now")';
    $sql = 'UPDATE conversations SET ' . implode(', ', $sets) . ' WHERE id = :id';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    return $stmt->execute();
}

function deleteConversation($id) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM conversations WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    return $stmt->execute();
}

// ============================================
// MESSAGE FUNCTIONS
// ============================================

function addMessage($conversationId, $role, $content, $extra = []) {
    $db = getDB();

    $stmt = $db->prepare('
        INSERT INTO messages (conversation_id, role, content, emotion, theme, tokens_in, tokens_out, model)
        VALUES (:conv_id, :role, :content, :emotion, :theme, :tokens_in, :tokens_out, :model)
    ');

    $stmt->bindValue(':conv_id', $conversationId, SQLITE3_TEXT);
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':content', is_array($content) ? json_encode($content) : $content, SQLITE3_TEXT);
    $stmt->bindValue(':emotion', $extra['emotion'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':theme', $extra['theme'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':tokens_in', $extra['tokens_in'] ?? 0, SQLITE3_INTEGER);
    $stmt->bindValue(':tokens_out', $extra['tokens_out'] ?? 0, SQLITE3_INTEGER);
    $stmt->bindValue(':model', $extra['model'] ?? null, SQLITE3_TEXT);

    $stmt->execute();
    $messageId = $db->lastInsertRowID();

    // Update conversation counters
    $updateStmt = $db->prepare('UPDATE conversations SET
        updated_at = datetime(\'now\'),
        message_count = message_count + 1,
        total_tokens_in = total_tokens_in + :tokens_in,
        total_tokens_out = total_tokens_out + :tokens_out
        WHERE id = :conv_id');
    $updateStmt->bindValue(':tokens_in', intval($extra['tokens_in'] ?? 0), SQLITE3_INTEGER);
    $updateStmt->bindValue(':tokens_out', intval($extra['tokens_out'] ?? 0), SQLITE3_INTEGER);
    $updateStmt->bindValue(':conv_id', $conversationId, SQLITE3_TEXT);
    $updateStmt->execute();

    return $messageId;
}

function getMessages($conversationId, $limit = 100, $offset = 0) {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM messages
        WHERE conversation_id = :conv_id
        ORDER BY created_at ASC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':conv_id', $conversationId, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    $messages = [];
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $messages[] = $row;
    }
    return $messages;
}

function updateMessage($messageId, $data) {
    $db = getDB();
    $sets = [];
    $params = [];

    $allowed = ['content', 'emotion', 'theme', 'edited', 'bookmarked'];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    if (empty($sets)) return false;

    $sql = 'UPDATE messages SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $messageId, SQLITE3_INTEGER);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    return $stmt->execute();
}

function deleteMessage($messageId) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM messages WHERE id = :id');
    $stmt->bindValue(':id', $messageId, SQLITE3_INTEGER);
    return $stmt->execute();
}

function deleteMessagesAfter($conversationId, $messageId) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM messages WHERE conversation_id = :conv_id AND id > :msg_id');
    $stmt->bindValue(':conv_id', $conversationId, SQLITE3_TEXT);
    $stmt->bindValue(':msg_id', $messageId, SQLITE3_INTEGER);
    return $stmt->execute();
}

function searchMessages($query, $conversationId = null) {
    $db = getDB();

    if ($conversationId) {
        $stmt = $db->prepare('
            SELECT m.*, c.title as conversation_title
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            WHERE m.conversation_id = :conv_id AND m.content LIKE :query
            ORDER BY m.created_at DESC
            LIMIT 50
        ');
        $stmt->bindValue(':conv_id', $conversationId, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('
            SELECT m.*, c.title as conversation_title
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            WHERE m.content LIKE :query
            ORDER BY m.created_at DESC
            LIMIT 50
        ');
    }

    $stmt->bindValue(':query', "%$query%", SQLITE3_TEXT);

    $messages = [];
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $messages[] = $row;
    }
    return $messages;
}

// ============================================
// MEMORY FUNCTIONS
// ============================================

function addMemory($fact, $type = 'general', $sourceMessageId = null, $confidence = 1.0) {
    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO memory (fact_type, fact, source_message_id, confidence)
        VALUES (:type, :fact, :source_id, :confidence)
    ');
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':fact', $fact, SQLITE3_TEXT);
    $stmt->bindValue(':source_id', $sourceMessageId, SQLITE3_INTEGER);
    $stmt->bindValue(':confidence', $confidence, SQLITE3_FLOAT);
    $stmt->execute();

    return $db->lastInsertRowID();
}

function getActiveMemories($limit = 50) {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM memory WHERE active = 1 ORDER BY updated_at DESC LIMIT :limit
    ');
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

    $memories = [];
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $memories[] = $row;
    }
    return $memories;
}

function updateMemory($id, $data) {
    $db = getDB();
    $sets = ['updated_at = datetime("now")'];
    $params = [];

    $allowed = ['fact', 'fact_type', 'confidence', 'active'];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    $sql = 'UPDATE memory SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    return $stmt->execute();
}

function deleteMemory($id) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM memory WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute();
}

function buildMemoryContext() {
    $memories = getActiveMemories(30);
    if (empty($memories)) return '';

    $categories = [];
    foreach ($memories as $mem) {
        $type = $mem['fact_type'];
        if (!isset($categories[$type])) $categories[$type] = [];
        $categories[$type][] = $mem['fact'];
    }

    $text = "\n\n[LONG-TERM MEMORY - Things you remember about this user]\n";
    foreach ($categories as $type => $facts) {
        $label = ucfirst(str_replace('_', ' ', $type));
        $text .= "$label:\n";
        foreach ($facts as $fact) {
            $text .= "- $fact\n";
        }
    }
    $text .= "\nUse these memories naturally in conversation. Don't explicitly say 'I remember that...' unless asked. Just incorporate the knowledge naturally.\n";

    return $text;
}

// ============================================
// SETTINGS FUNCTIONS
// ============================================

function getSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['value'] : $default;
}

function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    return $stmt->execute();
}

// ============================================
// HELPERS
// ============================================

function generateId() {
    return bin2hex(random_bytes(12));
}

function autoTitleConversation($conversationId, $firstMessage) {
    // Generate a short title from the first message
    $content = '';
    if (is_array($firstMessage)) {
        foreach ($firstMessage as $part) {
            if (isset($part['type']) && $part['type'] === 'text') {
                $content = $part['text'];
                break;
            }
        }
    } else {
        $content = $firstMessage;
    }

    // Clean and truncate
    $title = strip_tags($content);
    $title = preg_replace('/\s+/', ' ', $title);
    $title = trim($title);

    if (strlen($title) > 60) {
        $title = substr($title, 0, 57) . '...';
    }

    if (!empty($title)) {
        updateConversation($conversationId, ['title' => $title]);
    }

    return $title;
}

// Initialize DB on include
getDB();
