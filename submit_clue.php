<?php
/**
 * 线索提交接口
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
    
    // 提交线索并获取线索ID
    $clueId = submitClue($cleanData);
    
    // 更新失物招领状态
    updateLostAndFoundStatus($cleanData['lost_info_id'], $clueId);
    
    // 获取失物招领者信息
    $requester = getRequesterInfo($cleanData['lost_info_id']);
    
    // 发送通知邮件
    sendNotificationEmail($requester, $cleanData, $clueId);
    
    // 返回成功响应
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Clue submitted successfully.']);
    
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
        // 邮件发送失败，但提交成功
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Clue submitted successfully, unable to notify the requester.']);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['message' => 'Failed to submit clue.']);
    }
}

/**
 * 自定义异常类：数据验证异常
 */
class ValidationException extends Exception {}

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
    $requiredFields = ['campus', 'detail', 'location', 'filePath', 'contact', 'lost_info_id'];
    
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
    
    // 可以在此添加其他数据验证逻辑
    // 例如验证联系信息格式等
    
    return $cleanData;
}

/**
 * 提交线索到数据库
 * 
 * @param array $cleanData 清洗后的输入数据
 * @return int 新插入线索的ID
 * @throws Exception 如果数据库操作失败
 */
function submitClue($cleanData) {
    global $pdo;
    
    try {
        // 使用事务确保数据一致性
        $pdo->beginTransaction();
        
        // 获取当前时间
        $addTime = date('Y-m-d H:i:s');
        
        // 准备SQL插入语句
        $sql = "INSERT INTO volunteer_clues (campus, detail, location, file_path, contact, lost_info_id, created_at) 
                VALUES (:campus, :detail, :location, :filePath, :contact, :lost_info_id, :created_at)";
        
        // 使用静态变量缓存预处理语句
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $pdo->prepare($sql);
        }
        
        // 绑定参数并执行
        $stmt->bindParam(':campus', $cleanData['campus']);
        $stmt->bindParam(':detail', $cleanData['detail']);
        $stmt->bindParam(':location', $cleanData['location']);
        $stmt->bindParam(':filePath', $cleanData['filePath']);
        $stmt->bindParam(':contact', $cleanData['contact']);
        $stmt->bindParam(':lost_info_id', $cleanData['lost_info_id']);
        $stmt->bindParam(':created_at', $addTime);
        
        $stmt->execute();
        
        // 获取新插入线索的ID
        $clueId = $pdo->lastInsertId();
        
        // 记录线索提交操作
        lf_logger($pdo, $cleanData['lost_info_id'], 'clue submission', $clueId);
        
        $pdo->commit();
        
        return $clueId;
    } catch (PDOException $e) {
        // 如果发生错误，回滚事务
        $pdo->rollBack();
        logException($e);
        throw new Exception("Failed to submit clue: " . $e->getMessage());
    }
}

/**
 * 更新失物招领状态
 * 
 * @param int $lostInfoId 失物招领ID
 * @param int $clueId 线索ID
 * @throws Exception 如果数据库操作失败
 */
function updateLostAndFoundStatus($lostInfoId, $clueId) {
    global $pdo;
    
    try {
        // 使用事务确保数据一致性
        $pdo->beginTransaction();
        
        // 更新失物招领状态
        $sql = "UPDATE lost_and_found SET last_updated = NOW(), is_found = 2 WHERE id = :id";
        
        // 使用静态变量缓存预处理语句
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $pdo->prepare($sql);
        }
        
        $stmt->bindParam(':id', $lostInfoId);
        $stmt->execute();
        
        // 记录状态更新操作
        lf_logger($pdo, $lostInfoId, 'clue submitted, status updated to 2', $clueId);
        
        $pdo->commit();
    } catch (PDOException $e) {
        // 如果发生错误，回滚事务
        $pdo->rollBack();
        logException($e);
        throw new Exception("Failed to update lost and found status: " . $e->getMessage());
    }
}

/**
 * 获取失物招领者信息
 * 
 * @param int $lostInfoId 失物招领ID
 * @return array 失物招领者信息
 * @throws Exception 如果数据库操作失败
 */
function getRequesterInfo($lostInfoId) {
    global $pdo;
    
    try {
        // 查询失物招领者信息
        $sql = "SELECT created_at, student_name, email FROM lost_and_found WHERE id = :id";
        
        // 使用静态变量缓存预处理语句
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $pdo->prepare($sql);
        }
        
        $stmt->bindParam(':id', $lostInfoId);
        $stmt->execute();
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($result)) {
            throw new Exception("Lost and found request not found.");
        }
        
        return $result[0];
    } catch (PDOException $e) {
        logException($e);
        throw new Exception("Failed to get requester information: " . $e->getMessage());
    }
}

/**
 * 发送通知邮件
 * 
 * @param array $requester 失物招领者信息
 * @param array $cleanData 清洗后的输入数据
 * @param int $clueId 线索ID
 * @throws MailException 如果邮件发送失败
 */
function sendNotificationEmail($requester, $cleanData, $clueId) {
    global $clue_url, $email_account;
    
    try {
        // 初始化邮件发送器
        $mail = initializeMailer();
        
        // 准备邮件内容
        $recipientEmail = $requester['email'];
        $studentName = htmlspecialchars($requester['student_name'], ENT_QUOTES, 'UTF-8');
        $createdAt = htmlspecialchars($requester['created_at'], ENT_QUOTES, 'UTF-8');
        
        // 设置邮件收件人和主题
        $mail->addAddress($recipientEmail);
        $mail->Subject = 'Volunteer Clue Submission';
        $mail->isHTML(true);
        
        // 生成邮件内容
        $mail->Body = getEmailContent($requester, $cleanData, $clueId);
        
        // 发送邮件
        $mail->send();
    } catch (Exception $e) {
        logException($e);
        throw new MailException("Failed to send notification email: " . $e->getMessage());
    }
}

/**
 * 生成邮件内容
 * 
 * @param array $requester 失物招领者信息
 * @param array $cleanData 清洗后的输入数据
 * @param int $clueId 线索ID
 * @return string 邮件HTML内容
 */
function getEmailContent($requester, $cleanData, $clueId) {
    global $clue_url, $email_account;
    
    // 准备邮件数据
    $studentName = htmlspecialchars($requester['student_name'], ENT_QUOTES, 'UTF-8');
    $createdAt = htmlspecialchars($requester['created_at'], ENT_QUOTES, 'UTF-8');
    $campus = htmlspecialchars($cleanData['campus'], ENT_QUOTES, 'UTF-8');
    $detail = nl2br(htmlspecialchars($cleanData['detail'], ENT_QUOTES, 'UTF-8'));
    $location = htmlspecialchars($cleanData['location'], ENT_QUOTES, 'UTF-8');
    $contact = htmlspecialchars($cleanData['contact'], ENT_QUOTES, 'UTF-8');
    
    // 使用输出缓冲来构建复杂的HTML内容
    ob_start();
    ?>
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #2196F3; text-align: center;">Clue Submission Notification</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear <strong><?= $studentName ?></strong>,<br><br>
                A possible related clue was submitted to your request that was uploaded at <strong><?= $createdAt ?></strong>. Below are the details of the clue:
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Campus:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= convertCampus($campus) ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Detail:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= $detail ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Location:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= $location ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Contact:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?= $contact ?></td>
                </tr>
            </table>
            <p style="font-size: 14px; color: #888; margin-top: 20px;">
                You can click <a href="<?= $clue_url.$cleanData['lost_info_id'] ?>">this link</a> to view more infos and photos about this clue. If you have any questions, please contact us.
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
