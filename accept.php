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
    
handleRequest();

function update_request($pdo, $id, $op_email) {
    global $email_server, $email_account, $email_passwd, $domain_emaddr;

    try {
        $get_email_stmt = $pdo->prepare("SELECT email, room, reason, addTime, time FROM requests WHERE id = :id");
        $get_email_stmt->bindParam(':id', $id);
        $get_email_stmt->execute();
        $receiver = $get_email_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receiver) {
            throw new Exception("No record found.");
        }

        $accept_stmt = $pdo->prepare("UPDATE requests SET auth = 'yes', operator=:op WHERE id = :id");
        $accept_stmt->bindParam(':op', $op_email);
        $accept_stmt->bindParam(':id', $id);
        $accept_stmt->execute();

        if ($accept_stmt->rowCount() == 0) {
            throw new Exception("No rows updated.");
        }

        operatorLogger($pdo, $id, $op_email, 'accept','accept');

        $room_name = convertRoom($receiver['room']);
        $time_range = explode('-', $receiver['time']);
        $start_time = date('Y-m-d H:i', $time_range[0] / 1000);
        $end_time = date('Y-m-d H:i', $time_range[1] / 1000);

        $mail = initializeMailer();
        $mail->addAddress($receiver['email']);
        $mail->isHTML(true);
        $mail->Subject = 'Classroom Booking Application Approved';
        $mail->Body = '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: #4CAF50; text-align: center;">Classroom Booking Application Approved</h2>
            <p style="font-size: 16px; line-height: 1.5;">
                Dear User, your classroom booking application submitted on <strong>' . date('Y-m-d H:i:s', strtotime($receiver['addTime'])) . '</strong> has been approved.
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
                Your application has been successfully approved. Thank you for using the HFI-UC platform! If you have any questions, please feel free to contact us.
            </p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $email_account . '" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>';
        $mail->send();

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Email has sent successfully.']);
    } catch (Exception $e) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Failed to send email.']);
    }
}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $token = $_POST['token'];
    $key = base64_decode($base64Key);
    $email = opensslDecrypt($token, $key);
    if (!isAdmin($email)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $id = sanitizeInput($_POST['Id']);
    update_request($pdo, $id, $email);
}
?>
