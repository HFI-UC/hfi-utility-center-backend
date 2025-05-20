# 公告管理功能 - 草稿

## 1. 数据库设计 (参考 uc.sql)

*   **表名**: `announcements`
*   **字段**:
    *   `id` (INT, PK, AI)
    *   `title` (VARCHAR(255), NOT NULL) - 公告标题
    *   `content` (TEXT, NOT NULL) - 公告内容 (Quill Delta 格式)
    *   `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
    *   `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
    *   `created_by` (VARCHAR(255)) - 创建人 (从会话或 token 中获取)
    *   `status` (ENUM('draft', 'published', 'archived'), DEFAULT 'published') - 公告状态

## 2. API 接口设计

### 通用部分

*   **认证**: 参考 `accept.php` 的逻辑，校验用户登录状态和权限。
*   **依赖**: `global_error_handler.php`, `global_variables.php`, `db.php`
*   **响应格式**: JSON

    ```json
    {
        "success": true/false,
        "message": "...",
        "data": {} // (可选)
    }
    ```

### 2.1 添加公告 (`add_announcement.php`)

*   **方法**: POST
*   **请求参数**:
    *   `title` (string, required): 公告标题
    *   `content` (string, required): 公告内容 (Quill Delta 格式)
    *   `status` (string, optional, default: 'published'): 公告状态 ('draft', 'published')
*   **逻辑**:
    1.  引入依赖和认证。
    2.  获取当前登录用户名。
    3.  校验参数。
    4.  将数据插入 `announcements` 表。
    5.  返回成功或失败信息。

### 2.2 编辑公告 (`edit_announcement.php`)

*   **方法**: POST
*   **请求参数**:
    *   `id` (int, required): 公告ID
    *   `title` (string, optional): 公告标题
    *   `content` (string, optional): 公告内容 (Quill Delta 格式)
    *   `status` (string, optional): 公告状态 ('draft', 'published', 'archived')
*   **逻辑**:
    1.  引入依赖和认证。
    2.  校验参数。
    3.  检查公告是否存在且用户有权限编辑 (可选，看具体需求，通常创建者或管理员可编辑)。
    4.  更新 `announcements` 表中对应记录。
    5.  返回成功或失败信息。

### 2.3 获取公告列表 (`get_announcements.php`)

*   **方法**: GET
*   **请求参数**:
    *   `status` (string, optional, default: 'published'): 筛选公告状态 (e.g., 'published', 'all')
    *   `page` (int, optional, default: 1): 页码
    *   `limit` (int, optional, default: 10): 每页数量
    *   `sort_by` (string, optional, default: 'created_at'): 排序字段
    *   `sort_order` (string, optional, default: 'DESC'): 排序方式 (ASC/DESC)
*   **逻辑**:
    1.  引入依赖和认证 (根据是否所有人都可查看，认证可能是可选的，但建议保留)。
    2.  根据参数构建 SQL 查询。
    3.  查询 `announcements` 表 (通常只查询 `published` 状态的，除非有特殊参数)。
    4.  返回公告列表 (包含分页信息，如总数、当前页等)。

## 3. 数据库更新脚本 (`update_announcements.sql`)

```sql
-- 创建 announcements 表
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `created_by` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft', 'published', 'archived') DEFAULT 'published',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 可选：添加索引
ALTER TABLE `announcements` ADD INDEX `idx_status` (`status`);
ALTER TABLE `announcements` ADD INDEX `idx_created_at` (`created_at`);
ALTER TABLE `announcements` ADD INDEX `idx_created_by` (`created_by`);

-- 可选：示例数据
-- INSERT INTO `announcements` (`title`, `content`, `created_by`, `status`) VALUES
-- ('系统维护通知', '{"ops":[{"insert":"亲爱的用户，\n为了提升服务质量，我们计划于2024年8月1日凌晨2:00至4:00进行系统维护。届时相关服务可能暂停。不便之处，敬请谅解。\n"}]}', 'admin', 'published'),
-- ('新功能上线！', '{"ops":[{"insert":"激动人心的消息！我们的新功能【XX】已正式上线，快来体验吧！\n"}]}', 'system', 'published');
```

## 4. 文件结构 (暂定 api/ 目录下)

```
api/
├── announcements/
│   ├── add_announcement.php
│   ├── edit_announcement.php
│   └── get_announcements.php
├── global_error_handler.php (已存在或按需创建)
├── global_variables.php (已存在或按需创建)
├── db.php (已存在或按需创建)
└── accept.php (已存在，用于参考)
```

## 5. Quill 内容处理

*   **存储**: 直接存储 Quill 生成的 JSON 字符串 (Delta 格式)。
*   **展示**: 前端使用 Quill 渲染 Delta。
*   **安全性**:
    *   后端在接收到 Quill 内容时，不直接将其拼接到 HTML 中。
    *   如果需要在后端对内容进行某些处理或展示（例如生成摘要），应使用 HTML Purifier 或类似库对 Quill Delta 转换后的 HTML 进行清理，以防止 XSS。由于我们只是存储和透传，后端主要关注参数校验。前端渲染时 Quill 自身有一定安全机制，但仍需注意。

## 后续步骤
1.  确认 `global_*.php` 和 `db.php` 文件是否存在及位置。
2.  确认 `accept.php` 的具体认证逻辑。
3.  编写 `update_announcements.sql`。
4.  逐个实现三个 API 接口。
