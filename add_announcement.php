<?php
require_once 'global_variables.php';
require_once 'db.php';
// 设定响应头为 JSON
header('Content-Type: application/json; charset=UTF-8');

// 辅助函数：发送JSON响应并退出
function send_json_response($success, $message, $data = null, $statusCode = 200, $log_action_params = null) {
    global $pdo; // pdo 从 db.php 获取
    global $base64Key; // base64Key 从 global_variables.php 获取 (log_announcement_action 可能间接用到)

    if ($log_action_params && $pdo) {
        // 调用 global_variables.php 中定义的 log_announcement_action
        log_announcement_action($pdo, $log_action_params['announcement_id'], $log_action_params['user_email'], $log_action_params['action'], $log_action_params['details']);
    }
    http_response_code($statusCode);
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 辅助函数：清理输入字符串
function sanitize_input_value($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// 处理请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 对于方法不允许的请求，user_email 可能无法获取，但仍可记录尝试
    $user_email_for_log = isset($_POST['token']) ? opensslDecrypt($_POST['token'], base64_decode($base64Key)) : 'unknown_user';
    if ($user_email_for_log === false) $user_email_for_log = 'invalid_token';
    send_json_response(false, '仅支持 POST 请求。', null, 405, [
        'announcement_id' => null, 'user_email' => $user_email_for_log, 
        'action' => 'add_announcement_method_not_allowed', 'details' => ['method' => $_SERVER['REQUEST_METHOD']]
    ]);
}

// 1. 身份验证
if (!isset($_POST['token'])) {
    send_json_response(false, '缺少认证 token。', null, 401, [
        'announcement_id' => null, 'user_email' => 'unknown_user', 
        'action' => 'add_announcement_auth_fail_no_token', 'details' => null
    ]);
}

$token = $_POST['token'];
$key = base64_decode($base64Key); // $base64Key 来自 global_variables.php
$created_by_email = opensslDecrypt($token, $key);

if ($created_by_email === false || empty($created_by_email)) {
    send_json_response(false, '无效或已过期的 token。', null, 401, [
        'announcement_id' => null, 'user_email' => (is_string($token) ? 'invalid_token_received: ' . substr($token,0,10) . '...': 'invalid_token_received'), 
        'action' => 'add_announcement_auth_fail_invalid_token', 'details' => null
    ]);
}

// 2. 获取并验证输入参数
$title = isset($_POST['title']) ? sanitize_input_value($_POST['title']) : null;
$content = isset($_POST['content']) ? $_POST['content'] : null; 
$status = isset($_POST['status']) ? sanitize_input_value($_POST['status']) : 'published';

$validation_errors = [];
if (empty($title)) {
    $validation_errors[] = '公告标题不能为空。';
}
if (empty($content)) {
    $validation_errors[] = '公告内容不能为空。';
}

json_decode($content); // 检查 content 是否为有效 JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    $validation_errors[] = '公告内容必须是有效的 JSON 格式 (Quill Delta)。';
}

$valid_statuses = ['draft', 'published'];
if (!in_array($status, $valid_statuses)) {
    $validation_errors[] = '无效的公告状态。允许的状态: ' . implode(', ', $valid_statuses) . '。';
}

if (!empty($validation_errors)) {
    send_json_response(false, implode(' ', $validation_errors), null, 400, [
        'announcement_id' => null, 'user_email' => $created_by_email, 
        'action' => 'add_announcement_validation_fail', 'details' => ['errors' => $validation_errors, 'input' => $_POST]
    ]);
}

// 3. 将数据插入数据库
global $pdo; 

try {
    $stmt = $pdo->prepare(
        "INSERT INTO announcements (title, content, created_by, status, created_at, updated_at, deleted_at) " .
        "VALUES (:title, :content, :created_by, :status, NOW(), NOW(), NULL)" // Explicitly set deleted_at to NULL
    );

    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':content', $content); 
    $stmt->bindParam(':created_by', $created_by_email);
    $stmt->bindParam(':status', $status);

    if ($stmt->execute()) {
        $lastInsertId = $pdo->lastInsertId();
        send_json_response(true, '公告添加成功。', ['id' => $lastInsertId], 201, [
            'announcement_id' => $lastInsertId, 'user_email' => $created_by_email, 
            'action' => 'add_announcement_success', 'details' => ['title' => $title, 'status' => $status]
        ]);
    } else {
        send_json_response(false, '添加公告失败，请稍后重试。', null, 500, [
            'announcement_id' => null, 'user_email' => $created_by_email, 
            'action' => 'add_announcement_db_execute_fail', 'details' => ['title' => $title, 'status' => $status, 'errorInfo' => $stmt->errorInfo()]
        ]);
    }
} catch (PDOException $e) {
    logException($e); // 使用 global_error_handler.php 中的函数记录主异常
    send_json_response(false, '数据库操作失败，请联系管理员。', null, 500, [
        'announcement_id' => null, 'user_email' => $created_by_email, 
        'action' => 'add_announcement_db_exception', 'details' => ['title' => $title, 'status' => $status, 'exception_message' => $e->getMessage()]
    ]);
}

?> 