<?php
/*
ini_set('display_errors', 1);
error_reporting(E_ALL);
*/
require 'db.php';
require 'global_variables.php';

session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if (!isset($_POST['cf_token'], $_POST['username'], $_POST['password'])) {
    http_response_code(400);
    echo json_encode(["message" => "请求参数不完整"]);
    exit();
}


handleRequest();

$cftoken = $_POST['cf_token'];
$username = $_POST['username'];
$password = $_POST['password'];

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["message" => "用户名和密码不能为空。"]);
    exit();
}

$url = "https://challenges.cloudflare.com/turnstile/v0/siteverify";
$cfdata = http_build_query([
    'secret' => '0x4AAAAAAAiw3ghAZrJ0Zas0q9wuTeFpN5U',
    'response' => $cftoken
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $cfdata);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$result = curl_exec($ch);
if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(["message" => "CURL Error: " . curl_error($ch)]);
    curl_close($ch);
    exit();
}
curl_close($ch);

$response = json_decode($result, true);
if (is_null($response) || !$response['success']) {
    http_response_code(403);
    echo json_encode(["message" => "您疑似有点人机了"]);
    exit();
}

function opensslEncrypt($data, $key) {
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    // 将IV附加到加密数据上，因为解密时需要它
    return base64_encode($iv . $encrypted);
}

$key = base64_decode($base64Key);
$username = $_POST["username"];
$password = $_POST["password"];

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username or Password can not be empty."]);
    exit();
}

$sql = "SELECT username, email, password FROM users WHERE username = ? OR email = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$username, $username]);

if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (password_verify($password, $row['password'])) {
        $_SESSION['email'] = $row['email'];
        $token = opensslEncrypt($row['email'], $key);
        setcookie('token', $token, time() + (86400 * 7), "/"); // 设置token cookie，有效期7天
        http_response_code(200);
        echo json_encode(["success" => true, "message" => "登录成功！", "token" => $token]);
    } else {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "用户名或密码错误！"]);
    }
} else {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "用户名或密码错误！"]);
}
?>
