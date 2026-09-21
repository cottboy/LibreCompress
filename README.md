# LibreCompress

免费的 WordPress 图片压缩与格式转换插件。所有压缩均在服务器本地调用开源命令行工具完成，**不依赖任何第三方云服务**：无配额限制、无图片数量上限、无需注册 API Key，图片也不会被上传到外部服务器。

## 功能特性

- **六种格式压缩**：JPEG、PNG、WebP、AVIF、GIF、SVG，均支持有损/无损模式与质量参数调节
- **格式转换**：PNG / JPG / GIF / SVG 一键转换为 WebP 或 AVIF，前端直接输出新格式
- **上传自动化**：上传时自动压缩（可选），随后按勾选的源格式自动转换
- **原图备份**：压缩/转换前自动备份，可随时恢复单张或全部原图
- **媒体库集成**：列表新增压缩状态列，显示节省空间，支持单图压缩、恢复、删除备份
- **批量操作**：批量压缩、批量转换、清除压缩记录、恢复/删除全部备份
- **缩略图管理**：禁止生成缩略图、删除已有缩略图（自动替换文章内链接）、按需重新生成
- **SVG 安全上传**：上传时自动清理脚本等危险内容（防 XSS / XXE）
- **多语言就绪**：所有界面文字基于翻译域 `libre-compress`，可通过语言文件翻译

## 环境要求

| 项目 | 要求 |
| --- | --- |
| WordPress | 5.0 及以上 |
| PHP | 7.4 及以上 |
| `exec()` 函数 | 必须可用（压缩依赖命令行工具，设置页会自动检测） |
| 压缩工具 | 按需安装（见下文），装多少用多少，缺工具的格式自动跳过 |
| Node.js | 仅优化 SVG（svgo）时需要 |

## 安装插件

1. 将 `LibreCompress` 目录上传到 `/wp-content/plugins/`
2. 在 WordPress 后台「插件」页面启用
3. 进入「设置 → LibreCompress」配置

## 安装压缩工具

插件本身不带二进制文件，需自行部署所需工具，共三种方式（任选其一）：

1. **内置 bin 目录**：把可执行文件放进 `/wp-content/LibreCompress-bin/` 目录（Windows 下文件需带 `.exe` 扩展名）。该目录在插件更新时不会被覆盖
2. **系统 PATH**：把工具所在文件夹加入服务器的 Path 环境变量
3. **包管理器**：如 `apt`、`brew`、`npm` 等直接安装

安装后在「设置 → LibreCompress → 压缩工具」标签页可查看每个工具的检测状态、安装路径与下载链接。

### 工具清单与下载地址

| 工具 | 用途 | 下载地址 |
| --- | --- | --- |
| jpegoptim | JPEG 压缩 | <https://github.com/tjko/jpegoptim> |
| pngquant | PNG 有损压缩 | <https://pngquant.org> |
| oxipng | PNG 无损压缩 | <https://github.com/shssoichiro/oxipng> |
| gifsicle | GIF 压缩 | <https://www.lcdf.org/gifsicle/> |
| cwebp | WebP 压缩、转换编码 | <https://developers.google.com/speed/webp/download> |
| gif2webp | 动画 GIF 转 WebP | <https://developers.google.com/speed/webp/download> |
| avifenc | AVIF 编码（压缩与转换） | <https://github.com/AOMediaCodec/libavif/releases> |
| avifdec | AVIF 解码（压缩需要） | <https://github.com/AOMediaCodec/libavif/releases> |
| svgo | SVG 优化（`npm install -g svgo`） | <https://github.com/svg/svgo> |
| ffmpeg | 动画 GIF 转 AVIF | <https://ffmpeg.org/download.html> |
| resvg | SVG 转 WebP/AVIF 渲染 | <https://github.com/linebender/resvg/releases> |

### 各功能的依赖关系

**压缩**（按设置的压缩模式选择工具，PNG 两者缺其一时自动回退另一个）：

| 功能 | 依赖 |
| --- | --- |
| JPEG 压缩 | jpegoptim |
| PNG 压缩 | pngquant（有损）或 oxipng（无损） |
| WebP 压缩 | cwebp |
| AVIF 压缩 | avifenc + avifdec |
| GIF 压缩 | gifsicle |
| SVG 优化 | svgo |

**格式转换**（目标格式 WebP / AVIF 二选一；GD 为 PHP 自带扩展）：

| 功能 | 依赖 |
| --- | --- |
| PNG / JPG 转 WebP | cwebp |
| PNG / JPG 转 AVIF | avifenc |
| 静态 GIF 转 WebP | GD + cwebp |
| 静态 GIF 转 AVIF | GD + avifenc |
| 动画 GIF 转 WebP | gif2webp |
| 动画 GIF 转 AVIF | ffmpeg + avifenc |
| SVG 转 WebP | resvg + cwebp |
| SVG 转 AVIF | resvg + avifenc |

## 使用说明

### 基本设置

- **自动压缩**：上传图片时自动压缩（原图与全部缩略图）
- **备份原图**：压缩前把原图备份到独立目录，可随时恢复
- **压缩并发数**：批量压缩时同时执行的进程数（1-100）
- **SVG 上传**：允许上传 SVG 文件，上传时自动清理危险内容
- **格式转换**：勾选 PNG / JPG / GIF / SVG 中需要转换的格式，并选择目标格式（WebP 兼容性好，AVIF 压缩率更高但编码慢）

### 压缩工具页

- **系统状态**：检测 `exec()` 是否可用
- **工具状态**：逐个检测工具是否安装、显示安装路径与下载链接
- **路径支持状态**：以矩阵形式展示每条压缩/转换路径是否可用、缺少哪些依赖
- **压缩参数**：各格式的有损/无损模式与质量参数（JPEG/PNG/WebP/AVIF/GIF 质量 0-100，PNG 无损级别 0-6，SVG 精度 0-8）

### 转换行为说明

- 转换后**原文件从原位置移除**（开启备份时先备份），新格式文件同名仅替换扩展名，附件的 MIME 与元数据同步更新，WordPress 前端直接输出新格式 URL
- 转换产物**不小于**源文件时自动放弃转换、保留原文件
- 文章内容中已固化的旧格式 URL 会在新格式文件存在时自动替换
- 恢复原图时会反向处理：移除新格式文件并还原 MIME 与元数据

### 批量操作

设置页底部提供：批量压缩未压缩图片、批量转换未转换图片、清除压缩记录、恢复所有原图备份、删除所有原图备份，以及禁止/重新启用缩略图、删除已有缩略图（文章内链接替换为原图）、重新生成缩略图（文章内链接替换为「大」尺寸）。

## 安全设计

- 所有命令参数经 `escapeshellarg()` 转义，杜绝命令注入
- 文件路径经 `realpath()` 校验并限定在 uploads 目录内，拒绝 `..` 目录穿越
- SVG 上传清理：移除 `script` / `foreignObject` 元素、`on*` 事件属性、`javascript:` / `vbscript:` / `data:text/html` 协议；含 `DOCTYPE` / `ENTITY` 的文件直接拒绝（防 XXE），XML 解析启用 `LIBXML_NONET`
- 全部 AJAX 接口校验 nonce 与用户权限
- 备份目录与工具 bin 目录均放置 `.htaccess` / `index.php` 防止直接访问

## 数据存储

| 数据 | 位置 |
| --- | --- |
| 压缩记录表 | `{表前缀}libre_compress_records` |
| 备份记录表 | `{表前缀}libre_compress_backups` |
| 原图备份文件 | `/wp-content/uploads/libre-compress-backups/` |
| 压缩工具二进制 | `/wp-content/LibreCompress-bin/` |

卸载（删除）插件时会自动清理以上全部数据：删除数据表、设置项、备份目录与工具目录。

## 目录结构

```
LibreCompress/
├── libre-compress.php            # 插件入口、激活/停用钩子、SVG 上传安全
├── uninstall.php                 # 卸载清理脚本
├── compression/                  # 压缩工具封装
│   ├── class-tool-base.php       # 工具基类（查找、执行、转义）
│   └── class-{tool}.php          # jpegoptim/pngquant/oxipng/cwebp/avif/gifsicle/svgo
├── includes/
│   ├── class-libre-compress.php  # 主类（单例，协调各模块）
│   ├── class-compressor.php      # 压缩调度器
│   ├── class-converter.php       # 格式转换器
│   ├── class-backup.php          # 原图备份
│   ├── class-media-library.php   # 媒体库集成
│   ├── class-thumbnail-manager.php # 缩略图管理
│   ├── class-settings.php        # 设置页
│   └── class-database.php        # 数据库表
├── assets/js/admin.js            # 后台批量与单图操作脚本
└── languages/libre-compress.pot  # 翻译模板
```

## 开发者接口

自定义压缩工具需继承 `Libre_Compress_Tool_Base` 并实现 `get_name()`、`get_supported_formats()`、`get_executable_name()`、`build_command()` 等方法，然后通过钩子注册：

```php
add_action( 'libre_compress_register_tools', function ( $compressor ) {
    $compressor->register_tool( new My_Custom_Tool() );
} );
```

可用钩子：

- `libre_compress_register_tools` —— 注册自定义压缩工具
- `libre_compress_before_compress` —— 单文件压缩前触发
- `libre_compress_after_compress` —— 单文件压缩成功后触发
- `libre_compress_after_restore` —— 恢复原图后触发（转换器借此反向处理）

## 许可证

GPL v2 or later。详见 [LICENSE](LICENSE)。
