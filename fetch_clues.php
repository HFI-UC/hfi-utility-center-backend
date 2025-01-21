<?php
// fetch_clues.php

require_once 'db.php'; // Database connection
require_once 'global_variables.php'; // Contains $maxPage and $maxLimit
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');


handleRequest();

try {
    // Check if the request method is GET or POST
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed.']);
        exit;
    }
    
    // Retrieve parameters from GET or POST depending on the request method
    $params = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
    if(!isset($params['lost_info_id'])||empty($params['lost_info_id'])){
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'No Valid Parameters Provided.']);
        exit;
    }
    // Pagination parameters
    $page = isset($params['page']) && is_numeric($params['page']) ? (int) $params['page'] : 1;
    $page = max(1, min($page, $maxPage));

    $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 50;
    $limit = max(1, min($limit, $maxLimit));

    $offset = ($page - 1) * $limit;

    // Optional filtering parameters
    $lost_info_id = isset($params['lost_info_id']) && is_numeric($params['lost_info_id']) ? (int) $params['lost_info_id'] : null;
    $campus = isset($params['campus']) ? trim($params['campus']) : null;
    $query = isset($params['query']) ? trim($params['query']) : null; // For searching in detail or location

    // Build the SQL query with optional filters
    $sql = "SELECT id, campus, detail, location, file_path, contact, lost_info_id, created_at 
            FROM volunteer_clues";

    $conditions = [];
    $bindings = [];

    if (!is_null($lost_info_id)) {
        $conditions[] = "lost_info_id = :lost_info_id";
        $bindings[':lost_info_id'] = $lost_info_id;
    }

    if (!is_null($campus)) {
        $conditions[] = "campus = :campus";
        $bindings[':campus'] = $campus;
    }

    if (!is_null($query)) {
        $conditions[] = "(detail LIKE :query OR location LIKE :query)";
        $bindings[':query'] = '%' . $query . '%';
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    // Bind values
    foreach ($bindings as $param => $value) {
        $stmt->bindValue($param, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    // Fetch the results
    $clues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count of records for pagination
    $countSql = "SELECT COUNT(*) as total FROM volunteer_clues";
    if (count($conditions) > 0) {
        $countSql .= " WHERE " . implode(' AND ', $conditions);
    }
    $countStmt = $pdo->prepare($countSql);

    // Bind values for count query
    foreach ($bindings as $param => $value) {
        // Exclude :limit and :offset from bindings
        if ($param !== ':limit' && $param !== ':offset') {
            $countStmt->bindValue($param, $value, PDO::PARAM_STR);
        }
    }

    $countStmt->execute();
    $total = $countStmt->fetchColumn();

    // Calculate total pages
    $totalPages = ceil($total / $limit);

    // Prepare the response data
    $response = [
        'success' => true,
        'data' => $clues,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_entries' => (int) $total,
            'total_pages' => (int) $totalPages
        ]
    ];

    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve data.']);
    error_log("Database Error in fetch_clues.php: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    error_log("General Error in fetch_clues.php: " . $e->getMessage());
}
?>