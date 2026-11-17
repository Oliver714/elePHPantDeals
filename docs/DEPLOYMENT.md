# 部署指南

本指南覆盖从上传到配置的完整流程，适用于常见虚拟主机、VPS 与任意支持 PHP 的服务器。

## 一、准备

确认主机满足：

- PHP 7.4+，扩展 `pdo_sqlite`、`curl`、`dom` 已开启（`mbstring` 可选）。
- 站点目录可写（用于自动生成 `data/`）。

不确定扩展是否开启？上传一个内容为 `<?php phpinfo();` 的临时文件访问查看，或用 SSH 执行 `php -m`。

## 二、上传

1. 把仓库里的 `index.php` 上传到站点目录，例如：

   ```
   public_html/elephpantdeals/index.php
   ```

2. （可选但推荐）把 `data/` 放到 Web 根目录之外。例如主机给了你家目录 `/home/user/private`，把配置改成：

   ```php
   define('DATA_DIR', '/home/user/private/elephpantdeals-data');
   ```

   这样数据库完全无法被 HTTP 访问。若放在站点目录内也没关系——首次运行会自动生成拒绝下载的 `.htaccess`。

3. 设置 `ACCESS_TOKEN`：

   ```php
   define('ACCESS_TOKEN', '一串至少32位的随机字符');
   ```

   生成随机串的示例：`openssl rand -hex 24`。

## 三、首次验证

浏览器访问 `https://你的域名/elephpantdeals/index.php`：

- 应看到看板页面，顶部显示"尚无数据"（还没抓过）。
- 服务器目录里应自动生成 `data/` 与其中的 `.htaccess`。

若出现空白页或报错，检查 PHP 错误日志，通常是扩展缺失或目录不可写。

## 四、配置 cron

### 方式 A：主机控制面板（推荐）

大多数虚拟主机提供"cron 作业 / 定时任务"面板，命令类型选"URL"或"Shell 命令"：

```cron
*/15 * * * * curl -fsS "https://你的域名/elephpantdeals/index.php?refresh=你的TOKEN" >/dev/null 2>&1
```

- 频率：每 15 分钟一次。7 个来源约 105 分钟轮完一轮，接近每源 2 小时刷新一次。
- 若面板只允许填 URL，把整条 URL 填进去即可：`https://你的域名/elephpantdeals/index.php?refresh=你的TOKEN`。

### 方式 B：外部定时服务

没有面板 cron 时，可用 cron-job.org、UptimeRobot 等服务，每 15 分钟访问一次上面的 URL。

### 验证刷新

手动在浏览器访问一次刷新 URL（把 TOKEN 换成你的），应看到类似：

```json
{"ok":true,"round_id":1,"source":"linuxdo","status":"failed","error":"HTTP 401", ...}
```

首次抓 LINUX DO 若显示 401，是因为没配 Jina 密钥，属正常；其余源通常能成功。再访问几次，看到 `source` 依次变成 `v2ex`、`epic`、`nodeseek`……说明轮转正常。

## 五、常见问题

**Q：页面一直"尚无数据"？**
确认 cron 是否真的在跑：查看访问日志里有没有 `?refresh=` 请求，或手动访问刷新 URL 看返回。也可能是数据库目录不可写导致写库失败，检查 PHP 错误日志。

**Q：刷新返回 `{"skipped":"cooldown"}`？**
3 分钟内已有过刷新请求，正常现象，稍后再试即可。

**Q：刷新返回 `{"skipped":"busy"}`？**
上一次刷新还没结束（文件锁未释放）。等一会或检查是否有卡住的进程。

**Q：只有部分来源有数据？**
单个来源失败不影响其他源。查看顶部"来源健康"，鼠标悬停失败来源可看错误原因。常见原因：LINUX DO 缺 Jina 密钥、目标站临时限流（429/503）。

**Q：数据库文件会被下载吗？**
不会。`data/` 下有自动生成的 `.htaccess` 拒绝访问；若把 `DATA_DIR` 放到 Web 根目录外则彻底不可访问。

**Q：能改成每 2 小时只刷新一次吗？**
可以，但那样一轮要 14 小时才轮完 7 个源。建议保持较高频率（15 分钟），让每个源大约 2 小时更新一次。
