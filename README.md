# LibreCompress

免费的纯本地 WordPress 图片压缩插件。所有处理都在服务器本地调用开源命令行工具完成，**不依赖任何第三方云服务**：无需 API Key，图片不会上传到外部服务器。

## 核心规则

插件对用户只提供一个概念：**压缩**。

一次压缩会根据当前设置，为每个文件选择以下一种处理方式：

1. **同格式压缩**：JPEG → JPEG、PNG → PNG、WebP → WebP 等。
2. **目标格式输出**：把勾选的 PNG、JPG、GIF、SVG 压缩为 WebP 或 AVIF。

以下入口全部共用同一套处理流程和压缩记录：

- 上传图片时自动压缩；
- 媒体库单张图片的“压缩”按钮；
- 设置页的“批量压缩未压缩的图片”按钮；
- 重试未完成的图片。

同格式压缩成功和目标格式输出成功都会写入同一个压缩记录表，并统一视为“已压缩”。如果附件只有部分文件成功，媒体库会显示“部分已压缩”，批量操作只重试尚未完成的文件。

## 功能特性

- **六种格式压缩**：JPEG、PNG、WebP、AVIF、GIF、SVG。
- **可配置目标格式**：PNG / JPG / GIF / SVG 可按设置压缩为 WebP 或 AVIF。
- **统一批量入口**：一个按钮按当前设置处理所有未压缩图片，使用附件 ID 游标分页，不存在固定数量上限。
- **统一状态**：媒体库、批量操作和上传自动处理使用相同的文件级成功记录。
- **原图备份**：压缩前可自动备份，支持恢复单张或全部原图。
- **附件级排他锁**：同一附件的压缩、恢复和缩略图操作不会并发互相覆盖。
- **元数据同步**：目标格式输出后同步 `_wp_attached_file`、附件 metadata、文件大小和 MIME；主文件失败时不会把整个附件错误标记为新格式。
- **内容 URL 修复**：文章中的旧源格式 URL 根据持久化映射替换为实际压缩结果。
- **缩略图管理**：禁止生成、删除已有缩略图、按需重新生成。
- **SVG 安全上传**：清理脚本、事件属性和危险协议，写入失败时终止上传。
- **多语言就绪**：界面文字使用翻译域 `libre-compress`。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| WordPress | 5.0 及以上 |
| PHP | 7.4 及以上 |
| `exec()` / `proc_open()` | 必须可用，压缩依赖本地命令行工具并使用超时保护 |
| PHP DOM 扩展 | 仅启用 SVG 上传时需要 |
| 压缩工具 | 按需安装，缺少对应工具时该路径会安全跳过 |

## 安装插件

1. 将 `LibreCompress` 目录上传到 `/wp-content/plugins/`。
2. 在 WordPress 后台启用插件。
3. 进入“设置 → LibreCompress”配置。
4. 在“压缩工具”页面检查本地工具状态。

## 安装压缩工具

插件不携带二进制文件，可任选一种方式：

1. 放入 `/wp-content/LibreCompress-bin/`（Windows 下通常需要 `.exe` 后缀）。
2. 将工具目录加入系统 `PATH`。
3. 使用系统包管理器安装。

### 工具清单

| 工具 | 用途 |
| --- | --- |
| jpegoptim | JPEG 同格式压缩 |
| pngquant | PNG 有损压缩 |
| oxipng | PNG 无损压缩 |
| gifsicle | GIF 同格式压缩 |
| cwebp | WebP 同格式压缩，以及 PNG/JPG/GIF/SVG 输出为 WebP |
| gif2webp | 动画 GIF 输出为 WebP |
| avifenc | AVIF 压缩编码器 |
| avifdec | AVIF 同格式压缩所需解码器 |
| ffmpeg | 动画 GIF 输出为 AVIF |
| svgo | SVG 同格式压缩 |
| resvg | SVG 输出为 WebP/AVIF 前的栅格化处理 |

### 同格式压缩依赖

| 格式 | 依赖 |
| --- | --- |
| JPEG | jpegoptim |
| PNG 有损 | pngquant |
| PNG 无损 | oxipng |
| WebP | cwebp |
| AVIF | avifenc + avifdec |
| GIF | gifsicle |
| SVG | svgo |

PNG 严格按当前有损/无损设置选择工具，不会静默切换压缩模式。

### 目标格式输出依赖

| 源格式 | WebP | AVIF |
| --- | --- | --- |
| PNG / JPG | cwebp | avifenc |
| 静态 GIF | GD + cwebp | GD + avifenc |
| 动画 GIF | gif2webp | ffmpeg + avifenc |
| SVG | resvg + cwebp | resvg + avifenc |

同格式 AVIF 压缩只支持可确认的单帧文件；动画或多帧 AVIF 会安全跳过，避免静默丢帧。

## 使用说明

### 基本设置

- **自动压缩**：上传图片时按当前设置自动压缩原图和全部尺寸。
- **备份原图**：压缩结果提交前创建可恢复备份；备份失败时停止破坏性处理。
- **压缩并发数**：批量操作使用的附件级并发数。
- **SVG 上传**：允许受信任用户上传 SVG，并清理危险内容。
- **压缩输出格式**：勾选需要输出为 WebP/AVIF 的源格式；未勾选格式只做同格式压缩。
- **目标格式**：WebP 或 AVIF 二选一。

### 压缩结果规则

- 只有结果文件存在、可识别且体积严格小于源文件时才提交成功。
- 目标格式结果小于源文件时，以同名不同扩展名文件替换源文件。
- 同格式压缩和目标格式输出都保存当前文件路径、原始大小、压缩后大小、工具和状态。
- 压缩记录与当前文件路径不一致时视为未完成，允许重新处理。
- 附件内任意尺寸失败都不会把附件级 MIME 改成目标格式；只有主文件成功才更新附件级 MIME。
- 恢复原图时只删除确认属于该附件且路径位于 uploads 目录内的目标结果，并还原 `_wp_attached_file`、metadata 和 MIME。
- 批量操作只重试尚未成功的文件，不会再次压缩已经成功的尺寸。

### 批量操作

设置页提供一个批量入口：

- **批量压缩未压缩的图片**

该按钮按附件 ID 游标逐页读取媒体库，并使用设置中的并发数处理。所有成功路径统一计入“已压缩”。

设置页还提供：

- 清除压缩记录；
- 恢复所有原图备份；
- 删除所有原图备份；
- 禁止/重新启用缩略图；
- 删除已有缩略图；
- 重新生成缺少的缩略图。

## 安全与可靠性

- AJAX 接口验证 nonce、管理员权限、附件类型和附件 ID。
- 用户输入不直接提供文件路径；服务端始终根据附件 ID 和元数据重新解析文件。
- 文件路径规范化后必须位于 uploads 目录边界内。
- 备份、压缩、目标格式输出、恢复和缩略图操作使用附件级排他锁。
- 统一处理器生成的临时文件和回滚文件使用唯一名称。
- 压缩结果和恢复映射必须成功写入后才提交替换。
- 备份采用随机文件名，并包含 Apache、IIS 基础访问保护。
- SVG 清理会拒绝 DOCTYPE/ENTITY、危险元素、事件属性和脚本协议。

## 数据存储

| 数据 | 位置 |
| --- | --- |
| 统一压缩记录 | `{表前缀}libre_compress_records` |
| 备份记录 | `{表前缀}libre_compress_backups` |
| 目标格式输出恢复映射 | 附件 post meta `_libre_compress_converted` |
| 原图备份文件 | `/wp-content/uploads/libre-compress-backups/` |
| 本地工具 | `/wp-content/LibreCompress-bin/` |
| 附件处理锁 | `/wp-content/uploads/.libre-compress-locks/` |

## 目录结构

```text
LibreCompress/
├── libre-compress.php
├── uninstall.php
├── compression/                    # 本地压缩工具封装
├── includes/
│   ├── class-libre-compress.php    # 主类和模块协调
│   ├── class-processor.php         # 统一压缩入口、状态和附件锁
│   ├── class-compressor.php        # 同格式压缩底层处理
│   ├── class-converter.php         # 目标格式输出底层处理
│   ├── class-backup.php            # 原图备份和恢复
│   ├── class-media-library.php     # 媒体库状态和 AJAX
│   ├── class-thumbnail-manager.php # 缩略图管理
│   ├── class-settings.php          # 设置页面
│   └── class-database.php          # 数据访问
├── assets/js/admin.js
└── languages/libre-compress.pot
```

## 开发者接口

自定义同格式压缩工具可继承 `Libre_Compress_Tool_Base`，实现 `get_name()`、`get_supported_formats()`、`get_executable_name()`、`build_command()` 等方法，然后通过以下钩子注册：

```php
add_action( 'libre_compress_register_tools', function ( $compressor ) {
    $compressor->register_tool( new My_Custom_Tool() );
} );
```

可用钩子：

- `libre_compress_register_tools`：注册同格式压缩工具；
- `libre_compress_before_compress`：每个文件压缩前触发；
- `libre_compress_after_compress`：每个文件压缩成功后触发。

## 许可证

GPL v2 or later。详见 [LICENSE](LICENSE)。
