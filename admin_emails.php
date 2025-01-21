<?php
require 'db.php';
require 'global_variables.php';
// 定义缓存文件路径和缓存时间（单位：秒）

$cacheTime = $authItemsCacheTime; 

function isAdmin($email) {
    static $adminEmails = null;

    // 如果缓存的管理员邮箱列表还未加载，尝试从缓存文件加载
    if ($adminEmails === null) {
        $adminEmails = loadAdminEmailsFromCache();
    }

    // 如果缓存中没有管理员邮箱列表，从数据库加载并更新缓存
    if ($adminEmails === false) {
        $adminEmails = loadAdminEmailsFromDatabase();
        saveAdminEmailsToCache($adminEmails);
    }

    // 检查给定的邮箱是否在管理员邮箱列表中
    return in_array($email, $adminEmails);
}

// 从缓存文件加载管理员邮箱列表
function loadAdminEmailsFromCache() {
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

// 将管理员邮箱列表保存到缓存文件
function saveAdminEmailsToCache($adminEmails) {
    global $cacheFile;

    // 创建缓存目录（如果不存在）
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $export = var_export($adminEmails, true);
    $content = "<?php\nreturn $export;\n";
    file_put_contents($cacheFile, $content, LOCK_EX);
}

// 从数据库加载管理员邮箱列表
function loadAdminEmailsFromDatabase() {
    global $pdo;

    $stmt = $pdo->query("SELECT email FROM users");
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $emails;
}
?>
