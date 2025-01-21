<?php
require 'db.php';
require 'global_variables.php';
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Shanghai');

header('Content-Type: application/json');


handleRequest();

// 获取 POST 数据
$token = $_POST['token'] ?? '';
$action = $_POST['action'] ?? '';

// 检查是否提供了 token 和 action
if (empty($token) || empty($action)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token and action are required.']);
    exit;
}

$key = base64_decode($base64Key);
$decryptedData = opensslDecrypt($token, $key);

if (!$decryptedData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid token.']);
    exit;
}

// 解析解密后的数据
list($email, $id, $timestamp) = explode('|', $decryptedData);

/*
// 检查令牌是否过期（有效期为1周）
if (time() - $timestamp > 604800) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token has expired.']);
    exit;
}
*/
// 验证邮箱格式
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid token data.']);
    exit;
}

// 检查请求是否存在以及当前状态
$checkStatus = $pdo->prepare("SELECT auth, email, room, reason, addTime, time FROM requests WHERE id = :id");
$checkStatus->bindParam(':id', $id, PDO::PARAM_INT);
$checkStatus->execute();
$request = $checkStatus->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Request not found.']);
    exit;
}

if ($request['auth'] !== 'non') {
    $statusText = ($request['auth'] === 'yes') ? 'accepted' : 'rejected';
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => "The request has already been $statusText."]);
    exit;
}

// 检查令牌是否已被使用
$stmt = $pdo->prepare("SELECT used FROM used_tokens WHERE token = :token");
$stmt->bindParam(':token', $token);
$stmt->execute();
$tokenStatus = $stmt->fetch(PDO::FETCH_ASSOC);

if ($tokenStatus && $tokenStatus['used']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Token has already been used.']);
    exit;
}

try {
    //$pdo->beginTransaction();

    // 更新请求状态
    $status = ($action === 'approve') ? 'yes' : 'no';
    $updateStmt = $pdo->prepare("UPDATE requests SET auth = :status, operator=:op WHERE id = :id");
    $updateStmt->bindParam(':status', $status);
    $updateStmt->bindParam(':op',$email);
    $updateStmt->bindParam(':id', $id, PDO::PARAM_INT);
    $updateStmt->execute();

    if ($updateStmt->rowCount() == 0) {
        throw new Exception("Failed to update request status.");
    }

    // 标记令牌为已使用
    $usedTime = date('Y-m-d H:i:s');
    $updateTokenStmt = $pdo->prepare("UPDATE used_tokens SET used = TRUE, used_time = :usedTime WHERE token = :token");
    $updateTokenStmt->bindParam(':usedTime', $usedTime);
    $updateTokenStmt->bindParam(':token', $token);
    $updateTokenStmt->execute();

    // 记录操作日志
    operatorLogger($pdo, $id, $email, ($action === 'approve') ? 'accept' : 'reject', 'Token-based approval');
    $time_range = explode('-', $request['time']);
    $start_time = date('Y-m-d H:i', $time_range[0] / 1000);
    $end_time = date('Y-m-d H:i', $time_range[1] / 1000);
    //$pdo->commit();

    // 发送邮件给用户
    $room_name = convertRoom($request['room']);
    $mail = initializeMailer();
    $mail->addAddress($request['email']);
    $mail->isHTML(true);

    $addTimeFormatted = date('Y-m-d H:i:s', strtotime($request['addTime']));

    if ($action === 'approve') {
    $mail->Subject = 'Classroom Booking Application Approved';
    $mail->Body = '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #4CAF50; text-align: center;">Classroom Booking Application Approved</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear User, your classroom booking application submitted on <strong>' . date('Y-m-d H:i:s', strtotime($request['addTime'])) . '</strong> has been approved.
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Classroom:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $room_name . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Reason for Booking:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $request['reason'] . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Booking Time:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $start_time . ' - ' . $end_time . '</td>
                </tr>
            </table>
            <p style="font-size: 14px; color: #888; margin-top: 20px;">
                Your application has been successfully approved. Thank you for using the HFI-UC platform! If you have any questions, please feel free to contact us.
            </p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $email_account . '" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>';
} else {
    $mail->Subject = 'Classroom Booking Application Rejected';
    $mail->Body = '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #F44336; text-align: center;">Classroom Booking Application Rejected</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear User, your classroom booking application submitted on <strong>' . date('Y-m-d H:i:s', strtotime($request['addTime'])) . '</strong> has not been approved.
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Classroom:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $room_name . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Reason for Booking:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $request['reason'] . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Booking Time:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $start_time . ' - ' . $end_time . '</td>
                </tr>
            </table>
            <p style="font-size: 14px; color: #888; margin-top: 20px;">
                If you have any questions or need assistance, please contact us. You can resubmit your application or modify your booking request.
            </p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $email_account . '" style="background-color: #F44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>';
}



    $mail->send();
    http_response_code(200);
    echo json_encode([
        'success' => true, 
        'data' => $request
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    // 记录错误日志，防止敏感信息泄露
    error_log('Error processing request: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request.']);
}
?>