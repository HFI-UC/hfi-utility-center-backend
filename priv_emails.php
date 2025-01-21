<?php
require_once 'db.php';
require_once 'global_variables.php';

// 定义缓存文件路径和缓存时间（单位：秒）
$cacheFile = $privCacheFile;
$cacheTime = $authItemsCacheTime; // 从 global_variables.php 获取缓存时间

function isPriv($email) {
    static $privilegedEmails = null;

    // 如果缓存的特权用户邮箱列表还未加载，尝试从缓存文件加载
    if ($privilegedEmails === null) {
        $privilegedEmails = loadPrivilegedEmailsFromCache();
    }

    // 如果缓存中没有特权用户邮箱列表，从数据库加载并更新缓存
    if ($privilegedEmails === false) {
        $privilegedEmails = loadPrivilegedEmailsFromDatabase();
        savePrivilegedEmailsToCache($privilegedEmails);
    }

    // 检查给定的邮箱是否在特权用户邮箱列表中
    return in_array($email, $privilegedEmails);
}

// 从缓存文件加载特权用户邮箱列表
function loadPrivilegedEmailsFromCache() {
    global $cacheFile, $cacheTime;

    if (file_exists($cacheFile)) {
        // 检查缓存文件是否过期
        if (time() - filemtime($cacheFile) < $cacheTime) {
            $data = include $cacheFile;
            if (is_array($data)) {
                return $data;
            }
        }
    }
    return false;
}

// 将特权用户邮箱列表保存到缓存文件
function savePrivilegedEmailsToCache($emails) {
    global $cacheFile;

    // 创建缓存目录（如果不存在）
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $export = var_export($emails, true);
    $content = "<?php\nreturn $export;\n";
    file_put_contents($cacheFile, $content, LOCK_EX);
}

// 从数据库加载特权用户邮箱列表
function loadPrivilegedEmailsFromDatabase() {
    global $pdo;

    $stmt = $pdo->query("SELECT email FROM privilegers");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $emails;
}

// 确保 opensslDecrypt 函数已定义
if (!function_exists('opensslDecrypt')) {
    function opensslDecrypt($data, $key) {
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }
}
?>
