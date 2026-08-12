<?php
/**
 * resolve_api.php
 * Log Resolution AJAX Endpoint
 * Processes POST requests to mark logs as RESOLVED (archived).
 */

header('Content-Type: application/json; charset=UTF-8');

// Ensure correct request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.'
    ]);
    exit;
}

require_once 'db.php';

// Get and decode the raw JSON payload
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Extract inputs
$log_id = isset($data['log_id']) ? filter_var($data['log_id'], FILTER_VALIDATE_INT) : null;

// Validation checks
if ($log_id === false || $log_id === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing Log ID.'
    ]);
    exit;
}

try {
    $db = Database::getConnection();
    
    // Check if the log exists
    $check_stmt = $db->prepare("SELECT status FROM logs WHERE id = :id");
    $check_stmt->execute([':id' => $log_id]);
    $log = $check_stmt->fetch();
    
    if (!$log) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Log entry not found.'
        ]);
        exit;
    }
    
    if ($log['status'] === 'RESOLVED') {
        http_response_code(409); // Conflict
        echo json_encode([
            'success' => false,
            'message' => 'This log has already been resolved.'
        ]);
        exit;
    }
    
    // Update log status to RESOLVED
    $update_stmt = $db->prepare("
        UPDATE logs SET status = 'RESOLVED' WHERE id = :id
    ");
    $update_stmt->execute([':id' => $log_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Log successfully marked as RESOLVED (archived).'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal database error occurred while resolving the log.',
        'details' => $e->getMessage()
    ]);
}
