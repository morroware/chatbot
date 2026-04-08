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
    } else {
        // Verify tables exist (handles corrupted/empty DB files)
        $result = $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='conversations'");
        if (!$result) {
            initializeDatabase($db);
        }
    }

    return $db;
}

function initializeDatabase($db) {
    $db->exec('
        CREATE TABLE IF NOT EXISTS conversations (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL DEFAULT "New Chat",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            active INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id INTEGER NOT NULL,
            conversation_id TEXT NOT NULL,
            note TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

    runMigrations($db);
}

function runMigrations($db) {
    // Migration tracking table
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version INTEGER PRIMARY KEY,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $applied = [];
    $res = $db->query('SELECT version FROM schema_migrations');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $applied[] = $row['version'];
    }

    // Migration 1: Knowledge base, scheduled tasks, API tokens, tool calls, agent personas
    if (!in_array(1, $applied)) {
        $db->exec('
            CREATE TABLE IF NOT EXISTS knowledge_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                original_name TEXT NOT NULL,
                file_type TEXT NOT NULL,
                file_size INTEGER NOT NULL DEFAULT 0,
                chunk_count INTEGER DEFAULT 0,
                status TEXT DEFAULT "ready",
                description TEXT,
                tags TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS knowledge_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                word_count INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (file_id) REFERENCES knowledge_files(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS scheduled_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                task_type TEXT NOT NULL DEFAULT "prompt",
                prompt TEXT,
                schedule_type TEXT NOT NULL DEFAULT "once",
                schedule_value TEXT,
                next_run TEXT,
                last_run TEXT,
                last_result TEXT,
                run_count INTEGER DEFAULT 0,
                enabled INTEGER DEFAULT 1,
                model TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                token TEXT NOT NULL UNIQUE,
                permissions TEXT DEFAULT "chat",
                last_used TEXT,
                usage_count INTEGER DEFAULT 0,
                enabled INTEGER DEFAULT 1,
                expires_at TEXT,
                rate_limit INTEGER DEFAULT 60,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS tool_calls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id INTEGER,
                conversation_id TEXT,
                tool_name TEXT NOT NULL,
                tool_input TEXT,
                tool_result TEXT,
                duration_ms INTEGER,
                success INTEGER DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS agent_personas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                system_prompt TEXT,
                model TEXT,
                temperature REAL DEFAULT 0.7,
                max_tokens INTEGER DEFAULT 4096,
                avatar_url TEXT,
                enabled INTEGER DEFAULT 1,
                is_default INTEGER DEFAULT 0,
                tools_enabled TEXT DEFAULT "[]",
                kb_enabled INTEGER DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS usage_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stat_date TEXT NOT NULL,
                model TEXT NOT NULL DEFAULT "unknown",
                tokens_in INTEGER DEFAULT 0,
                tokens_out INTEGER DEFAULT 0,
                message_count INTEGER DEFAULT 0,
                tool_calls_count INTEGER DEFAULT 0,
                kb_queries INTEGER DEFAULT 0,
                UNIQUE(stat_date, model)
            );

            CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_file ON knowledge_chunks(file_id, chunk_index);
            CREATE INDEX IF NOT EXISTS idx_tool_calls_conv ON tool_calls(conversation_id);
            CREATE INDEX IF NOT EXISTS idx_tasks_next_run ON scheduled_tasks(next_run, enabled);
        ');
        $db->exec("INSERT INTO schema_migrations (version) VALUES (1)");
    }
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

    if (mb_strlen($title, 'UTF-8') > 60) {
        $title = mb_substr($title, 0, 57, 'UTF-8') . '...';
    }

    if (!empty($title)) {
        updateConversation($conversationId, ['title' => $title]);
    }

    return $title;
}

// ============================================
// KNOWLEDGE BASE FUNCTIONS
// ============================================

function addKnowledgeFile($filename, $originalName, $fileType, $fileSize, $description = '', $tags = '') {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO knowledge_files (filename, original_name, file_type, file_size, description, tags)
        VALUES (:filename, :original_name, :file_type, :file_size, :description, :tags)');
    $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
    $stmt->bindValue(':original_name', $originalName, SQLITE3_TEXT);
    $stmt->bindValue(':file_type', $fileType, SQLITE3_TEXT);
    $stmt->bindValue(':file_size', $fileSize, SQLITE3_INTEGER);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':tags', $tags, SQLITE3_TEXT);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function addKnowledgeChunk($fileId, $chunkIndex, $content) {
    $db = getDB();
    $wordCount = str_word_count($content);
    $stmt = $db->prepare('INSERT INTO knowledge_chunks (file_id, chunk_index, content, word_count)
        VALUES (:file_id, :chunk_index, :content, :word_count)');
    $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
    $stmt->bindValue(':chunk_index', $chunkIndex, SQLITE3_INTEGER);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':word_count', $wordCount, SQLITE3_INTEGER);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function updateKnowledgeFileChunkCount($fileId) {
    $db = getDB();
    $stmt = $db->prepare('UPDATE knowledge_files SET chunk_count = (SELECT COUNT(*) FROM knowledge_chunks WHERE file_id = :id),
        updated_at = datetime("now"), status = "ready" WHERE id = :id');
    $stmt->bindValue(':id', $fileId, SQLITE3_INTEGER);
    $stmt->execute();
}

function listKnowledgeFiles() {
    $db = getDB();
    $result = $db->query('SELECT * FROM knowledge_files ORDER BY created_at DESC');
    $files = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $files[] = $row;
    }
    return $files;
}

function getKnowledgeFile($id) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM knowledge_files WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function deleteKnowledgeFile($id) {
    $db = getDB();
    $file = getKnowledgeFile($id);
    if ($file) {
        $uploadDir = __DIR__ . '/data/uploads/';
        $filePath = $uploadDir . $file['filename'];
        if (file_exists($filePath)) @unlink($filePath);
    }
    $stmt = $db->prepare('DELETE FROM knowledge_files WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute();
}

function searchKnowledgeBase($query, $maxResults = 5) {
    $db = getDB();
    $results = [];

    // Try FTS5 first
    $hasFTS = $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='knowledge_fts'");
    if ($hasFTS) {
        try {
            $stmt = $db->prepare('SELECT kc.*, kf.original_name, kf.description FROM knowledge_chunks kc
                JOIN knowledge_files kf ON kc.file_id = kf.id
                JOIN knowledge_fts fts ON fts.rowid = kc.id
                WHERE knowledge_fts MATCH :query
                ORDER BY rank LIMIT :limit');
            $stmt->bindValue(':query', $query, SQLITE3_TEXT);
            $stmt->bindValue(':limit', $maxResults, SQLITE3_INTEGER);
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
        } catch (Exception $e) { /* fallback below */ }
    }

    // Fallback: LIKE search across chunks
    if (empty($results)) {
        $terms = array_filter(explode(' ', trim($query)));
        if (empty($terms)) return [];
        $conditions = [];
        foreach ($terms as $term) {
            $escaped = SQLite3::escapeString($term);
            $conditions[] = "kc.content LIKE '%{$escaped}%'";
        }
        $sql = 'SELECT kc.*, kf.original_name, kf.description FROM knowledge_chunks kc
            JOIN knowledge_files kf ON kc.file_id = kf.id
            WHERE (' . implode(' OR ', $conditions) . ')
            LIMIT :limit';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $maxResults, SQLITE3_INTEGER);
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $results[] = $row;
        }
    }

    return $results;
}

// ============================================
// SCHEDULED TASK FUNCTIONS
// ============================================

function createScheduledTask($data) {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO scheduled_tasks (name, description, task_type, prompt, schedule_type, schedule_value, next_run, enabled, model)
        VALUES (:name, :desc, :type, :prompt, :sched_type, :sched_val, :next_run, :enabled, :model)');
    $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
    $stmt->bindValue(':desc', $data['description'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':type', $data['task_type'] ?? 'prompt', SQLITE3_TEXT);
    $stmt->bindValue(':prompt', $data['prompt'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':sched_type', $data['schedule_type'], SQLITE3_TEXT);
    $stmt->bindValue(':sched_val', $data['schedule_value'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':next_run', $data['next_run'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':enabled', isset($data['enabled']) ? intval($data['enabled']) : 1, SQLITE3_INTEGER);
    $stmt->bindValue(':model', $data['model'] ?? null, SQLITE3_TEXT);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function listScheduledTasks() {
    $db = getDB();
    $result = $db->query('SELECT * FROM scheduled_tasks ORDER BY created_at DESC');
    $tasks = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tasks[] = $row;
    }
    return $tasks;
}

function getScheduledTask($id) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM scheduled_tasks WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function updateScheduledTask($id, $data) {
    $db = getDB();
    $sets = ['updated_at = datetime("now")'];
    $params = [];
    $allowed = ['name', 'description', 'task_type', 'prompt', 'schedule_type', 'schedule_value', 'next_run', 'last_run', 'last_result', 'run_count', 'enabled', 'model'];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    $sql = 'UPDATE scheduled_tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    return $stmt->execute();
}

function deleteScheduledTask($id) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM scheduled_tasks WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute();
}

function getDueTasks() {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM scheduled_tasks WHERE enabled = 1 AND next_run IS NOT NULL AND next_run <= datetime("now") ORDER BY next_run ASC');
    $result = $stmt->execute();
    $tasks = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tasks[] = $row;
    }
    return $tasks;
}

function computeNextRun($scheduleType, $scheduleValue) {
    $now = time();
    switch ($scheduleType) {
        case 'once': return null;
        case 'interval':
            $minutes = intval($scheduleValue);
            return date('Y-m-d H:i:s', $now + $minutes * 60);
        case 'daily':
            $parts = explode(':', $scheduleValue);
            $h = intval($parts[0] ?? 9);
            $m = intval($parts[1] ?? 0);
            $next = mktime($h, $m, 0);
            if ($next <= $now) $next = mktime($h, $m, 0, date('n'), date('j') + 1);
            return date('Y-m-d H:i:s', $next);
        case 'weekly':
            return date('Y-m-d H:i:s', $now + 7 * 86400);
        case 'monthly':
            return date('Y-m-d H:i:s', strtotime('+1 month', $now));
        default: return null;
    }
}

// ============================================
// API TOKEN FUNCTIONS
// ============================================

function createApiToken($name, $permissions = 'chat', $expiresAt = null, $rateLimit = 60) {
    $db = getDB();
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO api_tokens (name, token, permissions, expires_at, rate_limit) VALUES (:name, :token, :perms, :expires, :rate)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':perms', $permissions, SQLITE3_TEXT);
    $stmt->bindValue(':expires', $expiresAt, SQLITE3_TEXT);
    $stmt->bindValue(':rate', $rateLimit, SQLITE3_INTEGER);
    $stmt->execute();
    return ['id' => $db->lastInsertRowID(), 'token' => $token];
}

function validateApiToken($token) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM api_tokens WHERE token = :token AND enabled = 1');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) return false;
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) return false;
    // Update usage
    $upd = $db->prepare('UPDATE api_tokens SET last_used = datetime("now"), usage_count = usage_count + 1 WHERE id = :id');
    $upd->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $upd->execute();
    return $row;
}

function listApiTokens() {
    $db = getDB();
    $result = $db->query('SELECT id, name, permissions, last_used, usage_count, enabled, expires_at, rate_limit, created_at FROM api_tokens ORDER BY created_at DESC');
    $tokens = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $tokens[] = $row;
    }
    return $tokens;
}

function revokeApiToken($id) {
    $db = getDB();
    $stmt = $db->prepare('UPDATE api_tokens SET enabled = 0 WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute();
}

function deleteApiToken($id) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM api_tokens WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute();
}

// ============================================
// TOOL CALL LOGGING
// ============================================

function logToolCall($conversationId, $toolName, $toolInput, $toolResult, $durationMs, $success = true, $messageId = null) {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO tool_calls (message_id, conversation_id, tool_name, tool_input, tool_result, duration_ms, success)
        VALUES (:msg_id, :conv_id, :tool_name, :tool_input, :tool_result, :duration, :success)');
    $stmt->bindValue(':msg_id', $messageId, SQLITE3_INTEGER);
    $stmt->bindValue(':conv_id', $conversationId, SQLITE3_TEXT);
    $stmt->bindValue(':tool_name', $toolName, SQLITE3_TEXT);
    $stmt->bindValue(':tool_input', is_string($toolInput) ? $toolInput : json_encode($toolInput), SQLITE3_TEXT);
    $stmt->bindValue(':tool_result', is_string($toolResult) ? $toolResult : json_encode($toolResult), SQLITE3_TEXT);
    $stmt->bindValue(':duration', $durationMs, SQLITE3_INTEGER);
    $stmt->bindValue(':success', $success ? 1 : 0, SQLITE3_INTEGER);
    $stmt->execute();
    return $db->lastInsertRowID();
}

// ============================================
// AGENT PERSONAS
// ============================================

function listAgentPersonas() {
    $db = getDB();
    $result = $db->query('SELECT * FROM agent_personas ORDER BY is_default DESC, name ASC');
    $personas = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $personas[] = $row;
    }
    return $personas;
}

function getAgentPersona($id) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM agent_personas WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

function saveAgentPersona($data) {
    $db = getDB();
    if (!empty($data['id'])) {
        $stmt = $db->prepare('UPDATE agent_personas SET name=:name, description=:desc, system_prompt=:sys, model=:model,
            temperature=:temp, max_tokens=:max_tok, avatar_url=:avatar, enabled=:enabled, is_default=:default_p,
            tools_enabled=:tools, kb_enabled=:kb, updated_at=datetime("now") WHERE id=:id');
        $stmt->bindValue(':id', $data['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare('INSERT INTO agent_personas (name, description, system_prompt, model, temperature, max_tokens, avatar_url, enabled, is_default, tools_enabled, kb_enabled)
            VALUES (:name, :desc, :sys, :model, :temp, :max_tok, :avatar, :enabled, :default_p, :tools, :kb)');
    }
    $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
    $stmt->bindValue(':desc', $data['description'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':sys', $data['system_prompt'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':model', $data['model'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':temp', floatval($data['temperature'] ?? 0.7), SQLITE3_FLOAT);
    $stmt->bindValue(':max_tok', intval($data['max_tokens'] ?? 4096), SQLITE3_INTEGER);
    $stmt->bindValue(':avatar', $data['avatar_url'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':enabled', intval($data['enabled'] ?? 1), SQLITE3_INTEGER);
    $stmt->bindValue(':default_p', intval($data['is_default'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':tools', is_array($data['tools_enabled']) ? json_encode($data['tools_enabled']) : ($data['tools_enabled'] ?? '[]'), SQLITE3_TEXT);
    $stmt->bindValue(':kb', intval($data['kb_enabled'] ?? 1), SQLITE3_INTEGER);
    $stmt->execute();
    return empty($data['id']) ? $db->lastInsertRowID() : $data['id'];
}

function deleteAgentPersona($id) {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM agent_personas WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute();
}

// ============================================
// USAGE STATISTICS
// ============================================

function recordUsageStat($model, $tokensIn, $tokensOut, $messages = 1, $toolCalls = 0, $kbQueries = 0) {
    $db = getDB();
    $today = date('Y-m-d');
    $stmt = $db->prepare('INSERT INTO usage_stats (stat_date, model, tokens_in, tokens_out, message_count, tool_calls_count, kb_queries)
        VALUES (:date, :model, :ti, :to, :msgs, :tools, :kb)
        ON CONFLICT(stat_date, model) DO UPDATE SET
            tokens_in = tokens_in + :ti,
            tokens_out = tokens_out + :to,
            message_count = message_count + :msgs,
            tool_calls_count = tool_calls_count + :tools,
            kb_queries = kb_queries + :kb');
    $stmt->bindValue(':date', $today, SQLITE3_TEXT);
    $stmt->bindValue(':model', $model ?: 'unknown', SQLITE3_TEXT);
    $stmt->bindValue(':ti', intval($tokensIn), SQLITE3_INTEGER);
    $stmt->bindValue(':to', intval($tokensOut), SQLITE3_INTEGER);
    $stmt->bindValue(':msgs', intval($messages), SQLITE3_INTEGER);
    $stmt->bindValue(':tools', intval($toolCalls), SQLITE3_INTEGER);
    $stmt->bindValue(':kb', intval($kbQueries), SQLITE3_INTEGER);
    $stmt->execute();
}

function getUsageStats($days = 30) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM usage_stats WHERE stat_date >= date("now", :days) ORDER BY stat_date DESC, model ASC');
    $stmt->bindValue(':days', "-{$days} days", SQLITE3_TEXT);
    $result = $stmt->execute();
    $stats = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $stats[] = $row;
    }
    return $stats;
}

// Initialize DB on include
getDB();
