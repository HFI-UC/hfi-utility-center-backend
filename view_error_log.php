<?php
// error_logs.php

// 包含数据库连接文件
require_once 'db.php'; // 确保设置了 $pdo 变量

// 处理搜索参数
$searchKeyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$errorType = isset($_GET['error_type']) ? trim($_GET['error_type']) : '';

// 构建查询语句
$sql = "SELECT * FROM error_logs WHERE 1=1";
$params = [];

// 处理搜索关键字，使用唯一的占位符
if ($searchKeyword !== '') {
    $sql .= " AND (error_message LIKE :keyword1 OR error_file LIKE :keyword2 OR script_name LIKE :keyword3 OR error_type LIKE :keyword4)";
    $params[':keyword1'] = '%' . $searchKeyword . '%';
    $params[':keyword2'] = '%' . $searchKeyword . '%';
    $params[':keyword3'] = '%' . $searchKeyword . '%';
    $params[':keyword4'] = '%' . $searchKeyword . '%';
}

// 处理日期范围
if ($startDate !== '') {
    $sql .= " AND error_time >= :start_date";
    $params[':start_date'] = $startDate . ' 00:00:00';
}

if ($endDate !== '') {
    $sql .= " AND error_time <= :end_date";
    $params[':end_date'] = $endDate . ' 23:59:59';
}

// 处理错误类型
if ($errorType !== '') {
    $sql .= " AND error_type = :error_type";
    $params[':error_type'] = $errorType;
}

// 按时间倒序排列
$sql .= " ORDER BY error_time DESC";

// 执行查询
try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => &$value) {
        $stmt->bindParam($key, $value);
    }
    $stmt->execute();
    $errorLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error Logs</title>
    <style>
        /* 简单的样式 */
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h1 {
            text-align: center;
        }
        .search-form {
            margin-bottom: 20px;
            text-align: center;
        }
        .search-form input, .search-form select, .search-form button {
            padding: 5px 10px;
            margin-right: 5px;
        }
        .search-form input[type="text"] {
            width: 200px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table th, table td {
            border: 1px solid #aaa;
            padding: 8px;
            text-align: left;
            word-wrap: break-word;
        }
        table th {
            background-color: #eee;
        }
        .no-data {
            text-align: center;
            font-size: 18px;
            color: #666;
        }
        .details {
            background-color: #f9f9f9;
            padding: 10px;
            margin-top: 10px;
            border: 1px solid #ddd;
        }
        .details pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .toggle-btn {
            cursor: pointer;
            color: blue;
            text-decoration: underline;
        }
        /* 隔行换色 */
        table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        /* 响应式表格 */
        @media screen and (max-width: 768px) {
            table, tbody, tr, th, td {
                display: block;
            }
            tr {
                margin-bottom: 15px;
            }
            th, td {
                text-align: right;
                padding-left: 50%;
                position: relative;
            }
            th::before, td::before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                width: 45%;
                padding-left: 15px;
                font-weight: bold;
                text-align: left;
            }
        }
    </style>
    <script>
        // JavaScript 函数，用于显示和隐藏错误详情
        function toggleDetails(id) {
            var details = document.getElementById('details-' + id);
            if (details.style.display === 'none' || details.style.display === '') {
                details.style.display = 'table-row';
            } else {
                details.style.display = 'none';
            }
        }
    </script>
</head>
<body>

<h1>Error Logs</h1>

<div class="search-form">
    <form method="GET" action="view_error_log.php">
        <input type="text" name="keyword" placeholder="Search keyword" value="<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="error_type">
            <option value="">All Types</option>
            <option value="Error" <?php if ($errorType === 'Error') echo 'selected'; ?>>Error</option>
            <option value="Exception" <?php if ($errorType === 'Exception') echo 'selected'; ?>>Exception</option>
            <option value="Fatal Error" <?php if ($errorType === 'Fatal Error') echo 'selected'; ?>>Fatal Error</option>
            <option value="HTTP 400 Error" <?php if ($errorType === 'HTTP 400 Error') echo 'selected'; ?>>HTTP 400 Error</option>
            <option value="HTTP 403 Error" <?php if ($errorType === 'HTTP 403 Error') echo 'selected'; ?>>HTTP 403 Error</option>
            <option value="HTTP 404 Error" <?php if ($errorType === 'HTTP 404 Error') echo 'selected'; ?>>HTTP 404 Error</option>
            <option value="HTTP 500 Error" <?php if ($errorType === 'HTTP 500 Error') echo 'selected'; ?>>HTTP 500 Error</option>
            <!-- 添加其他错误类型 -->
        </select>
        <button type="submit">Search</button>
    </form>
</div>

<?php if (count($errorLogs) > 0): ?>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Error Type</th>
            <th>Error Message</th>
            <th>File</th>
            <th>Line</th>
            <th>Script Name</th>
            <th>Error Time</th>
            <th>Details</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($errorLogs as $log): ?>
        <tr>
            <td data-label="ID"><?php echo htmlspecialchars($log['id'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-label="Error Type"><?php echo htmlspecialchars($log['error_type'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-label="Error Message"><?php echo nl2br(htmlspecialchars($log['error_message'], ENT_QUOTES, 'UTF-8')); ?></td>
            <td data-label="File"><?php echo htmlspecialchars($log['error_file'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-label="Line"><?php echo htmlspecialchars($log['error_line'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-label="Script Name"><?php echo htmlspecialchars($log['script_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-label="Error Time"><?php echo htmlspecialchars($log['error_time'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-label="Details"><span class="toggle-btn" onclick="toggleDetails(<?php echo $log['id']; ?>)">View</span></td>
        </tr>
        <tr id="details-<?php echo $log['id']; ?>" style="display: none;">
            <td colspan="8">
                <div class="details">
                    <h3>Client Data</h3>
                    <p><strong>GET:</strong></p>
                    <pre><?php echo htmlspecialchars(json_encode(json_decode($log['client_get'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                    <p><strong>POST:</strong></p>
                    <pre><?php echo htmlspecialchars(json_encode(json_decode($log['client_post'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                    <p><strong>FILES:</strong></p>
                    <pre><?php echo htmlspecialchars(json_encode(json_decode($log['client_files'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                    <p><strong>COOKIE:</strong></p>
                    <pre><?php echo htmlspecialchars(json_encode(json_decode($log['client_cookie'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                    <p><strong>SESSION:</strong></p>
                    <pre><?php echo htmlspecialchars(json_encode(json_decode($log['client_session'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                    <p><strong>SERVER:</strong></p>
                    <pre><?php echo htmlspecialchars(json_encode(json_decode($log['client_server'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p class="no-data">No matching error logs found.</p>
<?php endif; ?>

</body>
</html>