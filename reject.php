<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require 'global_variables.php';
require 'db.php'; 
require 'admin_emails.php';
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function update_request($pdo, $id, $op_email,$reason) {
    global $email_server, $email_account, $email_passwd, $domain_emaddr;
    switch($reason){
    case 1:
        $reason = '选择的时间段已被其他活动预订。'; // 时间冲突
        break;
    case 2:
        $reason = '当前没有足够的设备或资源支持此次预约。'; // 资源不足
        break;
    case 3:
        $reason = '预约请求不符合课室使用的特定条件或规则。'; // 不符合预约条件
        break;
    case 4:
        $reason = '课室正在进行维修或升级，暂时无法使用。'; // 维修中
        break;
    case 5:
        $reason = '出于安全考虑，暂时无法批准使用。'; // 安全问题
        break;
    case 6:
        $reason = '提交的预约信息不完整或不准确。'; // 信息不完整
        break;
    case 7:
        $reason = '预约的活动违反了学校或机构的相关政策。'; // 违反政策
        break;
    case 8:
        $reason = '同一组织或个人的预约频率过高。'; // 预约过于频繁
        break;
    case 9:
        $reason = '由于特殊活动或紧急情况，优先安排资源。'; // 特殊活动优先
        break;
    default:
        $reason = 'Invalid reason code received: ' . $reason;
}

    try {
        $get_email_stmt = $pdo->prepare("SELECT email, room, reason, addTime, time FROM requests WHERE id = :id");
        $get_email_stmt->bindParam(':id', $id);
        $get_email_stmt->execute();
        $receiver = $get_email_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receiver) {
            throw new Exception("No record found.");
        }

        $accept_stmt = $pdo->prepare("UPDATE requests SET auth = 'no', operator=:op WHERE id = :id");
        $accept_stmt->bindParam(':op', $op_email);
        $accept_stmt->bindParam(':id', $id);
        $accept_stmt->execute();

        if ($accept_stmt->rowCount() == 0) {
            throw new Exception("No rows updated.");
        }
        operatorLogger($pdo, $id, $op_email, 'reject', $reason);
        $time_range = explode('-', $receiver['time']);
        $start_time = date('Y-m-d H:i', $time_range[0] / 1000);
        $end_time = date('Y-m-d H:i', $time_range[1] / 1000);
        $room_name = convertRoom($receiver['room']);
        $mail = initializeMailer();
        $mail->addAddress($receiver['email']);
        $mail->isHTML(true);
        $mail->Subject = 'Classroom Booking Application Rejected';
        $mail->Body = '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #F44336; text-align: center;">Classroom Booking Application Rejected</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear User, your classroom booking application submitted on <strong>' . date('Y-m-d H:i:s', strtotime($receiver['addTime'])) . '</strong> has not been approved.
            </p>
            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Classroom:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $room_name . '</td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Reason for Booking:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;">' . $receiver['reason'] . '</td>
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
        $mail->send();

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => '邮件已发送。']);
    } catch (Exception $e) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => '未能发送邮件，Error: ' . $e->getMessage()]);
    }
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $token = $_POST['token'];
    $key = base64_decode($base64Key);
    $email = opensslDecrypt($token, $key);
    if (!isAdmin($email)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $id = sanitizeInput($_POST['Id']);
    $reason = intval(sanitizeInput($_POST['Reason']));
    update_request($pdo, $id, $email, $reason);
}
?>
