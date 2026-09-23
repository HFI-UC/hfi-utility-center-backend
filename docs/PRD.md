# HFI Utility Center PHP 后端 PRD

版本：2.0.0  
状态：待确认后实现  
日期：2026-09-23

## 1. 背景

现有生产 API 是 Rust（Axum）+ PostgreSQL 17，HTTP 契约写在 `docs/openapi.yaml`（从 `hfiuc-api` commit `fa1dca4` 提取）。前端已经按这套契约接入 `https://api.hfiuc.org`。

本次重构把同一套产品换成 PHP 8.4、MySQL 5.6.51、Apache 2.4.54。路径和 JSON 字段保持可用，这样现有前端不用改。Rust 源码用来核对业务规则，OpenAPI 用来核对接口形状。

业务逻辑必须等价：同样的输入得到同样的领域结果，包括是否接受预约、状态怎么变、谁能看到学号和邮箱、邮件和 AI 任务是否入队。响应里只用来标明实现的值不必相同。例如 `GET /healthz` 的 `service` 从 `hfiuc-rust` 改为 `hfiuc-php`。

## 2. 目标

- 学生可以查询校区、班级、房间和可预约时段，提交、查看、修改、取消预约。
- 管理员可以登录，维护目录和公告，审批、修改、导出预约，并接收可选的新预约通知。
- 特权校区（Office Teachers）的管理员邮箱预约可以自动通过，并取消同一时段上冲突的普通预约。
- 邮件和 AI 审批在 HTTP 请求之外可靠投递，失败可重试。
- 历史预约即使缺少房间、班级或学号，仍然可以列表、详情和导出。

## 3. 非目标

- 不改前端，不新增接口，不改 URL，不把错误文案改成中文。
- 不继续运行 Rust 进程，也不在 PHP 里兼容 PostgreSQL。
- 不把 MySQL 升到 5.7 / 8.0 才能用的特性当成依赖。
- 不补齐 Rust 里尚未实现的分析字段（`reasons` 恒为空数组，`hourlyReservations` 与 `dailyReservationCreations` 恒为 0）。
- 第一期不包含从生产 PostgreSQL 的在线双写。数据导入是独立迁移任务，schema 必须能装下历史空值。

## 4. 用户与场景

| 角色 | 能做的事 |
| --- | --- |
| 访客 | 看首页、公告、校区、班级、房间策略、某天占用；按条件查预约列表，但看不到学号和邮箱 |
| 申请人 | 用 CSRF 创建预约；用邮件里的一次性 token 预览、修改（最多 2 次）、取消 |
| 管理员 | 登录后维护校区、班级、房间、可预约策略、管理员、公告；审批、修改未结束的已通过预约、导出 xlsx、查看未来预约和分析 |
| 系统 worker | 发邮件、调用 AI 审批、把 AI 结果写回仍为 `pending` 的预约 |

## 5. 功能需求

### 5.1 公开目录与公告

- `GET /campus/list`、`GET /class/list`、`GET /room/list` 返回全部记录。房间包含策略。房间 `enabled` 为 SQL NULL 时，响应当 `true`。
- 未登录的目录列表可以缓存；管理员看房间列表时不走缓存，以便看到刚改的数据。`POST /catalog/invalidate` 清空缓存，且按现有实现不检查登录。
- `GET /announcement/current`：没有记录、未启用，或正文 trim 后为空时，`data` 为 `null`。
- 管理端公告没有记录时返回空草稿，不返回 `null`。

### 5.2 创建预约

需要 CSRF，不需要登录。成功时 `data.reservationId` 为新 id，响应不包含初始状态。

普通预约：

- 邮箱必须包含 `@`。`reason` trim 后不能为空。
- `classId` 可省略；提供则班级必须存在。
- `purposeType` 可省略；提供时只能是 `personal`、`class`、`club`。
- 学号必须是 `GJ` 加 8 位数字。
- 开始、结束是 Unix 秒。服务端转成上海墙钟后再比较和存储。
- 开始必须晚于当前上海时间，且在 30 天内。结束必须晚于开始，时长不超过 2 小时，并且与开始在同一天。
- 该时段必须落在该房间某条启用策略的星期和时间范围内。星期日为 0。
- 房间不存在为 404；`enabled` 为 false 为 400。`enabled` 为 NULL 视为启用。
- 与状态不是 `rejected` / `cancelled` 的预约时间重叠则 409。
- 同一邮箱在开始日当天（上海 00:00 到次日 00:00，且结束时间也落在当天）未取消的预约最多 2 条。
- 初始状态为 `pending`。

特权预约：

- 若 `classId` 所属校区 `isPrivileged=true`，邮箱必须大小写不敏感地匹配某个管理员，否则 403。
- 此类预约自动 `approved`，学号存为 `-`，跳过可预约时段、冲突和每日限额。
- 同一房间上重叠的 `pending` / `approved` 预约改为 `cancelled`，并给这些申请人发取消邮件。

成功后：

- 写入取消 token。库里只存 SHA-256 十六进制哈希，明文只进入邮件任务。过期时间等于预约开始时间。
- 投递申请人邮件。普通预约再给开启通知的管理员各投递一条通知，并在 AI 审批开启时投递 `ai_approval`。

### 5.3 申请人修改与取消

- 预览、修改、取消只认邮件 token，不认管理员 cookie，也不认 CSRF。
- token 哈希匹配、未使用、未过期，且预约仍为 `pending` 或 `approved`、开始时间仍在未来，才可操作。
- 修改最多 2 次。修改后状态回到 `pending`，`editCount` 加 1，`latestExecutorId` 清空，并重新投递邮件；AI 开启时再投递审批任务。
- 取消把状态改为 `cancelled`，写下 `cancelledAt`，标记 token 已用，并投递取消邮件。

### 5.4 管理员预约操作

教室权限来自 `roomapprover`：同一间教室可以有多名管理员。可见性和管理权看“当前管理员是否管理这间教室”，不看 `latestExecutorId`，也不看是谁审批的。

因此不会出现这种结果：A 和 B 都能管理 101 教室，A 审批了 101 的一条预约之后，B 看不见它，或者不能修改它。AI 或特权流程写下的 `latestExecutorId` 也同样不独占这条预约。

- 已登录管理员的 `GET /reservation/get`、`GET /reservation/future` 和预约导出，只包含 `roomId` 属于该管理员的预约。`roomId` 为空的历史预约不属于任何管理员。
- 未登录的 `GET /reservation/get` 仍返回全部预约，但 `studentId` 和 `email` 为 `null`。每页 20 条，`page` 从 0 开始。
- 审批、管理员修改只允许作用于自己管理的教室。不管理该教室时返回 403，不修改数据。
- 审批只作用于仍为 `pending` 且开始时间在未来的预约。拒绝时 `reason` trim 后不能为空。任一管理该教室的管理员都可以审批，包括别人尚未处理的申请。
- 通过时新签发一枚取消 token，过期时间仍是预约开始时间。
- 任一管理该教室的管理员都可以修改仍未结束的 `approved` 预约，包括其他人刚刚通过的预约。不增加 `editCount`，状态保持 `approved`。
- 导出没有可见行时返回 404 JSON，不返回空 xlsx。`mode` 只影响文件名。
- 本阶段不新增教室权限分配接口。`roomapprover` 只作为权限数据；没有记录的管理员看不到、也不能处理任何预约。
- 目录列表仍是全部校区、班级和教室。分析接口仍是全局汇总，不按教室权限拆开。

### 5.5 管理员账号

- 密码登录必须先过 Cloudflare Turnstile。已登录时再登录返回 400。
- 也支持 `tempadminlogin` 中 15 分钟内的一次性 token 登录。用后删除。
- 会话 cookie 名为 `uc`，1 小时过期，只存服务端，响应体不返回会话值。
- 新密码至少 6 个字符。邮箱必须包含 `@`。重复邮箱返回 409。
- 新管理员默认不接收预约通知（迁移 0007 之后的默认值）。

### 5.6 分析

- `GET /analytics/overview` 和 `GET /analytics/weekly` 按现有实现不检查登录。
- 两个导出接口需要登录，返回最近 365 天的 `analytic` 表 CSV，路径不同但内容相同。

### 5.7 后台任务

worker 不是 HTTP 接口。任务种类：

| kind | 行为 |
| --- | --- |
| `reservation_created` | 给申请人发“已创建”邮件 |
| `reservation_modified` | 给申请人发“已修改”邮件 |
| `reservation_cancelled` | 给申请人发取消邮件；`reason=higher_priority` 时使用被特权预约挤占的文案 |
| `reservation_status_changed` | 给申请人发通过或拒绝邮件；通过邮件带上取消链接 |
| `admin_reservation_notification` | 通知仍开启接收的管理员 |
| `ai_approval` | GET AI 服务。`approved` / `rejected` 且预约仍为 `pending` 时写回，并投递状态邮件 |

SMTP 或 AI 未配置时，对应任务直接记成功，不报错。失败最多 8 次，退避上限 300 秒，之后标记 `failed`。

## 6. 等价与可以不同的地方

必须等价：

- 预约是否创建、初始状态、冲突、每日限额、特权预约自动通过并取消重叠预约。
- 申请人最多修改 2 次且回到 `pending`；管理员修改不增加 `editCount` 且保持 `approved`。
- 未登录列表看不到学号和邮箱；取消和申请人修改只认邮件 token。
- 审批条件、取消、公告启用条件，以及邮件 / AI 任务的入队条件和重试结果。申请人侧规则不按管理员拆分。
- 业务时间仍是上海墙钟，格式 `YYYY-MM-DDTHH:MM:SS`。`GET /healthz` 的 `time` 仍是 UTC RFC3339。

可以不同：

- 标明实现的返回值。`service` 使用 `hfiuc-php`。
- 错误文案保持英文且表达同一个原因，不要求与 Rust 句子逐字相同。
- Axum 在进业务前返回的 `text/plain`（坏 JSON、缺字段、错误 Content-Type）可以改成 JSON 信封。请求仍然要被拒绝，状态码保持 400、422、415。
- 缓存、锁和任务领取的内部实现。
- 管理端预约范围。Rust 让任意已登录管理员看到并处理全部预约。PHP 改为只看到、只处理自己管理的教室，并且同一教室的多名管理员共享这些预约。这是明确的产品变更。

接口形状仍然稳定：只接受 `GET`、`POST`、`OPTIONS`；业务成功和失败使用现有信封；首页是 HTML；三个导出成功时是文件，失败时是 JSON。未知 JSON 字段忽略。

## 7. 成功标准

- 除管理端预约可见性以外，同一组业务输入在 PHP 与 Rust 中得到相同的领域结果：预约行、状态、是否入队。
- A、B 都管理 101、C 不管理时：A 通过一条 101 预约后，B 仍能在列表里看到它，并可以修改这条未结束的已通过预约。101 上另一条仍为 `pending` 的预约，B 也可以审批。C 看不到这些预约，审批或修改返回 403。
- 普通预约、特权预约、冲突、每日 2 条限额、申请人 2 次修改、管理员修改、取消链接，都能用自动化测试复现。
- 同一房间的并发创建不会产生两条重叠的有效预约。
- worker 崩溃后，已领取但未完成的任务不会被静默丢弃；重试次数和最终 `failed` 与现有规则一致。
- `GET /healthz` 返回 `service: hfiuc-php`。
- Apache 只暴露 `public/`，配置文件和源码不能被直接下载。

## 8. 里程碑

1. 确认本 PRD 和 `docs/SPEC.md`。
2. 建立 PHP 工程、MySQL schema、Apache 前端控制器，先打通健康检查、CSRF、登录和目录。
3. 实现预约创建、占用查询、列表和导出。
4. 实现修改、取消、审批和公告。
5. 实现 outbox worker、SMTP 和 AI 审批。
6. 用测试对照业务结果是否与 Rust 等价，并确认实现标识已经改为 PHP。
7. 另开任务做 PostgreSQL 历史数据导入和切换。
