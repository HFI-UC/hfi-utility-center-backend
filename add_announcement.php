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

// 处理请求方法和 Content-Type
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $user_email_for_log = 'unknown_user'; // POSTでない場合、token取得は難しい
    send_json_response(false, '仅支持 POST 请求。', null, 405, [
        'announcement_id' => null, 'user_email' => $user_email_for_log,
        'action' => 'add_announcement_method_not_allowed', 'details' => ['method' => $_SERVER['REQUEST_METHOD']]
    ]);
}

if (stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
    $user_email_for_log = 'unknown_user'; // Content-Typeが不正な場合もtoken取得は難しい
    send_json_response(false, 'Content-Type 必须是 application/json。', null, 415, [
        'announcement_id' => null, 'user_email' => $user_email_for_log,
        'action' => 'add_announcement_invalid_content_type', 'details' => ['content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not_set']
    ]);
}

// 获取 JSON 输入
$json_input = file_get_contents('php://input');
$input_data = json_decode($json_input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $user_email_for_log = 'unknown_user'; // JSONデコード失敗時
    send_json_response(false, '无效的 JSON 输入: ' . json_last_error_msg(), null, 400, [
        'announcement_id' => null, 'user_email' => $user_email_for_log,
        'action' => 'add_announcement_invalid_json', 'details' => ['json_error' => json_last_error_msg()]
    ]);
}

// 1. 身份验证
if (!isset($input_data['token'])) {
    send_json_response(false, '缺少认证 token。', null, 401, [
        'announcement_id' => null, 'user_email' => 'unknown_user',
        'action' => 'add_announcement_auth_fail_no_token', 'details' => null
    ]);
}

$token = $input_data['token'];
$key = base64_decode($base64Key); // $base64Key 来自 global_variables.php
$created_by_email = opensslDecrypt($token, $key);

if ($created_by_email === false || empty($created_by_email)) {
    send_json_response(false, '无效或已过期的 token。', null, 401, [
        'announcement_id' => null, 'user_email' => (is_string($token) ? 'invalid_token_received: ' . substr($token,0,10) . '...': 'invalid_token_received'),
        'action' => 'add_announcement_auth_fail_invalid_token', 'details' => null
    ]);
}

// 2. 获取并验证输入参数
$title = isset($input_data['title']) ? sanitize_input_value($input_data['title']) : null;
$content = isset($input_data['content']) ? $input_data['content'] : null; // Content本身是JSON字符串，不需要sanitize
$status = isset($input_data['status']) ? sanitize_input_value($input_data['status']) : 'published';

$validation_errors = [];
if (empty($title)) {
    $validation_errors[] = '公告标题不能为空。';
}
if ($content === null) { // 检查是否为 null，因为空字符串可能是有效的JSON ("")
    $validation_errors[] = '公告内容不能为空。';
} else {
    // 确保 content 是字符串形式的 JSON，以便后续的 json_decode 检查
    if (!is_string($content)) {
        $validation_errors[] = '公告内容必须是字符串形式的 JSON (Quill Delta)。';
    } else {
        json_decode($content); // 检查 content 是否为有效 JSON 字符串
        if (json_last_error() !== JSON_ERROR_NONE) {
            $validation_errors[] = '公告内容必须是有效的 JSON 格式 (Quill Delta)。错误: ' . json_last_error_msg();
        }
    }
}


$valid_statuses = ['draft', 'published'];
if (!in_array($status, $valid_statuses)) {
    $validation_errors[] = '无效的公告状态。允许的状态: ' . implode(', ', $valid_statuses) . '。';
}

if (!empty($validation_errors)) {
    send_json_response(false, implode(' ', $validation_errors), null, 400, [
        'announcement_id' => null, 'user_email' => $created_by_email,
        'action' => 'add_announcement_validation_fail', 'details' => ['errors' => $validation_errors, 'input' => $input_data]
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
            'action' => 'add_announcement_success', 'details' => ['title' => $title, 'status' => $status] // content 不记录在details中，可能过大
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