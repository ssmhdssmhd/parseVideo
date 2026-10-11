<?php
/**
 * Sohu 视频解析器 (新版)
 * 通过搜狐官方 v4 接口获取视频信息与播放地址, 匿名可用
 * 支持: https://tv.sohu.com/v/xxx.html / m.tv.sohu.com/v{vid}.shtml 等
 */
class Sohu
{
    const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    // 接口返回字段 => 清晰度归类key (与旧解析器命名一致)
    private static $fieldMap = array(
        'url_original' => 'original', // 原画
        'url_blue'     => 'super',    // 超清
        'url_super'    => 'super',    // 超清
        'url_high'     => 'high',     // 高清
        'url_nor'      => 'normal',   // 标清
    );

    public static function parse($url)
    {
        $vid = self::getVid($url);
        if (!$vid) return false;

        $api = "http://api.tv.sohu.com/v4/video/info/{$vid}.json";
        $html = Base::_cget($api, self::UA, '', 'https://tv.sohu.com');
        if (!$html) return false;

        $rs = json_decode($html, true);
        if (empty($rs) || (isset($rs['status']) && $rs['status'] != 200) || empty($rs['data'])) return false;
        $data = $rs['data'];

        $result = array();
        foreach (self::$fieldMap as $field => $key) {
            if (!empty($data[$field])) {
                if (!isset($result[$key])) $result[$key] = array();
                $result[$key][] = $data[$field];
            }
        }

        $result['title']   = !empty($data['video_name']) ? $data['video_name'] : (isset($data['album_name']) ? $data['album_name'] : '');
        $result['img']     = !empty($data['video_big_pic']) ? $data['video_big_pic'] : (isset($data['firstFrameImage']) ? $data['firstFrameImage'] : '');
        $result['seconds'] = isset($data['total_duration']) ? (int)round($data['total_duration']) : 0;
        return $result;
    }

    /**
     * 从各种搜狐视频链接中提取 vid
     */
    private static function getVid($url)
    {
        // my.tv.sohu.com/us/{uid}/{vid}.shtml
        if (preg_match('#my\.tv\.sohu\.com/us/\d+/(\d+)\.shtml#i', $url, $m)) return $m[1];
        // m.tv.sohu.com/v{vid}.shtml
        if (preg_match('#m\.tv\.sohu\.com/v(\d+)\.shtml#i', $url, $m)) return $m[1];
        // tv.sohu.com/{yyyymmdd}/n{vid}.shtml (2013 旧格式)
        if (preg_match('#tv\.sohu\.com/\d{8}/n(\d+)\.shtml#i', $url, $m)) return $m[1];
        // tv.sohu.com/v/{base64}.html -> 抓取页面提取 vid
        if (preg_match('#tv\.sohu\.com/v/#i', $url)) {
            $html = Base::_cget($url, self::UA, '', 'https://tv.sohu.com');
            if ($html && preg_match('#vid="(\d+)"#', $html, $m)) return $m[1];
        }
        return false;
    }
}
