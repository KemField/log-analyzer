<?php
/**
 * escalate_api.php
 * Incident Escalation AJAX Endpoint
 * Processes POST requests to create incidents and escalate log statuses.
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
$priority = isset($data['priority']) ? strtoupper(trim($data['priority'])) : null;
$developer_notes = isset($data['developer_notes']) ? trim($data['developer_notes']) : '';

// Validation checks
if ($log_id === false || $log_id === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing Log ID.'
    ]);
    exit;
}

$valid_priorities = ['HIGH', 'MEDIUM', 'LOW'];
if (!in_array($priority, $valid_priorities, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid escalation priority. Allowed values: HIGH, MEDIUM, LOW.'
    ]);
    exit;
}

if (empty($developer_notes)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Developer notes are required for escalation.'
    ]);
    exit;
}

try {
    $db = Database::getConnection();
    
    // Start transaction for atomic operations
    $db->beginTransaction();
    
    // Check if the log exists and is not already escalated
    $check_stmt = $db->prepare("SELECT status FROM logs WHERE id = :id FOR UPDATE");
    $check_stmt->execute([':id' => $log_id]);
    $log = $check_stmt->fetch();
    
    if (!$log) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Log entry not found.'
        ]);
        exit;
    }
    
    if ($log['status'] === 'ESCALATED') {
        $db->rollBack();
        http_response_code(409); // Conflict
        echo json_encode([
            'success' => false,
            'message' => 'This log has already been escalated to an incident.'
        ]);
        exit;
    }
    
    // 1. Insert into incidents table
    $insert_stmt = $db->prepare("
        INSERT INTO incidents (log_id, priority, developer_notes)
        VALUES (:log_id, :priority, :developer_notes)
    ");
    $insert_stmt->execute([
        ':log_id' => $log_id,
        ':priority' => $priority,
        ':developer_notes' => $developer_notes
    ]);
    
    // 2. Update log status to ESCALATED
    $update_stmt = $db->prepare("
        UPDATE logs SET status = 'ESCALATED' WHERE id = :id
    ");
    $update_stmt->execute([':id' => $log_id]);
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Log successfully escalated to developers.',
        'incident_id' => $db->lastInsertId()
    ]);
    
} catch (Exception $e) {
    // Rollback changes on any database errors
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal database error occurred while processing escalation.',
        'details' => $e->getMessage()
    ]);
}
