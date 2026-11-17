# 运维手册

## 健康检查

访问 `?api=status`（无需令牌）可得到一个约 1KB 的 JSON，适合做监控探针：

```json
{
  "activeCount": 93,
  "newCount": 3,
  "sourceHealth": { "ok": 6, "total": 7 },
  "freshness": { "lastFinished": 1794868400000, "ageMs": 41000, "stale": false, "severe": false },
  "lastRoundId": 7,
  "sources": [ ... ]
}
```

关注字段：

- `freshness.ageMs`：距最近一轮完成的毫秒数。超过 2.5 小时 `stale=true`，超过 4 小时 `severe=true`，页面顶部会给出提示。
- `sourceHealth.ok`：最近一次各来源结果中成功数。`ok=0` 说明所有源最近一次都失败了。
- `sources[].lastError`：具体失败原因，如 `HTTP 401`、`超时`。
- `lastRoundId`：轮次 ID，每次刷新 +1。长时间不增长说明 cron 停了。

## 轮转机制

刷新不是一次抓全部，而是每次 HTTP 请求抓一个源：

1. `meta` 表的 `source_cursor` 记录下一个要抓的源索引。
2. 每次刷新：读游标 → 抓该源 → 入库 → 游标 +1 并对 7 取模。
3. 每 7 次请求完成一轮。

这样设计是为了让单次请求足够短（单源超时 10 秒），不触发虚拟主机的执行时长上限。代价是"一轮"分散在 7 次请求中完成。

## 数据清理

- **在架窗口**：`purgeStale()` 在每次刷新后运行，删除 `ref_at` 早于 7 天或已到期的条目。
- **Epic 对账**：Epic 成功解析后，撤下不再免费的旧条目；解析失败则保留旧数据不动。
- **轮次历史**：`rounds` 和 `source_runs` 会随刷新持续增长。若在意体积，可定期清理旧记录：

  ```sql
  DELETE FROM source_runs WHERE round_id IN (
    SELECT id FROM rounds WHERE finished_at < strftime('%s','now','-30 days') * 1000
  );
  DELETE FROM rounds WHERE finished_at < strftime('%s','now','-30 days') * 1000;
  ```

  注意保留最近一轮，避免影响"本轮新增"判定。

## 数据库结构

| 表 | 用途 |
| --- | --- |
| `deals` | 条目主表；`canonical_url` 唯一键用于去重，`ref_at` 为在架参考时间（带索引） |
| `rounds` | 每一轮的记录（触发方式、开始/结束时间、状态） |
| `source_runs` | 每次抓取某来源的结果（成功数、条数、错误、耗时） |
| `meta` | 键值表，存 `source_cursor`、`last_refresh_at` |

升级时 `db()` 会自动补列与回填（如旧库缺 `ref_at`），无需手动迁移。

## 备份

数据库是一个单文件 `data/deals.db`（WAL 模式下还有 `-wal`/`-shm` 伴随文件）。备份时建议先停掉 cron，或直接复制三个文件：

```bash
cp data/deals.db* /backup/
```

恢复时把文件放回 `data/` 即可。收藏/已领在浏览器本地，不属于服务端备份范围。

## 故障排查

| 现象 | 可能原因与处理 |
| --- | --- |
| 刷新返回 HTTP 403 | TOKEN 不匹配；确认 URL 里 token 与 `ACCESS_TOKEN` 一致 |
| 刷新返回 500 | 看 PHP 错误日志；常见为数据库不可写或结构异常 |
| 所有源都失败 | 检查主机出网能力、DNS、是否被目标站限流；LINUX DO 需 Jina 密钥 |
| 页面样式错乱 | 浏览器过旧或缓存；强制刷新（Ctrl+F5） |
| 数据不更新 | 检查 `freshness` 与 `lastRoundId`；多半是 cron 停了 |

## 升级

1. 备份 `data/`。
2. 用新版本 `index.php` 覆盖旧文件（配置区需按新版本重新核对）。
3. 访问一次页面，让自动迁移跑完；随后访问 `?api=status` 确认无报错。
