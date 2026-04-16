=== Image2URL Clipboard Booster ===
Contributors: image2url
Tags: images, upload, clipboard, gutenberg, cloud
Requires at least: 5.0
Tested up to: 6.9.4
Stable tag: 0.7.0
License: MIT
License URI: https://opensource.org/licenses/MIT

让 Gutenberg 粘贴图片即上云，自动返回可长期访问的外链，减少站点 inode 占用。支持自定义上传端点与体积限制。

== Description ==

Image2URL Clipboard Booster 是一个专为 WordPress 设计的图片上传插件，解决共享主机 inode 限制问题。

**核心功能：**
* 剪贴板直传：在 Gutenberg 编辑器中粘贴图片，自动上传到云端并插入外链
* 多图顺序上传：一次粘贴多张图片时会顺序处理并批量插入
* 端点可配置：支持自建 API 或自定义域名，并提供后台连通性校验
* 本地回退工具：支持扫描文章中的外链图片并回退到 WordPress 媒体库
* 后台批量回退：批量任务会进入后台队列，由 WP-Cron 按批次执行并展示进度
* 区块与特色图同步：回退时会补齐 core/image、core/cover、core/media-text 区块的本地附件引用，并可自动设置正文首图为特色图
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

支持 JPEG、PNG、GIF、WebP 格式，所有文件都会经过严格的安全验证。

= 是否会在本地保存图片？ =

默认不会占用本地媒体库空间，图片直接上传到配置的云端服务。未来版本将提供双备份模式。

= 如何配置自定义上传端点？ =

在插件设置页面修改"上传端点"字段，支持自建 API 服务或自定义域名。

= 上传失败会重试吗？ =

会自动重试最多 3 次，采用指数退避策略（1s、2s、4s 间隔）。

= 可以验证自定义端点是否可用吗？ =

可以。设置页提供“验证端点”按钮，会直接从当前 WordPress 站点发起连通性检测。

= 如何把外链图片回退到本地媒体库？ =

进入 “工具 -> Image2URL Migration”，输入文章 ID 后即可扫描和回退。批量模式会创建后台任务，并由 WP-Cron 按批次自动执行。

= 回退后会同步区块和特色图吗？ =

会。`core/image`、`core/cover`、`core/media-text` 区块会被同步为本地附件引用，前端可重新获得本地 `srcset` 等能力。若文章还没有特色图，插件会默认尝试将正文首张已本地化图片设为特色图；如需关闭，可使用 `image2url_migration_auto_set_featured_image` 过滤器。

= 批量回退为什么没有继续执行？ =

批量回退依赖 WP-Cron。如果站点关闭了内置 WP-Cron，请确保服务器侧有定时任务触发 `wp-cron.php`，否则后台任务不会自动推进。

== Screenshots ==

1. 插件设置页面
2. Gutenberg 粘贴图片演示
3. 上传成功提示

== Changelog ==

= 0.7.0 =
* 回退同步扩展到 core/cover 和 core/media-text
* 这两类区块的媒体属性会切换为本地附件引用
* 特色图候选识别现在会优先考虑 cover 和 media-text 中的已本地化图片

= 0.6.0 =
* 回退时会同步 core/image 区块属性到本地附件引用
* 本地化后的图片区块重新获得响应式图片标记
* 文章无特色图时，会自动尝试将正文首张已本地化图片设为特色图

= 0.5.0 =
* 批量回退改为 WP-Cron 后台执行，不依赖当前管理页持续打开
* 新增后台任务轮询与重新入队能力
* 新增任务锁，避免同一批量任务并发重复处理
* 插件停用/卸载时会清理批量任务定时事件

= 0.4.0 =
* 新增批量回退任务表和任务状态面板
* 批量回退改为按批次执行，避免单次请求超时
* 新增后台自动执行与进度刷新脚本
* 任务执行结果支持最近日志展示

= 0.3.0 =
* 新增图片映射表，用于记录外链和本地附件关系
* 上传成功后自动记录文章级远端图片映射
* 新增后台迁移工具页，支持单篇扫描、单篇回退和基础批量回退
* 支持将文章中的外链图片下载到媒体库并替换为本地 URL

= 0.2.0 =
* 改进远端上传传输实现，增强兼容性
* 将速率限制改为跨请求生效
* 新增后台端点连通性验证
* 支持一次粘贴多张图片后顺序上传
* 收紧默认支持格式，移除 SVG 默认支持

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
