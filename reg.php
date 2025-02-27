<?php
/**
 * 用户注册接口
 * 优化性能并增加错误处理
 */
require 'global_variables.php';
require 'db.php';
require_once 'global_error_handler.php';

// 启动会话并设置响应头
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// 注意：此接口当前处于禁用状态
// 如需启用，请删除下面的exit()语句并取消注释相关代码
exit; // 临时停用接口

try {
    // 检查请求方法和参数
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('仅支持POST请求');
    }
    
    if (!isset($_POST['regusername'], $_POST['regpassword'])) {
        throw new InvalidArgumentException('缺少必要参数');
    }
    
    // 获取并验证用户输入
    $username = filter_input(INPUT_POST, 'regusername', FILTER_SANITIZE_STRING);
    $email = $username; // 使用用户名作为邮箱
    $password = $_POST['regpassword']; // 密码会被哈希，无需过滤
    
    // 验证邮箱格式
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('无效的邮箱地址');
    }
    
    // 检查用户名是否存在
    checkUsernameAvailability($username);
    
    // 注册新用户
    registerNewUser($username, $email, $password);
    
    // 返回成功响应
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => '注册成功']);
    
} catch (Exception $e) {
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(400);
    } else {
        http_response_code(500);
    }
    
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * 检查用户名是否可用
 * 
 * @param string $username 用户名
 * @throws InvalidArgumentException 如果用户名已存在
 */
function checkUsernameAvailability($username) {
    global $pdo;
    
    try {
        // 使用索引列进行查询优化
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException('用户名已被注册');
        }
    } catch (PDOException $e) {
        logException($e);
        throw new Exception('检查用户名时发生错误');
    }
}

/**
 * 注册新用户
 * 
 * @param string $username 用户名
 * @param string $email 邮箱
 * @param string $password 密码（明文）
 * @throws Exception 如果注册失败
 */
function registerNewUser($username, $email, $password) {
    global $pdo;
    
    try {
        // 使用事务确保数据一致性
        $pdo->beginTransaction();
        
        // 使用更安全的密码哈希算法，增加成本因子以提高安全性
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        // 插入新用户
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, created_at) VALUES (:username, :password, :email, NOW())");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        // 获取新插入用户的ID
        $userId = $pdo->lastInsertId();
        
        // 记录注册日志
        $logStmt = $pdo->prepare("INSERT INTO registration_logs (user_id, ip_address, user_agent, register_time) VALUES (:user_id, :ip, :user_agent, NOW())");
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $logStmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $logStmt->execute();
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        logException($e);
        throw new Exception('注册失败，请稍后再试');
    }
}
?>
