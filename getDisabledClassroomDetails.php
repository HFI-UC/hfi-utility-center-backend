<?php
require 'global_variables.php';
require 'db.php';
require 'admin_emails.php';

header('Content-Type: application/json');

// 检查是否提供了令牌
if (!isset($_POST['token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token is required.']);
    exit;
}

// 解密令牌以获取邮箱
$token = $_POST['token'];
$key = base64_decode($base64Key);
$email = opensslDecrypt($token, $key);

if ($email === false) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token.']);
    exit;
}

// 检查用户是否为管理员
if (!isAdmin($email)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 直接从数据库获取教室信息
function fetchClassrooms($pdo) {
    $stmt = $pdo->query("SELECT * FROM classrooms");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$classrooms = fetchClassrooms($pdo);

http_response_code(200);
echo json_encode(['success' => true, 'policy' => $classrooms]);
?>
