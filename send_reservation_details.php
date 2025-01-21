<?php
require_once 'src/vendor/autoload.php';
require 'db.php'; // 数据库连接
require 'global_variables.php'; // 包含全局变量，例如 $email_server 等
require 'admin_emails.php'; // 包含 isAdmin() 函数
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';

// 引入 PhpSpreadsheet 库
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 使用命名空间
use PHPMailer\PHPMailer\PHPMailer;

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置默认字符编码为 UTF-8
mb_internal_encoding('UTF-8');

// 辅助函数
function formatTime($timeRange) {
    list($startTime, $endTime) = explode('-', $timeRange);
    $start_time_human = date('Y-m-d H:i:s', $startTime / 1000);
    $end_time_human = date('Y-m-d H:i:s', $endTime / 1000);
    return "$start_time_human - $end_time_human";
}

function formatAuth($auth) {
    $statusMap = [
        'yes' => 'Approved',
        'no'  => 'Rejected',
        'non' => 'Pending',
    ];
    return $statusMap[$auth] ?? $auth;
}

try {
    // 初始化进度数组
    $progress = [];

    // 获取第二天的日期范围
    $tomorrow = new DateTime('tomorrow');
    $tomorrow_start = $tomorrow->setTime(0, 0, 0)->getTimestamp();
    $tomorrow_end = $tomorrow->setTime(23, 59, 59)->getTimestamp();

    $progress[] = '已计算明天的日期范围。';

    // 查询第二天的预约
    $sql = "SELECT * FROM requests WHERE (SUBSTRING_INDEX(time, '-', 1) / 1000 BETWEEN :start AND :end) ORDER BY room";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start', $tomorrow_start, PDO::PARAM_INT);
    $stmt->bindValue(':end', $tomorrow_end, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $progress[] = '已从数据库获取明天的预约。';

    if (empty($reservations)) {
        echo json_encode(['success' => true, 'message' => 'No reservations for tomorrow.', 'progress' => $progress]);
        exit;
    }

    // 确保数据编码为 UTF-8，防止乱码
    array_walk_recursive($reservations, function (&$value) {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
    });

    $progress[] = '已检查并转换预约数据的编码。';

    // 按教室分类预约
    $reservationsByClassroom = [];
    foreach ($reservations as $reservation) {
        $room = $reservation['room'];
        $reservationsByClassroom[$room][] = $reservation;
    }

    $progress[] = '已按教室对预约进行分类。';

    // 生成 Excel 文件
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial Unicode MS')->setSize(11); // 设置字体支持中文
    $sheetIndex = 0;

    foreach ($reservationsByClassroom as $room => $roomReservations) {
        if ($sheetIndex > 0) {
            $spreadsheet->createSheet();
        }

        $spreadsheet->setActiveSheetIndex($sheetIndex);
        $sheet = $spreadsheet->getActiveSheet();
        $converted_room = convertRoom($room);
        if ($converted_room == $room) {
            $sheet->setTitle("Room $converted_room");
        } else {
            $sheet->setTitle("$converted_room");
        }

        // 添加标题行（移除了 'Operator' 列）
        $headers = [
            'A1' => 'Reservation ID',
            'B1' => 'Name / Class',
            'C1' => 'Email',
            'D1' => 'Student ID',
            'E1' => 'Reservation Time',
            'F1' => 'Reason',
            'G1' => 'Status',
            'H1' => 'Submission Time',
        ];

        foreach ($headers as $cell => $header) {
            $sheet->setCellValue($cell, $header);
        }

        // 填充数据（不包括 'operator' 列）
        $row = 2;
        foreach ($roomReservations as $reservation) {
            $sheet->setCellValue("A$row", $reservation['id']);
            $sheet->setCellValue("B$row", $reservation['name']);
            $sheet->setCellValue("C$row", $reservation['email']);
            $sheet->setCellValue("D$row", $reservation['sid']);
            $sheet->setCellValue("E$row", formatTime($reservation['time']));
            $sheet->setCellValue("F$row", $reservation['reason']);
            $sheet->setCellValue("G$row", formatAuth($reservation['auth']));
            $sheet->setCellValue("H$row", $reservation['addTime']);
            // 已移除 'operator' 列

            $row++;
        }

        // 自动调整列宽
        foreach (range('A', 'H') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $sheetIndex++;
    }

    $progress[] = '已生成 Excel 文件。';

    $spreadsheet->setActiveSheetIndex(0);

    // 定义 'cache' 文件夹的路径，位于当前脚本所在目录
    $cacheDir = __DIR__ . '/cache';

    // 检查 'cache' 文件夹是否存在，如果不存在则创建
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // 定义文件名和保存路径
    $filename = 'reservations_report_' . date('Y-m-d', $tomorrow_start) . '.xlsx';
    $filepath = $cacheDir . '/' . $filename;

    // 保存 Excel 文件到指定路径
    $writer = new Xlsx($spreadsheet);
    $writer->save($filepath);

    $progress[] = '已将 Excel 文件保存到缓存目录。';

    // 使用 initializeMailer() 函数发送邮件
    $mail = initializeMailer();

    $progress[] = '已初始化邮件发送器。';

    // 设置收件人
    $adminEmail = 'joannaliang@gdhfi.com'; // 请替换为行政办公室的实际邮箱地址
    
    $mail->addAddress($adminEmail, 'Administrative Office');
    $mail->addBCC('jeffery.lui@foxmail.com','Observation');
    $progress[] = '已添加收件人。';

    // 邮件内容
    $mail->Subject = 'Room Reservation Report for ' . date('Y-m-d', $tomorrow_start);
    $mail->isHTML(true);

    // 计算预约总数
    $totalReservations = count($reservations);

    // 邮件正文，包含预约总数和备注
    $mail->Body = '
        <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f7f7f7; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
                <h2 style="color: #4CAF50; text-align: center;">Room Reservation Report</h2>
                <p style="font-size: 16px; line-height: 1.5;">
                    Please find attached the room reservation report for <strong>' . date('Y-m-d', $tomorrow_start) . '</strong>.
                </p>
                <p style="font-size: 14px; line-height: 1.5;">
                    The report contains detailed information about all <strong>' . $totalReservations . '</strong> reservations scheduled for tomorrow.
                </p>
                <p style="font-size: 14px; line-height: 1.5; color: #888;">
                    Note: This data represents reservations at the time of sending. There may be new reservations or changes after this email was sent.
                </p>
                <p style="font-size: 14px; line-height: 1.5;">
                    If you have any questions, please contact us.
                </p>
                <div style="text-align: center; margin-top: 30px;">
                    <p>Contact us at: <a href="mailto:' . htmlspecialchars($mail->Username) . '">' . htmlspecialchars($mail->Username) . '</a></p>
                </div>
            </div>
        </div>';

    $progress[] = '已准备好邮件内容。';

    // 添加附件
    $mail->addAttachment($filepath);

    $progress[] = '已添加附件。';

    // 发送邮件
    if ($mail->send()) {
        $progress[] = '邮件发送成功。';
        echo json_encode(['success' => true, 'message' => 'Email has been sent', 'progress' => $progress]);
    } else {
        $progress[] = '邮件发送失败。';
        echo json_encode(['success' => false, 'message' => 'Error occurred: ' . $mail->ErrorInfo, 'progress' => $progress]);
    }

    // 清理临时文件
    unlink($filepath);

    $progress[] = '已删除临时文件。';

} catch (Exception $e) {
    $progress[] = '发生异常：' . $e->getMessage();
    echo json_encode(['success' => false, 'message' => 'Error occurred: ' . $e->getMessage(), 'progress' => $progress]);
}
?>
