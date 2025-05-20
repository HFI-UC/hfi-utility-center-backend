<?php
require_once 'global_variables.php';
require_once 'db.php';
header('Content-Type: application/json; charset=UTF-8');


// 辅助函数：发送JSON响应并退出
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

// 辅助函数：清理输入字符串
function sanitize_input_value($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// 处理请求方法和 Content-Type
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $user_email_for_log = 'unknown_user';
    $request_id_for_log = null; // ID is unknown before parsing body
    send_json_response(false, '仅支持 POST 请求。', null, 405, [
        'announcement_id' => $request_id_for_log,
        'user_email' => $user_email_for_log,
        'action' => 'edit_announcement_method_not_allowed',
        'details' => ['method' => $_SERVER['REQUEST_METHOD']]
    ]);
}

if (stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
    $user_email_for_log = 'unknown_user';
    $request_id_for_log = null;
    send_json_response(false, 'Content-Type 必须是 application/json。', null, 415, [
        'announcement_id' => $request_id_for_log,
        'user_email' => $user_email_for_log,
        'action' => 'edit_announcement_invalid_content_type',
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
        'action' => 'edit_announcement_invalid_json',
        'details' => ['json_error' => json_last_error_msg()]
    ]);
}

// 1. 身份验证
if (!isset($input_data['token'])) {
    send_json_response(false, '缺少认证 token。', null, 401, [
        'announcement_id' => isset($input_data['id']) ? filter_var($input_data['id'], FILTER_SANITIZE_NUMBER_INT) : null,
        'user_email' => 'unknown_user',
        'action' => 'edit_announcement_auth_fail_no_token',
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
        'action' => 'edit_announcement_auth_fail_invalid_token',
        'details' => ['token_prefix' => substr($token, 0, 10) . '...']
    ]);
}

// 2. 获取并验证输入参数
$id = isset($input_data['id']) ? filter_var($input_data['id'], FILTER_VALIDATE_INT) : null;
if ($id === false || $id === null) {
    send_json_response(false, '无效或缺少公告 ID。', null, 400, [
        'announcement_id' => null,
        'user_email' => $requesting_user_email,
        'action' => 'edit_announcement_validation_fail_no_id',
        'details' => ['input_id' => $input_data['id'] ?? null]
    ]);
}

$title_update = isset($input_data['title']) ? sanitize_input_value($input_data['title']) : null;
$content_update = isset($input_data['content']) ? $input_data['content'] : null; // Content is JSON string
$status_update = isset($input_data['status']) ? sanitize_input_value($input_data['status']) : null;

if ($title_update === null && $content_update === null && $status_update === null) {
    send_json_response(false, '至少需要提供一个要更新的字段 (title, content, status)。', null, 400, [
        'announcement_id' => $id,
        'user_email' => $requesting_user_email,
        'action' => 'edit_announcement_validation_fail_no_fields',
        'details' => ['input' => array_intersect_key($input_data, array_flip(['title', 'content', 'status']))]
    ]);
}

$validation_errors = [];
if ($content_update !== null) {
    if (!is_string($content_update)) {
        $validation_errors[] = '公告内容必须是字符串形式的 JSON (Quill Delta)。';
    } else {
        json_decode($content_update);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $validation_errors[] = '公告内容必须是有效的 JSON 格式 (Quill Delta)。错误: ' . json_last_error_msg();
        }
    }
}
if ($status_update !== null) {
    $valid_statuses = ['draft', 'published', 'archived']; // Edit can set to archived
    if (!in_array($status_update, $valid_statuses)) {
        $validation_errors[] = '无效的公告状态。允许的状态: ' . implode(', ', $valid_statuses) . '。';
    }
}
if (!empty($validation_errors)) {
    send_json_response(false, implode(' ', $validation_errors), null, 400, [
        'announcement_id' => $id,
        'user_email' => $requesting_user_email,
        'action' => 'edit_announcement_validation_fail',
        'details' => ['errors' => $validation_errors, 'input' => array_intersect_key($input_data, array_flip(['title', 'content', 'status']))]
    ]);
}

// 3. 更新数据库中的数据
global $pdo;

try {
    // 先获取当前公告数据，用于比较和日志记录，并检查是否存在且未软删除
    $stmt_select = $pdo->prepare("SELECT * FROM announcements WHERE id = :id AND deleted_at IS NULL");
    $stmt_select->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt_select->execute();
    $current_announcement = $stmt_select->fetch(PDO::FETCH_ASSOC);

    if (!$current_announcement) {
        send_json_response(false, '公告不存在或已被删除。', null, 404, [
            'announcement_id' => $id,
            'user_email' => $requesting_user_email,
            'action' => 'edit_announcement_fail_not_found_or_deleted', 
            'details' => null
        ]);
    }

    $update_fields_sql = [];
    $params_execute = ['id' => $id];
    $changes_detected = [];

    if ($title_update !== null && $title_update !== $current_announcement['title']) {
        $update_fields_sql[] = 'title = :title';
        $params_execute['title'] = $title_update;
        $changes_detected['title'] = ['old' => $current_announcement['title'], 'new' => $title_update];
    }
    if ($content_update !== null && $content_update !== $current_announcement['content']) {
        $update_fields_sql[] = 'content = :content';
        $params_execute['content'] = $content_update;
        $changes_detected['content'] = ['old' => '[content_omitted]', 'new' => '[content_omitted]']; // Avoid logging large content
    }
    if ($status_update !== null && $status_update !== $current_announcement['status']) {
        $update_fields_sql[] = 'status = :status';
        $params_execute['status'] = $status_update;
        $changes_detected['status'] = ['old' => $current_announcement['status'], 'new' => $status_update];
    }

    if (empty($update_fields_sql)) {
        send_json_response(true, '没有提供需要更新的字段或值未发生变化。', null, 200, [
            'announcement_id' => $id,
            'user_email' => $requesting_user_email,
            'action' => 'edit_announcement_no_change',
            'details' => ['input' => array_intersect_key($input_data, array_flip(['title', 'content', 'status']))]
        ]);
    }

    $update_fields_sql[] = 'updated_at = NOW()'; 
    $sql = "UPDATE announcements SET " . implode(', ', $update_fields_sql) . " WHERE id = :id AND deleted_at IS NULL";

    $stmt_update = $pdo->prepare($sql);

    if ($stmt_update->execute($params_execute)) {
        if ($stmt_update->rowCount() > 0) {
            send_json_response(true, '公告更新成功。', null, 200, [
                'announcement_id' => $id, 
                'user_email' => $requesting_user_email,
                'action' => 'edit_announcement_success', 
                'details' => $changes_detected
            ]);
        } else {
             // 理应在前面 $current_announcement 检查时已处理公告不存在的情况
             // 若执行到此 rowCount=0，多半是并发删除或值相同（但值相同已在上面判断 no_change）
            send_json_response(false, '公告更新失败或未找到匹配记录（可能已被删除）。', null, 404, [
                'announcement_id' => $id, 
                'user_email' => $requesting_user_email,
                'action' => 'edit_announcement_fail_on_update_not_found_or_deleted', 
                'details' => $changes_detected
            ]);
        }
    } else {
        send_json_response(false, '公告更新执行失败，请稍后重试。', null, 500, [
            'announcement_id' => $id,
            'user_email' => $requesting_user_email,
            'action' => 'edit_announcement_db_execute_fail', 
            'details' => ['changes' => $changes_detected, 'errorInfo' => $stmt_update->errorInfo()]
        ]);
    }

} catch (PDOException $e) {
    logException($e);
    send_json_response(false, '数据库操作失败，请联系管理员。', null, 500, [
        'announcement_id' => $id,
        'user_email' => $requesting_user_email,
        'action' => 'edit_announcement_db_exception', 
        'details' => ['exception_message' => $e->getMessage()]
    ]);
}

?> 