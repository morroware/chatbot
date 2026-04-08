<?php
/**
 * Task Scheduler - cPanel/Cron Compatible
 *
 * HOW TO SET UP ON cPanel:
 * 1. Go to cPanel > Cron Jobs
 * 2. Set frequency (e.g., every 5 minutes: * /5 * * * *)
 * 3. Command: php /home/YOUR_USER/public_html/chatbot/scheduler.php
 *    Or for subdirectory: php /path/to/chatbot/scheduler.php
 *
 * HOW TO SET UP ON LINUX:
 * Add to crontab: crontab -e
 *   * /5 * * * * php /var/www/html/chatbot/scheduler.php >> /tmp/chatbot-scheduler.log 2>&1
 *
 * This script can also be triggered via HTTP for testing:
 *   GET scheduler.php?secret=YOUR_SECRET
 */

// Allow CLI execution and authenticated HTTP access
$isCLI = (php_sapi_name() === 'cli');
$isHTTP = !$isCLI;

if ($isHTTP) {
    // Require secret token for HTTP access
    require_once __DIR__ . '/db.php';
    $config = parse_ini_file(__DIR__ . '/config.ini', true);
    $schedulerSecret = $config['general']['scheduler_secret'] ?? '';

    $providedSecret = $_GET['secret'] ?? $_SERVER['HTTP_X_SCHEDULER_SECRET'] ?? '';

    if (empty($schedulerSecret) || $providedSecret !== $schedulerSecret) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized. Set scheduler_secret in config.ini']);
        exit;
    }
    header('Content-Type: application/json');
}

if (!$isCLI) {
    require_once __DIR__ . '/db.php';
} else {
    require_once __DIR__ . '/db.php';
}

require_once __DIR__ . '/api-tasks.php';

// ============================================
// LOCK FILE (prevent overlapping runs)
// ============================================

$lockFile = __DIR__ . '/data/scheduler.lock';
$lockHandle = fopen($lockFile, 'w');

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $msg = 'Scheduler already running, skipping.';
    if ($isCLI) {
        echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
    } else {
        echo json_encode(['status' => 'skipped', 'reason' => 'already_running']);
    }
    fclose($lockHandle);
    exit;
}

// ============================================
// MAIN EXECUTION
// ============================================

$startTime = microtime(true);
$dueTasks = getDueTasks();
$results = [];
$ran = 0;
$errors = 0;

if ($isCLI) {
    echo "[" . date('Y-m-d H:i:s') . "] Scheduler started. Found " . count($dueTasks) . " due task(s).\n";
}

foreach ($dueTasks as $task) {
    if ($isCLI) {
        echo "[" . date('Y-m-d H:i:s') . "] Running task #{$task['id']}: {$task['name']}\n";
    }

    try {
        $result = executeTask($task);

        if (isset($result['error'])) {
            $errors++;
            $results[] = [
                'task_id' => $task['id'],
                'task_name' => $task['name'],
                'status' => 'error',
                'error' => $result['error'],
            ];
            if ($isCLI) {
                echo "  [ERROR] {$result['error']}\n";
            }
        } else {
            $ran++;
            $output_preview = mb_substr($result['output'] ?? '', 0, 100, 'UTF-8');
            $results[] = [
                'task_id' => $task['id'],
                'task_name' => $task['name'],
                'status' => 'success',
                'output_preview' => $output_preview,
            ];
            if ($isCLI) {
                echo "  [OK] " . $output_preview . "\n";
            }
        }
    } catch (Exception $e) {
        $errors++;
        $results[] = [
            'task_id' => $task['id'],
            'task_name' => $task['name'],
            'status' => 'exception',
            'error' => $e->getMessage(),
        ];
        if ($isCLI) {
            echo "  [EXCEPTION] " . $e->getMessage() . "\n";
        }
    }
}

$elapsed = round(microtime(true) - $startTime, 2);

if ($isCLI) {
    echo "[" . date('Y-m-d H:i:s') . "] Finished. Ran: $ran, Errors: $errors, Time: {$elapsed}s\n";
}

// Release lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($lockFile);

if ($isHTTP) {
    echo json_encode([
        'status' => 'done',
        'tasks_ran' => $ran,
        'errors' => $errors,
        'elapsed_seconds' => $elapsed,
        'results' => $results,
    ]);
}
