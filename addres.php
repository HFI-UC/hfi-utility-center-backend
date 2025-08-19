<?php
//addres.php
//这个逻辑太屎了 我用GPT生成的注释 刚好能锻炼阅读能力(逃

header('Content-Type: application/json');
require 'global_variables.php';
require 'db.php';
require 'priv_emails.php';
date_default_timezone_set('Asia/Shanghai');

require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Initialize variables
$isPriv = false;
$rejectCnt = 0;
$key = base64_decode($base64Key);
$able_to_send_email=1;

function checkFixedUnavailableTimes(PDO $pdo, $room, int $requestStartTimeMillis, int $requestEndTimeMillis): array {
    try {
        // 1. 获取请求的星期几 (1=Mon, 7=Sun) 和 时:分 (用于比较)
        $requestStartTimeSec = $requestStartTimeMillis / 1000;
        $requestEndTimeSec = $requestEndTimeMillis / 1000;
        $requestDayOfWeek = date('N', $requestStartTimeSec);
        // 将请求时间转换为当天基于0点的秒数或使用 strtotime('H:i') 得到的可比较时间戳
        $requestStartHM = strtotime(date('H:i', $requestStartTimeSec));
        $requestEndHM = strtotime(date('H:i', $requestEndTimeSec));
        // 如果结束时间 H:i 小于开始时间 H:i，说明跨天了（对于教室规则通常不跨天，但以防万一）
        // if ($requestEndHM < $requestStartHM) $requestEndHM += 86400; // 加一天秒数

        // 2. 从数据库获取该教室的不可用规则
        $stmt = $pdo->prepare("SELECT days, start_time, end_time FROM classrooms WHERE classroom = :room AND unavailable = 1");
        $stmt->bindParam(':room', $room);
        $stmt->execute();
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. 遍历规则进行检查
        foreach ($rules as $rule) {
            // 检查星期几是否匹配
            $ruleDays = explode(',', $rule['days']); // 假设 'days' 是逗号分隔的数字字符串，如 "1,4"
            if (in_array($requestDayOfWeek, $ruleDays)) {
                // 星期几匹配，比较时间段 (仅比较 H:i)
                $ruleStartHM = strtotime($rule['start_time']); // e.g., strtotime("15:20")
                $ruleEndHM = strtotime($rule['end_time']);     // e.g., strtotime("17:20")
                // 处理规则跨天 (如果需要)
                // if ($ruleEndHM < $ruleStartHM) $ruleEndHM += 86400;

                // 核心重叠逻辑: (请求开始 < 规则结束) AND (请求结束 > 规则开始)
                if ($requestStartHM < $ruleEndHM && $requestEndHM > $ruleStartHM) {
                    // 发现冲突
                    $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    $dayName = $dayNames[$requestDayOfWeek - 1];
                    $roomName = convertRoom($room); // 获取房间名
                    $message = "Booking conflict: Room {$roomName} is unavailable on {$dayName}s between {$rule['start_time']} and {$rule['end_time']}. Your request from " . date('H:i', $requestStartTimeSec) . " to " . date('H:i', $requestEndTimeSec) . " overlaps.";
                    return ['available' => false, 'message' => $message];
                }
            }
        }

        // 4. 如果循环结束都没有找到冲突
        return ['available' => true, 'message' => ''];

    } catch (PDOException $e) {
        logException($e);
        // 如果检查出错，保守起见可以认为不可用，或者抛出异常让上层处理
        return ['available' => false, 'message' => 'Could not verify room availability due to a database error. Please try again.'];
        // 或者 throw $e;
    } catch (Exception $e) {
        logException($e);
        return ['available' => false, 'message' => 'Could not verify room availability due to an unexpected error.'];
        // 或者 throw $e;
    }
}

// Function to check if a user is a privileger
function isPrivileger($pdo, $email) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM privilegers WHERE email = :email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    return $stmt->fetchColumn() > 0;
}

// Function for AES encryption
function opensslEncrypt($data, $key) {
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

// Function to generate a one-time token
function generateOneTimeToken($email, $requestId, $key) {
    $data = $email . '|' . $requestId . '|' . time();
    return opensslEncrypt($data, $key);
}

// Function to reject conflicting requests for privileger users
function rejectConflictingRequests($pdo, $startTime, $endTime, $room, $privilegedEmail) {
    global $rejectCnt;
    // Optimize SQL query by avoiding SUBSTRING_INDEX and using BETWEEN
    $sql = "SELECT * FROM requests WHERE room = :room AND auth != 'no' AND (
        (:startTime < SUBSTRING_INDEX(time, '-', -1) AND :endTime > SUBSTRING_INDEX(time, '-', 1))
    ) AND email != :email";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':room', $room);
    $stmt->bindParam(':startTime', $startTime);
    $stmt->bindParam(':endTime', $endTime);
    $stmt->bindParam(':email', $privilegedEmail);
    $stmt->execute();
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($conflicts as $conflict) {
        $updateStmt = $pdo->prepare("UPDATE requests SET auth = 'no' WHERE id = :id");
        $updateStmt->bindParam(':id', $conflict['id']);
        $updateStmt->execute();
        operatorLogger($pdo, $conflict['id'], $privilegedEmail, 'reject', 'Conflicted with a higher priority booking.');
        sendRejectionEmail($conflict['email'], $conflict['room'], $conflict['addTime']);
        $rejectCnt++;
    }
}

// Function to send rejection email
function sendRejectionEmail($recipientEmail, $room, $addTime) {
    global $email_account;
    $mail = initializeMailer();
    $room_name = convertRoom($room);
    $mail->addAddress($recipientEmail);
    $mail->Subject = 'Room Booking Request Rejected';
    $mail->isHTML(true);
    $mail->Body = '
        <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
                <h2 style="color: #F44336; text-align: center;">Room Booking Request Rejected</h2>
                <p style="font-size: 16px; line-height: 1.5;">
                    Your room booking request submitted on <strong>' . date('Y-m-d H:i:s', strtotime($addTime)) . '</strong> has been rejected due to a conflict with a higher priority booking.
                </p>
                <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Classroom:</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd;">' . $room_name . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Rejection reason:</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd;">Conflicts with a higher priority booking.</td>
                    </tr>
                </table>
                <p style="font-size: 14px; color: #888; margin-top: 20px;">
                    You may adjust your request and submit it again. If you have any questions, please contact us.
                </p>
                            <div style="text-align: center; margin-top: 30px;">
                <a href="mailto:' . $email_account . '" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Contact Us</a>
            </div>
            </div>
        </div>';
    $mail->send();
}

// Function to send approval request email
function sendApprovalRequestEmail($teacher_email, $room, $user_email, $time, $name, $reason, $requestId) {
    global $pdo, $key, $email_account, $base_url;
    $mail = initializeMailer();
    $mail->addAddress($teacher_email);

    list($start_time, $end_time) = explode('-', $time);
    $start_time_human = date('Y-m-d H:i:s', $start_time / 1000);
    $end_time_human = date('Y-m-d H:i:s', $end_time / 1000);

    $unencode_token = generateOneTimeToken($teacher_email, $requestId, $key);
    $token = urlencode($unencode_token);

    $stmt = $pdo->prepare("INSERT INTO used_tokens (token, used) VALUES (:token, FALSE) ON DUPLICATE KEY UPDATE token = token");
    $stmt->bindParam(':token', $unencode_token);
    $stmt->execute();

    $approve_link = "{$base_url}?token={$token}&action=approve";
    $reject_link = "{$base_url}?token={$token}&action=reject";

    $room_name = convertRoom($room);
    $mail->Subject = 'Approval Needed for Room Booking';
    $mail->isHTML(true);
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; font-size: 14px; color: #333;'>
        <div style='background-color: #f5f5f5; padding: 20px; border: 1px solid #ddd;'>
            <h2 style='color: #4CAF50;'>Room Booking Approval Request</h2>
            <p><strong>Room:</strong> $room_name</p>
            <p><strong>Requested By:</strong> $user_email</p>
            <p><strong>Name:</strong> $name</p>
            <p><strong>Start Time:</strong> $start_time_human</p>
            <p><strong>End Time:</strong> $end_time_human</p>
            <p><strong>Reason:</strong> $reason</p>
        </div>

        <div style='margin-top: 20px;'>
            <p style='font-size: 16px;'>Please review the request:</p>
            <a href='$approve_link' style='background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Approve</a>
            &nbsp;&nbsp;&nbsp;
            <a href='$reject_link' style='background-color: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Reject</a>
        </div>

        <div style='text-align: center; margin-top: 30px;'>
                <a href='mailto:" . $email_account . "' style='background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Contact Us</a>
            </div>
    </div>";
    $mail->send();
}

// Get and validate input data
$room = filter_input(INPUT_POST, 'room', FILTER_SANITIZE_NUMBER_INT);
$sid = filter_input(INPUT_POST, 'sid', FILTER_SANITIZE_NUMBER_INT);
$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$clientTime = filter_input(INPUT_POST, 'time', FILTER_SANITIZE_STRING);
$name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
$reason = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_STRING);
$addTime_YMDHMS = date('Y-m-d H:i:s');

// 初始化字段和对应的变量
$fields = [
    'room' => $room,
    'Student ID' => $sid,
    'email' => $email,
    'time' => $clientTime,
    'name' => $name,
    'reason' => $reason
];

// 使用数组过滤器来检查哪些字段为空
$missingFields = array_keys(array_filter($fields, function($value) {
    return empty($value);
}));


// 如果有缺失的字段，则返回 400 错误，并列出具体缺失的字段
if (!empty($missingFields)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'The following fields are required and cannot be empty: ' . implode(', ', $missingFields) . '.'
    ]);
    exit;
}

list($startTime, $endTime) = explode('-', $clientTime);

if (!is_numeric($startTime) || !is_numeric($endTime)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
    exit;
}

if ($startTime >= $endTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Start time cannot be later than or equal to end time.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Check for restricted rooms during cleaning time
$userStartTime = strtotime(date('H:i', $startTime / 1000));
$userEndTime = strtotime(date('H:i', $endTime / 1000));
if (in_array($room, $restrictedRooms) && !($userEndTime <= $cleaningStartTime || $userStartTime >= $cleaningEndTime)) {
    $room_name = convertRoom($room);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => "{$room_name} is unavailable between 12:00-13:00 for cleaning."]);
    exit;
}

$availabilityCheck = checkFixedUnavailableTimes($pdo, $room, $startTime, $endTime);
    if (!$availabilityCheck['available']) {
        // 如果检查失败（无论是冲突还是错误），都阻止预约
        http_response_code(409); // Conflict or error during check
        echo json_encode(['success' => false, 'message' => $availabilityCheck['message']]);
        exit;
    }

// Check if the user is a privileger
if (isPriv($email)) {
    rejectConflictingRequests($pdo, $startTime, $endTime, $room, $email);
    $isPriv = true;
}

try {
    if (!$isPriv) {
        $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
        // Start the transaction and set isolation level to READ COMMITTED
        $pdo->beginTransaction();
    
        // Lock existing bookings for the room during the desired time period
        $checkSql = "SELECT id FROM requests WHERE room = :room AND auth != 'no' AND (
            :startTime < SUBSTRING_INDEX(time, '-', -1) AND :endTime > SUBSTRING_INDEX(time, '-', 1)
        ) FOR UPDATE";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindParam(':startTime', $startTime);
        $checkStmt->bindParam(':endTime', $endTime);
        $checkStmt->bindParam(':room', $room);
        $checkStmt->execute();
    
        if ($checkStmt->rowCount() > 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Time conflict detected. Your request cannot be processed.']);
            $able_to_send_email=false;
            exit;
        }
    }
    if(!$able_to_send_email){
        exit;//不知道为什么检测到撞车后还是会发邮件 所以设了个布尔类型强行判断((
    }
    if (!$pdo->inTransaction()){
        $pdo->beginTransaction();
    }
    // Insert the new booking request
    $insertSql = "INSERT INTO requests (room, email, auth, time, name, reason, sid, addTime) VALUES (:room, :email, :auth, :time, :name, :reason, :sid, :addTime)";
    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->bindParam(':room', $room);
    $insertStmt->bindParam(':email', $email);
    $insertStmt->bindValue(':auth', $isPriv ? 'yes' : 'non');
    $insertStmt->bindParam(':time', $clientTime);
    $insertStmt->bindParam(':name', $name);
    $insertStmt->bindParam(':reason', $reason);
    $insertStmt->bindParam(':sid', $sid);
    $insertStmt->bindParam(':addTime', $addTime_YMDHMS);
    $insertStmt->execute();

    $requestId = $pdo->lastInsertId();
    $pdo->commit();

    if ($isPriv) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => "Your booking has been approved. {$rejectCnt} conflicting requests were rejected."]);
        exit;
    } else {
        // Send approval request emails
        $stmt = $pdo->prepare("SELECT email FROM managed_rooms WHERE room = :room");
        $stmt->bindParam(':room', $room);
        $stmt->execute();
        $teacher_emails = $stmt->fetchColumn();

        if ($teacher_emails) {
            $teacherEmails = explode(',', $teacher_emails);
            foreach ($teacherEmails as $teacher_email) {
                sendApprovalRequestEmail($teacher_email, $room, $email, $clientTime, $name, $reason, $requestId);
            }
        }
    }
    
    // Send confirmation email to the user
    $mail = initializeMailer();
    $room_name = convertRoom($room);
    $mail->addAddress($email);
    $mail->Subject = 'Room Booking Request Submitted';
    $mail->isHTML(true);

    // Convert the time period to human-readable format
    $start_time_human = date('Y-m-d H:i:s', $startTime / 1000);
    $end_time_human = date('Y-m-d H:i:s', $endTime / 1000);
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;'>
<div style='font-family: Arial, sans-serif; font-size: 14px; color: #333;'>
    <div style='background-color: #f5f5f5; padding: 20px; border: 1px solid #ddd;'>
        <h2 style='color: #4CAF50;'>Room Booking Request Submitted Successfully</h2>
        <p>Your booking details are as follows:</p>
        <table style='border-collapse: collapse; width: 100%; margin-top: 10px;'>
            <tr>
                <th style='border: 1px solid #ddd; padding: 8px; background-color: #f2f2f2;'>Room</th>
                <th style='border: 1px solid #ddd; padding: 8px; background-color: #f2f2f2;'>Time Period</th>
                <th style='border: 1px solid #ddd; padding: 8px; background-color: #f2f2f2;'>Reason</th>
            </tr>
            <tr>
                <td style='border: 1px solid #ddd; padding: 8px;'>{$room_name}</td>
                <td style='border: 1px solid #ddd; padding: 8px;'>{$start_time_human} to {$end_time_human}</td>
                <td style='border: 1px solid #ddd; padding: 8px;'>{$reason}</td>
            </tr>
        </table>
    </div>

    <div style='margin-top: 20px;'>
        <p style='font-size: 14px;'>Your booking request has been added to the approval queue. You will be notified via email within three working days. You can also log in to our website to check the status of your booking.</p>
    </div>

            <div style='text-align: center; margin-top: 30px;'>
                <a href='mailto:" . $email_account . "' style='background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Contact Us</a>
            </div>
</div>
</div>
</div>";
    $mail->send();

    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Your booking request has been added to the approval queue.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request.']);
}
?>