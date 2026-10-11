<?php
/**
 * M1907 云加速解析模块  v.0.0.2
 * ====================================
 * 对接站点: im1907.top / m1907.top (加速节点 *.cloud.nnpp.vip:2223)
 *
 * 解析流程:
 *   1. 直链判定: url 含 .m3u8 / .mp4 时直接返回, 不调云加速接口
 *   2. 云加速解析: GET /api/v2/?z=<日期签名>&jx=<url>&s1ig=<星期签名>
 *        z    = md5( md5( str((北京日+18)^10) )[0:10] )
 *        s1ig = (北京星期几, 0=周日) + 11397
 *   3. 响应为 AES-256-GCM 密文: 前12字节=IV, 后16字节=tag, 中间=密文
 *        密钥 = sha256("1561657456173")   (由站点前端混淆数组还原)
 *   4. 解密得 JSON, 按 type 分支取播放地址
 *   5. 官方站点回退: 官方 URL 直接解析失败时, 抓取页面提取片名(og:title /
 *        <title> / JSON-LD / 内嵌 JS), 再按关键词调用 api/v2 取源;
 *        亦支持显式片名参数 ?t= / ?name= / ?title= 强制按片名搜索
 *
 * 返回统一格式:
 *   {
 *     "code": 200|404|403|416|500|601,
 *     "msg":  "解析成功"|"无url参数"|"需要验证码"|"无法解析该地址"|"解析失败"|"内容受限",
 *     "url":  "https://.../index.m3u8",
 *     "time": "2026-10-11 08:02:29",
 *     "KFZ":  "1080P"|"第1集"|""
 *   }
 *
 * 依赖: PHP >= 7.1 + openssl 扩展
 */

declare(strict_types=1);

const API_HOST = 'm1-a1.cloud.nnpp.vip:2223';
const AES_KEY_SOURCE = '1561657456173'; // sha256 后作为 AES-256-GCM 密钥
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const REFERER = 'https://im1907.top/';
const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';

// 官方站点域名(直接解析失败时, 提取片名走关键词搜索)
const OFFICIAL_SITES = '#(v\.qq\.com|v\.qq\.net|qq\.com|iqiyi\.com|qiyi\.com|youku\.com|tudou\.com|mgtv\.com|hunantv\.com|tv\.sohu\.com|letv\.com|le\.com|so\.tv|fun\.tv|ifeng\.com|pptv\.com|pps\.tv|56\.com|kankan\.com|mtime\.com|tv189\.com|vip\.sina\.com\.cn|video\.sina\.com\.cn)#i';

function httpRequest(string $url, int $timeout = 20): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => UA,
        CURLOPT_REFERER => REFERER,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    curl_close($ch);
    return $body;
}

/**
 * 生成 api/v2 请求所需的 z 与 s1ig 签名 (按北京时间 UTC+8)
 * z    = md5( md5( str((北京日+18)^10) )[0:10] )
 * s1ig = (北京星期几: 0=周日) + 11397
 */
function makeSign(?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('+0800'));
    $day = (int)$now->format('j');            // 日, 1~31
    $c0 = ($day + 18) ^ 10;
    $c = substr(md5((string)$c0), 0, 10);
    $z = md5($c);

    $getDay = ((int)$now->format('w'));       // 星期, 0=周日..6=周六
    $s1ig = $getDay + 11397;
    return [$z, $s1ig];
}

/**
 * 解密 api/v2 返回的 AES-256-GCM 密文并解析 JSON
 * 结构: [IV(12)][密文][GCM TAG(16)], 密钥 = sha256(AES_KEY_SOURCE)
 */
function decryptResponse(string $data): array
{
    // 密文长度须大于 IV(12) + TAG(16); 过短说明是非加密错误响应, 直接判失败
    if (strlen($data) < 29) {
        throw new RuntimeException('响应非法(非加密数据)');
    }
    $key = hash('sha256', AES_KEY_SOURCE, true);
    $iv  = substr($data, 0, 12);
    $tag = substr($data, -16);
    $ct  = substr($data, 12, -16);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('AES-GCM 解密失败');
    }
    $json = json_decode($plain, true);
    if (!is_array($json)) {
        throw new RuntimeException('解密后 JSON 解析失败');
    }
    return $json;
}

/**
 * 用手机 UA 抓取页面(官方站点对桌面 UA / 服务器 IP 风控更严)
 */
function httpGetPage(string $url, int $timeout = 15): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => MOBILE_UA,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? '' : $body;
}

/**
 * 清洗片名: 去 HTML 实体、去换行空格、取主体、去站点品牌后缀、过滤无效标题
 */
function cleanTitle(string $title): string
{
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $title = str_replace(["\r", "\n", "\t"], '', $title);
    $title = preg_replace('/\s+/u', ' ', trim($title)) ?? '';

    // CN 站点标题常为 "片名-类型-描述-站点"; 取首个分隔符前的主体作为片名
    // (如 "狂飙-电视剧-全集高清正版视频在线观看-爱奇艺" -> "狂飙")
    if (preg_match('/^\s*([^-_|]{2,30})\s*[-_|]/u', $title, $m)) {
        $cand = trim($m[1]);
        if (preg_match_all('/./u', $cand) >= 2) {
            $title = $cand;
        }
    }

    // 站点品牌后缀: 名称分隔符 + 站点名 + 广告词
    $brandTokens = ['腾讯视频', '爱奇艺', '优酷', '芒果TV', '芒果tv', '搜狐视频', '搜狐',
        '高清在线观看', '高清全集在线观看', '高清', '全集在线观看', '在线观看',
        '正版高清视频平台', '海量正版高清视频', '随时随地看大片', '热播', '抢先看'];
    foreach ($brandTokens as $tok) {
        $title = preg_replace('#(?:\s*[-_|]\s*|\s+)' . preg_quote($tok, '#') . '\s*$#u', '', $title) ?? $title;
        $title = preg_replace('#^' . preg_quote($tok, '#') . '[_\-]\s*#u', '', $title) ?? $title;
    }
    // 去除更通用的平台字样残留
    $title = preg_replace('#\s*[-_|]\s*(?:腾讯|爱奇艺|优酷|芒果|搜狐|正片|预告)\s*$#u', '', $title) ?? $title;
    $title = trim($title);

    // 无效标题(品牌名 / 报错页)直接丢弃, 避免拿垃圾关键词去搜
    if ($title === '') return '';
    $badExact = ['腾讯视频', '爱奇艺', '优酷', '芒果TV', '芒果tv', '搜狐视频', '搜狐',
        '腾讯', '土豆', '乐视', '404', '您查找的页面不存在'];
    if (in_array($title, $badExact, true)) return '';
    if (strpos($title, '404') !== false) return '';
    if (strpos($title, '页面不存在') !== false || strpos($title, '页面走丢') !== false
        || strpos($title, '找不到') !== false) return '';
    // 至少 2 个字符才认为有效(不依赖 mbstring 扩展)
    $title = trim($title);
    if (preg_match_all('/./u', $title) < 2) return '';
    return $title;
}

/**
 * 抓取官方视频页, 提取片名用于 M1907 关键词搜索
 *
 * 依次尝试: og:title / video:title -> <title> -> JSON-LD name/headline -> 内嵌 JS 字段
 */
function extractKeyword(string $url): string
{
    $cleanUrl = strtok($url, '?');
    $html = httpGetPage($cleanUrl === false ? $url : $cleanUrl);
    if ($html === '') return '';

    $title = '';
    // 1. og:title / video:title / og:video:title
    if (preg_match('#<\s*meta\s+(?:content=["\']([^"\']+)["\']\s+)?property=["\'](?:og:video:title|og:title|video:title)["\'](?:\s+content=["\']([^"\']*)["\'])?[^>]*>#i', $html, $m)) {
        $title = ($m[2] ?? '') !== '' ? $m[2] : ($m[1] ?? '');
    }
    // 2. <title>
    if ($title === '' && preg_match('#<\s*title\b[^>]*>([^<]{2,120})</title>#i', $html, $m)) {
        $title = trim($m[1]);
    }
    // 3. JSON-LD name / headline
    if ($title === '' && preg_match('#"(?:name|headline)"\s*:\s*"([^"]{2,80})"#i', $html, $m)) {
        $title = $m[1];
    }
    // 4. 内嵌 JS 视频字段
    if ($title === '' && preg_match('#"(?:videoName|videoTitle|title|vod_title)"\s*:\s*"([^"]{2,80})"#i', $html, $m)) {
        $title = $m[1];
    }

    return cleanTitle($title);
}

/**
 * 从 query 中取指定参数(会剥离 m1907 站点的 jx 前缀)
 */
function queryVal(string $url, string $key): string
{
    if (preg_match('#(?:[?&])' . preg_quote($key, '#') . '=([^&]+)#i', $url, $m)) {
        return urldecode($m[1]);
    }
    return '';
}

/**
 * 用单个输入(url 或片名关键词)调用 api/v2 并解析
 *
 * @return array|false false=不可解析; ['__captcha'=>true]=需验证码;
 *                      ['__restricted'=>true]=内容受限; 数组内 code=200 为成功
 * @throws RuntimeException 网络/解密失败(由调用方捕获)
 */
function parseInput(string $input, int $ep, string $epName, string $nowStr)
{
    [$z, $s1ig] = makeSign();
    $api = "https://" . API_HOST . "/api/v2/?z=$z&jx=" . urlencode($input) . "&s1ig=$s1ig";
    $body = decryptResponse(httpRequest($api));

    $type = $body['type'] ?? null;
    if ($type === 4000) return ['__captcha' => true];
    if ($type !== 'movie' && $type !== 'tv') return false;

    $items = $body['data'] ?? [];
    $eps = $items[0]['source']['eps'] ?? null;
    if (empty($items) || !is_array($eps)) return ['__restricted' => true];

    // 按集数取条目, 越界取最后一集
    $idx = min($ep - 1, count($eps) - 1);
    $target = $eps[$idx];
    return [
        'code' => 200,
        'msg'  => '解析成功',
        'url'  => $target['url'],
        'time' => $nowStr,
        'KFZ'  => $epName !== '' ? $epName : ($target['name'] ?? ''),
    ];
}

/**
 * 解析入口, 返回统一 JSON 格式
 *
 * @param string $url     目标视频地址(必填)
 * @param int    $ep      剧集集数, 默认第1集
 * @param string $epName  集名覆盖(KFZ 字段), 缺省取接口返回的 eps[].name
 * @return array
 */
function parse(string $url = '', int $ep = 1, string $epName = ''): array
{
    $nowStr = (new DateTimeImmutable('now', new DateTimeZone('+0800')))
        ->format('Y-m-d H:i:s');

    if ($url === '') {
        return ['code' => 404, 'msg' => '无url参数', 'url' => '', 'time' => $nowStr, 'KFZ' => ''];
    }

    $url = trim($url);
    $low = strtolower($url);
    // 直链判定: m3u8 / mp4 直接返回, 不走云加速
    if (strpos($low, '.m3u8') !== false || strpos($low, '.mp4') !== false) {
        return [
            'code' => 200, 'msg' => '解析成功', 'url' => $url,
            'time' => $nowStr, 'KFZ' => $epName !== '' ? $epName : '直链',
        ];
    }

    // 待尝试输入: 显式片名参数优先, 其次原始 URL
    $explicit = queryVal($url, 't') ?: queryVal($url, 'name') ?: queryVal($url, 'title');
    $inputs = [];
    if ($explicit !== '') $inputs[] = $explicit;
    $inputs[] = $url;

    $networkErr = null;
    $restricted = false;
    foreach ($inputs as $input) {
        try {
            $r = parseInput($input, $ep, $epName, $nowStr);
        } catch (Throwable $e) {
            $networkErr = $e;
            continue;
        }
        if ($r === false) continue;
        if (isset($r['__captcha'])) {
            return ['code' => 403, 'msg' => '需要验证码', 'url' => '', 'time' => $nowStr, 'KFZ' => ''];
        }
        if (isset($r['__restricted'])) { $restricted = true; continue; }
        return $r;
    }

    // 官方站点 URL 直接解析失败 -> 抓页面提片名, 再走关键词搜索
    if (preg_match(OFFICIAL_SITES, $url)) {
        $kw = extractKeyword($url);
        if ($kw !== '') {
            try {
                $r = parseInput($kw, $ep, $epName, $nowStr);
                if (is_array($r) && !isset($r['__captcha']) && !isset($r['__restricted'])) return $r;
                if (is_array($r) && isset($r['__captcha'])) {
                    return ['code' => 403, 'msg' => '需要验证码', 'url' => '', 'time' => $nowStr, 'KFZ' => ''];
                }
                if (is_array($r) && isset($r['__restricted'])) $restricted = true;
            } catch (Throwable $e) {
                $networkErr = $e;
            }
        }
    }

    if ($restricted) {
        return ['code' => 601, 'msg' => '内容受限', 'url' => '', 'time' => $nowStr, 'KFZ' => ''];
    }
    if ($networkErr !== null) {
        return ['code' => 500, 'msg' => '解析失败: ' . $networkErr->getMessage(),
            'url' => '', 'time' => $nowStr, 'KFZ' => ''];
    }
    return ['code' => 416, 'msg' => '无法解析该地址', 'url' => '', 'time' => $nowStr, 'KFZ' => ''];
}

/**
 * 统一 JSON 输出
 */
function respond(array $obj, string $msg = '', int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---------------- 命令行运行 ----------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $url = $argv[1] ?? '';
    echo json_encode(parse($url), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

// ---------------- Web 运行 (CGI/内置服务器) ----------------
if (PHP_SAPI !== 'cli') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $allow = ['/api/m1907', '/api/m1907/', '/', '/index.php', '/m1907parse.php'];
    if (!in_array($path, $allow, true)) {
        respond(['code' => 404, 'msg' => '无url参数', 'url' => '', 'time' => '', 'KFZ' => '']);
        exit(0);
    }
    $url = $_GET['url'] ?? $_GET['jx'] ?? '';   // 兼容 ?url= 与 ?jx=
    $ep = max(1, (int)($_GET['ep'] ?? 1));
    if ($url === '' && isset($_GET['url2'])) {  // 支持多个?url= 已转 url2
        $url = $_GET['url2'];
    }
    respond(parse($url, $ep));
}