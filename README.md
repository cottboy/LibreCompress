# LibreCompress

免费的纯本地 WordPress 图片压缩插件。所有处理都在服务器本地调用开源命令行工具完成，**不依赖任何第三方云服务**：无需 API Key，图片不会上传到外部服务器。

## 核心规则

插件对用户只提供一个概念：**压缩**。

一次压缩会根据当前设置，为每个文件选择以下一种处理方式：

1. **同格式压缩**：JPEG → JPEG、PNG → PNG、WebP → WebP 等。
2. **格式转换**：把勾选的 PNG、JPG、GIF 压缩为 WebP 或 AVIF。

以下入口全部共用同一套处理流程和压缩记录：

- 上传图片时自动压缩；
- 媒体库单张图片的“压缩”按钮；
- 设置页的“批量压缩未压缩的图片”按钮；
- 重试未完成的图片。

同格式压缩成功和格式转换成功都会写入同一个压缩记录表，并统一视为“已压缩”；转换后体积没有变小时保留原图，按节省 0% 记为已压缩，不会反复重试。缺工具、编码失败、文件损坏都不算完成。如果附件只有部分文件成功，媒体库会显示“部分已压缩”，批量操作只重试尚未完成的文件。绿色状态里的“已压缩%”与媒体详情页同一口径：把该附件包含的所有文件的压缩前大小和节省大小分别求和后相除，旧兼容格式回退文件一起算，原图备份不是被压缩的对象，不参与这项统计。

格式转换是真实替换：生成新文件、更新附件路径与元数据、持久改写文章内已固化的旧图片链接，新格式接管附件引用。新格式以“源文件名.webp”这类双扩展名生成。是否保留旧格式文件由“兼容格式回退”开关决定：开启时旧格式文件原地保留并同样经过压缩，作为前台 `<picture>` 的兼容格式回退；关闭时提交成功后删除源文件，磁盘上只留新格式。

## 功能特性

- **七种格式压缩**：JPEG、PNG、WebP、AVIF、GIF、SVG、JPEG XL。
- **可配置目标格式**：只有 PNG / JPG / GIF 能按设置压缩为 WebP、AVIF 或 JPEG XL；WebP / AVIF / JPEG XL 无论静图动图都只做同格式压缩，不再转换一次。
- **动图默认就压**：GIF、APNG、动画 WebP、动画 AVIF、动画 JXL 整张交给支持动画的成熟工具，插件不拆帧、不修改透明像素、不拼装动画。APNG 同格式只走 oxipng 无损压缩。
- **统一批量入口**：设置页的批量按钮都把任务交给服务器自己跑，按附件 ID 游标逐个处理，不存在固定数量上限。
- **后台批量任务**：任务状态存在选项里，服务器一批接一批地执行（每批限量时长，永远跑不到网关超时线），浏览器只负责显示进度，关掉页面任务照样继续；页面上的进度条是真进度，随时可停止、可只重试失败项。
- **统一状态**：媒体库、批量操作和上传自动处理使用相同的文件级成功记录。
- **全流程上传拦截**：通过 `add_attachment` 登记所有新建附件，第三方代码不生成标准 metadata 时也会在请求结束前处理；剩余待办由后台页面加载和每小时定时任务限量收尾。
- **原图备份**：压缩前可自动备份到 uploads 下的独立目录，同一附件只保留最初那份原图，SVG 同样备份；支持恢复单张或全部原图，也可设置保留天数，到期自动清理。
- **兼容格式回退**：开启后格式转换生成“源文件名.webp”“源文件名.avif”的双扩展名新文件，旧格式文件原地保留并同样经过压缩，前台用 `<picture>` 让不支持新格式的浏览器加载旧格式；回退地址由新格式名去掉最后一段扩展名确定性推导，不查询数据库。关闭后转换不再保留旧格式文件，提交成功后直接删除源文件，新格式完整替换旧格式，前台也只输出新格式。
- **隐私保护**：压缩和格式转换时默认清除 EXIF、GPS 拍摄位置、设备与软件信息，对所有压缩路径生效，并尽量保留 ICC 色彩配置以免偏色。
- **动图保护**：识别 APNG 的 `acTL` 块、WebP 的 `ANMF` 块，绝不让只认第一帧的工具把动图压成静图；APNG 由 oxipng 整只无损优化：PNG 走无损时直接跟着无损压；PNG 走有损时，勾了“APNG 自动无损压缩”才绕开 pngquant 单独无损压，不勾则不压。
- **按路径分组参数**：只有整条工具链完全一致的路径才合并成一节，共 15 节（10 条同格式压缩 + 5 条格式转换）。PNG 与 APNG 都只有 oxipng，共用一组；WebP 与 PNG/JPG 转 WebP 都只有 cwebp，共用一组；而多了解码或播放参数回写步骤的路径（AVIF 同格式、静态 GIF 转 AVIF、动画 GIF 转 WebP 等）各自独立。
- **APNG 独立控制**：APNG 是否转格式由“格式转换”里单独的 APNG 勾选框决定，与 PNG 勾选无关。
- **缩放画质可拉满**：勾选后 WordPress 生成缩略图与缩放图时，除 PNG 外所有格式一律按质量 100 保存，不让 GD / Imagick 先损耗一遍；以后 WordPress 支持新格式也自动覆盖，不用再改插件。
- **参数与工具一一对应**：设置页每一项后面都标着它实际传给哪个工具的哪个参数，范围就是工具本身认可的范围（例如 pngquant 的 `--speed` 是 1-11、avifenc 的 `-s` 是 0-10、FFmpeg 的 `-crf` 是 0-63）。插件不做换算、不折算档位，没列出来的参数一律不传。
- **JPEG 只用 jpegoptim**：有损与无损都交给 jpegoptim，不依赖 jpegli，省掉 PPM 中转也就不会丢元数据。
- **附件级排他锁**：同一附件的压缩、恢复和缩略图操作不会并发互相覆盖。
- **元数据同步**：格式转换后同步 `_wp_attached_file`、附件 metadata、文件大小和 MIME；原本没有完整 metadata 的图片按目标文件的真实尺寸建立，不伪造数据；主文件失败时不会把整个附件错误标记为新格式。
- **内容 URL 持久替换**：转换时把文章正文里已固化的旧地址按实际映射改写为新文件，保留查询参数、锚点、`srcset` 与区块 JSON 的转义写法；恢复时反向替换。
- **缩略图管理**：按尺寸逐个勾选是否生成，并用一个勾选框控制是否自动缩放超尺寸主图；删除未勾选尺寸时可一并清理不符设置的 `-scaled` 缩放图，补生成缺失尺寸时可一并生成或修正缩放图。
- **SVG 安全上传**：清理脚本、事件属性和危险协议，写入失败时终止上传。
- **多语言就绪**：界面文字使用翻译域 `libre-compress`。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| WordPress | 6.4 及以上 |
| PHP | 7.4 及以上 |
| `proc_open()` | 必须可用：所有压缩都以参数数组直接启动命令行工具，不经过命令行解释器（`exec()` 已不再使用） |
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
| jpegoptim | JPEG 有损与无损压缩 |
| pngquant | PNG 有损压缩 |
| oxipng | PNG 无损压缩，APNG 整只无损优化 |
| gifsicle | GIF 同格式压缩 |
| cwebp | 静态 WebP 同格式压缩，以及 PNG/JPG 输出为 WebP |
| gif2webp | 静态与动画 GIF 输出为 WebP（整只 GIF 直读，不拆帧；单帧 GIF 出来即为静图） |
| webpmux | 动画 WebP 信息读取、末帧时长与背景色修正 |
| avifenc | 静态 AVIF 编码（不认 GIF，静态 GIF 转 AVIF 需先用 GD 解成 PNG） |
| avifdec | 静态 AVIF 解码、动画 AVIF 信息读取 |
| cjxl | JPEG XL 编码（同格式压缩、目标格式输出，并直接读 GIF / APNG 产出动画 JXL） |
| ffmpeg | 动画 WebP 同格式重压、APNG 转动画 WebP、完整动画 AVIF 输出 |
| ffprobe | 为 FFmpeg 挑选动画 AVIF 的颜色与透明轨道 |
| oxvg | SVG 同格式压缩（Rust 单文件程序，不需要 Node.js） |

SVG 压缩改用 oxvg（Rust 单文件程序，不再需要 Node.js）：从发布页取 `oxvg.exe` 放进工具目录即可，**Windows x64 请用 v0.0.6 版本**（v0.0.9 的 `oxvg.exe` 是 ARM64，x64 只能自己编译），其他平台可用 `cargo install oxvg`。

Windows 下把 `gif2webp.exe`、`webpmux.exe`、`cjxl.exe` 及对应包内依赖的 `.dll` 放进 `LibreCompress-bin`。

APNG 转 WebP 需要支持 `libwebp_anim` 的 FFmpeg；动画 AVIF 需要支持 `libaom-av1`、透明动画 AVIF 输出的新版 FFmpeg，两者均需支持 `-enc_time_base 1:1000` 和 `-fps_mode passthrough`，`ffmpeg.exe` 和 `ffprobe.exe` 放进同一工具目录。仅找到文件不代表该构建具备所有能力；实际处理失败时会保留原图。

### 同格式压缩依赖

| 格式 | 依赖 |
| --- | --- |
| JPEG 有损 | jpegoptim |
| JPEG 无损 | jpegoptim |
| PNG 有损 | pngquant |
| PNG 无损 | oxipng |
| WebP | cwebp |
| AVIF | avifenc + avifdec |
| GIF | gifsicle |
| SVG | oxvg |
| JPEG XL | cjxl |

PNG 严格按当前有损/无损设置选择工具，不会静默切换压缩模式。

### 格式转换依赖

| 源格式 | WebP | AVIF | JPEG XL |
| --- | --- | --- | --- |
| PNG / JPG | cwebp | avifenc | cjxl |
| 静态 GIF | gif2webp | GD + avifenc | cjxl |
| 动画 GIF | gif2webp + webpmux | ffmpeg | cjxl |
| APNG | ffmpeg + webpmux | ffmpeg | cjxl |

WebP、AVIF、JPEG XL 不作为转换源：它们只做同格式压缩。

静态 GIF 转 WebP、转 JPEG XL 都是把原文件直接交给 gif2webp / cjxl（两者都直读 GIF，透明背景不丢、产物也比先转 PNG 再编码小得多），只有转 AVIF 要先经 GD 解成 PNG（avifenc 不认 GIF）。这里不要改用 cwebp 读 GIF：实测 cwebp 会丢掉 GIF 的透明背景。

动图输出 WebP 时要多一个 webpmux：FFmpeg 与 gif2webp 写出的播放参数有两处不对（末帧时长、背景色），必须由 webpmux 改回原值，缺它就会失败。

### 动图处理

整张动画直接交给以下工具处理。工具在内部读取和编码各帧，插件只调用工具并读取播放参数，不自行拆帧，也不自行整理透明通道。

| 源格式 | 同格式压缩 | 输出 WebP | 输出 AVIF | 输出 JXL |
| --- | --- | --- | --- | --- |
| 动画 GIF | gifsicle | gif2webp | ffmpeg | cjxl |
| APNG | oxipng | ffmpeg | ffmpeg | cjxl |
| 动画 WebP | ffmpeg（webpmux 读帧表并修播放参数） | 不转换 | 不转换 | 不转换 |
| 动画 AVIF | ffmpeg + ffprobe + avifdec | 不转换 | 不转换 | 不转换 |
| 动画 JXL | cjxl | 不转换 | 不转换 | 不转换 |

缺少对应工具时跳过，不自行拆帧。

动画 WebP 同格式重压由 FFmpeg 的 `libwebp_anim` 一条命令完成：它内部解码整只动图、自己计算帧间差异矩形与画布尺寸，所以画布不会被裁小，帧数再多也只是一条命令。写回后由 webpmux 补两处播放参数——`libwebp_anim` 会用平均帧时长推算末帧时长，需要按原图改回来；背景色则一律写成不透明白，也要改回原值。末帧时长那一步会按帧包围盒重算画布，所以对"帧盖不满整张画布"的动图（常见透明留白）只改背景色、不动时长，宁可差几毫秒也不裁画布。FFmpeg 的 WebP 编码器没有 `-exact`，透明区域的 RGB 会被压平，这是相对逐帧 cwebp 唯一的画质退让点。

WebP / AVIF / JPEG XL 无论静图动图都只做同格式压缩；APNG 是否转格式由设置里单独的 APNG 勾选控制，动画 GIF 跟随 GIF 勾选。插件默认不往上传白名单里添加任何图片格式，也不补尺寸元数据；AVIF 从 WordPress 6.5 起原生支持上传，JXL 与其余格式的上传和媒体元数据完全交给 WordPress 自己处理。唯一的例外是设置里手动开启的 SVG 上传。

- APNG 同格式由 oxipng 无损优化：PNG 走无损时直接压；PNG 走有损时，勾了“APNG 自动无损压缩”就绕开 pngquant 单独无损压，不勾则不压，以免破坏动画或产生调色板闪烁。
- AVIF 的颜色和透明部分均由 FFmpeg 内部处理，透明部分始终无损编码，以保留半透明边缘。
- 动图不转换成 GIF，避免丢失半透明背景。

命令只传入完整动画的路径，长度不再随帧数增长。图片仍受服务器内存、工具能力和处理时间限制；失败时保留原图。

### 压缩参数

“压缩工具”页按压缩路径分组：**只有整条工具链完全一致的路径才合并成一节**，共 15 节（10 条同格式压缩 + 5 条格式转换）。同一个编码器但前后还多了解码（`avifdec`、GD）或播放参数回写（`webpmux`、`ffprobe`）的路径各算一条，参数各自独立——所以“AVIF 同格式压缩”“PNG/JPG 转 AVIF”“静态 GIF 转 AVIF”是三节，不是一节。

页面顶部的“路径支持状态”表与参数区一一对应：同样 15 行，一行一条路径，依赖列写的就是那条命令真实调用的全部工具，不多也不少，缺哪个工具会在状态列点名。

| 分组 | 完整工具链 | 覆盖的路径 |
| --- | --- | --- |
| `jpeg` | jpegoptim | JPEG 同格式压缩 |
| `png_lossy` | pngquant | PNG 有损压缩（`png_mode` 决定 PNG 走有损还是无损） |
| `png_lossless` | oxipng | PNG 无损压缩、APNG 动图压缩（`apng_enabled` 决定 PNG 有损模式时 APNG 压不压） |
| `gif` | gifsicle | GIF 同格式压缩（含动画） |
| `svg` | oxvg | SVG 同格式压缩 |
| `webp` | cwebp | WebP 静图同格式压缩、PNG/JPG 转 WebP |
| `webp_anim` | FFmpeg + webpmux | 动画 WebP 同格式压缩、APNG 转 WebP |
| `avif` | avifdec + avifenc | AVIF 静图同格式压缩 |
| `avif_anim` | avifdec + FFmpeg + ffprobe | 动画 AVIF 同格式压缩 |
| `jxl` | cjxl | JPEG XL 同格式压缩（含动画）、PNG/JPG/GIF/APNG 转 JPEG XL |
| `gif_to_webp` | gif2webp | 静态 GIF 转 WebP |
| `gif_to_webp_anim` | gif2webp + webpmux | 动画 GIF 转 WebP |
| `avif_convert` | avifenc | PNG/JPG 转 AVIF |
| `avif_convert_gif` | GD + avifenc | 静态 GIF 转 AVIF |
| `avif_convert_anim` | FFmpeg | 动画 GIF/APNG 转 AVIF |

规则只有一条：**一个输入框对应一个真实的命令行参数，插件不做任何换算**。界面上的范围就是工具自己 `--help` 里写的范围（例如 pngquant `--speed` 是 1–11、avifenc `-s` 是 0–10、FFmpeg `-crf` 是 0–63），你填多少就传多少，不会出现“界面填 0–9、实际折算成 0–6”这种事。

- **没列出来的参数一律不传**，由工具用自己内部的默认值。cwebp 的分区数、噪点整形、滤波强度/锐度、锐利色彩转换都属于这一类，插件不再插手。
- **超出范围只在保存时收到该参数的边界值**，避免把非法数字塞进命令行；合法范围内的值原样保留。
- 设置页、保存校验、命令拼装共用同一份参数表，不会出现界面写一套、实际传另一套。
- 改动只影响之后的压缩，已经压好的图片不会自动重压。

#### JPEG 同格式压缩

全部由 jpegoptim 处理，有损与无损都走它，不再依赖 jpegli。

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | jpegoptim `--max` | 0–100 | 80 |
| 渐进式编码 | jpegoptim `--all-progressive` / `--all-normal` / `--auto-mode` | 自动 / 顺序式 / 渐进式 | 渐进式 |

无损模式下不传 `--max`，jpegoptim 只重排压缩数据，画面像素不变。

#### PNG 有损压缩

PNG 有两条路径：本节的有损（pngquant）与下一节的无损（oxipng），由本节最上面的“PNG 走哪条路径”二选一，两条不会同时执行。

pngquant 的 `--quality` 本身就是“下限 上限”两段，所以界面直接给两个输入框：低于下限就不保存、保留原图，上限决定最多保留多少颜色。

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| PNG 走哪条路径 | pngquant / oxipng 二选一 | 有损 / 无损 | 有损 |
| 有损质量下限 | pngquant `--quality` 下限 | 0–100 | 60 |
| 有损质量上限 | pngquant `--quality` 上限 | 0–100 | 80 |
| pngquant 速度 | pngquant `--speed` | 1–11 | 4 |
| 抖动 | pngquant（关掉时加 `--nofs`） | 开 / 关 | 开 |

下限填得比上限大时保存会自动对调。

#### PNG / APNG 无损压缩

只重排压缩数据，画面一个像素都不变。静态 PNG 在“PNG 走哪条路径”选无损时走这一组；APNG 动图也走这一组，整只交给 oxipng，保得住帧结构与每帧时长。

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| APNG 自动无损压缩 | APNG 走 oxipng | 开 / 关 | 开 |
| oxipng 无损级别 | oxipng `-o` | 0–6 | 2 |
| 优化透明通道 | oxipng `--alpha` | 开 / 关 | 关 |
| Zopfli 极限压缩 | oxipng `--zopfli` | 开 / 关 | 关 |

APNG 什么时候压、什么时候不压：

| 情况 | 结果 |
| --- | --- |
| “PNG 走哪条路径”选无损（勾不勾“APNG 自动无损压缩”都一样） | APNG 做无损压缩 |
| 选有损 + 勾选“APNG 自动无损压缩” | APNG 绕开 pngquant，单独走 oxipng 无损压缩 |
| 选有损 + 不勾“APNG 自动无损压缩” | APNG 不压缩（pngquant 逐帧各挑调色板会把动图压闪） |
| 基本设置里勾了“APNG”转格式 | 走格式转换，跟上面三条无关 |


#### GIF 同格式压缩

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损强度 | gifsicle `--lossy` | 0–1000 | 80 |
| 调色板颜色数 | gifsicle `--colors` | 2–256 | 256 |
| 优化级别 | gifsicle `-O` | 1–3 | 2 |

有损强度是 gifsicle 的原生参数，数值越大文件越小、噪点越多，常用 20–200，填 0 就不加有损参数。

#### WebP 静图同格式压缩

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | cwebp `-q` | 0–100 | 80 |
| 有损压缩方法 | cwebp `-m` | 0–6 | 4 |
| 无损级别 | cwebp `-z` | 0–9 | 6 |
| 透明通道质量 | cwebp `-alpha_q` | 0–100 | 100 |
| 内容预设 | cwebp `-preset` | 默认 / 照片 / 室内人像 / 绘图 / 图标 / 文字 | 默认 |
| 保留透明区域颜色 | cwebp `-exact` | 开 / 关 | 关 |

`-preset` 会重置质量、方法等参数，所以它永远排在命令行最前面，后面的显式值优先级更高。

#### WebP 动图同格式压缩

动画 WebP 整只交给 FFmpeg 的 `libwebp_anim`，只有两项，语义与范围都和 FFmpeg 完全一致，不做任何折算。画布尺寸、帧时长、循环次数由 FFmpeg 从原图读取。

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | FFmpeg `-quality` | 0–100 | 80 |
| 压缩方法 | FFmpeg `-compression_level` | 0–6 | 4 |

`-compression_level` 就是 libwebp 的 method，0 最快、6 最慢最小，有损与无损都生效。动图这条路上没有 `-z`，所以这里也不会出现“0–9 折成 0–6”的换算。

#### AVIF 静图同格式压缩

静图先用 avifdec 解成 PNG，再交给 avifenc。

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | avifenc `-q` | 0–100 | 80 |
| 透明通道质量 | avifenc `--qalpha` | 0–100 | 80 |
| 速度 | avifenc `-s` | 0–10 | 6 |
| 色度抽样 | avifenc `-y` | 自动 / 4:4:4 / 4:2:2 / 4:2:0 | 自动 |
| 位深 | avifenc `-d` | 8 / 10 / 12 | 8 |
| 并行线程数 | avifenc `-j` | 1–256 | 4 |

选 4:2:0 时会带上 avifenc 自己的 `--sharpyuv`。位深选 8 位时不传 `-d`。

#### AVIF 动图同格式压缩

动画 AVIF 整只交给 FFmpeg 的 `libaom-av1`，用的是 libaom 的原始参数，与上面的 avifenc 那组互不相干。

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| CRF（质量） | FFmpeg `-crf` | 0–63 | 20 |
| 速度 | FFmpeg `-cpu-used` | 0–8 | 6 |
| 编码线程数 | FFmpeg `-threads` | 1–256 | 4 |
| 色彩抽样 | FFmpeg `-pix_fmt:v:0` | 自动 / 4:4:4 / 4:2:2 / 4:2:0 | 自动 |

CRF 是 libaom 自己的刻度：越小画质越好、体积越大，0 接近无损，63 最差，常用 20–30。无损模式下改传 `-lossless 1`，不再传 CRF。

色彩抽样只在有损模式下传入，映射到 `yuv444p` / `yuv422p` / `yuv420p`，只作用于颜色轨道（`-pix_fmt:v:0`），透明轨道仍然按 gray 编码；选“自动”则完全不传，由编码器按输入决定。

#### JPEG XL 同格式压缩（静图与动图）

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | cjxl `-q` | 0–100 | 80 |
| 压缩级别 | cjxl `-e` | 1–10 | 7 |

无损模式下改传 `-d 0`。JPEG 输入统一带 `--lossless_jpeg=0`，否则 cjxl 会走 JPEG 无损重构、与质量参数冲突直接失败。

#### SVG 同格式压缩

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 优化预设 | oxvg `optimise -e` | 常规 / 保守 | 常规 |

#### 格式转换参数

12 条转换路径各自独立成组，每组都有自己的完整一份参数：PNG/JPG 转 WebP 的 `-q` 和 PNG/JPG 转 AVIF 的 `-q` 只是长得像，改一个不会动另一个；动画 GIF 转 WebP 与 APNG 转 WebP 用的甚至不是同一个工具，参数也各自一套。

| 分组 | 谁在处理 | 输入 |
| --- | --- | --- |
| PNG / JPG 转 WebP | cwebp | PNG、JPG |
| PNG / JPG 转 AVIF | avifenc | PNG、JPG |
| PNG / JPG 转 JPEG XL | cjxl | PNG、JPG |
| 静态 GIF 转 WebP | gif2webp | 静态 GIF |
| 静态 GIF 转 AVIF | GD 解成 PNG，再走 avifenc | 静态 GIF |
| 静态 GIF 转 JPEG XL | cjxl | 静态 GIF |
| 动画 GIF 转 WebP | gif2webp | 动画 GIF |
| 动画 GIF 转 AVIF | FFmpeg `libaom-av1` | 动画 GIF |
| 动画 GIF 转 JPEG XL | cjxl | 动画 GIF |
| APNG 转 WebP | FFmpeg `libwebp_anim` | APNG |
| APNG 转 AVIF | FFmpeg `libaom-av1` | APNG |
| APNG 转 JPEG XL | cjxl | APNG |

下面按工具列参数，**每个分组都各自保存一份**，互不影响。

**cwebp（PNG / JPG 转 WebP）**

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | cwebp `-q` | 0–100 | 80 |
| 有损压缩方法 | cwebp `-m` | 0–6 | 4 |
| 无损级别 | cwebp `-z` | 0–9 | 6 |
| 透明通道质量 | cwebp `-alpha_q` | 0–100 | 100 |
| 内容预设 | cwebp `-preset` | 默认 / 照片 / 室内人像 / 绘图 / 图标 / 文字 | 默认 |
| 保留透明区域颜色 | cwebp `-exact` | 开 / 关 | 关 |

**gif2webp（静态 GIF 转 WebP / 动画 GIF 转 WebP，两组各一份）**

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 启用有损 | gif2webp `-lossy` | 开 / 关 | 开 |
| 有损质量 | gif2webp `-q` | 0–100 | 80 |
| 压缩方法 | gif2webp `-m` | 0–6 | 4 |

gif2webp 默认就是无损的，`-lossy` 是它自己的一个开关，所以这里给的是勾选框而不是“有损/无损”二选一：不勾就只重排数据，勾了才按质量重新量化。

**FFmpeg `libwebp_anim`（APNG 转 WebP）**

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | FFmpeg `-quality` | 0–100 | 80 |
| 压缩方法 | FFmpeg `-compression_level` | 0–6 | 4 |

**avifenc（PNG / JPG 转 AVIF、静态 GIF 转 AVIF，两组各一份）**

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | avifenc `-q` | 0–100 | 80 |
| 透明通道质量 | avifenc `--qalpha` | 0–100 | 80 |
| 速度 | avifenc `-s` | 0–10 | 6 |
| 色彩抽样 | avifenc `-y` | 自动 / 4:4:4 / 4:2:2 / 4:2:0 | 自动 |
| 位深 | avifenc `-d` | 8 / 10 / 12 | 8 |
| 并行线程数 | avifenc `-j` | 1–256 | 4 |

**FFmpeg `libaom-av1`（动画 GIF 转 AVIF、APNG 转 AVIF，两组各一份）**

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| CRF（质量） | FFmpeg `-crf` | 0–63 | 20 |
| 速度 | FFmpeg `-cpu-used` | 0–8 | 6 |
| 编码线程数 | FFmpeg `-threads` | 1–256 | 4 |
| 色彩抽样 | FFmpeg `-pix_fmt:v:0` | 自动 / 4:4:4 / 4:2:2 / 4:2:0 | 自动 |

**cjxl（PNG / JPG、静态 GIF、动画 GIF、APNG 转 JPEG XL，四组各一份）**

| 参数 | 传给工具 | 范围 | 默认 |
| --- | --- | --- | --- |
| 有损质量 | cjxl `-q` | 0–100 | 80 |
| 压缩级别 | cjxl `-e` | 1–10 | 7 |

各工具没有的开关不会硬塞：cwebp 的分区数、噪点整形、滤波强度/锐度、锐利色彩转换一律不传；FFmpeg 的 `-preset` 一旦传入会把 `lossless` 和 `compression_level` 一起重置，所以动图不传它。

### 关于色彩抽样（4:4:4 / 4:2:0）

只有真正存在这个开关的路径才会出现“色彩抽样”：

- **三条 avifenc 路径**（AVIF 静图、PNG/JPG→AVIF、静态 GIF→AVIF）走 `avifenc -y`，可选 4:4:4 / 4:2:2 / 4:2:0，4:2:0 时额外带 `--sharpyuv`；
- **三条动画 AVIF 路径**（AVIF 动图同格式、动画 GIF→AVIF、APNG→AVIF）走 FFmpeg 的 `-pix_fmt:v:0`，可选 yuv444p / yuv422p / yuv420p；
- 其余路径（jpegoptim、pngquant、oxipng、gifsicle、cwebp、gif2webp、libwebp_anim、cjxl）本身就**没有**这个参数：jpegoptim 不重新编码，PNG/GIF 是调色板或无损格式，libwebp 只接受 4:2:0，cjxl 也没有色度抽样开关。插件不会为了“看起来能设”造一个假参数。

选 JPEG XL 作为目标格式时建议同时开启"兼容格式回退"：浏览器对动画 JXL 的支持远不如 AVIF/WebP，保留旧格式回退才能让前台正常显示。

### 压缩结果规则

- 只有结果文件存在、可识别且体积严格小于源文件时才更换格式或替换文件内容。
- 结果没有变小：保留原图并写入 0% 的完成记录；缺工具或编码失败不写完成记录，仍保留为待处理。
- 转换结果的文件名按“源文件名.目标格式”的双扩展名规则生成，如 `photo.jpg` 转 WebP 得到 `photo.jpg.webp`；只有当这个目标名已被占用（上次转换中断的残留或同名文件）时，才先给源文件追加 `-1`、`-2` 改名再转换。源文件与目标文件主名不会互相撞名，前台的旧格式回退由新格式名去掉最后一段扩展名确定性推导，不会探到不属于本附件的图片。
- 同格式压缩和格式转换都保存当前文件路径、原始大小、压缩后大小、工具和状态。
- 压缩记录与当前文件路径不一致时视为未完成，允许重新处理。
- 附件内任意尺寸失败都不会把附件级 MIME 改成目标格式；只有主文件成功才更新附件级 MIME。
- 格式转换的提交顺序固定为：附件路径与元数据 → MIME → 文章链接。旧格式文件是否保留由兼容格式回退开关决定：开启时原地留作回退，提交不删除任何源文件；关闭时提交成功后删除源文件，新格式完整替换。任一步失败都会把引用退回源文件、把记录写成失败，下次执行凭映射继续提交而不重复编码。删除源文件只发生在引用和正文链接都已指向新格式之后，删除失败也不阻断提交，残留文件仍可由删除入口清理。
- 恢复原图先把主图像备份写回原路径并校验，再删除缩放图、缩略图与格式转换结果（绝不删到原图头上），把元数据、附件路径和 MIME 改回原图，然后按当前勾选设置重新生成缩略图（勾选了自动缩放且原图超过阈值时，`-scaled` 缩放图会重新生成并接管主文件），最后按“压缩前指向哪个尺寸就还原到哪个尺寸”的规则改写正文链接：目标文件已经重新生成好的原样还原；原来指向主图的，恢复时关闭了自动缩放就改到当前主图（也就是原图本身）；原来指向缩略图而该尺寸这次没勾选、没生成出来的，改到像素面积最接近的现有尺寸，平手选更大的；一个现有尺寸都没有时才保持原样。随后清理备份索引、压缩记录和转换映射；任一阶段失败都可重试续跑。
- 主图像备份统一在压缩这个附件之前完成（只备份主图），因此早于任何改名与转换，备份下来的是没被动过的原图；单文件处理顺序为：判断目标名是否被占用并改名 → 转换生成新格式 → 开启兼容格式回退时单独压缩旧格式。旧格式必须在转换之后再压缩，否则压缩损失会叠加到转换质量上。
- 批量操作只重试尚未成功的文件，不会再次压缩已经成功的尺寸。
- APNG 的后缀和 MIME 都是普通 PNG，只能靠 `acTL` 块识别。pngquant、cwebp、avifenc 都只读第一帧，一旦拿去压 APNG 就会把动图毁成静图，所以 APNG 只在 PNG 无损、或 PNG 有损但勾了“APNG 自动无损压缩”时由 oxipng 整只无损压缩；两者都不满足时 APNG 原样保留、不写记录。

### 元数据处理

“压缩时删除图片元数据”默认开启，对**全部压缩路径**逐一实测过：勾选后产物里都找不到写入素材的隐私标记（EXIF / XMP / 注释 / 文本块等），ICC 色彩配置在工具支持时尽量保留以免偏色。各格式的实际行为：

| 格式 | 勾选删除（默认） | 取消勾选 |
| --- | --- | --- |
| JPEG | `jpegoptim --strip-exif --strip-xmp --strip-iptc --strip-com`，EXIF/XMP/IPTC/注释清除，ICC 保留 | jpegoptim 用 `--strip-none` 完整保留 |
| PNG | `oxipng --strip=safe` 清除 eXIf 与文本块；oxipng 会把标准 sRGB 配置换成等效的 `sRGB` 块，色彩声明不丢 | `--strip=none` 保留 eXIf；pngquant 有损路径仍会自行丢弃文本块 |
| WebP | cwebp 使用 `-metadata icc`，只保留 ICC | 使用 `-metadata all` 尽量保留 |
| AVIF | `avifenc --ignore-exif --ignore-xmp`，ICC 保留 | avifenc 默认会把输入图的 EXIF/XMP 原样带进 AVIF |
| 动画 WebP | 逐帧重压后由 webpmux 重新组装，EXIF/XMP 不进入结果；GIF 转 WebP 只复制 ICC；APNG 转 WebP 由 FFmpeg 按设置处理 | GIF 转换尽量保留工具支持的输入配置；APNG 转换按设置复制 |
| 动画 AVIF | FFmpeg 使用 `-map_metadata -1` 清除元数据 | 尝试复制工具支持的元数据，不能保证保留 EXIF/XMP |
| GIF | `gifsicle --no-comments --no-names --no-extensions`，注释、帧名、XMP 等应用扩展块全部清除；循环扩展与透明索引保留 | 原样保留全部扩展块 |
| SVG | oxvg 预设本身会删 `<metadata>` 与注释；勾选删除时插件再按文本清一遍 `<metadata>` 与注释，不依赖 oxvg 版本行为 | oxvg 预设的行为，插件不再额外清理 |

智能备份只保存主图像（未缩放原图优先）这一份压缩前的逐字节副本，不再逐张备份缩略图，以此节省磁盘；备份只服务于“恢复原图”，恢复时会按当前设置重新生成缩略图，正文链接仍指向原来的尺寸（原来用缩略图还是缩略图，原来用原图还是原图）。目录已禁止直接访问，前台回退用的是媒体库里同样压缩过的旧格式文件，两者都不依赖备份目录对外提供图片。已经压缩过的图片改设置后不会自动重做，需要先清除对应的压缩记录。

兼容格式回退文件与压缩后的图片走同一套元数据处理：JPEG 回退由 jpegoptim 处理，PNG 回退由 oxipng/pngquant 清理，WebP 回退由 cwebp 重新编码。

### 缩放画质

WordPress 生成缩略图与 `-scaled` 缩放图时会用 GD / Imagick 把图片重新编码一遍（JPG 默认质量 82）。这是压缩工具之前的一次损耗，工具再怎么调也补不回来。

勾选“调整图片尺寸时把保存质量拉满”后，除 PNG 之外的所有格式都按质量 100 保存：判断条件是“输出 MIME 不是 `image/png`”，不写死格式列表，所以以后 WordPress 支持 JPEG XL 之类新格式也会自动覆盖，不用回来改插件。PNG 在 GD / Imagick 里本来就是无损编码，质量参数会被换算成压缩级别，改它只会让文件变大，因此原样放行。

JPG 是有损格式，质量 100 只是肉眼看不出差别，不是数学意义上的无损。要真正做到零损失，得让缩放输出 PNG 当中间文件再编码成目标格式，那是另一套缩略图生成流程，插件目前没做。

### 批量操作

设置页的 7 个批量按钮全部走同一套后台任务：

- **批量压缩未压缩的图片**
- **清除压缩记录与原图备份**
- **恢复所有原图备份**
- **删除所有原图备份**
- **删除所有兼容格式回退**
- **删除未勾选尺寸的缩略图**
- **补生成缺失尺寸的缩略图**

点下按钮后只会登记一条任务，真正干活的是服务器自己：

- 服务器按附件 ID 游标一个一个处理，每批只干固定时长（默认 20 秒）就收手，把接力棒交给下一个请求，所以单个请求永远不会跑到 Nginx、宝塔、CDN 的超时线；
- 任务的类型、总数、已处理、成功 / 跳过 / 失败、失败附件 ID、游标和当前文件都写在选项 `libre_compress_task` 里，页面只是每 2 秒来看一眼，**关掉浏览器任务照样继续，重新打开设置页还能接着看进度**；
- 进度条的分母来自数据库计数或图片总数统计，所以是真的百分比，不是转圈动画；面板上另有“已处理 / 总数”“成功 / 跳过 / 失败”“当前文件”“已用时”；
- 面板上的按钮：**停止任务**（随时叫停，已处理的部分保留）、**继续执行**（接力棒掉了时手动补一棒）、**重试失败的项目**（只把失败名单里的附件重跑一遍）、**完成**（关掉结果面板，服务端会记住，刷新页面也不会再冒出来；任务还在执行时不给关，要先停止）；
- 接力棒发不出去时（个别服务器禁止网站访问自己），页面轮询会就地补一棒，另有每 2 分钟一次的定时兜底，只要站点有人访问就会接着跑；
- 失败原因按类型汇总在面板里，失败附件 ID 一并列出（最多显示前 10 个，重试最多带 200 个）。

各按钮的处理规则：

- 批量压缩：只处理仍有未压缩文件的附件，已压缩的图顺路看一眼就跳过，所有成功路径统一计入“已压缩”；
- 清除记录：连同备份文件、备份索引和转换映射一起清除，失败项会列出附件 ID，再次执行可继续清除；
- 恢复原图：备份文件已丢失又无需收尾的附件会顺手清掉残留索引；
- 删除所有原图备份：按附件逐个删除备份文件与索引；
- 删除所有兼容格式回退：删除仍有对应新格式文件的旧格式文件，并把文章里指向它们的图片链接改到新格式；
- 删除未勾选尺寸的缩略图：删掉这些尺寸的文件、清掉元数据与压缩记录，并把文章里指向它们的链接（含 srcset）改到像素面积最接近的现有尺寸（原图也一起比面积，它差得最远、排在最后），平手时选更大的；同时关闭了自动缩放或缩放图与阈值不符时，删掉 `-scaled` 缩放图让主图退回原图；
- 补生成缺失尺寸的缩略图：只为已勾选但文件缺失的尺寸生成，不重建已存在的缩略图，也不改动纯缩略图的文章链接；开启自动缩放且主图超阈值却缺少缩放图或缩放图与阈值不符时，会一并生成或修正 `-scaled` 缩放图并改写主文件链接。

## 安全与可靠性

- AJAX 接口验证 nonce、管理员权限、附件类型和附件 ID。
- 用户输入不直接提供文件路径；服务端始终根据附件 ID 和元数据重新解析文件。
- 文件路径规范化后必须位于 uploads 目录边界内。
- 备份、压缩、格式转换、恢复和缩略图操作使用附件级排他锁。
- 统一处理器生成的临时文件和回滚文件使用唯一名称。
- 压缩结果和恢复映射必须成功写入后才提交替换；元数据与映射写入都以读回结果为准，避免 WordPress 在值未变化时返回 false 被误判为失败。
- 备份索引中的路径必须落在备份目录内才会被删除，指向目录外的记录一律拒绝，防止篡改记录删掉现用图片。
- 原图备份到期清理由每日定时任务执行，到期判断在数据库内用 `NOW()` 完成（WordPress 把 PHP 时区强制设为 UTC，用 PHP 时间戳比较会让保留期偏移数小时）；同一附件的所有备份都超期才整批删除，避免恢复成一半新一半旧；单轮限量 200 个附件，仍有积压时 5 分钟后再排一次，锁被占用或索引异常的附件留到下次重试。
- 正文链接写入采用比较原内容后写入（compare-and-swap）并在失败时重读重试一次，不覆盖并发编辑。
- 附件永久删除时同步清理压缩记录、备份、转换映射、恢复中间状态和媒体库里的旧格式回退文件；旧格式文件不写进附件元数据，WordPress 不会带走它们，只能由插件按映射删除。
- 备份采用随机文件名，目录内放置 Apache、IIS 的完全拒绝访问规则和静默 index.php，任何请求都拿不到备份文件。
- SVG 清理会拒绝 DOCTYPE/ENTITY、危险元素、事件属性和脚本协议。

## 数据存储

| 数据 | 位置 |
| --- | --- |
| 统一压缩记录 | `{表前缀}libre_compress_records` |
| 备份记录 | `{表前缀}libre_compress_backups` |
| 格式转换恢复映射 | 附件 post meta `_libre_compress_output` |
| 恢复收尾状态 | 附件 post meta `_libre_compress_restore_ready` |
| 上传待处理标记 | 附件 post meta `_libre_compress_pending` |
| 原图备份文件 | `/wp-content/uploads/libre-compress-backups/` |
| 兼容格式回退文件 | 与新格式文件同目录，名为新格式名去掉最后一段扩展名，如 `example.jpg` 与 `example.jpg.webp` |
| 批量任务状态 | 选项 `libre_compress_task`，只保留最近一次任务，关闭浏览器后仍是它 |
| 批量任务兜底 | 定时任务 `libre_compress_task_watchdog_event`，任务执行期间每 2 分钟一次，结束后自动停排 |
| 备份到期清理任务 | 定时任务 `libre_compress_backup_prune_event`，每天一次 |
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
│   ├── class-task.php              # 后台批量任务引擎
│   ├── class-compressor.php        # 同格式压缩底层处理
│   ├── class-converter.php         # 格式转换底层处理
│   ├── class-backup.php            # 原图备份和恢复
│   ├── class-compatible-fallback.php # 前台兼容格式回退
│   ├── class-media-library.php     # 媒体库状态和 AJAX
│   ├── class-thumbnail-manager.php # 缩略图管理
│   ├── class-settings.php          # 设置页面
│   └── class-database.php          # 数据访问
├── assets/js/admin.js
└── languages/libre-compress.pot
```

## 接口

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
