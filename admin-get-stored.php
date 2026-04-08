<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Return stored sensitive values from session
echo json_encode([
    'success' => true,
    'api_key' => $_SESSION['stored_api_key'] ?? '',
    'password_hash' => $_SESSION['stored_password_hash'] ?? '',
    'endpoint' => $_SESSION['stored_endpoint'] ?? 'https://api.anthropic.com/v1/messages',
    'version' => $_SESSION['stored_version'] ?? '2023-06-01'
]);
?>