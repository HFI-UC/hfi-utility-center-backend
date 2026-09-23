# HFI Utility Center PHP 后端 Spec

版本：2.0.0  
状态：待确认后实现  
日期：2026-09-23  
配套：`docs/PRD.md`、`docs/openapi.yaml`

## 1. 运行环境

| 组件 | 版本 | 约束 |
| --- | --- | --- |
| PHP | 8.4 | `strict_types=1`。扩展：`pdo_mysql`、`json`、`mbstring`、`openssl`、`curl`、`zip` |
| MySQL | 5.6.51 | 只用 InnoDB。不能使用 JSON 列、CTE、窗口函数、`CHECK` 强制、`SKIP LOCKED`、生成列 |
| Apache | 2.4.54 | 站点根目录是 `public/`。PHP 通过 PHP-FPM 运行，Apache 用 `mod_proxy_fcgi` |

许可证延续 AGPL-3.0。Rust 代码保留在仓库里，用来核对业务结果，直到 PHP 切换完成后再决定是否移除。

## 2. 业务等价

下面这些结果必须和 Rust 相同。比较的是数据库里的领域状态和是否产生副作用，不是响应字符串是否逐字相同。

- 路径、方法、查询参数名、JSON 字段名保持不变，现有前端可以继续调用。
- 请求中的 Unix 秒先转成 `Asia/Shanghai` 墙钟，再写入数据库。响应里的业务时间不带时区。
- 预约规则、状态机、每日限额、冲突区间、特权预约、修改次数、审批条件与 `docs/PRD.md` 一致。
- 未登录调用 `GET /reservation/get` 时，`studentId` 和 `email` 为 `null`，范围仍是全部预约。
- 已登录管理员只能看到并处理 `roomapprover` 中属于自己的教室。`latestExecutorId` 只记录最近一次操作者，不参与授权。同一教室的其他管理员在别人审批之后仍然可见、可审批、可修改。详见 `docs/PRD.md` 第 5.4 节。这是相对 Rust 的产品变更。
- cookie 名仍是 `uc`。登录时先清除旧的 `SameSite=None; Partitioned` cookie，再设置 `Path=/; HttpOnly; Secure; SameSite=Lax`。会话 1 小时过期。
- 取消 token 明文不入库。入库值是 SHA-256 的小写十六进制。
- 密码使用 bcrypt，cost 12。导入的旧哈希若是 `$2b$`，`password_verify` 必须能通过。新密码使用 `PASSWORD_BCRYPT`。
- 邮件和 AI 任务的种类、入队条件、最多 8 次重试、退避上限 300 秒保持不变。

`GET /healthz` 的 `data.status` 仍是 `ok`，`data.time` 仍是 UTC RFC3339，`data.service` 改为 `hfiuc-php`。实现时同步改 `docs/openapi.yaml` 里该字段的枚举。

## 3. 可以不同的响应

- 错误 `message` 用英文说明同一个业务原因即可，不必复制 Rust 原句。
- 坏 JSON、缺字段、类型不匹配、错误 `Content-Type`、查询参数无法解析，仍然拒绝。状态码保持 400、422、415、400。正文可以使用 JSON 信封，不必模仿 Axum 的 `text/plain`。
- 未知 JSON 字段继续忽略。

内部实现可以不同，只要第 2 节的业务结果不变：

| Rust | PHP / MySQL |
| --- | --- |
| CSRF 存在进程内 `HashMap`，10 分钟、用一次即删 | 表 `csrftoken`。签发插入，校验时 `DELETE` 命中且未过期才算有效 |
| 目录缓存在进程内存 | 表 `catalogcache`。`POST /catalog/invalidate` 删除这些行 |
| `pg_advisory_xact_lock(roomId)` | 同一 PDO 连接上 `GET_LOCK('hfiuc_room_{id}', 5)`，事务结束或连接关闭时 `RELEASE_LOCK` |
| `FOR UPDATE SKIP LOCKED` 领取 outbox | `UPDATE ... ORDER BY id LIMIT 1` 写入 `lockToken`，再按 token 读出该行 |
| PostgreSQL `json` / `jsonb` / 数组 | `TEXT`，内容是 JSON 文本 |
| `timestamp without time zone` | `DATETIME`。连接建立后执行 `SET time_zone = '+08:00'` |
| `ILIKE` | 在 `utf8mb4_unicode_ci` 下使用 `LIKE`。keyword 里的 `%` 和 `_` 仍是通配符 |
| `now()` 取决于 PostgreSQL 时区 | 会话时区固定为上海。因此“今天”和 `endTime > now()` 按上海日历日计算 |

本地 HTTP 调试时，浏览器会丢弃 `Secure` cookie。增加配置 `COOKIE_SECURE`，生产为 `true`，本地 http 可以为 `false`。生产部署不得关闭。

PDO 禁止持久连接。`GET_LOCK` 跟连接绑定，持久连接会把房间锁泄漏给下一个请求。

## 4. 工程结构

```
php/
  composer.json
  config/config.php          # 只读环境变量，仓库不提交密钥
  public/index.php           # 唯一入口
  public/.htaccess
  public/index.html
  public/assets/
  bin/worker.php             # CLI，不经过 Apache
  sql/001_schema.sql
  src/Bootstrap.php
  src/Http/FrontController.php
  src/Http/Request.php
  src/Http/Response.php
  src/Http/Cors.php
  src/Auth/
  src/Catalog/
  src/Reservation/
  src/Announcement/
  src/Analytics/
  src/Worker/
  tests/
```

不引入 Laravel 或 Symfony 全框架。HTTP 层自己做路由、JSON 校验和信封。Composer 依赖只保留 PHPMailer（SMTP）和 PhpSpreadsheet（xlsx）。

## 5. HTTP

Apache 把非文件请求重写到 `public/index.php`。`/assets/` 由 Apache 直接发送，并加上：

`Cache-Control: public, max-age=31536000, immutable`

应用自己处理 CORS，允许的来源与 `src/app.rs` 相同：`FRONTEND_URL`、`https://hfiuc.org`、`https://www.hfiuc.org`、`https://preview.hfiuc.org`、`https://neo.hfiuc.org`，以及 localhost / 127.0.0.1 的 3000、5173、5174。允许方法只有 `GET`、`POST`、`OPTIONS`。允许头为 `Accept`、`Content-Type`、`x-csrf-token`、`x-request-id`。`Allow-Credentials: true`。

每个响应带 `x-request-id`。传入的合法值沿用，否则生成 UUID。

POST JSON 的处理顺序：

1. `Content-Type` 不是 `application/json`：415。
2. 正文不是合法 JSON：400。
3. 按该路由的必填字段和类型检查：失败则 422。整数拒绝字符串数字。多余字段丢弃。
4. 进入业务规则。

这些拒绝和业务失败都使用 JSON 信封。查询参数缺失或类型不对返回 400。

## 6. 数据模型

表名全小写。列名使用驼峰，SQL 里始终加反引号，以匹配现有 JSON 和 PostgreSQL 列，并避免 Windows 上 `lower_case_table_names=1` 的大小写问题。字符集 `utf8mb4`，排序规则 `utf8mb4_unicode_ci`。

索引列如果是 `VARCHAR`，长度不超过 191，以便在未开启 `innodb_large_prefix` 时仍能建成唯一索引。

### 6.1 目录

- `campus`：`id` INT AUTO_INCREMENT，`name` VARCHAR(191) NOT NULL，`isPrivileged` TINYINT(1) NOT NULL DEFAULT 0，`createdAt` DATETIME NULL
- `class`：`id`，`name` VARCHAR(191) NOT NULL，`campusId` INT NULL，`createdAt` DATETIME NULL。外键指向 `campus`，删除校区时拒绝（与当前未声明 `ON DELETE CASCADE` 的行为一致，有引用则删除失败并返回现有错误文案）
- `room`：`id`，`name` VARCHAR(191) NOT NULL，`campusId` INT NULL，`enabled` TINYINT(1) NULL，`createdAt` DATETIME NULL
- `roompolicy`：`id`，`roomId` INT NOT NULL，`days` TEXT NOT NULL，`startTime` TEXT NOT NULL，`endTime` TEXT NOT NULL，`enabled` TINYINT(1) NOT NULL DEFAULT 1
  - `days` 示例：`[1,3,5]`
  - `startTime` / `endTime` 示例：`[8,0]`、`[21,30]`
- `roomapprover`：`roomId` INT NOT NULL，`adminId` INT NOT NULL。一名管理员可对应多间教室，一间教室可对应多名管理员。重复行按“存在即有权限”处理，不把重复次数当成更高权限。索引 `(adminId, roomId)` 和 `(roomId, adminId)`。本阶段没有分配接口。

### 6.2 预约

`reservation`：

| 列 | 类型 | 说明 |
| --- | --- | --- |
| `id` | INT AUTO_INCREMENT | |
| `roomId` | INT NULL | 历史行可空 |
| `classId` | INT NULL | 历史行和新请求都可空 |
| `startTime` / `endTime` | DATETIME NOT NULL | 上海墙钟 |
| `studentName` | VARCHAR(191) NOT NULL | |
| `studentId` | VARCHAR(32) NULL | 特权预约写入 `-` |
| `email` | VARCHAR(191) NOT NULL | |
| `reason` | TEXT NOT NULL | |
| `status` | VARCHAR(16) NOT NULL | `pending`、`approved`、`rejected`、`cancelled` |
| `purposeType` | VARCHAR(16) NULL | |
| `needsMultimedia` | TINYINT(1) NOT NULL DEFAULT 0 | |
| `editCount` | INT NOT NULL DEFAULT 0 | |
| `latestExecutorId` | INT NULL | 审计字段。审批、管理员修改、特权取消和 AI 写回时更新。授权不读取它 |
| `cancelledAt` | DATETIME NULL | |
| `createdAt` | DATETIME NOT NULL | 默认上海当前时间 |

索引：`(roomId, status, startTime, endTime)`、`(email, createdAt)`、`(status, id)`。MySQL 5.6 没有部分索引，不建 Rust 迁移里那条只覆盖 `pending` / `approved` 的索引。

`reservationcanceltoken`：`tokenHash` CHAR(64) UNIQUE，`expiresAt` DATETIME NOT NULL，`usedAt` DATETIME NULL，`reservationId` 外键 `ON DELETE CASCADE`。

`reservationoperationlog`：`adminId` NULL，`reservationId`，`operation` VARCHAR(64)，`reason` TEXT NULL，`createdAt` DATETIME。

`outboxjob`：`id` BIGINT AUTO_INCREMENT，`kind` VARCHAR(64)，`payload` TEXT，`status` VARCHAR(16) DEFAULT `pending`，`attempts` INT DEFAULT 0，`availableAt` DATETIME，`lockedAt` DATETIME NULL，`lockToken` CHAR(36) NULL，`lastError` TEXT NULL，`createdAt`，`completedAt`。索引 `(status, availableAt)`。`lockToken` 是 MySQL 领取队列所需的新增列。

### 6.3 管理员、公告、分析、运行时

- `admin`：`name`，`email` VARCHAR(191) UNIQUE，`password` VARCHAR(255)，`receiveReservationNotifications` TINYINT(1) NOT NULL DEFAULT 0，`createdAt`
- `adminlogin`：`email`，`cookie` VARCHAR(191) UNIQUE，`expiry` DATETIME。登录插入 `expiry = 当前上海时间 + 1 小时`
- `tempadminlogin`：`token` VARCHAR(191) UNIQUE，`email`，`createdAt`
- `announcement`：固定一行，`id` 为 1。更新使用 `INSERT ... ON DUPLICATE KEY UPDATE`
- `analytic`：`date` DATETIME，`reservations`，`reservationCreations`，`requests`，`approvals`，`rejections`。PHP 只读，供 CSV 导出
- `csrftoken`：`token` VARCHAR(191) PRIMARY KEY，`expiresAt` DATETIME。读取时顺便删除过期行
- `catalogcache`：`cacheKey` VARCHAR(32) PRIMARY KEY，`payload` MEDIUMTEXT，`updatedAt` DATETIME

外键使用 InnoDB。删除失败时映射到 Rust 现有的 404 / 500 文案，不把 MySQL 错误原文返回给客户端。

## 7. 预约并发

创建、申请人修改、管理员修改在开始校验冲突前，对目标 `roomId` 获取 `GET_LOCK`。锁必须和后续 `SELECT` / `INSERT` / `UPDATE` 使用同一个 PDO 连接。

冲突判断保持半开区间：已有 `startTime < 新结束` 且 `endTime > 新开始`，并且状态不是 `rejected` / `cancelled`。

特权预约取消重叠记录时，逐行更新并写入 `cancelled_by_priority` 日志和 `reservation_cancelled` 任务，payload 的 `reason` 为 `higher_priority`。

## 8. Worker

`php bin/worker.php` 独立于 Apache 循环运行，空闲时睡眠 500 毫秒。

领取一条任务：

```sql
UPDATE outboxjob
SET status = 'processing',
    lockedAt = NOW(),
    attempts = attempts + 1,
    lockToken = ?
WHERE status = 'pending' AND availableAt <= NOW()
ORDER BY id
LIMIT 1
```

`ROW_COUNT() = 1` 后再 `SELECT ... WHERE lockToken = ?`。处理成功改为 `completed`。失败时若 `attempts >= 8` 改为 `failed`，否则改回 `pending`，并把 `availableAt` 设为 `NOW() + LEAST(POW(2, attempts), 300)` 秒。

进程在提交 `processing` 之后崩溃时，该行会停在 `processing`。这与 Rust 相同，第一期不做自动抢回。

AI 审批：

- 配置关闭或 URL 为空：任务成功结束。
- GET `AI_APPROVAL_URL`，查询参数 `s` 和 `reason`，超时 10 秒。
- 响应 `status=pending`：成功结束，不改预约。
- `approved` 或 `rejected`：仅当预约仍是 `pending` 时更新 `status` 和 `latestExecutorId`。通过时再插入取消 token 和状态邮件任务。
- 其他状态或 HTTP 失败：按 outbox 重试。

邮件发件人显示名是 `HFI-UC`。SMTP 主机、账号或密码为空时，邮件任务成功结束。正文和主题沿用 `src/worker.rs` 的英文 HTML。

取消链接和修改链接的格式从 Rust 邮件模板原样移植，基址为 `FRONTEND_URL`。

## 9. 配置

环境变量沿用现有名称，另加 MySQL 和 cookie 项。密钥只放在服务器环境或 Apache 不可读的文件中。

| 变量 | 用途 |
| --- | --- |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASSWORD` | MySQL。不使用 `DATABASE_URL` 的 PostgreSQL 形式 |
| `FRONTEND_URL` | 邮件链接和额外 CORS 来源。默认 `https://www.hfiuc.org` |
| `SMTP_SERVER` `SMTP_EMAIL` `SMTP_PASSWORD` | 发信 |
| `CLOUDFLARE_SECRET` | Turnstile |
| `AI_APPROVAL_ENABLED` `AI_APPROVAL_URL` `AI_APPROVAL_SECRET` `AI_APPROVAL_ADMIN_ID` | AI 审批 |
| `COOKIE_SECURE` | 生产 `true` |
| `BIND` | 仅文档用途。Apache 决定监听地址 |

## 10. Apache

- `DocumentRoot` 指向 `php/public`。
- 禁止访问 `php/src`、`php/config`、`php/bin`、`php/sql`。
- `AllowOverride` 只在需要 `public/.htaccess` 时打开；优先把重写规则放进虚拟主机。
- 不启用目录列表。
- `LimitRequestBody` 限制 JSON 正文，建议 1 MB。导出和静态文件不受此限。
- 生产站点只提供 HTTPS。应用假设反代或 Apache 已经终止 TLS。

## 11. 测试

使用 PHPUnit，不连接外网。

- 健康检查的 `service` 是 `hfiuc-php`。缺字段 422、坏 JSON 400、错误 Content-Type 415，正文是 JSON 信封。未知字段被忽略。
- 预约规则：时长、30 天、同日、策略、学号、每日 2 条、冲突 409、特权自动通过并取消重叠预约。断言数据库结果，而不是错误句子是否与 Rust 逐字相同。
- 教室权限：A、B 都管理 101，C 不管理。A 通过 101 的预约后，B 的未来列表和分页列表仍包含该条，B 可以修改它；C 的列表不包含它，C 审批或修改返回 403。`latestExecutorId` 为 A 或 AI 管理员时结果相同。
- 鉴权：未登录列表隐藏学号和邮箱；写接口没有 CSRF 返回 403；取消 token 不要求 cookie。
- 时间：Unix 秒 `1800000000` 在上海时区的落点与 Rust 相同。
- worker：伪造 SMTP 和 AI HTTP 客户端，断言重试到第 8 次变为 `failed`，以及 AI `pending` 不改预约。

数据库测试使用 MySQL 5.6.51，不使用更高版本才有的 SQL 来证明通过。

## 12. 实现顺序

1. schema、PDO、前端控制器、CORS、信封和健康检查。
2. CSRF 表、登录、会话 cookie、Turnstile。
3. 目录 CRUD、策略和缓存失效。
4. 预约创建、占用、列表、导出。
5. 取消、申请人修改、管理员修改和审批。
6. 公告和分析只读导出。
7. worker、邮件和 AI。
8. 契约测试。PostgreSQL 数据导入不放在这个顺序里。
