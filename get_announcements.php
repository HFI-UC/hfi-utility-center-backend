<?php
require_once 'global_variables.php';
require_once 'db.php';
header('Content-Type: application/json; charset=UTF-8');

// 辅助函数：发送JSON响应并退出 (此处不需要日志记录，因为是GET请求)
function send_json_response($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 辅助函数：清理输入字符串
function sanitize_input_value($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(false, '仅支持 GET 请求。', null, 405);
}

// 2. 获取并验证查询参数
$status_filter = 'published';
$page = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) : 1;
$limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 100]]) : 10;
$sort_by = isset($_GET['sort_by']) ? sanitize_input_value($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? strtoupper(sanitize_input_value($_GET['sort_order'])) : 'DESC';
$include_deleted = isset($_GET['include_deleted']) && filter_var($_GET['include_deleted'], FILTER_VALIDATE_BOOLEAN);

// 参数校验
$allowed_sort_fields = ['id', 'title', 'created_at', 'updated_at', 'status'];
if (!in_array($sort_by, $allowed_sort_fields)) {
    $sort_by = 'created_at'; 
}
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC'; 
}

if ($status_filter !== null) {
    $valid_statuses_filter = ['published', 'draft', 'archived']; 
    if (!in_array($status_filter, $valid_statuses_filter)) {
        // 如果传入了 status 但无效，可以报错或默认为 null (不过滤)
        send_json_response(false, '无效的 status 筛选参数。', null, 400);
    }
}

// 3. 从数据库查询数据
global $pdo;

try {
    $params = [];
    $base_sql = "FROM announcements";
    $where_clauses = [];

    if (!$include_deleted) {
        $where_clauses[] = "deleted_at IS NULL";
    }

    if ($status_filter !== null) {
        $where_clauses[] = "status = :status_filter";
        $params[':status_filter'] = $status_filter;
    }
    
    $sql_where = "";
    if (!empty($where_clauses)) {
        $sql_where = " WHERE " . implode(" AND ", $where_clauses);
    }

    // 获取总记录数 (用于分页)
    $count_sql = "SELECT COUNT(*) AS total " . $base_sql . $sql_where;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = $total_records > 0 ? ceil($total_records / $limit) : 0;

    $announcements = [];
    if ($total_records > 0 && $page <= $total_pages) {
        $offset = ($page - 1) * $limit;
        $data_sql = "SELECT id, title, content, status, created_at, updated_at " 
                    . $base_sql . $sql_where 
                    . " ORDER BY `$sort_by` $sort_order " 
                    . " LIMIT :limit OFFSET :offset";
        
        $data_stmt = $pdo->prepare($data_sql);
        $data_stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $data_stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => &$val) { 
            $data_stmt->bindParam($key, $val);
        }
        unset($val); 

        $data_stmt->execute();
        $announcements = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $response_data = [
        'announcements' => $announcements,
        'pagination' => [
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $limit,
            'include_deleted' => $include_deleted
        ]
    ];

    send_json_response(true, '公告获取成功。', $response_data);

} catch (PDOException $e) {
    logException($e); // GET请求通常不主动记录业务日志，但可以记录系统异常
    send_json_response(false, '数据库操作失败，请联系管理员。', null, 500);
}

?> 