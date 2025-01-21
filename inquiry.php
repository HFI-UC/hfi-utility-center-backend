<?php
require 'db.php';
require_once 'global_variables.php';

handleRequest();

$method = $_SERVER['REQUEST_METHOD'];
$inputMethod = $method === 'POST' ? INPUT_POST : INPUT_GET;

$fields = [
    'email' => FILTER_SANITIZE_EMAIL,
    'room'  => FILTER_SANITIZE_NUMBER_INT,
    'sid'   => FILTER_SANITIZE_NUMBER_INT
];

$queryField = filter_input($inputMethod, 'query', FILTER_DEFAULT);
$inputTime  = filter_input($inputMethod, 'time', FILTER_SANITIZE_NUMBER_INT);

$received_any = false;
$conditions   = [];
$bindings     = [];

foreach ($fields as $field => $filter) {
    $value = filter_input($inputMethod, $field, $filter);
    if ($value !== null && $value !== false && $value !== '') {
        $received_any         = true;
        $conditions[]         = "$field = :$field";
        $bindings[":$field"] = $value;
    }
}

if (!empty($queryField)) {
    $received_any = true;
    $searchColumns   = ['id', 'room', 'email', 'auth', 'time', 'name', 'reason'];
    $queryConditions = [];
    foreach ($searchColumns as $i => $column) {
        $paramName             = ":query$i";
        $queryConditions[]     = "$column LIKE $paramName";
        $bindings[$paramName] = '%' . $queryField . '%';
    }
    $conditions[] = '(' . implode(' OR ', $queryConditions) . ')';
}

if ($inputTime !== null && $inputTime !== false && $inputTime !== '') {
    $received_any = true;
    $threeHours   = 10800 * 1000; 
    $startTime    = $inputTime - $threeHours;
    $endTime      = $inputTime + $threeHours;
    $timeCondition = "(
        SUBSTRING_INDEX(time, '-', 1) <= :endTime AND SUBSTRING_INDEX(time, '-', -1) >= :startTime
    )";
    $conditions[] = $timeCondition;
    $bindings[':startTime'] = $startTime;
    $bindings[':endTime']   = $endTime;
}

if (!$received_any) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No parameters provided.']);
    exit;
}

$conditions[] = "auth <> 'no'";


$sql = "SELECT id, room, email, auth, time, name, reason FROM requests";
$sql .= " WHERE " . implode(' AND ', $conditions);
$sql .='ORDER BY addTime DESC';
try {
    $stmt = $pdo->prepare($sql);
    foreach ($bindings as $param => $value) {
        $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 解码已转码的符号
    foreach ($results as &$row) {
        foreach ($row as &$value) {
            if (is_string($value)) {
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($decoded !== $value) {
                    $value = $decoded;
                }
            }
        }
    }
    unset($row, $value); // 释放引用
    echo json_encode(['success' => true, 'data' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log($e->getMessage());
}
?>