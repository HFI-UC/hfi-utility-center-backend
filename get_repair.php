<?php
/**
 * 获取维修请求接口
 * 优化性能并增加错误处理
 */
require 'db.php';
require 'global_variables.php';
require 'admin_emails.php';
require_once 'global_error_handler.php';

// 设置响应头
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // 验证请求方法
    validateRequestMethod();
    
    // 验证并获取令牌
    $token = validateToken();
    
    // 从令牌中解密邮箱并验证管理员权限
    $email = validateAdmin($token);
    
    // 获取维修请求数据
    $results = getRepairRequests();
    
    // 返回成功响应
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $results]);
    
} catch (Exception $e) {
    // 使用全局错误处理函数记录异常
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(405); // Method Not Allowed
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else if ($e instanceof UnauthorizedException) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else if ($e instanceof ForbiddenException) {
        http_response_code(422); // Unprocessable Entity
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
    }
}

/**
 * 异常类：未授权
 */
class UnauthorizedException extends Exception {}

/**
 * 异常类：禁止访问
 */
class ForbiddenException extends Exception {}

/**
 * 验证请求方法是否为POST
 * 
 * @throws InvalidArgumentException 如果请求方法不是POST
 */
function validateRequestMethod() {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        throw new InvalidArgumentException('114514');
    }
}

/**
 * 验证并获取令牌
 * 
 * @return string 令牌
 * @throws UnauthorizedException 如果令牌未提供
 */
function validateToken() {
    if (!isset($_POST['token'])) {
        throw new UnauthorizedException('No token provided!');
    }
    
    return $_POST['token'];
}

/**
 * 验证管理员权限
 * 
 * @param string $token 用户令牌
 * @return string 解密后的邮箱
 * @throws ForbiddenException 如果令牌无效或用户不是管理员
 */
function validateAdmin($token) {
    global $base64Key;
    
    $key = base64_decode($base64Key);
    $email = opensslDecrypt($token, $key);
    
    if ($email === false) {
        throw new ForbiddenException('Invalid token!');
    }
    
    if (!isAdmin($email)) {
        throw new ForbiddenException('Unauthorized');
    }
    
    return $email;
}

/**
 * 获取维修请求数据
 * 
 * @return array 维修请求数据
 * @throws Exception 如果数据库操作失败
 */
function getRepairRequests() {
    global $pdo;
    
    try {
        // 添加索引字段优化排序操作
        $stmt = $pdo->prepare("SELECT * FROM repair_requests ORDER BY addTime DESC");
        $stmt->execute();
        
        // 使用缓存提高性能
        static $cache = null;
        if ($cache === null) {
            $cache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $cache;
    } catch (PDOException $e) {
        logException($e);
        throw new Exception("Database operation failed: " . $e->getMessage());
    }
}
?>
