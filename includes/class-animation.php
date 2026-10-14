<?php
/**
 * 动画图像统一处理层
 *
 * 把 GIF / APNG / 动画 WebP / 动画 AVIF / 动画 JXL 都先拆成"带透明的逐帧 PNG + 每帧时长"，
 * 再按目标格式重新组装。拆帧和组装全部交给命令行工具，本类只负责编排与校验。
 *
 * 为什么必须校验：命令行工具在动画上有大量"看着成功其实把动图压成静图"的坑，
 * 例如 ImageMagick 写出的多帧 AVIF 容器里浏览器只认第一帧、ffmpeg 的 y4m 通道不带透明、
 * ffmpeg 解 GIF 默认还会按固定帧率重复凑帧。校验不过就整体失败，由上层回滚原文件。
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

        if ( ! preg_match( '/\b(\d+)\s+images\b/i', $info, $count ) ) {
            return null;
        }

        preg_match_all( '/delay\s+([0-9.]+)\s*s/i', $info, $delays );

        if ( empty( $delays[1] ) || count( $delays[1] ) < (int) $count[1] ) {
            return null;
        }

        $frames = (int) $count[1];
        $loop   = 0;

        if ( preg_match( '/loop\s+(\d+)\s+times/i', $info, $finite ) ) {
            // gifsicle 打印的是"额外循环次数"，APNG 语义是"总播放次数"，这里统一存额外次数
            $loop = (int) $finite[1];
        }

        $size = preg_match( '/logical screen\s+(\d+)x(\d+)/i', $info, $screen ) ? array( (int) $screen[1], (int) $screen[2] ) : array( 0, 0 );

        return array(
            'frames'  => $frames,
            'delays'  => array_map(
                static function ( $seconds ) {
                    return self::milliseconds( (float) $seconds, 1 );
                },
                array_slice( $delays[1], 0, $frames )
            ),
            'loop'    => $loop,
            'alpha'   => false !== stripos( $info, 'transparent' ),
            'width'   => $size[0],
            'height'  => $size[1],
            'partial' => false, // GIF 只有全透明/不透明两种
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

        if ( empty( $chunks['acTL'] ) ) {
            return null;
        }

        $control  = unpack( 'Nframes/Nplays', $chunks['acTL'] );
        $frames   = (int) $control['frames'];
        $delays   = array();

        foreach ( $chunks['fcTL'] as $fctl ) {
            // 注意：unpack 里 d1 会被解析成"double 类型取 1 个"，所以末尾两个字节必须用 C
            $delay = unpack( 'Nseq/Nwidth/Nheight/Nx/Ny/nnum/nden/Cdispose/Cblend', $fctl );

            if ( false === $delay || empty( $delay['den'] ) ) {
                return null;
            }

            $delays[] = self::milliseconds( $delay['num'] / $delay['den'], 1 );
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
            'alpha'    => in_array( $color, array( 4, 6 ), true ) || ! empty( $chunks['tRNS'] ),
            'width'    => (int) $header['width'],
            'height'   => (int) $header['height'],
            'partial'  => 6 === $color || 4 === $color,
            'plays'    => (int) $control['plays'],
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
            $delays[] = self::milliseconds( (int) $row[6] / 1000, 1 );

            if ( 'yes' === $row[3] ) {
                $alpha = true;
            }
        }

        $loop   = preg_match( '/Loop Count\s*:\s*(\d+)/i', $info, $lc ) ? (int) $lc[1] : 0;
        $canvas = preg_match( '/Canvas size:\s*(\d+)\s*x\s*(\d+)/i', $info, $cs ) ? array( (int) $cs[1], (int) $cs[2] ) : array( (int) $rows[0][1], (int) $rows[0][2] );

        return array(
            'frames'  => $frames,
            'delays'  => $delays,
            'loop'    => $loop,
            'alpha'   => $alpha,
            'width'   => $canvas[0],
            'height'  => $canvas[1],
            'partial' => $alpha,
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
                $delays[] = self::milliseconds( (int) $frame[2] / $timescale, 0 );
            }
        } elseif ( preg_match_all( '/duration\s+([0-9.]+)/', $info, $secs ) && count( $secs[1] ) === $frames ) {
            foreach ( $secs[1] as $seconds ) {
                $delays[] = self::milliseconds( (float) $seconds, 0 );
            }
        } else {
            return null;
        }

        $alpha_line = preg_match( '/\* Alpha\s*:\s*([^\n]*)/i', $info, $alpha ) ? trim( $alpha[1] ) : '';

        return array(
            'frames'    => $frames,
            'delays'    => $delays,
            'loop'      => preg_match( '/Repetition Count\s*:\s*(\d+)/i', $info, $rep ) ? (int) $rep[1] : 0,
            'alpha'     => '' !== $alpha_line && false === stripos( $alpha_line, 'absent' ),
            'width'     => $resolution[0],
            'height'    => $resolution[1],
            'partial'   => false !== stripos( $alpha_line, 'premultiplied' ),
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

        return array(
            'frames'  => $frames,
            'delays'  => array_map(
                static function ( $ms ) {
                    return self::milliseconds( (float) $ms / 1000, 0 );
                },
                $delays[1]
            ),
            'loop'    => false !== stripos( $info, '(looping)' ) ? 0 : 1,
            'alpha'   => false !== stripos( $info, '+Alpha' ) || false !== stripos( $info, 'Alpha: ' ),
            'width'   => $res[0],
            'height'  => $res[1],
            'partial' => true,
        );
    }

    /**
     * 秒换算成毫秒
     *
     * 内部时长单位统一为毫秒：WebP 原生就是毫秒，AVIF 用 1000 的时间基准，
     * JPEG XL 由 jxlinfo 直接报毫秒，GIF / APNG 换算过来都是精确值。
     *
     * @param float $seconds 秒
     * @param int   $guard   传 1 时把 0 抬到 1 毫秒（GIF 允许 0 延时，但重组时 0 会改变播放语义）
     * @return int
     */
    private static function milliseconds( float $seconds, int $guard ): int {
        $value = (int) round( $seconds * 1000 );

        if ( $value < 1 ) {
            return $guard > 0 ? 1 : max( 0, $value );
        }

        return $value;
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
        $target    = '' === $target ? $source : $target;
        $encoder   = 'avif' === $target ? 'avifenc' : 'img2webp';

        if ( 'jxl' === $target ) {
            // cjxl 原生读 GIF 与 APNG 的逐帧时长，不需要拆帧
            return array( array( 'cjxl' ) );
        }

        if ( 'gif' === $source && 'webp' === $target ) {
            return array( array( 'gif2webp' ), array( 'ffmpeg', 'img2webp' ) );
        }

        if ( 'webp' === $source ) {
            return array( array( 'webpmux', 'anim_dump', $encoder ) );
        }

        if ( 'avif' === $source ) {
            return array( array( 'avifdec', $encoder ) );
        }

        // gif / png 源逐帧拆开后重组
        return array( array( 'ffmpeg', $encoder ) );
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

        if ( 'jxl' === $extension ) {
            // cjxl 能直接读动画 JXL 并重压，无需拆帧
            $command = Libre_Compress_Cjxl::build_command( $file_path, $file_path . '.lc-anim-jxl.jxl' );

            if ( false === $command ) {
                return $fail( __( 'JXL 压缩工具 cjxl 不可用', 'libre-compress' ) );
            }

            $result = Libre_Compress_Tool_Base::run_command( $command, 300 );
            $output = $command[ count( $command ) - 1 ];

            if ( empty( $result['success'] ) || ! file_exists( $output ) || 0 === (int) filesize( $output ) ) {
                if ( file_exists( $output ) ) {
                    unlink( $output ); // phpcs:ignore WordPress.PHP
                }

                return $fail( __( 'cjxl 压缩失败', 'libre-compress' ) );
            }

            if ( ! self::verify( $file_path, $output ) ) {
                unlink( $output ); // phpcs:ignore WordPress.PHP

                return $fail( __( 'JXL 重压后帧数、时长或透明通道与原图不一致，已放弃', 'libre-compress' ) );
            }

            // 结果落在临时文件里，由上层用同一个路径替换；这里直接改名回原路径
            if ( ! rename( $output, $file_path ) ) {
                unlink( $output ); // phpcs:ignore WordPress.PHP

                return $fail( __( '压缩结果替换失败，已保留原文件', 'libre-compress' ) );
            }

            clearstatcache( true, $file_path );

            return array(
                'success'         => true,
                'message'         => __( '压缩成功', 'libre-compress' ),
                'original_size'   => $original,
                'compressed_size' => (int) filesize( $file_path ),
            );
        }

        $plan = self::analyze( $file_path );

        if ( null === $plan ) {
            return $fail( __( '无法读取动图信息，已停止压缩以保护内容', 'libre-compress' ) );
        }

        if ( ! self::same_format_supported( $extension ) ) {
            return $fail( self::skip_reason( $extension ) );
        }

        $dir      = self::create_frame_dir();
        $temp_out = $file_path . '.lc-anim-' . wp_generate_password( 8, false, false ) . '.' . $extension;
        $temps    = array( $temp_out );

        if ( '' === $dir ) {
            return $fail( __( '无法创建动图临时目录，已保留原文件', 'libre-compress' ) );
        }

        $frames = self::decode_frames( $file_path, $dir, $plan, $temps );

        if ( null === $frames ) {
            self::cleanup( $temps );

            return $fail( __( '动图拆帧失败', 'libre-compress' ) );
        }

        // 兜底：源容器表明确实带透明（GIF 的透明索引、APNG 的色彩类型、WebP 的 ALPH 块，
        // 都由 gifsicle / 自读容器 / webpmux 给出，与拆帧工具无关），拆出来的帧却没有透明通道，
        // 说明这台机器的拆帧工具把透明丢了，重组必然产出丢透明的图——直接放弃，原文件不动。
        // 动画 AVIF 不在这条之列：alpha 平面全不透明时 avifdec 会省掉它、输出不带通道的 PNG，
        // 而容器声明仍然带，硬套这条会把本来正常的动图误跳过；那种图并没丢任何透明，交给 verify() 核对产物即可。
        if ( $plan['alpha'] && ! self::frames_have_alpha( $frames ) && in_array( $plan['format'], array( 'gif', 'png', 'webp' ), true ) ) {
            self::cleanup( $temps );

            return $fail( __( '这台机器的动图拆帧工具会丢失透明通道，已放弃这次压缩并保留原文件', 'libre-compress' ) );
        }

        $commands = self::build_encode_commands( $extension, $frames, $plan['delays'], $plan['loop'], $temp_out );

        if ( false === $commands ) {
            self::cleanup( $temps );

            return $fail( self::missing_tool_message( $extension ) );
        }

        $executed = self::run_commands( $commands, $dir );

        if ( '' !== $executed ) {
            self::cleanup( $temps );

            return $fail( $executed );
        }

        if ( ! file_exists( $temp_out ) || 0 === (int) filesize( $temp_out ) ) {
            self::cleanup( $temps );

            return $fail( __( '压缩结果为空文件，已保留原文件', 'libre-compress' ) );
        }

        if ( ! self::verify( $file_path, $temp_out, $frames ) ) {
            self::cleanup( $temps );

            return $fail( __( '压缩结果与原动图的帧数、时长或透明通道不一致，已放弃', 'libre-compress' ) );
        }

        if ( ! rename( $temp_out, $file_path ) ) {
            self::cleanup( $temps );

            return $fail( __( '压缩结果替换失败，已保留原文件', 'libre-compress' ) );
        }

        // 改名后临时项已不存在，剩下的帧文件仍要清理
        $temps = array_diff( $temps, array( $temp_out ) );
        self::cleanup( $temps );

        clearstatcache( true, $file_path );

        return array(
            'success'         => true,
            'message'         => __( '压缩成功', 'libre-compress' ),
            'original_size'   => $original,
            'compressed_size' => (int) filesize( $file_path ),
        );
    }

    /* ---------------------------------------------------------------------
     * 执行：转换为目标格式
     * ------------------------------------------------------------------- */

    /**
     * 动画转换入口：把动图源压成目标格式文件
     *
     * 与同格式压缩不同，这里输出到调用方指定的新路径，失败只清理临时件，
     * 由调用方决定怎么处理源文件。
     *
     * @param string $source 源文件绝对路径
     * @param string $target 目标格式 webp|avif|jxl
     * @param string $output 目标文件绝对路径
     * @return array{success:bool,message:string,tool:string}
     */
    public static function convert( string $source, string $target, string $output ): array {
        $source_format = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
        $source_format = 'jpeg' === $source_format ? 'jpg' : $source_format;

        $result = array(
            'success' => false,
            'message' => '',
            'tool'    => 'animation',
        );

        $plan = self::analyze( $source );

        if ( null === $plan ) {
            $result['message'] = __( '无法读取动图信息，已停止转换以保护内容', 'libre-compress' );
            return $result;
        }

        if ( ! self::convert_supported( $source_format, $target ) ) {
            $result['message'] = self::skip_reason( $source_format, $target );
            return $result;
        }

        // 动图转 JXL 直接把源交给 cjxl：实测它读 GIF 与 APNG 都能逐帧保留时长与透明
        if ( 'jxl' === $target ) {
            $command = Libre_Compress_Cjxl::build_command( $source, $output );

            if ( false === $command ) {
                $result['message'] = __( 'JXL 压缩工具 cjxl 不可用', 'libre-compress' );
                return $result;
            }

            $run = Libre_Compress_Tool_Base::run_command( $command, 300 );

            if ( empty( $run['success'] ) || ! file_exists( $output ) || 0 === (int) filesize( $output ) ) {
                $result['message'] = __( 'cjxl 转换失败', 'libre-compress' );
                return $result;
            }

            if ( ! self::verify( $source, $output ) ) {
                unlink( $output ); // phpcs:ignore WordPress.PHP

                $result['message'] = __( '转换结果与原动图的帧数、时长或透明通道不一致，已放弃', 'libre-compress' );
                return $result;
            }

            $result['success'] = true;
            $result['tool']    = 'cjxl';

            return $result;
        }

        $dir    = self::create_frame_dir();
        $temps  = array( $output );

        if ( '' === $dir ) {
            $result['message'] = __( '无法创建动图临时目录，已保留原文件', 'libre-compress' );
            return $result;
        }

        $frames = self::decode_frames( $source, $dir, $plan, $temps );

        if ( null === $frames ) {
            self::cleanup( $temps );

            $result['message'] = __( '动图拆帧失败', 'libre-compress' );
            return $result;
        }

        // 兜底：拆帧工具把源图的透明通道丢了就不能继续，理由与"同格式压缩"那一处相同。
        // 这里的源只会是 GIF 与 APNG（convert_supported() 已限定），两者的透明声明都来自
        // gifsicle 与自读的 PNG 块，与拆帧工具无关，可以放心拿来对照。
        if ( $plan['alpha'] && ! self::frames_have_alpha( $frames ) ) {
            self::cleanup( $temps );

            $result['message'] = __( '这台机器的动图拆帧工具会丢失透明通道，已放弃这次转换并保留原文件', 'libre-compress' );

            return $result;
        }

        $commands = self::build_encode_commands( $target, $frames, $plan['delays'], $plan['loop'], $output );

        if ( false === $commands ) {
            self::cleanup( $temps );

            $result['message'] = self::missing_tool_message( $source_format, $target );
            return $result;
        }

        $error = self::run_commands( $commands, $dir );

        if ( '' !== $error ) {
            self::cleanup( $temps );

            $result['message'] = $error;
            return $result;
        }

        if ( ! file_exists( $output ) || 0 === (int) filesize( $output ) ) {
            self::cleanup( $temps );

            $result['message'] = __( '转换结果为空文件', 'libre-compress' );
            return $result;
        }

        if ( ! self::verify( $source, $output, $frames ) ) {
            self::cleanup( $temps );

            $result['message'] = __( '转换结果与原动图的帧数、时长或透明通道不一致，已放弃', 'libre-compress' );
            return $result;
        }

        // 输出文件是调用方要保留的，不能出现在清理列表里
        self::cleanup( array_diff( $temps, array( $output ) ) );

        $result['success'] = true;
        $result['tool']    = 'avif' === $target ? 'ffmpeg+avifenc' : 'img2webp';

        return $result;
    }

    /* ---------------------------------------------------------------------
     * 拆帧 / 组装 / 校验
     * ------------------------------------------------------------------- */

    /**
     * 逐帧临时目录
     *
     * 放在系统临时目录里，一是帧文件不该出现在公开的 uploads 里，二是配合"以该目录为
     * 工作目录执行编码器"，让命令里每个帧只占一个裸文件名（约 8 字符），把 Windows
     * 命令行长度的天花板抬到上千帧。
     *
     * @return string 目录路径，创建失败返回空字符串
     */
    private static function create_frame_dir(): string {
        $base = rtrim( sys_get_temp_dir(), '/\\' );

        // 必须"创建成功"才算拿到目录：撞上同名目录时复用，会读到上一轮或另一个请求留下的帧文件
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $dir = $base . '/lc-' . wp_generate_password( 8, false, false );

            if ( @mkdir( $dir, 0777, false ) ) {
                return $dir;
            }
        }

        return '';
    }

    /**
     * 把动图拆成逐帧 PNG
     *
     * 帧文件名固定为 0.png、1.png……；所有临时件（含目录本身）追加进 $temps 引用参数，
     * 由调用方统一清理。$dir 必须已由 create_frame_dir() 建好。
     *
     * @param string $source 动图源路径
     * @param string $dir    帧目录
     * @param array  $plan   analyze() 结果
     * @param array  $temps  临时件清单（引用）
     * @return string[]|null 帧路径数组
     */
    private static function decode_frames( string $source, string $dir, array $plan, array &$temps ): ?array {
        $format = $plan['format'];
        $frames = array();

        for ( $i = 0; $i < $plan['frames']; $i++ ) {
            $frames[] = $dir . '/' . $i . '.png';
            $temps[]  = $frames[ $i ];
        }

        // 目录要在帧之后清理，位置不能挪
        $temps[] = $dir;

        $commands = array();

        if ( 'gif' === $format || 'png' === $format ) {
            $ffmpeg = self::tool( 'ffmpeg' );

            if ( false === $ffmpeg ) {
                return null;
            }

            // -fps_mode passthrough 是关键：默认会按固定帧率重复凑帧，4 帧被灌成 27 帧
            // -start_number 0 让 ffmpeg 的序号与本层的 0.png 起点对齐，不必再改名
            $commands[] = array( $ffmpeg, '-y', '-loglevel', 'error', '-i', $source, '-fps_mode', 'passthrough', '-start_number', '0', $dir . '/%d.png' );
            $decoded    = $frames;
        } elseif ( 'webp' === $format ) {
            // 动画 WebP 的帧是增量小块，webpmux 取出来不是整幅画布；
            // anim_dump 走 libwebp 的动画解码器，输出的正是合成后的整幅 RGBA 帧。
            $anim_dump = self::tool( 'anim_dump' );

            if ( false === $anim_dump ) {
                return null;
            }

            for ( $i = 0; $i < $plan['frames']; $i++ ) {
                $temps[] = sprintf( '%s/anim_%04d.png', $dir, $i );
            }

            $commands = array(
                array( $anim_dump, '-folder', $dir, '-prefix', 'anim_', $source ),
            );

            $decoded = array();

            for ( $i = 0; $i < $plan['frames']; $i++ ) {
                $decoded[] = sprintf( '%s/anim_%04d.png', $dir, $i );
            }
        } else {
            // 动画 AVIF 用 avifdec 逐帧取整幅画布
            $decoder = self::tool( 'avifdec' );

            if ( false === $decoder ) {
                return null;
            }

            for ( $i = 0; $i < $plan['frames']; $i++ ) {
                $commands[] = array( $decoder, '--index', (string) $i, $source, $frames[ $i ] );
            }

            $decoded = $frames;
        }

        $error = self::run_commands( $commands );

        if ( '' !== $error ) {
            return null;
        }

        // ffmpeg 的数字命名与确定性命名不同步时改名对齐
        $final = array();

        foreach ( $decoded as $index => $produced ) {
            $target = $frames[ $index ];

            if ( $produced !== $target ) {
                if ( ! file_exists( $produced ) ) {
                    return null;
                }

                rename( $produced, $target );
            }

            if ( ! file_exists( $target ) || 0 === (int) filesize( $target ) ) {
                return null;
            }

            $final[] = $target;
        }

        // 帧必须是 8 位 PNG：各编码器的输入约定建立在这之上，拿到 16 位或浮点格式说明解码工具行为异常
        // 透明平面全不透明时 avifdec 会写成无 alpha 的 RGB PNG，这也是合法输入
        foreach ( $final as $frame ) {
            if ( ! self::is_decodable_png( $frame ) ) {
                return null;
            }
        }

        return $final;
    }

    /**
     * 构建动画组装命令
     *
     * 命令以帧目录为工作目录执行，所以这里只写帧的裸文件名，输出路径保持绝对。
     *
     * @param string   $target  目标扩展名
     * @param string[] $frames  帧文件绝对路径
     * @param int[]    $delays  每帧时长（毫秒）
     * @param int      $loop    额外循环次数，0 表示无限
     * @param string   $output  输出路径
     * @return array[]|false 命令链，缺工具返回 false
     */
    private static function build_encode_commands( string $target, array $frames, array $delays, int $loop, string $output ) {
        $settings = get_option( 'libre_compress_tools', array() );

        if ( 'avif' === $target ) {
            $avifenc = self::tool( 'avifenc' );

            if ( false === $avifenc ) {
                return false;
            }

            $mode     = isset( $settings['avif_mode'] ) ? $settings['avif_mode'] : 'lossy';
            $quality  = isset( $settings['avif_quality'] ) ? absint( $settings['avif_quality'] ) : 80;
            $commands = array(
                array(
                    $avifenc,
                    '-j',
                    '4',
                    '-s',
                    (string) Libre_Compress_Settings::tool_speed( 'avif_speed' ),
                    // 时间基准设成 1000：一格正好 1 毫秒，与插件内部的时长单位一致
                    '--timescale',
                    '1000',
                ),
            );

            if ( Libre_Compress_Settings::strips_metadata() ) {
                $commands[0][] = '--ignore-exif';
                $commands[0][] = '--ignore-xmp';
            }

            if ( 'lossless' === $mode ) {
                $commands[0][] = '--lossless';
            } else {
                $commands[0][] = '-q';
                $commands[0][] = (string) max( 0, min( 100, $quality ) );

                if ( self::prefer_420() ) {
                    $commands[0][] = '-y';
                    $commands[0][] = '420';
                    $commands[0][] = '--sharpyuv';
                }
            }

            // 命令行长度是帧数的真实天花板（Windows 约 32767 字符）：
            // 各帧时长一样时只给一条 --duration，每帧省下 14 字符，实测上限从约 1100 帧抬到约 3100 帧。
            // 实测这是 --duration 的常规行为（非 :u 选项对其后的所有帧持续生效），产物逐帧时长与逐帧给参数完全一致。
            if ( count( array_unique( $delays ) ) === 1 ) {
                $commands[0][] = '--duration';
                $commands[0][] = (string) max( 1, (int) $delays[0] );

                foreach ( $frames as $frame ) {
                    $commands[0][] = basename( $frame );
                }
            } else {
                // 时长不一致时必须逐帧成对给出，--duration 只作用于紧跟它的那一帧
                foreach ( $frames as $index => $frame ) {
                    $commands[0][] = '--duration';
                    $commands[0][] = (string) max( 1, (int) $delays[ $index ] );
                    $commands[0][] = basename( $frame );
                }
            }

            $commands[0][] = '--repetition-count';
            $commands[0][] = 0 === $loop ? 'infinite' : (string) $loop;
            $commands[0][] = $output;

            return array( $commands[0] );
        }

        if ( 'webp' === $target ) {
            $img2webp = self::tool( 'img2webp' );

            if ( false === $img2webp ) {
                return false;
            }

            $mode    = isset( $settings['webp_mode'] ) ? $settings['webp_mode'] : 'lossy';
            $command = array( $img2webp );

            if ( 'lossless' === $mode ) {
                $command[] = '-lossless';
                $command[] = '-z';
                $command[] = (string) Libre_Compress_Settings::tool_speed( 'webp_lossless_level' );
            } else {
                $quality = isset( $settings['webp_quality'] ) ? absint( $settings['webp_quality'] ) : 80;
                $command[] = '-q';
                $command[] = (string) max( 0, min( 100, $quality ) );
                $command[] = '-m';
                $command[] = (string) Libre_Compress_Settings::tool_speed( 'webp_method' );

                if ( self::prefer_420() ) {
                    $command[] = '-sharp_yuv';
                }
            }

            foreach ( $frames as $index => $frame ) {
                // img2webp 的帧选项作用于其后的文件，-d 的单位就是毫秒，与内部单位一致无需换算
                $command[] = '-d';
                $command[] = (string) max( 1, (int) $delays[ $index ] );
                $command[] = basename( $frame );
            }

            $command[] = '-loop';
            $command[] = (string) $loop;
            $command[] = '-o';
            $command[] = $output;

            return array( $command );
        }

        return false;
    }


    /**
     * 顺序执行命令，返回空字符串表示全部成功
     *
     * @param array[]       $commands 命令链
     * @param string|null   $cwd      工作目录，逐帧编码时用于把路径缩成裸文件名
     * @return string 失败原因
     */
    private static function run_commands( array $commands, ?string $cwd = null ): string {
        foreach ( $commands as $command ) {
            $result = Libre_Compress_Tool_Base::run_command( $command, 300, $cwd );

            if ( empty( $result['success'] ) ) {
                $output = trim( (string) $result['output'] );

                return '' === $output
                    ? __( '动图处理命令执行失败', 'libre-compress' )
                    : substr( $output, 0, 200 );
            }
        }

        return '';
    }

    /**
     * 校验产物：帧数、总时长、透明通道必须与原动图一致
     *
     * 这是防住"静默毁掉动画"的唯一手段，任何一项对不上就判定失败。
     *
     * @param string       $source 原动图
     * @param string       $output 产物
     * @param string[]|null $frames 本次拆出的帧，用于判断原图是否真的有透明像素
     * @return bool
     */
    private static function verify( string $source, string $output, ?array $frames = null ): bool {
        $expected = self::analyze( $source );
        $actual   = self::analyze( $output );

        // 单帧动图无需比较帧数，产物只要存在即认为可用
        if ( null === $expected ) {
            return null !== $actual;
        }

        if ( null === $actual ) {
            return false;
        }

        if ( $expected['frames'] > 1 && $actual['frames'] !== $expected['frames'] ) {
            return false;
        }

        if ( $expected['alpha'] && ! $actual['alpha'] && ( null === $frames || self::frames_have_alpha( $frames ) ) ) {
            return false;
        }

        $source_total = array_sum( $expected['delays'] );
        $output_total = array_sum( $actual['delays'] );

        // 各格式的时长刻度不同（AVIF 受 timescale 量化），允许 5% 误差
        if ( $source_total > 0 && abs( $output_total - $source_total ) > $source_total * 0.05 ) {
            return false;
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * PNG / APNG 容器读写
     * ------------------------------------------------------------------- */

    /**
     * 读取 PNG 块
     *
     * @param string $path PNG 路径
     * @return array{IHDR:string,acTL?:string,tRNS?:string,fcTL:string[],IDAT:string}
     */
    private static function png_chunks( string $path ): array {
        $data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if ( false === $data || 8 > strlen( $data ) ) {
            return array( 'IHDR' => '', 'fcTL' => array(), 'IDAT' => '' );
        }

        $offset = 8;
        $chunks = array(
            'IHDR'   => '',
            'fcTL'   => array(),
            'IDAT'   => '',
        );

        while ( $offset + 12 <= strlen( $data ) ) {
            $length = unpack( 'N', substr( $data, $offset, 4 ) );
            $length = (int) $length[1];
            $type   = substr( $data, $offset + 4, 4 );
            $body   = substr( $data, $offset + 8, $length );

            if ( 'IHDR' === $type || 'acTL' === $type || 'tRNS' === $type || 'PLTE' === $type ) {
                $chunks[ $type ] = $body;
            } elseif ( 'fcTL' === $type ) {
                $chunks['fcTL'][] = $body;
            } elseif ( 'IDAT' === $type ) {
                $chunks['IDAT'] .= $body;
            } elseif ( 'IEND' === $type ) {
                break;
            }

            $offset += 12 + $length;
        }

        return $chunks;
    }

    /**
     * 判断解码出的帧是否是编码器能吃的 8 位 PNG
     *
     * @param string $path PNG 路径
     * @return bool
     */
    private static function is_decodable_png( string $path ): bool {
        $ihdr = self::png_chunks( $path )['IHDR'];

        return 13 === strlen( $ihdr ) && 8 === ord( $ihdr[8] ) && in_array( ord( $ihdr[9] ), array( 0, 2, 3, 4, 6 ), true );
    }

    /**
     * 拆出来的帧里是否真的有透明像素
     *
     * 容器声明的"有 alpha 平面"和"这张图真的透明"是两件事：GIF 转出的 AVIF 带一整层全不透明
     * alpha，avifdec 解码时直接给出 RGB 帧。拿容器声明去要求产物，会把这种动图永久判成失败。
     *
     * @param string[] $frames 帧路径
     * @return bool
     */
    private static function frames_have_alpha( array $frames ): bool {
        foreach ( $frames as $frame ) {
            $chunks = self::png_chunks( $frame );

            if ( ! empty( $chunks['tRNS'] ) ) {
                return true;
            }

            $color = isset( $chunks['IHDR'][9] ) ? ord( $chunks['IHDR'][9] ) : 0;

            if ( 4 === $color || 6 === $color ) {
                return true;
            }
        }

        return false;
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

    /**
     * 清理临时件
     *
     * 先删完所有文件再移除目录，清单里的先后顺序就不会影响结果。
     *
     * @param array $paths 路径清单
     */
    private static function cleanup( array $paths ): void {
        $dirs = array();

        foreach ( $paths as $path ) {
            if ( ! is_string( $path ) || '' === $path ) {
                continue;
            }

            if ( is_dir( $path ) ) {
                $dirs[] = $path;
                continue;
            }

            if ( file_exists( $path ) ) {
                unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }

        foreach ( $dirs as $dir ) {
            @rmdir( $dir );
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
