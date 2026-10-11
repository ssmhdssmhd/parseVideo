<?php
/**
 * M1907 云加速解析器  v.0.0.2 (项目 v.0.0.4)
 * ====================================
 * 对接站点: im1907.top / m1907.top / hi1907.top / z1.m1907.cn
 * 加速节点: *.cloud.nnpp.vip:2223
 *
 * 解析流程:
 *   1. 直链判定: url 含 .m3u8 / .mp4 时直接返回, 不走云加速
 *   2. 云加速解析: GET /api/v2/?z=<日期签名>&jx=<url>&s1ig=<星期签名>
 *        z    = md5( md5( str((北京日+18)^10) )[0:10] )
 *        s1ig = (北京星期几: 0=周日) + 11397
 *   3. 响应为 AES-256-GCM 密文: 前12字节=IV, 后16字节=tag, 中间=密文
 *        密钥 = sha256("1561657456173")   (由站点前端混淆数组还原)
 *   4. 解密得 JSON, 按 type 分支取播放地址
 *
 * 返回数组(与框架保持一致的字段):
 *   title  片名
 *   source 来源(直链 / 云加速)
 *   url    默认首个可播地址
 *   eps    所有清晰度/集数列表 [ ['name'=>, 'url'=>], ... ]
 *
 * 依赖: PHP >= 7.1 + curl + openssl 扩展
 */
class M1907
{
    const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
    const REFERER = "https://im1907.top/";
    const API = "https://m1-a1.cloud.nnpp.vip:2223/api/v2/";
    const AES_KEY_SOURCE = "1561657456173"; // sha256 后作为 AES-256-GCM 密钥

    /** 官方站点域名(无法直接用官方 URL 解析时, 提取片名走关键词搜索) */
    const OFFICIAL_SITES = '#(v\.qq\.com|v\.qq\.net|qq\.com|iqiyi\.com|qiyi\.com|youku\.com|tudou\.com|mgtv\.com|hunantv\.com|tv\.sohu\.com|letv\.com|le\.com|so\.tv|fun\.tv|ifeng\.com|pptv\.com|pps\.tv|56\.com|kankan\.com|mtime\.com|tv189\.com|vip\.sina\.com\.cn|video\.sina\.com\.cn)#i';

    public static function parse($url)
    {
        $url = trim($url);
        if ($url === '') return false;

        // 若传入的是 m1907 站点页面, 提取其内部的 jx 目标地址
        if (preg_match('#(?:im1907|m1907|hi1907)\.top#i', $url)) {
            if (preg_match('#[?&]jx=([^&]+)#i', $url, $m)) {
                $target = urldecode($m[1]);
                if ($target !== '') $url = $target;
            }
        }

        // 直链判定: m3u8 / mp4 直接返回
        $low = strtolower($url);
        if (strpos($low, '.m3u8') !== false || strpos($low, '.mp4') !== false) {
            return array(
                'title'  => self::basenameNoExt($url),
                'source' => 'M1907直链',
                'url'    => $url,
                'eps'    => array(array('name' => '直链', 'url' => $url)),
            );
        }

        // 显式片名参数(调用方可直接传 t/name/title), 优先于 URL 解析
        $explicit = self::queryVal($url, 't') ?: self::queryVal($url, 'name') ?: self::queryVal($url, 'title');
        if ($explicit !== '') {
            $rst = self::parseInput($explicit);
            if ($rst !== false) return $rst;
        }

        // 云加速解析官方 URL 本身
        $rst = self::parseInput($url);
        if ($rst !== false) return $rst;

        // 官方站点 URL 无法直接解析 -> 抓取页面提取片名, 再走关键词搜索
        if (preg_match(self::OFFICIAL_SITES, $url)) {
            $kw = self::extractKeyword($url);
            if ($kw !== '') {
                $rst = self::parseInput($kw);
                if ($rst !== false) return $rst;
            }
        }

        return false;
    }

    /**
     * 调用 api/v2 解析单个输入(url 或片名关键词), 成功返回结果数组, 失败返回 false
     */
    private static function parseInput($input)
    {
        try {
            list($z, $s1ig) = self::makeSign();
            $api = self::API . "?z={$z}&jx=" . urlencode($input) . "&s1ig={$s1ig}";
            $bin = self::httpGet($api);
            if ($bin === false) return false;
            $body = self::decrypt($bin);
            if ($body === null) return false;
        } catch (Throwable $e) {
            return false;
        }

        $type = isset($body['type']) ? $body['type'] : null;
        if ($type !== 'movie' && $type !== 'tv') return false;

        $items = isset($body['data']) ? $body['data'] : array();
        if (empty($items) || empty($items[0]['source']['eps'])) return false;

        $eps = $items[0]['source']['eps'];
        $title = isset($items[0]['name']) ? $items[0]['name'] : '';
        $flatten = array();
        foreach ($eps as $e) {
            $flatten[] = array(
                'name' => isset($e['name']) ? $e['name'] : '',
                'url'  => isset($e['url']) ? $e['url'] : '',
            );
        }

        return array(
            'title'  => $title,
            'source' => 'M1907云加速',
            'url'    => $flatten[0]['url'],
            'eps'    => $flatten,
        );
    }

    /**
     * 从 query 中取指定参数(会剥离 m1907 站点的 jx 前缀)
     */
    private static function queryVal($url, $key)
    {
        if (preg_match('#(?:[?&])' . preg_quote($key, '#') . '=([^&]+)#i', $url, $m)) {
            return urldecode($m[1]);
        }
        return '';
    }

    /**
     * 抓取官方视频页, 提取片名(片名)用于 M1907 关键词搜索
     *
     * 依次尝试:
     *   og:title / video:title / og:video 元标签
     *   <title> 标签
     *   JSON-LD 的 name / headline
     *   页面内嵌 JS 的 "title"/"name"/"videoName"/"videoTitle"
     * 并去除站点品牌后缀(如 " - 腾讯视频", "_爱奇艺", "高清在线观看" 等)。
     */
    private static function extractKeyword($url)
    {
        // 剥离 query(避免把追踪参数也算进去)
        $cleanUrl = strtok($url, '?');
        $html = self::httpGetWithMobileUA($cleanUrl);
        if ($html === false || $html === '') return '';

        $title = '';

        // 1. og:title / video:title / og:video:title
        if (preg_match('#<\s*meta\s+(?:content=["\']([^"\']+)["\']\s+)?property=["\'](?:og:video:title|og:title|video:title)["\'](?:\s+content=["\']([^"\']*)["\'])?[^>]*>#i', $html, $m)) {
            $title = !empty($m[2]) ? $m[2] : (isset($m[1]) ? $m[1] : '');
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

        $title = self::cleanTitle($title);
        return $title;
    }

    /**
     * 清洗片名: 去 HTML 实体、去换行空格、去站点品牌后缀
     */
    private static function cleanTitle($title)
    {
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = str_replace(array("\r", "\n", "\t"), '', $title);
        $title = preg_replace('/\s+/u', ' ', trim($title));

        // 站点品牌后缀: 名称分隔符 + 站点名 + 广告词
        $brandTokens = array('腾讯视频', '爱奇艺', '优酷', '芒果TV', '芒果tv', '搜狐视频', '搜狐',
            '高清在线观看', '高清全集在线观看', '高清', '全集在线观看', '在线观看',
            '正版高清视频平台', '海量正版高清视频', '随时随地看大片', '热播', '抢先看');
        foreach ($brandTokens as $tok) {
            // " - xx" / " xx" / "_xx" 形式后缀
            $title = preg_replace('#(?:\s*[-_|]\s*|\s+)' . preg_quote($tok, '#') . '\s*$#u', '', $title);
            // "xx_" 前缀形式
            $title = preg_replace('#^' . preg_quote($tok, '#') . '[_\-]\s*#u', '', $title);
        }
        // 去除更通用的平台字样残留
        $title = preg_replace('#\s*[-_|]\s*(?:腾讯|爱奇艺|优酷|芒果|搜狐|正片|预告)\s*$#u', '', $title);
        $title = trim($title);

        // 无效标题(品牌名 / 报错页)直接丢弃, 避免拿垃圾关键词去搜
        if ($title === '') return '';
        $badExact = array('腾讯视频', '爱奇艺', '优酷', '芒果TV', '芒果tv', '搜狐视频', '搜狐', '404', '您查找的页面不存在');
        if (in_array($title, $badExact, true)) return '';
        // 至少 2 个非空白字符才认为有效
        if (mb_strlen(trim($title), 'UTF-8') < 2) return '';
        return $title;
    }

    /**
     * 使用手机 UA 抓取页面(官方站点对桌面 UA / 服务器 IP 风控更严)
     */
    private static function httpGetWithMobileUA($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        ));
        $body = curl_exec($ch);
        curl_close($ch);
        return $body;
    }

    /**
     * 生成 api/v2 请求所需的 z 与 s1ig 签名 (按北京时间 UTC+8)
     * z    = md5( md5( str((北京日+18)^10) )[0:10] )
     * s1ig = (北京星期几: 0=周日) + 11397
     */
    private static function makeSign()
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('+0800'));
        $day = (int)$now->format('j');                 // 日, 1~31
        $c0  = ($day + 18) ^ 10;
        $c   = substr(md5((string)$c0), 0, 10);
        $z   = md5($c);
        $s1ig = (int)$now->format('w') + 11397;        // 0=周日 .. 6=周六
        return array($z, $s1ig);
    }

    /**
     * 解密 api/v2 返回的 AES-256-GCM 密文并解析 JSON
     * 结构: [IV(12)][密文][GCM TAG(16)], 密钥 = sha256(AES_KEY_SOURCE)
     */
    private static function decrypt($data)
    {
        $key = hash('sha256', self::AES_KEY_SOURCE, true);
        $iv  = substr($data, 0, 12);
        $tag = substr($data, -16);
        $ct  = substr($data, 12, -16);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) return null;
        $json = json_decode($plain, true);
        return is_array($json) ? $json : null;
    }

    private static function httpGet($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_REFERER        => self::REFERER,
        ));
        $body = curl_exec($ch);
        curl_close($ch);
        return $body;
    }

    private static function basenameNoExt($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $name = $path !== null ? basename($path) : 'm1907';
        return preg_replace('/\.(m3u8|mp4|flv)$/i', '', $name);
    }
}