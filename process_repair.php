<?php
/**
 * 处理维修请求接口
 * 优化性能并增加错误处理
 */
require_once 'global_variables.php';
require 'db.php';
require 'admin_emails.php';
require_once 'global_error_handler.php';
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // 验证请求方法
    validateRequestMethod();
    
    // 获取并验证请求数据
    $requestData = getRequestData();
    
    // 验证管理员权限
    $email = validateAdmin($requestData['token']);
    
    // 验证请求ID和操作类型
    validateRequest($requestData);
    
    // 获取维修请求信息
    $request = getRepairRequest($requestData['id']);
    
    // 更新维修请求状态
    updateRepairStatus($requestData['id'], $requestData['action']);
    
    // 发送邮件通知用户
    sendNotificationEmail($request, $requestData['action']);
    
    // 返回成功响应
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Repair request updated and email sent successfully.'
    ]);
    
} catch (Exception $e) {
    // 使用全局错误处理函数记录异常
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => $e->getMessage()]);
    } else if ($e instanceof UnauthorizedException) {
        http_response_code(403); // Forbidden
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else if ($e instanceof NotFoundException) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => $e->getMessage()]);
    } else if ($e instanceof MailException) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Failed to send email: ' . $e->getMessage()]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'An internal server error occurred.']);
    }
}

/**
 * 自定义异常类：未授权
 */
class UnauthorizedException extends Exception {}

/**
 * 自定义异常类：资源未找到
 */
class NotFoundException extends Exception {}

/**
 * 自定义异常类：邮件发送异常
 */
class MailException extends Exception {}

/**
 * 验证请求方法是否为POST
 * 
 * @throws InvalidArgumentException 如果请求方法不是POST
 */
function validateRequestMethod() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Only POST method is allowed.');
    }
}

/**
 * 获取并验证请求数据
 * 
 * @return array 包含id、token和action的数组
 * @throws InvalidArgumentException 如果请求数据无效
 */
function getRequestData() {
    // 初始化变量
    $id = $token = $action = null;
    
    // 确定内容类型
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
    
    if (strpos($contentType, 'application/json') !== false) {
        // 处理 JSON 输入
        static $jsonData = null;
        if ($jsonData === null) {
            $input = file_get_contents('php://input');
            $jsonData = json_decode($input, true);
            
            if (!is_array($jsonData)) {
                throw new InvalidArgumentException('Invalid JSON input');
            }
        }
        
        $id = isset($jsonData['id']) ? intval($jsonData['id']) : null;
        $token = isset($jsonData['token']) ? trim($jsonData['token']) : null;
        $action = isset($jsonData['action']) ? intval($jsonData['action']) : null;
    } else {
        // 处理表单数据输入
        $id = isset($_POST['id']) ? intval($_POST['id']) : null;
        $token = isset($_POST['token']) ? trim($_POST['token']) : null;
        $action = isset($_POST['action']) ? intval($_POST['action']) : null;
    }
    
    // 验证输入数据
    if ($id === null || empty($token) || $action === null) {
        throw new InvalidArgumentException('Missing required fields: id, token, or action.');
    }
    
    return [
        'id' => $id,
        'token' => $token,
        'action' => $action
    ];
}

/**
 * 验证管理员权限
 * 
 * @param string $token 用户令牌
 * @return string 解密后的邮箱
 * @throws UnauthorizedException 如果用户不是管理员
 */
function validateAdmin($token) {
    global $base64Key;
    
    $key = base64_decode($base64Key);
    $email = opensslDecrypt($token, $key);
    
    if ($email === false || !isAdmin($email)) {
        throw new UnauthorizedException('Unauthorized.');
    }
    
    return $email;
}

/**
 * 验证请求参数
 * 
 * @param array $requestData 请求数据
 * @throws InvalidArgumentException 如果请求参数无效
 */
function validateRequest($requestData) {
    // 验证 action
    $validActions = [1, 2, 3];
    if (!in_array($requestData['action'], $validActions)) {
        throw new InvalidArgumentException('Invalid action provided.');
    }
}

/**
 * 获取维修请求信息
 * 
 * @param int $id 维修请求ID
 * @return array 维修请求数据
 * @throws NotFoundException 如果维修请求未找到
 * @throws Exception 如果数据库操作失败
 */
function getRepairRequest($id) {
    global $pdo;
    
    try {
        // 使用静态变量缓存查询结果
        static $requestCache = [];
        
        if (isset($requestCache[$id])) {
            return $requestCache[$id];
        }
        
        $sql = "SELECT * FROM repair_requests WHERE id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new NotFoundException('Repair request not found.');
        }
        
        $requestCache[$id] = $request;
        return $request;
    } catch (PDOException $e) {
        logException($e);
        throw new Exception("Database operation failed: " . $e->getMessage());
    }
}

/**
 * 更新维修请求状态
 * 
 * @param int $id 维修请求ID
 * @param int $status 新状态值
 * @throws Exception 如果数据库操作失败
 */
function updateRepairStatus($id, $status) {
    global $pdo;
    
    try {
        // 使用事务确保数据一致性
        $pdo->beginTransaction();
        
        $sql = "UPDATE repair_requests SET status = :status, update_time = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status', $status, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        logException($e);
        throw new Exception("Failed to update repair status: " . $e->getMessage());
    }
}

/**
 * 发送通知邮件
 * 
 * @param array $request 维修请求数据
 * @param int $action 操作类型
 * @throws MailException 如果邮件发送失败
 */
function sendNotificationEmail($request, $action) {
    global $domain_emaddr;
    
    // 获取收件人信息
    $toEmail = $request['email'];
    $studentName = $request['studentName'];
    
    try {
        // 初始化 PHPMailer
        $mail = initializeMailer();
        
        // 添加收件人
        $mail->addAddress($toEmail, $studentName);
        
        // 设置内容格式
        $mail->isHTML(true);
        
        // 根据操作类型设置邮件主题和内容
        $emailData = getEmailContent($action, $studentName, $request);
        $mail->Subject = $emailData['subject'];
        $mail->Body = $emailData['body'];
        
        // 发送邮件
        $mail->send();
    } catch (Exception $e) {
        throw new MailException($e->getMessage());
    }
}

/**
 * 根据操作类型获取邮件内容
 * 
 * @param int $action 操作类型
 * @param string $studentName 学生姓名
 * @param array $request 维修请求数据
 * @return array 包含subject和body的数组
 */
function getEmailContent($action, $studentName, $request) {
    global $domain_emaddr;
    
    // 根据操作类型设置邮件主题、主题色和状态文本
    if ($action === 1) { // 已完成
        $subject = 'Repair Request Completed';
        $themeColor = '#4CAF50'; // 绿色
        $statusText = 'Your repair request has been completed.';
        $footerText = 'Thank you for using our services! If you have any questions, please feel free to contact us.';
    } elseif ($action === 2) { // 已打回
        $subject = 'Repair Request Returned';
        $themeColor = '#F44336'; // 红色
        $statusText = 'Your repair request has been returned. Please provide accurate information and resubmit.';
        $footerText = 'Please provide accurate information and resubmit your request. If you have any questions, feel free to contact us.';
    } elseif ($action === 3) { // 重复申请
        $subject = 'Duplicate Repair Request';
        $themeColor = '#FF9800'; // 橙色
        $statusText = 'Your repair request is a duplicate. Please do not submit multiple requests for the same issue.';
        $footerText = 'Please note that we are already processing a previous request that report the same incident. If you have additional information, please contact us directly instead of submitting a new request.';
    }
    
    // 构建邮件内容
    $body = '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: ' . $themeColor . '; text-align: center;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear ' . htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') . ', ' . $statusText . ' Submission Time: <strong>' . htmlspecialchars($request['addTime'], ENT_QUOTES, 'UTF-8') . '</strong>.
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Subject:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($request['subject'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Details:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($request['detail'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Location:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($request['location'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
            </table>
            <p style="font-size: 14px; color: #888; margin-top: 20px;">' . $footerText . '</p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $domain_emaddr . '" style="background-color: ' . $themeColor . '; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>';
    
    return [
        'subject' => $subject,
        'body' => $body
    ];
}
?>
