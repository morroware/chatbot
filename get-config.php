<?php
header('Content-Type: application/json');

$config = parse_ini_file(__DIR__ . '/config.ini', true);
$emotions = parse_ini_file(__DIR__ . '/emotions.ini', true);
$themes = parse_ini_file(__DIR__ . '/themes.ini', true);

if (!$config || !$emotions || !$themes) {
    echo json_encode(['success' => false, 'error' => 'Configuration files not found']);
    exit;
}

// Extract emotion-theme mapping
$emotionThemeMap = [];
if (isset($config['emotion_theme_map'])) {
    foreach ($config['emotion_theme_map'] as $emotionKey => $themeKey) {
        if (isset($themes[$themeKey])) {
            $emotionThemeMap[$emotionKey] = $themeKey;
        }
    }
}

// Build available models list (without exposing keys)
$models = [];
if (isset($config['models'])) {
    foreach ($config['models'] as $key => $value) {
        $parts = explode('|', $value);
        $models[$key] = [
            'name' => trim($parts[0] ?? $key),
            'provider' => trim($parts[1] ?? 'anthropic')
        ];
    }
}

// Remove sensitive data
unset($config['api']);
unset($config['admin']);
unset($config['emotion_theme_map']);
unset($config['models']);
unset($config['model_ids']);
// Remove scheduler secret from public config
if (isset($config['general']['scheduler_secret'])) {
    unset($config['general']['scheduler_secret']);
}

echo json_encode([
    'success' => true,
    'config' => $config,
    'emotions' => $emotions,
    'themes' => $themes,
    'emotion_theme_map' => $emotionThemeMap,
    'models' => $models
]);
