<?php
/**
 * 动画图像统一处理层
 *
 * 完整动画直接交给 gifsicle、oxipng、gif2webp、cjxl 或 FFmpeg。
 * 动画 WebP 用 libwebp 自带的 webpmux + cwebp 原样重组：webpmux 按帧抽出压缩
 * 码流，cwebp 重压，再按原图的时间、偏移、处置、混合与背景色装回去，全程不
 * 经过任何聚合引擎，也不解码成整画布像素。
 * 插件只选择工具、读取动画信息和校验结果，不拆帧、不重写透明像素。
 * 工具执行失败或播放信息不一致时保留原文件。
 *
 * @package LibreCompress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Libre_Compress_Animation {

    /**
     * 同格式压缩受支持的动画格式
     *
     * gif 由 gifsicle 原生处理（它自己就能读写动画，不需要本层），
     * jxl 由 cjxl 直接读动画 JXL 重压，同样不需要拆帧。
     *
     * @var array
     */
    private static $same_format_targets = array( 'webp', 'avif', 'jxl' );

    /**
     * 允许参与格式转换的动图源扩展名
     *
     * WebP / AVIF / JXL 无论静图动图都只做同格式压缩，不进出转换链路。
     *
     * @var array
     */
    private static $convert_sources = array( 'gif', 'png' );

    /* ---------------------------------------------------------------------
     * 设置读取
     * ------------------------------------------------------------------- */

    /**
     * APNG 是否强制走无损通道
     *
     * 有损 PNG 靠 pngquant 逐帧各自挑 256 色调色板，APNG 逐帧处理必然闪烁，
     * 所以这一项决定的是"检测到 APNG 就改用 oxipng 无损压"还是"干脆不压"。
     * 默认开启：只减体积不变画质，没有副作用。
     *
     * @return bool
     */
    public static function apng_lossless_enabled(): bool {
        $tools = get_option( 'libre_compress_tools', array() );

        return ! isset( $tools['apng_lossless'] ) || ! empty( $tools['apng_lossless'] );
    }

    /**
     * 是否优先使用 4:2:0 色度抽样
     *
     * @return bool
     */
    public static function prefer_420(): bool {
        $general = get_option( 'libre_compress_general', array() );

        return ! isset( $general['chroma_420'] ) || ! empty( $general['chroma_420'] );
    }

    /* ---------------------------------------------------------------------
     * 元信息读取
     * ------------------------------------------------------------------- */

    /**
     * 按扩展名快速判断是否为动图
     *
     * 只做容器层面的判定，拿不到结论时返回 false，避免把静图误判成动图。
     *
     * @param string $file_path 文件绝对路径
     * @return bool
     */
    public static function is_animated( string $file_path ): bool {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, array( 'gif', 'png', 'webp', 'avif', 'jxl' ), true ) ) {
            return false;
        }

        $plan = self::analyze( $file_path );

        return null !== $plan && $plan['frames'] > 1;
    }

    /**
     * 读取动图的帧数、每帧时长、循环次数、是否含透明
     *
     * 时长统一成毫秒（WebP 与 JPEG XL 的原生单位，GIF / APNG 换算过来也是精确值）。读不出结论返回 null，
     * 调用方一律按"不处理"处理，绝不带着猜测去覆盖用户的图。
     *
     * @param string $file_path 文件绝对路径
     * @return array|null frames/delays/loop/alpha/width/height/format
     */
    public static function analyze( string $file_path ): ?array {
        // 同一请求内刚写完的文件会命中 PHP 的 stat 缓存，filesize() 可能拿到旧值
        clearstatcache( true, $file_path );

        if ( ! file_exists( $file_path ) ) {
            return null;
        }

        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        switch ( $extension ) {
            case 'gif':
                $plan = self::analyze_gif( $file_path );
                break;
            case 'png':
                $plan = Libre_Compress_Compressor::is_apng( $file_path ) ? self::analyze_apng( $file_path ) : null;
                break;
            case 'webp':
                $plan = Libre_Compress_Compressor::is_animated_webp( $file_path ) ? self::analyze_webp( $file_path ) : null;
                break;
            case 'avif':
                $plan = self::analyze_avif( $file_path );
                break;
            case 'jxl':
                $plan = self::analyze_jxl( $file_path );
                break;
            default:
                $plan = null;
        }

        if ( null === $plan || $plan['frames'] < 1 || count( $plan['delays'] ) !== $plan['frames'] ) {
            return null;
        }

        $plan['format'] = $extension;

        return $plan;
    }

    /**
     * 用 gifsicle -I 读取 GIF 动画信息
     *
     * 不自己解析字节流：GIF 的延时藏在逐帧图形控制扩展里，
     * 帧数据又可能包含同样特征的字节，手写解析容易数错帧。gifsicle 本来就是 GIF 依赖。
     *
     * @param string $file_path GIF 路径
     * @return array|null
     */
    private static function analyze_gif( string $file_path ): ?array {
        $gifsicle = Libre_Compress_Output::find_local_tool( 'gifsicle' );

        if ( false === $gifsicle ) {
            return null;
        }

        $result = Libre_Compress_Tool_Base::run_command( array( $gifsicle, '-I', $file_path ), 30 );

        if ( empty( $result['success'] ) ) {
            return null;
        }

        $info = (string) $result['output'];

        if ( ! preg_match( '/\b(\d+)\s+images?\b/i', $info, $count ) ) {
            return null;
        }

        preg_match_all( '/\+\s*image\s+#\d+.*?(?=\+\s*image\s+#|\z)/is', $info, $image_blocks );

        if ( count( $image_blocks[0] ) !== (int) $count[1] ) {
            return null;
        }

        $frames = (int) $count[1];
        $delays = array();

        foreach ( $image_blocks[0] as $block ) {
            // gifsicle 对合法的 0 延时帧不输出 delay 行；缺少这一行就是 0 毫秒。
            $delays[] = preg_match( '/delay\s+([0-9.]+)\s*s/i', $block, $delay )
                ? self::milliseconds( (float) $delay[1] )
                : 0;
        }

        $loop          = 0;
        $loop_infinite = false;

        if ( preg_match( '/loop\s+(?:count\s+)?(\d+)(?:\s+times)?/i', $info, $finite ) ) {
            // gifsicle 打印的是"额外循环次数"，APNG 语义是"总播放次数"，这里统一存额外次数
            $loop = (int) $finite[1];
        } elseif ( false !== stripos( $info, 'loop forever' ) ) {
            $loop_infinite = true;
        }

        $size = preg_match( '/logical screen\s+(\d+)x(\d+)/i', $info, $screen ) ? array( (int) $screen[1], (int) $screen[2] ) : array( 0, 0 );

        return array(
            'frames'  => $frames,
            'delays'  => $delays,
            'loop'    => $loop,
            'loop_infinite' => $loop_infinite,
            'alpha'   => false !== stripos( $info, 'transparent' ),
            'icc'     => false !== stripos( $info, 'ICCRGBG1012' ),
            'width'   => $size[0],
            'height'  => $size[1],
        );
    }

    /**
     * 解析 APNG 的 acTL / fcTL 块
     *
     * @param string $file_path APNG 路径
     * @return array|null
     */
    private static function analyze_apng( string $file_path ): ?array {
        $chunks = self::png_chunks( $file_path );

        if ( 8 !== strlen( $chunks['acTL'] ?? '' ) || 13 !== strlen( $chunks['IHDR'] ) ) {
            return null;
        }

        $control  = unpack( 'Nframes/Nplays', $chunks['acTL'] );
        $frames   = (int) $control['frames'];
        $delays   = array();

        foreach ( $chunks['fcTL'] as $fctl ) {
            if ( 26 !== strlen( $fctl ) ) {
                return null;
            }
            // 注意：unpack 里 d1 会被解析成"double 类型取 1 个"，所以末尾两个字节必须用 C
            $delay = unpack( 'Nseq/Nwidth/Nheight/Nx/Ny/nnum/nden/Cdispose/Cblend', $fctl );

            if ( false === $delay ) {
                return null;
            }

            // APNG 规范规定分母为 0 时按 100 处理，而不是把文件判为损坏。
            $denominator = empty( $delay['den'] ) ? 100 : (int) $delay['den'];
            $delays[]    = self::milliseconds( $delay['num'] / $denominator );
        }

        if ( $frames < 1 || count( $delays ) !== $frames ) {
            return null;
        }

        $header = unpack( 'Nwidth/Nheight/Cdepth/Ccolor/Ccompress/Cfilter/Cinterlace', $chunks['IHDR'] );
        $color  = (int) $header['color'];

        return array(
            'frames'   => $frames,
            'delays'   => $delays,
            // APNG 的 num_plays 是总播放次数，0 为无限；转成与 GIF 一致的"额外次数"
            'loop'     => 0 === (int) $control['plays'] ? 0 : max( 0, (int) $control['plays'] - 1 ),
            'loop_infinite' => 0 === (int) $control['plays'],
            'alpha'    => in_array( $color, array( 4, 6 ), true ) || ! empty( $chunks['tRNS'] ),
            'icc'      => ! empty( $chunks['iCCP'] ),
            'width'    => (int) $header['width'],
            'height'   => (int) $header['height'],
            'depth'    => (int) $header['depth'],
        );
    }

    /**
     * 用 webpmux -info 读取动画 WebP
     *
     * @param string $file_path WebP 路径
     * @return array|null
     */
    private static function analyze_webp( string $file_path ): ?array {
        $webpmux = Libre_Compress_Output::find_local_tool( 'webpmux' );

        if ( false === $webpmux ) {
            return null;
        }

        $result = Libre_Compress_Tool_Base::run_command( array( $webpmux, '-info', $file_path ), 30 );

        if ( empty( $result['success'] ) ) {
            return null;
        }

        $info = (string) $result['output'];

        if ( ! preg_match( '/Number of frames:\s*(\d+)/i', $info, $count ) ) {
            return null;
        }

        $frames = (int) $count[1];

        // 帧表每行：序号 宽 高 alpha x y 时长(ms) dispose blend 大小 编码
        preg_match_all( '/^\s*\d+:\s+(\d+)\s+(\d+)\s+(yes|no)\s+(-?\d+)\s+(-?\d+)\s+(\d+)\s+/m', $info, $rows, PREG_SET_ORDER );

        if ( count( $rows ) !== $frames ) {
            return null;
        }

        $delays = array();
        $alpha  = false !== stripos( $info, 'transparency' );

        foreach ( $rows as $row ) {
            $delays[] = self::milliseconds( (int) $row[6] / 1000 );

            if ( 'yes' === $row[3] ) {
                $alpha = true;
            }
        }

        $loop_infinite = (bool) preg_match( '/Loop Count\s*:\s*0\b/i', $info );
        // WebP 保存总播放次数，统一换成 GIF / AVIF 使用的额外播放次数。
        $loop = preg_match( '/Loop Count\s*:\s*(\d+)/i', $info, $lc ) ? max( 0, (int) $lc[1] - 1 ) : null;
        $canvas = preg_match( '/Canvas size:\s*(\d+)\s*x\s*(\d+)/i', $info, $cs ) ? array( (int) $cs[1], (int) $cs[2] ) : array( (int) $rows[0][1], (int) $rows[0][2] );

        return array(
            'frames'  => $frames,
            'delays'  => $delays,
            'loop'    => $loop,
            'loop_infinite' => $loop_infinite,
            'alpha'   => $alpha,
            'icc'     => (bool) preg_match( '/Features present:[^\r\n]*ICC/i', $info ),
            'width'   => $canvas[0],
            'height'  => $canvas[1],
        );
    }

    /**
     * 用 avifdec --info 读取动画 AVIF
     *
     * @param string $file_path AVIF 路径
     * @return array|null
     */
    private static function analyze_avif( string $file_path ): ?array {
        $decoder = Libre_Compress_Output::find_local_tool( 'avifdec' );

        if ( false === $decoder ) {
            return null;
        }

        $result = Libre_Compress_Tool_Base::run_command( array( $decoder, '--info', $file_path ), 60 );

        if ( empty( $result['success'] ) ) {
            return null;
        }

        $info = (string) $result['output'];

        if ( ! preg_match( '/,\s*(\d+)\s+frames?\b/i', $info, $count ) && ! preg_match( '/Image\s+Count\s*:\s*(\d+)/i', $info, $count ) ) {
            return null;
        }

        $frames     = (int) $count[1];
        $timescale  = preg_match( '/(\d+)\s+timescales?\s+per\s+second/i', $info, $ts ) ? (int) $ts[1] : 0;
        $resolution = preg_match( '/Resolution\s*:\s*(\d+)x(\d+)/i', $info, $res ) ? array( (int) $res[1], (int) $res[2] ) : array( 0, 0 );

        // 每帧时长优先按 "duration 0.10 (10 timescales)" 取 timescales 换算，拿不到就按秒换算
        preg_match_all( '/duration\s+([0-9.]+)\s*\((\d+)\s+timescales?\)/i', $info, $per_frame, PREG_SET_ORDER );

        $delays = array();

        if ( count( $per_frame ) === $frames && $timescale > 0 ) {
            foreach ( $per_frame as $frame ) {
                $delays[] = self::milliseconds( (int) $frame[2] / $timescale );
            }
        } elseif ( preg_match_all( '/duration\s+([0-9.]+)/', $info, $secs ) && count( $secs[1] ) === $frames ) {
            foreach ( $secs[1] as $seconds ) {
                $delays[] = self::milliseconds( (float) $seconds );
            }
        } else {
            return null;
        }

        $alpha_line = preg_match( '/\* Alpha\s*:\s*([^\n]*)/i', $info, $alpha ) ? trim( $alpha[1] ) : '';
        $loop_infinite = (bool) preg_match( '/(?:Repetition|Repeat) Count\s*:\s*Infinite\b/i', $info );

        return array(
            'frames'    => $frames,
            'delays'    => $delays,
            'loop'      => $loop_infinite ? 0 : ( preg_match( '/(?:Repetition|Repeat) Count\s*:\s*(\d+)/i', $info, $rep ) ? (int) $rep[1] : null ),
            'loop_infinite' => $loop_infinite,
            'alpha'     => '' !== $alpha_line && false === stripos( $alpha_line, 'absent' ),
            'icc'       => (bool) preg_match( '/ICC Profile\s*:\s*(?!Absent\b)\S/i', $info ),
            'width'     => $resolution[0],
            'height'    => $resolution[1],
            'depth'     => preg_match( '/Bit Depth\s*:\s*(\d+)/i', $info, $depth ) ? (int) $depth[1] : 0,
            'premultiplied' => (bool) preg_match( '/^Premultiplied\b/i', $alpha_line ),
        );
    }

    /**
     * 用 jxlinfo -v 读取动画 JXL
     *
     * @param string $file_path JXL 路径
     * @return array|null
     */
    private static function analyze_jxl( string $file_path ): ?array {
        $jxlinfo = Libre_Compress_Output::find_local_tool( 'jxlinfo' );

        if ( false === $jxlinfo ) {
            return null;
        }

        $result = Libre_Compress_Tool_Base::run_command( array( $jxlinfo, '-v', $file_path ), 60 );
        $info   = (string) $result['output'];

        if ( ! preg_match( '/Have animation:\s*1/i', $info ) ) {
            return null;
        }

        preg_match_all( '/duration:\s*([0-9.]+)\s*ms/i', $info, $delays );

        if ( empty( $delays[1] ) ) {
            return null;
        }

        $frames = count( $delays[1] );
        $res    = preg_match( '/(\d+)\s*x\s*(\d+)/', $info, $size ) ? array( (int) $size[1], (int) $size[2] ) : array( 0, 0 );

        $loop_infinite = false;
        $loop          = 0;

        if ( preg_match( '/Num loops:\s*(\d+)/i', $info, $loops ) ) {
            $loop = (int) $loops[1];
            $loop_infinite = 0 === $loop && false !== stripos( $info, '(looping)' );
        }

        return array(
            'frames'  => $frames,
            'delays'  => array_map(
                static function ( $ms ) {
                    return self::milliseconds( (float) $ms / 1000 );
                },
                $delays[1]
            ),
            'loop'    => $loop,
            'loop_infinite' => $loop_infinite,
            'alpha'   => false !== stripos( $info, '+Alpha' ) || false !== stripos( $info, 'Alpha: ' ),
            'width'   => $res[0],
            'height'  => $res[1],
        );
    }

    /**
     * 秒换算成毫秒
     *
     * 内部时长单位统一为毫秒：WebP 原生就是毫秒，AVIF 用 1000 的时间基准，
     * JPEG XL 由 jxlinfo 直接报毫秒，GIF / APNG 换算过来都是精确值。
     *
     * @param float $seconds 秒
     * @return int
     */
    private static function milliseconds( float $seconds ): int {
        return max( 0, (int) round( $seconds * 1000 ) );
    }

    /* ---------------------------------------------------------------------
     * 能力判定
     * ------------------------------------------------------------------- */

    /**
     * 该路径需要的命令行工具
     *
     * 返回的是"或"关系的多组，组内是"与"关系；目标为空表示同格式压缩。
     * 能力判定与提示文案共用这一张表，避免同一件事在两处各写一遍。
     *
     * @param string $source 源扩展名
     * @param string $target 目标扩展名
     * @return string[][]
     */
    public static function required_tool_groups( string $source, string $target = '' ): array {
        $target = '' === $target ? $source : $target;
        $readers = array( 'gif' => 'gifsicle', 'webp' => 'webpmux', 'avif' => 'avifdec', 'jxl' => 'jxlinfo' );
        $group = array();

        if ( isset( $readers[ $source ] ) ) {
            $group[] = $readers[ $source ];
        }

        if ( 'gif' === $source && 'webp' === $target ) {
            $group[] = 'gif2webp';
        } elseif ( 'webp' === $target && 'png' === $source ) {
            $group[] = 'ffmpeg';
        } elseif ( 'webp' === $target && 'webp' === $source ) {
            $group[] = 'cwebp';
        } elseif ( 'avif' === $target && in_array( $source, array( 'gif', 'png', 'avif' ), true ) ) {
            $group[] = 'ffmpeg';
            if ( 'avif' === $source ) {
                $group[] = 'ffprobe';
            }
        } elseif ( 'jxl' === $target && in_array( $source, array( 'gif', 'png', 'jxl' ), true ) ) {
            $group[] = 'cjxl';
        } else {
            return array();
        }

        if ( isset( $readers[ $target ] ) ) {
            $group[] = $readers[ $target ];
        }

        return array( array_values( array_unique( $group ) ) );
    }

    /**
     * 该路径的工具是否至少配齐了一组
     *
     * @param string $source 源扩展名
     * @param string $target 目标扩展名，空字符串表示同格式压缩
     * @return bool
     */
    public static function tools_available( string $source, string $target = '' ): bool {
        foreach ( self::required_tool_groups( $source, $target ) as $group ) {
            $complete = true;

            foreach ( $group as $name ) {
                if ( false === self::tool( $name ) ) {
                    $complete = false;
                    break;
                }
            }

            if ( $complete ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 该格式的动画能否同格式压缩
     *
     * png 不在这里：APNG 由 native_tool_for() 决定交给 oxipng 还是直接跳过。
     *
     * @param string $extension 扩展名
     * @return bool
     */
    public static function same_format_supported( string $extension ): bool {
        if ( ! in_array( $extension, self::$same_format_targets, true ) ) {
            return false;
        }

        return self::tools_available( $extension );
    }

    /**
     * 该动图应交给哪个常规单文件工具原地处理
     *
     * APNG 属于这种情况：实测 oxipng 在 --strip=safe/--strip=none 下会完整保留
     * acTL/fcTL/fdAT 的帧结构与每帧时长（--strip=all 会把动图毁成一张静图，
     * 工具类本来就不用它），所以整只 APNG 直接优化即可，不必拆帧。
     * 有损 PNG 模式仍危险——pngquant 逐帧各自选调色板，动图会闪烁，
     * 而 APNG 规范要求所有帧共用一张全局调色板，因此只有用户明确开启
     * "APNG 自动无损压缩"时才在有损模式下走这里。
     *
     * @param string $extension 扩展名
     * @return string 工具注册名，空字符串表示不交给单文件工具
     */
    public static function native_tool_for( string $extension ): string {
        if ( 'gif' === $extension ) {
            return false !== self::tool( 'gifsicle' ) ? 'gifsicle' : '';
        }

        if ( 'png' !== $extension ) {
            return '';
        }

        $settings = get_option( 'libre_compress_tools', array() );

        if ( 'lossless' !== ( isset( $settings['png_mode'] ) ? $settings['png_mode'] : 'lossy' ) && ! self::apng_lossless_enabled() ) {
            return '';
        }

        $tools = libre_compress()->compressor->get_tools();

        return isset( $tools['oxipng'] ) && $tools['oxipng']->is_tool_available() ? 'oxipng' : '';
    }

    /**
     * 动画源能否转换成目标格式
     *
     * 只有 PNG（APNG）与 GIF 参与转换，且必须至少一组工具齐备。
     *
     * @param string $source 源扩展名
     * @param string $target 目标扩展名
     * @return bool
     */
    public static function convert_supported( string $source, string $target ): bool {
        if ( ! in_array( $target, array( 'webp', 'avif', 'jxl' ), true ) || $source === $target ) {
            return false;
        }

        if ( ! in_array( $source, self::$convert_sources, true ) ) {
            return false;
        }

        return self::tools_available( $source, $target );
    }

    /**
     * 查找命令行工具（复用转换器的查找逻辑：插件目录优先，其次系统 PATH）
     *
     * @param string $name 可执行文件名
     * @return string|false
     */
    private static function tool( string $name ) {
        return Libre_Compress_Output::find_local_tool( $name );
    }

    /* ---------------------------------------------------------------------
     * 执行：同格式压缩
     * ------------------------------------------------------------------- */

    /**
     * 动画同格式压缩入口
     *
     * @param string $file_path 文件绝对路径
     * @param array  $options   预留的选项覆盖位
     * @return array 与工具类 compress() 相同的结果结构
     */
    public static function compress_same_format( string $file_path, array $options = array() ): array {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $original  = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;

        $fail = static function ( string $message ) use ( $original ) {
            return array(
                'success'         => false,
                'message'         => $message,
                'original_size'   => $original,
                'compressed_size' => $original,
            );
        };

        if ( ! self::same_format_supported( $extension ) ) {
            return $fail( self::skip_reason( $extension ) );
        }

        $output = $file_path . '.lc-anim-' . wp_generate_password( 12, false, false ) . '.' . $extension;
        $result = self::process( $file_path, $extension, $output );

        if ( ! $result['success'] ) {
            return $fail( $result['message'] );
        }

        if ( ! rename( $output, $file_path ) ) {
            self::cleanup( array( $output ) );
            return $fail( __( '压缩结果替换失败，已保留原文件', 'libre-compress' ) );
        }

        clearstatcache( true, $file_path );

        return array(
            'success' => true,
            'message' => __( '压缩成功', 'libre-compress' ),
            'original_size' => $original,
            'compressed_size' => (int) filesize( $file_path ),
        );
    }

    /**
     * 把完整动画交给目标格式工具，源文件始终不动。
     */
    public static function convert( string $source, string $target, string $output ): array {
        $format = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
        if ( ! self::convert_supported( $format, $target ) ) {
            return array( 'success' => false, 'message' => self::skip_reason( $format, $target ), 'tool' => 'animation' );
        }
        return self::process( $source, $target, $output );
    }

    /**
     * 共用完整动画处理流程，失败只删除本次生成的结果。
     */
    private static function process( string $source, string $target, string $output ): array {
        $result = array( 'success' => false, 'message' => '', 'tool' => 'animation' );
        $plan = self::analyze( $source );

        // 调用方必须给出尚不存在的新路径，不能覆盖原图或其他文件。
        if ( file_exists( $output ) || null === $plan || $plan['frames'] < 2 ) {
            $result['message'] = __( '无法安全处理这张动图，已保留原文件', 'libre-compress' );
            return $result;
        }

        if ( 'jxl' === $target && 'jxl' !== $plan['format'] && empty( $plan['loop_infinite'] ) ) {
            $result['message'] = __( 'JXL 工具无法保留这张动图的有限播放次数，已跳过以保护动画内容', 'libre-compress' );
            return $result;
        }

        // 这些通道目前按 8 位直通透明编码，不能悄悄降低高位深或改变预乘透明的含义。
        if ( in_array( $target, array( 'webp', 'avif' ), true )
            && ( ( $plan['depth'] ?? 8 ) > 8 || ! empty( $plan['premultiplied'] ) ) ) {
            $result['message'] = __( '当前动画工具无法完整保留这张图片的颜色精度或透明方式，已保留原文件', 'libre-compress' );
            return $result;
        }

        // FFmpeg 的 WebP 输出不会写回 ICC，不能以可能偏色的结果替换原图。
        if ( 'webp' === $target && 'png' === $plan['format'] && ! empty( $plan['icc'] ) ) {
            $result['message'] = __( '当前动画工具无法保留这张图片的颜色配置，已保留原文件以避免偏色', 'libre-compress' );
            return $result;
        }

        // 动画 WebP 同格式压缩自己走一条链路，不经过聚合引擎，也不解码整画布。
        $reassembled = ( 'webp' === $target && 'webp' === $plan['format'] );

        if ( $reassembled ) {
            $native  = self::recompress_animated_webp( $source, $output, $plan );
            $ran     = ! empty( $native['success'] );
            $command = array( 'webpmux' );
        } else {
            $command = self::build_native_command( $source, $target, $output, $plan );
            if ( false === $command ) {
                $result['message'] = __( '当前工具无法完整处理这张动画，已保留原文件', 'libre-compress' );
                return $result;
            }

            $run = Libre_Compress_Tool_Base::run_command( $command, 300 );
            $ran = ! empty( $run['success'] );
        }

        clearstatcache( true, $output );
        if ( ! $ran || ! file_exists( $output ) || 0 === (int) filesize( $output ) ) {
            self::cleanup( array( $output ) );
            if ( $reassembled ) {
                $result['message'] = (string) $native['message'];
            } else {
                $result['message'] = __( '动图处理命令执行失败', 'libre-compress' );
            }
            return $result;
        }

        if ( 'webp' === $target && 'png' === $plan['format'] ) {
            // libwebp_anim 会推算末帧时长，交给 webpmux 按原图校正播放信息；不接触图像帧。
            $timed = $output . '.lc-timing.webp';
            if ( file_exists( $timed ) ) {
                self::cleanup( array( $output ) );
                $result['message'] = __( '无法安全处理这张动图，已保留原文件', 'libre-compress' );
                return $result;
            }
            $timing = Libre_Compress_Tool_Base::run_command( array(
                self::tool( 'webpmux' ), '-duration', (string) end( $plan['delays'] ) . ',' . $plan['frames'],
                $output, '-o', $timed,
            ), 60 );
            if ( empty( $timing['success'] ) || ! rename( $timed, $output ) ) {
                self::cleanup( array( $timed, $output ) );
                $result['message'] = __( '动图处理命令执行失败', 'libre-compress' );
                return $result;
            }
        }

        if ( 'webp' === $target && 'webp' === $plan['format'] && ! empty( $plan['icc'] )
            && ! self::restore_webp_profile( $source, $output ) ) {
            self::cleanup( array( $output ) );
            $result['message'] = __( '当前动画工具无法保留这张图片的颜色配置，已保留原文件以避免偏色', 'libre-compress' );
            return $result;
        }

        if ( ! self::matches_plan( $plan, self::analyze( $output ) ) ) {
            self::cleanup( array( $output ) );
            $result['message'] = __( '处理结果的动画、尺寸、播放次数、透明背景或颜色配置与原图不一致，已保留原文件', 'libre-compress' );
            return $result;
        }

        $result['success'] = true;

        if ( 'jxl' === $target ) {
            $result['tool'] = 'cjxl';
        } elseif ( $reassembled ) {
            $result['tool'] = 'webpmux';
        } else {
            $result['tool'] = basename( $command[0], '.exe' );
        }

        return $result;
    }

    /**
     * 工具接收完整动画，不生成逐帧临时图片。
     */
    private static function build_native_command( string $source, string $target, string $output, array $plan ) {
        $settings = get_option( 'libre_compress_tools', array() );
        if ( 'jxl' === $target ) {
            return Libre_Compress_Cjxl::build_command( $source, $output );
        }

        if ( 'avif' === $target ) {
            return self::build_avif_command( $source, $output, $plan );
        }

        if ( 'gif' === $plan['format'] ) {
            return self::gif2webp_command( $source, $output );
        }

        // 剩下的只有动画 WebP 同格式压缩，它由 recompress_animated_webp() 用 libwebp 自带的 webpmux 与 cwebp 处理。
        if ( 'png' !== $plan['format'] ) {
            return false;
        }

        $lossless = 'lossless' === ( $settings['webp_mode'] ?? 'lossy' );
        $quality = max( 0, min( 100, absint( $settings['webp_quality'] ?? 80 ) ) );

        return array(
            self::tool( 'ffmpeg' ), '-nostdin', '-v', 'error', '-f', 'apng', '-ignore_loop', '1', '-i', $source,
            '-c:v', 'libwebp_anim', '-lossless', $lossless ? '1' : '0', '-quality', (string) $quality,
            '-compression_level', (string) Libre_Compress_Settings::tool_speed( 'webp_method' ),
            '-enc_time_base', '1:1000', '-fps_mode', 'passthrough', '-map_metadata', '-1',
            '-loop', $plan['loop_infinite'] ? '0' : (string) ( $plan['loop'] + 1 ), '-f', 'webp', $output,
        );
    }

    /**
     * 构建 gif2webp 命令
     *
     * 动画 GIF 与静态 GIF 共用一个出口：gif2webp 自己读整只 GIF，多帧出来仍是动画，
     * 单帧出来就是一张静图，两种都不需要拆帧，也不经过任何聚合引擎。
     *
     * @param string $source GIF 源路径
     * @param string $output 输出路径
     * @return array|false 参数数组，工具不可用时返回 false
     */
    public static function gif2webp_command( string $source, string $output ) {
        $gif2webp = self::tool( 'gif2webp' );

        if ( false === $gif2webp ) {
            return false;
        }

        $settings = get_option( 'libre_compress_tools', array() );
        $lossless = 'lossless' === ( $settings['webp_mode'] ?? 'lossy' );
        $quality  = max( 0, min( 100, absint( $settings['webp_quality'] ?? 80 ) ) );

        $command = array( $gif2webp, '-m', (string) Libre_Compress_Settings::tool_speed( 'gif2webp_method' ) );

        if ( ! $lossless ) {
            $command = array_merge( $command, array( '-lossy', '-q', (string) $quality ) );
        }

        $command = array_merge( $command, array( '-metadata', Libre_Compress_Settings::strips_metadata() ? 'icc' : 'all' ) );

        return array_merge( $command, array( $source, '-o', $output ) );
    }

    /**
     * 用 libwebp 自带工具重压整张动画 WebP
     *
     * webpmux -get frame 抽出来的是压缩码流（VP8 / VP8L）而不是像素，cwebp 按设置
     * 重压这一帧，再由 webpmux 按原图的时间、偏移、处置方式、混合方式、循环次数和
     * 背景色原样装回去。alpha 平面与颜色平面各自独立，cwebp 的 -alpha_q 默认即为
     * 无损，透明通道不会因为重压而损失；-exact 保证透明区域的 RGB 原样保留，动画的
     * 帧间差分才不会被这点改动放大成杂边。
     *
     * @param string $source 原动画路径
     * @param string $output 结果路径（调用方保证尚不存在）
     * @param array  $plan   原动画信息
     * @return array{success:bool,message:string}
     */
    private static function recompress_animated_webp( string $source, string $output, array $plan ): array {
        $failed = static function ( string $message ) {
            return array( 'success' => false, 'message' => $message );
        };

        $info = Libre_Compress_Tool_Base::run_command( array( self::tool( 'webpmux' ), '-info', $source ), 60 );

        if ( empty( $info['success'] ) ) {
            return $failed( __( '无法读取这张动图的帧信息，已保留原文件', 'libre-compress' ) );
        }

        $parsed = self::parse_webp_frames( (string) $info['output'] );

        if ( null === $parsed || count( $parsed['frames'] ) !== $plan['frames'] ) {
            return $failed( __( '无法读取这张动图的帧信息，已保留原文件', 'libre-compress' ) );
        }

        // webpmux 按帧的包围盒推导画布尺寸：盖不满整张画布的动图（常见的透明留白）
        // 重组后画布会被裁小，这种图不动。
        if ( ! self::frames_cover_canvas( $parsed['frames'], $plan['width'], $plan['height'] ) ) {
            return $failed( __( '这张动图有透明留白区域，重组会改变画布尺寸，已保留原文件', 'libre-compress' ) );
        }

        $settings = get_option( 'libre_compress_tools', array() );
        $lossless = 'lossless' === ( $settings['webp_mode'] ?? 'lossy' );
        $quality  = max( 0, min( 100, absint( $settings['webp_quality'] ?? 80 ) ) );
        $work     = rtrim( sys_get_temp_dir(), '/\\' ) . '/lc-webp-' . wp_generate_password( 12, false, false );

        if ( ! wp_mkdir_p( $work ) ) {
            return $failed( __( '无法创建动图处理的临时目录，已保留原文件', 'libre-compress' ) );
        }

        $encoded = array();

        foreach ( $parsed['frames'] as $frame ) {
            $raw = $work . '/src-' . $frame['index'] . '.webp';
            $new = $work . '/new-' . $frame['index'] . '.webp';

            $get = Libre_Compress_Tool_Base::run_command(
                array( self::tool( 'webpmux' ), '-get', 'frame', (string) $frame['index'], $source, '-o', $raw ),
                60
            );

            if ( empty( $get['success'] ) || ! is_file( $raw ) || 0 === (int) filesize( $raw ) ) {
                self::remove_directory( $work );
                return $failed( __( '动图处理命令执行失败', 'libre-compress' ) );
            }

            $encode = array( self::tool( 'cwebp' ) );

            if ( $lossless ) {
                $encode = array_merge( $encode, array(
                    '-lossless',
                    '-z',
                    (string) Libre_Compress_Settings::tool_speed( 'webp_lossless_level' ),
                ) );
            } else {
                $encode = array_merge( $encode, array(
                    '-q',
                    (string) $quality,
                    '-m',
                    (string) Libre_Compress_Settings::tool_speed( 'webp_method' ),
                ) );

                if ( self::prefer_420() ) {
                    $encode[] = '-sharp_yuv';
                }
            }

            $encode = array_merge( $encode, array( '-exact', $raw, '-o', $new ) );

            $result = Libre_Compress_Tool_Base::run_command( $encode, 300 );

            if ( empty( $result['success'] ) || ! is_file( $new ) || 0 === (int) filesize( $new ) ) {
                self::remove_directory( $work );
                return $failed( __( '动图处理命令执行失败', 'libre-compress' ) );
            }

            $encoded[] = $new;
        }

        $assemble = array( self::tool( 'webpmux' ) );

        foreach ( $parsed['frames'] as $position => $frame ) {
            // 帧参数：文件 + 时长 + 横向偏移 + 纵向偏移 + 处置方式(0 不处置 / 1 还原背景) ± 混合方式
            // 帧的路径与 "+时长+横偏移+纵偏移+处置±混合" 必须是两个独立参数，拼成一串 webpmux 会当成打不开的文件名。
            $assemble[] = '-frame';
            $assemble[] = $encoded[ $position ];
            $assemble[] = '+' . $frame['duration'] . '+' . $frame['x'] . '+' . $frame['y']
                . '+' . $frame['dispose'] . ( $frame['blend'] ? '+b' : '-b' );
        }

        $assemble[] = '-loop';
        $assemble[] = (string) ( ! empty( $plan['loop_infinite'] ) ? 0 : $plan['loop'] + 1 );

        if ( null !== $parsed['background'] ) {
            // 不显式写回背景色，webpmux 会把它重置成不透明白，透明背景就没了。
            $assemble[] = '-bgcolor';
            $assemble[] = $parsed['background'];
        }

        $assemble[] = '-o';
        $assemble[] = $output;

        $built = Libre_Compress_Tool_Base::run_command( $assemble, 300 );
        self::remove_directory( $work );

        if ( empty( $built['success'] ) || ! is_file( $output ) || 0 === (int) filesize( $output ) ) {
            return $failed( __( '动图处理命令执行失败', 'libre-compress' ) );
        }

        return array( 'success' => true, 'message' => '' );
    }

    /**
     * 解析 webpmux -info 的帧表
     *
     * @param string $info webpmux -info 的完整输出
     * @return array|null 每帧的序号、尺寸、时长、偏移、处置与混合方式；背景色单独返回，读不出返回 null
     */
    private static function parse_webp_frames( string $info ): ?array {
        if ( ! preg_match( '/Number of frames:\s*(\d+)/i', $info, $count ) ) {
            return null;
        }

        preg_match_all(
            '/^\s*(\d+):\s+(\d+)\s+(\d+)\s+(yes|no)\s+(-?\d+)\s+(-?\d+)\s+(\d+)\s+(\S+)\s+(yes|no)\s+/m',
            $info,
            $rows,
            PREG_SET_ORDER
        );

        if ( count( $rows ) !== (int) $count[1] ) {
            return null;
        }

        // 背景色是 0xAARRGGBB，webpmux -bgcolor 要的是 A,R,G,B 四个十进制数。
        $background = preg_match( '/Background color\s*:\s*0x([0-9A-Fa-f]{8})/', $info, $bg )
            ? implode( ',', array_map( 'hexdec', str_split( strtoupper( $bg[1] ), 2 ) ) )
            : null;

        $frames = array();

        foreach ( $rows as $row ) {
            $frames[] = array(
                'index'    => (int) $row[1],
                'width'    => (int) $row[2],
                'height'   => (int) $row[3],
                'x'        => (int) $row[5],
                'y'        => (int) $row[6],
                'duration' => (int) $row[7],
                'dispose'  => 'none' === strtolower( $row[8] ) ? 0 : 1,
                'blend'    => 'yes' === strtolower( $row[9] ),
            );
        }

        return array( 'frames' => $frames, 'background' => $background );
    }

    /**
     * 判断所有帧拼起来能否盖满整张画布
     *
     * 按行做矩形并集判断即可，不需要逐像素，也不接触任何图像数据。
     *
     * @param array $frames 帧列表
     * @param int   $width  画布宽度
     * @param int   $height 画布高度
     * @return bool 能否盖满
     */
    private static function frames_cover_canvas( array $frames, int $width, int $height ): bool {
        if ( $width < 1 || $height < 1 ) {
            return false;
        }

        $rows = array_fill( 0, $height, array() );

        foreach ( $frames as $frame ) {
            $left   = $frame['x'];
            $top    = $frame['y'];
            $right  = $frame['x'] + $frame['width'];
            $bottom = $frame['y'] + $frame['height'];

            // 帧跑到画布外面时包围盒会比原画布大，尺寸同样对不上。
            if ( $left < 0 || $top < 0 || $right > $width || $bottom > $height || $right <= $left || $bottom <= $top ) {
                return false;
            }

            for ( $y = $top; $y < $bottom; $y++ ) {
                $rows[ $y ][] = array( $left, $right );
            }
        }

        foreach ( $rows as $spans ) {
            if ( empty( $spans ) ) {
                return false;
            }

            usort( $spans, static function ( $a, $b ) {
                return $a[0] <=> $b[0];
            } );

            $covered = 0;

            foreach ( $spans as $span ) {
                if ( $span[0] > $covered ) {
                    return false;
                }

                $covered = max( $covered, $span[1] );
            }

            if ( $covered < $width ) {
                return false;
            }
        }

        return true;
    }

    /** 清理本次动图处理用的临时目录。 */
    private static function remove_directory( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }

        foreach ( (array) glob( $path . '/*' ) as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }

        rmdir( $path );
    }

    /**
     * FFmpeg 内部处理颜色和透明背景，全程不把动画帧交给 PHP。
     */
    private static function build_avif_command( string $source, string $output, array $plan ) {
        $settings = get_option( 'libre_compress_tools', array() );
        $lossless = 'lossless' === ( $settings['avif_mode'] ?? 'lossy' );
        $quality = max( 0, min( 100, absint( $settings['avif_quality'] ?? 80 ) ) );
        $command = array( self::tool( 'ffmpeg' ), '-nostdin', '-v', 'error' );

        if ( 'gif' === $plan['format'] ) {
            // 保留 GIF 的零延时与透明底色，不套用播放器的最小延时。
            $command = array_merge( $command, array( '-min_delay', '0', '-ignore_loop', '1' ) );
        } elseif ( 'png' === $plan['format'] ) {
            $command = array_merge( $command, array( '-f', 'apng', '-ignore_loop', '1' ) );
        }
        $command = array_merge( $command, array( '-i', $source ) );

        $input = '[0:v:0]';
        $alpha_input = '';
        if ( 'avif' === $plan['format'] ) {
            // AVIF 同时可能有封面图与动画轨道，只选择完整动画，不能误压封面。
            $probe = Libre_Compress_Tool_Base::run_command( array( self::tool( 'ffprobe' ), '-v', 'error', '-show_streams', '-of', 'json', $source ), 30 );
            $info = json_decode( (string) $probe['output'], true );
            if ( empty( $probe['success'] ) || empty( $info['streams'] ) ) {
                return false;
            }
            $colors = array();
            $alphas = array();
            foreach ( $info['streams'] as $stream ) {
                if ( 'video' !== ( $stream['codec_type'] ?? '' ) || (int) ( $stream['nb_frames'] ?? 0 ) !== $plan['frames']
                    || (int) $stream['width'] !== $plan['width'] || (int) $stream['height'] !== $plan['height'] ) {
                    continue;
                }
                if ( 0 === strpos( $stream['pix_fmt'] ?? '', 'gray' ) ) {
                    $alphas[] = (int) $stream['index'];
                } else {
                    $colors[] = (int) $stream['index'];
                }
            }
            if ( 1 !== count( $colors ) || ( $plan['alpha'] && 1 !== count( $alphas ) ) ) {
                return false;
            }
            $input = '[0:' . $colors[0] . ']';
            if ( $plan['alpha'] ) {
                $alpha_input = '[0:' . $alphas[0] . ']';
            }
        }

        $pixel_format = $lossless ? 'gbrp' : ( self::prefer_420() ? 'yuv420p' : 'yuv444p' );
        if ( 'avif' === $plan['format'] && $lossless ) {
            // 无损重压直接使用原颜色与透明轨道，避免颜色换算；颜色配置仍由输出校验把关。
            $command = array_merge( $command, array( '-map', '0:' . $colors[0] ) );
            if ( $plan['alpha'] ) {
                $command = array_merge( $command, array( '-map', '0:' . $alphas[0], '-colorspace:v:1', 'bt709' ) );
            }
        } elseif ( $plan['alpha'] ) {
            // 透明背景独立无损编码，避免有损颜色压缩把半透明边缘弄坏。
            $filter = '' !== $alpha_input
                ? $input . 'format=' . $pixel_format . '[c];' . $alpha_input . 'format=gray,setparams=colorspace=bt709[a]'
                : $input . 'split[color][alpha];[color]format=' . $pixel_format . '[c];[alpha]alphaextract,format=gray,setparams=colorspace=bt709[a]';
            $command = array_merge( $command, array( '-filter_complex', $filter, '-map', '[c]', '-map', '[a]' ) );
        } else {
            $command = array_merge( $command, array( '-filter_complex', $input . 'format=' . $pixel_format . '[c]', '-map', '[c]' ) );
        }

        $command = array_merge( $command, array(
            '-c:v', 'libaom-av1',
            '-crf', (string) ( $lossless ? 0 : max( 1, (int) round( ( 100 - $quality ) * 63 / 100 ) ) ),
            '-b:v', '0', '-cpu-used', (string) min( 8, Libre_Compress_Settings::tool_speed( 'avif_speed' ) ),
            '-threads', '4',
        ) );
        if ( $plan['alpha'] ) {
            $command = array_merge( $command, array( '-crf:v:1', '0' ) );
        }
        $command = array_merge( $command, array(
            '-enc_time_base', '1:1000', '-fps_mode', 'passthrough',
            '-map_metadata', Libre_Compress_Settings::strips_metadata() ? '-1' : '0',
            '-loop', ! empty( $plan['loop_infinite'] ) ? '0' : (string) ( $plan['loop'] + 1 ),
            '-f', 'avif', $output,
        ) );
        return $command;
    }

    /** 使用 WebP 自带工具原样复制颜色配置，不读取或重建动画帧。 */
    private static function restore_webp_profile( string $source, string $output ): bool {
        $profile = $output . '.lc-profile.icc';
        $tagged = $output . '.lc-profile.webp';
        if ( file_exists( $profile ) || file_exists( $tagged ) ) {
            return false;
        }
        $extract = Libre_Compress_Tool_Base::run_command( array(
            self::tool( 'webpmux' ), '-get', 'icc', $source, '-o', $profile,
        ), 30 );
        $ok = false;
        if ( ! empty( $extract['success'] ) ) {
            $attach = Libre_Compress_Tool_Base::run_command( array(
                self::tool( 'webpmux' ), '-set', 'icc', $profile, $output, '-o', $tagged,
            ), 30 );
            $ok = ! empty( $attach['success'] ) && rename( $tagged, $output );
        }
        self::cleanup( array( $profile, $tagged ) );
        return $ok;
    }

    /**
     * 校验动画信息；读不出循环次数时不能把猜测当作成功。
     */
    public static function matches_plan( array $expected, ?array $actual ): bool {
        if ( null === $actual || $actual['frames'] !== $expected['frames']
            || $expected['width'] < 1 || $expected['height'] < 1
            || $actual['width'] !== $expected['width'] || $actual['height'] !== $expected['height']
            || $actual['loop_infinite'] !== $expected['loop_infinite']
            || ( empty( $expected['loop_infinite'] ) && ( null === $expected['loop'] || null === $actual['loop'] || $expected['loop'] !== $actual['loop'] ) )
            || ( $expected['alpha'] && ! $actual['alpha'] )
            || ( ! empty( $expected['icc'] ) && empty( $actual['icc'] ) ) ) {
            return false;
        }
        // 本层使用的工具均能表达毫秒，逐帧只容许换算造成的 1 毫秒误差。
        foreach ( $expected['delays'] as $index => $delay ) {
            if ( ! isset( $actual['delays'][ $index ] ) || abs( $actual['delays'][ $index ] - $delay ) > 1 ) {
                return false;
            }
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * PNG / APNG 信息读取
     * ------------------------------------------------------------------- */

    /**
     * 读取 PNG 块
     *
     * @param string $path PNG 路径
     * @return array PNG 头、动画控制信息及颜色配置声明
     */
    private static function png_chunks( string $path ): array {
        $data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if ( false === $data || "\x89PNG\r\n\x1a\n" !== substr( $data, 0, 8 ) ) {
            return array( 'IHDR' => '', 'fcTL' => array() );
        }

        $offset = 8;
        $chunks = array(
            'IHDR'   => '',
            'fcTL'   => array(),
        );

        while ( $offset + 12 <= strlen( $data ) ) {
            $length = unpack( 'N', substr( $data, $offset, 4 ) );
            $length = (int) $length[1];
            $type   = substr( $data, $offset + 4, 4 );
            if ( $length > strlen( $data ) - $offset - 12 ) {
                return array( 'IHDR' => '', 'fcTL' => array() );
            }
            $body   = substr( $data, $offset + 8, $length );

            if ( in_array( $type, array( 'IHDR', 'acTL', 'tRNS', 'iCCP' ), true ) ) {
                $chunks[ $type ] = $body;
            } elseif ( 'fcTL' === $type ) {
                $chunks['fcTL'][] = $body;
            } elseif ( 'IEND' === $type ) {
                break;
            }

            $offset += 12 + $length;
        }

        return $chunks;
    }

    /* ---------------------------------------------------------------------
     * 辅助
     * ------------------------------------------------------------------- */

    /**
     * 不支持时的原因文案
     *
     * @param string $extension 扩展名
     * @param string $target    目标格式
     * @return string
     */
    public static function skip_reason( string $extension, string $target = '' ): string {
        if ( 'png' === $extension && '' === $target ) {
            return __( '有损 PNG 压缩会逐帧各自调色让 APNG 闪烁，已跳过；要压 APNG 请开启"APNG 自动无损压缩"或把 PNG 设为无损', 'libre-compress' );
        }

        return self::missing_tool_message( $extension, $target );
    }

    /**
     * 缺工具时的提示文案
     *
     * @param string $source 源扩展名
     * @param string $target 目标扩展名，空字符串表示同格式压缩
     * @return string
     */
    private static function missing_tool_message( string $source, string $target = '' ): string {
        $groups = array();

        foreach ( self::required_tool_groups( $source, $target ) as $group ) {
            $groups[] = implode( ' + ', $group );
        }

        return sprintf(
            /* translators: %s: 工具名称 */
            __( '动图处理需要 %s 工具，当前环境未配齐，已跳过', 'libre-compress' ),
            implode( ' 或 ', $groups )
        );
    }

    /** 清理本次生成的临时文件。 */
    private static function cleanup( array $paths ): void {
        foreach ( $paths as $path ) {
            if ( is_file( $path ) ) {
                unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
    }

}

/**
 * 动画同格式压缩的处理器
 *
 * 形状与 Libre_Compress_Tool_Base 的压缩入口一致（get_name / get_max_file_size / compress），
 * 让压缩调度器能用同一套回滚副本与记录流程处理动图。
 */
class Libre_Compress_Animated_Handler {

    /**
     * 处理的扩展名
     *
     * @var string
     */
    private $extension;

    /**
     * @param string $extension 扩展名
     */
    public function __construct( string $extension ) {
        $this->extension = $extension;
    }

    /**
     * 通道名称，写入压缩记录
     *
     * @return string
     */
    public function get_name(): string {
        return 'animation:' . $this->extension;
    }

    /**
     * 不额外限制体积
     *
     * @return int
     */
    public function get_max_file_size(): int {
        return 0;
    }

    /**
     * 执行动画同格式压缩
     *
     * @param string $file_path 文件路径
     * @param array  $options   选项
     * @return array
     */
    public function compress( string $file_path, array $options = array() ): array {
        return Libre_Compress_Animation::compress_same_format( $file_path, $options );
    }
}
