<?php
// clean.php

require_once 'src/vendor/autoload.php';
require 'db.php'; // 数据库连接
require 'global_variables.php'; // 包含全局变量和函数，如 initializeMailer()
require 'admin_emails.php'; // 包含 isAdmin() 函数
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';

// 引入 PhpSpreadsheet 库
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 使用命名空间
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置默认字符编码为 UTF-8
mb_internal_encoding('UTF-8');

// 辅助函数
function formatTime($timeRange) {
    list($startTime, $endTime) = explode('-', $timeRange);
    $start_time_human = date('Y-m-d H:i:s', $startTime / 1000);
    $end_time_human = date('Y-m-d H:i:s', $endTime / 1000);
    return "$start_time_human - $end_time_human";
}

function formatAuth($auth) {
    $statusMap = [
        'yes' => '审批通过',
        'no'  => '打回',
        'non' => '未审批',
    ];
    return $statusMap[$auth] ?? '未知';
}

try {
    // 初始化进度数组
    $progress = [];

    // 开始事务
    $pdo->beginTransaction();

    // 记录脚本开始时间
    $scriptStartTime = microtime(true);

    // 获取执行脚本的IP和User-Agent
    // 如果通过HTTP请求执行
    if (php_sapi_name() !== 'cli') {
        $executionIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $executionUa = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    } else {
        // 如果通过CLI执行
        $executionIp = 'CLI';
        $executionUa = 'CLI';
    }

    // 初始化统计数据
    $stats = [
        'lost_and_found_moved' => 0,
        'volunteer_clues_moved' => 0,
        'requests_moved' => 0,
        'repair_requests_moved' => 0,
        'details' => [
            'lost_and_found_ids' => [],
            'volunteer_clues_ids' => [],
            'requests_ids' => [],
            'repair_requests_ids' => []
        ]
    ];

    /**
     * 迁移 from 'lost_and_found' to 'lost_and_found_history' table
     */

    // 计算90天前的日期
    $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));

    // 选择需要迁移的记录（is_found != 0 且 last_updated <= 90天前）
    $sqlSelectLnF = "SELECT * FROM lost_and_found WHERE is_found != 0 AND last_updated <= :ninetyDaysAgo";
    $stmtSelectLnF = $pdo->prepare($sqlSelectLnF);
    $stmtSelectLnF->execute([':ninetyDaysAgo' => $ninetyDaysAgo]);
    $rowsLnF = $stmtSelectLnF->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rowsLnF)) {
        // 获取 'lost_and_found' 表的列名
        $columnsStmtLnF = $pdo->query("DESCRIBE lost_and_found");
        $columnsLnF = $columnsStmtLnF->fetchAll(PDO::FETCH_COLUMN);
        $columnsListLnF = implode(', ', $columnsLnF);

        // 准备插入到 'lost_and_found_history' 的占位符
        $placeholdersLnF = '(' . implode(', ', array_fill(0, count($columnsLnF), '?')) . ')';

        // 准备插入语句
        $sqlInsertLnF = "INSERT INTO lost_and_found_history ($columnsListLnF) VALUES $placeholdersLnF";
        $stmtInsertLnF = $pdo->prepare($sqlInsertLnF);

        // 数组用于存储需要删除的ID
        $idsToDeleteLnF = [];

        foreach ($rowsLnF as $row) {
            // 准备插入数据
            $rowData = [];
            foreach ($columnsLnF as $column) {
                $rowData[] = isset($row[$column]) ? $row[$column] : null;
            }
            $stmtInsertLnF->execute($rowData);

            // 收集ID用于删除和报告
            $idsToDeleteLnF[] = $row['id'];
            $stats['lost_and_found_moved']++;
            $stats['details']['lost_and_found_ids'][] = $row['id'];
        }

        // 删除原表中的记录
        $idsPlaceholderLnF = implode(', ', array_fill(0, count($idsToDeleteLnF), '?'));
        $sqlDeleteLnF = "DELETE FROM lost_and_found WHERE id IN ($idsPlaceholderLnF)";
        $stmtDeleteLnF = $pdo->prepare($sqlDeleteLnF);
        $stmtDeleteLnF->execute($idsToDeleteLnF);

        $progress[] = '已迁移 ' . $stats['lost_and_found_moved'] . ' 条记录到 lost_and_found_history 表。';
    } else {
        $progress[] = '没有符合条件的 lost_and_found 记录需要迁移到 lost_and_found_history 表。';
    }

    /**
     * 迁移相关的 'volunteer_clues' 到 'volunteer_clues_history' table
     */
    if (!empty($idsToDeleteLnF)) {
        // 选择与迁移的 'lost_and_found' 记录相关的线索
        $sqlSelectClues = "SELECT * FROM volunteer_clues WHERE lost_info_id IN (" . implode(',', array_fill(0, count($idsToDeleteLnF), '?')) . ")";
        $stmtSelectClues = $pdo->prepare($sqlSelectClues);
        $stmtSelectClues->execute($idsToDeleteLnF);
        $rowsClues = $stmtSelectClues->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rowsClues)) {
            // 获取 'volunteer_clues' 表的列名
            $columnsStmtClues = $pdo->query("DESCRIBE volunteer_clues");
            $columnsClues = $columnsStmtClues->fetchAll(PDO::FETCH_COLUMN);
            $columnsListClues = implode(', ', $columnsClues);

            // 准备插入到 'volunteer_clues_history' 的占位符
            $placeholdersClues = '(' . implode(', ', array_fill(0, count($columnsClues), '?')) . ')';

            // 准备插入语句
            $sqlInsertClues = "INSERT INTO volunteer_clues_history ($columnsListClues) VALUES $placeholdersClues";
            $stmtInsertClues = $pdo->prepare($sqlInsertClues);

            // 数组用于存储需要删除的线索ID
            $idsToDeleteClues = [];

            foreach ($rowsClues as $clue) {
                // 准备插入数据
                $clueData = [];
                foreach ($columnsClues as $column) {
                    $clueData[] = isset($clue[$column]) ? $clue[$column] : null;
                }
                $stmtInsertClues->execute($clueData);

                // 收集线索ID用于删除和报告
                $idsToDeleteClues[] = $clue['id'];
                $stats['volunteer_clues_moved']++;
                $stats['details']['volunteer_clues_ids'][] = $clue['id'];
            }

            // 删除原表中的线索记录
            $idsPlaceholderClues = implode(', ', array_fill(0, count($idsToDeleteClues), '?'));
            $sqlDeleteClues = "DELETE FROM volunteer_clues WHERE id IN ($idsPlaceholderClues)";
            $stmtDeleteClues = $pdo->prepare($sqlDeleteClues);
            $stmtDeleteClues->execute($idsToDeleteClues);

            $progress[] = '已迁移 ' . $stats['volunteer_clues_moved'] . ' 条记录到 volunteer_clues_history 表。';
        } else {
            $progress[] = '没有符合条件的 volunteer_clues 记录需要迁移到 volunteer_clues_history 表。';
        }
    }

    /**
     * 迁移 from 'requests' to 'history' table
     */

    // SQL 查询: 从 'requests' 表中选择 auth = 'yes' 且时间早于当前时间的记录
    $sqlSelectRequests = "SELECT * FROM requests WHERE auth != 'non' AND SUBSTRING_INDEX(time, '-', -1) < :currentTime";
    $stmtSelectRequests = $pdo->prepare($sqlSelectRequests);
    $currentTime = time() * 1000; // 当前时间（毫秒）
    $stmtSelectRequests->execute([':currentTime' => $currentTime]);
    $rowsRequests = $stmtSelectRequests->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rowsRequests)) {
        // 获取 'history' 表的列名
        $columnsStmtHistory = $pdo->query("DESCRIBE history");
        $columnsHistory = $columnsStmtHistory->fetchAll(PDO::FETCH_COLUMN);
        $columnsListHistory = implode(', ', $columnsHistory);

        // 准备插入到 'history' 的占位符
        $placeholdersHistory = '(' . implode(', ', array_fill(0, count($columnsHistory), '?')) . ')';

        // 准备插入语句
        $sqlInsertHistory = "INSERT INTO history ($columnsListHistory) VALUES $placeholdersHistory";
        $stmtInsertHistory = $pdo->prepare($sqlInsertHistory);

        // 数组用于存储需要删除的ID
        $idsToDeleteRequests = [];

        foreach ($rowsRequests as $row) {
            // 准备插入数据
            $rowData = [];
            foreach ($columnsHistory as $column) {
                $rowData[] = isset($row[$column]) ? $row[$column] : null;
            }
            $stmtInsertHistory->execute($rowData);

            // 收集ID用于删除和报告
            $idsToDeleteRequests[] = $row['id'];
            $stats['requests_moved']++;
            $stats['details']['requests_ids'][] = $row['id'];
        }

        // 删除原表中的记录
        $idsPlaceholderRequests = implode(', ', array_fill(0, count($idsToDeleteRequests), '?'));
        $sqlDeleteRequests = "DELETE FROM requests WHERE id IN ($idsPlaceholderRequests)";
        $stmtDeleteRequests = $pdo->prepare($sqlDeleteRequests);
        $stmtDeleteRequests->execute($idsToDeleteRequests);

        $progress[] = '已迁移 ' . $stats['requests_moved'] . ' 条记录到 history 表。';
    } else {
        $progress[] = '没有符合条件的 requests 记录需要迁移到 history 表。';
    }

    /**
     * 迁移 from 'repair_requests' to 'repair_requests_history' table
     */

    // 获取一周前的时间
    $oneWeekAgo = date('Y-m-d H:i:s', strtotime('-1 week'));

    // SQL 查询: 从 'repair_requests' 表中选择 status IN (1,2,3) 且 addTime < 一周前的记录
    $sqlSelectRepair = "SELECT * FROM repair_requests WHERE status IN (1,2,3) AND addTime < :oneWeekAgo";
    $stmtSelectRepair = $pdo->prepare($sqlSelectRepair);
    $stmtSelectRepair->execute([':oneWeekAgo' => $oneWeekAgo]);
    $rowsRepair = $stmtSelectRepair->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rowsRepair)) {
        // 获取 'repair_requests' 表的列名
        $columnsStmtRepair = $pdo->query("DESCRIBE repair_requests");
        $columnsRepair = $columnsStmtRepair->fetchAll(PDO::FETCH_COLUMN);
        $columnsListRepair = implode(', ', $columnsRepair);

        // 准备插入到 'repair_requests_history' 的占位符
        $placeholdersRepair = '(' . implode(', ', array_fill(0, count($columnsRepair), '?')) . ')';

        // 准备插入语句
        $sqlInsertRepair = "INSERT INTO repair_requests_history ($columnsListRepair) VALUES $placeholdersRepair";
        $stmtInsertRepair = $pdo->prepare($sqlInsertRepair);

        // 数组用于存储需要删除的ID
        $idsToDeleteRepair = [];

        foreach ($rowsRepair as $row) {
            // 准备插入数据
            $rowData = [];
            foreach ($columnsRepair as $column) {
                $rowData[] = isset($row[$column]) ? $row[$column] : null;
            }
            $stmtInsertRepair->execute($rowData);

            // 收集ID用于删除和报告
            $idsToDeleteRepair[] = $row['id'];
            $stats['repair_requests_moved']++;
            $stats['details']['repair_requests_ids'][] = $row['id'];
        }

        // 删除原表中的记录
        $idsPlaceholderRepair = implode(', ', array_fill(0, count($idsToDeleteRepair), '?'));
        $sqlDeleteRepair = "DELETE FROM repair_requests WHERE id IN ($idsPlaceholderRepair)";
        $stmtDeleteRepair = $pdo->prepare($sqlDeleteRepair);
        $stmtDeleteRepair->execute($idsToDeleteRepair);

        $progress[] = '已迁移 ' . $stats['repair_requests_moved'] . ' 条记录到 repair_requests_history 表。';
    } else {
        $progress[] = '没有符合条件的 repair_requests 记录需要迁移到 repair_requests_history 表。';
    }

    // 提交事务
    $pdo->commit();

    // 记录脚本结束时间
    $scriptEndTime = microtime(true);
    $scriptDuration = $scriptEndTime - $scriptStartTime;

    /**
     * 生成清理报告
     */
    // 准备报告内容
    $reportDate = date('Y-m-d H:i:s');
    // 准备变量
    $escapedReportDate = htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8');
    $formattedScriptDuration = number_format($scriptDuration, 2);
    $escapedExecutionIp = htmlspecialchars($executionIp, ENT_QUOTES, 'UTF-8');
    $escapedExecutionUa = htmlspecialchars($executionUa, ENT_QUOTES, 'UTF-8');
    $escapedEmailAccount = htmlspecialchars($email_account, ENT_QUOTES, 'UTF-8');

    $lostAndFoundIds = !empty($stats['details']['lost_and_found_ids']) ? implode(', ', $stats['details']['lost_and_found_ids']) : '无';
    $volunteerCluesIds = !empty($stats['details']['volunteer_clues_ids']) ? implode(', ', $stats['details']['volunteer_clues_ids']) : '无';
    $requestsIds = !empty($stats['details']['requests_ids']) ? implode(', ', $stats['details']['requests_ids']) : '无';
    $repairRequestsIds = !empty($stats['details']['repair_requests_ids']) ? implode(', ', $stats['details']['repair_requests_ids']) : '无';

    // 获取当前年份
    $currentYear = date('Y');

    // 使用 HEREDOC 语法美化 $reportBody
    $reportBody = <<<EOD
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>数据清理报告</title>
    </head>
    <body style="margin:0; padding:0; background-color:#f7f7f7;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td align="center" style="padding:20px 0;">
                    <table width="800" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                        <!-- Header -->
                        <tr>
                            <td style="background-color:#4CAF50; padding:20px; text-align:center;">
                                <h1 style="margin:0; font-size:24px; color:#ffffff;">数据清理报告</h1>
                            </td>
                        </tr>
                        <!-- Body -->
                        <tr>
                            <td style="padding:30px; font-family: Arial, sans-serif; color:#333333;">
                                <p style="font-size:16px; line-height:1.6;">
                                    你好，<br><br>
                                    数据清理过程已于 <strong>{$escapedReportDate}</strong> 成功完成。以下是迁移的详细统计和信息：
                                </p>
                                
                                <!-- Migration Summary -->
                                <h2 style="color:#2196F3; font-size:20px; border-bottom:2px solid #2196F3; padding-bottom:5px;">迁移摘要</h2>
                                <table width="100%" cellpadding="10" cellspacing="0" border="0" style="border-collapse:collapse; margin-top:10px;">
                                    <tr style="background-color:#f1f1f1;">
                                        <th style="border:1px solid #ddd; text-align:left;">表</th>
                                        <th style="border:1px solid #ddd; text-align:left;">迁移记录数</th>
                                    </tr>
                                    <tr>
                                        <td style="border:1px solid #ddd;">lost_and_found &rarr; lost_and_found_history</td>
                                        <td style="border:1px solid #ddd;">{$stats['lost_and_found_moved']}</td>
                                    </tr>
                                    <tr style="background-color:#f9f9f9;">
                                        <td style="border:1px solid #ddd;">volunteer_clues &rarr; volunteer_clues_history</td>
                                        <td style="border:1px solid #ddd;">{$stats['volunteer_clues_moved']}</td>
                                    </tr>
                                    <tr>
                                        <td style="border:1px solid #ddd;">requests &rarr; history</td>
                                        <td style="border:1px solid #ddd;">{$stats['requests_moved']}</td>
                                    </tr>
                                    <tr style="background-color:#f9f9f9;">
                                        <td style="border:1px solid #ddd;">repair_requests &rarr; repair_requests_history</td>
                                        <td style="border:1px solid #ddd;">{$stats['repair_requests_moved']}</td>
                                    </tr>
                                </table>
                                
                                <!-- Detailed Information -->
                                <h2 style="color:#2196F3; font-size:20px; border-bottom:2px solid #2196F3; padding-bottom:5px; margin-top:30px;">详细信息</h2>
                                <ul style="list-style-type:none; padding:0;">
                                    <li style="margin-bottom:10px;"><strong>lost_and_found 迁移记录数:</strong> {$stats['lost_and_found_moved']}</li>
                                    <li style="margin-bottom:10px;"><strong>volunteer_clues 迁移记录数:</strong> {$stats['volunteer_clues_moved']}</li>
                                    <li style="margin-bottom:10px;"><strong>requests 迁移记录数:</strong> {$stats['requests_moved']}</li>
                                    <li style="margin-bottom:10px;"><strong>repair_requests 迁移记录数:</strong> {$stats['repair_requests_moved']}</li>
                                    <li style="margin-bottom:10px;"><strong>脚本执行时间:</strong> {$formattedScriptDuration} 秒</li>
                                    <li style="margin-bottom:10px;"><strong>执行IP:</strong> {$escapedExecutionIp}</li>
                                    <li style="margin-bottom:10px;"><strong>User-Agent:</strong> {$escapedExecutionUa}</li>
                                </ul>
                                
                                <!-- Migration IDs -->
                                <h2 style="color:#2196F3; font-size:20px; border-bottom:2px solid #2196F3; padding-bottom:5px; margin-top:30px;">迁移记录ID</h2>
                                
                                <div style="margin-top:10px;">
                                    <h3 style="font-size:16px; color:#555555;">lost_and_found &rarr; lost_and_found_history</h3>
                                    <p style="background-color:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">{$lostAndFoundIds}</p>
                                    
                                    <h3 style="font-size:16px; color:#555555; margin-top:20px;">volunteer_clues &rarr; volunteer_clues_history</h3>
                                    <p style="background-color:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">{$volunteerCluesIds}</p>
                                    
                                    <h3 style="font-size:16px; color:#555555; margin-top:20px;">requests &rarr; history</h3>
                                    <p style="background-color:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">{$requestsIds}</p>
                                    
                                    <h3 style="font-size:16px; color:#555555; margin-top:20px;">repair_requests &rarr; repair_requests_history</h3>
                                    <p style="background-color:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">{$repairRequestsIds}</p>
                                </div>
                                
                                <!-- Footer -->
                                <p style="font-size:14px; color:#888888; margin-top:30px;">
                                    此报告提供了数据清理过程的全面摘要。请保留此报告以备记录和将来参考。
                                </p>
                                <div style="text-align: center; margin-top:20px;">
                                    <a href="mailto:{$escapedEmailAccount}" style="background-color:#4CAF50; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:5px; font-weight:bold;">联系我们</a>
                                </div>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style="background-color:#f1f1f1; padding:15px; text-align:center; font-size:12px; color:#777777;">
                                &copy; {$currentYear} HFI-UC. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
EOD;

    /**
     * 导出迁移数据为 XLSX 附件
     */
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial Unicode MS')->setSize(11); // 设置字体支持中文

    /**
     * 添加 lost_and_found_history 数据
     */
    if (!empty($stats['details']['lost_and_found_ids'])) {
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('lost_and_found_history');

        // 设置表头
        $headers = [
            'A1' => 'ID',
            'B1' => '类型',
            'C1' => '学生姓名',
            'D1' => '详细信息',
            'E1' => '位置',
            'F1' => '邮箱',
            'G1' => '备用联系方式',
            'H1' => '校区',
            'I1' => '文件路径',
            'J1' => '奖励',
            'K1' => '状态',
            'L1' => '最后更新时间',
            'M1' => '密码',
            'N1' => '创建时间',
        ];

        foreach ($headers as $cell => $header) {
            $sheet1->setCellValue($cell, $header);
        }

        // 查询迁移的数据
        $sqlExportLnF = "SELECT * FROM lost_and_found_history WHERE id IN (" . implode(',', array_fill(0, count($stats['details']['lost_and_found_ids']), '?')) . ")";
        $stmtExportLnF = $pdo->prepare($sqlExportLnF);
        $stmtExportLnF->execute($stats['details']['lost_and_found_ids']);
        $exportLnF = $stmtExportLnF->fetchAll(PDO::FETCH_ASSOC);

        // 填充数据
        $rowNum = 2;
        foreach ($exportLnF as $row) {
            $sheet1->setCellValue("A$rowNum", $row['id']);
            $sheet1->setCellValue("B$rowNum", $row['type']);
            $sheet1->setCellValue("C$rowNum", $row['student_name']);
            $sheet1->setCellValue("D$rowNum", $row['detail']);
            $sheet1->setCellValue("E$rowNum", $row['location']);
            $sheet1->setCellValue("F$rowNum", $row['email']);
            $sheet1->setCellValue("G$rowNum", $row['alt_contact']);
            $sheet1->setCellValue("H$rowNum", convertCampus($row['campus']));
            $sheet1->setCellValue("I$rowNum", $row['file_path']);
            $sheet1->setCellValue("J$rowNum", $row['reward']);

            // 根据 is_found 值显示对应的状态
            $isFoundStatus = '';
            switch ($row['is_found']) {
                case 0:
                    $isFoundStatus = '丢失';
                    break;
                case 1:
                    $isFoundStatus = '已解决';
                    break;
                case 2:
                    $isFoundStatus = '尚未验证线索';
                    break;
                case 3:
                    $isFoundStatus = '隐藏';
                    break;
                default:
                    $isFoundStatus = '未知';
                    break;
            }

            $sheet1->setCellValue("K$rowNum", $isFoundStatus);
            $sheet1->setCellValue("L$rowNum", $row['last_updated']);
            $sheet1->setCellValue("M$rowNum", $row['password']);
            $sheet1->setCellValue("N$rowNum", $row['created_at']);
            $rowNum++;
        }

        // 自动调整列宽
        foreach (range('A', 'N') as $columnID) {
            $sheet1->getColumnDimension($columnID)->setAutoSize(true);
        }

        $progress[] = '已添加 lost_and_found_history 数据到 Excel。';
    }

    /**
     * 添加 volunteer_clues_history 数据
     */
    if (!empty($stats['details']['volunteer_clues_ids'])) {
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('volunteer_clues_history');

        // 设置表头
        $headers = [
            'A1' => 'ID',
            'B1' => '校区',
            'C1' => '详细信息',
            'D1' => '位置',
            'E1' => '文件路径',
            'F1' => '联系方式',
            'G1' => '失物招领ID',
            'H1' => '创建时间',
        ];

        foreach ($headers as $cell => $header) {
            $sheet2->setCellValue($cell, $header);
        }

        // 查询迁移的数据
        $sqlExportClues = "SELECT * FROM volunteer_clues_history WHERE id IN (" . implode(',', array_fill(0, count($stats['details']['volunteer_clues_ids']), '?')) . ")";
        $stmtExportClues = $pdo->prepare($sqlExportClues);
        $stmtExportClues->execute($stats['details']['volunteer_clues_ids']);
        $exportClues = $stmtExportClues->fetchAll(PDO::FETCH_ASSOC);

        // 填充数据
        $rowNum = 2;
        foreach ($exportClues as $clue) {
            $sheet2->setCellValue("A$rowNum", $clue['id']);
            $sheet2->setCellValue("B$rowNum", convertCampus($clue['campus']));
            $sheet2->setCellValue("C$rowNum", $clue['detail']);
            $sheet2->setCellValue("D$rowNum", $clue['location']);
            $sheet2->setCellValue("E$rowNum", $clue['file_path']);
            $sheet2->setCellValue("F$rowNum", $clue['contact']);
            $sheet2->setCellValue("G$rowNum", $clue['lost_info_id']);
            $sheet2->setCellValue("H$rowNum", $clue['created_at']);
            $rowNum++;
        }

        // 自动调整列宽
        foreach (range('A', 'H') as $columnID) {
            $sheet2->getColumnDimension($columnID)->setAutoSize(true);
        }

        $progress[] = '已添加 volunteer_clues_history 数据到 Excel。';
    }

    /**
     * 处理 requests 数据并按教室分类
     */
    if (!empty($stats['details']['requests_ids'])) {
        // 选择需要导出的 requests 数据
        $sqlExportHistory = "SELECT * FROM history WHERE id IN (" . implode(',', array_fill(0, count($stats['details']['requests_ids']), '?')) . ")";
        $stmtExportHistory = $pdo->prepare($sqlExportHistory);
        $stmtExportHistory->execute($stats['details']['requests_ids']);
        $exportHistory = $stmtExportHistory->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($exportHistory)) {
            // 按教室分类 requests 数据
            $requestsByClassroom = [];
            foreach ($exportHistory as $request) {
                $room = $request['room'];
                $requestsByClassroom[$room][] = $request;
            }

            foreach ($requestsByClassroom as $room => $roomRequests) {
                // 创建新的工作表
                $sheet = $spreadsheet->createSheet();
                $converted_room = convertRoom($room);
                if ($converted_room == $room) {
                    $sheet->setTitle("Room $converted_room");
                } else {
                    $sheet->setTitle("$converted_room");
                }

                // 设置表头
                $headers = [
                    'A1' => 'ID',
                    'B1' => '房间号',
                    'C1' => '邮箱',
                    'D1' => '审批状态',
                    'E1' => '时间',
                    'F1' => '姓名',
                    'G1' => '原因',
                    'H1' => '学号',
                    'I1' => '添加时间',
                    'J1' => '操作员',
                ];

                foreach ($headers as $cell => $header) {
                    $sheet->setCellValue($cell, $header);
                }

                // 填充数据
                $rowNum = 2;
                foreach ($roomRequests as $request) {
                    $sheet->setCellValue("A$rowNum", $request['id']);
                    $sheet->setCellValue("B$rowNum", $request['room']);
                    $sheet->setCellValue("C$rowNum", $request['email']);
                    $sheet->setCellValue("D$rowNum", formatAuth($request['auth']));
                    $sheet->setCellValue("E$rowNum", $request['time']);
                    $sheet->setCellValue("F$rowNum", $request['name']);
                    $sheet->setCellValue("G$rowNum", $request['reason']);
                    $sheet->setCellValue("H$rowNum", $request['sid']);
                    $sheet->setCellValue("I$rowNum", $request['addTime']);
                    $sheet->setCellValue("J$rowNum", $request['operator']);
                    $rowNum++;
                }

                // 自动调整列宽
                foreach (range('A', 'J') as $columnID) {
                    $sheet->getColumnDimension($columnID)->setAutoSize(true);
                }
            }

            $progress[] = '已按教室分类并添加 history 数据到 Excel。';
        }
    }

    /**
     * 添加 repair_requests_history 数据
     */
    if (!empty($stats['details']['repair_requests_ids'])) {
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle('repair_requests_history');

        // 设置表头
        $headers = [
            'A1' => 'ID',
            'B1' => '学生姓名',
            'C1' => '主题',
            'D1' => '详细信息',
            'E1' => '位置',
            'F1' => '邮箱',
            'G1' => '校区',
            'H1' => '文件路径',
            'I1' => '添加时间',
            'J1' => '状态',
        ];

        foreach ($headers as $cell => $header) {
            $sheet4->setCellValue($cell, $header);
        }

        // 查询迁移的数据
        $sqlExportRepair = "SELECT * FROM repair_requests_history WHERE id IN (" . implode(',', array_fill(0, count($stats['details']['repair_requests_ids']), '?')) . ")";
        $stmtExportRepair = $pdo->prepare($sqlExportRepair);
        $stmtExportRepair->execute($stats['details']['repair_requests_ids']);
        $exportRepair = $stmtExportRepair->fetchAll(PDO::FETCH_ASSOC);

        // 填充数据
        $rowNum = 2;
        foreach ($exportRepair as $repair) {
            $sheet4->setCellValue("A$rowNum", $repair['id']);
            $sheet4->setCellValue("B$rowNum", $repair['studentName']);
            $sheet4->setCellValue("C$rowNum", $repair['subject']);
            $sheet4->setCellValue("D$rowNum", $repair['detail']);
            $sheet4->setCellValue("E$rowNum", $repair['location']);
            $sheet4->setCellValue("F$rowNum", $repair['email']);
            $sheet4->setCellValue("G$rowNum", convertCampus($repair['campus']));
            $sheet4->setCellValue("H$rowNum", $repair['filePath']);
            $sheet4->setCellValue("I$rowNum", $repair['addTime']);

            // 根据 status 值显示对应的状态
            $statusText = '';
            switch ($repair['status']) {
                case 1:
                    $statusText = '维修完成';
                    break;
                case 2:
                    $statusText = '打回';
                    break;
                case 3:
                    $statusText = '重复报修';
                    break;
                default:
                    $statusText = '未知';
                    break;
            }

            $sheet4->setCellValue("J$rowNum", $statusText);
            $rowNum++;
        }

        // 自动调整列宽
        foreach (range('A', 'J') as $columnID) {
            $sheet4->getColumnDimension($columnID)->setAutoSize(true);
        }

        $progress[] = '已添加 repair_requests_history 数据到 Excel。';
    }

    // 设置活动工作表为第一个
    $spreadsheet->setActiveSheetIndex(0);

    // 保存XLSX文件到临时目录
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $xlsxPath = $cacheDir . '/data_cleaning_report_' . date('Ymd_His') . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($xlsxPath);

    $progress[] = '已将 Excel 文件保存到缓存目录。';

    /**
     * 发送清理报告邮件
     */
    
    try {
        // 初始化邮件对象
        $mail = initializeMailer();

        // 设置收件人邮箱（使用您的邮箱）
        $adminEmail = 'jeffery.lui@foxmail.com'; // 请替换为实际的管理员邮箱

        $mail->addAddress($adminEmail, '管理员');
        $mail->addBCC('joannaliang@gdhfi.com', 'Observation'); // 可选的密送

        $progress[] = '已添加收件人。';

        // 邮件主题
        $mail->Subject = '数据清理报告 - ' . date('Y-m-d');
        $mail->isHTML(true);

        // 使用 $reportBody 作为邮件内容
        $mail->Body = $reportBody;

        // 添加附件
        $mail->addAttachment($xlsxPath, 'Data_Cleaning_Report.xlsx');

        $progress[] = '已添加附件。';

        // 发送邮件并实现重试机制
        $maxRetries = 3;
        $attempt = 0;
        $sent = false;

        while ($attempt < $maxRetries && !$sent) {
            try {
                $mail->send();
                $sent = true;
                $progress[] = '邮件发送成功。';
                echo json_encode(['success' => true, 'message' => '数据迁移和报告发送成功。', 'progress' => $progress]);
            } catch (Exception $e) {
                $attempt++;
                $progress[] = '邮件发送尝试 ' . $attempt . ' 失败: ' . $mail->ErrorInfo;
                if ($attempt < $maxRetries) {
                    sleep(5); // 等待5秒后重试
                } else {
                    throw $e; // 达到最大重试次数后抛出异常
                }
            }
        }

        // 删除临时文件
        if (file_exists($xlsxPath)) {
            unlink($xlsxPath);
            $progress[] = '已删除临时文件。';
        }

    } catch (Exception $e) {
        // 记录邮件发送失败的错误，但不影响主流程
        error_log('发送清理报告邮件失败: ' . $e->getMessage());
        $progress[] = '发送清理报告邮件失败: ' . $e->getMessage();
        echo json_encode(['success' => false, 'message' => '发送清理报告邮件失败。', 'progress' => $progress]);
    }

    /**
     * 发送成功响应
     */
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Data migrated and a report has been sent.', 'progress' => $progress]);
} catch (Exception $e) {
    // 回滚事务
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // 记录异常日志
    error_log('迁移失败: ' . $e->getMessage());
    customExceptionHandler($e);
    // 发送错误响应
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error.', 'progress' => $e->getMessage()]);
}
?>
