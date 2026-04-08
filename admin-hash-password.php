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

if (!isset($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'No password provided']);
    exit;
}

$hash = password_hash($data['password'], PASSWORD_BCRYPT);
echo json_encode(['success' => true, 'hash' => $hash]);
?>
