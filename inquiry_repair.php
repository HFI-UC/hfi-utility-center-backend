<?php
// inquiry_repair.php

require_once('db.php');
require_once 'global_variables.php'; // 确保这个文件包含了全局错误处理器和数据库连接

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 允许跨域请求（根据需要调整）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// 检查请求方法是否为 GET 或 POST
$allowedMethods = ['GET', 'POST'];
if (!in_array($_SERVER['REQUEST_METHOD'], $allowedMethods)) {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method Not Allowed.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id,subject,detail,location,campus,filePath,addTime,status FROM repair_requests ORDER BY addTime DESC");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => $results]);
        exit;





/*——————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————————*/
// 获取邮箱地址
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['email'])) {
        $email = trim($_GET['email']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 支持 `application/x-www-form-urlencoded` 和 `application/json`
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

    if (strpos($contentType, 'application/json') !== false) {
        // 处理 JSON 输入
        $content = trim(file_get_contents("php://input"));
        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['email'])) {
            $email = trim($decoded['email']);
        }
    } else {
        // 处理表单输入
        if (isset($_POST['email'])) {
            $email = trim($_POST['email']);
        }
    }
}

// 验证邮箱是否提供
if (empty($email)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Email address is required.']);
    exit;
}

// 验证邮箱格式
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format.']);
    exit;
}

// 清洗邮箱地址
$cleanEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

// 查询数据库
$sql = "SELECT * FROM repair_requests WHERE email = :email ORDER BY addTime DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $cleanEmail, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        // 成功响应，返回数据
        http_response_code(200); // OK
        echo json_encode(['data' => $results], JSON_UNESCAPED_UNICODE);
    } else {
        // 无相关记录
        http_response_code(404); // Not Found
        echo json_encode(['message' => 'No repair requests found for the provided email.']);
    }
} catch (PDOException $e) {
    // 记录数据库错误
    error_log("Database error in get_repair_by_email.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal server error occurred.']);
}
?>
