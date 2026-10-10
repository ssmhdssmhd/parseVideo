<?php
/**
 * Bilibili 视频解析器
 * 通过官方接口获取视频信息与播放地址
 * 支持: https://www.bilibili.com/video/BVxxx / avxxx / b23.tv 短链, 支持分P (?p=N)
 */
class Bilibili
{
    const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    // 清晰度 => 归类key (与旧解析器保持一致的命名)
    private static $sharpness = array(
        80 => 'super',    // 1080P
        64 => 'high',     // 720P
        32 => 'normal',   // 480P
        16 => 'fluent',   // 360P
    );

    public static function parse($url)
    {
        $url = self::resolveShortUrl($url);

        // 提取 bvid / av号
        $bvid = '';
        $aid = '';
        if (preg_match('#/video/(BV[0-9A-Za-z]+)#i', $url, $m)) {
            $bvid = $m[1];
        } elseif (preg_match('#(?:av|AV)(\d+)#', $url, $m)) {
            $aid = $m[1];
        } else {
            return false;
        }

        // 分P参数
        $p = 1;
        if (preg_match('#[?&]p=(\d+)#i', $url, $m)) {
            $p = max(1, (int)$m[1]);
        }

        // 1. 获取视频信息 (标题/封面/时长/分P)
        $info = self::fetchView($bvid, $aid);
        if (!$info) return false;

        $data = $info['data'];
        $title = $data['title'];
        $pic = $data['pic'];
        $seconds = $data['duration'];
        $pages = isset($data['pages']) ? $data['pages'] : array();

        // 选定分P的 cid
        $cid = $data['cid'];
        if (!empty($pages)) {
            $target = isset($pages[$p - 1]) ? $pages[$p - 1] : $pages[0];
            $cid = $target['cid'];
        }

        // 2. 获取各清晰度播放地址
        $result = array();
        foreach (self::$sharpness as $qn => $key) {
            $durls = self::fetchPlayurl($bvid, $aid, $cid, $qn);
            if (empty($durls)) continue;
            $urls = array();
            foreach ($durls as $d) {
                if (!empty($d['url'])) $urls[] = $d['url'];
            }
            if ($urls) $result[$key] = $urls;
        }

        $result['title'] = $title;
        $result['img'] = $pic;
        $result['seconds'] = $seconds;
        $result['p'] = $p;
        $result['cid'] = $cid;
        if (!empty($pages)) {
            $result['pages'] = array();
            foreach ($pages as $pg) {
                $result['pages'][] = array(
                    'p'    => $pg['page'],
                    'part' => $pg['part'],
                    'cid'  => $pg['cid'],
                );
            }
        }
        return $result;
    }

    /**
     * 解析短链 (b23.tv) -> 真实链接
     */
    private static function resolveShortUrl($url)
    {
        if (!preg_match('#b23\.tv#i', $url)) return $url;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, self::UA);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $final ? $final : $url;
    }

    /**
     * 获取视频信息
     */
    private static function fetchView($bvid, $aid)
    {
        if ($bvid) {
            $api = "https://api.bilibili.com/x/web-interface/view?bvid=" . urlencode($bvid);
        } elseif ($aid) {
            $api = "https://api.bilibili.com/x/web-interface/view?aid=" . urlencode($aid);
        } else {
            return false;
        }
        $html = Base::_cget($api, self::UA, '', 'https://www.bilibili.com');
        if (!$html) return false;
        $rs = json_decode($html, true);
        if (empty($rs) || (isset($rs['code']) && $rs['code'] != 0) || empty($rs['data'])) return false;
        return $rs;
    }

    /**
     * 获取播放地址
     */
    private static function fetchPlayurl($bvid, $aid, $cid, $qn)
    {
        $params = "cid={$cid}&qn={$qn}&fnval=0&fnver=0";
        if ($bvid) {
            $params .= "&bvid=" . urlencode($bvid);
        } elseif ($aid) {
            $params .= "&avid=" . urlencode($aid);
        }
        $api = "https://api.bilibili.com/x/player/playurl?" . $params;
        $html = Base::_cget($api, self::UA, '', 'https://www.bilibili.com');
        if (!$html) return false;
        $rs = json_decode($html, true);
        if (empty($rs) || (isset($rs['code']) && $rs['code'] != 0) || empty($rs['data']['durl'])) return false;
        return $rs['data']['durl'];
    }
}
