# parseVideo

解析国内各大视频网站视频 PHP 项目（2013 年原版，已修复可用）

## 版本信息

当前版本：**v.0.0.1**（2026-10-10）

## 功能

- 解析视频真实播放地址（m3u8 / mp4 / flv）
- `getVideo.php` 为测试入口，支持 Web（`?url=`）和命令行两种方式
- 支持分P视频解析（`?p=N`）、av 号 / BV 号、b23.tv 短链

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
| 优酷 / 爱奇艺 / 腾讯 / 土豆 / 搜狐 / 乐视 / 芒果 / 56 / 酷6 / 新浪 / 迅雷看看 / 时光网 / PPS / PPTV / tv189 | 已失效 | 2013 年旧接口已关闭 |

## 解析结果字段

- `title` 视频标题
- `img` 封面图
- `seconds` 时长(秒)
- `super` / `high` / `normal` / `fluent` 各清晰度播放地址数组
- `pages` 分P列表（多P视频）

## 更新日志

### v.0.0.1 (2026-10-10)

- 修复：新增哔哩哔哩官方 API 解析器（core/bilibili.class.php），支持 BV / av / 短链 / 分P / 多清晰度
- 修复：parserVideo.class.php 路由增加 bilibili.com / b23.tv
- 修复：getVideo.php 支持命令行与 Web 传参，清理废弃的 xhprof 代码
- 说明：2013 年旧站点（优酷/爱奇艺/腾讯等）接口均已关闭，暂时无法解析

---
原项目: [keygle/parseVideo](https://github.com/keygle/parseVideo)
