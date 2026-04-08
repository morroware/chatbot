<?php
/**
 * Knowledge Base API
 * Upload, manage, and search documents for RAG (Retrieval-Augmented Generation)
 * Supports: PDF, DOCX, TXT, MD, CSV, JSON, HTML
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$UPLOAD_DIR = __DIR__ . '/data/uploads/';
$MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB
$ALLOWED_EXTENSIONS = ['txt', 'md', 'pdf', 'docx', 'doc', 'csv', 'json', 'html', 'htm', 'rtf'];
$CHUNK_SIZE = 500;   // words per chunk
$CHUNK_OVERLAP = 50; // word overlap

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function jsonError($msg, $code = 400) {
    jsonResponse(['success' => false, 'error' => $msg], $code);
}

// Ensure upload directory exists
if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
    // Protect uploads directory
    file_put_contents($UPLOAD_DIR . '.htaccess', "Order deny,allow\nDeny from all\n");
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

// ============================================
// ACTIONS
// ============================================

switch ($action) {
    case 'upload':
        handleUpload();
        break;
    case 'list':
        handleList();
        break;
    case 'get':
        handleGet();
        break;
    case 'delete':
        handleDelete();
        break;
    case 'search':
        handleSearch();
        break;
    case 'update':
        handleUpdate();
        break;
    default:
        jsonError('Unknown action');
}

// ============================================
// UPLOAD
// ============================================

function handleUpload() {
    global $UPLOAD_DIR, $MAX_FILE_SIZE, $ALLOWED_EXTENSIONS, $CHUNK_SIZE, $CHUNK_OVERLAP;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
        jsonError('No file uploaded');
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL    => 'File upload incomplete',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
        ];
        jsonError($errors[$file['error']] ?? 'Upload error: ' . $file['error']);
    }

    if ($file['size'] > $MAX_FILE_SIZE) {
        jsonError('File exceeds maximum size of 20MB');
    }

    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $ALLOWED_EXTENSIONS)) {
        jsonError("File type .$ext not supported. Allowed: " . implode(', ', $ALLOWED_EXTENSIONS));
    }

    // Generate safe filename
    $safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $UPLOAD_DIR . $safeFilename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonError('Failed to save file');
    }

    $description = trim($_POST['description'] ?? '');
    $tags = trim($_POST['tags'] ?? '');

    // Extract text and create chunks
    $text = extractText($destPath, $ext);
    if ($text === false || trim($text) === '') {
        @unlink($destPath);
        jsonError('Could not extract text from this file. Ensure the file has readable text content.');
    }

    // Add to database
    $fileId = addKnowledgeFile($safeFilename, $originalName, $ext, $file['size'], $description, $tags);

    // Chunk and store
    $chunks = chunkText($text, $CHUNK_SIZE, $CHUNK_OVERLAP);
    foreach ($chunks as $i => $chunk) {
        if (trim($chunk) !== '') {
            addKnowledgeChunk($fileId, $i, $chunk);
        }
    }
    updateKnowledgeFileChunkCount($fileId);

    // Build FTS index if supported
    buildFTSIndex();

    $fileData = getKnowledgeFile($fileId);
    jsonResponse(['success' => true, 'file' => $fileData, 'chunks_created' => count($chunks)]);
}

// ============================================
// LIST
// ============================================

function handleList() {
    $files = listKnowledgeFiles();
    jsonResponse(['success' => true, 'files' => $files]);
}

// ============================================
// GET
// ============================================

function handleGet() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    $file = getKnowledgeFile($id);
    if (!$file) jsonError('File not found', 404);

    // Optionally include chunks preview
    $db = getDB();
    $stmt = $db->prepare('SELECT id, chunk_index, SUBSTR(content, 1, 200) as preview, word_count FROM knowledge_chunks WHERE file_id = :id ORDER BY chunk_index LIMIT 20');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $chunks = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $chunks[] = $row;
    }

    jsonResponse(['success' => true, 'file' => $file, 'chunks' => $chunks]);
}

// ============================================
// DELETE
// ============================================

function handleDelete() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? $_POST['id'] ?? 0);
    if (!$id) jsonError('Missing id');

    $ok = deleteKnowledgeFile($id);
    if ($ok) buildFTSIndex(); // Rebuild FTS after deletion
    jsonResponse(['success' => (bool)$ok]);
}

// ============================================
// SEARCH
// ============================================

function handleSearch() {
    $query = trim($_GET['q'] ?? '');
    $maxResults = intval($_GET['limit'] ?? 5);
    if (empty($query)) jsonError('Missing query');

    $results = searchKnowledgeBase($query, min($maxResults, 20));
    jsonResponse(['success' => true, 'results' => $results, 'query' => $query]);
}

// ============================================
// UPDATE METADATA
// ============================================

function handleUpdate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');

    $file = getKnowledgeFile($id);
    if (!$file) jsonError('File not found', 404);

    $db = getDB();
    $stmt = $db->prepare('UPDATE knowledge_files SET description=:desc, tags=:tags, updated_at=datetime("now") WHERE id=:id');
    $stmt->bindValue(':desc', trim($input['description'] ?? $file['description']), SQLITE3_TEXT);
    $stmt->bindValue(':tags', trim($input['tags'] ?? $file['tags']), SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    jsonResponse(['success' => true, 'file' => getKnowledgeFile($id)]);
}

// ============================================
// TEXT EXTRACTION
// ============================================

function extractText($filePath, $ext) {
    switch ($ext) {
        case 'txt':
        case 'md':
        case 'rtf':
            return file_get_contents($filePath);

        case 'html':
        case 'htm':
            $content = file_get_contents($filePath);
            // Remove scripts and styles
            $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
            $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
            $content = strip_tags($content);
            return preg_replace('/\s+/', ' ', $content);

        case 'json':
            $data = json_decode(file_get_contents($filePath), true);
            if ($data === null) return false;
            return flattenToText($data);

        case 'csv':
            return extractFromCSV($filePath);

        case 'pdf':
            return extractFromPDF($filePath);

        case 'docx':
        case 'doc':
            return extractFromDOCX($filePath);

        default:
            return file_get_contents($filePath);
    }
}

function flattenToText($data, $depth = 0) {
    if ($depth > 5) return '';
    if (is_string($data)) return $data . "\n";
    if (is_numeric($data)) return (string)$data . "\n";
    if (is_bool($data)) return ($data ? 'true' : 'false') . "\n";
    if (is_null($data)) return '';
    if (is_array($data)) {
        $parts = [];
        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? "$key: " : '';
            $parts[] = $keyStr . flattenToText($value, $depth + 1);
        }
        return implode("\n", $parts);
    }
    return '';
}

function extractFromCSV($filePath) {
    $lines = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = null;
        while (($row = fgetcsv($handle)) !== false) {
            if (!$headers) {
                $headers = $row;
                $lines[] = implode(' | ', $row);
            } else {
                $line = [];
                foreach ($row as $i => $cell) {
                    $header = $headers[$i] ?? "col$i";
                    $line[] = "$header: $cell";
                }
                $lines[] = implode(', ', $line);
            }
        }
        fclose($handle);
    }
    return implode("\n", $lines);
}

function extractFromPDF($filePath) {
    // Try pdftotext (poppler-utils, common on Linux/cPanel)
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        $escaped = escapeshellarg($filePath);
        $text = @shell_exec("pdftotext $escaped - 2>/dev/null");
        if ($text && strlen(trim($text)) > 10) {
            return $text;
        }
    }

    // Fallback: basic PDF text extraction via regex
    $content = file_get_contents($filePath);
    if ($content === false) return false;

    // Extract text streams from PDF
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams);
    $text = '';
    foreach ($streams[1] as $stream) {
        // Skip binary/compressed streams
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', substr($stream, 0, 100))) continue;
        // Extract readable text between parentheses (PDF string objects)
        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $stream, $strings);
        foreach ($strings[1] as $s) {
            $decoded = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)'], ["\n", "\r", "\t", '(', ')'], $s);
            if (mb_strlen($decoded) > 2) $text .= $decoded . ' ';
        }
    }

    // Also try BT...ET blocks
    preg_match_all('/BT\s+(.*?)\s+ET/s', $content, $btBlocks);
    foreach ($btBlocks[1] as $block) {
        preg_match_all('/\(([^)]*)\)\s*Tj/', $block, $tjs);
        foreach ($tjs[1] as $tj) {
            $text .= $tj . ' ';
        }
    }

    return trim($text) ?: false;
}

function extractFromDOCX($filePath) {
    if (!class_exists('ZipArchive')) {
        // Fallback: try reading as plain text
        $content = file_get_contents($filePath);
        return preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $content);
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return false;

    $text = '';

    // word/document.xml contains the main text
    $xmlFile = $zip->getFromName('word/document.xml');
    if ($xmlFile !== false) {
        // Remove XML tags but preserve paragraph breaks
        $xmlFile = preg_replace('/<w:p[ >]/', "\n<w:p>", $xmlFile);
        $xmlFile = preg_replace('/<w:br[^>]*\/>/', "\n", $xmlFile);
        $text = strip_tags($xmlFile);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
    }

    // Also extract text from headers/footers
    for ($i = 1; $i <= 3; $i++) {
        $header = $zip->getFromName("word/header{$i}.xml");
        if ($header !== false) $text = strip_tags($header) . "\n" . $text;
    }

    $zip->close();
    return trim($text) ?: false;
}

// ============================================
// TEXT CHUNKING
// ============================================

function chunkText($text, $chunkSize = 500, $overlap = 50) {
    // Normalize whitespace
    $text = preg_replace('/\r\n|\r/', "\n", $text);
    $text = preg_replace('/\n{4,}/', "\n\n\n", $text);

    // Split into paragraphs first
    $paragraphs = preg_split('/\n{2,}/', $text);
    $paragraphs = array_filter(array_map('trim', $paragraphs));

    $chunks = [];
    $currentChunk = '';
    $currentWords = 0;
    $overlapBuffer = [];

    foreach ($paragraphs as $para) {
        $words = preg_split('/\s+/', trim($para));
        $paraWords = count($words);

        // If a single paragraph is larger than chunk size, split it
        if ($paraWords > $chunkSize) {
            // Save current chunk first
            if ($currentChunk) {
                $chunks[] = $currentChunk;
                $overlapBuffer = array_slice(explode(' ', $currentChunk), -$overlap);
                $currentChunk = '';
                $currentWords = 0;
            }
            // Split the big paragraph
            for ($i = 0; $i < $paraWords; $i += ($chunkSize - $overlap)) {
                $slice = array_slice($words, $i, $chunkSize);
                if (count($slice) > 20) {
                    $chunks[] = implode(' ', $slice);
                }
            }
            $overlapBuffer = array_slice($words, -$overlap);
            continue;
        }

        // Start new chunk with overlap from previous
        if ($currentWords === 0 && !empty($overlapBuffer)) {
            $currentChunk = implode(' ', $overlapBuffer) . "\n\n";
            $currentWords = count($overlapBuffer);
        }

        if ($currentWords + $paraWords > $chunkSize && $currentChunk) {
            $chunks[] = trim($currentChunk);
            $overlapBuffer = array_slice(explode(' ', $currentChunk), -$overlap);
            $currentChunk = implode(' ', $overlapBuffer) . "\n\n" . $para;
            $currentWords = count($overlapBuffer) + $paraWords;
        } else {
            $currentChunk .= ($currentChunk ? "\n\n" : '') . $para;
            $currentWords += $paraWords;
        }
    }

    if (trim($currentChunk)) {
        $chunks[] = trim($currentChunk);
    }

    return array_values(array_filter($chunks, function($c) { return str_word_count($c) > 5; }));
}

// ============================================
// FTS5 INDEX
// ============================================

function buildFTSIndex() {
    $db = getDB();
    // Check if FTS5 is available
    try {
        $db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_fts USING fts5(content, content=knowledge_chunks, content_rowid=id)');
        // Rebuild the FTS index
        $db->exec("INSERT INTO knowledge_fts(knowledge_fts) VALUES('rebuild')");
    } catch (Exception $e) {
        // FTS5 not available, fallback to LIKE queries in searchKnowledgeBase()
    }
}
