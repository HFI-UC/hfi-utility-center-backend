<?php
/**
 * 失物招领提交接口
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
    
    // 处理密码并提交到数据库
    $addId = submitLostAndFoundEntry($cleanData);
    
    // 发送确认邮件
    sendConfirmationEmail($cleanData, $addId);
    
    // 返回成功响应
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Your L&F entry ID is ' . $addId . ', please take good care of it.']);
    
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
    } else if ($e instanceof MailException) {
        // 邮件发送失败，但我们仍然提供成功响应，因为数据已经保存
        http_response_code(201);
        echo json_encode([
            'success' => true, 
            'message' => 'Your L&F entry was saved, but we could not send a confirmation email.'
        ]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['message' => 'Failed to submit entry.']);
    }
}

/**
 * 自定义异常类：数据验证异常
 */
class ValidationException extends Exception {}

/**
 * 自定义异常类：邮件异常
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
    // 定义需要的字段
    // type为lost时是丢失物品发布悬赏，为found时是失物寻找失主
    $requiredFields = ['student_name', 'detail', 'location', 'email', 'campus', 'file_path', 'password', 'type', 'event_time'];
    $optionalFields = ['reward', 'alt_contact'];
    
    // 初始化一个数组来存储清洗后的数据
    $cleanData = [];
    
    // 验证并清洗必填字段
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new ValidationException("Missing required field: $field.");
        }
        // 清洗数据
        $cleanData[$field] = trim($_POST[$field]);
    }
    
    // 清洗可选字段
    foreach ($optionalFields as $field) {
        if (isset($_POST[$field]) && !empty(trim($_POST[$field]))) {
            $cleanData[$field] = trim($_POST[$field]);
        } else {
            // 设置为null或默认值
            $cleanData[$field] = null;
        }
    }
    
    // 验证邮箱格式
    if (!filter_var($cleanData['email'], FILTER_VALIDATE_EMAIL)) {
        throw new ValidationException('Invalid email format.');
    }
    
    return $cleanData;
}

/**
 * 提交失物招领条目到数据库
 * 
 * @param array $cleanData 清洗后的输入数据
 * @return int 新插入条目的ID
 * @throws Exception 如果数据库操作失败
 */
function submitLostAndFoundEntry($cleanData) {
    global $pdo;
    
    try {
        // 使用事务确保数据一致性
        $pdo->beginTransaction();
        
        // 对密码进行哈希处理
        $hashedPassword = password_hash($cleanData['password'], PASSWORD_DEFAULT);
        
        // 准备SQL插入语句
        $sql = "INSERT INTO lost_and_found (
                    type, student_name, detail, location, email, campus, file_path, 
                    reward, alt_contact, password, created_at, last_updated, event_time
                ) VALUES (
                    :type, :student_name, :detail, :location, :email, :campus, :file_path,
                    :reward, :alt_contact, :password, NOW(), NOW(), :event_time
                )";
        
        // 预处理SQL
        $stmt = $pdo->prepare($sql);
        
        // 绑定参数
        $stmt->bindParam(':type', $cleanData['type']);
        $stmt->bindParam(':student_name', $cleanData['student_name']);
        $stmt->bindParam(':detail', $cleanData['detail']);
        $stmt->bindParam(':location', $cleanData['location']);
        $stmt->bindParam(':email', $cleanData['email']);
        $stmt->bindParam(':campus', $cleanData['campus']);
        $stmt->bindParam(':file_path', $cleanData['file_path']);
        $stmt->bindParam(':alt_contact', $cleanData['alt_contact']);
        $stmt->bindParam(':reward', $cleanData['reward']);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':event_time', $cleanData['event_time']);
        
        // 执行SQL
        $stmt->execute();
        
        // 获取新插入行的ID
        $addId = $pdo->lastInsertId();
        
        // 记录操作日志
        lf_logger($pdo, $addId, 'add', '');
        
        // 提交事务
        $pdo->commit();
        
        return $addId;
    } catch (PDOException $e) {
        // 回滚事务
        $pdo->rollBack();
        logException($e);
        throw new Exception("Database operation failed: " . $e->getMessage());
    }
}

/**
 * 发送确认邮件
 * 
 * @param array $cleanData 清洗后的输入数据
 * @param int $addId 新插入条目的ID
 * @throws MailException 如果邮件发送失败
 */
function sendConfirmationEmail($cleanData, $addId) {
    global $request_url, $email_account;
    
    try {
        // 初始化邮件发送器
        $mail = initializeMailer();
        
        // 设置邮件基本信息
        $recipientEmail = $cleanData['email'];
        $student_name = $cleanData['student_name'];
        $addTime = date('Y-m-d H:i:s');
        
        // 根据类型设置标题
        $type = ucfirst($cleanData['type']); // 'Lost' 或 'Found'
        
        // 准备邮件内容
        $mail->addAddress($recipientEmail);
        $mail->Subject = 'Lost and Found Request Submitted';
        $mail->isHTML(true);
        
        // 生成邮件正文
        $mail->Body = getEmailContent($cleanData, $type, $student_name, $addTime, $addId);
        
        // 发送邮件
        $mail->send();
    } catch (Exception $e) {
        logException($e);
        throw new MailException("Failed to send confirmation email: " . $e->getMessage());
    }
}

/**
 * 生成邮件内容
 * 
 * @param array $cleanData 清洗后的输入数据
 * @param string $type 类型（Lost或Found）
 * @param string $student_name 学生姓名
 * @param string $addTime 添加时间
 * @param int $addId 条目ID
 * @return string 邮件HTML内容
 */
function getEmailContent($cleanData, $type, $student_name, $addTime, $addId) {
    global $request_url, $email_account;
    
    // 使用输出缓冲来构建复杂的HTML内容
    ob_start();
    ?>
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #4CAF50; text-align: center;">Lost and Found Request Submitted</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear <strong><?= htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') ?></strong>,<br><br>
                Your <?= strtolower($type) ?> request submitted on <strong><?= htmlspecialchars($addTime, ENT_QUOTES, 'UTF-8') ?></strong> has been received successfully.
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Type:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Request Id:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= htmlspecialchars($addId, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Detail:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= nl2br(htmlspecialchars($cleanData['detail'], ENT_QUOTES, 'UTF-8')) ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Location:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= htmlspecialchars($cleanData['location'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Campus:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= htmlspecialchars(convertCampus($cleanData['campus']), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Reward:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= (!empty($cleanData['reward']) ? htmlspecialchars($cleanData['reward'], ENT_QUOTES, 'UTF-8') : 'N/A') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Passphrase:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd; color: red"><?= htmlspecialchars($cleanData['password'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Alternate Contact:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= (!empty($cleanData['alt_contact']) ? htmlspecialchars($cleanData['alt_contact'], ENT_QUOTES, 'UTF-8') : 'N/A') ?></td>
                </tr>
            </table>
            <p style="font-size: 14px; color: #888; margin-top: 20px;">
                We will notify you once we find a match for your request. You can also check your request status at <a href="<?= $request_url.$addId ?>">here.</a> If you have any questions, please contact us.
            </p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:<?= $email_account ?>" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
