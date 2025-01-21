<?php
//submit_repair.php
require_once 'db.php';
require_once 'global_variables.php'; 

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 允许跨域请求（根据需要调整）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// 检查请求方法是否为 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 定义需要的字段
$requiredFields = ['studentName', 'subject', 'detail', 'location', 'email', 'campus', 'filePath'];

// 初始化一个数组来存储清洗后的数据
$cleanData = [];

// 验证并清洗输入数据
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => "Missing required field: $field."]);
        exit;
    }
    // 清洗数据
    $cleanData[$field] = trim($_POST[$field]);
}

// 验证邮箱格式
if (!filter_var($cleanData['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format.']);
    exit;
}

$cleanData['filePath'] = filter_var($cleanData['filePath'], FILTER_SANITIZE_URL);
$cleanData['status'] = false;
$sql = "INSERT INTO repair_requests (studentName, subject, detail, location, email, campus, filePath, status) 
        VALUES (:studentName, :subject, :detail, :location, :email, :campus, :filePath, :status)";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':studentName', $cleanData['studentName']);
    $stmt->bindParam(':subject', $cleanData['subject']);
    $stmt->bindParam(':detail', $cleanData['detail']);
    $stmt->bindParam(':location', $cleanData['location']);
    $stmt->bindParam(':email', $cleanData['email']);
    $stmt->bindParam(':campus', $cleanData['campus']);
    $stmt->bindParam(':filePath', $cleanData['filePath']);
    $stmt->bindParam(':status', $cleanData['status'], PDO::PARAM_BOOL);

    $stmt->execute();

    http_response_code(201); // Created
    echo json_encode(['success' => true, 'message'=>"Repair request submitted successfully."]);
} catch (PDOException $e) {
    error_log("Database error in submit_repair.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false, 'message' => 'An internal server error occurred.']);
}
?>
