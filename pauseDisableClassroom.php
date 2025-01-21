<?php
require "global_variables.php";
require "db.php";
require 'admin_emails.php';

if (!isset($_POST['token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication token is missing']);
    exit;
}

$token = $_POST['token'];
$key = base64_decode($base64Key);
$email = opensslDecrypt($token, $key);

if ($email === false) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

if (!isAdmin($email)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

$response = ['success' => false];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id'])) {
    $id = sanitizeInput($_POST['id']);

    $stmt = $pdo->prepare("UPDATE classrooms SET unavailable = 0 WHERE id = ?");
    if ($stmt->execute([$id])) {
        http_response_code(200);
        $response['success'] = true;
    } else {
        http_response_code(500);
        $response['error'] = 'Database error: ' . $stmt->errorInfo()[2];
    }
} else {
    http_response_code(400);
    $response['error'] = 'Missing classroom ID';
}

echo json_encode($response);
?>
