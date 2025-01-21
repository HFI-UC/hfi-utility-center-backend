<?php
// fetch_lnf_with_clues.php

require_once 'db.php'; 
require_once 'global_variables.php'; 
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

    // Get the token from the request headers or parameters
    $token = isset($params['token']) ? $params['token'] : null;

    // Check if token exists, if not, set an isFoundFilter to restrict to values 0 or 1
    $isAdmin = false;
    if ($token) {
        // Decrypt the token and check if the user is an admin
        $decryptedToken = opensslDecrypt($token, $base64Key);

        if ($decryptedToken) {
            $userEmail = json_decode($decryptedToken)->email;  // Assuming token contains user email
            $isAdmin = isAdmin($userEmail);
        }
    }

    // Set filters for is_found field based on whether the user is admin or not
    $isFoundFilter = null;
    if ($isAdmin) {
        $isFoundFilter = isset($params['is_found']) && in_array($params['is_found'], ['0', '1', '3']) ? $params['is_found'] : null;
    } else {
        $isFoundFilter = isset($params['is_found']) && in_array($params['is_found'], ['0', '1']) ? $params['is_found'] : null;
    }

    // Pagination parameters
    $page = isset($params['page']) && is_numeric($params['page']) ? (int) $params['page'] : 1;
    $page+=1;
    $page = min(max(1, $page), $maxPage);
    
    $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 50;
    $limit = min($limit, $maxLimit);

    $offset = ($page - 1) * $limit;

    // Query parameter
    $query = isset($params['query']) ? trim($params['query']) : null;

    // New parameter to include clues
    $includeClues = isset($params['include_clues']) && ($params['include_clues'] === 'true' || $params['include_clues'] === '1');

    // Build SQL query with optional filters
    $sql = "SELECT id, type, student_name, detail, location, email, alt_contact, campus, file_path, reward, is_found, last_updated, created_at, event_time
            FROM lost_and_found";

    $conditions = [];
    $bindings = [];

    if (!is_null($isFoundFilter)) {
        $conditions[] = "is_found = :is_found";
        $bindings[':is_found'] = $isFoundFilter;
    }

    if (!is_null($query) && $query !== '') {
        // Define which columns to search
        $searchColumns = ['type', 'student_name', 'detail', 'location', 'email', 'alt_contact', 'campus', 'reward', "id"];
        $queryConditions = [];
        foreach ($searchColumns as $i => $column) {
            $paramName = ":query$i";
            $queryConditions[] = "$column LIKE $paramName";
            $bindings[$paramName] = '%' . $query . '%';
        }
        $conditions[] = '(' . implode(' OR ', $queryConditions) . ')';
    }

    // Combine conditions
    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    // Bind values
    foreach ($bindings as $param => $value) {
        $stmt->bindValue($param, $value, PDO::PARAM_STR);
    }

    if (!is_null($isFoundFilter)) {
        $stmt->bindValue(':is_found', $isFoundFilter, PDO::PARAM_INT);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    // Fetch the lost and found entries
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If includeClues is true, fetch the clues for each lost item
    if ($includeClues && !empty($entries)) {
        // Collect all lost_info_ids
        $lostInfoIds = array_column($entries, 'id');

        // Prepare the SQL to fetch clues for all the lost_info_ids
        $placeholders = implode(',', array_fill(0, count($lostInfoIds), '?'));
        $cluesSql = "SELECT id, campus, detail, location, file_path, contact, lost_info_id, created_at 
                     FROM volunteer_clues 
                     WHERE lost_info_id IN ($placeholders)";

        $cluesStmt = $pdo->prepare($cluesSql);
        $cluesStmt->execute($lostInfoIds);

        $clues = $cluesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group clues by lost_info_id
        $cluesByLostInfoId = [];
        foreach ($clues as $clue) {
            $lostInfoId = $clue['lost_info_id'];
            if (!isset($cluesByLostInfoId[$lostInfoId])) {
                $cluesByLostInfoId[$lostInfoId] = [];
            }
            $cluesByLostInfoId[$lostInfoId][] = $clue;
        }

        // Attach clues to the corresponding lost and found entries
        foreach ($entries as &$entry) {
            $entry['clues'] = isset($cluesByLostInfoId[$entry['id']]) ? $cluesByLostInfoId[$entry['id']] : [];
        }
        unset($entry); // Unset reference
    }

    // Get total count of records
    $countSql = "SELECT COUNT(*) as total FROM lost_and_found";

    if (count($conditions) > 0) {
        $countSql .= " WHERE " . implode(' AND ', $conditions);
    }

    $countStmt = $pdo->prepare($countSql);

    // Bind values for count query
    foreach ($bindings as $param => $value) {
        $countStmt->bindValue($param, $value, PDO::PARAM_STR);
    }

    if (!is_null($isFoundFilter)) {
        $countStmt->bindValue(':is_found', $isFoundFilter, PDO::PARAM_INT);
    }

    $countStmt->execute();
    $total = $countStmt->fetch()['total'];

    // Calculate pagination
    $totalPages = ceil($total / $limit);

    // Convert entries' keys to camelCase without changing their data
    $entries = array_map('convertKeysToCamelCase', $entries);

    // Prepare the response data
    $response = [
        'success' => true,
        'data' => $entries,
        'totalPages' => (int) $totalPages
    ];

    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500); 
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve data.']);
    error_log("Database Error in fetch_lnf_with_clues.php: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500); 
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    error_log("General Error in fetch_lnf_with_clues.php: " . $e->getMessage());
}
?>
