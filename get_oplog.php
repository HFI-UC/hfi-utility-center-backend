<?php
require 'db.php'; // Database connection
require 'global_variables.php';
require 'admin_emails.php';
header('Content-Type: application/json');

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.']);
    exit;
}

// Check if 'token' and 'ids' are provided
if (!isset($_POST['token']) || !isset($_POST['ids'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Parameter is incomplete. '.$_POST['ids']]);
    exit;
}

// Retrieve and sanitize the token
$token = $_POST['token'];
$key = base64_decode($base64Key);
$email = opensslDecrypt($token, $key);
// Check if token is valid
if ($email === false) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Invalid token.']);
    exit;
}

// Verify if the user has necessary permissions (optional, implement as needed)
// For example, check if the user is an admin or has access to view logs
if (!isAdmin($email)) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get the 'ids' parameter and process it
$idsParam = $_POST['ids'];
$idArray = explode(',', $idsParam);

// Sanitize and validate the IDs
$ids = [];
foreach ($idArray as $id) {
    $id = trim($id);
    if (ctype_digit($id)) {
        $ids[] = (int)$id;
    }
}

// Check if we have valid IDs
if (empty($ids)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided']);
    exit;
}

try {
    // Build the placeholders for the IN clause
    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');

    // Prepare the SQL statement
    $sql = "SELECT reqId, operator, time, operation, reason FROM oplog WHERE reqId IN ($placeholders)";
    $stmt = $pdo->prepare($sql);

    // Execute the statement with the array of IDs
    $stmt->execute($ids);

    // Fetch all matching records
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize results by reqId
    $response = [];
    foreach ($results as $row) {
        $reqId = $row['reqId'];
        $response[$reqId][] = [
            'operator'  => $row['operator'],
            'time'      => $row['time'],
            'operation' => $row['operation'],
            'reason'    => $row['reason'],
        ];
    }

    http_response_code(200); // OK
    echo json_encode(['success' => true, 'data' => $response]);
} catch (PDOException $e) {
    // Log the error for debugging purposes
    error_log('Database error: ' . $e->getMessage());

    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Internal Server ERR: ' . $e->getMessage()]);
}
?>
