<?php
declare(strict_types=1);

/**
 * elePHPantDeals (PHP single-file edition)
 * ------------------------------------------------------------------
 * 单文件部署：把本文件拷贝到支持 PHP + SQLite 的虚拟主机即可。
 * cron 只发 HTTP 请求，每次请求抓一个源，用 meta 表游标轮转。
 *
 * 规则移植自 https://github.com/luobinger/PerkRadar (MIT)，
 * 包括：分类信号、RSS/Atom/Epic/Jina-Markdown 解析、链接规范化、
 * 来源域名白名单、7 天在架窗口、Epic 成功后的对账撤下。
 *
 * 需要扩展：pdo_sqlite、curl、dom、mbstring(可选，有则用)
 * PHP 7.4+ 兼容。
 */

/* ============================ 配置区（按需修改） ============================ */

define('ACCESS_TOKEN', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING_32CHARS'); // cron 调用刷新端点用，至少 32 位
define('DATA_DIR', __DIR__ . '/data');          // 需可写；建议放在 Web 根目录之外，或用附带的 .htaccess 保护
define('DB_FILE', DATA_DIR . '/deals.db');
define('LOCK_FILE', DATA_DIR . '/refresh.lock');
define('JINA_API_KEY', '');                      // LINUX DO 走 Jina；可留空（匿名可能受限）
define('SOURCE_TIMEOUT_S', 10);                  // 单源网络超时（秒），保守以适配虚拟主机 30s 上限
define('CONNECT_TIMEOUT_S', 6);
define('MAX_ATTEMPTS', 2);                       // 429/503 时最多尝试次数
define('RETRY_WAIT_CAP_MS', 2000);               // 重试等待上限
define('REFRESH_COOLDOWN_MS', 3 * 60 * 1000);    // 两次刷新请求最小间隔，防 cron 重复触发
define('MAX_BOARD_ITEMS', 5000);                 // 看板最多返回条目数（性能上限；超出部分不展示，库里仍保留到 7 天窗口清理）
define('APP_VERSION', '1.2.0');

/* ============================ 常量 ============================ */

define('RETENTION_MS', 7 * 24 * 3600 * 1000);
define('EXPIRING_SOON_MS', 48 * 3600 * 1000);
define('STALE_AFTER_MS', (int)(2.5 * 3600 * 1000));
define('SEVERE_AFTER_MS', 4 * 3600 * 1000);
define('MAX_RESPONSE_BYTES', 1500000);
define('MAX_FEED_ITEMS', 2000);
define('MAX_URL_CHARS', 2048);
define('TITLE_MAX', 300);
define('SUMMARY_MAX', 160);
define('FUTURE_TOLERANCE_MS', 7 * 24 * 3600 * 1000);
define('MIN_PUBLISH_MS', 946684800000); // 2000-01-01T00:00:00Z
define('USER_AGENT', 'elePHPantDeals/1.0 (+single-file; cron-driven)');

/* ============================ 数据源（固定） ============================ */

function sources(): array
{
    return [
        ['id' => 'linuxdo',   'name' => 'LINUX DO福利羊毛', 'url' => 'https://r.jina.ai/https://linux.do/c/welfare/36.rss', 'hosts' => ['linux.do']],
        ['id' => 'v2ex',      'name' => 'V2EX推广',          'url' => 'https://www.v2ex.com/feed/promotions.xml',          'hosts' => ['v2ex.com']],
        ['id' => 'epic',      'name' => 'Epic',              'url' => 'https://store-site-backend-static.ak.epicgames.com/freeGamesPromotions?locale=zh-CN&country=CN&allowCountries=CN', 'hosts' => ['store.epicgames.com']],
        ['id' => 'nodeseek',  'name' => 'NodeSeek',          'url' => 'https://rss.nodeseek.com/',                        'hosts' => ['nodeseek.com']],
        ['id' => 'appinn',    'name' => '小众软件',           'url' => 'https://www.appinn.com/feed/',                     'hosts' => ['appinn.com']],
        ['id' => 'ghxi',      'name' => '果核剥壳',           'url' => 'https://www.ghxi.com/feed',                        'hosts' => ['ghxi.com']],
        ['id' => 'iplaysoft', 'name' => '异次元软件世界',      'url' => 'https://www.iplaysoft.com/feed',                   'hosts' => ['iplaysoft.com']],
    ];
}

/* ============================ 基础设施 ============================ */

function nowMs(): int
{
    return (int) round(microtime(true) * 1000);
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    ensureDataDir();
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS deals (
  id INTEGER PRIMARY KEY,
  canonical_url TEXT NOT NULL UNIQUE,
  url TEXT NOT NULL,
  title TEXT NOT NULL,
  summary TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL,
  source_id TEXT NOT NULL,
  published_at INTEGER,
  expires_at INTEGER,
  first_seen_at INTEGER NOT NULL,
  first_seen_round_id INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  ref_at INTEGER
);
CREATE TABLE IF NOT EXISTS rounds (
  id INTEGER PRIMARY KEY,
  trigger TEXT NOT NULL,
  started_at INTEGER NOT NULL,
  finished_at INTEGER,
  status TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS source_runs (
  id INTEGER PRIMARY KEY,
  round_id INTEGER NOT NULL,
  source_id TEXT NOT NULL,
  ok INTEGER NOT NULL,
  fetched_count INTEGER NOT NULL DEFAULT 0,
  inserted_count INTEGER NOT NULL DEFAULT 0,
  error TEXT,
  final_url TEXT,
  started_at INTEGER NOT NULL,
  finished_at INTEGER
);
CREATE TABLE IF NOT EXISTS meta (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_deals_source ON deals(source_id);
CREATE INDEX IF NOT EXISTS idx_source_runs_round ON source_runs(round_id);
CREATE INDEX IF NOT EXISTS idx_source_runs_source ON source_runs(source_id, id DESC);
SQL
    );
    // 迁移：旧库补 ref_at 列并回填，避免升级后比较失效
    $cols = $pdo->query('PRAGMA table_info(deals)')->fetchAll();
    $hasRef = false;
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'ref_at') { $hasRef = true; break; }
    }
    if (!$hasRef) {
        $pdo->exec('ALTER TABLE deals ADD COLUMN ref_at INTEGER');
    }
    $pdo->exec('UPDATE deals SET ref_at = COALESCE(published_at, first_seen_at) WHERE ref_at IS NULL');
    // 索引必须在补列之后建：旧库缺列时在 schema SQL 里建索引会抛错并中断迁移。
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_deals_ref ON deals(ref_at)');
    return $pdo;
}

function metaGet(PDO $db, string $key): ?string
{
    $st = $db->prepare('SELECT value FROM meta WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function metaSet(PDO $db, string $key, string $value): void
{
    $st = $db->prepare('INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $st->execute([$key, $value]);
}

function jsonOut($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ============================ 文本 / 时间 / 链接 ============================ */

function uSubstr(string $s, int $n): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $n, 'UTF-8');
    }
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return implode('', array_slice($chars ?: [], 0, $n));
}

function plainText(string $s): string
{
    $s = preg_replace('/<!\[CDATA\[([\s\S]*?)\]\]>/', '$1', $s) ?? $s;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/<script[\s\S]*?<\/script>/i', ' ', $s) ?? $s;
    $s = preg_replace('/<style[\s\S]*?<\/style>/i', ' ', $s) ?? $s;
    $s = strip_tags($s);
    $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', ' ', $s) ?? $s;
    $s = preg_replace('/[+*=_~#]{8,}/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

function cleanTitle(string $s): string
{
    return uSubstr(plainText($s), TITLE_MAX);
}

function excerpt(string $s): string
{
    $p = plainText($s);
    if (uSubstr($p, SUMMARY_MAX + 1) === $p && mb_strlenSafe($p) <= SUMMARY_MAX) {
        return $p;
    }
    return rtrim(uSubstr($p, SUMMARY_MAX)) . '…';
}

function mb_strlenSafe(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return count(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

function publishTimeMs($value, int $now): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $ts = strtotime(trim($value));
    if ($ts === false) {
        return null;
    }
    $ms = $ts * 1000;
    if ($ms < MIN_PUBLISH_MS || $ms > $now + FUTURE_TOLERANCE_MS) {
        return null;
    }
    return $ms;
}

/** 规范化链接：去重键与外链。拒绝非 http(s)、凭证、跨站由调用方过滤。 */
function canonicalUrl(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > MAX_URL_CHARS) {
        throw new RuntimeException('无效链接');
    }
    $p = parse_url($raw);
    if (!is_array($p) || !isset($p['scheme']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        throw new RuntimeException('无效链接');
    }
    if (isset($p['user']) || isset($p['pass'])) {
        throw new RuntimeException('无效链接');
    }
    $host = strtolower($p['host'] ?? '');
    if ($host === '') {
        throw new RuntimeException('无效链接');
    }
    $scheme = strtolower($p['scheme']);
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = $p['path'] ?? '';
    if (strlen($path) > 1) {
        $path = rtrim($path, '/');
    }
    $params = [];
    if (isset($p['query']) && $p['query'] !== '') {
        parse_str($p['query'], $parsed);
        foreach ((array) $parsed as $k => $v) {
            if (preg_match('/^(utm_|spm$|spm_|ref$|fbclid$|gclid$|mc_)/i', (string) $k)) {
                continue;
            }
            $params[(string) $k] = $v;
        }
        ksort($params);
    }
    $query = $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
    return $scheme . '://' . $host . $port . $path . $query;
}

function onSourceDomain(string $url, array $hosts): bool
{
    $h = parse_url($url, PHP_URL_HOST);
    if (!is_string($h) || $h === '') {
        return false;
    }
    $h = strtolower($h);
    foreach ($hosts as $allowed) {
        $allowed = strtolower($allowed);
        if ($h === $allowed || substr($h, -strlen('.' . $allowed)) === '.' . $allowed) {
            return true;
        }
    }
    return false;
}

/* ============================ 分类（移植自 classify.ts） ============================ */

function tokenHit(string $title, string $word): bool
{
    return (bool) preg_match('/(?:^|[^A-Za-z])' . preg_quote($word, '/') . '(?:[^A-Za-z]|$)/i', $title);
}

function classifyCategory(string $title, string $sourceId): string
{
    if ($sourceId === 'epic') {
        return '游戏';
    }
    $strong = (bool) preg_match('/限免|免费领|优惠[码券]|折扣码|兑换码|大促|促销|特价|降价|打折|爆降|折扣|永免|白嫖|秒杀|立减|满减|返现|返利|半价|史低|新低|羊毛|薅|红包|福利|赠送|买一送一|首月|黑五|双十一|双\s*11|(?:^|[^\d.])\d(?:\.\d)?\s*折|[0零]\s*元(?:购|领|撸|体验|试用)/u', $title);
    $medium = (bool) preg_match('/免费|优惠|(?:注册|新人|新用户|评论|留言)[^，。,.!！]{0,6}送|送[^，。,.!！]{0,8}(?:额度|会员|流量|时长|积分|余额)|体验额度|低至|\d+\s*%\s*off/iu', $title);
    $membership = (bool) preg_match('/会员|订阅|套餐/u', $title);
    $game = tokenHit($title, 'epic') || tokenHit($title, 'steam') || (bool) preg_match('/游戏|喜加一/u', $title);
    $hardNoise = (bool) preg_match('/抽奖|开奖|抽取|求购|收购|求助/u', $title)
        || (bool) preg_match('/(?:^|[\s【\[])(?:收|求)(?:购|助|一个|一下|个)?(?:[A-Za-z0-9._-]{0,16}|团队|管理员|家庭|企业|共享|朋友|个人|学生|开发者|机场)?[\s:：]*(?:优惠码|会员|账号|主机|路由器|套餐|商品|上车|自建)/u', $title);
    $trade = (bool) preg_match('/(?:^|[【\[])\s*(?:出|收)\s*(?:[】\]]|\s|\d|个|一|台|张|几)|(?:^|[\s+＋【\[])\d+(?:\.\d+)?\s*(?:元|块|刀|[rRuU¥$])?\s*(?:出|收)(?![现来去入到集藏获录费益购])|清机出|(?:总价|打包|溢价|原价|平价|折价)\s*\d*\s*(?:元|刀|[rRuU])?\s*(?:出|收)|车找人|人找车|拼车|合租/u', $title);
    $editorial = (bool) preg_match('/闲聊|讨论|新闻|资讯|评测|测评|避雷|吐槽|预测/u', $title);
    $question = (bool) preg_match('/有没有|有无|是否|请问|能不能|可不可以|为什么|为啥|怎么|如何|有什么|什么价|是啥|哪里有|哪个好|吗\s*[?？]|吗\s*$/u', $title);

    $score = ($strong ? 5 : ($medium ? 3 : (($membership || $game) ? 2 : 0)))
        - ($hardNoise ? 10 : 0)
        - ($trade ? 6 : 0)
        - ($editorial ? 5 : 0)
        - ($question ? 4 : 0)
        - ((preg_match('/[?？]/u', $title) && !$strong) ? 1 : 0);

    if ($score < 2) {
        return '其他';
    }
    if ($game) {
        return '游戏';
    }
    if ($membership) {
        return '会员订阅';
    }
    if (preg_match('/限免|免费领/u', $title)) {
        return '限免';
    }
    if (preg_match('/白嫖|免费|永免|赠送|羊毛|薅|红包|福利|返现|返利|体验额度|送[^，。,.!！]{0,8}(?:额度|会员|流量|时长|积分|余额)|[0零]\s*元/u', $title)) {
        return '免费白嫖';
    }
    return '低价优惠';
}

/* ============================ 在架规则 ============================ */

function isStale(?int $publishedAt, int $firstSeenAt, ?int $expiresAt, int $now): bool
{
    if ($expiresAt !== null && $expiresAt <= $now) {
        return true;
    }
    $ref = $publishedAt ?? $firstSeenAt;
    return $ref < $now - RETENTION_MS;
}

/* ============================ HTTP 抓取 ============================ */

function httpGet(string $url, array $headers): array
{
    $body = '';
    $tooBig = false;
    $retryAfterMs = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_S,
        CURLOPT_TIMEOUT => SOURCE_TIMEOUT_S,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfterMs) {
            if (preg_match('/^retry-after:\s*(.+)$/i', trim($line), $m)) {
                $v = trim($m[1]);
                if (preg_match('/^\d+(?:\.\d+)?$/', $v)) {
                    $retryAfterMs = (int) ((float) $v * 1000);
                } else {
                    $t = strtotime($v);
                    if ($t !== false) {
                        $retryAfterMs = max(0, $t * 1000 - nowMs());
                    }
                }
                $retryAfterMs = min(6 * 3600 * 1000, $retryAfterMs);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$body, &$tooBig) {
            if (strlen($body) + strlen($data) > MAX_RESPONSE_BYTES) {
                $tooBig = true;
                return 0;
            }
            $body .= $data;
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($tooBig) {
        return [false, '', $status, $finalUrl, 0, '响应过大'];
    }
    if ($ok === false) {
        return [false, '', $status, $finalUrl, 0, $err !== '' ? $err : '网络错误'];
    }
    return [true, $body, $status, $finalUrl, $retryAfterMs, ''];
}

function fetchSourceText(array $source, int $now): array
{
    $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, application/json, text/plain, */*'];
    if ($source['id'] === 'linuxdo') {
        $headers[] = 'Accept: text/markdown';
        $key = JINA_API_KEY;
        if ($key === '') {
            $env = getenv('JINA_API_KEY');
            $key = $env === false ? '' : trim($env);
        }
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
    }
    for ($attempt = 0; $attempt < MAX_ATTEMPTS; $attempt++) {
        [$ok, $body, $status, $finalUrl, $retryMs, $err] = httpGet($source['url'], $headers);
        if ($ok && $status >= 200 && $status < 300) {
            return [true, $body, $finalUrl, ''];
        }
        $canRetry = ($status === 429 || $status === 503 || $status === 0) && $attempt < MAX_ATTEMPTS - 1;
        if (!$canRetry) {
            $msg = $status > 0 ? 'HTTP ' . $status . ($status === 429 ? ' rate_limited' : '') : ($err !== '' ? $err : '抓取失败');
            return [false, '', $finalUrl, mb_strlenSafe($msg) > 180 ? uSubstr($msg, 180) : $msg];
        }
        $wait = min(RETRY_WAIT_CAP_MS, $retryMs > 0 ? $retryMs : 1000 * ($attempt + 1));
        usleep($wait * 1000);
    }
    return [false, '', '', '抓取失败'];
}

/* ============================ 解析器 ============================ */

/** RSS 2.0 / Atom，命名空间无关（按 localName 取节点）。 */
function parseFeed(string $xml, int $now): array
{
    $head = strtolower(substr($xml, 0, 400));
    if (strpos($head, '<!doctype html') !== false || preg_match('/<html[\s>]/', $head)) {
        throw new RuntimeException('返回的是网页而不是订阅源，可能被阻断');
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) {
        throw new RuntimeException('未能解析 RSS/Atom');
    }

    $records = [];
    foreach ($doc->getElementsByTagName('*') as $node) {
        $ln = $node->localName;
        if ($ln === 'item' || $ln === 'entry') {
            $records[] = $node;
        }
    }
    if (count($records) === 0) {
        throw new RuntimeException('未能解析 RSS/Atom');
    }

    $items = [];
    foreach (array_slice($records, 0, MAX_FEED_ITEMS) as $rec) {
        $title = cleanTitle(childText($rec, ['title']));
        $url = extractLink($rec);
        if ($title === '' || $url === '') {
            continue;
        }
        $summary = childText($rec, ['description', 'summary', 'encoded', 'content']);
        $published = null;
        foreach (['pubDate', 'published', 'updated', 'date'] as $field) {
            $published = publishTimeMs(childText($rec, [$field]), $now);
            if ($published !== null) {
                break;
            }
        }
        $items[] = [
            'url' => $url,
            'title' => $title,
            'summary' => excerpt($summary),
            'publishedAt' => $published,
            'expiresAt' => null,
        ];
    }
    return $items;
}

function childText(DOMNode $node, array $names): string
{
    foreach ($node->childNodes as $c) {
        if ($c->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        if (in_array($c->localName, $names, true)) {
            $t = plainText($c->textContent);
            if ($t !== '') {
                return $t;
            }
        }
    }
    return '';
}

function extractLink(DOMNode $item): string
{
    $fallback = '';
    foreach ($item->childNodes as $c) {
        if ($c->nodeType !== XML_ELEMENT_NODE || $c->localName !== 'link') {
            continue;
        }
        if ($c->hasAttribute('href')) {
            $rel = $c->getAttribute('rel');
            $href = trim($c->textContent === '' ? $c->getAttribute('href') : $c->getAttribute('href'));
            if ($rel === '' || $rel === 'alternate') {
                return $href;
            }
            if ($fallback === '') {
                $fallback = $href;
            }
        } else {
            $t = trim($c->textContent);
            if ($t !== '') {
                return $t;
            }
        }
    }
    return $fallback;
}

/** Epic：只收当前免费且折扣为 0 的促销。 */
function parseEpic(string $body, int $now): array
{
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Epic 响应不是 JSON');
    }
    $elements = $payload['data']['Catalog']['searchStore']['elements'] ?? null;
    if (!is_array($elements)) {
        throw new RuntimeException('Epic 响应缺少商品列表');
    }
    if (count($elements) === 0) {
        throw new RuntimeException('Epic 商品列表为空');
    }

    $items = [];
    $seen = [];
    foreach ($elements as $el) {
        if (!is_array($el)) {
            throw new RuntimeException('Epic 商品列表结构异常');
        }
        if (!array_key_exists('promotions', $el) || !isset($el['title']) || !is_string($el['title']) || trim($el['title']) === '') {
            throw new RuntimeException('Epic 商品列表结构异常');
        }
        $dp = $el['price']['totalPrice']['discountPrice'] ?? null;
        if (!is_int($dp) && !is_float($dp)) {
            throw new RuntimeException('Epic 商品列表结构异常');
        }
        $promo = $el['promotions'];
        // 上游对无促销商品会返回 promotions:null，属正常情况，跳过该商品即可。
        if ($promo === null) {
            continue;
        }
        if (!is_array($promo) || !isset($promo['promotionalOffers']) || !is_array($promo['promotionalOffers'])) {
            throw new RuntimeException('Epic 商品列表结构异常');
        }
        foreach ($promo['promotionalOffers'] as $group) {
            if (!is_array($group) || !isset($group['promotionalOffers']) || !is_array($group['promotionalOffers'])) {
                throw new RuntimeException('Epic 商品列表促销结构异常');
            }
            foreach ($group['promotionalOffers'] as $offer) {
                if (!is_array($offer) || !isset($offer['startDate'], $offer['endDate'])
                    || !is_string($offer['startDate']) || !is_string($offer['endDate'])
                    || !is_numeric(strtotime($offer['startDate'])) || !is_numeric(strtotime($offer['endDate']))
                    || strtotime($offer['endDate']) <= strtotime($offer['startDate'])
                    || !isset($offer['discountSetting']['discountPercentage'])
                    || !is_numeric($offer['discountSetting']['discountPercentage'])) {
                    throw new RuntimeException('Epic 商品列表促销结构异常');
                }
            }
        }
        if (abs((float) $dp) > 0.000001) {
            continue;
        }
        $active = [];
        foreach ($promo['promotionalOffers'] as $group) {
            foreach ($group['promotionalOffers'] as $offer) {
                $s = strtotime($offer['startDate']);
                $e = strtotime($offer['endDate']);
                $p = (float) $offer['discountSetting']['discountPercentage'];
                if ($s <= $now / 1000 && $now / 1000 < $e && ($p === 0.0 || $p === 100.0)) {
                    $active[] = $offer;
                }
            }
        }
        if (count($active) === 0) {
            continue;
        }
        $slug = epicSlug($el);
        $title = cleanTitle((string) $el['title']);
        if ($slug === null || $title === '' || strpos($slug, '..') !== false || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $slug)) {
            throw new RuntimeException('Epic 商品列表链接结构异常');
        }
        $url = 'https://store.epicgames.com/zh-CN/p/' . $slug;
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $ends = [];
        $starts = [];
        foreach ($active as $o) {
            $ends[] = strtotime($o['endDate']) * 1000;
            $starts[] = strtotime($o['startDate']) * 1000;
        }
        $items[] = [
            'url' => $url,
            'title' => $title,
            'summary' => excerpt(isset($el['description']) && is_string($el['description']) ? $el['description'] : ''),
            'publishedAt' => min($starts),
            'expiresAt' => max($ends),
        ];
    }
    return $items;
}

function epicSlug(array $el): ?string
{
    $mappings = [];
    foreach (['offerMappings', 'catalogNs'] as $key) {
        $m = $el[$key]['mappings'] ?? null;
        if (is_array($m)) {
            $mappings = array_merge($mappings, $m);
        }
    }
    foreach ($mappings as $m) {
        if (is_array($m) && ($m['pageType'] ?? '') === 'productHome'
            && isset($m['pageSlug']) && is_string($m['pageSlug'])
            && $m['pageSlug'] !== '' && $m['pageSlug'] !== 'home') {
            return $m['pageSlug'];
        }
    }
    if (isset($el['productSlug']) && is_string($el['productSlug']) && $el['productSlug'] !== '') {
        return $el['productSlug'];
    }
    return null;
}

/** Jina reader 返回的 Markdown。 */
function parseLinuxDo(string $md, int $now): array
{
    if (strpos($md, 'linux.do/t/topic/') === false) {
        throw new RuntimeException('返回内容不是 LINUX DO 福利列表，可能被阻断');
    }
    $chunks = preg_split('/^#{2,4} \[/m', $md);
    if ($chunks === false) {
        throw new RuntimeException('未能从 Markdown 解析出条目');
    }
    array_shift($chunks);
    $items = [];
    foreach (array_slice($chunks, 0, MAX_FEED_ITEMS) as $chunk) {
        $end = strpos($chunk, '](');
        if ($end === false) {
            continue;
        }
        $title = cleanTitle(substr($chunk, 0, $end));
        $rest = substr($chunk, $end);
        if (!preg_match('/^\]\((https?:\/\/linux\.do\/t\/topic\/\d+)\)/', $rest, $um)) {
            continue;
        }
        $datePattern = '/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{1,2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}\b/';
        if (!preg_match($datePattern, $chunk, $dm)) {
            continue;
        }
        if ($title === '') {
            continue;
        }
        $items[] = [
            'url' => $um[1],
            'title' => $title,
            'summary' => '',
            'publishedAt' => publishTimeMs($dm[0], $now),
            'expiresAt' => null,
        ];
    }
    if (count($items) === 0) {
        throw new RuntimeException('未能从 Markdown 解析出条目');
    }
    return $items;
}

/* ============================ 入库 ============================ */

function findDeal(PDO $db, string $canonical): ?array
{
    $st = $db->prepare('SELECT * FROM deals WHERE canonical_url = ?');
    $st->execute([$canonical]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function insertRound(PDO $db, string $trigger, int $startedAt): int
{
    $st = $db->prepare('INSERT INTO rounds(trigger,started_at,finished_at,status) VALUES(?,?,NULL,?)');
    $st->execute([$trigger, $startedAt, 'running']);
    return (int) $db->lastInsertId();
}

function finishRound(PDO $db, int $roundId, int $finishedAt, string $status): void
{
    $st = $db->prepare('UPDATE rounds SET finished_at=?, status=? WHERE id=?');
    $st->execute([$finishedAt, $status, $roundId]);
}

function insertSourceRun(PDO $db, int $roundId, array $r): void
{
    $st = $db->prepare('INSERT INTO source_runs(round_id,source_id,ok,fetched_count,inserted_count,error,final_url,started_at,finished_at)
                       VALUES(?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $roundId,
        $r['source_id'],
        $r['ok'] ? 1 : 0,
        $r['fetched_count'],
        $r['inserted_count'],
        $r['error'],
        $r['final_url'],
        $r['started_at'],
        $r['finished_at'],
    ]);
}

/** 返回 inserted 数。失败不改动该源已有条目；Epic 成功后对账撤下。 */
function applySource(PDO $db, array $source, bool $ok, array $items, string $error, string $finalUrl, int $roundId, int $startedAt, int $finishedAt, int $now): int
{
    if (!$ok) {
        insertSourceRun($db, $roundId, [
            'source_id' => $source['id'], 'ok' => false, 'fetched_count' => 0,
            'inserted_count' => 0, 'error' => $error, 'final_url' => null,
            'started_at' => $startedAt, 'finished_at' => $finishedAt,
        ]);
        return 0;
    }

    $inserted = 0;
    $keep = [];
    foreach ($items as $item) {
        try {
            $canonical = canonicalUrl($item['url']);
        } catch (Throwable $e) {
            continue;
        }
        if (!onSourceDomain($canonical, $source['hosts'])) {
            continue;
        }
        if (isStale($item['publishedAt'], $now, $item['expiresAt'], $now)) {
            continue;
        }
        $title = trim($item['title']);
        if ($title === '') {
            continue;
        }
        $existing = findDeal($db, $canonical);
        if ($existing !== null && $existing['source_id'] !== $source['id']) {
            continue;
        }
        $category = classifyCategory($title, $source['id']);
        if ($existing === null) {
            $refAt = $item['publishedAt'] ?? $now; // 发布时间优先，缺失时用首次入库时间；与在架规则一致
            $st = $db->prepare('INSERT INTO deals(canonical_url,url,title,summary,category,source_id,published_at,expires_at,first_seen_at,first_seen_round_id,updated_at,ref_at)
                                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$canonical, $canonical, $title, $item['summary'], $category, $source['id'],
                $item['publishedAt'], $item['expiresAt'], $now, $roundId, $now, $refAt]);
            $inserted++;
        } else {
            $published = $item['publishedAt'] ?? ($existing['published_at'] !== null ? (int) $existing['published_at'] : null);
            $refAt = $published ?? (int) $existing['first_seen_at'];
            $st = $db->prepare('UPDATE deals SET url=?, title=?, summary=?, category=?, published_at=?, expires_at=?, updated_at=?, ref_at=? WHERE id=?');
            $st->execute([$canonical, $title, $item['summary'], $category, $published, $item['expiresAt'], $now, $refAt, (int) $existing['id']]);
        }
        $keep[$canonical] = true;
    }

    if ($source['id'] === 'epic') {
        $st = $db->prepare("SELECT id, canonical_url FROM deals WHERE source_id='epic'");
        $st->execute();
        foreach ($st->fetchAll() as $row) {
            if (!isset($keep[$row['canonical_url']])) {
                $d = $db->prepare('DELETE FROM deals WHERE id=?');
                $d->execute([(int) $row['id']]);
            }
        }
    }

    insertSourceRun($db, $roundId, [
        'source_id' => $source['id'], 'ok' => true, 'fetched_count' => count($items),
        'inserted_count' => $inserted, 'error' => null, 'final_url' => $finalUrl,
        'started_at' => $startedAt, 'finished_at' => $finishedAt,
    ]);
    return $inserted;
}

function purgeStale(PDO $db, int $now): void
{
    $st = $db->prepare('SELECT id, published_at, first_seen_at, expires_at FROM deals');
    $st->execute();
    foreach ($st->fetchAll() as $row) {
        if (isStale(
            $row['published_at'] !== null ? (int) $row['published_at'] : null,
            (int) $row['first_seen_at'],
            $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
            $now
        )) {
            $d = $db->prepare('DELETE FROM deals WHERE id=?');
            $d->execute([(int) $row['id']]);
        }
    }
}

/* ============================ 刷新（每次一个源） ============================ */

function handleRefresh(): void
{
    $token = $_GET['refresh'] ?? '';
    if (!is_string($token) || !hash_equals(ACCESS_TOKEN, $token)) {
        jsonOut(['error' => 'forbidden'], 403);
        return;
    }

    $db = db();

    // 先做一次无锁冷却检查，快速返回
    $now = nowMs();
    $last = metaGet($db, 'last_refresh_at');
    if ($last !== null && $now - (int) $last < REFRESH_COOLDOWN_MS) {
        jsonOut(['skipped' => 'cooldown', 'retry_after_ms' => REFRESH_COOLDOWN_MS - ($now - (int) $last)]);
        return;
    }

    ensureDataDir();
    $lock = @fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        jsonOut(['skipped' => 'busy']);
        return;
    }

    try {
        // 拿锁后再查一次冷却，避免并发下重复抓取
        $now = nowMs();
        $last = metaGet($db, 'last_refresh_at');
        if ($last !== null && $now - (int) $last < REFRESH_COOLDOWN_MS) {
            jsonOut(['skipped' => 'cooldown', 'retry_after_ms' => REFRESH_COOLDOWN_MS - ($now - (int) $last)]);
            return;
        }
        metaSet($db, 'last_refresh_at', (string) $now);

        $sources = sources();
        $cursorRaw = metaGet($db, 'source_cursor');
        $cursor = $cursorRaw === null ? 0 : (int) $cursorRaw;
        if ($cursor < 0 || $cursor >= count($sources)) {
            $cursor = 0;
        }
        $source = $sources[$cursor];

        $startedAt = nowMs();
        $roundId = insertRound($db, 'cron-http', $startedAt);
        $ok = false;
        $items = [];
        $error = '';
        $finalUrl = '';

        try {
            [$ok, $body, $finalUrl, $error] = fetchSourceText($source, $startedAt);
            if ($ok) {
                if ($source['id'] === 'epic') {
                    $items = parseEpic($body, $startedAt);
                } elseif ($source['id'] === 'linuxdo') {
                    $items = parseLinuxDo($body, $startedAt);
                } else {
                    $items = parseFeed($body, $startedAt);
                }
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_strlenSafe($e->getMessage()) > 180 ? uSubstr($e->getMessage(), 180) : $e->getMessage();
            $items = [];
        }

        $finishedAt = nowMs();
        $inserted = applySource($db, $source, $ok, $items, $ok ? '' : $error, $finalUrl, $roundId, $startedAt, $finishedAt, $startedAt);
        finishRound($db, $roundId, $finishedAt, $ok ? 'success' : 'failed');
        purgeStale($db, $finishedAt);

        $next = ($cursor + 1) % count($sources);
        metaSet($db, 'source_cursor', (string) $next);

        jsonOut([
            'ok' => true,
            'round_id' => $roundId,
            'source' => $source['id'],
            'source_name' => $source['name'],
            'status' => $ok ? 'success' : 'failed',
            'error' => $ok ? null : $error,
            'fetched' => count($items),
            'inserted' => $inserted,
            'next_source' => $sources[$next]['id'],
            'duration_ms' => $finishedAt - $startedAt,
        ]);
    } catch (Throwable $e) {
        jsonOut(['error' => 'refresh_failed', 'message' => $e->getMessage()], 500);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* ============================ 看板数据 ============================ */

/** 全量统计在架数与本轮新增数（不受展示上限影响），供看板与轻量状态共用。 */
function activeStats(PDO $db, int $now, int $cutoff, int $latestRoundId): array
{
    $where = '(expires_at IS NULL OR expires_at > :now) AND ref_at >= :cutoff';
    $st = $db->prepare("SELECT COUNT(*) FROM deals WHERE $where");
    $st->bindValue(':now', $now, PDO::PARAM_INT);
    $st->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
    $st->execute();
    $activeCount = (int) $st->fetchColumn();

    $st = $db->prepare("SELECT COUNT(*) FROM deals WHERE $where AND first_seen_round_id = :rid");
    $st->bindValue(':now', $now, PDO::PARAM_INT);
    $st->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
    $st->bindValue(':rid', $latestRoundId, PDO::PARAM_INT);
    $st->execute();
    return ['activeCount' => $activeCount, 'newCount' => (int) $st->fetchColumn()];
}

/** 最近一轮与各来源健康、新鲜度——看板与轻量状态共用的头信息。 */
function boardHead(PDO $db, int $now): array
{
    $lastRound = $db->query('SELECT id, finished_at, status FROM rounds ORDER BY id DESC LIMIT 1')->fetch();
    $latestRoundId = $lastRound === false ? -1 : (int) $lastRound['id'];
    $finishedAt = ($lastRound !== false && $lastRound['finished_at'] !== null) ? (int) $lastRound['finished_at'] : null;
    $age = $finishedAt === null ? null : max(0, $now - $finishedAt);

    $sourceStatus = [];
    foreach (sources() as $s) {
        $q = $db->prepare('SELECT ok, error, finished_at, fetched_count FROM source_runs WHERE source_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$s['id']]);
        $run = $q->fetch();
        $sourceStatus[] = [
            'id' => $s['id'],
            'name' => $s['name'],
            'lastOk' => $run === false ? null : ((int) $run['ok'] === 1),
            'lastError' => $run === false || $run['error'] === null ? null : (string) $run['error'],
            'lastFinished' => $run === false || $run['finished_at'] === null ? null : (int) $run['finished_at'],
            'lastFetched' => $run === false ? null : (int) $run['fetched_count'],
        ];
    }

    $cursorRaw = metaGet($db, 'source_cursor');
    $cursor = $cursorRaw === null ? 0 : (int) $cursorRaw;
    $all = sources();

    return [
        'generatedAt' => $now,
        'lastRoundId' => $latestRoundId < 0 ? null : $latestRoundId,
        'lastRound' => [
            'id' => $latestRoundId < 0 ? null : $latestRoundId,
            'status' => $lastRound === false ? null : (string) $lastRound['status'],
        ],
        'sourceHealth' => [
            'ok' => count(array_filter($sourceStatus, function ($s) { return $s['lastOk'] === true; })),
            'total' => count($sourceStatus),
        ],
        'sources' => $sourceStatus,
        'freshness' => [
            'lastFinished' => $finishedAt,
            'ageMs' => $age,
            'stale' => $age !== null && $age > STALE_AFTER_MS,
            'severe' => $age !== null && $age > SEVERE_AFTER_MS,
        ],
        'rotation' => [
            'nextSource' => $all[$cursor % count($all)]['id'],
            'nextSourceName' => $all[$cursor % count($all)]['name'],
        ],
        '_cutoff' => $now - RETENTION_MS,
        '_latestRoundId' => $latestRoundId,
    ];
}

function buildBoard(PDO $db): array
{
    $now = nowMs();
    $cutoff = $now - RETENTION_MS;

    // ref_at 是入库时算好的 COALESCE(published_at, first_seen_at)，可直接走索引，避免全表扫描。
    // LIMIT 用整数绑定，防止字符串绑定导致 SQLite 忽略限制。
    $st = $db->prepare('SELECT * FROM deals
        WHERE (expires_at IS NULL OR expires_at > :now)
          AND ref_at >= :cutoff
        ORDER BY ref_at DESC, id DESC
        LIMIT :lim');
    $st->bindValue(':now', $now, PDO::PARAM_INT);
    $st->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
    $st->bindValue(':lim', MAX_BOARD_ITEMS, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $head = boardHead($db, $now);
    $latestRoundId = $head['_latestRoundId'];
    $stats = activeStats($db, $now, $head['_cutoff'], $latestRoundId);

    $deals = [];
    foreach ($rows as $r) {
        $published = $r['published_at'] !== null ? (int) $r['published_at'] : null;
        $expires = $r['expires_at'] !== null ? (int) $r['expires_at'] : null;
        $deals[] = [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'url' => (string) $r['url'],
            'summary' => (string) $r['summary'],
            'category' => (string) $r['category'],
            'sourceId' => (string) $r['source_id'],
            'sourceName' => sourceNameById((string) $r['source_id']),
            'publishedAt' => $published,
            'expiresAt' => $expires,
            'expiresSoon' => $expires !== null && $expires - $now > 0 && $expires - $now <= EXPIRING_SOON_MS,
            'isNew' => (int) $r['first_seen_round_id'] === $latestRoundId,
        ];
    }

    unset($head['_cutoff'], $head['_latestRoundId']);
    return array_merge($head, [
        'version' => APP_VERSION,
        'deals' => $deals,
        'activeCount' => $stats['activeCount'],
        'newCount' => $stats['newCount'],
        'truncated' => $stats['activeCount'] > count($deals),
        'shownCount' => count($deals),
    ]);
}

/** 轻量状态：不含 deals 明细，供前端 30 秒探测是否有变化。 */
function buildStatus(PDO $db): array
{
    $now = nowMs();
    $head = boardHead($db, $now);
    $stats = activeStats($db, $now, $head['_cutoff'], $head['_latestRoundId']);
    unset($head['_cutoff'], $head['_latestRoundId']);
    return array_merge($head, [
        'activeCount' => $stats['activeCount'],
        'newCount' => $stats['newCount'],
    ]);
}

function sourceNameById(string $id): string
{
    foreach (sources() as $s) {
        if ($s['id'] === $id) {
            return $s['name'];
        }
    }
    return $id;
}

/* ============================ 入口分流 ============================ */

$uri = $_SERVER['REQUEST_URI'] ?? '';
if (isset($_GET['refresh'])) {
    handleRefresh();
    return;
}
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    jsonOut(buildStatus(db()));
    return;
}
if (isset($_GET['api']) && $_GET['api'] === 'board') {
    jsonOut(buildBoard(db()));
    return;
}

$board = buildBoard(db());
$boot = json_encode($board, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$template = <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>elePHPantDeals · 优惠线索看板</title>
<style>
:root{
  --bg:#f6f7f9; --panel:#fff; --text:#1c2026; --muted:#6b7280; --line:#e5e7eb;
  --accent:#2563eb; --accent-soft:#eff6ff; --red:#dc2626; --green:#059669; --amber:#d97706;
  --chip:#f3f4f6; --shadow:0 1px 2px rgba(0,0,0,.04),0 8px 24px rgba(0,0,0,.04);
}
@media(prefers-color-scheme:dark){
  :root{--bg:#111318;--panel:#1b1e24;--text:#e5e7eb;--muted:#9ca3af;--line:#2f333b;
  --accent:#60a5fa;--accent-soft:#1e293b;--red:#f87171;--green:#34d399;--amber:#fbbf24;--chip:#252932;--shadow:none}
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:1080px;margin:0 auto;padding:24px 16px 64px}
h1{font-size:22px;margin:0 0 4px;letter-spacing:.3px}
.sub{color:var(--muted);font-size:12px;margin-bottom:18px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:16px;margin-bottom:14px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.stat .n{font-size:22px;font-weight:700}
.stat .l{color:var(--muted);font-size:12px}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:12px}
.banner.warn{background:rgba(217,119,6,.1);color:var(--amber)}
.banner.bad{background:rgba(220,38,38,.1);color:var(--red)}
.srcs{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.src{font-size:11px;padding:3px 8px;border-radius:99px;background:var(--chip);color:var(--muted);border:1px solid var(--line)}
.src.ok{color:var(--green)} .src.bad{color:var(--red)}
.controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.controls input,.controls select{background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit}
.controls input[type=search]{flex:1;min-width:200px}
.controls label{display:flex;align-items:center;gap:5px;color:var(--muted);font-size:12px;cursor:pointer}
.btn{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:8px 14px;font:inherit;cursor:pointer}
.btn.ghost{background:var(--chip);color:var(--text);border:1px solid var(--line)}
.btn:disabled{opacity:.55;cursor:not-allowed}
.refreshbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)}
.refreshbar input[type=password]{background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit;min-width:220px;flex:1}
.msg{font-size:12px}
.msg.ok{color:var(--green)} .msg.warn{color:var(--amber)} .msg.err{color:var(--red)} .msg.muted{color:var(--muted)}
.deal{padding:13px 4px;border-bottom:1px solid var(--line)}
.deal:last-child{border-bottom:0}
.deal .t{font-size:15px;font-weight:600;line-height:1.4}
.deal .m{color:var(--muted);font-size:11px;margin-top:5px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.deal .s{color:var(--muted);font-size:12px;margin-top:6px}
.tag{font-size:10px;padding:2px 7px;border-radius:99px;background:var(--chip);color:var(--muted)}
.tag.new{background:var(--red);color:#fff}
.tag.soon{background:rgba(217,119,6,.15);color:var(--amber)}
.tag.cat{background:var(--accent-soft);color:var(--accent)}
.ops{margin-top:7px;display:flex;gap:8px}
.ops button{font-size:11px;padding:3px 9px;border-radius:6px;border:1px solid var(--line);background:var(--panel);color:var(--muted);cursor:pointer}
.ops button.on{color:var(--accent);border-color:var(--accent)}
.ops button.fav.on{color:var(--amber);border-color:var(--amber)}
.more{width:100%;margin-top:12px;padding:10px;border:1px dashed var(--line);border-radius:8px;background:transparent;color:var(--muted);cursor:pointer}
.empty{color:var(--muted);text-align:center;padding:40px 0}
.foot{color:var(--muted);font-size:11px;text-align:center;margin-top:20px;line-height:1.8}
#toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111;color:#fff;padding:8px 16px;border-radius:8px;font-size:12px;opacity:0;pointer-events:none;transition:opacity .2s;z-index:9}
#toast.show{opacity:1}
</style>
</head>
<body>
<div class="wrap">
  <h1>elePHPantDeals <span style="font-size:12px;color:var(--muted);font-weight:400">优惠线索看板</span></h1>
  <div class="sub">数据仅供筛查，领取请以原文与官方说明为准。</div>

  <div class="panel">
    <div id="banner"></div>
    <div class="stats">
      <div class="stat"><div class="n" id="s-active">—</div><div class="l">在架线索</div></div>
      <div class="stat"><div class="n" id="s-new">—</div><div class="l">本轮新增</div></div>
      <div class="stat"><div class="n" id="s-health">—</div><div class="l">来源健康（最近一次）</div></div>
      <div class="stat"><div class="n" id="s-fresh">—</div><div class="l">数据新鲜度</div></div>
    </div>
    <div class="srcs" id="srcs"></div>
    <div class="refreshbar">
      <input type="password" id="rfKey" placeholder="输入 refresh key（即 ACCESS_TOKEN）" autocomplete="off" aria-label="refresh key">
      <button class="btn" id="rfGo">手动刷新</button>
      <span class="msg muted" id="rfMsg"></span>
    </div>
  </div>

  <div class="panel">
    <div class="controls">
      <input type="search" id="q" placeholder="搜索标题（字面包含）">
      <select id="source"><option value="">全部来源</option></select>
      <select id="category"><option value="">全部分类</option></select>
      <select id="sort">
        <option value="new">时间：新 → 旧</option>
        <option value="old">时间：旧 → 新</option>
        <option value="source">按来源分组</option>
      </select>
      <label><input type="checkbox" id="onlyNew"> 本轮新增</label>
      <label><input type="checkbox" id="onlyFav"> 我的收藏</label>
      <label><input type="checkbox" id="showAll"> 查看全部线索</label>
      <button class="btn ghost" id="reset">重置</button>
    </div>
  </div>

  <div class="panel">
    <div id="list"></div>
    <button class="more" id="more" style="display:none">加载更多</button>
  </div>

  <div class="foot">
    收藏与已领只保存在当前浏览器 localStorage，不上传、不写入数据库。<br>
    刷新通过 <code>index.php?refresh=TOKEN</code> 触发：可手动点击上方按钮，或由 cron / 外部定时服务定期访问 · <span id="ver"></span>
  </div>
</div>
<div id="toast"></div>

<script id="boot" type="application/json">{{BOOT}}</script>
<script>
(function(){
  "use strict";
  var D = JSON.parse(document.getElementById('boot').textContent);
  var KEY = 'elephpantdeals-v1';
  var OLD_KEY = 'perkradar-php-v1';
  var PAGE = 100;
  function loadPersonal(){
    try{
      var raw=localStorage.getItem(KEY);
      if(raw===null)raw=localStorage.getItem(OLD_KEY); // 兼容旧键，改名不丢收藏/已领
      var x=JSON.parse(raw||'null');
      if(x&&typeof x==='object')return{claimed:x.claimed||[],favorites:x.favorites||[],showAll:!!x.showAll};
    }catch(e){}
    return{claimed:[],favorites:[],showAll:false}
  }
  var P = loadPersonal();
  function savePersonal(){try{localStorage.setItem(KEY,JSON.stringify(P))}catch(e){toast('保存失败，本次操作仅在当前页有效')}}
  function toast(m){var t=document.getElementById('toast');t.textContent=m;t.className='show';setTimeout(function(){t.className=''},1800)}
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function fmt(ms){if(!ms)return '未知';var d=new Date(ms);var diff=Date.now()-ms;if(diff<60000)return '刚刚';if(diff<3600000)return Math.floor(diff/60000)+' 分钟前';if(diff<86400000)return Math.floor(diff/3600000)+' 小时前';if(diff<7*86400000)return Math.floor(diff/86400000)+' 天前';return d.toLocaleDateString('zh-CN')}
  function inArr(a,v){return a.indexOf(v)>=0}
  function toggle(a,v){var i=a.indexOf(v);if(i>=0)a.splice(i,1);else a.push(v);return a}

  var state={q:'',source:'',category:'',sort:'new',onlyNew:false,onlyFav:false,showAll:P.showAll,limit:PAGE};

  function initFilters(){
    var ss=document.getElementById('source'), cs=document.getElementById('category');
    var srcSeen={};
    D.deals.forEach(function(d){srcSeen[d.sourceId]=d.sourceName});
    Object.keys(srcSeen).sort().forEach(function(id){var o=document.createElement('option');o.value=id;o.textContent=srcSeen[id];ss.appendChild(o)});
    ['免费白嫖','限免','低价优惠','会员订阅','游戏','其他'].forEach(function(c){var o=document.createElement('option');o.value=c;o.textContent=c;cs.appendChild(o)});
    document.getElementById('showAll').checked=state.showAll;
  }

  function renderStats(){
    document.getElementById('s-active').innerHTML=D.activeCount+(D.truncated?'<span style="font-size:11px;color:var(--muted);font-weight:400"> （仅载入最新 '+D.shownCount+' 条）</span>':'');
    document.getElementById('s-new').textContent=D.newCount;
    document.getElementById('s-health').textContent=D.sourceHealth.ok+' / '+D.sourceHealth.total;
    var f=D.freshness, el=document.getElementById('s-fresh');
    el.textContent=f.lastFinished?fmt(f.lastFinished):'尚无数据';
    el.style.color=f.severe?'var(--red)':(f.stale?'var(--amber)':'');
    var b=document.getElementById('banner');
    if(f.severe){b.innerHTML='<div class="banner bad">数据严重过时：最近一轮完成于 '+fmt(f.lastFinished)+'，请检查 cron 是否仍在运行。</div>'}
    else if(f.stale){b.innerHTML='<div class="banner warn">数据可能过时：最近一轮完成于 '+fmt(f.lastFinished)+'。</div>'}
    else{b.innerHTML=''}
    document.getElementById('srcs').innerHTML=D.sources.map(function(s){
      var cls=s.lastOk===true?'ok':(s.lastOk===false?'bad':'');
      var label=s.lastOk===null?'未抓取':(s.lastOk?'正常 '+fmt(s.lastFinished):'失败 '+fmt(s.lastFinished));
      var tip=s.lastError?(' title="'+esc(s.lastError)+'"'):'';
      return '<span class="src '+cls+'"'+tip+'>'+esc(s.name)+' · '+label+'</span>';
    }).join('')+'<span class="src">下次轮到：'+esc(D.rotation.nextSourceName)+'</span>';
    document.getElementById('ver').textContent='版本 '+esc(D.version)+' · 本页生成于 '+fmt(D.generatedAt);
  }

  function filtered(){
    var q=state.q.trim().toLowerCase();
    var list=D.deals.filter(function(d){
      if(q&&d.title.toLowerCase().indexOf(q)<0)return false;
      if(state.source&&d.sourceId!==state.source)return false;
      if(state.category&&d.category!==state.category)return false;
      if(state.onlyNew&&!d.isNew)return false;
      if(state.onlyFav&&!inArr(P.favorites,d.url))return false;
      if(!state.showAll&&!q&&!state.source&&!state.category&&!state.onlyFav&&d.category==='其他')return false;
      return true;
    });
    if(state.sort==='new')list.sort(function(a,b){return (b.publishedAt||b.expiresAt||0)-(a.publishedAt||a.expiresAt||0)});
    else if(state.sort==='old')list.sort(function(a,b){return (a.publishedAt||a.expiresAt||0)-(b.publishedAt||b.expiresAt||0)});
    else list.sort(function(a,b){return a.sourceId.localeCompare(b.sourceId,'zh')||((b.publishedAt||0)-(a.publishedAt||0))});
    return list;
  }

  function render(){
    var list=filtered();
    var shown=list.slice(0,state.limit);
    var box=document.getElementById('list');
    if(shown.length===0){box.innerHTML='<div class="empty">没有符合条件的线索</div>'}
    else{
      box.innerHTML=shown.map(function(d){
        var tags='<span class="tag cat">'+esc(d.category)+'</span><span class="tag">'+esc(d.sourceName)+'</span>';
        if(d.isNew)tags+='<span class="tag new">本轮新增</span>';
        if(d.expiresSoon)tags+='<span class="tag soon">即将到期</span>';
        var time=d.publishedAt?('发布 '+fmt(d.publishedAt)):(d.expiresAt?('截止 '+fmt(d.expiresAt)):'时间未知');
        var fav=inArr(P.favorites,d.url), claim=inArr(P.claimed,d.url);
        return '<div class="deal">'+
          '<div class="t"><a href="'+esc(d.url)+'" target="_blank" rel="noopener noreferrer">'+esc(d.title)+'</a></div>'+
          '<div class="m">'+tags+'<span>'+time+'</span></div>'+
          (d.summary?'<div class="s">'+esc(d.summary)+'</div>':'')+
          '<div class="ops">'+
            '<button class="fav'+(fav?' on':'')+'" data-act="fav" data-url="'+esc(d.url)+'">'+(fav?'★ 已收藏':'☆ 收藏')+'</button>'+
            '<button class="'+(claim?'on':'')+'" data-act="claim" data-url="'+esc(d.url)+'">'+(claim?'✓ 已领':'标记已领')+'</button>'+
          '</div></div>';
      }).join('');
    }
    var more=document.getElementById('more');
    more.style.display=list.length>state.limit?'block':'none';
    if(list.length>state.limit)more.textContent='加载更多（还有 '+(list.length-state.limit)+' 条）';
  }

  document.getElementById('list').addEventListener('click',function(e){
    var b=e.target.closest('button[data-act]');if(!b)return;
    var url=b.getAttribute('data-url'),act=b.getAttribute('data-act');
    if(act==='fav'){toggle(P.favorites,url);savePersonal();render()}
    else{toggle(P.claimed,url);savePersonal();render()}
  });
  document.getElementById('more').addEventListener('click',function(){state.limit+=PAGE;render()});
  document.getElementById('q').addEventListener('input',function(e){state.q=e.target.value;state.limit=PAGE;render()});
  document.getElementById('source').addEventListener('change',function(e){state.source=e.target.value;state.limit=PAGE;render()});
  document.getElementById('category').addEventListener('change',function(e){state.category=e.target.value;state.limit=PAGE;render()});
  document.getElementById('sort').addEventListener('change',function(e){state.sort=e.target.value;render()});
  document.getElementById('onlyNew').addEventListener('change',function(e){state.onlyNew=e.target.checked;state.limit=PAGE;render()});
  document.getElementById('onlyFav').addEventListener('change',function(e){state.onlyFav=e.target.checked;state.limit=PAGE;render()});
  document.getElementById('showAll').addEventListener('change',function(e){state.showAll=e.target.checked;P.showAll=state.showAll;savePersonal();state.limit=PAGE;render()});
  document.getElementById('reset').addEventListener('click',function(){
    state.q='';state.source='';state.category='';state.sort='new';state.onlyNew=false;state.onlyFav=false;state.showAll=false;state.limit=PAGE;
    P.showAll=false;savePersonal();
    document.getElementById('q').value='';document.getElementById('source').value='';document.getElementById('category').value='';
    document.getElementById('sort').value='new';document.getElementById('onlyNew').checked=false;
    document.getElementById('onlyFav').checked=false;document.getElementById('showAll').checked=false;
    render();
  });

  // 手动刷新：refresh key 仅随本次请求发送，不保存、不写入页面。
  var lastSig=D.lastRoundId+'|'+D.activeCount+'|'+D.newCount;
  var rfGo=document.getElementById('rfGo'), rfKey=document.getElementById('rfKey'), rfMsg=document.getElementById('rfMsg');
  function setRfMsg(text,cls){rfMsg.textContent=text;rfMsg.className='msg '+(cls||'muted')}
  function waitLabel(ms){
    if(typeof ms!=='number'||ms<=0)return '稍后';
    var s=Math.ceil(ms/1000);
    if(s<60)return s+' 秒';
    var m=Math.ceil(s/60);
    return m+' 分钟';
  }
  function manualRefresh(){
    var key=rfKey.value.trim();
    if(!key){setRfMsg('请先输入 refresh key','err');rfKey.focus();return}
    rfGo.disabled=true;setRfMsg('正在刷新…','muted');
    fetch('?refresh='+encodeURIComponent(key),{cache:'no-store'}).then(function(r){return r.json()}).then(function(d){
      rfGo.disabled=false;
      if(d.error==='forbidden'){setRfMsg('refresh key 不正确','err');return}
      if(d.skipped==='busy'){setRfMsg('已有刷新进行中，请稍后重试','warn');return}
      if(d.skipped==='cooldown'){setRfMsg('冷却期内跳过，请在 '+waitLabel(d.retry_after_ms)+' 后重试','warn');return}
      if(!d.ok){setRfMsg('刷新失败：'+(d.message||'未知错误'),'err');return}
      // 成功：显示已更新来源与新增数
      var detail='已更新来源：'+(d.source_name||d.source);
      if(d.status==='success')detail+='，新增 '+(d.inserted||0)+' 条';
      else detail+='（本轮抓取失败：'+(d.error||'未知错误')+'）';
      setRfMsg(detail,d.status==='success'?'ok':'warn');
      rfKey.value=''; // 成功后清除输入的 key
      // 立即拉全量数据刷新看板，并同步探测签名
      return fetch('?api=board',{cache:'no-store'}).then(function(r){return r.json()}).then(function(b){
        D=b;lastSig=b.lastRoundId+'|'+b.activeCount+'|'+b.newCount;renderStats();render();
      });
    }).catch(function(){rfGo.disabled=false;setRfMsg('请求失败，请检查网络后重试','err')});
  }
  rfGo.addEventListener('click',manualRefresh);
  rfKey.addEventListener('keydown',function(e){if(e.key==='Enter')manualRefresh()});

  // 先取轻量状态（几百字节），仅在数据变化时拉全量列表，避免每 30 秒重复传几 MB。
  function poll(){
    fetch('?api=status',{cache:'no-store'}).then(function(r){return r.json()}).then(function(s){
      var sig=s.lastRoundId+'|'+s.activeCount+'|'+s.newCount;
      if(sig===lastSig)return;              // 无变化：不拉全量
      return fetch('?api=board',{cache:'no-store'}).then(function(r){return r.json()}).then(function(d){
        lastSig=sig;
        D=d;renderStats();render();
      });
    }).catch(function(){});
  }
  setInterval(poll,30000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)poll()});

  initFilters();renderStats();render();
})();
</script>
</body>
</html>
HTML;

$html = str_replace('{{BOOT}}', $boot, $template);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
