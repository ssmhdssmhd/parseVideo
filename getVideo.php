<?php
//error_reporting(0);
require 'parserVideo.class.php';
header("content-type:text/html;charset=utf-8");
function debug($url){
    $result = ParserVideo::parse($url);
    echo '<pre>';
    print_r($result);
    echo '</pre>';
}

// 支持 web: getVideo.php?url=xxx  和 命令行: php getVideo.php "url"
if (php_sapi_name() == 'cli') {
    $url = isset($argv[1]) ? trim($argv[1]) : '';
} else {
    $url = isset($_GET['url']) ? trim($_GET['url']) : '';
}
if ($url === '') {
    $url = "https://www.bilibili.com/video/BV1GJ411x7h7";
}
debug($url);
