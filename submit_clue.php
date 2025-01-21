<?php
// submit_clue.php

require_once 'db.php'; // 数据库连接
require_once 'global_variables.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 允许跨域请求（根据需要调整）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
try {
// 检查请求方法是否为 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method Not Allowed']);
    exit;
}

// 定义需要的字段
$requiredFields = ['campus', 'detail', 'location', 'filePath', 'contact', 'lost_info_id'];

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

// 验证联系信息（邮箱、微信、QQ）
// 根据需要自定义验证规则
// 例如，如果联系信息是邮箱，可以取消下面的注释
/*
if (!filter_var($cleanData['contact'], FILTER_VALIDATE_EMAIL)) {
    // 如果不是有效的邮箱，继续检查是否为微信或 QQ（可以根据需要自定义验证规则）
    if (!preg_match('/^[a-zA-Z0-9_]{5,}$/', $cleanData['contact'])) {
        http_response_code(422);
        echo json_encode(['message' => 'Invalid contact information.']);
        exit;
    }
}
*/

// 获取当前时间
$addTime = date('Y-m-d H:i:s');

try {
    // 准备 SQL 插入语句
    $sql = "INSERT INTO volunteer_clues (campus, detail, location, file_path, contact, lost_info_id, created_at) 
            VALUES (:campus, :detail, :location, :filePath, :contact, :lost_info_id, :created_at)";

    // 预处理 SQL
    $stmt = $pdo->prepare($sql);

    // 绑定参数并执行
    $stmt->bindParam(':campus', $cleanData['campus']);
    $stmt->bindParam(':detail', $cleanData['detail']);
    $stmt->bindParam(':location', $cleanData['location']);
    $stmt->bindParam(':filePath', $cleanData['filePath']);
    $stmt->bindParam(':contact', $cleanData['contact']);
    $stmt->bindParam(':lost_info_id', $cleanData['lost_info_id']);
    $stmt->bindParam(':created_at', $addTime);
    
    $stmt->execute();
    $clueId = $pdo->lastInsertId();
    lf_logger($pdo, $cleanData['lost_info_id'], 'clue submission', $clueId);
    
    $sql="UPDATE lost_and_found SET last_updated = NOW(), is_found = 2 WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id',$cleanData['lost_info_id']);
    $stmt->execute();
    lf_logger($pdo,$cleanData['lost_info_id'],'clue submitted, status updated to 2',$clueId);
    $sql = "SELECT created_at, student_name, email FROM lost_and_found WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id',$cleanData['lost_info_id']);
    $stmt->execute();
    $recipient=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // 发送确认邮件
    try {
        $mail = initializeMailer();
        $recipientEmail = $recipient[0]['email']; 
        $campus = htmlspecialchars($cleanData['campus'], ENT_QUOTES, 'UTF-8');
        $detail = nl2br(htmlspecialchars($cleanData['detail'], ENT_QUOTES, 'UTF-8'));
        $location = htmlspecialchars($cleanData['location'], ENT_QUOTES, 'UTF-8');
        $filePath = htmlspecialchars($cleanData['filePath'], ENT_QUOTES, 'UTF-8');
        $clueId = htmlspecialchars($clueId, ENT_QUOTES, 'UTF-8');
        $addTimeEscaped = htmlspecialchars($addTime, ENT_QUOTES, 'UTF-8');
        
        $mail->addAddress($recipientEmail);
        $mail->Subject = 'Volunteer Clue Submission';
        $mail->isHTML(true);
        $mail->Body = '
            <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
                <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
                    <h2 style="color: #2196F3; text-align: center;">Clue Submission Notification</h2>
                    <p style="font-size: 16px; line-height: 1.5;">
                        Dear <strong>' . htmlspecialchars($recipient[0]['student_name'], ENT_QUOTES, 'UTF-8') . '</strong>,<br><br>
                        A possible related clue was submitted to your request that was uploaded at <strong>' . $recipient[0]['created_at'] . '</strong>. Below are the details of the clue :
                    </p>
                    <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Campus:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . convertCampus($campus) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Detail:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . $detail . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Location:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . $location . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f1f1f1;"><strong>Contact:</strong></td>
                            <td style="padding: 10px; border: 1px solid #ddd;">' . $cleanData['contact'] . '</td>
                        </tr>
                    </table>
                    <p style="font-size: 14px; color: #888; margin-top: 20px;">
                        You can click <a href="'.$clue_url.$cleanData['lost_info_id'].'">this link</a> to view more infos and photos about this clue. If you have any questions, please contact us.
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
        // 您可以选择在这里返回一个警告，但不改变主流程
        http_response_code(201);
        echo json_encode(['success'=> true,'message' => 'Clue submitted successfully, unable to email the requester.']);
    }
    // 返回成功响应
    http_response_code(201);
    echo json_encode(['success'=> true,'message' => 'Clue submitted successfully.']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Failed to submit clue.']);
    // 记录错误日志，而不是将错误信息返回给用户
}
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Error: ' . $e->getMessage()]);
}
?>