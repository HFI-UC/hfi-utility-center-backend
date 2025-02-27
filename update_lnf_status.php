<?php
/**
 * 失物招领状态更新接口
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
    
    // 验证并清洗输入数据
    $cleanData = validateAndSanitizeInput();
    
    // 根据action获取对应的状态值和文本
    list($isFound, $statusText) = getStatusFromAction($cleanData['action']);
    
    // 验证用户密码并获取用户信息
    $userInfo = verifyPasswordAndGetUserInfo($cleanData);
    
    // 更新状态
    updateStatus($cleanData, $isFound);
    
    // 发送状态更新邮件
    sendStatusUpdateEmail($userInfo, $cleanData, $statusText);
    
    // 返回成功响应
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Status updated successfully.']);
    
} catch (Exception $e) {
    // 使用全局错误处理函数记录异常
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(405); // Method Not Allowed
        echo json_encode(['message' => $e->getMessage()]);
    } else if ($e instanceof ValidationException) {
        http_response_code(422); // Unprocessable Entity
        echo json_encode(['message' => $e->getMessage()]);
    } else if ($e instanceof AuthenticationException) {
        http_response_code(422); // Unprocessable Entity
        echo json_encode(['message' => 'Invalid password or ID.']);
    } else if ($e instanceof MailException) {
        // 邮件发送失败，但状态已更新
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Status updated successfully, but failed to send email notification.']);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['message' => 'Failed to update status.']);
    }
}

/**
 * 自定义异常类：数据验证异常
 */
class ValidationException extends Exception {}

/**
 * 自定义异常类：身份验证异常
 */
class AuthenticationException extends Exception {}

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
        throw new InvalidArgumentException('Method Not Allowed');
    }
}

/**
 * 验证并清洗输入数据
 * 
 * @return array 清洗后的数据
 * @throws ValidationException 如果输入数据无效
 */
function validateAndSanitizeInput() {
    // 定义必填字段
    $requiredFields = ['id', 'password', 'action'];
    
    // 初始化存储清洗数据的数组
    $cleanData = [];
    
    // 验证并清洗输入数据
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new ValidationException("Missing required field: $field.");
        }
        // 清洗数据
        $cleanData[$field] = trim($_POST[$field]);
    }
    
    // 验证action字段
    $action = $cleanData['action'];
    $validActions = ['found', 'not_found', 'hide'];
    
    if (!in_array($action, $validActions)) {
        throw new ValidationException('Invalid action.');
    }
    
    return $cleanData;
}

/**
 * 根据action获取对应的状态值和文本
 * 
 * @param string $action 动作名称
 * @return array [is_found值, 状态文本]
 * @throws ValidationException 如果action无效
 */
function getStatusFromAction($action) {
    switch ($action) {
        case 'found':
            return [1, 'Found'];
        case 'not_found':
            return [0, 'Not Found'];
        case 'hide':
            return [3, 'Hidden'];
        default:
            // 这种情况不应该发生，因为我们已经验证了action
            throw new ValidationException('Invalid action.');
    }
}

/**
 * 验证密码并获取用户信息
 * 
 * @param array $cleanData 清洗后的输入数据
 * @return array 用户信息
 * @throws AuthenticationException 如果密码验证失败
 */
function verifyPasswordAndGetUserInfo($cleanData) {
    global $pdo;
    
    try {
        // 使用静态变量缓存预处理语句
        static $stmt = null;
        if ($stmt === null) {
            $sql = "SELECT password, student_name, email, created_at FROM lost_and_found WHERE id = :id";
            $stmt = $pdo->prepare($sql);
        }
        
        $stmt->bindParam(':id', $cleanData['id'], PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && password_verify($cleanData['password'], $result['password'])) {
            return $result;
        } else {
            throw new AuthenticationException('Invalid password or ID.');
        }
    } catch (PDOException $e) {
        logException($e);
        throw new Exception("Database error: " . $e->getMessage());
    }
}

/**
 * 更新状态
 * 
 * @param array $cleanData 清洗后的输入数据
 * @param int $isFound 状态值
 * @throws Exception 如果数据库操作失败
 */
function updateStatus($cleanData, $isFound) {
    global $pdo;
    
    try {
        // 使用事务确保数据一致性
        $pdo->beginTransaction();
        
        // 使用静态变量缓存预处理语句
        static $updateStmt = null;
        if ($updateStmt === null) {
            $updateSql = "UPDATE lost_and_found SET is_found = :is_found, last_updated = NOW() WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
        }
        
        $updateStmt->bindParam(':is_found', $isFound, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $cleanData['id'], PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 记录更新操作
        $updateId = $pdo->lastInsertId();
        lf_logger($pdo, $cleanData['id'], 'submitter updated to' . $isFound, $updateId);
        
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        logException($e);
        throw new Exception("Failed to update status: " . $e->getMessage());
    }
}

/**
 * 发送状态更新邮件
 * 
 * @param array $userInfo 用户信息
 * @param array $cleanData 清洗后的输入数据
 * @param string $statusText 状态文本
 * @throws MailException 如果邮件发送失败
 */
function sendStatusUpdateEmail($userInfo, $cleanData, $statusText) {
    global $request_url, $email_account;
    
    try {
        // 准备邮件详情
        $studentName = htmlspecialchars($userInfo['student_name'], ENT_QUOTES, 'UTF-8');
        $recipientEmail = $userInfo['email'];
        $createdAt = htmlspecialchars($userInfo['created_at'], ENT_QUOTES, 'UTF-8');
        
        // 初始化邮件发送器
        $mail = initializeMailer();
        $mail->addAddress($recipientEmail);
        $mail->Subject = 'Status Update for Your Lost and Found Request';
        $mail->isHTML(true);
        
        // 生成邮件内容
        $mail->Body = getEmailContent($studentName, $createdAt, $statusText, $cleanData);
        
        // 发送邮件
        $mail->send();
    } catch (Exception $e) {
        logException($e);
        throw new MailException("Failed to send email: " . $e->getMessage());
    }
}

/**
 * 生成邮件内容
 * 
 * @param string $studentName 学生姓名
 * @param string $createdAt 创建时间
 * @param string $statusText 状态文本
 * @param array $cleanData 清洗后的输入数据
 * @return string 邮件HTML内容
 */
function getEmailContent($studentName, $createdAt, $statusText, $cleanData) {
    global $request_url, $email_account;
    
    // 使用输出缓冲来构建复杂的HTML内容
    ob_start();
    ?>
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #2196F3; text-align: center;">Status Update Notification</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear <strong><?= $studentName ?></strong>,<br><br>
                The status of your lost and found request submitted on <strong><?= $createdAt ?></strong> has been updated to <strong><?= $statusText ?></strong>.
            </p>
            <p style="font-size: 16px; line-height: 1.5;">
                Below are the details of your request:
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Request ID:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= htmlspecialchars($cleanData['id'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Status:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= $statusText ?></td>
                </tr>
            </table>
            <p style="font-size: 14px; color: #888; margin-top: 20px;">
                You can click <a href="<?= $request_url . $cleanData['id'] ?>">this link</a> to view more information about your request. If you have any questions, please contact us.
            </p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:<?= htmlspecialchars($email_account, ENT_QUOTES, 'UTF-8') ?>" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
