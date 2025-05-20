<?php
require_once 'global_variables.php';
require_once 'db.php';

header('Content-Type: application/json; charset=UTF-8');

// 辅助函数：发送JSON响应并退出 (log_announcement_action from global_variables.php)
function send_json_response($success, $message, $data = null, $statusCode = 200, $log_action_params = null) {
    global $pdo;
    global $base64Key;

    if ($log_action_params && $pdo) {
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

// 辅助函数：清理输入字符串 (如果 sanitize_input_value 也计划移到全局，则需调整)
// 当前保留本地定义，因 global_variables.php 未提供
// function sanitize_input_value($data) { // No longer needed here as only ID and token are expected, and token is not sanitized directly.
// return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
// }

// 本地 get_user_ip 和 log_announcement_action 已移除

// 处理请求方法和 Content-Type
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $user_email_for_log = 'unknown_user';
    $request_id_for_log = null;
    send_json_response(false, '仅支持 POST 请求。', null, 405, [
        'announcement_id' => $request_id_for_log,
        'user_email' => $user_email_for_log,
        'action' => 'delete_announcement_method_not_allowed',
        'details' => ['method' => $_SERVER['REQUEST_METHOD']]
    ]);
}

if (stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
    $user_email_for_log = 'unknown_user';
    $request_id_for_log = null;
    send_json_response(false, 'Content-Type 必须是 application/json。', null, 415, [
        'announcement_id' => $request_id_for_log,
        'user_email' => $user_email_for_log,
        'action' => 'delete_announcement_invalid_content_type',
        'details' => ['content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not_set']
    ]);
}

// 获取 JSON 输入
$json_input = file_get_contents('php://input');
$input_data = json_decode($json_input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $user_email_for_log = 'unknown_user';
    $request_id_for_log = null;
    send_json_response(false, '无效的 JSON 输入: ' . json_last_error_msg(), null, 400, [
        'announcement_id' => $request_id_for_log,
        'user_email' => $user_email_for_log,
        'action' => 'delete_announcement_invalid_json',
        'details' => ['json_error' => json_last_error_msg()]
    ]);
}

// 1. 身份验证
if (!isset($input_data['token'])) {
    send_json_response(false, '缺少认证 token。', null, 401, [
        'announcement_id' => isset($input_data['id']) ? filter_var($input_data['id'], FILTER_SANITIZE_NUMBER_INT) : null,
        'user_email' => 'unknown_user',
        'action' => 'delete_announcement_auth_fail_no_token',
        'details' => null
    ]);
}
$token = $input_data['token'];
$key = base64_decode($base64Key);
$requesting_user_email = opensslDecrypt($token, $key);

if ($requesting_user_email === false || empty($requesting_user_email)) {
    send_json_response(false, '无效或已过期的 token。', null, 401, [
        'announcement_id' => isset($input_data['id']) ? filter_var($input_data['id'], FILTER_SANITIZE_NUMBER_INT) : null,
        'user_email' => 'invalid_token_received',
        'action' => 'delete_announcement_auth_fail_invalid_token',
        'details' => ['token_prefix' => substr($token, 0, 10) . '...']
    ]);
}

// 2. 获取并验证输入参数
$id = isset($input_data['id']) ? filter_var($input_data['id'], FILTER_VALIDATE_INT) : null;
if ($id === false || $id === null) {
    send_json_response(false, '无效或缺少公告 ID。', null, 400, [
        'announcement_id' => null,
        'user_email' => $requesting_user_email,
        'action' => 'delete_announcement_validation_fail_no_id',
        'details' => ['input_id' => $input_data['id'] ?? null] // Log the received id from JSON
    ]);
}

// 3. 执行删除
global $pdo;

try {
    // 检查公告是否存在且未被删除
    $stmt_check = $pdo->prepare("SELECT id FROM announcements WHERE id = :id AND deleted_at IS NULL");
    $stmt_check->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt_check->execute();

    if (!$stmt_check->fetch()) {
        send_json_response(false, '公告不存在或已被删除。', null, 404, [
            'announcement_id' => $id, 
            'user_email' => $requesting_user_email, // 修正
            'action' => 'delete_announcement_fail_not_found_or_already_deleted', 
            'details' => null
        ]);
    }

    $stmt_delete = $pdo->prepare("UPDATE announcements SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL");
    $stmt_delete->bindParam(':id', $id, PDO::PARAM_INT);

    if ($stmt_delete->execute()) {
        if ($stmt_delete->rowCount() > 0) {
            send_json_response(true, '公告删除成功。', null, 200, [
                'announcement_id' => $id, 
                'user_email' => $requesting_user_email, // 修正
                'action' => 'soft_delete_announcement_success', 
                'details' => null
            ]);
        } else {
            // 此情况理论上应该在上面的 fetch 检查中被捕获，或者发生并发删除
            send_json_response(false, '删除失败，公告可能已被删除或不存在。', null, 404, [
                'announcement_id' => $id, 
                'user_email' => $requesting_user_email, // 修正
                'action' => 'soft_delete_announcement_fail_on_update_not_found', 
                'details' => null
            ]);
        }
    } else {
        send_json_response(false, '删除操作执行失败，请稍后重试。', null, 500, [
            'announcement_id' => $id, 
            'user_email' => $requesting_user_email, // 修正
            'action' => 'soft_delete_announcement_db_execute_fail', 
            'details' => ['errorInfo' => $stmt_delete->errorInfo()]
        ]);
    }

} catch (PDOException $e) {
    logException($e);
    send_json_response(false, '数据库操作失败，请联系管理员。', null, 500, [
        'announcement_id' => $id, 
        'user_email' => $requesting_user_email, // 修正
        'action' => 'soft_delete_announcement_db_exception', 
        'details' => ['exception_message' => $e->getMessage()]
    ]);
}

?> 