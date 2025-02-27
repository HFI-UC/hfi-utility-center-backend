<?php
/**
 * 失物招领信息获取接口
 * 优化性能并增加错误处理
 */
require_once 'db.php'; 
require_once 'global_variables.php'; 
require_once 'global_error_handler.php';

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Headers: Content-Type');

try {
    // 验证请求方法
    validateRequestMethod();
    
    // 获取请求参数
    $params = getRequestParameters();
    
    // 验证用户权限
    $isAdmin = validateUserPermission($params);
    
    // 准备查询参数
    $queryParams = prepareQueryParameters($params, $isAdmin);
    
    // 获取失物招领数据
    $entries = fetchLostAndFoundEntries($queryParams);
    
    // 如果需要获取线索，则获取相关线索
    if ($queryParams['includeClues'] && !empty($entries)) {
        $entries = fetchAndAttachClues($entries);
    }
    
    // 获取记录总数和总页数
    $totalPages = getTotalPages($queryParams);
    
    // 格式化响应数据并返回
    $entries = array_map('convertKeysToCamelCase', $entries);
    
    $response = [
        'success' => true,
        'data' => $entries,
        'totalPages' => (int) $totalPages
    ];
    
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 使用全局错误处理函数记录异常
    logException($e);
    
    // 根据异常类型返回适当的错误响应
    if ($e instanceof InvalidArgumentException) {
        http_response_code(405); // Method Not Allowed
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else if ($e instanceof PDOException) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve data.']);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    }
}

/**
 * 验证请求方法是否为GET或POST
 * 
 * @throws InvalidArgumentException 如果请求方法不是GET或POST
 */
function validateRequestMethod() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Method Not Allowed.');
    }
}

/**
 * 获取请求参数
 * 
 * @return array 请求参数
 */
function getRequestParameters() {
    return ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
}

/**
 * 验证用户权限
 * 
 * @param array $params 请求参数
 * @return bool 是否是管理员
 */
function validateUserPermission($params) {
    global $base64Key;
    
    $token = isset($params['token']) ? $params['token'] : null;
    $isAdmin = false;
    
    if ($token) {
        // 使用静态变量缓存令牌解密结果
        static $adminCache = [];
        $cacheKey = md5($token);
        
        if (isset($adminCache[$cacheKey])) {
            return $adminCache[$cacheKey];
        }
        
        try {
            $decryptedToken = opensslDecrypt($token, $base64Key);
            
            if ($decryptedToken) {
                $userEmail = json_decode($decryptedToken)->email;
                $isAdmin = isAdmin($userEmail);
                
                // 缓存结果
                $adminCache[$cacheKey] = $isAdmin;
            }
        } catch (Exception $e) {
            logException($e);
            // 如果解密失败，默认为非管理员权限
            return false;
        }
    }
    
    return $isAdmin;
}

/**
 * 准备查询参数
 * 
 * @param array $params 请求参数
 * @param bool $isAdmin 是否是管理员
 * @return array 查询参数
 */
function prepareQueryParameters($params, $isAdmin) {
    global $maxPage, $maxLimit;
    
    // 设置is_found过滤条件
    $isFoundFilter = null;
    if ($isAdmin) {
        $isFoundFilter = isset($params['is_found']) && in_array($params['is_found'], ['0', '1', '3']) ? $params['is_found'] : null;
    } else {
        $isFoundFilter = isset($params['is_found']) && in_array($params['is_found'], ['0', '1']) ? $params['is_found'] : null;
    }
    
    // 分页参数
    $page = isset($params['page']) && is_numeric($params['page']) ? (int) $params['page'] : 1;
    $page += 1; // 页码调整
    $page = min(max(1, $page), $maxPage);
    
    $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 50;
    $limit = min($limit, $maxLimit);
    
    $offset = ($page - 1) * $limit;
    
    // 查询参数
    $query = isset($params['query']) ? trim($params['query']) : null;
    
    // 是否包含线索
    $includeClues = isset($params['include_clues']) && ($params['include_clues'] === 'true' || $params['include_clues'] === '1');
    
    return [
        'isFoundFilter' => $isFoundFilter,
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'query' => $query,
        'includeClues' => $includeClues
    ];
}

/**
 * 获取失物招领数据
 * 
 * @param array $queryParams 查询参数
 * @return array 失物招领数据
 * @throws PDOException 如果数据库操作失败
 */
function fetchLostAndFoundEntries($queryParams) {
    global $pdo;
    
    try {
        // 构建查询
        $sql = "SELECT id, type, student_name, detail, location, email, alt_contact, campus, file_path, reward, is_found, last_updated, created_at, event_time
                FROM lost_and_found";
        
        $conditions = [];
        $bindings = [];
        
        if (!is_null($queryParams['isFoundFilter'])) {
            $conditions[] = "is_found = :is_found";
            $bindings[':is_found'] = $queryParams['isFoundFilter'];
        }
        
        if (!is_null($queryParams['query']) && $queryParams['query'] !== '') {
            // 定义要搜索的列
            $searchColumns = ['type', 'student_name', 'detail', 'location', 'email', 'alt_contact', 'campus', 'reward', "id"];
            $queryConditions = [];
            
            foreach ($searchColumns as $i => $column) {
                $paramName = ":query$i";
                $queryConditions[] = "$column LIKE $paramName";
                $bindings[$paramName] = '%' . $queryParams['query'] . '%';
            }
            
            $conditions[] = '(' . implode(' OR ', $queryConditions) . ')';
        }
        
        // 合并条件
        if (count($conditions) > 0) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        
        // 使用静态缓存避免重复准备语句
        static $stmtCache = null;
        if ($stmtCache === null) {
            $stmtCache = $pdo->prepare($sql);
        }
        
        // 绑定参数
        foreach ($bindings as $param => $value) {
            $stmtCache->bindValue($param, $value, PDO::PARAM_STR);
        }
        
        if (!is_null($queryParams['isFoundFilter'])) {
            $stmtCache->bindValue(':is_found', $queryParams['isFoundFilter'], PDO::PARAM_INT);
        }
        
        $stmtCache->bindValue(':limit', $queryParams['limit'], PDO::PARAM_INT);
        $stmtCache->bindValue(':offset', $queryParams['offset'], PDO::PARAM_INT);
        
        $stmtCache->execute();
        
        // 使用 PDO::FETCH_ASSOC 提高性能
        return $stmtCache->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logException($e);
        throw $e;
    }
}

/**
 * 获取并附加线索信息
 * 
 * @param array $entries 失物招领数据
 * @return array 附加了线索的失物招领数据
 * @throws PDOException 如果数据库操作失败
 */
function fetchAndAttachClues($entries) {
    global $pdo;
    
    try {
        // 收集所有lost_info_ids
        $lostInfoIds = array_column($entries, 'id');
        
        // 准备SQL获取所有ID的线索
        $placeholders = implode(',', array_fill(0, count($lostInfoIds), '?'));
        $cluesSql = "SELECT id, campus, detail, location, file_path, contact, lost_info_id, created_at 
                     FROM volunteer_clues 
                     WHERE lost_info_id IN ($placeholders)";
        
        // 使用静态缓存，减少预处理语句的创建
        static $cluesStmtCache = [];
        $cacheKey = count($lostInfoIds);
        
        if (!isset($cluesStmtCache[$cacheKey])) {
            $cluesStmtCache[$cacheKey] = $pdo->prepare($cluesSql);
        }
        
        $cluesStmtCache[$cacheKey]->execute($lostInfoIds);
        
        $clues = $cluesStmtCache[$cacheKey]->fetchAll(PDO::FETCH_ASSOC);
        
        // 按lost_info_id分组线索
        $cluesByLostInfoId = [];
        foreach ($clues as $clue) {
            $lostInfoId = $clue['lost_info_id'];
            if (!isset($cluesByLostInfoId[$lostInfoId])) {
                $cluesByLostInfoId[$lostInfoId] = [];
            }
            $cluesByLostInfoId[$lostInfoId][] = $clue;
        }
        
        // 将线索附加到相应的失物招领条目
        foreach ($entries as &$entry) {
            $entry['clues'] = isset($cluesByLostInfoId[$entry['id']]) ? $cluesByLostInfoId[$entry['id']] : [];
        }
        
        return $entries;
    } catch (PDOException $e) {
        logException($e);
        throw $e;
    }
}

/**
 * 获取总页数
 * 
 * @param array $queryParams 查询参数
 * @return int 总页数
 * @throws PDOException 如果数据库操作失败
 */
function getTotalPages($queryParams) {
    global $pdo;
    
    try {
        // 构建计数SQL
        $countSql = "SELECT COUNT(*) as total FROM lost_and_found";
        
        $conditions = [];
        $bindings = [];
        
        if (!is_null($queryParams['isFoundFilter'])) {
            $conditions[] = "is_found = :is_found";
            $bindings[':is_found'] = $queryParams['isFoundFilter'];
        }
        
        if (!is_null($queryParams['query']) && $queryParams['query'] !== '') {
            // 定义要搜索的列
            $searchColumns = ['type', 'student_name', 'detail', 'location', 'email', 'alt_contact', 'campus', 'reward', "id"];
            $queryConditions = [];
            
            foreach ($searchColumns as $i => $column) {
                $paramName = ":query$i";
                $queryConditions[] = "$column LIKE $paramName";
                $bindings[$paramName] = '%' . $queryParams['query'] . '%';
            }
            
            $conditions[] = '(' . implode(' OR ', $queryConditions) . ')';
        }
        
        // 合并条件
        if (count($conditions) > 0) {
            $countSql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        // 使用缓存减少预处理语句创建
        static $countStmtCache = null;
        if ($countStmtCache === null) {
            $countStmtCache = $pdo->prepare($countSql);
        }
        
        // 绑定参数
        foreach ($bindings as $param => $value) {
            $countStmtCache->bindValue($param, $value, PDO::PARAM_STR);
        }
        
        if (!is_null($queryParams['isFoundFilter'])) {
            $countStmtCache->bindValue(':is_found', $queryParams['isFoundFilter'], PDO::PARAM_INT);
        }
        
        $countStmtCache->execute();
        $total = $countStmtCache->fetch()['total'];
        
        // 计算总页数
        return ceil($total / $queryParams['limit']);
    } catch (PDOException $e) {
        logException($e);
        throw $e;
    }
}
?>
