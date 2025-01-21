<?php
// process_repair.php
require_once 'global_variables.php'; 
require 'db.php';
require 'admin_emails.php';
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');

// 允许跨域请求（根据需要调整）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// 检查请求方法是否为 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST method is allowed.']);
    exit;
}

// 初始化变量
$id = $token = $action = null;

// 确定内容类型
$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

if (strpos($contentType, 'application/json') !== false) {
    // 处理 JSON 输入
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        exit;
    }

    $id = isset($data['id']) ? intval($data['id']) : null;
    $token = isset($data['token']) ? trim($data['token']) : null;
    $action = isset($data['action']) ? intval($data['action']) : null;
} else {
    // 处理表单数据输入
    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $token = isset($_POST['token']) ? trim($_POST['token']) : null;
    $action = isset($_POST['action']) ? intval($_POST['action']) : null;
}

// 验证 token
    $key = base64_decode($base64Key);
    $email = opensslDecrypt($token, $key);
    if (!isAdmin($email)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    
// 验证输入数据
if ($id === null || empty($token) || $action === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: id, token, or action.']);
    exit;
}

// 验证 action
$validActions = [1, 2, 3];
if (!in_array($action, $validActions)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action provided.']);
    exit;
}

// 获取报修申请信息
try {
    $sql = "SELECT * FROM repair_requests WHERE id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode(['error' => 'Repair request not found.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An internal server error occurred.']);
    exit;
}

// 更新报修申请的状态
$statusUpdate = $action; // 使用 action 值作为 status

try {
    $sql = "UPDATE repair_requests SET status = :status WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':status', $statusUpdate, PDO::PARAM_INT);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An internal server error occurred.'.$e->getMessage()]);
    exit;
}

// 发送邮件通知用户
$toEmail = $request['email'];
$studentName = $request['studentName'];

// 初始化 PHPMailer
$mail = initializeMailer();

try {
    // 收件人
    $mail->addAddress($toEmail, $studentName); // 添加收件人

    // 内容设置
    $mail->isHTML(true); // 设置邮件格式为 HTML

    // 根据 action 设置邮件主题、主题色和内容
    if ($action === 1) { // 已完成
        $mail->Subject = 'Repair Request Completed';
        $themeColor = '#4CAF50'; // 绿色
        $statusText = 'Your repair request has been completed.';
    } elseif ($action === 2) { // 已打回
        $mail->Subject = 'Repair Request Returned';
        $themeColor = '#F44336'; // 红色
        $statusText = 'Your repair request has been returned. Please provide accurate information and resubmit.';
    } elseif ($action === 3) { // 重复申请
        $mail->Subject = 'Duplicate Repair Request';
        $themeColor = '#FF9800'; // 橙色
        $statusText = 'Your repair request is a duplicate. Please do not submit multiple requests for the same issue.';
    }

    // 邮件内容
    $mail->Body = '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
        <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
            <h2 style="color: ' . $themeColor . '; text-align: center;">' . htmlspecialchars($mail->Subject, ENT_QUOTES, 'UTF-8') . '</h2>
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
            <p style="font-size: 14px; color: #888; margin-top: 20px;">';

    if ($action === 1) { // 已完成
        $mail->Body .= 'Thank you for using our services! If you have any questions, please feel free to contact us.';
    } elseif ($action === 2) { // 已打回
        $mail->Body .= 'Please provide accurate information and resubmit your request. If you have any questions, feel free to contact us.';
    } elseif ($action === 3) { // 重复申请
        $mail->Body .= 'Please note that we are already processing a previous request that report the same incident. If you have additional information, please contact us directly instead of submitting a new request.';
    }

    $mail->Body .= '</p>
            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $domain_emaddr . '" style="background-color: ' . $themeColor . '; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
        </div>
    </div>';

    // 发送邮件
    $mail->send();

    // 成功响应
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Repair request updated and email sent successfully.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send email: ' . $mail->ErrorInfo]);
}
?>
