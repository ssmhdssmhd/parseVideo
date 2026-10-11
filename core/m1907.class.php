<?php
/**
 * M1907 云加速解析器  v.0.0.1
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

        // 云加速解析
        try {
            list($z, $s1ig) = self::makeSign();
            $api = self::API . "?z={$z}&jx=" . urlencode($url) . "&s1ig={$s1ig}";
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