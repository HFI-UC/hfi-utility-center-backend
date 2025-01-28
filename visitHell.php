<?php
//visitHell.php
require_once 'global_variables.php';
if($_SERVER['REQUEST_METHOD']=='OPTIONS'){
    http_response_code(200);
    exit;
}
$input = file_get_contents('php://input');
$data = json_decode($input, true);
if(!isset($data['cf_token'])){
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid Parameter."]);
    exit();
}
$cftoken = $data['cf_token'];

if (empty($cftoken)) {
    http_response_code(400);
    echo json_encode(["success" => false,"message" => "Invalid Parameter."]);
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
    echo json_encode(["success" => false,"message" => "Turnstile Service Error."]);
    curl_close($ch);
    exit();
}
curl_close($ch);

$response = json_decode($result, true);
if (is_null($response) || !$response['success']) {
    http_response_code(403);
    echo json_encode(["success" => false,"message" => "您疑似有点人机了。"]);
    exit();
}

$ip = $_SERVER['REMOTE_ADDR'];  
$userAgent = $_SERVER['HTTP_USER_AGENT'];  
$referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';  
$cookie = json_encode($_COOKIE);  
$serverInfo = json_encode($_SERVER);  


$cacheFile = __DIR__ . '/cache/visit_count.json';


if (file_exists($cacheFile)) {
    $data = json_decode(file_get_contents($cacheFile), true);
} else {
    $data = ['cnt' => 0];  
}


$data['cnt']++;


file_put_contents($cacheFile, json_encode($data));


try {
    
    $stmt = $pdo->prepare("UPDATE visit_stats SET visit_count = :visit_count WHERE id = 1");
    $stmt->execute(['visit_count' => $data['cnt']]);
    
    
    $visitStatId = 1;  
    
    
    $logStmt = $pdo->prepare("INSERT INTO visit_logs (visit_stat_id, ip_address, user_agent, referrer, cookie, server_info) VALUES (:visit_stat_id, :ip_address, :user_agent, :referrer, :cookie, :server_info)");
    $logStmt->execute([
        'visit_stat_id' => $visitStatId,
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'referrer' => $referrer,
        'cookie' => $cookie,
        'server_info' => $serverInfo
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    logException($e);
    echo json_encode(["success"=>false,"message" => "Internal Server Error."]);
    exit();
}


echo json_encode(['success' => true, 'cnt' => $data['cnt']]);
?>
