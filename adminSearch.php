<?php
require 'global_variables.php';
require 'admin_emails.php';
require 'db.php';

handleRequest();

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$key = base64_decode($base64Key);
$email = opensslDecrypt($token, $key);

if (!isAdmin($email)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

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
    if ($value !== null && $value !== false) {
        $received_any          = true;
        $conditions[]          = "$field = :$field";
        $bindings[":$field"] = $value;
    }
}

if ($queryField !== null && trim($queryField) !== '') {
    $received_any = true;
    $searchColumns    = ['id', 'room', 'email', 'auth', 'time', 'name', 'reason'];
    $queryConditions  = [];
    foreach ($searchColumns as $i => $column) {
        $paramName             = ":query$i";
        $queryConditions[]     = "$column LIKE $paramName";
        $bindings[$paramName] = '%' . $queryField . '%';
    }
    $conditions[] = '(' . implode(' OR ', $queryConditions) . ')';
}

if ($inputTime !== null && $inputTime !== false) {
    $received_any = true;
    $threeHours   = 10800 * 1000; 
    $timeCondition = "(
        :inputTime BETWEEN SUBSTRING_INDEX(time, '-', 1) AND SUBSTRING_INDEX(time, '-', -1) OR
        ABS(SUBSTRING_INDEX(time, '-', 1) - :inputTime1) < $threeHours OR
        ABS(SUBSTRING_INDEX(time, '-', -1) - :inputTime2) < $threeHours
    )";
    $conditions[] = $timeCondition;
    $bindings[':inputTime']  = $inputTime;
    $bindings[':inputTime1'] = $inputTime;
    $bindings[':inputTime2'] = $inputTime;
}

if (!$received_any) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No parameters provided']);
    exit;
}

$sql = "SELECT * FROM (
    SELECT * FROM requests
    UNION ALL
    SELECT * FROM history
) AS combined";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(' AND ', $conditions);
}

$sql .= " ORDER BY id ASC";

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
    echo json_encode(['success' => false, 'message' => 'Internal Server Error.']);
    error_log($e->getMessage());
}
?>
