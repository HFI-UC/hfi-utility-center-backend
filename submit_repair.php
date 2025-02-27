<?php
/**
 * 维修请求提交接口
 * 优化性能并增加错误处理
 */
require_once 'db.php';
require_once 'global_variables.php';
require_once 'global_error_handler.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // 验证请求方法
    validateRequestMethod();
    
    // 验证并获取请求数据
    $cleanData = validateAndSanitizeInput();
    
    // 提交维修请求
    submitRepairRequest($cleanData);
    
    // 返回成功响应
    http_response_code(201); // Created
    echo json_encode(['success' => true, 'message' => "Repair request submitted successfully."]);
    
} catch (Exception $e) {
    // 使用全局错误处理函数记录异常
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
    }
}

/**
 * 验证请求方法是否为POST
 * 
 * @throws InvalidArgumentException 如果请求方法不是POST
 */
function validateRequestMethod() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Method Not Allowed');
    }
}

/**
 * 验证并清洗输入数据
 * 
 * @return array 清洗后的数据
 * @throws InvalidArgumentException 如果输入数据无效
 */
function validateAndSanitizeInput() {
    // 定义需要的字段
    $requiredFields = ['studentName', 'subject', 'detail', 'location', 'email', 'campus', 'filePath'];
    
    // 初始化一个数组来存储清洗后的数据
    $cleanData = [];
    
    // 验证并清洗输入数据
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new InvalidArgumentException("Missing required field: $field.");
        }
        // 清洗数据
        $cleanData[$field] = trim($_POST[$field]);
    }
    
    // 验证邮箱格式
    if (!filter_var($cleanData['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email format.');
    }
    
    // 清洗文件路径并设置状态
    $cleanData['filePath'] = filter_var($cleanData['filePath'], FILTER_SANITIZE_URL);
    $cleanData['status'] = false;
    
    return $cleanData;
}

/**
 * 提交维修请求到数据库
 * 
 * @param array $cleanData 清洗后的请求数据
 * @throws Exception 如果数据库操作失败
 */
function submitRepairRequest($cleanData) {
    global $pdo;
    
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        $sql = "INSERT INTO repair_requests (studentName, subject, detail, location, email, campus, filePath, status, created_at) 
                VALUES (:studentName, :subject, :detail, :location, :email, :campus, :filePath, :status, NOW())";
        
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
        
        // 提交事务
        $pdo->commit();
    } catch (PDOException $e) {
        // 回滚事务
        $pdo->rollBack();
        logException($e);
        throw new Exception("Database operation failed: " . $e->getMessage());
    }
}
?>
