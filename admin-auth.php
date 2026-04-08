<?php
session_start();
header('Content-Type: application/json');

$config = parse_ini_file('config.ini', true);

if (!$config) {
    echo json_encode(['success' => false, 'error' => 'Configuration error']);
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!isset($data['username']) || !isset($data['password'])) {
        echo json_encode(['success' => false, 'error' => 'Missing credentials']);
        exit;
    }
    
    $username = $data['username'];
    $password = $data['password'];
    
    if ($username === $config['admin']['username'] && 
        password_verify($password, $config['admin']['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
    exit;
}

// Check if logged in
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success' => true,
        'logged_in' => isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
