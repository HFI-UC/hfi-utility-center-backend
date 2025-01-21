<?php
require "global_variables.php";
require "db.php";

$cacheFile = __DIR__ . '/cache/classrooms.json';
$cacheTime = 3600; // 缓存有效期，单位为秒


handleRequest();

function fetchClassrooms($pdo) {
    $stmt = $pdo->prepare("SELECT classroom, days, start_time, end_time FROM classrooms WHERE unavailable = 1");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getClassroomsData($pdo, $cacheFile, $cacheTime) {
    // 检查缓存目录是否存在，不存在则创建
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // 检查缓存文件是否存在且未过期
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $jsonData = file_get_contents($cacheFile);
        if ($jsonData !== false) {
            return $jsonData;
        }
    }

    // 获取数据
    $classrooms = fetchClassrooms($pdo);
    $data = [
        'policy' => $classrooms
    ];
    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // 写入缓存文件，使用文件锁防止并发写入导致的数据损坏
    $tempFile = $cacheFile . '.' . uniqid('', true);
    if (file_put_contents($tempFile, $jsonData, LOCK_EX) !== false) {
        rename($tempFile, $cacheFile);
    }

    return $jsonData;
}

header('Content-Type: application/json');
echo getClassroomsData($pdo, $cacheFile, $cacheTime);
?>