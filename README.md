# elePHPantDeals

一个基于 [PerkRadar](https://github.com/luobinger/PerkRadar) 的**单文件优惠线索聚合器**。它读取固定的公开 RSS、Atom、JSON 和 Markdown 来源，把标题、原文链接和短摘要整理到一个本地 SQLite 数据库，再用一个网页看板帮助你搜索、筛选、收藏和标记已领。

**单文件、零依赖安装**：整个应用就是一个 `index.php`，拷贝到一个支持 PHP + SQLite 的虚拟主机（或任意 PHP 服务器）即可运行，无需 Composer、无需常驻进程。刷新通过 HTTP 请求触发——可以手动点击页面上的刷新按钮，也可以用 cron 或外部定时服务定期访问刷新 URL。

> 条目来自公开订阅源，可能包含闲聊、求购、推广或失效活动；分类由标题规则推断，不构成优惠真实性、有效期或领取资格的核验，请以原文和活动官方说明为准。

## 特性

- **单文件部署**：只有一个 `index.php`，无需构建、无第三方库。
- **手动刷新**：页面内置刷新按钮，输入 refresh key 即可触发；刷新成功显示已更新来源，冷却期内给出剩余等待时间。
- **单源短时刷新**：每次请求只抓一个来源、游标轮转，单次耗时短，不触发主机执行时长限制。
- **SQLite 存储**：数据留在本地，支持 WAL，自动建表与迁移。
- **7 个固定来源**：覆盖 LINUX DO、V2EX、Epic、NodeSeek、小众软件、果核剥壳、异次元。
- **看板交互**：标题搜索、来源/分类筛选、排序、本轮新增、收藏与已领。
- **轻量刷新检测**：前端每 30 秒只拉几百字节的状态签名，数据无变化不下发明细列表。
- **性能护栏**：展示上限（`MAX_BOARD_ITEMS`）+ `ref_at` 索引查询，数据涨到上万条也不拖慢页面。

## 环境要求

- PHP 7.4 或以上
- 扩展：`pdo_sqlite`、`curl`、`dom`、`mbstring`（`mbstring` 可选，缺失时自动降级）
- 一个可写目录用于存放 SQLite 数据库（默认 `./data/`）

## 快速开始

1. 下载 `index.php` 到站点目录（例如 `public_html/elephpantdeals/index.php`）。
2. 打开文件顶部的「配置区」，把 `ACCESS_TOKEN` 改成你自己的随机字符串（至少 32 位）：

   ```php
   define('ACCESS_TOKEN', '改成你自己的随机长字符串_至少32位');
   ```

3. 浏览器访问 `https://你的域名/elephpantdeals/index.php`，确认页面正常打开。首次访问会自动创建 `data/` 目录、数据库和一个拒绝外部下载数据库的 `.htaccess`。
4. 点击页面上的「手动刷新」按钮，输入第 2 步设置的 refresh key，先抓一个来源验证是否正常。之后可按需配置定时刷新（见下方）。

## 刷新方式

刷新统一通过访问 `index.php?refresh=TOKEN` 触发，这是一个只需发一次 HTTP 请求的轻量端点。它支持多种触发方式：

- **手动刷新**：页面上的「手动刷新」按钮，输入 refresh key 即可触发。成功后显示本次已更新的来源与新增条数；若在冷却期内则显示跳过并给出还需等待的时间。refresh key 与 `ACCESS_TOKEN` 相同，仅在本次请求中使用、不会写入页面或保存，避免外界持续恶意触发刷新。
- **主机 cron**：在控制面板添加定时任务（见下方示例）。
- **外部定时服务**：若主机面板不支持 cron，可用 cron-job.org、UptimeRobot 等定时访问该 URL。

定时刷新推荐每 15 分钟一次（7 个来源约 105 分钟轮完一轮）：

```cron
*/15 * * * * curl -fsS "https://你的域名/elephpantdeals/index.php?refresh=你的TOKEN" >/dev/null 2>&1
```

关键行为：

- 每次请求只抓一个源，返回一段 JSON，说明本次抓了哪个源、抓到/新增多少条、下一个源是谁。
- 内置 3 分钟冷却（`REFRESH_COOLDOWN_MS`）与文件锁，重复触发或上一轮未结束时会安全跳过。
- 单个源网络超时默认 10 秒，最坏情况也远低于虚拟主机的 30 秒执行上限。

### 为什么每次只刷新一个来源

原版 PerkRadar 是常驻进程，一轮内并发抓取全部 7 个来源。本项目运行在共享虚拟主机上，没有常驻进程，刷新只能由一次 HTTP 请求完成，因此改为**每次请求只抓一个来源、用游标轮转**：

- **避开执行时长限制**：虚拟主机普遍有 30 秒左右的 PHP 执行上限。串行抓 7 个源（每源超时 10 秒）最坏要 70 秒，必然超时；分成 7 次请求后每次最多约 10 秒，稳定落在限制内。
- **降低资源占用**：单次请求只占一个网络连接的抓取时间，不会因并发多个请求拖垮低配主机。
- **失败隔离**：某个源超时或限流只影响它自己，其余来源照常轮转更新。
- **代价**：一轮更新被摊到 7 次请求中完成，单个来源的更新间隔约为「定时频率 × 7」。以每 15 分钟触发一次计算，每个来源约每 105 分钟更新一次，接近原版的 2 小时节奏。

## 配置区说明

所有配置都在 `index.php` 顶部，按需修改：

| 配置项 | 用途与默认值 |
| --- | --- |
| `ACCESS_TOKEN` | 刷新端点的访问令牌，**必须修改为 ≥32 位随机串**；未匹配返回 403 |
| `DATA_DIR` | 数据目录，默认 `__DIR__.'/data'`，需可写；建议放在 Web 根目录之外 |
| `DB_FILE` | 数据库路径，默认 `DATA_DIR.'/deals.db'` |
| `LOCK_FILE` | 防重入锁文件，默认 `DATA_DIR.'/refresh.lock'` |
| `JINA_API_KEY` | LINUX DO 来源使用的 Jina 凭据，可留空（匿名可能受限） |
| `SOURCE_TIMEOUT_S` | 单源网络超时（秒），默认 10 |
| `CONNECT_TIMEOUT_S` | 连接超时（秒），默认 6 |
| `MAX_ATTEMPTS` | 429/503 时的最大尝试次数，默认 2 |
| `RETRY_WAIT_CAP_MS` | 重试等待上限（毫秒），默认 2000 |
| `REFRESH_COOLDOWN_MS` | 两次刷新请求最小间隔（毫秒），默认 3 分钟 |
| `MAX_BOARD_ITEMS` | 看板最多返回的条目数，默认 5000；超出部分不展示但仍入库 |
| `APP_VERSION` | 版本号，显示在页面底部 |

## HTTP 接口

| 路径 | 说明 |
| --- | --- |
| `index.php` | 看板页面（内嵌首屏数据） |
| `index.php?refresh=TOKEN` | 触发一次刷新（抓一个源），返回 JSON；供手动按钮、cron 或外部定时服务调用 |
| `index.php?api=status` | 轻量状态：计数、新鲜度、来源健康、轮次 ID（约 1KB），不含条目明细 |
| `index.php?api=board` | 完整看板数据（含条目列表），供前端检测到变化后拉取 |

刷新接口返回示例：

```json
{
  "ok": true,
  "round_id": 7,
  "source": "epic",
  "source_name": "Epic",
  "status": "success",
  "error": null,
  "fetched": 1,
  "inserted": 1,
  "next_source": "nodeseek",
  "duration_ms": 35
}
```

## 数据来源与处理

七个固定来源，地址写在 `index.php` 的 `sources()` 中，调用方不能替换：

| 来源 | 格式 | 主要规则 |
| --- | --- | --- |
| LINUX DO 福利羊毛 | 经 Jina 转换的 Markdown | 解析福利区主题标题、主题链接和 RFC822 日期 |
| V2EX 推广 | Atom | 使用 `promotions.xml` |
| Epic | JSON | 只收录当前处于促销窗口内、折扣价为 0 的免费游戏 |
| NodeSeek | RSS / Atom | 收录允许域名内的条目 |
| 小众软件 | RSS / Atom | 收录允许域名内的条目 |
| 果核剥壳 | RSS / Atom | 收录允许域名内的条目 |
| 异次元软件世界 | RSS / Atom | 跟随重定向到最终地址 |

处理规则要点：

- **7 天在架窗口**：Epic 按促销开始时间计算，其余来源以发布日期为准、缺失时用首次入库时间；到期条目自动撤下。
- **链接安全**：仅接受 `http`/`https`，拒绝带凭证的链接，条目链接必须在来源域名白名单内（防止订阅源注入站外钓鱼链接）。
- **字段长度**：标题最多 300 字符，摘要最多 160 字符；来源没有简介时留空，不补写价格或领取条件。
- **失败不影响旧数据**：来源抓取失败不会清空该来源已存条目；Epic 仅在成功解析后对账撤下不再免费的条目。
- **分类**：按标题信号评分推断（强化"限免/免费/优惠"等词，弱化"求购/抽奖/讨论/提问"等词），仅作展示参考。

更多细节见 [docs/SOURCES.md](docs/SOURCES.md)。

## 收藏与已领

收藏与已领状态**只保存在当前浏览器的 localStorage**（键 `elephpantdeals-v1`），不上传、不写入数据库，按浏览器站点隔离：

```json
{ "claimed": [], "favorites": [], "showAll": false }
```

从旧版 `perkradar-php-v1` 升级时会自动读取旧键，收藏与已领不会丢失。清除浏览器站点数据会清除这些状态。

## 安全说明

- 刷新端点用 `ACCESS_TOKEN` 鉴权（常量时间比较），令牌请通过 HTTPS 传输，不要写进公开页面。
- 数据库放在 `data/` 并自带 `.htaccess` 拒绝下载；若可把 `DATA_DIR` 移到 Web 根目录之外会更稳妥。
- 公开看板无需令牌。若数据敏感，请在 Web 服务器层加访问控制（如 Basic Auth）。
- 未知来源的标题与摘要在页面中均经过 HTML 转义输出。

部署与排错详见 [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) 与 [docs/OPERATIONS.md](docs/OPERATIONS.md)。

## 性能设计

- **探后取 (`?api=status`)**：前端 30 秒轮询约 1KB 的状态签名，仅在轮次/计数变化时拉全量列表。
- **`ref_at` 索引**：入库时预存 `COALESCE(published_at, first_seen_at)`，看板查询直接走索引，避免全表扫描。
- **展示上限**：`MAX_BOARD_ITEMS` 限制单次返回条目数，计数仍按全量统计并准确显示。

## 与 PerkRadar 的关系

本项目是 [PerkRadar](https://github.com/luobinger/PerkRadar) 的 PHP 单文件衍生实现，移植了其分类信号、RSS/Atom/Epic/Jina-Markdown 解析、链接规范化与域名白名单、7 天在架规则等核心逻辑，改为单文件 + 每次抓一个源的架构，并引入上述性能优化。感谢原项目作者与贡献者。

## 许可证

MIT License，详见 [LICENSE](LICENSE)。
