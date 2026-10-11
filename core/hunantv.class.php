<?php
/**
 * Hunantv (芒果TV) 视频解析器 (新版)
 * 通过芒果官方接口链路 player/video -> getSource -> atcl 获取播放地址, 匿名可用
 * 支持: https://www.mgtv.com/b/{album}/{vid}.html / /s/{vid}.html / hunantv.com 等
 */
class Hunantv
{
    const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    // 芒果清晰度 def => 归类key (与旧解析器命名一致)
    private static $defMap = array(
        '1' => 'normal', // 480P
        '2' => 'high',   // 576P
        '3' => 'super',  // 720P
        '4' => 'fluent', // 1080P(会员, 一般拿不到)
    );

    public static function parse($url)
    {
        $vid = self::getVideoId($url);
        if (!$vid) return false;

        // 设备号/时间戳/首次令牌
        $did = self::uuid();
        $clit = time();
        $tk2 = self::Hf("did={$did}|pno=1030|ver=0.3.0301|clit={$clit}");

        // 1. player/video 获取 atc(pm2/tk2) 与视频信息
        $p1 = array(
            'video_id' => $vid, 'did' => $did, 'suuid' => '', 'cxid' => '',
            'entranceType' => '', 'tk2' => $tk2, 'type' => 'pch5', 'auth_mode' => 1,
            '_support' => 10000000, 'src' => '', 'abroad' => '', 'allowedRC' => 1,
        );
        $d1 = self::getJson('https://pcweb.api.mgtv.com/player/video?' . http_build_query($p1));
        if (empty($d1) || !isset($d1['code']) || $d1['code'] != 200 || empty($d1['data']['atc'])) return false;

        $atc = $d1['data']['atc'];
        $pm2 = $atc['pm2'];
        $tk2r = $atc['tk2'];
        $info = isset($d1['data']['info']) ? $d1['data']['info'] : array();
        $title = isset($info['partName']) && $info['partName'] ? $info['partName'] : (isset($info['title']) ? $info['title'] : '');
        $thumb = isset($info['thumb']) ? $info['thumb'] : '';
        $duration = isset($info['duration']) ? (int)$info['duration'] : 0;

        // 2. player/getSource 获取分清晰度流地址
        $p2 = array(
            '_support' => 10000000, 'tk2' => $tk2r, 'pm2' => $pm2, 'did' => $did, 'vf' => '',
            'video_id' => $vid, 'type' => 'pch5', 'cxid' => '', 'entranceType' => '',
            'suuid' => '', 'auth_mode' => 1, 'src' => '', 'abroad' => '', 'allowedRC' => 1,
            'definitionType' => 2,
        );
        $d2 = self::getJson('https://pcweb.api.mgtv.com/player/getSource?' . http_build_query($p2));
        if (empty($d2) || !isset($d2['code']) || $d2['code'] != 200 || empty($d2['data']['stream'])) return false;

        $streams = $d2['data']['stream'];
        $sd = isset($d2['data']['stream_domain'][0]) ? $d2['data']['stream_domain'][0] : '';

        // 3. 逐个清晰度请求 atcl 获得 m3u8
        $result = array();
        foreach ($streams as $s) {
            if (empty($s['url']) || !empty($s['vip'])) continue; // 跳过空流/会员流
            $m3u8 = self::getM3u8($sd . $s['url']);
            if (!$m3u8) continue;
            $key = isset(self::$defMap[$s['def']]) ? self::$defMap[$s['def']] : ('def' . $s['def']);
            if (!isset($result[$key])) $result[$key] = array();
            $result[$key][] = $m3u8;
        }
        if (empty($result)) return false;

        $result['title'] = $title;
        $result['img'] = $thumb;
        $result['seconds'] = $duration;
        return $result;
    }

    /**
     * 从芒果视频链接中提取 video_id
     */
    private static function getVideoId($url)
    {
        // 取最后一个数字ID: /b/{album}/{vid}.html | /s/{vid}.html
        if (preg_match_all('#/(\d+)\.html#i', $url, $m) && !empty($m[1])) {
            return end($m[1]);
        }
        return false;
    }

    /**
     * 请求 atcl 接口获得 m3u8 地址
     */
    private static function getM3u8($url)
    {
        $html = Base::_cget($url, self::UA, '', 'https://www.mgtv.com/');
        if (!$html) return false;
        $rs = json_decode($html, true);
        if (empty($rs) || empty($rs['info'])) return false;
        return $rs['info'];
    }

    /**
     * GET 请求并解析 JSON
     */
    private static function getJson($url)
    {
        $html = Base::_cget($url, self::UA, '', 'https://www.mgtv.com/');
        if (!$html) return null;
        $rs = json_decode($html, true);
        return is_array($rs) ? $rs : null;
    }

    /**
     * 生成 v4 uuid
     */
    private static function uuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * 令牌混淆: base64 特殊替换后反转
     */
    private static function Hf($s)
    {
        $b = base64_encode($s);
        $b = str_replace(array('+', '/', '='), array('_', '~', '-'), $b);
        return strrev($b);
    }
}
