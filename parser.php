<?php
/**
 * parser.php
 * Multi-Format Log Parser and Database Synchronizer
 * Parses standard PHP error logs, Apache/XAMPP logs, and custom formats.
 * Supports AJAX POST API requests and standard GET/CLI reports.
 */

require_once 'db.php';

// Check if request is an AJAX POST API call
$is_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');

/**
 * Normalizes log timestamps to MySQL DATETIME format.
 */
function sanitizeTimestamp($time_str) {
    // Remove timezone suffixes (e.g. UTC, GMT) that can sometimes throw off strtotime
    $time_str = preg_replace('/\s+[A-Z]{3,4}$/i', '', $time_str);
    $time = strtotime($time_str);
    if ($time === false) {
        return date('Y-m-d H:i:s');
    }
    return date('Y-m-d H:i:s', $time);
}

/**
 * Normalizes arbitrary severity strings to allowed ENUM values.
 */
function normalizeSeverity($sev) {
    $sev = strtoupper(trim($sev));
    if (in_array($sev, ['CRITICAL', 'EMERG', 'EMERGENCY', 'ALERT', 'CRIT'], true)) {
        return 'CRITICAL';
    }
    if (in_array($sev, ['ERROR', 'ERR', 'FATAL'], true)) {
        return 'ERROR';
    }
    if (in_array($sev, ['WARNING', 'WARN', 'NOTICE', 'DEPRECATED', 'STRICT'], true)) {
        return 'WARNING';
    }
    return 'ERROR';
}

/**
 * Normalizes Apache/XAMPP server severity modules (e.g. 'php:error' or 'ssl:warn').
 */
function parseApacheSeverity($module_sev) {
    $module_sev = strtolower(trim($module_sev));
    if (strpos($module_sev, ':') !== false) {
        $parts = explode(':', $module_sev);
        $sev = end($parts);
    } else {
        $sev = $module_sev;
    }
    
    if (in_array($sev, ['emerg', 'alert', 'crit', 'critical'], true)) {
        return 'CRITICAL';
    }
    if (in_array($sev, ['error', 'err', 'fatal'], true)) {
        return 'ERROR';
    }
    if (in_array($sev, ['warn', 'warning', 'notice'], true)) {
        return 'WARNING';
    }
    return 'ERROR';
}

/**
 * Normalizes standard PHP runtime error severities (e.g. 'Fatal error', 'Warning').
 */
function parsePhpSeverity($php_sev) {
    $php_sev = strtolower(trim($php_sev));
    if (strpos($php_sev, 'fatal') !== false || strpos($php_sev, 'parse') !== false) {
        return 'CRITICAL';
    }
    if (strpos($php_sev, 'warning') !== false || strpos($php_sev, 'notice') !== false || strpos($php_sev, 'deprecated') !== false || strpos($php_sev, 'strict') !== false) {
        return 'WARNING';
    }
    return 'ERROR';
}

/**
 * Attempts to parse offending script file paths out of log messages.
 */
function parseFilePathFromMessage($msg) {
    // Check Apache file not found errors
    if (preg_match('#(?:File does not exist|script not found or unable to stat):\s*(?P<file>(?:[a-zA-Z]:[\\\\/]+|\/)[^\s]+)#i', $msg, $path_matches)) {
        return trim($path_matches['file']);
    }
    // Check PHP error style "in C:\file.php on line X" or "in C:\file.php:X"
    if (preg_match('#\s+in\s+(?P<file>(?:[a-zA-Z]:[\\\\/]+|\/)[^:\s]+?)(?:(?::| on line )\d+)?$#i', $msg, $path_matches)) {
        return trim($path_matches['file']);
    }
    return null;
}

/**
 * Infers appropriate HTTP Status Codes based on severity or message content.
 */
function detectStatusCodeFromMessage($msg, $severity) {
    if (preg_match('/HTTP\s+(?P<code>\d+)/i', $msg, $m)) {
        return (int)$m['code'];
    }
    if (stripos($msg, 'File does not exist') !== false || stripos($msg, '404 Not Found') !== false) {
        return 404;
    }
    if (stripos($msg, 'Access denied') !== false || stripos($msg, 'Forbidden') !== false || stripos($msg, '403') !== false) {
        return 403;
    }
    if (stripos($msg, 'Unauthorized') !== false || stripos($msg, '401') !== false) {
        return 401;
    }
    if (stripos($msg, 'Fatal error') !== false || stripos($msg, 'Database connection') !== false || stripos($msg, 'SQLSTATE') !== false) {
        return 500;
    }
    if ($severity === 'CRITICAL' || $severity === 'ERROR') {
        return 500;
    }
    return null;
}

/**
 * Parses log lines using multiple Regex engines and saves unique ones to database.
 */
function parseLogLines(array $lines, PDO $db, array &$stats): array {
    $inserted_logs = [];
    
    // Prepare queries
    $check_stmt = $db->prepare("
        SELECT id FROM logs 
        WHERE timestamp = :timestamp 
          AND severity = :severity 
          AND status_code = :status_code 
          AND client_ip = :client_ip
        LIMIT 1
    ");
    
    $insert_stmt = $db->prepare("
        INSERT INTO logs (timestamp, severity, status_code, error_message, file_path, client_ip, status)
        VALUES (:timestamp, :severity, :status_code, :error_message, :file_path, :client_ip, 'NEW')
    ");
    
    // REGEX Patterns
    // 1. Custom format: [timestamp] [severity] [client IP] HTTP code - message [in file_path]
    $custom_pattern = '/^\[(?P<timestamp>[^\]]+)\]\s+\[(?P<severity>[^\]]+)\]\s+\[client\s+(?P<client_ip>[^\]]+)\]\s+HTTP\s+(?P<status_code>\d+)\s+-\s+(?P<error_message>.+?)(?:\s+in\s+(?P<file_path>.+))?$/';
    
    // 2. Apache error log with client IP: [Wed Aug 12 18:34:00.123 2026] [module:severity] [pid X] [client IP:port] message
    $apache_ip_pattern = '/^\[(?P<timestamp>[^\]]+)\]\s+\[(?P<module_severity>[^\]]+)\]\s+\[pid\s+\d+\]\s+\[client\s+(?P<client_ip>[^\]:]+)(?::\d+)?\]\s+(?P<error_message>.+)$/';
    
    // 3. Apache error log without client IP: [Wed Aug 12 18:35:00.654 2026] [module:severity] [pid X] message
    $apache_no_ip_pattern = '/^\[(?P<timestamp>[^\]]+)\]\s+\[(?P<module_severity>[^\]]+)\]\s+\[pid\s+\d+\]\s+(?P<error_message>.+)$/';
    
    // 4. Standard PHP error log: [12-Aug-2026 18:34:00 UTC] PHP Fatal error: message
    $php_pattern = '/^\[(?P<timestamp>[^\]]+)\]\s+PHP\s+(?P<php_severity>[^:]+):\s+(?P<error_message>.+)$/';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $stats['total_lines']++;
        
        $matched = false;
        $timestamp = null;
        $severity = 'ERROR';
        $client_ip = '127.0.0.1';
        $status_code = null;
        $error_message = '';
        $file_path = null;
        
        if (preg_match($custom_pattern, $line, $matches)) {
            $timestamp = sanitizeTimestamp($matches['timestamp']);
            $severity = normalizeSeverity($matches['severity']);
            $client_ip = $matches['client_ip'];
            $status_code = (int)$matches['status_code'];
            $error_message = $matches['error_message'];
            $file_path = isset($matches['file_path']) ? trim($matches['file_path']) : null;
            $matched = true;
        } elseif (preg_match($apache_ip_pattern, $line, $matches)) {
            $timestamp = sanitizeTimestamp($matches['timestamp']);
            $severity = parseApacheSeverity($matches['module_severity']);
            $client_ip = $matches['client_ip'];
            $error_message = $matches['error_message'];
            $file_path = parseFilePathFromMessage($error_message);
            $status_code = detectStatusCodeFromMessage($error_message, $severity);
            $matched = true;
        } elseif (preg_match($apache_no_ip_pattern, $line, $matches)) {
            $timestamp = sanitizeTimestamp($matches['timestamp']);
            $severity = parseApacheSeverity($matches['module_severity']);
            $client_ip = '127.0.0.1';
            $error_message = $matches['error_message'];
            $file_path = parseFilePathFromMessage($error_message);
            $status_code = detectStatusCodeFromMessage($error_message, $severity);
            $matched = true;
        } elseif (preg_match($php_pattern, $line, $matches)) {
            $timestamp = sanitizeTimestamp($matches['timestamp']);
            $severity = parsePhpSeverity($matches['php_severity']);
            $client_ip = '127.0.0.1';
            $error_message = 'PHP ' . trim($matches['php_severity']) . ': ' . $matches['error_message'];
            $file_path = parseFilePathFromMessage($error_message);
            $status_code = detectStatusCodeFromMessage($error_message, $severity);
            $matched = true;
        }
        
        if ($matched) {
            $stats['parsed_successfully']++;
            
            // Check if this log entry already exists
            $check_stmt->execute([
                ':timestamp' => $timestamp,
                ':severity' => $severity,
                ':status_code' => $status_code,
                ':client_ip' => $client_ip
            ]);
            
            if ($check_stmt->fetch()) {
                $stats['duplicates_skipped']++;
            } else {
                // Insert new log
                $insert_stmt->execute([
                    ':timestamp' => $timestamp,
                    ':severity' => $severity,
                    ':status_code' => $status_code,
                    ':error_message' => $error_message,
                    ':file_path' => $file_path,
                    ':client_ip' => $client_ip
                ]);
                $new_id = (int)$db->lastInsertId();
                $stats['inserted']++;
                
                // Add details for dynamic UI rendering
                $inserted_logs[] = [
                    'id' => $new_id,
                    'timestamp' => $timestamp,
                    'severity' => $severity,
                    'status_code' => $status_code,
                    'error_message' => $error_message,
                    'file_path' => $file_path,
                    'client_ip' => $client_ip,
                    'status' => 'NEW'
                ];
            }
        } else {
            $stats['failed_regex']++;
            $stats['errors'][] = "Failed to parse line: " . htmlspecialchars($line);
        }
    }
    
    return $inserted_logs;
}

if ($is_post) {
    // Process AJAX API Request
    header('Content-Type: application/json; charset=UTF-8');
    
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    $action = isset($data['action']) ? trim($data['action']) : '';
    
    $api_stats = [
        'total_lines' => 0,
        'parsed_successfully' => 0,
        'inserted' => 0,
        'duplicates_skipped' => 0,
        'failed_regex' => 0,
        'errors' => []
    ];
    
    try {
        $db = Database::getConnection();
        $inserted_logs = [];
        
        if ($action === 'parse_file') {
            $log_file_path = __DIR__ . '/sample_error.log';
            if (!file_exists($log_file_path)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'sample_error.log not found on server.'
                ]);
                exit;
            }
            
            $lines = file($log_file_path);
            $inserted_logs = parseLogLines($lines, $db, $api_stats);
            
        } elseif ($action === 'parse_text') {
            $raw_text = isset($data['raw_text']) ? trim($data['raw_text']) : '';
            if (empty($raw_text)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'No log data provided.'
                ]);
                exit;
            }
            
            // Normalize line endings and split
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $inserted_logs = parseLogLines($lines, $db, $api_stats);
            
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action.'
            ]);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'stats' => $api_stats,
            'inserted_logs' => $inserted_logs
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred during log parsing.',
            'details' => $e->getMessage()
        ]);
    }
    exit;
}

// -------------------------------------------------------------
// Direct GET / CLI execution logic (Backwards Compatibility)
// -------------------------------------------------------------

$log_file_path = __DIR__ . '/sample_error.log';
$stats = [
    'total_lines' => 0,
    'parsed_successfully' => 0,
    'inserted' => 0,
    'duplicates_skipped' => 0,
    'failed_regex' => 0,
    'errors' => []
];

if (!file_exists($log_file_path)) {
    $stats['errors'][] = "Log file 'sample_error.log' not found in " . __DIR__;
} else {
    try {
        $db = Database::getConnection();
        $lines = file($log_file_path);
        parseLogLines($lines, $db, $stats);
    } catch (Exception $e) {
        $stats['errors'][] = "Database error: " . $e->getMessage();
    }
}

$is_cli = (php_sapi_name() === 'cli');

if ($is_cli) {
    echo "=== LOG PARSER REPORT ===\n";
    echo "Total lines processed: {$stats['total_lines']}\n";
    echo "Successfully parsed  : {$stats['parsed_successfully']}\n";
    echo "Inserted to DB       : {$stats['inserted']}\n";
    echo "Duplicates skipped   : {$stats['duplicates_skipped']}\n";
    echo "Failed regex matches : {$stats['failed_regex']}\n";
    if (!empty($stats['errors'])) {
        echo "\nErrors/Warnings:\n";
        foreach ($stats['errors'] as $error) {
            echo " - {$error}\n";
        }
    }
    echo "=========================\n";
} else {
    // Elegant browser interface
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Log Parser Tool | L2 Support Dashboard</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-900 text-slate-100 min-h-screen font-sans p-6 md:p-12">
        <div class="max-w-4xl mx-auto space-y-8">
            <div class="flex items-center justify-between border-b border-slate-800 pb-6">
                <div>
                    <h1 class="text-3xl font-extrabold tracking-tight text-white">Log Parser Utility</h1>
                    <p class="text-slate-400 mt-1 text-sm">Imports server error logs into structured database entries.</p>
                </div>
                <a href="index.php" class="px-4 py-2 text-xs font-semibold text-cyan-400 bg-cyan-400/10 border border-cyan-400/20 rounded-lg hover:bg-cyan-400/25 transition duration-200">
                    Go to Dashboard &rarr;
                </a>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-800/50 p-5 rounded-2xl border border-slate-700/30">
                    <p class="text-xs text-slate-400 font-medium uppercase tracking-wider">Processed Lines</p>
                    <p class="text-3xl font-bold text-white mt-1"><?php echo $stats['total_lines']; ?></p>
                </div>
                <div class="bg-slate-800/50 p-5 rounded-2xl border border-slate-700/30">
                    <p class="text-xs text-slate-400 font-medium uppercase tracking-wider">Inserted</p>
                    <p class="text-3xl font-bold text-emerald-400 mt-1"><?php echo $stats['inserted']; ?></p>
                </div>
                <div class="bg-slate-800/50 p-5 rounded-2xl border border-slate-700/30">
                    <p class="text-xs text-slate-400 font-medium uppercase tracking-wider">Duplicates Skipped</p>
                    <p class="text-3xl font-bold text-amber-400 mt-1"><?php echo $stats['duplicates_skipped']; ?></p>
                </div>
                <div class="bg-slate-800/50 p-5 rounded-2xl border border-slate-700/30">
                    <p class="text-xs text-slate-400 font-medium uppercase tracking-wider">Regex Failures</p>
                    <p class="text-3xl font-bold <?php echo $stats['failed_regex'] > 0 ? 'text-red-400' : 'text-slate-400'; ?> mt-1">
                        <?php echo $stats['failed_regex']; ?>
                    </p>
                </div>
            </div>

            <div class="bg-slate-800 border border-slate-700/50 rounded-2xl p-6 shadow-xl space-y-6">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 animate-ping"></span>
                    Execution Summary
                </h2>

                <div class="space-y-4">
                    <div class="flex justify-between items-center py-2 border-b border-slate-700/30 text-sm">
                        <span class="text-slate-400">Target File</span>
                        <span class="font-mono text-cyan-300"><?php echo basename($log_file_path); ?></span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-slate-700/30 text-sm">
                        <span class="text-slate-400">Database Status</span>
                        <span class="text-emerald-400 font-semibold">Connected (OK)</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-slate-700/30 text-sm">
                        <span class="text-slate-400">Successfully Parsed</span>
                        <span class="text-slate-200"><?php echo $stats['parsed_successfully']; ?> / <?php echo $stats['total_lines']; ?></span>
                    </div>
                </div>

                <?php if (!empty($stats['errors'])): ?>
                    <div class="mt-6 space-y-3">
                        <h3 class="text-red-400 font-bold text-sm uppercase tracking-wider">Errors & Warnings</h3>
                        <div class="bg-slate-900 border border-red-500/20 rounded-xl p-4 space-y-2 max-h-60 overflow-y-auto">
                            <?php foreach ($stats['errors'] as $error): ?>
                                <div class="flex items-start gap-2.5 text-xs text-red-300 font-mono">
                                    <span class="text-red-500 font-bold flex-shrink-0">&bull;</span>
                                    <span><?php echo htmlspecialchars($error); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-4 flex items-center gap-3 mt-6">
                        <svg class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-sm text-emerald-300">Log parsing completed successfully with no errors!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
}
