<?php
require_once 'priv_emails.php'; // 包含 isPriv 函数

header('Content-Type: application/json');

// 检查是否提供了令牌
if (isset($_POST['token'])) {
    $token = $_POST['token'];
    $key = base64_decode($base64Key);

    // 解密令牌
    $email = opensslDecrypt($token, $key);

    // 检查解密是否成功
    if ($email === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid token.']);
        exit;
    }

    // 检查用户是否具有特权
    if (isPriv($email)) {
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
} else {
    // 缺少令牌
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token is required.']);
    exit;
}
?>
