<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Load configurations
$config = parse_ini_file(__DIR__ . '/config.ini', true);
$emotions = parse_ini_file(__DIR__ . '/emotions.ini', true);
$themes = parse_ini_file(__DIR__ . '/themes.ini', true);

if (!$config || !$emotions || !$themes) {
    echo json_encode([
        'success' => false,
        'error' => 'Configuration files not found or invalid'
    ]);
    exit;
}

// Extract emotion-theme mapping from config
$emotionThemeMap = [];
if (isset($config['emotion_theme_map'])) {
    // Validate that mapped themes exist
    foreach ($config['emotion_theme_map'] as $emotionKey => $themeKey) {
        if (isset($themes[$themeKey])) {
            $emotionThemeMap[$emotionKey] = $themeKey;
        }
    }
}

// Store full API key and password hash in session
$_SESSION['stored_api_key'] = $config['api']['api_key'] ?? '';
$_SESSION['stored_password_hash'] = $config['admin']['password'] ?? '';
$_SESSION['stored_endpoint'] = $config['api']['endpoint'] ?? 'https://api.anthropic.com/v1/messages';
$_SESSION['stored_version'] = $config['api']['anthropic_version'] ?? '2023-06-01';

// Mask API key for frontend
$apiKeySet = !empty($config['api']['api_key']) && $config['api']['api_key'] !== 'YOUR_API_KEY_HERE';
$config['api']['api_key_display'] = $apiKeySet ? '••••••••' . mb_substr($config['api']['api_key'], -4, null, 'UTF-8') : '';
unset($config['api']['api_key']);

// Remove password hash from frontend
unset($config['admin']['password']);

// Don't expose emotion_theme_map in main config
unset($config['emotion_theme_map']);

echo json_encode([
    'success' => true,
    'config' => $config,
    'emotions' => $emotions,
    'themes' => $themes,
    'emotion_theme_map' => $emotionThemeMap
], JSON_UNESCAPED_UNICODE);
?>