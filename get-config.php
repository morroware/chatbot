<?php
header('Content-Type: application/json');

// Load configurations
$config = parse_ini_file('config.ini', true);
$emotions = parse_ini_file('emotions.ini', true);
$themes = parse_ini_file('themes.ini', true);

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

// Remove sensitive data
unset($config['api']);
unset($config['admin']);
unset($config['emotion_theme_map']); // Don't expose in main config

echo json_encode([
    'success' => true,
    'config' => $config,
    'emotions' => $emotions,
    'themes' => $themes,
    'emotion_theme_map' => $emotionThemeMap
]);
?>
