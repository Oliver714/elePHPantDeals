# 数据来源与处理规则

本项目的数据规则移植自 [PerkRadar](https://github.com/luobinger/PerkRadar)，以下是当前实现的要点。

## 来源清单

来源地址硬编码在 `index.php` 的 `sources()` 中，调用方无法通过 URL 参数替换。

| ID | 名称 | 格式 | 允许的链接域名 |
| --- | --- | --- | --- |
| `linuxdo` | LINUX DO 福利羊毛 | 经 Jina 转换的 Markdown | `linux.do` |
| `v2ex` | V2EX 推广 | Atom（`promotions.xml`） | `v2ex.com` |
| `epic` | Epic 免费游戏 | JSON | `store.epicgames.com` |
| `nodeseek` | NodeSeek | RSS / Atom | `nodeseek.com` |
| `appinn` | 小众软件 | RSS / Atom | `appinn.com` |
| `ghxi` | 果核剥壳 | RSS / Atom | `ghxi.com` |
| `iplaysoft` | 异次元软件世界 | RSS / Atom（跟随重定向） | `iplaysoft.com` |

## 抓取

- 单源超时 `SOURCE_TIMEOUT_S`（默认 10 秒），连接超时 `CONNECT_TIMEOUT_S`（默认 6 秒）。
- 响应体上限 1.5MB，单个订阅源最多解析 2000 条。
- 返回 429/503 时最多尝试 `MAX_ATTEMPTS` 次（默认 2），等待时间受 `RETRY_WAIT_CAP_MS` 限制。
- 请求带固定 User-Agent；LINUX DO 若配置了 `JINA_API_KEY` 会加 `Authorization: Bearer` 头。

## 解析

- **RSS / Atom**：按节点 localName 取值（命名空间无关），标题取 `title`，链接优先 `alternate` 关系的 `link`，时间依次尝试 `pubDate`/`published`/`updated`/`date`，摘要取 `description`/`summary`/`encoded`/`content`。
- **Epic**：解析 `data.Catalog.searchStore.elements`，只保留折扣价为 0、且当前时间落在促销窗口内（`start <= now < end`、折扣为 0% 或 100%）的条目；`expiresAt` 取促销结束时间。商品列表为空或结构异常视为失败，不用空结果清空旧条目。`promotions: null` 表示无促销，跳过该商品。
- **LINUX DO**：Jina 返回的是 Markdown，按 `### [标题](链接)` 分块，提取 `linux.do/t/topic/<id>` 链接与 RFC822 日期；无摘要字段。

## 时间规则

- 发布时间经 `publishTimeMs()` 校验：不早于 2000-01-01，且不晚于当前时间 + 7 天（容忍时钟偏差），否则视为无日期。
- 在架参考时间 `ref_at = published_at ?? first_seen_at`：发布日期优先，缺失时用首次入库时间，但页面不把首次入库时间显示为"发布时间"。
- 在架窗口 7 天：`ref_at < now - 7d` 或 `expiresAt <= now` 的条目被清理；临界值（刚好 7 天、刚好到期）按保留处理。
- Epic 的 `expiresAt` 来自促销结束时间；其他来源留空。页面中的"即将到期"标记指 `expiresAt` 在 48 小时内。

## 链接处理

`canonicalUrl()` 规范化链接作为去重键与外链：

- 只接受 `http`/`https`，长度 ≤2048。
- 拒绝带 `user:pass@` 凭证的链接（常见钓鱼形态）。
- 去掉 `#fragment`，移除跟踪参数（`utm_*`、`spm*`、`ref`、`fbclid`、`gclid`、`mc_*`），参数名排序，路径去掉末尾斜杠，主机名小写。
- 入库前用来源域名白名单过滤：条目链接的主机必须等于或属于允许域名，站外链接直接丢弃。

## 字段清理

- 标题：解码 HTML 实体、去标签、去控制字符与 ASCII 装饰线、压缩空白，截断到 300 字符。
- 摘要：同样清洗后截断到 160 字符，超出加省略号；来源无简介时留空，不自行补写价格或日期。

## 分类

`classifyCategory()` 按标题信号评分（仅 Epic 固定为"游戏"）：

- 强信号（+5）：限免、免费领、优惠码、折扣、秒杀、白嫖、羊毛、红包、福利、首月等。
- 中信号（+3）：免费、优惠、注册送、低至、`N% off` 等。
- 归属信号（+2）：会员/订阅/套餐，或游戏相关词。
- 扣分项：求购/抽奖/求助（−10）、二手交易（−6）、资讯评测（−5）、提问（−4）、问号（−1）。
- 得分 ≥2 才归类到"免费白嫖/限免/低价优惠/会员订阅/游戏"之一，否则为"其他"。

本项目**默认在读取时重算分类**，不改写数据库里已存的分类，这样规则更新后旧条目也会重新归类。分类仅为展示启发式，不代表优惠真实有效。
