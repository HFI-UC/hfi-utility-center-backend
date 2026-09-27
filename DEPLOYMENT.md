# PHP API 与 Cloudflare Queue 上线顺序

当前线上 `https://api.hfiuc.org/healthz` 返回 `hfiuc-rust`。新的 PHP API、数据库迁移和前端必须在同一维护窗口切换；Worker 已按 `main`/`dev` 两套环境部署，但 `DISPATCH_ENABLED=false`，不会提前调用新的 PHP `/tasks` 接口。

## 上线前

1. 确认 PHP 8.4、MySQL 5.6、Apache/PHP-FPM 的目标主机、站点目录和回滚方式。
2. 完整备份生产数据库，在独立恢复库验证备份可用；记录 `reservation`、`outboxjob`、`roomapprover` 的行数。
3. 对生产库执行只读 `sql/003_roles_archive_preflight.sql`。若发现孤儿关联，先人工修复，迁移不会替你清空历史 ID。
4. 在维护窗口停止预约写入，按顺序执行未应用的 `sql/002_drop_campus_is_privileged.sql`、`sql/003_roles_archive.sql`。新库直接使用 `sql/001_schema.sql`。
5. PHP 根目录 `.env` 配置 `TASK_PULL_SECRET`、`TASK_EXECUTE_SECRET`、Cloudflare Queue 凭据及现有生产依赖；两项新 task secret 分别在对应 Worker 环境中配置为 Cloudflare Secrets。不要写进 `wrangler.jsonc`。
6. dev Worker 使用独立 `uc-dev` Queue 和独立 dev PHP API。未配置 dev PHP API 前保持 dev 调度关闭。

## 切换顺序

1. 发布 PHP API，并确认 `GET /health` 返回 `service=hfiuc-php`。
2. 用错误 Bearer token 验证 `GET /tasks` 与 `POST /tasks/{id}/execute` 均返回 401；用正确 token 拉取空任务返回 200。
3. 将生产 Worker 的 `DISPATCH_ENABLED` 改为 `true` 后用最新版 Wrangler 发布；dev 在独立 API 验证后同样启用。
4. 发布同步更新的前端，验证普通预约、优先预约预览/确认、管理员房间授权和归档恢复。
5. 创建测试预约，检查 outbox 事务提交后立即派发、Queue consumer 执行和确认；模拟 Queue 发布失败，确认 Durable Object Alarm 下一轮可补发。
6. AI 开启时，确认 pending 15 分钟内可人工审核；超过 15 分钟仍 pending 才进入 `ai_reviewing`。AI 故障会低频重试，全局管理员可填写原因解锁。

## 观察与回滚

观察 `outboxjob` 的 `pending`、`leased`、`processing`、`failed` 数量，最近的 `lastError`，Cloudflare Queue 的积压与重试，以及 MySQL `errorlog`/`auditlog`。Queue 至少一次投递，已完成任务按 task ID 幂等；若 SMTP 已接受邮件但数据库尚未完成更新，仍有重复邮件窗口。

若 PHP 冒烟失败，先关闭 Worker 调度，再停止新预约写入，回到原 API/前端上游并恢复维护窗口前数据库备份。不要在存在新写入后直接反向运行非事务性 MySQL DDL。
