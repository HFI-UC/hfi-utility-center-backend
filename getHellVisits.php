<?php
// getHellVisits.php
header('Access-Control-Allow-Origin: *');

$cacheFile = __DIR__ . '/cache/visit_count.json';

if (file_exists($cacheFile)) {
    header('Content-Type: application/json');
    echo file_get_contents($cacheFile);
} else {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Visit count file not found."]);
}
?>
