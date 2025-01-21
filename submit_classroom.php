<?php
require 'global_variables.php';
require 'db.php';
require 'admin_emails.php';

header('Content-Type: application/json');

// 确保请求方法为 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// 检查是否提供了令牌
if (!isset($_POST['token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token is required']);
    exit;
}

// 解密令牌获取邮箱
$token = $_POST['token'];
$key = base64_decode($base64Key);
$email = opensslDecrypt($token, $key);

if ($email === false) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// 检查用户是否为管理员
if (!isAdmin($email)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 定义输入清理函数
function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

$response = ['success' => false];

// 获取并验证输入数据
$classroom = isset($_POST['classroom']) ? sanitizeInput($_POST['classroom']) : null;
$days = isset($_POST['days']) ? $_POST['days'] : null;
$start_time = isset($_POST['start_time']) ? sanitizeInput($_POST['start_time']) : null;
$end_time = isset($_POST['end_time']) ? sanitizeInput($_POST['end_time']) : null;

// 检查必填字段
if (empty($classroom) || empty($days) || empty($start_time) || empty($end_time)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// 验证 $days 是否为数组
if (!is_array($days) || empty($days)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing days']);
    exit;
}

// 验证时间格式（假设为 HH:MM 格式）
if (!preg_match('/^\d{1,2}:\d{1,2}:\d{2}$/', $start_time) || !preg_match('/^\d{1,2}:\d{1,2}:\d{2}$/', $end_time)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid time format']);
    exit;
}

// 将 days 数组转换为逗号分隔的字符串
$days_string = implode(',', array_map('intval', $days));

// 准备并执行插入语句
try {
    $stmt = $pdo->prepare("INSERT INTO classrooms (classroom, days, start_time, end_time, operator, unavailable) VALUES (:classroom, :days, :start_time, :end_time, :operator, 1)");
    $stmt->bindParam(':classroom', $classroom);
    $stmt->bindParam(':days', $days_string);
    $stmt->bindParam(':start_time', $start_time);
    $stmt->bindParam(':end_time', $end_time);
    $stmt->bindParam(':operator', $email);
    $stmt->execute();

    http_response_code(201);
    $response['success'] = true;
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Internal Server Error';
}

echo json_encode($response);
?>
