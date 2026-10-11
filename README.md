# parseVideo

解析国内各大视频网站视频 PHP 项目（2013 年原版，已修复更新可用）

## 版本信息

当前版本：**v.0.0.6**（2026-10-11）

## 功能

- 解析视频真实播放地址（m3u8 / mp4 / flv）
- `getVideo.php` 为测试入口，支持 Web（`?url=`）和命令行两种方式
- `m1907parse.php` 为 M1907 **JSON 接口**，统一返回 `{code,msg,url,time,KFZ}` 格式，支持命令行与 Web/HTTP
- 哔哩哔哩支持分P视频解析（`?p=N`）、av 号 / BV 号、b23.tv 短链
- 搜狐 / 芒果TV 使用官方现行接口，匿名可用
- M1907 云加速解析（im1907.top / m1907.top / hi1907.top / z1.m1907.cn），支持任意直链 m3u8/mp4 直接播放、m1907 云加速接口 AES-GCM 解密取源

## 使用方式

Web（数组调试输出）:

```
getVideo.php?url=https://www.bilibili.com/video/BV1GJ411x7h7
```

命令行:

```
php getVideo.php "https://www.bilibili.com/video/BV1GJ411x7h7"
```

### JSON 接口（m1907parse.php）

命令行:

```
php m1907parse.php "狂飙"
php m1907parse.php "http://www.iqiyi.com/v_xkt6z3z798.html"
```

Web:

```
GET /m1907parse.php?url=<视频地址或片名>&ep=<集数,默认1>
GET /api/m1907?url=<视频地址或片名>&ep=<集数>
```

响应格式:

```json
{ "code": 200, "msg": "解析成功", "url": "https://.../index.m3u8", "time": "2026-10-11 20:50:24", "KFZ": "第1集" }
```

`code`：200 成功 / 404 无参数 / 403 需验证码 / 416 无法解析 / 500 解析失败 / 601 内容受限。
`KFZ`：清晰度或集数标记（如 `第01集`、`直链`）。

## 支持站点

| 站点 | 状态 | 说明 |
|------|------|------|
| 哔哩哔哩 bilibili.com / b23.tv | 可用 | 官方 API 解析，支持分P、多清晰度 |
| M1907 im1907.top / m1907.top / hi1907.top / z1.m1907.cn | 可用 | 云加速接口 AES-GCM 解密取源；任意直链 m3u8/mp4 直接播放 |
| 腾讯 v.qq.com / v.qq.net | 部分可用 | 旧接口已失效时自动回退 M1907 云加速（片名关键词搜索取源） |
| 爱奇艺 iqiyi.com / qiyi.com | 部分可用 | 同上，自动回退 M1907 云加速 |
| 优酷 youku.com / tudou.com | 部分可用 | 同上，可回退 M1907 云加速 |
| 搜狐视频 tv.sohu.com / m.tv.sohu.com / my.tv.sohu.com | 可用 | 官方 v4 接口，匿名可解析，多清晰度 m3u8 |
| 芒果TV mgtv.com / hunantv.com | 可用 | 官方接口链路，免费档 480P/576P/720P 可播 |
| 乐视 / 56 / 酷6 / 新浪 / 迅雷看看 / 时光网 / PPS / PPTV / tv189 | 已失效 | 2013 年旧接口已关闭，且受平台风控限制，暂无法解析 |

> 说明：优酷（阿里云盾）、爱奇艺（dash 签名）等官方独立接口匿名解析受限，短网址/移动端链接因 JS 渲染、服务端风控可能导致标题提取失败。
> M1907 云加速对官方站点支持：官方 URL 直接解析失败时，自动抓取页面提取片名并按关键词搜索取源；对无法直接改名/无法抓取的链接，可在 URL 后追加 `&t=片名`、`&name=片名` 或 `&title=片名` 强制按片名搜索。

## 解析结果字段

- `title` 视频标题
- `img` 封面图
- `seconds` 时长(秒)
- `super` / `high` / `normal` / `fluent` / `original` 各清晰度播放地址数组
- `pages` 分P列表（哔哩哔哩多P视频）
- `source` 来源（M1907直链 / M1907云加速）
- `eps` 全部清晰度/集数列表（`[{name,url}]`，M1907）

## 更新日志

### v.0.0.6 (2026-10-11)

- 新增：`m1907parse.php` JSON 接口纳入仓库，统一 `{code,msg,url,time,KFZ}` 返回，支持命令行与 Web（`/m1907parse.php`、`/api/m1907`）、`ep` 集数参数
- 增强：JSON 接口补齐官方站点回退——官方 URL 直接解析失败时抓页面提片名(og:title / `<title>` / JSON-LD / 内嵌 JS)，再走关键词搜索；支持显式片名参数 `?t=` / `?name=` / `?title=`
- 修复：`decrypt` 增加密文长度前置校验（< IV+TAG 直接判失败），消除 API 返回短响应时 `openssl_decrypt` 的 PHP Warning 污染 JSON 输出（JSON 接口与 core/m1907.class.php 同步）
- 验证：`php m1907parse.php "狂飙"` / 爱奇艺剧集 / 腾讯 cover 等真实链接均返回 `code:200` 且含可播 m3u8

### v.0.0.5 (2026-10-11)

- 修复：`cleanTitle()` 调用 `mb_strlen()` 在未安装 mbstring 扩展的环境下致命错误（Fatal），改为 `preg_match_all('/./u')` 计数，不依赖 mbstring
- 优化：片名清洗按 CN 站点标题约定「片名-类型-描述-站点」取首个分隔符前主体（如 `狂飙-电视剧-全集高清正版视频在线观看-爱奇艺` → `狂飙`），并强化无效标题过滤（含 404/页面不存在/找不到 一律丢弃）
- 真实链接实测（PHP 命令行与 Web 入口 getVideo.php 双通道）：
  - 腾讯 `v.qq.com/x/cover/mzc00200803dr6b.html` → 兰香如故（47 集）
  - 爱奇艺 `iqiyi.com/v_xkt6z3z798.html` / `v_1nvdvo4f38k.html` → 狂飙（39/38 集）
  - 爱奇艺专辑 `iqiyi.com/a_1fkgtbddd2x.html` → 狂飙（清洗规则生效前误判，现已修正）
  - 哔哩哔哩 `BV1GJ411x7h7` / `b23.tv/BV1GJ411x7h7` → 多清晰度 mp4 正常
  - 输出 m3u8 经二次校验：HTTP 200 + `application/vnd.apple.mpegurl`，为合法 HLS 列表，真实可播
- 已知限制：腾讯新版封面页为 Vue SPA，HTML 无标题/描述（仅通用 `腾讯视频`），且 `s.video.qq.com` 对本机 IP 直接拒绝 TLS；此类链接请追加 `&name=片名`

### v.0.0.4 (2026-10-11)

- 增强：M1907（core/m1907.class.php）官方站点回退链路——官方 URL 直接解析失败时，自动抓取页面提取片名(og:title / `<title>` / JSON-LD / 内嵌 JS)，按关键词调用 api/v2 取源
- 新增：显式片名参数 `&t=` / `&name=` / `&title=`，调用方可强制按片名搜索（网页标题无法提取时的兜底）
- 增强：标题清洗——去除站点品牌后缀、丢弃纯品牌名/404 页等无效标题，避免用垃圾关键词检索
- 更新：parserVideo.class.php 路由——专门的站点解析器失败时自动回退 M1907 云加速
- 验证：腾讯 v.qq.com / 爱奇艺 iqiyi.com 官方链接经 `?name=` 参数实测可回退解析出可播 m3u8；哔哩哔哩、直链 m3u8 解析不受影响

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
