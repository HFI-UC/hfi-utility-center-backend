<?php
require "global_variables.php";
require "db.php";
require 'admin_emails.php';

if (!isset($_POST['token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '114514']);
    exit;
}


handleRequest();

$token = $_POST['token'];
$key = base64_decode($base64Key);
$email = opensslDecrypt($token, $key);

if ($email === false) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'token不合法！']);
    exit;
}

if (!isAdmin($email)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Function to sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

$response = ['success' => false];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $recordId = sanitizeInput($_POST['id']);

    $stmt = $pdo->prepare("DELETE FROM classrooms WHERE id = ?");
    if ($stmt->execute([$recordId])) {
        http_response_code(200);
        $response['success'] = true;
    } else {
        http_response_code(500);
        $response['error'] = 'Database error: ' . $stmt->errorInfo()[2];
    }
}

echo json_encode($response);
?>
