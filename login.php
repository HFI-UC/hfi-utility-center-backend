<?php
/**
 * 用户登录接口
 * 优化性能并增加错误处理
 */
require 'db.php';
require 'global_variables.php';
require_once 'global_error_handler.php';

// 启动会话并设置响应头
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // 验证请求参数
    validateRequestParameters();
    
    // 获取并验证用户输入
    $cftoken = $_POST['cf_token'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // 验证Cloudflare Turnstile
    verifyCloudflareTurnstile($cftoken);
    
    // 验证用户凭据并登录
    loginUser($username, $password);
    
} catch (Exception $e) {
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "服务器内部错误，请稍后再试。"]);
    }
}

/**
 * 验证请求参数
 * @throws InvalidArgumentException 如果请求参数不完整或无效
 */
function validateRequestParameters() {
    // 检查必需参数是否存在
    if (!isset($_POST['cf_token'], $_POST['username'], $_POST['password'])) {
        throw new InvalidArgumentException("请求参数不完整");
    }
    
    // 检查用户名和密码是否为空
    if (empty($_POST['username']) || empty($_POST['password'])) {
        throw new InvalidArgumentException("用户名和密码不能为空");
    }
}

/**
 * 验证Cloudflare Turnstile
 * @param string $cftoken Cloudflare Turnstile令牌
 * @throws Exception 如果验证失败
 */
function verifyCloudflareTurnstile($cftoken) {
    // Cloudflare Turnstile验证URL
    $url = "https://challenges.cloudflare.com/turnstile/v0/siteverify";
    $cfdata = http_build_query([
        'secret' => '0x4AAAAAAAiw3ghAZrJ0Zas0q9wuTeFpN5U',
        'response' => $cftoken
    ]);
    
    // 创建缓存目录，如果不存在
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    // 生成缓存键并检查是否存在缓存
    $cacheKey = md5($cftoken);
    $cachePath = $cacheDir . '/' . $cacheKey;
    
    // 如果缓存存在且未过期（有效期1小时），直接使用缓存结果
    if (file_exists($cachePath) && (time() - filemtime($cachePath) < 3600)) {
        $result = file_get_contents($cachePath);
        $response = json_decode($result, true);
        
        if (is_array($response) && isset($response['success']) && $response['success']) {
            return true;
        }
    }
    
    // 初始化curl选项，启用SSL验证
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $cfdata);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 开启SSL验证
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);   // 验证主机名
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);   // 连接超时5秒
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);         // 总超时10秒
    
    // 设置HTTP keepalive
    curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 60);
    curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 30);
    
    $result = curl_exec($ch);
    
    // 检查curl错误
    if (curl_errno($ch)) {
        curl_close($ch);
        throw new Exception("CURL Error: " . curl_error($ch));
    }
    
    curl_close($ch);
    
    // 解析响应
    $response = json_decode($result, true);
    if (is_null($response) || !$response['success']) {
        throw new Exception("验证失败: " . (isset($response['error-codes']) ? implode(', ', $response['error-codes']) : "未知错误"));
    }
    
    // 缓存成功的结果
    file_put_contents($cachePath, $result);
    
    return true;
}

/**
 * 加密数据
 * @param string $data 要加密的数据
 * @param string $key 加密密钥
 * @return string 加密后的Base64字符串
 */
function opensslEncrypt($data, $key) {
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    // 将IV附加到加密数据上，因为解密时需要它
    return base64_encode($iv . $encrypted);
}

/**
 * 验证用户凭据并处理登录
 * @param string $username 用户名或邮箱
 * @param string $password 密码
 * @throws Exception 如果登录失败
 */
function loginUser($username, $password) {
    global $pdo, $base64Key;
    
    try {
        // 使用事务来保证数据一致性
        $pdo->beginTransaction();
        
        $sql = "SELECT username, email, password FROM users WHERE username = ? OR email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username, $username]);
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (password_verify($password, $row['password'])) {
                // 用户验证成功，可以添加登录日志
                $logSql = "INSERT INTO login_logs (user_id, login_time, ip_address, user_agent) 
                          VALUES ((SELECT id FROM users WHERE username = ?), NOW(), ?, ?)";
                $logStmt = $pdo->prepare($logSql);
                $logStmt->execute([
                    $row['username'], 
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
                
                // 设置会话和Cookie
                $_SESSION['email'] = $row['email'];
                $key = base64_decode($base64Key);
                $token = opensslEncrypt($row['email'], $key);
                setcookie('token', $token, time() + (86400 * 7), "/", "", true, true); // 增加secure和httponly标志
                
                $pdo->commit();
                
                // 返回成功响应
                http_response_code(200);
                echo json_encode(["success" => true, "message" => "登录成功！", "token" => $token]);
            } else {
                $pdo->rollBack();
                http_response_code(401);
                echo json_encode(["success" => false, "message" => "用户名或密码错误！"]);
            }
        } else {
            $pdo->rollBack();
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "用户名或密码错误！"]);
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        logException($e);
        throw new Exception("数据库操作失败");
    }
}
?>
