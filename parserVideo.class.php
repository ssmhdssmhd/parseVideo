<?php
//自动加载core里的class
define('CLASS_DIR', 'core/');
// Add your class dir to include path
set_include_path(str_replace("\\","/",__DIR__."/".CLASS_DIR));
// You can use this trick to make autoloader look for commonly used "My.class.php" type filenames
spl_autoload_extensions('.class.php');
// Use default autoload implementation
spl_autoload_register();


class ParserVideo
{
    /**
     * 支持站点白名单(命中后走对应解析器, 未命中或解析失败统一回退 M1907/flvcd)
     * 已移除失效站点: tudou/ku6/letv/sina/kankan/mtime/pps/tv189
     */
    const CHECK_URL_VALID = "/(youku\.com|56\.com|((my|m)\.)?tv\.sohu\.com|v\.qq\.com|pptv\.com|iqiyi\.com|hunantv\.com|mgtv\.com|bilibili\.com|b23\.tv|im1907\.top|m1907\.top|hi1907\.top|z1\.m1907\.cn)/";

    /**
     * parse 
     * @param string $url 
     * @static
     * @access public
     * @return void
     */
    static public function parse($url=''){
        $lowerurl = strtolower($url);
        preg_match(self::CHECK_URL_VALID, $lowerurl, $matches);
        if(!$matches){
            // 直链(m3u8/mp4)优先交由 M1907 处理
            $direct = M1907::parse($url);
            if ($direct !== false) return $direct;
            return self::getFlvcdUrl($url);
        }

        switch($matches[1]){
            case 'youku.com':
                $data = self::_parseYouku($url);
                break;
            case '56.com':
                $data = self::_parse56($url);
                break;
            case 'my.tv.sohu.com':
            case 'm.tv.sohu.com':
            case 'tv.sohu.com':
                $data = self::_parseSohu($url);
                break;
            case 'v.qq.com':
                $data = self::_parseQq($url);
                break;
            case "pptv.com":
                $data = self::_parsePptv($url);
                break;
            case "iqiyi.com":
                $data = self::_parseIqiyi($url);
                break;
            case  "hunantv.com":
            case  "mgtv.com":
                $data = self::_parseHunantv($url);
                break;
            case 'bilibili.com':
            case 'b23.tv':
                $data = self::_parseBilibili($url);
                break;
            case 'im1907.top':
            case 'm1907.top':
            case 'hi1907.top':
            case 'z1.m1907.cn':
                $data = self::_parseM1907($url);
                break;
            default:
                $data = false;
        }

        // 专门的站点解析器失败时, 回退到 M1907 云加速(含官方站点片名关键词搜索)
        if (empty($data)) {
            $data = self::_parseM1907($url);
        }

        return $data;
    }

    /**
     * [_parseQq  解析腾讯视频]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parseQq($url){
        //if(mt_rand(0,10) == 3){
            $data = Qq::parse($url);
        //}else{
        //    $data = self::getFlvcdUrl($url);
        //}
        return $data;
    }

    /**
     * [_parseYouku 解析优酷网]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parseYouku($url){
        $data = Youku::parse($url);
        return $data;
    }

    /**
     * [_parse56 56网视频解析]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parse56($url){
        $data = Renren56::parse($url);
        return $data;
    }

    /**
     * [_parseSohu 解析搜狐视频]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parseSohu($url){
        $data = Sohu::parse($url);
        return $data;
    }

    /**
     * [_parsePptv 解析pptv视频]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parsePptv($url){
        $data = Pptv::parse($url);
        return $data;
    }

    /**
     * [_parseIqiyi 解析爱奇艺视频]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parseIqiyi($url){
        $data = Iqiyi::parse($url);
        return $data;
    }

    /**
     * [_parseHunantv 解析芒果网视频]
     * @return [type] [description]
     */
    static private function _parseHunantv($url){
        $data = Hunantv::parse($url);
        return $data;
    }

    /**
     * [_parseBilibili 解析哔哩哔哩视频]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parseBilibili($url){
        $data = Bilibili::parse($url);
        return $data;
    }

    /**
     * [_parseM1907 解析 M1907 云加速视频(im1907.top/m1907.top/hi1907.top)]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static private function _parseM1907($url){
        $data = M1907::parse($url);
        return $data;
    }

    /**
     * [getFlvcdUrl 通过flvcd来解析视频地址]
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    static public function getFlvcdUrl($url){
        $url = "http://www.flvcd.com/parse.php?kw=" . urlencode($url);
        $result = array();
        for ($i = 0; $i < 4; $i++) {
            if ($i == 0) {
                $url = $url . '&flag=one&format=super';
            }
            if ($i == 1) {
                $url = $url . '&flag=one&format=high';
            }
            if ($i == 2) {
                $url = $url . '&flag=one&format=normal';;
            }
            if ($i == 3) {
                $url = $url . '&flag=one&format=fluent';;
            }            
            $_str = Base::_cget($url);
            $match = $matchs = array();
            preg_match('/name="inf"\s*value="([^"]+)"/i', $_str, $matchs); //匹配多视频地址
            $download = iconv('utf-8', 'gbk', '下载地址：');
            preg_match('/' . $download . '([^<]*)<a href="([^"]+)"/i', $_str, $match);
            preg_match('/title = "([^"]+)"/i', $_str, $names); //匹配视频标题  $name 数组的第二个是标题
            if ($match && trim($match[2])) {
                $urls = trim($match[2]);
            } else if(is_array($matchs) && !empty($matchs[1])){
                $urls = substr($matchs[1], -1) == '|' ? substr($matchs[1], 0, -1) : $matchs[1];
            }else{
                break;
            }
            if ($urls) {
                switch ($i) {
                    case 0:
                        $sharpness = 'super';
                        break;
                    case 1:
                        $sharpness = 'high';
                        break;
                    case 2:
                        $sharpness = 'normal';
                        break;
                    case 3:
                        $sharpness = 'fluent';
                        break;                    
                }
                if($sharpness == 'normal'){
                    $result['title'] = iconv('gbk', 'utf-8', $names[1]);
                }
                $result[$sharpness] = array_filter(explode('|',$urls));
            }
        }
        return $result;
    }    

}