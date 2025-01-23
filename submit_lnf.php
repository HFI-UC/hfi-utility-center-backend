<?php
// submit_lnf.php

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
    echo json_encode(['message' => 'Method Not Allowed']);
    exit;
}

// 定义需要的字段
// type为lost时是丢失物品发布悬赏，为found时是失物寻找失主
$requiredFields = ['student_name', 'detail', 'location', 'email', 'campus', 'file_path', 'password', 'type', 'event_time'];
$optionalFields = ['reward', 'alt_contact'];

// 初始化一个数组来存储清洗后的数据
$cleanData = [];

// 验证并清洗输入数据
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
        http_response_code(422); // Unprocessable Entity
        echo json_encode(['message' => "Missing required field: $field."]);
        exit;
    }
    // 清洗数据
    $cleanData[$field] = trim($_POST[$field]);
}
//清洗可选字段
foreach ($optionalFields as $field) {
    if (isset($_POST[$field]) && !empty(trim($_POST[$field]))) {
        $cleanData[$field] = trim($_POST[$field]);
    } else {
        // Set to null or a default value if necessary
        $cleanData[$field] = null;
    }
}
// 验证邮箱格式
if (!filter_var($cleanData['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['message' => 'Invalid email format.']);
    exit;
}

// 对密码进行哈希处理
$hashedPassword = password_hash($cleanData['password'], PASSWORD_DEFAULT);

try {
    // 准备 SQL 插入语句
    $sql = "INSERT INTO lost_and_found (type, student_name, detail, location, email, campus, file_path, reward, alt_contact, password, created_at, last_updated, event_time) 
            VALUES (:type, :student_name, :detail, :location, :email, :campus, :file_path, :reward, :alt_contact, :password, NOW(), NOW(), :event_time)";

    // 预处理 SQL
    $stmt = $pdo->prepare($sql);

    // 绑定参数并执行
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
    $stmt->bindParam(':event_time',$cleanData['event_time']);

    $stmt->execute();
    $addId = $pdo->lastInsertId();
    lf_logger($pdo, $addId, 'add', '');

    // 发送确认邮件
    try {
        $mail = initializeMailer();
        $recipientEmail = $cleanData['email'];
        $student_name = $cleanData['student_name'];
        $addTime = date('Y-m-d H:i:s'); // 或者从数据库获取 `created_at` 字段

        // 根据类型设置标题
        $type = ucfirst($cleanData['type']); // 'Lost' 或 'Found'

        $mail->addAddress($recipientEmail);
        $mail->Subject = 'Lost and Found Request Submitted';
        $mail->isHTML(true);
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
                <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
                    <h2 style="color: #4CAF50; text-align: center;">Lost and Found Request Submitted</h2>
                    <p style="font-size: 16px; line-height: 1.5;">
                        Dear <strong>' . htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') . '</strong>,<br><br>
                        Your ' . strtolower($type) . ' request submitted on <strong>' . htmlspecialchars($addTime, ENT_QUOTES, 'UTF-8') . '</strong> has been received successfully.
                    </p>
                    <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Type:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Request Id:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($addId, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Detail:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . nl2br(htmlspecialchars($cleanData['detail'], ENT_QUOTES, 'UTF-8')) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Location:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($cleanData['location'], ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Campus:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars(convertCampus($cleanData['campus']), ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Reward:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . (!empty($cleanData['reward']) ? htmlspecialchars($cleanData['reward'], ENT_QUOTES, 'UTF-8') : 'N/A') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Passphrase:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd; color: red">' . htmlspecialchars($cleanData['password'], ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Alternate Contact:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . (!empty($cleanData['alt_contact']) ? htmlspecialchars($cleanData['alt_contact'], ENT_QUOTES, 'UTF-8') : 'N/A') . '</td>
                        </tr>
                    </table>
                    <p style="font-size: 14px; color: #888; margin-top: 20px;">
                        We will notify you once we find a match for your request. You can also check your request status at <a href="'.$request_url.$addId.'">here.</a> If you have any questions, please contact us.
                    </p>
                        <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $email_account . '" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
                </div>
            </div>';
        
        $mail->send();
    } catch (Exception $e) {
        // 如果邮件发送失败，记录错误但不影响主流程
        logException($e);
    }
    // 返回成功响应
    http_response_code(201);
    echo json_encode(['success'=> true, 'message' => 'Your L&F entry ID is '.$addId.', please take good care of it.']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Failed to submit entry.']);
}
?>