<?php
// report-error.php

header('Content-Type: application/json');

// 包含数据库连接文件
require_once 'db.php'; // 确保该文件设置了 $pdo 变量
require_once 'global_variables.php';

// 获取原始的 POST 数据
$rawData = file_get_contents("php://input");
$errorDetails = json_decode($rawData, true);

// 验证和清理数据
if ($errorDetails && is_array($errorDetails)) {
    // 清理输入数据
    $url = isset($errorDetails['url']) ? filter_var($errorDetails['url'], FILTER_SANITIZE_URL) : '';
    $timestamp = isset($errorDetails['timestamp']) ? filter_var($errorDetails['timestamp'], FILTER_SANITIZE_STRING) : '';
    $userAgent = isset($errorDetails['userAgent']) ? filter_var($errorDetails['userAgent'], FILTER_SANITIZE_STRING) : '';
    $referrer = isset($errorDetails['referrer']) ? filter_var($errorDetails['referrer'], FILTER_SANITIZE_URL) : '';
    $screenWidth = isset($errorDetails['screenWidth']) ? filter_var($errorDetails['screenWidth'], FILTER_VALIDATE_INT) : null;
    $screenHeight = isset($errorDetails['screenHeight']) ? filter_var($errorDetails['screenHeight'], FILTER_VALIDATE_INT) : null;
    $language = isset($errorDetails['language']) ? filter_var($errorDetails['language'], FILTER_SANITIZE_STRING) : '';
    $cookiesEnabled = isset($errorDetails['cookiesEnabled']) ? filter_var($errorDetails['cookiesEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    $platform = isset($errorDetails['platform']) ? filter_var($errorDetails['platform'], FILTER_SANITIZE_STRING) : '';
    $userFeedback = isset($errorDetails['userFeedback']) ? filter_var($errorDetails['userFeedback'], FILTER_SANITIZE_STRING) : '';

    // 将时间戳转换为适合 MySQL 的 DATETIME 格式
    $errorTimestamp = date('Y-m-d H:i:s', strtotime($timestamp));

    try {
        // 开始事务
        $pdo->beginTransaction();

        // 准备 SQL 语句，插入错误报告
        $stmt = $pdo->prepare("INSERT INTO error_reports (
            url, timestamp, user_agent, referrer, screen_width, screen_height, language, cookies_enabled, platform, user_feedback
        ) VALUES (
            :url, :timestamp, :user_agent, :referrer, :screen_width, :screen_height, :language, :cookies_enabled, :platform, :user_feedback
        )");

        // 绑定参数
        $stmt->bindParam(':url', $url);
        $stmt->bindParam(':timestamp', $errorTimestamp);
        $stmt->bindParam(':user_agent', $userAgent);
        $stmt->bindParam(':referrer', $referrer);
        $stmt->bindParam(':screen_width', $screenWidth);
        $stmt->bindParam(':screen_height', $screenHeight);
        $stmt->bindParam(':language', $language);
        $stmt->bindParam(':cookies_enabled', $cookiesEnabled, PDO::PARAM_BOOL);
        $stmt->bindParam(':platform', $platform);
        $stmt->bindParam(':user_feedback', $userFeedback);

        // 执行语句
        $stmt->execute();

        // 提交事务
        $pdo->commit();

        // 初始化邮件
        $mail = initializeMailer();
        if ($mail === null) {
            throw new Exception('Failed to initialize mailer.');
        }

        // 设置收件人
        $mail->addAddress('feedback@hfiuc.org'); // 替换为您的反馈邮箱

        // 设置邮件主题
        $mail->Subject = '网站错误反馈';

        // 构建邮件内容
        $emailBody = "以下是用户反馈的错误信息：\n\n";
        $emailBody .= "发生时间: " . $errorTimestamp . "\n";
        $emailBody .= "页面 URL: " . $url . "\n";
        $emailBody .= "用户代理: " . $userAgent . "\n";
        $emailBody .= "来源页面: " . $referrer . "\n";
        $emailBody .= "屏幕分辨率: " . $screenWidth . "x" . $screenHeight . "\n";
        $emailBody .= "语言: " . $language . "\n";
        $emailBody .= "Cookie 启用: " . ($cookiesEnabled ? '是' : '否') . "\n";
        $emailBody .= "平台: " . $platform . "\n";

        if (!empty($userFeedback)) {
            $emailBody .= "\n用户反馈:\n" . $userFeedback . "\n";
        }

        // 设置邮件正文
        $mail->Body = $emailBody;

        // 发送邮件
        $mail->send();

        // 发送成功响应
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Error report stored and email sent.']);
    } catch (Exception $e) {
        // 如果事务未提交，回滚
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // 记录错误日志
        error_log('Error in send_feedback.php: ' . $e->getMessage());

        // 发送错误响应
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to store error report or send email.']);
    }
} else {
    // 数据无效
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
}
?>
