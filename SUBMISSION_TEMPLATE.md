# WordPress Plugin Submission Form Template

## 📝 提交表单填写模板

### Basic Plugin Information

**Plugin Name:** `Image2URL Clipboard Booster`

**Plugin Description:**  
Upload images to cloud services directly from clipboard in Gutenberg editor. Reduces local storage usage and inode consumption on shared hosting. Features security validation, retry mechanisms, and custom endpoint support.

**Plugin URL:** `https://github.com/image2url/image2url-wordpress`  

---

### 📋 Additional Information

#### Why do you want to add this plugin to the directory?
Image2URL solves shared hosting inode limitations by enabling clipboard-to-cloud image uploads in Gutenberg. It removes local storage requirements, adds CSRF/file validation/malicious-content checks, auto-retries, and allows custom endpoints—filling a gap for secure, zero-config real-time image uploads.

#### List any other similar plugins
Direct competitors: None (no existing Gutenberg clipboard-to-cloud plugins)  
Related plugins: Add From Server, External Media, Media from FTP, File Away  
Key differentiators: Real-time paste handling, zero local storage, enterprise security, user-friendly workflow.

#### Does your plugin use any 3rd party libraries or assets?
No external dependencies. Built entirely on WordPress core APIs: wp-blocks/wp-data/wp-element, wp-notices/wp-i18n/wp-a11y, Settings API + AJAX handlers, standard PHP + WordPress security functions.

#### Does your plugin have any commercial affiliations?
No commercial affiliations. GPLv2+ license.  
Default endpoint (image2url.com) is optional and replaceable; supports self-hosted/custom endpoints; no vendor lock-in or data collection.

---

## 🚀 最新指南合规清单（基于 2025-02 Detailed Plugin Guidelines）
- GPLv2 或更高；所有依赖 GPL 兼容；在主文件和 readme.txt 声明 License/License URI。
- 无混淆/加密/远程下载执行代码；不使用 eval/shell/dynamic include。
- 默认不采集个人数据或遥测；如需数据收集，需在 readme 和设置中披露并可关闭。
- 不滥用管理员权限、不劫持仪表盘、不强制外部服务、不展示过度 nag。
- 插件名称/slug 不滥用商标或 “WordPress” 前缀；与功能相关且无关键词堆砌。
- 外部请求可禁用或降级，失败不影响编辑器基础功能。
- 停用/卸载后清理数据（`uninstall.php` 或 `register_uninstall_hook`）。
- 输入/输出使用 `sanitize_*` / `esc_*`，关键操作使用 nonce + capability 检查。
- 包内无密钥/证书；`readme.txt` 包含 Stable tag / Tested up to / Requires PHP。

## ✅ 提交前步骤
1) 创建 GitHub 仓库并推送初始代码：
```bash
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/YOUR_USERNAME/image2url-wordpress.git
git push -u origin main
```

2) 创建插件压缩包：
```powershell
# Windows PowerShell
Compress-Archive -Path image2url-wordpress.php, readme.txt, assets, includes `
  -DestinationPath image2url-wordpress.zip -Force
```
```bash
# macOS / Linux
zip -r image2url-wordpress.zip \
  image2url-wordpress.php \
  readme.txt \
  assets/ \
  includes/
```

3) 本地测试：安装到本地站点，验证粘贴上传、设置页、错误处理、停用/卸载行为。

4) 合规/文件检查：
- [ ] readme.txt：Stable tag / Tested up to / Requires PHP / License / License URI 完整
- [ ] 主文件头：Plugin Name、Description、Version、License、Text Domain 完整
- [ ] 输入输出安全：sanitize/escape + nonce + capability
- [ ] 外部请求可关闭；默认不收集个人数据
- [ ] 卸载清理逻辑就绪，无残留敏感数据
- [ ] 无混淆或远程执行代码

提交地址：https://wordpress.org/plugins/developers/add/

---

## 📧 审核后准备工作

1. 收到 SVN 仓库与应用密码  
2. 安装 SVN 客户端  
3. 检出仓库并上传：
```bash
svn checkout https://plugins.svn.wordpress.org/image2url-clipboard-booster/ image2url-clipboard-booster
cd image2url-clipboard-booster
cp -r /path/to/plugin/* trunk/
cp -r /path/to/plugin/assets/* assets/
svn add --force trunk/ assets/
svn commit -m "Initial commit v0.1.0"
svn copy trunk/ tags/0.1.0/
svn commit -m "Tagging version 0.1.0"
```

---

## ⏱ 预期时间线
- 审核分配：约 1-7 个工作日
- 最终审核：约 7-15 个工作日
- 总时间：约 8-22 个工作日

## 📱 后续维护
- 及时回复用户评论和问题
- 定期更新（安全修复、功能改进），保持与最新 WP 版本兼容
- 监控错误日志和性能

---

更多细节请参考 `ADDITIONAL_INFORMATION.md`、`UPLOAD_GUIDE.md`、`SECURITY.md`。准备好后即可提交流程。
