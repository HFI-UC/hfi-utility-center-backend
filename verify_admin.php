<?php
require 'db.php';
require 'global_variables.php';
require 'admin_emails.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $key = base64_decode($base64Key);

    // 检查令牌是否提供
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Token is required.']);
        exit;
    }
    $email = opensslDecrypt($token, $key);

    // 验证邮箱格式
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid token data.']);
        exit;
    }

    // 检查用户是否为管理员
    if (!isAdmin($email)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
        exit;
    }

    // 验证成功
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
} else {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed.']);
    exit;
}
?>
