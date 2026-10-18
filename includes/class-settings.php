<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Libre_Compress_Settings {

    /**
     * 原图备份永久保留的取值
     */
    const RETENTION_PERMANENT = -1;

    /**
     * 大图缩放阈值：与 WordPress 默认值保持一致
     */
    const IMAGE_SIZE_THRESHOLD_DEFAULT = 2560;

    /**
     * 大图缩放阈值上限，防止误填夸张数值
     */
    const IMAGE_SIZE_THRESHOLD_MAX = 20000;

    private $current_tab = 'general';

    /* ---------------------------------------------------------------------
     * 参数总表
     *
     * 设置页渲染、保存校验、工具拼命令全部只认这一份表：
     * 一处改范围，界面、校验、实际传参同时生效，不会再出现界面写 0-100、
     * 工具只认 0-80 这种两边不一致的情况。
     * ------------------------------------------------------------------- */

    /**
     * 压缩模式参数（各路径共用同一套文案）
     *
     * @return array
     */
    private static function mode_param(): array {
        return array(
            'type'    => 'mode',
            'label'   => __( '压缩模式', 'libre-compress' ),
            'flag'    => '',
            'default' => 'lossy',
            'desc'    => __( '有损会丢掉部分画面细节来换更小体积；无损只重排压缩数据，画面一个像素都不变。', 'libre-compress' ),
        );
    }

    /**
     * 所有压缩路径的参数定义
     *
     * 每个参数都写清楚：界面上叫什么、实际传给工具的哪个参数、取值范围、默认值。
     * type 取值：
     * - int    整数，必须有 min / max
     * - choice 枚举，必须有 choices
     * - bool   开关
     * - mode   有损 / 无损二选一
     *
     * @return array
     */
    public static function param_groups(): array {
        static $groups = null;

        if ( null !== $groups ) {
            return $groups;
        }

        $groups = array(
            'jpeg'             => array(
                'title'  => __( 'JPEG 同格式压缩', 'libre-compress' ),
                'note'   => __( '有损优先用 jpegli：cjpegli 读不了 JPEG，所以先由 djpegli 解成 PPM，再交给 cjpegli 重新编码。没装 jpegli、或这里强制用 jpegoptim 时走 jpegoptim。无损压缩始终由 jpegoptim 负责。', 'libre-compress' ),
                'params' => array(
                    'jpeg_encoder'      => array(
                        'type'    => 'choice',
                        'label'   => __( '有损编码器', 'libre-compress' ),
                        'flag'    => 'cjpegli / jpegoptim',
                        'choices' => array(
                            'auto'      => __( '自动（装了 jpegli 就用 jpegli）', 'libre-compress' ),
                            'cjpegli'   => __( '只用 jpegli', 'libre-compress' ),
                            'jpegoptim' => __( '只用 jpegoptim', 'libre-compress' ),
                        ),
                        'default' => 'auto',
                        'desc'    => __( 'jpegli 需要 cjpegli 和 djpegli 两个文件同时存在。jpegli 解码时会丢掉 EXIF、ICC 等元数据；要保留元数据请选 jpegoptim。', 'libre-compress' ),
                    ),
                    'jpeg_mode'         => self::mode_param(),
                    'jpeg_quality'      => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'cjpegli -q / jpegoptim --max',
                        'min'     => 1,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '只有有损压缩有效。jpegli 官方建议 68-96，80 左右肉眼基本看不出差别。', 'libre-compress' ),
                    ),
                    'jpeg_chroma'       => array(
                        'type'    => 'choice',
                        'label'   => __( '色度抽样', 'libre-compress' ),
                        'flag'    => 'cjpegli --chroma_subsampling',
                        'choices' => array(
                            'auto' => __( '自动（交给 jpegli 决定）', 'libre-compress' ),
                            '444'  => __( '4:4:4（色彩最准，文件最大）', 'libre-compress' ),
                            '440'  => __( '4:4:0（少见的纵向抽样）', 'libre-compress' ),
                            '422'  => __( '4:2:2（横向减半）', 'libre-compress' ),
                            '420'  => __( '4:2:0（最省体积，彩色边缘略糊）', 'libre-compress' ),
                        ),
                        'default' => 'auto',
                        'desc'    => __( '只有 jpegli 有损压缩有效，jpegoptim 没有这个开关。', 'libre-compress' ),
                    ),
                    'jpeg_progressive'  => array(
                        'type'    => 'choice',
                        'label'   => __( '渐进式编码', 'libre-compress' ),
                        'flag'    => 'cjpegli -p / jpegoptim --all-progressive',
                        'choices' => array(
                            'auto'        => __( '自动（让 jpegoptim 挑更小的，jpegli 用默认 2 级）', 'libre-compress' ),
                            'normal'      => __( '顺序式（边下边显示，整张出现）', 'libre-compress' ),
                            'progressive' => __( '渐进式（先糊后清，首屏更快）', 'libre-compress' ),
                        ),
                        'default' => 'progressive',
                        'desc'    => __( '渐进式通常体积更小、加载观感更好；个别老软件不认渐进式就选顺序式。', 'libre-compress' ),
                    ),
                ),
            ),
            'png'              => array(
                'title'  => __( 'PNG / APNG 同格式压缩', 'libre-compress' ),
                'note'   => __( '有损走 pngquant，无损走 oxipng。APNG 只能无损，检测到动画会自动改走 oxipng。', 'libre-compress' ),
                'params' => array(
                    'png_mode'          => self::mode_param(),
                    'png_quality_min'   => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量下限', 'libre-compress' ),
                        'flag'    => 'pngquant --quality 下限',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 60,
                        'desc'    => __( '低于这个质量就不保存、直接保留原图，用来防止压得太糊。0 表示不设下限。', 'libre-compress' ),
                    ),
                    'png_quality_max'   => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量上限', 'libre-compress' ),
                        'flag'    => 'pngquant --quality 上限',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '追求的最高质量，凑不到时会自动减少颜色数。下限大于上限时会自动对调。', 'libre-compress' ),
                    ),
                    'pngquant_speed'    => array(
                        'type'    => 'int',
                        'label'   => __( 'pngquant 速度', 'libre-compress' ),
                        'flag'    => 'pngquant --speed',
                        'min'     => 1,
                        'max'     => 11,
                        'default' => 4,
                        'desc'    => __( '数值越大越快越省 CPU，画质越粗糙；1 最慢最细，4 是 pngquant 默认值。', 'libre-compress' ),
                    ),
                    'pngquant_dither'   => array(
                        'type'    => 'bool',
                        'label'   => __( '抖动', 'libre-compress' ),
                        'flag'    => 'pngquant（关掉时加 --nofs）',
                        'default' => true,
                        'desc'    => __( '勾选：减少颜色后用抖动过渡，照片类渐变更自然；取消：色块更干净、体积更小，适合扁平图标。', 'libre-compress' ),
                    ),
                    'oxipng_level'      => array(
                        'type'    => 'int',
                        'label'   => __( 'oxipng 无损级别', 'libre-compress' ),
                        'flag'    => 'oxipng -o',
                        'min'     => 0,
                        'max'     => 6,
                        'default' => 2,
                        'desc'    => __( '数值越大压得越小、越慢（6 非常慢）。2 是 oxipng 默认值，日常够用。', 'libre-compress' ),
                    ),
                    'oxipng_alpha'      => array(
                        'type'    => 'bool',
                        'label'   => __( '优化透明通道', 'libre-compress' ),
                        'flag'    => 'oxipng -a',
                        'default' => false,
                        'desc'    => __( '把完全透明的像素颜色改写成可压缩的值，带透明的大图能再小一截。只在无损压缩时有用。', 'libre-compress' ),
                    ),
                    'oxipng_zopfli'     => array(
                        'type'    => 'bool',
                        'label'   => __( 'Zopfli 极限压缩', 'libre-compress' ),
                        'flag'    => 'oxipng --zopfli',
                        'default' => false,
                        'desc'    => __( '更慢更狠的压缩算法，通常还能再省几个百分点。大图会很慢，容易把服务器拖到超时。', 'libre-compress' ),
                    ),
                    'apng_lossless'     => array(
                        'type'    => 'bool',
                        'label'   => __( 'APNG 自动无损压缩', 'libre-compress' ),
                        'flag'    => 'APNG 改走 oxipng',
                        'default' => true,
                        'desc'    => __( '有损 PNG 靠 pngquant 逐帧各自挑调色板，APNG 这样压会闪，所以检测到 APNG 一律改用 oxipng 只做无损。取消勾选则 PNG 为有损时 APNG 原样不压（PNG 本身设为无损时与本项无关）。', 'libre-compress' ),
                    ),
                ),
            ),
            'gif'              => array(
                'title'  => __( 'GIF 同格式压缩', 'libre-compress' ),
                'note'   => __( '整只 GIF（含动画）交给 gifsicle，插件不拆帧、不动透明像素。', 'libre-compress' ),
                'params' => array(
                    'gif_mode'        => self::mode_param(),
                    'gifsicle_lossy'  => array(
                        'type'    => 'int',
                        'label'   => __( '有损强度', 'libre-compress' ),
                        'flag'    => 'gifsicle --lossy',
                        'min'     => 0,
                        'max'     => 1000,
                        'default' => 80,
                        'desc'    => __( '直接对应 gifsicle 的 --lossy，数值越大文件越小、噪点越多，常用 20-200；填 0 表示不加有损参数。只有有损模式有效。', 'libre-compress' ),
                    ),
                    'gif_colors'      => array(
                        'type'    => 'int',
                        'label'   => __( '调色板颜色数', 'libre-compress' ),
                        'flag'    => 'gifsicle --colors',
                        'min'     => 2,
                        'max'     => 256,
                        'default' => 256,
                        'desc'    => __( 'GIF 最多 256 色。256 等于不缩色；调低后体积更小但会掉色，图标类 64-128 往往够用。', 'libre-compress' ),
                    ),
                    'gifsicle_level'  => array(
                        'type'    => 'int',
                        'label'   => __( '优化级别', 'libre-compress' ),
                        'flag'    => 'gifsicle -O',
                        'min'     => 1,
                        'max'     => 3,
                        'default' => 2,
                        'desc'    => __( '3 会多存一张透明度索引表，能再小一点；2 是官方推荐的平衡档。', 'libre-compress' ),
                    ),
                ),
            ),
            'webp'             => array(
                'title'  => __( 'WebP 同格式压缩（静图与动图）', 'libre-compress' ),
                'note'   => __( '静图用 cwebp，动图用 FFmpeg 的 libwebp_anim。动图只认质量、方法和无损级别三项，其余开关只对静图生效。', 'libre-compress' ),
                'params' => array(
                    'webp_mode'            => self::mode_param(),
                    'webp_quality'         => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'cwebp -q / FFmpeg -quality',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '0 最省体积、100 最好。照片一般 75-85，截图和图标可以再低一些。', 'libre-compress' ),
                    ),
                    'webp_method'          => array(
                        'type'    => 'int',
                        'label'   => __( '有损压缩方法', 'libre-compress' ),
                        'flag'    => 'cwebp -m / FFmpeg -compression_level',
                        'min'     => 0,
                        'max'     => 6,
                        'default' => 4,
                        'desc'    => __( '0 最快、6 最慢但最小。方法只影响压缩耗时和体积，不影响画质。', 'libre-compress' ),
                    ),
                    'webp_lossless_level'  => array(
                        'type'    => 'int',
                        'label'   => __( '无损级别', 'libre-compress' ),
                        'flag'    => 'cwebp -z',
                        'min'     => 0,
                        'max'     => 9,
                        'default' => 6,
                        'desc'    => __( '只有无损模式有效，0 最快、9 最小。动图按比例换算成 FFmpeg 的 0-6 档。', 'libre-compress' ),
                    ),
                    'webp_alpha_quality'   => array(
                        'type'    => 'int',
                        'label'   => __( '透明通道质量', 'libre-compress' ),
                        'flag'    => 'cwebp -alpha_q',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 100,
                        'desc'    => __( '100 表示透明通道无损。动图由 FFmpeg 编码，没有这个开关。', 'libre-compress' ),
                    ),
                    'webp_preset'          => array(
                        'type'    => 'choice',
                        'label'   => __( '内容预设', 'libre-compress' ),
                        'flag'    => 'cwebp -preset',
                        'choices' => array(
                            'default' => __( '默认', 'libre-compress' ),
                            'photo'   => __( '照片', 'libre-compress' ),
                            'picture' => __( '室内人像', 'libre-compress' ),
                            'drawing' => __( '绘图 / 线稿', 'libre-compress' ),
                            'icon'    => __( '图标 / 小彩图', 'libre-compress' ),
                            'text'    => __( '文字截图', 'libre-compress' ),
                        ),
                        'default' => 'default',
                        'desc'    => __( '按内容类型预调滤镜与分区参数。预设会覆盖方法和质量，所以它永远排在这些参数前面；下面高级参数里手填的值优先级更高。', 'libre-compress' ),
                    ),
                    'webp_exact'           => array(
                        'type'    => 'bool',
                        'label'   => __( '保留透明区域颜色', 'libre-compress' ),
                        'flag'    => 'cwebp -exact',
                        'default' => false,
                        'desc'    => __( '默认会把全透明像素的 RGB 抹平。透明图以后还要再编辑就勾上，代价是体积约多 16%。', 'libre-compress' ),
                    ),
                    'webp_segments'        => array(
                        'type'      => 'int',
                        'label'     => __( '分区数量', 'libre-compress' ),
                        'flag'      => 'cwebp -segments',
                        'min'       => 1,
                        'max'       => 4,
                        'default'   => 4,
                        'advanced'  => true,
                        'desc'      => __( '把图切成几块分别量化，4 最均匀也最慢。', 'libre-compress' ),
                    ),
                    'webp_sns'             => array(
                        'type'      => 'int',
                        'label'     => __( '空间噪点整形', 'libre-compress' ),
                        'flag'      => 'cwebp -sns',
                        'min'       => 0,
                        'max'       => 100,
                        'default'   => 50,
                        'advanced'  => true,
                        'desc'      => __( '把量化误差藏进纹理里，让人眼不易察觉。0 关闭，100 最强。', 'libre-compress' ),
                    ),
                    'webp_filter'          => array(
                        'type'      => 'int',
                        'label'     => __( '滤波强度', 'libre-compress' ),
                        'flag'      => 'cwebp -f',
                        'min'       => 0,
                        'max'       => 100,
                        'default'   => 60,
                        'advanced'  => true,
                        'desc'      => __( '编码前先平滑画面，0 关闭、100 最强。纹理丰富的照片不要调太高。', 'libre-compress' ),
                    ),
                    'webp_sharpness'       => array(
                        'type'      => 'int',
                        'label'     => __( '滤波锐度', 'libre-compress' ),
                        'flag'      => 'cwebp -sharpness',
                        'min'       => 0,
                        'max'       => 7,
                        'default'   => 0,
                        'advanced'  => true,
                        'desc'      => __( '0 最锐、7 最柔。线条和文字截图建议留在 0。', 'libre-compress' ),
                    ),
                    'webp_sharp_yuv'       => array(
                        'type'      => 'bool',
                        'label'     => __( '锐利色彩转换', 'libre-compress' ),
                        'flag'      => 'cwebp -sharp_yuv',
                        'default'   => false,
                        'advanced'  => true,
                        'desc'      => __( 'RGB 转 YUV 时用更准的算法，红色和暗部更干净，编码稍慢。', 'libre-compress' ),
                    ),
                ),
            ),
            'avif'             => array(
                'title'  => __( 'AVIF 同格式压缩（静图与动图）', 'libre-compress' ),
                'note'   => __( '静图先由 avifdec 解成 PNG 再交给 avifenc 重压；动图由 FFmpeg 一次处理完。动图只用质量与速度，其余开关只对静图生效。', 'libre-compress' ),
                'params' => array(
                    'avif_mode'           => self::mode_param(),
                    'avif_quality'        => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'avifenc -q / FFmpeg -crf',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '100 为无损，0 最省体积。AVIF 的 80 大致相当于 JPEG 的 90。', 'libre-compress' ),
                    ),
                    'avif_alpha_quality'  => array(
                        'type'    => 'int',
                        'label'   => __( '透明通道质量', 'libre-compress' ),
                        'flag'    => 'avifenc --qalpha',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '100 为透明通道无损；半透明边缘多的图建议调高。动图的透明通道由 FFmpeg 固定无损。', 'libre-compress' ),
                    ),
                    'avif_speed'          => array(
                        'type'    => 'int',
                        'label'   => __( '速度', 'libre-compress' ),
                        'flag'    => 'avifenc -s / FFmpeg -cpu-used',
                        'min'     => 0,
                        'max'     => 10,
                        'default' => 6,
                        'desc'    => __( '0 最慢最小、10 最快最大。动图的 FFmpeg 最高只到 8，超过按 8 处理。', 'libre-compress' ),
                    ),
                    'avif_chroma'         => array(
                        'type'    => 'choice',
                        'label'   => __( '色度抽样', 'libre-compress' ),
                        'flag'    => 'avifenc -y',
                        'choices' => array(
                            'auto' => __( '自动（按输入决定）', 'libre-compress' ),
                            '444'  => __( '4:4:4（色彩最准）', 'libre-compress' ),
                            '422'  => __( '4:2:2（横向减半）', 'libre-compress' ),
                            '420'  => __( '4:2:0 + sharpyuv（最小，边缘略糊）', 'libre-compress' ),
                        ),
                        'default' => 'auto',
                        'desc'    => __( '只有有损有效。选 4:2:0 会带 sharpyuv，让彩色边缘不至于太糊。', 'libre-compress' ),
                    ),
                    'avif_depth'          => array(
                        'type'    => 'choice',
                        'label'   => __( '位深', 'libre-compress' ),
                        'flag'    => 'avifenc -d',
                        'choices' => array(
                            '8'  => __( '8 位（默认，浏览器都能显示）', 'libre-compress' ),
                            '10' => __( '10 位（更平滑的渐变，体积略大）', 'libre-compress' ),
                            '12' => __( '12 位（几乎用不到）', 'libre-compress' ),
                        ),
                        'default' => '8',
                        'desc'    => __( '位深越高渐变越平滑，但 10/12 位文件更大，老设备可能不支持。', 'libre-compress' ),
                    ),
                    'avif_jobs'           => array(
                        'type'    => 'int',
                        'label'   => __( '并行线程数', 'libre-compress' ),
                        'flag'    => 'avifenc -j',
                        'min'     => 1,
                        'max'     => 16,
                        'default' => 4,
                        'desc'    => __( '同时开几个编码线程。开太多会和网站抢 CPU，4 左右比较稳。', 'libre-compress' ),
                    ),
                ),
            ),
            'jxl'              => array(
                'title'  => __( 'JPEG XL 同格式压缩（静图与动图）', 'libre-compress' ),
                'note'   => __( 'cjxl 能直接读写动画 JXL，一条命令重编码，不需要拆帧。', 'libre-compress' ),
                'params' => array(
                    'jxl_mode'    => self::mode_param(),
                    'jxl_quality' => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'cjxl -q',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '100 等于数学无损，90 已经是视觉无损。官方建议 68-96。', 'libre-compress' ),
                    ),
                    'jxl_effort'  => array(
                        'type'    => 'int',
                        'label'   => __( '压缩级别', 'libre-compress' ),
                        'flag'    => 'cjxl -e',
                        'min'     => 1,
                        'max'     => 10,
                        'default' => 7,
                        'desc'    => __( '级别越高越慢、同样画质体积越小。7 是 cjxl 默认值，8 以上明显变慢。', 'libre-compress' ),
                    ),
                ),
            ),
            'svg'              => array(
                'title'  => __( 'SVG 同格式压缩', 'libre-compress' ),
                'note'   => __( '由 oxvg 处理，两种预设都会删掉 <metadata> 和注释。', 'libre-compress' ),
                'params' => array(
                    'svg_preset' => array(
                        'type'    => 'choice',
                        'label'   => __( '优化预设', 'libre-compress' ),
                        'flag'    => 'oxvg optimise -e',
                        'choices' => array(
                            'default' => __( '常规（default）', 'libre-compress' ),
                            'safe'    => __( '保守（safe，几乎不改视觉）', 'libre-compress' ),
                        ),
                        'default' => 'default',
                        'desc'    => __( '常规会合并路径、精简属性；保守只做绝对安全的重写。', 'libre-compress' ),
                    ),
                ),
            ),
            'convert_webp'     => array(
                'title'  => __( '格式转换：输出 WebP', 'libre-compress' ),
                'note'   => __( 'PNG / JPG 用 cwebp，GIF 用 gif2webp，APNG 用 FFmpeg。这三处的参数都在这里分开设置。', 'libre-compress' ),
                'params' => array(
                    'convert_webp_mode'           => self::mode_param(),
                    'convert_webp_quality'        => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'cwebp -q / gif2webp -q / FFmpeg -quality',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '三条转换路线共用这一个质量。', 'libre-compress' ),
                    ),
                    'convert_webp_method'         => array(
                        'type'    => 'int',
                        'label'   => __( 'cwebp / FFmpeg 有损方法', 'libre-compress' ),
                        'flag'    => 'cwebp -m / FFmpeg -compression_level',
                        'min'     => 0,
                        'max'     => 6,
                        'default' => 4,
                        'desc'    => __( '0 最快、6 最慢但最小。', 'libre-compress' ),
                    ),
                    'convert_webp_lossless_level' => array(
                        'type'    => 'int',
                        'label'   => __( '无损级别', 'libre-compress' ),
                        'flag'    => 'cwebp -z',
                        'min'     => 0,
                        'max'     => 9,
                        'default' => 6,
                        'desc'    => __( '只有无损模式有效。动图按比例换算成 FFmpeg 的 0-6 档。', 'libre-compress' ),
                    ),
                    'convert_webp_gif_method'     => array(
                        'type'    => 'int',
                        'label'   => __( 'gif2webp 方法', 'libre-compress' ),
                        'flag'    => 'gif2webp -m',
                        'min'     => 0,
                        'max'     => 6,
                        'default' => 4,
                        'desc'    => __( 'GIF 转 WebP 单独用这一档，和上面的 cwebp 方法互不影响。', 'libre-compress' ),
                    ),
                    'convert_webp_alpha_quality'  => array(
                        'type'    => 'int',
                        'label'   => __( '透明通道质量', 'libre-compress' ),
                        'flag'    => 'cwebp -alpha_q',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 100,
                        'desc'    => __( '100 为透明通道无损。GIF / APNG 转 WebP 时透明通道由各自工具处理。', 'libre-compress' ),
                    ),
                    'convert_webp_preset'         => array(
                        'type'    => 'choice',
                        'label'   => __( '内容预设', 'libre-compress' ),
                        'flag'    => 'cwebp -preset',
                        'choices' => array(
                            'default' => __( '默认', 'libre-compress' ),
                            'photo'   => __( '照片', 'libre-compress' ),
                            'picture' => __( '室内人像', 'libre-compress' ),
                            'drawing' => __( '绘图 / 线稿', 'libre-compress' ),
                            'icon'    => __( '图标 / 小彩图', 'libre-compress' ),
                            'text'    => __( '文字截图', 'libre-compress' ),
                        ),
                        'default' => 'default',
                        'desc'    => __( '只对 PNG / JPG 转 WebP 生效，GIF 与 APNG 不走 cwebp。', 'libre-compress' ),
                    ),
                    'convert_webp_exact'          => array(
                        'type'    => 'bool',
                        'label'   => __( '保留透明区域颜色', 'libre-compress' ),
                        'flag'    => 'cwebp -exact',
                        'default' => false,
                        'desc'    => __( '保留全透明像素的 RGB，体积约多 16%。', 'libre-compress' ),
                    ),
                    'convert_webp_segments'       => array(
                        'type'     => 'int',
                        'label'    => __( '分区数量', 'libre-compress' ),
                        'flag'     => 'cwebp -segments',
                        'min'      => 1,
                        'max'      => 4,
                        'default'  => 4,
                        'advanced' => true,
                        'desc'     => __( '把图切成几块分别量化，4 最均匀也最慢。', 'libre-compress' ),
                    ),
                    'convert_webp_sns'            => array(
                        'type'     => 'int',
                        'label'    => __( '空间噪点整形', 'libre-compress' ),
                        'flag'     => 'cwebp -sns',
                        'min'      => 0,
                        'max'      => 100,
                        'default'  => 50,
                        'advanced' => true,
                        'desc'     => __( '把量化误差藏进纹理里，0 关闭、100 最强。', 'libre-compress' ),
                    ),
                    'convert_webp_filter'         => array(
                        'type'     => 'int',
                        'label'    => __( '滤波强度', 'libre-compress' ),
                        'flag'     => 'cwebp -f',
                        'min'      => 0,
                        'max'      => 100,
                        'default'  => 60,
                        'advanced' => true,
                        'desc'     => __( '编码前先平滑画面，0 关闭、100 最强。', 'libre-compress' ),
                    ),
                    'convert_webp_sharpness'      => array(
                        'type'     => 'int',
                        'label'    => __( '滤波锐度', 'libre-compress' ),
                        'flag'     => 'cwebp -sharpness',
                        'min'      => 0,
                        'max'      => 7,
                        'default'  => 0,
                        'advanced' => true,
                        'desc'     => __( '0 最锐、7 最柔。', 'libre-compress' ),
                    ),
                    'convert_webp_sharp_yuv'      => array(
                        'type'     => 'bool',
                        'label'    => __( '锐利色彩转换', 'libre-compress' ),
                        'flag'     => 'cwebp -sharp_yuv',
                        'default'  => false,
                        'advanced' => true,
                        'desc'     => __( 'RGB 转 YUV 时用更准的算法，红色和暗部更干净。', 'libre-compress' ),
                    ),
                ),
            ),
            'convert_avif'     => array(
                'title'  => __( '格式转换：输出 AVIF', 'libre-compress' ),
                'note'   => __( 'PNG / JPG 与静态 GIF 走 avifenc，动画 GIF 与 APNG 走 FFmpeg。动图只用质量与速度。', 'libre-compress' ),
                'params' => array(
                    'convert_avif_mode'          => self::mode_param(),
                    'convert_avif_quality'       => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'avifenc -q / FFmpeg -crf',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '100 为无损，0 最省体积。', 'libre-compress' ),
                    ),
                    'convert_avif_alpha_quality' => array(
                        'type'    => 'int',
                        'label'   => __( '透明通道质量', 'libre-compress' ),
                        'flag'    => 'avifenc --qalpha',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '100 为透明通道无损。动图的透明通道由 FFmpeg 固定无损。', 'libre-compress' ),
                    ),
                    'convert_avif_speed'         => array(
                        'type'    => 'int',
                        'label'   => __( '速度', 'libre-compress' ),
                        'flag'    => 'avifenc -s / FFmpeg -cpu-used',
                        'min'     => 0,
                        'max'     => 10,
                        'default' => 6,
                        'desc'    => __( '0 最慢最小、10 最快最大。动图最高只到 8。', 'libre-compress' ),
                    ),
                    'convert_avif_chroma'        => array(
                        'type'    => 'choice',
                        'label'   => __( '色度抽样', 'libre-compress' ),
                        'flag'    => 'avifenc -y',
                        'choices' => array(
                            'auto' => __( '自动（按输入决定）', 'libre-compress' ),
                            '444'  => __( '4:4:4（色彩最准）', 'libre-compress' ),
                            '422'  => __( '4:2:2（横向减半）', 'libre-compress' ),
                            '420'  => __( '4:2:0 + sharpyuv（最小，边缘略糊）', 'libre-compress' ),
                        ),
                        'default' => 'auto',
                        'desc'    => __( '只有有损有效。', 'libre-compress' ),
                    ),
                    'convert_avif_depth'         => array(
                        'type'    => 'choice',
                        'label'   => __( '位深', 'libre-compress' ),
                        'flag'    => 'avifenc -d',
                        'choices' => array(
                            '8'  => __( '8 位（默认，浏览器都能显示）', 'libre-compress' ),
                            '10' => __( '10 位（渐变更平滑，体积略大）', 'libre-compress' ),
                            '12' => __( '12 位（几乎用不到）', 'libre-compress' ),
                        ),
                        'default' => '8',
                        'desc'    => __( '位深越高渐变越平滑，但体积更大、老设备可能不支持。', 'libre-compress' ),
                    ),
                    'convert_avif_jobs'          => array(
                        'type'    => 'int',
                        'label'   => __( '并行线程数', 'libre-compress' ),
                        'flag'    => 'avifenc -j',
                        'min'     => 1,
                        'max'     => 16,
                        'default' => 4,
                        'desc'    => __( '同时开几个编码线程，开太多会和网站抢 CPU。', 'libre-compress' ),
                    ),
                ),
            ),
            'convert_jxl'      => array(
                'title'  => __( '格式转换：输出 JPEG XL', 'libre-compress' ),
                'note'   => __( '静态 GIF、APNG 都由 cjxl 直读，图像与动画共用这一组参数。', 'libre-compress' ),
                'params' => array(
                    'convert_jxl_mode'    => self::mode_param(),
                    'convert_jxl_quality' => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'libre-compress' ),
                        'flag'    => 'cjxl -q',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '100 等于数学无损，90 已经是视觉无损。', 'libre-compress' ),
                    ),
                    'convert_jxl_effort'  => array(
                        'type'    => 'int',
                        'label'   => __( '压缩级别', 'libre-compress' ),
                        'flag'    => 'cjxl -e',
                        'min'     => 1,
                        'max'     => 10,
                        'default' => 7,
                        'desc'    => __( '级别越高越慢、同样画质体积越小。', 'libre-compress' ),
                    ),
                ),
            ),
        );

        return $groups;
    }

    /**
     * 把分组表拍平成 键名 => 参数定义
     *
     * @return array<string,array>
     */
    public static function param_map(): array {
        static $map = null;

        if ( null !== $map ) {
            return $map;
        }

        $map = array();

        foreach ( self::param_groups() as $group_id => $group ) {
            foreach ( $group['params'] as $key => $param ) {
                $param['group'] = $group_id;
                $map[ $key ]    = $param;
            }
        }

        return $map;
    }

    /**
     * 全部参数的默认值
     *
     * @return array
     */
    public static function defaults(): array {
        $defaults = array();

        foreach ( self::param_map() as $key => $param ) {
            $defaults[ $key ] = $param['default'];
        }

        return $defaults;
    }

    /**
     * 按定义把单个值夹到合法范围
     *
     * 非法输入（数组、对象、非数字、空值）一律回落到默认值，绝不把可疑数据带进命令行。
     *
     * @param array $param 参数定义
     * @param mixed $value 原始值
     * @return mixed
     */
    public static function normalize_param( array $param, $value ) {
        switch ( $param['type'] ) {
            case 'int':
                if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^-?\d+$/D', (string) $value ) ) {
                    return (int) $param['default'];
                }

                return (int) max( (int) $param['min'], min( (int) $param['max'], (int) $value ) );

            case 'choice':
                return ( is_string( $value ) && array_key_exists( $value, $param['choices'] ) ) ? $value : $param['default'];

            case 'bool':
                return isset( $value ) ? ! empty( $value ) : (bool) $param['default'];

            case 'mode':
                return ( 'lossless' === $value ) ? 'lossless' : 'lossy';
        }

        return $param['default'];
    }

    /**
     * 整表归一化
     *
     * @param array $input 表单或选项原始值
     * @return array
     */
    public static function normalize_all( array $input ): array {
        $normalized = array();

        foreach ( self::param_map() as $key => $param ) {
            $normalized[ $key ] = self::normalize_param( $param, $input[ $key ] ?? null );
        }

        // pngquant 的质量区间必须下限不大于上限，填反了直接对调，别让它悄悄压成最差质量。
        if ( $normalized['png_quality_min'] > $normalized['png_quality_max'] ) {
            $swap                          = $normalized['png_quality_min'];
            $normalized['png_quality_min'] = $normalized['png_quality_max'];
            $normalized['png_quality_max'] = $swap;
        }

        return $normalized;
    }

    /**
     * 读取单个已归一化的参数
     *
     * @param string $key 参数键名
     * @return mixed
     */
    public static function param( string $key ) {
        $map = self::param_map();

        if ( ! isset( $map[ $key ] ) ) {
            return null;
        }

        $settings = get_option( 'libre_compress_tools', array() );
        $settings = is_array( $settings ) ? $settings : array();

        return self::normalize_param( $map[ $key ], $settings[ $key ] ?? null );
    }

    /**
     * 读取整数参数
     *
     * @param string $key 参数键名
     * @return int
     */
    public static function param_int( string $key ): int {
        return (int) self::param( $key );
    }

    /**
     * 读取枚举参数
     *
     * @param string $key 参数键名
     * @return string
     */
    public static function param_choice( string $key ): string {
        $value = self::param( $key );

        return is_string( $value ) ? $value : '';
    }

    /**
     * 读取开关参数
     *
     * @param string $key 参数键名
     * @return bool
     */
    public static function param_flag( string $key ): bool {
        return (bool) self::param( $key );
    }

    /**
     * 读取某条压缩路径的压缩模式
     *
     * @param string $group 分组键名，例如 jpeg、webp、convert_webp
     * @return string lossy|lossless
     */
    public static function param_mode( string $group ): string {
        $value = self::param( $group . '_mode' );

        return 'lossless' === $value ? 'lossless' : 'lossy';
    }

    /* ---------------------------------------------------------------------
     * 基本设置
     * ------------------------------------------------------------------- */

    /**
     * 归一化原图备份保留时长
     *
     * -1 为永久保留；0 不是合法值，按步进方向落到 1；其他负数和非数字一律按永久处理；
     * 正整数不设上限。
     *
     * @param mixed $value 待归一化的原始值
     * @return int
     */
    public static function normalize_retention_days( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::RETENTION_PERMANENT;
        }

        $days = (int) $value;

        if ( 0 === $days ) {
            return 1;
        }

        return $days < 0 ? self::RETENTION_PERMANENT : $days;
    }

    /**
     * 归一化大图缩放阈值
     *
     * 对应 WordPress 的 big_image_size_threshold：上传图片的宽或高超过此值时会生成
     * -scaled 缩放图并接管原文件位置。阈值不再承担“关闭”语义，关闭自动缩放由独立的
     * 勾选框负责，因此非数字或小于 1 的值一律回落到默认阈值。
     *
     * @param mixed $value 待归一化的原始值
     * @return int
     */
    public static function normalize_image_size_threshold( $value ): int {
        // 阈值不再用 0 表示关闭：关闭自动缩放由独立的勾选框负责，
        // 因此非数字或小于 1 的值一律回落到默认阈值。
        if ( ! is_numeric( $value ) || (int) $value < 1 ) {
            return self::IMAGE_SIZE_THRESHOLD_DEFAULT;
        }

        return (int) max( 1, min( self::IMAGE_SIZE_THRESHOLD_MAX, (int) $value ) );
    }

    /**
     * 是否开启自动缩放主图
     *
     * 对应缩略图尺寸区域里带勾选框的缩放小项：勾选才按阈值生成 -scaled 缩放图，
     * 不勾选就是关闭自动缩放。选项缺失时按开启处理，与 WordPress 默认缩放一致。
     *
     * @return bool
     */
    public static function image_scale_enabled(): bool {
        $general = get_option( 'libre_compress_general', array() );

        return ! isset( $general['image_scale_enabled'] ) || ! empty( $general['image_scale_enabled'] );
    }

    /**
     * 大图缩放阈值
     *
     * @return int 像素值，仅在开启自动缩放时生效
     */
    public static function image_size_threshold(): int {
        $general = get_option( 'libre_compress_general', array() );

        return self::normalize_image_size_threshold( $general['image_size_threshold'] ?? null );
    }

    /**
     * 压缩与格式转换时是否删除图片元数据
     *
     * 选项缺失时按删除处理：拍摄地点、设备型号这类信息留在对外展示的图片上属于隐私泄露，
     * 默认不删不如默认删。
     *
     * @return bool
     */
    public static function strips_metadata(): bool {
        $general = get_option( 'libre_compress_general', array() );

        return ! isset( $general['strip_metadata'] ) || (bool) $general['strip_metadata'];
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_settings_menu() {
        add_options_page(
            __( 'LibreCompress', 'libre-compress' ),
            __( 'LibreCompress', 'libre-compress' ),
            'manage_options',
            'libre-compress',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'libre_compress_general_group',
            'libre_compress_general',
            array( $this, 'sanitize_general_settings' )
        );

        register_setting(
            'libre_compress_tools_group',
            'libre_compress_tools',
            array( $this, 'sanitize_tools_settings' )
        );
    }

    public function sanitize_general_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $sanitized = array();

        $current_general = get_option( 'libre_compress_general', array() );
        $sanitized['auto_compress']      = ! empty( $input['auto_compress'] );
        $sanitized['backup_enabled']     = ! empty( $input['backup_enabled'] );
        $sanitized['backup_retention_days'] = self::normalize_retention_days( isset( $input['backup_retention_days'] ) ? $input['backup_retention_days'] : null );
        $sanitized['image_scale_enabled']      = ! empty( $input['image_scale_enabled'] );
        $sanitized['image_size_threshold']   = self::normalize_image_size_threshold( isset( $input['image_size_threshold'] ) ? $input['image_size_threshold'] : null );
        $sanitized['strip_metadata']        = ! empty( $input['strip_metadata'] );
        // 缩略图尺寸：每个尺寸都有隐藏域 0 和复选框 1 两个同名输入，后提交的复选框生效，
        // 所以未勾选时也会带上 0；表单里没有这组字段时保留原状态。
        if ( array_key_exists( 'thumbnail_state', $input ) ) {
            $state    = (array) $input['thumbnail_state'];
            $disabled = array();

            foreach ( array_keys( wp_get_registered_image_subsizes() ) as $size_name ) {
                if ( ! isset( $state[ $size_name ] ) || '1' !== (string) $state[ $size_name ] ) {
                    $disabled[] = $size_name;
                }
            }

            $sanitized['disabled_thumbnail_sizes'] = $disabled;
        } else {
            $stored                                = isset( $current_general['disabled_thumbnail_sizes'] ) ? (array) $current_general['disabled_thumbnail_sizes'] : array();
            $sanitized['disabled_thumbnail_sizes'] = array_values( array_filter( array_map( 'sanitize_key', $stored ) ) );
        }

        // 压缩输出设置：勾选的源格式输出为目标格式，未勾选格式执行同格式压缩。
        $sanitized['output_png'] = ! empty( $input['output_png'] );
        $sanitized['output_jpg'] = ! empty( $input['output_jpg'] );
        $sanitized['output_gif'] = ! empty( $input['output_gif'] );
        $sanitized['output_format'] = ( isset( $input['output_format'] ) && in_array( $input['output_format'], array( 'webp', 'avif', 'jxl' ), true ) ) ? $input['output_format'] : 'avif';

        // 兼容格式回退开关
        $sanitized['fallback_enabled'] = ! empty( $input['fallback_enabled'] );

        // SVG 上传开关
        $sanitized['allow_svg_upload'] = ! empty( $input['allow_svg_upload'] );

        return $sanitized;
    }

    /**
     * 保存压缩参数
     *
     * 表单一共提交哪些字段就校验哪些字段，全部按参数总表夹取；
     * 既不做缺省补全以外的手工拼装，也不给任何字段留“漏了就归零”的口子。
     *
     * @param mixed $input 表单提交值
     * @return array
     */
    public function sanitize_tools_settings( $input ) {
        return self::normalize_all( is_array( $input ) ? $input : array() );
    }

    public function render_settings_page() {
        $this->current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

        $valid_tabs = array( 'general', 'tools' );
        if ( ! in_array( $this->current_tab, $valid_tabs, true ) ) {
            $this->current_tab = 'general';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LibreCompress', 'libre-compress' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=libre-compress&tab=general' ) ); ?>" class="nav-tab <?php echo 'general' === $this->current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '基本设置', 'libre-compress' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=libre-compress&tab=tools' ) ); ?>" class="nav-tab <?php echo 'tools' === $this->current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '压缩工具', 'libre-compress' ); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ( $this->current_tab ) {
                    case 'tools':
                        $this->render_tools_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_general_tab() {
        $options = get_option( 'libre_compress_general', array() );
        $retention_days = Libre_Compress_Settings::normalize_retention_days( isset( $options['backup_retention_days'] ) ? $options['backup_retention_days'] : null );
        $registered_sizes = wp_get_registered_image_subsizes();
        $disabled_sizes   = Libre_Compress_Thumbnail_Manager::disabled_sizes();
        ?>
        <style>
            .libre-compress-seg { position: relative; display: inline-flex; background: #dcdcde; border-radius: 8px; padding: 3px; vertical-align: middle; }
            .libre-compress-seg input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
            .libre-compress-seg label { position: relative; z-index: 2; flex: 1; min-width: 64px; padding: 5px 16px; text-align: center; color: #50575e; font-weight: 500; cursor: pointer; border-radius: 6px; transition: color .15s; }
            .libre-compress-seg .seg-thumb { position: absolute; z-index: 1; top: 3px; bottom: 3px; left: 3px; width: calc((100% - 6px) / 3); background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0, 0, 0, .15); transition: transform .2s ease; }
            #libre-compress-seg-avif:checked ~ .seg-thumb { transform: translateX(100%); }
            #libre-compress-seg-jxl:checked ~ .seg-thumb { transform: translateX(200%); }
            #libre-compress-seg-webp:checked ~ label[for="libre-compress-seg-webp"],
            #libre-compress-seg-avif:checked ~ label[for="libre-compress-seg-avif"],
            #libre-compress-seg-jxl:checked ~ label[for="libre-compress-seg-jxl"] { color: #1d2327; font-weight: 600; }
        </style>
        <form method="post" action="options.php">
            <?php settings_fields( 'libre_compress_general_group' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '自动压缩', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[auto_compress]" value="1" <?php checked( ! empty( $options['auto_compress'] ) ); ?>>
                            <?php esc_html_e( '上传图片时自动压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '备份原图', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[backup_enabled]" value="1" <?php checked( $options['backup_enabled'] ?? true ); ?>>
                            <?php esc_html_e( '压缩前备份原图', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="libre-compress-retention-days"><?php esc_html_e( '原图备份保留时长', 'libre-compress' ); ?></label></th>
                    <td>
                        <input type="number" id="libre-compress-retention-days" name="libre_compress_general[backup_retention_days]" value="<?php echo esc_attr( $retention_days ); ?>" min="-1" step="1" class="small-text">
                        <?php esc_html_e( '天', 'libre-compress' ); ?>
                        <p class="description"><?php esc_html_e( '填 -1 表示永久保留；填天数则备份到期后自动删除，届时这张图将无法再恢复原图。0 无效，步进时会自动跳过 0。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '图片元数据', 'libre-compress' ); ?></th>
                    <td>
                        <label style="display:block;">
                            <input type="checkbox" name="libre_compress_general[strip_metadata]" value="1" <?php checked( $options['strip_metadata'] ?? true ); ?>>
                            <?php esc_html_e( '压缩时删除图片元数据', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '删除 EXIF、GPS 拍摄位置、设备型号、作者与软件信息等隐私数据，对同格式压缩与格式转换的所有路径都生效。ICC 色彩配置会尽量保留以免偏色；取消勾选则尽量保留元数据，但 jpegli 等编码工具本身不写元数据时留不住。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'SVG 上传', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[allow_svg_upload]" value="1" <?php checked( ! empty( $options['allow_svg_upload'] ) ); ?>>
                            <?php esc_html_e( '允许上传 SVG 文件', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '上传时自动清理脚本等危险内容；SVG 可能被用于 XSS 攻击，仅在信任上传者时开启。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '格式转换', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_png]" value="1" <?php checked( ! empty( $options['output_png'] ) ); ?>>
                            <?php esc_html_e( 'PNG', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_jpg]" value="1" <?php checked( ! empty( $options['output_jpg'] ) ); ?>>
                            <?php esc_html_e( 'JPG', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_gif]" value="1" <?php checked( ! empty( $options['output_gif'] ) ); ?>>
                            <?php esc_html_e( 'GIF', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '勾选的源格式执行压缩时输出为目标格式；未勾选的格式只执行同格式压缩。所有成功结果都记为已压缩。只有 PNG、JPG、GIF 能转换格式，动图同样按这两个勾选走：APNG 看 PNG，动画 GIF 看 GIF；WebP、AVIF、JPEG XL 无论静图动图都只做同格式压缩，不会再来一遍转换。动图整张交给支持动画和透明背景的工具处理，插件不拆帧、不修改透明像素。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '兼容格式回退', 'libre-compress' ); ?></th>
                    <td>
                        <label style="display:block;">
                            <input type="checkbox" name="libre_compress_general[fallback_enabled]" value="1" <?php checked( $options['fallback_enabled'] ?? false ); ?>>
                            <?php esc_html_e( '为转换后的图片提供旧格式回退', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '开启后，格式转换会把旧格式文件原地留下并同样压缩，新的 WebP/AVIF 以“原文件名.webp”“原文件名.avif”的双扩展名形式生成，前台用 <picture> 输出：支持新格式的浏览器加载新格式，不支持的浏览器加载同样压缩过的旧格式。关闭后转换不再保留旧格式文件，新格式直接替换源文件，前台也只输出新格式。此前已转换的图片仍留着旧格式文件，可用下方的“删除所有兼容格式回退”清理。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '目标格式', 'libre-compress' ); ?></th>
                    <td>
                        <span class="libre-compress-seg">
                            <input type="radio" name="libre_compress_general[output_format]" value="webp" id="libre-compress-seg-webp" <?php checked( ( $options['output_format'] ?? 'avif' ), 'webp' ); ?>>
                            <label for="libre-compress-seg-webp"><?php esc_html_e( 'WebP', 'libre-compress' ); ?></label>
                            <input type="radio" name="libre_compress_general[output_format]" value="avif" id="libre-compress-seg-avif" <?php checked( ( $options['output_format'] ?? 'avif' ), 'avif' ); ?>>
                            <label for="libre-compress-seg-avif"><?php esc_html_e( 'AVIF', 'libre-compress' ); ?></label>
                            <input type="radio" name="libre_compress_general[output_format]" value="jxl" id="libre-compress-seg-jxl" <?php checked( ( $options['output_format'] ?? 'avif' ), 'jxl' ); ?>>
                            <label for="libre-compress-seg-jxl"><?php esc_html_e( 'JPEG XL', 'libre-compress' ); ?></label>
                            <span class="seg-thumb"></span>
                        </span>
                        <p class="description"><?php esc_html_e( '选择目标格式：AVIF 压缩率更高但压缩更慢，WebP 兼容性最好，JPEG XL 同画质体积最小但浏览器支持仍不完整（动画 JXL 更是只有少数浏览器能播），选 JPEG XL 时建议同时开启上面的“兼容格式回退”。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '缩略图尺寸', 'libre-compress' ); ?></th>
                    <td>
                        <label style="display:block; margin-bottom:10px;">
                            <input type="checkbox" name="libre_compress_general[image_scale_enabled]" value="1" <?php checked( Libre_Compress_Settings::image_scale_enabled() ); ?>>
                            <?php esc_html_e( '自动缩放超尺寸的主图，阈值', 'libre-compress' ); ?>
                            <input type="number" name="libre_compress_general[image_size_threshold]" value="<?php echo esc_attr( Libre_Compress_Settings::image_size_threshold() ); ?>" min="1" max="20000" step="1" class="small-text">
                            <?php esc_html_e( '像素', 'libre-compress' ); ?>
                        </label>
                        <p class="description" style="margin-top:0;"><?php esc_html_e( '勾选后，上传或补生成缩略图时，宽或高超过此阈值的主图会另存为 -scaled 缩放图并接管主文件，未缩放的源文件仍留在磁盘上；不勾选则关闭自动缩放，原图直接投入使用。压缩已有图片时不会改动缩放，改动缩放请用下方的“删除未勾选尺寸的缩略图”和“补生成缺失尺寸的缩略图”两个按钮。默认 2560，与 WordPress 默认一致。', 'libre-compress' ); ?></p>
                        <div style="margin-top:14px;">
                            <?php if ( empty( $registered_sizes ) ) : ?>
                                <p class="description"><?php esc_html_e( '当前没有注册额外的缩略图尺寸。', 'libre-compress' ); ?></p>
                            <?php else : ?>
                                <?php foreach ( $registered_sizes as $size_name => $size_config ) : ?>
                                    <?php
                                    $width     = (int) $size_config['width'];
                                    $height    = (int) $size_config['height'];
                                    // 宽或高为 0 表示该方向不限，由 WordPress 按原图比例自动推算。
                                    $dimension = sprintf(
                                        '%1$s×%2$s',
                                        $width > 0 ? $width : __( '自动', 'libre-compress' ),
                                        $height > 0 ? $height : __( '自动', 'libre-compress' )
                                    );
                                    $crop      = empty( $size_config['crop'] ) ? __( '等比', 'libre-compress' ) : __( '裁剪', 'libre-compress' );
                                    ?>
                                    <label style="display:block;">
                                        <input type="hidden" name="libre_compress_general[thumbnail_state][<?php echo esc_attr( $size_name ); ?>]" value="0">
                                        <input type="checkbox" name="libre_compress_general[thumbnail_state][<?php echo esc_attr( $size_name ); ?>]" value="1" <?php checked( ! in_array( $size_name, $disabled_sizes, true ) ); ?>>
                                        <?php
                                        printf(
                                            /* translators: 1: 尺寸名, 2: 目标像素, 3: 裁剪方式 */
                                            esc_html__( '%1$s（%2$s，%3$s）', 'libre-compress' ),
                                            esc_html( $size_name ),
                                            esc_html( $dimension ),
                                            esc_html( $crop )
                                        );
                                        ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php esc_html_e( '取消勾选的尺寸在上传图片时不再生成；已经存在的文件用下方“删除未勾选尺寸的缩略图”清理。以后新注册的尺寸默认勾选。', 'libre-compress' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <table class="form-table">
            <tr>
                <td style="width: 200px; padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-bulk-compress">
                        <?php esc_html_e( '批量压缩未压缩的图片', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '按当前设置压缩媒体库中所有未压缩的图片', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-clear-records">
                        <?php esc_html_e( '清除压缩记录与原图备份', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '同时删除备份文件、备份索引和格式转换映射，之后所有图片都可重新压缩；此操作不可撤销。', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-restore-all">
                        <?php esc_html_e( '恢复所有原图备份', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '将所有已压缩的图片恢复为原图', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-all-backups">
                        <?php esc_html_e( '删除所有原图备份', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除所有备份文件，释放磁盘空间', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-all-fallbacks">
                        <?php esc_html_e( '删除所有兼容格式回退', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除媒体库中转换后保留的旧格式回退文件，并把文章里指向它们的图片链接改到新格式；删除后不支持新格式的浏览器将直接加载新格式图片', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-thumbnails">
                        <?php esc_html_e( '删除未勾选尺寸的缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除未勾选尺寸已存在的缩略图，文章里指向它们的图片链接会改到面积最接近的现有尺寸；若关闭了自动缩放，或现有 -scaled 缩放图与阈值不符，也会删除缩放图让主图退回原图', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-generate-thumbnails">
                        <?php esc_html_e( '补生成缺失尺寸的缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '为已勾选但缺失的尺寸补生成缩略图；若开启了自动缩放且主图超过阈值，还会生成或修正 -scaled 缩放图。不会重建已存在的缩略图，纯缩略图缺失也不改动文章链接', 'libre-compress' ); ?></span>
                </td>
            </tr>
        </table>

        <?php
        // 批量操作由服务器自己在后台一批批地跑（见 Libre_Compress_Task），
        // 这里的面板只负责把进度摆出来给用户看，初始状态由脚本数据带过去。
        ?>
        <style>
            .libre-compress-task { margin-top: 12px; padding: 12px 14px; max-width: 900px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; border-radius: 3px; }
            .libre-compress-task.is-done { border-left-color: #00a32a; }
            .libre-compress-task.is-stopped { border-left-color: #dba617; }
            .libre-compress-task-head { display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px; }
            .libre-compress-task-head .task-label { font-size: 14px; }
            .libre-compress-task-head .task-status { color: #646970; font-size: 12px; }
            .libre-compress-task .task-bar { position: relative; height: 22px; background: #f0f0f1; border-radius: 3px; overflow: hidden; }
            .libre-compress-task .task-bar-fill { width: 0; height: 100%; background: #2271b1; border-radius: 3px; transition: width .3s ease; }
            .libre-compress-task.is-done .task-bar-fill { background: #00a32a; }
            .libre-compress-task.is-stopped .task-bar-fill { background: #dba617; }
            .libre-compress-task .task-bar-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #1d2327; }
            .libre-compress-task .task-line { margin: 8px 0 0; color: #50575e; font-size: 13px; }
            .libre-compress-task .task-current { word-break: break-all; }
            .libre-compress-task .task-summary { margin: 10px 0 0; white-space: pre-line; font-size: 13px; color: #1d2327; }
            .libre-compress-task .task-actions { margin: 10px 0 0; display: flex; flex-wrap: wrap; gap: 8px; }
        </style>
        <div id="libre-compress-task" class="libre-compress-task" style="display: none;">
            <div class="libre-compress-task-head">
                <strong class="task-label"></strong>
                <span class="task-status"></span>
            </div>
            <div class="task-bar">
                <div class="task-bar-fill"></div>
                <span class="task-bar-text"></span>
            </div>
            <p class="task-line task-progress"></p>
            <p class="task-line task-counts"></p>
            <p class="task-line task-current"></p>
            <p class="task-summary"></p>
            <p class="task-actions">
                <button type="button" class="button" id="libre-compress-task-stop"><?php esc_html_e( '停止任务', 'libre-compress' ); ?></button>
                <button type="button" class="button" id="libre-compress-task-resume"><?php esc_html_e( '继续执行', 'libre-compress' ); ?></button>
                <button type="button" class="button" id="libre-compress-task-retry"><?php esc_html_e( '重试失败的项目', 'libre-compress' ); ?></button>
                <button type="button" class="button" id="libre-compress-task-dismiss"><?php esc_html_e( '完成', 'libre-compress' ); ?></button>
            </p>
        </div>
        <?php
    }

    private function render_tools_tab() {
        $compressor       = libre_compress()->compressor;
        $output_processor = libre_compress()->output_processor;
        $tools            = $compressor->get_tools();

        $proc_open_available = Libre_Compress_Tool_Base::is_command_execution_available();

        // 全部压缩工具，按格式相邻排序
        $all_tools = array(
            array( 'name' => 'jpegoptim', 'tool' => null, 'path' => $output_processor->find_local_tool( 'jpegoptim' ), 'url' => 'https://github.com/tjko/jpegoptim' ),
            array( 'name' => 'cjpegli', 'tool' => null, 'path' => $output_processor->find_local_tool( 'cjpegli' ), 'url' => 'https://github.com/google/jpegli' ),
            array( 'name' => 'djpegli', 'tool' => null, 'path' => $output_processor->find_local_tool( 'djpegli' ), 'url' => 'https://github.com/google/jpegli' ),
            array( 'name' => 'pngquant', 'tool' => $tools['pngquant'] ),
            array( 'name' => 'oxipng', 'tool' => $tools['oxipng'] ),
            array( 'name' => 'gifsicle', 'tool' => $tools['gifsicle'] ),
            array( 'name' => 'gif2webp', 'tool' => null, 'path' => $output_processor->find_local_tool( 'gif2webp' ), 'url' => 'https://developers.google.com/speed/webp/download' ),
            array( 'name' => 'webpmux', 'tool' => null, 'path' => $output_processor->find_local_tool( 'webpmux' ), 'url' => 'https://developers.google.com/speed/webp/download' ),
            array( 'name' => 'ffprobe', 'tool' => null, 'path' => $output_processor->find_local_tool( 'ffprobe' ), 'url' => 'https://ffmpeg.org/download.html' ),
            array( 'name' => 'ffmpeg', 'tool' => null, 'path' => $output_processor->find_local_tool( 'ffmpeg' ), 'url' => 'https://ffmpeg.org/download.html' ),
            array( 'name' => 'cwebp', 'tool' => $tools['cwebp'] ),
            array( 'name' => 'avifenc', 'tool' => null, 'path' => $output_processor->find_local_tool( 'avifenc' ), 'url' => 'https://github.com/AOMediaCodec/libavif' ),
            array( 'name' => 'avifdec', 'tool' => null, 'path' => $output_processor->find_local_tool( 'avifdec' ), 'url' => 'https://github.com/AOMediaCodec/libavif' ),
            array( 'name' => 'cjxl', 'tool' => isset( $tools['cjxl'] ) ? $tools['cjxl'] : null, 'path' => $output_processor->find_local_tool( 'cjxl' ), 'url' => 'https://github.com/libjxl/libjxl/releases' ),
            array( 'name' => 'oxvg', 'tool' => $tools['oxvg'] ),
        );

        // 统一为渲染字段：available(bool)、path、url
        foreach ( $all_tools as $index => $row ) {
            if ( null !== $row['tool'] ) {
                $all_tools[ $index ]['available'] = $row['tool']->is_tool_available();
                $all_tools[ $index ]['path']      = $row['tool']->get_tool_binary_path();
                $all_tools[ $index ]['url']       = $row['tool']->get_download_url();
            } else {
                $all_tools[ $index ]['available'] = false !== $row['path'];
            }
        }
        ?>
        <h2><?php esc_html_e( '系统状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <tr>
                <td style="width: 120px;"><strong>proc_open()</strong></td>
                <td style="width: 150px;"></td>
                <td>
                    <?php if ( $proc_open_available ) : ?>
                        <span style="color: #00a32a;">✓ <?php esc_html_e( '可用', 'libre-compress' ); ?></span>
                    <?php else : ?>
                        <span><?php esc_html_e( '不可用', 'libre-compress' ); ?></span>
                        <p class="description"><?php esc_html_e( '插件的所有压缩都靠 proc_open() 直接启动命令行工具，请联系主机商启用', 'libre-compress' ); ?></p>
                    <?php endif; ?>
                </td>
                <td></td>
            </tr>
        </table>

        <h2><?php esc_html_e( '工具状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 170px;"><?php esc_html_e( '工具', 'libre-compress' ); ?></th>
                    <th style="width: 90px;"><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '安装路径', 'libre-compress' ); ?></th>
                    <th style="width: 50px;"><?php esc_html_e( '链接', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all_tools as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
                        <td>
                            <?php if ( $row['available'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '已安装', 'libre-compress' ); ?></span>
                            <?php else : ?>
                                <span><?php esc_html_e( '未安装', 'libre-compress' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( false !== $row['path'] ) : ?>
                                <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html( $row['path'] ); ?></code>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( '跳转', 'libre-compress' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="max-width: 900px; margin-top: 10px;">
            <?php esc_html_e( '安装方式：① 把可执行文件直接放进 /wp-content/LibreCompress-bin 目录；② 把工具所在文件夹加入系统 Path 环境变量；③ 使用包管理器安装。用 jpegli 必须同时放 cjpegli 和 djpegli 两个文件。', 'libre-compress' ); ?>
        </p>

        <h2 style="margin-top: 30px;"><?php esc_html_e( '路径支持状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 220px;"><?php esc_html_e( '路径', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '依赖', 'libre-compress' ); ?></th>
                    <th style="width: 280px;"><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $this->get_path_support_rows() as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['label'] ); ?></td>
                        <td><?php echo wp_kses_post( $row['deps_display'] ); ?></td>
                        <td>
                            <?php if ( $row['supported'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '支持', 'libre-compress' ); ?></span>
                            <?php else : ?>
                                <span><?php esc_html_e( '不支持', 'libre-compress' ); ?></span>
                                <?php if ( ! empty( $row['missing'] ) ) : ?>
                                    <span class="description">— <?php echo esc_html( sprintf( __( '缺少：%s', 'libre-compress' ), implode( '、', $row['missing'] ) ) ); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <hr>

        <h2><?php esc_html_e( '压缩参数', 'libre-compress' ); ?></h2>
        <p class="description" style="max-width: 900px;">
            <?php esc_html_e( '每条压缩路径的参数都在下面单独设置，输入框后面标的是它实际传给哪个工具的哪个参数，范围就是工具本身认可的范围：填超了保存时会自动夹到边界，不会静默改成别的值。改动只影响之后的压缩，已经压好的图片不会自动重压。', 'libre-compress' ); ?>
        </p>
        <form method="post" action="options.php">
            <?php settings_fields( 'libre_compress_tools_group' ); ?>

            <?php foreach ( self::param_groups() as $group ) : ?>
                <h3 style="margin-top: 28px;"><?php echo esc_html( $group['title'] ); ?></h3>
                <?php if ( ! empty( $group['note'] ) ) : ?>
                    <p class="description" style="max-width: 900px;"><?php echo esc_html( $group['note'] ); ?></p>
                <?php endif; ?>
                <table class="form-table">
                    <?php
                    $advanced = array();

                    foreach ( $group['params'] as $key => $param ) {
                        if ( ! empty( $param['advanced'] ) ) {
                            $advanced[ $key ] = $param;
                            continue;
                        }

                        $this->render_param_row( $key, $param );
                    }
                    ?>
                </table>
                <?php if ( ! empty( $advanced ) ) : ?>
                    <details style="margin: -6px 0 18px;">
                        <summary style="cursor: pointer; color: #2271b1;"><?php esc_html_e( '高级参数（多数情况不用动）', 'libre-compress' ); ?></summary>
                        <table class="form-table">
                            <?php
                            foreach ( $advanced as $key => $param ) {
                                $this->render_param_row( $key, $param );
                            }
                            ?>
                        </table>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * 按参数定义渲染一行设置
     *
     * @param string $key   参数键名
     * @param array  $param 参数定义
     */
    private function render_param_row( string $key, array $param ): void {
        $value = self::param( $key );
        $id    = 'libre-compress-' . $key;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $param['label'] ); ?></label></th>
            <td>
                <?php if ( 'mode' === $param['type'] ) : ?>
                    <label>
                        <input type="radio" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]" value="lossy" <?php checked( $value, 'lossy' ); ?>>
                        <?php esc_html_e( '有损压缩', 'libre-compress' ); ?>
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="radio" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]" value="lossless" <?php checked( $value, 'lossless' ); ?>>
                        <?php esc_html_e( '无损压缩', 'libre-compress' ); ?>
                    </label>
                <?php elseif ( 'choice' === $param['type'] ) : ?>
                    <select id="<?php echo esc_attr( $id ); ?>" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]">
                        <?php foreach ( $param['choices'] as $choice => $text ) : ?>
                            <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( (string) $value, (string) $choice ); ?>><?php echo esc_html( $text ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ( 'bool' === $param['type'] ) : ?>
                    <input type="hidden" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]" value="0">
                    <label>
                        <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (bool) $value ); ?>>
                        <?php echo esc_html( $param['desc'] ); ?>
                    </label>
                <?php else : ?>
                    <input type="number" id="<?php echo esc_attr( $id ); ?>" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $param['min'] ); ?>" max="<?php echo esc_attr( $param['max'] ); ?>" step="1" class="small-text">
                <?php endif; ?>

                <?php if ( ! empty( $param['flag'] ) ) : ?>
                    <code style="margin-left: 6px; font-size: 11px;"><?php echo esc_html( $param['flag'] ); ?></code>
                <?php endif; ?>

                <?php if ( 'bool' !== $param['type'] ) : ?>
                    <p class="description">
                        <?php
                        if ( 'int' === $param['type'] ) {
                            printf(
                                /* translators: 1: 最小取值，2: 最大取值，3: 默认值 */
                                esc_html__( '范围 %1$d-%2$d，默认 %3$d。', 'libre-compress' ),
                                (int) $param['min'],
                                (int) $param['max'],
                                (int) $param['default']
                            );
                            echo ' ';
                        }

                        echo esc_html( $param['desc'] );
                        ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * 构建压缩路径支持状态行
     *
     * groups 为"或"关系：任一组内依赖全部可用即视为支持该路径，
     * 组内为"与"关系，包含该路径实际调用的全部工具
     *
     * @return array[] 每行包含 label、deps_display、supported、missing
     */
    private function get_path_support_rows(): array {
        $tools            = libre_compress()->compressor->get_tools();
        $output_processor = libre_compress()->output_processor;

        $available = array();
        foreach ( $tools as $name => $tool ) {
            $available[ $name ] = $tool->is_tool_available();
        }

        // avifenc/avifdec 按可执行文件独立判定（同格式 AVIF 需要两者，目标输出只需要 avifenc）
        $available['jpegoptim'] = false !== $output_processor->find_local_tool( 'jpegoptim' );
        $available['avifenc'] = false !== $output_processor->find_local_tool( 'avifenc' );
        $available['avifdec'] = false !== $output_processor->find_local_tool( 'avifdec' );
        $available['gif2webp'] = false !== $output_processor->find_local_tool( 'gif2webp' );
        $available['ffmpeg']   = false !== $output_processor->find_local_tool( 'ffmpeg' );
        $available['gd']       = function_exists( 'imagecreatefromgif' ) && function_exists( 'imagepng' );

        // 动图处理用到的 libwebp 与 libavif 附带工具按可执行文件独立判定
        foreach ( array( 'ffprobe', 'webpmux', 'cjpegli', 'djpegli' ) as $helper ) {
            $available[ $helper ] = false !== $output_processor->find_local_tool( $helper );
        }

        $dep_labels = array(
            'jpegoptim' => 'jpegoptim',
            'cjpegli'   => 'cjpegli',
            'djpegli'   => 'djpegli',
            'pngquant'  => 'pngquant',
            'oxipng'    => 'oxipng',
            'cwebp'     => 'cwebp',
            'avifenc'   => 'avifenc',
            'avifdec'   => 'avifdec',
            'cjxl'      => 'cjxl',
            'gifsicle'  => 'gifsicle',
            'oxvg'      => 'oxvg',
            'gif2webp'  => 'gif2webp',
            'ffprobe'   => 'ffprobe',
            'webpmux'   => 'webpmux',
            'ffmpeg'    => 'ffmpeg',
            'gd'        => __( 'GD 扩展', 'libre-compress' ),
        );

        $rows_definition = array(
            array( 'label' => __( 'JPEG 有损压缩', 'libre-compress' ), 'groups' => array( array( 'jpegoptim' ), array( 'cjpegli', 'djpegli' ) ) ),
            array( 'label' => __( 'JPEG 无损压缩', 'libre-compress' ), 'groups' => array( array( 'jpegoptim' ) ) ),
            array( 'label' => __( 'PNG 有损压缩', 'libre-compress' ), 'groups' => array( array( 'pngquant' ) ) ),
            array( 'label' => __( 'PNG / APNG 无损压缩', 'libre-compress' ), 'groups' => array( array( 'oxipng' ) ) ),
            array( 'label' => __( 'WEBP 压缩', 'libre-compress' ), 'groups' => array( array( 'cwebp' ) ) ),
            array( 'label' => __( '动画 WebP 同格式压缩', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'webp', '' ) ),
            array( 'label' => __( 'AVIF 压缩', 'libre-compress' ), 'groups' => array( array( 'avifenc', 'avifdec' ) ) ),
            array( 'label' => __( '动画 AVIF 同格式压缩', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'avif', '' ) ),
            array( 'label' => __( 'JPEG XL 压缩', 'libre-compress' ), 'groups' => array( array( 'cjxl' ) ) ),
            array( 'label' => __( 'GIF 压缩', 'libre-compress' ), 'groups' => array( array( 'gifsicle' ) ) ),
            array( 'label' => __( 'SVG 压缩', 'libre-compress' ), 'groups' => array( array( 'oxvg' ) ) ),
            array( 'label' => __( 'PNG/JPG 压缩为 WebP', 'libre-compress' ), 'groups' => array( array( 'cwebp' ) ) ),
            array( 'label' => __( 'PNG/JPG 压缩为 AVIF', 'libre-compress' ), 'groups' => array( array( 'avifenc' ) ) ),
            array( 'label' => __( 'PNG/JPG 压缩为 JPEG XL', 'libre-compress' ), 'groups' => array( array( 'cjxl' ) ) ),
            array( 'label' => __( '静态 GIF 压缩为 WebP', 'libre-compress' ), 'groups' => array( array( 'gif2webp' ) ) ),
            array( 'label' => __( '静态 GIF 压缩为 AVIF', 'libre-compress' ), 'groups' => array( array( 'gd', 'avifenc' ) ) ),
            array( 'label' => __( '静态 GIF 压缩为 JPEG XL', 'libre-compress' ), 'groups' => array( array( 'cjxl' ) ) ),
            array( 'label' => __( '动画 GIF 压缩为 WebP', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'gif', 'webp' ) ),
            array( 'label' => __( '动画 GIF 压缩为 AVIF', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'gif', 'avif' ) ),
            array( 'label' => __( '动画 GIF 压缩为 JPEG XL', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'gif', 'jxl' ) ),
            array( 'label' => __( 'APNG 压缩为 WebP', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'png', 'webp' ) ),
            array( 'label' => __( 'APNG 压缩为 AVIF', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'png', 'avif' ) ),
            array( 'label' => __( 'APNG 压缩为 JPEG XL', 'libre-compress' ), 'groups' => Libre_Compress_Animation::required_tool_groups( 'png', 'jxl' ) ),
        );

        $rows = array();

        foreach ( $rows_definition as $definition ) {
            $supported    = false;
            $missing_all  = array();
            $deps_display = array();

            foreach ( $definition['groups'] as $group ) {
                $group_missing = array();
                $group_labels  = array();

                foreach ( $group as $dep ) {
                    $label          = isset( $dep_labels[ $dep ] ) ? $dep_labels[ $dep ] : $dep;
                    $group_labels[] = '<code>' . esc_html( $label ) . '</code>';

                    if ( empty( $available[ $dep ] ) ) {
                        $group_missing[] = $label;
                    }
                }

                if ( empty( $group_missing ) ) {
                    $supported = true;
                }

                $missing_all    = array_merge( $missing_all, $group_missing );
                $deps_display[] = implode( ' + ', $group_labels );
            }

            $rows[] = array(
                'label'        => $definition['label'],
                'deps_display' => implode( ' ' . __( '或', 'libre-compress' ) . ' ', $deps_display ),
                'supported'    => $supported,
                'missing'      => array_values( array_unique( $missing_all ) ),
            );
        }

        return $rows;
    }
}
