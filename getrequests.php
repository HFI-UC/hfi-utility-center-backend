<?php
require 'db.php';
require 'global_variables.php';
require 'admin_emails.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['token'])) {
        $token = $_POST['token'];
        $key = base64_decode($base64Key);
        $email = opensslDecrypt($token, $key);

        if ($email === false) {
            http_response_code(401);
            echo json_encode(['success'=>false, 'message' => 'Invalid Token!']);
            exit;
        }
        if (!isAdmin($email)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized!']);
            exit; 
        }
        $stmt = $pdo->prepare("SELECT requests.* FROM requests LEFT JOIN managed_rooms ON requests.room = managed_rooms.room WHERE managed_rooms.room IS NULL");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => $results]);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['success'=>false, 'message' => 'No token provided!']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success'=>false, 'message' => '114514']);
    exit;
}
?>