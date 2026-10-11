# parseVideo

解析国内各大视频网站视频 PHP 项目（2013 年原版，已修复更新可用）

## 版本信息

当前版本：**v.0.0.3**（2026-10-11）

## 功能

- 解析视频真实播放地址（m3u8 / mp4 / flv）
- `getVideo.php` 为测试入口，支持 Web（`?url=`）和命令行两种方式
- 哔哩哔哩支持分P视频解析（`?p=N`）、av 号 / BV 号、b23.tv 短链
- 搜狐 / 芒果TV 使用官方现行接口，匿名可用
- M1907 云加速解析（im1907.top / m1907.top / hi1907.top / z1.m1907.cn），支持任意直链 m3u8/mp4 直接播放、m1907 云加速接口 AES-GCM 解密取源

## 使用方式

Web:

```
getVideo.php?url=https://www.bilibili.com/video/BV1GJ411x7h7
```

命令行:

```
php getVideo.php "https://www.bilibili.com/video/BV1GJ411x7h7"
```

## 支持站点

| 站点 | 状态 | 说明 |
|------|------|------|
| 哔哩哔哩 bilibili.com / b23.tv | 可用 | 官方 API 解析，支持分P、多清晰度 |
| M1907 im1907.top / m1907.top / hi1907.top / z1.m1907.cn | 可用 | 云加速接口 AES-GCM 解密取源；任意直链 m3u8/mp4 直接播放 |
| 搜狐视频 tv.sohu.com / m.tv.sohu.com / my.tv.sohu.com | 可用 | 官方 v4 接口，匿名可解析，多清晰度 m3u8 |
| 芒果TV mgtv.com / hunantv.com | 可用 | 官方接口链路，免费档 480P/576P/720P 可播 |
| 优酷 / 爱奇艺 / 腾讯 / 土豆 / 乐视 / 56 / 酷6 / 新浪 / 迅雷看看 / 时光网 / PPS / PPTV / tv189 | 已失效 | 2013 年旧接口已关闭，且受平台风控限制，暂无法解析 |

> 说明：优酷（阿里云盾）、爱奇艺（dash 签名）、西瓜/抖音（jssvmprt 反爬）等平台匿名解析受限，后续有可行方案再接入。
> M1907 云加速对官方站点（腾讯/爱奇艺/优酷/芒果等）URL 识别不稳定（部分返回服务端 500 / 首页兜底），可靠源为该解析器自身聚合目录或直链 m3u8/mp4。

## 解析结果字段

- `title` 视频标题
- `img` 封面图
- `seconds` 时长(秒)
- `super` / `high` / `normal` / `fluent` / `original` 各清晰度播放地址数组
- `pages` 分P列表（哔哩哔哩多P视频）
- `source` 来源（M1907直链 / M1907云加速）
- `eps` 全部清晰度/集数列表（`[{name,url}]`，M1907）

## 更新日志

### v.0.0.3 (2026-10-11)

- 新增：M1907 云加速解析器（core/m1907.class.php），支持 im1907.top / m1907.top / hi1907.top / z1.m1907.cn，任意直链 m3u8/mp4 直接播放
- 实现：m1907 api/v2 日期/星期签名（z、s1ig）+ 响应 AES-256-GCM 解密取源，返回 title / url / eps 结构
- 更新：parserVideo.class.php 路由新增 m1907 站点；任意 `?url=` 直链(m3u8/mp4) 优先走 M1907，其余仍按原 flvcd 兜底
- 验证：真实直链 m3u8/mp4 及 m1907 聚合目录源实测可解析；官方站点（腾讯/爱奇艺等）URL 识别不稳定，已在文档注明

### v.0.0.2 (2026-10-11)

- 新增：搜狐视频官方 v4 接口解析器（core/sohu.class.php），支持 tv.sohu.com/v/xxx.html、m.tv.sohu.com/v{vid}.shtml 等链接，返回 original/super/high/normal 多清晰度 m3u8
- 新增：芒果TV官方接口链路解析器（core/hunantv.class.php），player/video → getSource → atcl 三步获取 480P/576P/720P 免费档 m3u8
- 更新：parserVideo.class.php 路由新增 m.tv.sohu.com、mgtv.com，搜狐/芒果入口接入主路由
- 验证：搜狐、芒果TV 均使用真实链接实测可播；哔哩哔哩解析保持可用

### v.0.0.1 (2026-10-10)

- 修复：新增哔哩哔哩官方 API 解析器（core/bilibili.class.php），支持 BV / av / 短链 / 分P / 多清晰度
- 修复：parserVideo.class.php 路由增加 bilibili.com / b23.tv
- 修复：getVideo.php 支持命令行与 Web 传参，清理废弃的 xhprof 代码
- 说明：2013 年旧站点（优酷/爱奇艺/腾讯等）接口均已关闭，暂时无法解析

---
原项目: [keygle/parseVideo](https://github.com/keygle/parseVideo)
