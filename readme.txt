=== Image2URL Clipboard Booster ===
Contributors: image2url
Tags: images, upload, clipboard, gutenberg, media, cloud, automation
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

让 Gutenberg 粘贴图片即上云，自动返回可长期访问的外链，减少站点 inode 占用。支持自定义上传端点与体积限制。

== Description ==

Image2URL Clipboard Booster 是一个专为 WordPress 设计的图片上传插件，解决共享主机 inode 限制问题。

**核心功能：**
* 剪贴板直传：在 Gutenberg 编辑器中粘贴图片，自动上传到云端并插入外链
* 端点可配置：支持自建 API 或自定义域名
* 体积限制：本地预检查，避免超大文件上传
* 无侵入部署：启用即用，停用恢复默认行为

**适用场景：**
* 共享主机 inode 限制严重的站点
* 需要减少本地媒体库占用的用户
* 追求高效图片上传体验的编辑者

**安全特性：**
* CSRF 攻击防护
* 文件类型签名验证
* 恶意内容扫描
* 速率限制保护

== Installation ==

1. 下载插件压缩包
2. 在 WordPress 后台进入 "插件" -> "安装插件" -> "上传插件"
3. 选择下载的 zip 文件并安装
4. 启用插件
5. 在 "设置" -> "Image2URL" 中进行配置

== Frequently Asked Questions ==

= 支持哪些图片格式？ =

支持 JPEG、PNG、GIF、WebP 和 SVG 格式，所有文件都会经过严格的安全验证。

= 是否会在本地保存图片？ =

默认不会占用本地媒体库空间，图片直接上传到配置的云端服务。未来版本将提供双备份模式。

= 如何配置自定义上传端点？ =

在插件设置页面修改"上传端点"字段，支持自建 API 服务或自定义域名。

= 上传失败会重试吗？ =

会自动重试最多 3 次，采用指数退避策略（1s、2s、4s 间隔）。

== Screenshots ==

1. 插件设置页面
2. Gutenberg 粘贴图片演示
3. 上传成功提示

== Changelog ==

= 0.1.0 =
* 首次发布
* Gutenberg 剪贴板图片上传
* 自定义端点配置
* 安全验证机制
* 重试机制实现

== Upgrade Notice ==

== Reviews ==

== Other Notes ==

**技术要求：**
* WordPress 5.0+
* PHP 7.4+
* fileinfo 扩展
* curl 扩展

**隐私政策：**
本插件不收集用户数据，所有图片上传至用户配置的第三方服务。

== Development ==

* GitHub: https://github.com/your-username/image2url-wordpress
* 报告问题: https://github.com/your-username/image2url-wordpress/issues