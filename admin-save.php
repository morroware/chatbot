<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['file']) || !isset($data['content'])) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

$allowedFiles = ['config.ini', 'emotions.ini', 'themes.ini'];
$file = $data['file'];

if (!in_array($file, $allowedFiles)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file']);
    exit;
}

// Create timestamped backup
$backupFile = $file . '.backup.' . time();
if (file_exists($file)) {
    copy($file, $backupFile);
    
    // Clean old backups (keep last 5)
    $backups = glob($file . '.backup.*');
    if (count($backups) > 5) {
        // Sort by modification time (oldest first)
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        // Delete oldest backups, keep only last 5
        foreach (array_slice($backups, 0, -5) as $oldBackup) {
            @unlink($oldBackup);
        }
    }
}

// Write new content
$content = $data['content'];
if (file_put_contents($file, $content) !== false) {
    echo json_encode(['success' => true, 'message' => 'Configuration saved']);
} else {
    // Restore most recent backup on failure
    $backups = glob($file . '.backup.*');
    if (!empty($backups)) {
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        copy($backups[0], $file);
    }
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
}
?>
