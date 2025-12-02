# WordPress 插件市场上传详细指南（对齐最新版 Detailed Plugin Guidelines）

## 前置要求

### 1) 账户与权限
- 拥有活跃的 WordPress.org 账户并验证邮箱
- 可访问 https://wordpress.org/plugins/developers/add/

### 2) 开发/提交工具
```bash
# Windows
# GUI + CLI: TortoiseSVN https://tortoisesvn.net/
# 仅命令行: winget install Slik.Subversion --accept-source-agreements --accept-package-agreements

# macOS
brew install subversion

# Linux (Ubuntu/Debian)
sudo apt-get install subversion
```

### 3) 插件文件结构检查
```
image2url-wordpress/
├── image2url-wordpress.php     # 主插件文件
├── readme.txt                  # WordPress.org 说明文件
├── README.md                   # 开发文档
├── SECURITY.md                 # 安全文档
├── UPLOAD_GUIDE.md             # 本指南
├── assets/
│  ├── js/editor-paste.js
│  ├── banner-772x250.png
│  ├── banner-1544x500.png
│  ├── icon-128x128.png
│  └── icon-256x256.png
└── includes/
   ├── class-image2url-plugin.php
   └── class-image2url-security.php
```

## 提交前合规清单（基于 2025-02 Detailed Plugin Guidelines）
- 许可证：GPLv2+，主文件和 readme.txt 均声明 License/License URI；所有依赖 GPL 兼容。
- 代码安全：无混淆/加密/远程下载执行；不使用 eval/shell/dynamic include。
- 数据与隐私：默认不收集个人数据或遥测；如有外部请求/日志，readme 与设置页须披露并可关闭。
- 权限与体验：不滥用管理员权限，不强制连接外部服务，无误导性/过度 nag 通知。
- 命名：slug 与功能一致，不使用 “WordPress” 前缀或商标滥用。
- 失效保护：外部请求失败时回退，不阻断编辑器基本功能。
- 停用/卸载：提供 `uninstall.php` 或 `register_uninstall_hook` 清理数据（除非用户选择保留）。
- 输入/输出：使用 `sanitize_*` / `esc_*`，关键操作使用 nonce 与 capability 检查。
- 包内无敏感密钥，包含规范 `readme.txt`（Stable tag / Tested up to / Requires PHP / License）。

## 打包插件
```powershell
# Windows PowerShell（在插件根目录）
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

## 提交插件审核
1. 打开 https://wordpress.org/plugins/developers/add/ 并登录  
2. 填写基本信息：  
   - Plugin Name: `Image2URL Clipboard Booster`  
   - Description: “Gutenberg 粘贴图片即上云，自动返回可长期访问的外链，减少本地存储与 inode 占用。”  
   - Plugin URL: 插件官网或 GitHub 仓库  
   - 上传 `image2url-wordpress.zip`
3. 提交后等待审核分配（约 1-7 个工作日）

## 设置 SVN 仓库（审核通过后）
1. 获取仓库地址（示例）：`https://plugins.svn.wordpress.org/image2url-clipboard-booster/`
2. 检出仓库
```bash
mkdir ~/wordpress-plugins
cd ~/wordpress-plugins
svn checkout https://plugins.svn.wordpress.org/image2url-clipboard-booster/ image2url-clipboard-booster
cd image2url-clipboard-booster
```
3. 仓库结构
```
image2url-clipboard-booster/
├── assets/
├── branches/
├── tags/
└── trunk/
```

## 上传插件文件到 SVN
```bash
# 复制文件到 trunk 与 assets
cp -r /path/to/image2url-wordpress/* trunk/
cp -r /path/to/image2url-wordpress/assets/* assets/

# 添加文件并提交
svn add --force trunk/ assets/
svn status   # 确认 A 状态
svn commit -m "Initial commit of Image2URL Clipboard Booster v0.1.0"
```

## 创建版本标签
```bash
svn copy trunk/ tags/0.1.0/
svn commit -m "Tagging version 0.1.0"
```
确保 `readme.txt` 中 `Stable tag: 0.1.0`。

## 更新与维护
```bash
# 开发更新
# ...修改文件...
svn status
svn add trunk/new-file.php      # 如有新增
svn delete trunk/old-file.php   # 如有删除
svn commit -m "Update plugin to v0.1.1 with bug fixes"

# 打标签
svn copy trunk/ tags/0.1.1/
svn commit -m "Tagging version 0.1.1"
```

## 常见问题与排查
- SVN 连接失败：检查网络、代理，或 `ping plugins.svn.wordpress.org`
- 认证问题：清除缓存  
  - Windows: 删除 `%APPDATA%\Subversion\auth\` 内缓存  
  - macOS/Linux: `rm -rf ~/.subversion/auth/`
- 权限：确保工作目录可写；Linux/macOS 可 `chmod -R 755 ~/wordpress-plugins/`
- 审核拒绝：对照 Detailed Plugin Guidelines 修复安全/隐私/命名/数据清理等问题后重提

## 最佳实践
- 使用语义化版本号，重要版本创建 tag
- 及时更新 readme.txt（Stable tag、Tested up to、Changelog）
- 定期安全审查和依赖更新
- 监控用户反馈并及时响应

成功上传后，插件将上线到：https://wordpress.org/plugins/image2url-clipboard-booster/
