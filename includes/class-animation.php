<?php
/**
 * 动画图像统一处理层
 *
 * 完整动画直接交给 gifsicle、oxipng、gif2webp、cjxl 或 FFmpeg。
 * 动画 WebP 与动画 AVIF 都由 FFmpeg 一次读完整只动图再写回，不拆帧、不产生
 * 逐帧临时文件，帧数再多也不会撑爆命令行；画布尺寸、逐帧时长、循环次数由
 * FFmpeg 自己保住。webpmux 只负责读帧表，并在写回后修正末帧时长与背景色。
 * 插件只选择工具、读取播放参数，不重写透明像素。
 * 工具执行失败时保留原文件。
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
    private static $same_format_targets = array( 'webp', 'avif' );

    /**
     * 允许参与格式转换的动图源扩展名
     *
     * WebP / AVIF / JXL 无论静图动图都只做同格式压缩，不进出转换链路。
     *
     * @var array
     */
    private static $convert_sources = array( 'gif', 'png' );

    /**
     * 同一请求内读过的动图信息，按"路径 + 体积 + 修改时间"索引
     *
     * 调度器选路线、转换前判定是否动图、执行前拼参数都要读同一张图的信息，
     * 文件没被改动过就不必反复起 webpmux / avifdec 进程。
     * 文件被改写后体积或修改时间必然变化，缓存自动失效，不会拿旧结论处理新内容。
     *
     * @var array<string,array|null>
     */
    private static $plans = array();

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
    public static function apng_enabled(): bool {
        return Libre_Compress_Settings::param_flag( 'apng_enabled' );
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

        if ( ! in_array( $extension, array( 'gif', 'png', 'webp', 'avif' ), true ) ) {
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

        $cache_key = $file_path . '|' . (int) filesize( $file_path ) . '|' . (int) filemtime( $file_path );

        if ( array_key_exists( $cache_key, self::$plans ) ) {
            return self::$plans[ $cache_key ];
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
            default:
                $plan = null;
        }

        if ( null === $plan || $plan['frames'] < 1 || count( $plan['delays'] ) !== $plan['frames'] ) {
            self::$plans[ $cache_key ] = null;
            return null;
        }

        $plan['format'] = $extension;

        self::$plans[ $cache_key ] = $plan;

        return $plan;
    }

    /**
     * 读取 GIF 动画信息
     *
     * 直接按 GIF 块结构解析：延时与透明标记在图形控制扩展里，
     * 循环次数在 NETSCAPE2.0 应用扩展里。这样只做格式转换时不再需要 gifsicle。
     *
     * @param string $file_path GIF 路径
     * @return array|null
     */
    private static function analyze_gif( string $file_path ): ?array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = file_get_contents( $file_path );

        if ( false === $data || strlen( $data ) < 14 || 'GIF' !== substr( $data, 0, 3 ) ) {
            return null;
        }

        $width  = (int) unpack( 'v', substr( $data, 6, 2 ) )[1];
        $height = (int) unpack( 'v', substr( $data, 8, 2 ) )[1];
        $flags  = ord( $data[10] );
        $pos    = 13;
        $length = strlen( $data );

        if ( $flags & 0x80 ) {
            $pos += 3 * ( 2 << ( $flags & 0x07 ) );
        }

        $frames        = 0;
        $delays        = array();
        $loop          = 0;
        $loop_infinite = false;
        $alpha         = false;
        $pending_delay = 0;
        $pending_alpha = false;

        while ( $pos < $length ) {
            $block = ord( $data[ $pos ] );

            if ( 0x3B === $block ) {
                break;
            }

            if ( 0x21 === $block ) {
                if ( $pos + 2 > $length ) {
                    return null;
                }

                $label = ord( $data[ $pos + 1 ] );
                $pos  += 2;

                if ( 0xF9 === $label ) {
                    // 图形控制扩展：块大小 4，打包字段、延时、透明色索引、结束符。
                    if ( $pos + 6 > $length || 4 !== ord( $data[ $pos ] ) ) {
                        return null;
                    }

                    $packed  = ord( $data[ $pos + 1 ] );
                    $pending_delay = (int) unpack( 'v', substr( $data, $pos + 2, 2 ) )[1] * 10;
                    $pending_alpha = (bool) ( $packed & 0x01 );
                    $pos += 6;
                    continue;
                }

                if ( 0xFF === $label && $pos + 12 <= $length && 11 === ord( $data[ $pos ] ) ) {
                    $identifier = substr( $data, $pos + 1, 11 );

                    if ( 'NETSCAPE2.0' === $identifier || 'ANIMEXTS1.0' === $identifier ) {
                        $sub = $pos + 12;

                        if ( $sub + 3 <= $length && 3 === ord( $data[ $sub ] ) && 1 === ord( $data[ $sub + 1 ] ) ) {
                            $count         = (int) unpack( 'v', substr( $data, $sub + 2, 2 ) )[1];
                            $loop          = $count;
                            $loop_infinite = 0 === $count;
                        }
                    }
                }

                // 跳过扩展的剩余子块。
                while ( $pos < $length ) {
                    $size = ord( $data[ $pos ] );
                    $pos++;

                    if ( 0 === $size ) {
                        break;
                    }

                    $pos += $size;
                }

                continue;
            }

            if ( 0x2C !== $block ) {
                return null;
            }

            // 图像描述符：块标记 1 字节 + 位置/尺寸 8 字节 + 打包字段 1 字节。
            if ( $pos + 10 > $length ) {
                return null;
            }

            $pos += 10;
            $packed = ord( $data[ $pos - 1 ] );

            if ( $packed & 0x80 ) {
                $pos += 3 * ( 2 << ( $packed & 0x07 ) );
            }

            // LZW 最小码长 + 数据子块。
            $pos++;

            while ( $pos < $length ) {
                $size = ord( $data[ $pos ] );
                $pos++;

                if ( 0 === $size ) {
                    break;
                }

                $pos += $size;
            }

            $frames++;
            $delays[] = $pending_delay;
            $alpha    = $alpha || $pending_alpha;
            $pending_delay = 0;
            $pending_alpha = false;
        }

        if ( $frames < 1 || count( $delays ) !== $frames ) {
            return null;
        }

        return array(
            'frames'        => $frames,
            'delays'        => $delays,
            'loop'          => $loop,
            'loop_infinite' => $loop_infinite,
            'alpha'         => $alpha,
            'width'         => $width,
            'height'        => $height,
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
        $details  = array();

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

            // 帧矩形与偏移决定"帧能否盖满画布"，末帧时长修正要靠它判断能不能动。
            $details[] = array(
                'width'  => (int) $delay['width'],
                'height' => (int) $delay['height'],
                'x'      => (int) $delay['x'],
                'y'      => (int) $delay['y'],
            );
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
            'width'    => (int) $header['width'],
            'height'   => (int) $header['height'],
            'frame_details' => $details,
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

        $result = Libre_Compress_Tool_Base::run_command( array( $webpmux, '-info', $file_path ), 60 );

        if ( empty( $result['success'] ) ) {
            return null;
        }

        $info = (string) $result['output'];

        if ( ! preg_match( '/Number of frames:\s*(\d+)/i', $info, $count ) ) {
            return null;
        }

        $frames = (int) $count[1];

        // 一次读全逐帧时长、偏移和背景色：偏移用来判断帧能不能盖满画布，
        // 背景色在 FFmpeg 写回后要按原图改回去。
        $parsed = self::parse_webp_frames( $info );

        if ( null === $parsed || count( $parsed['frames'] ) !== $frames ) {
            return null;
        }

        $delays = array();
        $alpha  = false !== stripos( $info, 'transparency' );

        foreach ( $parsed['frames'] as $frame ) {
            $delays[] = $frame['duration'];

            if ( $frame['alpha'] ) {
                $alpha = true;
            }
        }

        $loop_infinite = (bool) preg_match( '/Loop Count\s*:\s*0\b/i', $info );
        // WebP 保存总播放次数，统一换成 GIF / AVIF 使用的额外播放次数。
        $loop = preg_match( '/Loop Count\s*:\s*(\d+)/i', $info, $lc ) ? max( 0, (int) $lc[1] - 1 ) : null;
        $canvas = preg_match( '/Canvas size:\s*(\d+)\s*x\s*(\d+)/i', $info, $cs ) ? array( (int) $cs[1], (int) $cs[2] ) : array( (int) $parsed['frames'][0]['width'], (int) $parsed['frames'][0]['height'] );

        return array(
            'frames'  => $frames,
            'delays'  => $delays,
            'loop'    => $loop,
            'loop_infinite' => $loop_infinite,
            'alpha'   => $alpha,
            'width'   => $canvas[0],
            'height'  => $canvas[1],
            'frame_details'    => $parsed['frames'],
            'background_color' => $parsed['background'],
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
            'width'     => $resolution[0],
            'height'    => $resolution[1],
        );
    }

    /**
     * 秒换算成毫秒
     *
     * 内部时长单位统一为毫秒：WebP 原生就是毫秒，AVIF 用 1000 的时间基准，
     * GIF / APNG 换算过来都是精确值。
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
        $target  = '' === $target ? $source : $target;
        $readers = array( 'webp' => 'webpmux', 'avif' => 'avifdec' );
        $group   = array();

        if ( isset( $readers[ $source ] ) ) {
            $group[] = $readers[ $source ];
        }

        if ( 'webp' === $target ) {
            // GIF 交给 gif2webp，其余动图交给 FFmpeg 的 libwebp_anim；
            // 两条路都要用 webpmux 回写末帧时长与背景色，缺了它就出不了正确播放参数。
            $group[] = 'gif' === $source ? 'gif2webp' : 'ffmpeg';
            $group[] = 'webpmux';
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

        // APNG 有自己的一组参数与开关，与静态 PNG 的有损/无损模式无关。
        if ( ! self::apng_enabled() ) {
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

        $command = self::build_native_command( $source, $target, $output, $plan );

        if ( false === $command ) {
            $result['message'] = __( '当前工具无法完整处理这张动画，已保留原文件', 'libre-compress' );
            return $result;
        }

        $run = Libre_Compress_Tool_Base::run_command( $command, 300 );

        clearstatcache( true, $output );
        if ( empty( $run['success'] ) || ! file_exists( $output ) || 0 === (int) filesize( $output ) ) {
            self::cleanup( array( $output ) );
            $result['message'] = __( '动图处理命令执行失败', 'libre-compress' );
            return $result;
        }

        if ( 'webp' === $target && ! self::restore_webp_playback( $output, $plan ) ) {
            self::cleanup( array( $output ) );
            $result['message'] = __( '动图处理命令执行失败', 'libre-compress' );
            return $result;
        }

        $result['success'] = true;
        $result['tool']    = 'jxl' === $target ? 'cjxl' : basename( $command[0], '.exe' );

        return $result;
    }

    /**
     * 工具接收完整动画，不生成逐帧临时图片。
     */
    private static function build_native_command( string $source, string $target, string $output, array $plan ) {
        $format = $plan['format'];

        // 参数按工具取：同一个工具只有一组参数，GIF 与 APNG 转同一个目标格式共用这套设置。
        if ( 'jxl' === $target ) {
            return Libre_Compress_Cjxl::build_command( $source, $output, 'jxl' );
        }

        if ( 'avif' === $target ) {
            return self::build_avif_command( $source, $output, $plan );
        }

        if ( 'gif' === $format ) {
            return self::gif2webp_command( $source, $output, 'gif_to_webp' );
        }

        // 剩下的都是 WebP 目标：WebP 同格式重压与 APNG 转 WebP，都交给 FFmpeg 一次读写整只动图。
        if ( ! in_array( $format, array( 'png', 'webp' ), true ) ) {
            return false;
        }

        return self::build_webp_command( $source, $output, $plan );
    }

    /**
     * 构建 WebP 输出命令
     *
     * libwebp_anim 自己会推算帧间差异矩形与画布尺寸，所以画布大小、帧偏移、
     * 逐帧时长和循环次数都不用插件操心，帧数再多也只是一条命令。
     * 动画 WebP 同格式重压与 APNG 转 WebP 共用 webp_anim 一组参数。
     *
     * @param string $source 源路径
     * @param string $output 输出路径
     * @param array  $plan   原动画信息
     * @return array 命令参数
     */
    private static function build_webp_command( string $source, string $output, array $plan ): array {
        $same     = 'webp' === $plan['format'];
        $group    = 'webp_anim';
        $lossless = 'lossless' === Libre_Compress_Settings::param_mode( $group );
        $quality  = Libre_Compress_Settings::param_int( $group . '_quality' );
        $method   = Libre_Compress_Settings::param_int( $group . '_method' );

        $command = array( self::tool( 'ffmpeg' ), '-nostdin', '-v', 'error' );

        if ( ! $same ) {
            $command = array_merge( $command, array( '-f', 'apng', '-ignore_loop', '1' ) );
        }

        $command = array_merge( $command, array(
            '-i', $source,
            '-c:v', 'libwebp_anim',
        ) );

        // 这里不能传 -preset：FFmpeg 会在初始化时用 WebPConfigPreset() 重置整份配置，
        // 实测会把 -lossless 抹成有损、把 -compression_level 抹回默认，
        // 只有 -quality 能留下。内容预设因此只对静图的 cwebp 生效。

        // -compression_level 直接吃参数表里的 0-6，也就是 libwebp 的 method，不做任何折算。
        if ( $lossless ) {
            $command = array_merge( $command, array( '-lossless', '1', '-compression_level', (string) $method ) );
        } else {
            $command = array_merge( $command, array( '-quality', (string) $quality, '-compression_level', (string) $method ) );
        }

        return array_merge( $command, array(
            '-enc_time_base', '1:1000', '-fps_mode', 'passthrough',
            '-map_metadata', Libre_Compress_Settings::strips_metadata() ? '-1' : '0',
            '-loop', ! empty( $plan['loop_infinite'] ) ? '0' : (string) ( (int) $plan['loop'] + 1 ),
            '-f', 'webp', $output,
        ) );
    }

    /**
     * 把 FFmpeg 写歪的播放参数改回原图
     *
     * 两处必须改：libwebp_anim 用平均帧时长推算末帧时长，背景色则一律写成不透明白。
     * webpmux 的 -duration 会按帧包围盒重算画布，盖不满整张画布的动图（常见透明留白）
     * 一改就会缩水，这种图只改背景色，时长保留 FFmpeg 的推算值。
     *
     * @param string $output 已写好的 WebP 路径，就地替换
     * @param array  $plan   原动画信息
     * @return bool 是否成功
     */
    private static function restore_webp_playback( string $output, array $plan ): bool {
        $details = isset( $plan['frame_details'] ) ? (array) $plan['frame_details'] : array();

        if ( self::frames_cover_canvas( $details, (int) $plan['width'], (int) $plan['height'] ) ) {
            $duration = array(
                '-duration',
                (int) end( $plan['delays'] ) . ',' . (int) $plan['frames'],
            );

            if ( ! self::remux_webp( $output, $duration ) ) {
                return false;
            }
        }

        if ( ! empty( $plan['background_color'] ) ) {
            return self::remux_webp( $output, array( '-set', 'bgcolor', (string) $plan['background_color'] ) );
        }

        return true;
    }

    /**
     * 用 webpmux 就地改一项播放参数
     *
     * @param string $output  目标文件
     * @param array  $options webpmux 的选项与参数
     * @return bool 是否成功
     */
    private static function remux_webp( string $output, array $options ): bool {
        $temp = $output . '.lc-webp-tmp';

        self::cleanup( array( $temp ) );

        $run = Libre_Compress_Tool_Base::run_command(
            array_merge( array( self::tool( 'webpmux' ) ), $options, array( $output, '-o', $temp ) ),
            60
        );

        clearstatcache( true, $temp );

        if ( empty( $run['success'] ) || ! is_file( $temp ) || 0 === (int) filesize( $temp ) ) {
            self::cleanup( array( $temp ) );
            return false;
        }

        if ( ! rename( $temp, $output ) ) {
            self::cleanup( array( $temp ) );
            return false;
        }

        return true;
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
    public static function gif2webp_command( string $source, string $output, string $group = 'gif_to_webp' ) {
        $gif2webp = self::tool( 'gif2webp' );

        if ( false === $gif2webp ) {
            return false;
        }

        // gif2webp 默认保持无损，-lossy 是一个独立开关，不是“模式”。
        $lossy   = Libre_Compress_Settings::param_flag( $group . '_lossy' );
        $quality = Libre_Compress_Settings::param_int( $group . '_quality' );

        $command = array( $gif2webp, '-m', (string) Libre_Compress_Settings::param_int( $group . '_method' ) );

        if ( $lossy ) {
            $command = array_merge( $command, array( '-lossy', '-q', (string) $quality ) );
        }

        $command = array_merge( $command, array( '-metadata', Libre_Compress_Settings::strips_metadata() ? 'icc' : 'all' ) );

        return array_merge( $command, array( $source, '-o', $output ) );
    }

    /**
     * 解析 webpmux -info 的帧表
     *
     * 帧表每行：序号 宽 高 alpha x y 时长(ms) dispose blend 大小 编码，
     * 只取判定要用到的前七列，后面几列与插件无关。
     *
     * @param string $info webpmux -info 的完整输出
     * @return array|null 每帧的尺寸、是否带透明、偏移与时长；背景色单独返回，读不出返回 null
     */
    private static function parse_webp_frames( string $info ): ?array {
        if ( ! preg_match( '/Number of frames:\s*(\d+)/i', $info, $count ) ) {
            return null;
        }

        preg_match_all(
            '/^\s*(\d+):\s+(\d+)\s+(\d+)\s+(yes|no)\s+(-?\d+)\s+(-?\d+)\s+(\d+)\s/m',
            $info,
            $rows,
            PREG_SET_ORDER
        );

        if ( count( $rows ) !== (int) $count[1] ) {
            return null;
        }

        // 背景色是 0xAARRGGBB，webpmux -set bgcolor 要的是 A,R,G,B 四个十进制数。
        $background = preg_match( '/Background color\s*:\s*0x([0-9A-Fa-f]{8})/', $info, $bg )
            ? implode( ',', array_map( 'hexdec', str_split( strtoupper( $bg[1] ), 2 ) ) )
            : null;

        $frames = array();

        foreach ( $rows as $row ) {
            $frames[] = array(
                'width'    => (int) $row[2],
                'height'   => (int) $row[3],
                'alpha'    => 'yes' === strtolower( $row[4] ),
                'x'        => (int) $row[5],
                'y'        => (int) $row[6],
                'duration' => (int) $row[7],
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

    /**
     * FFmpeg 内部处理颜色和透明背景，全程不把动画帧交给 PHP。
     */
    private static function build_avif_command( string $source, string $output, array $plan ) {
        $same = 'avif' === $plan['format'];
        // 动画 AVIF 同格式压缩、动画 GIF 与 APNG 转 AVIF 都走 FFmpeg 的 libaom-av1，共用 avif_anim 一组参数。
        $group    = 'avif_anim';
        $lossless = 'lossless' === Libre_Compress_Settings::param_mode( $group );
        $crf      = Libre_Compress_Settings::param_int( $group . '_crf' );
        $speed    = Libre_Compress_Settings::param_int( $group . '_speed' );
        $threads  = Libre_Compress_Settings::param_int( $group . '_threads' );
        $command  = array( self::tool( 'ffmpeg' ), '-nostdin', '-v', 'error' );

        if ( 'gif' === $plan['format'] ) {
            // 保留 GIF 的零延时与透明底色，不套用播放器的最小延时。
            $command = array_merge( $command, array( '-min_delay', '0', '-ignore_loop', '1' ) );
        } elseif ( 'png' === $plan['format'] ) {
            $command = array_merge( $command, array( '-f', 'apng', '-ignore_loop', '1' ) );
        }
        $command = array_merge( $command, array( '-i', $source ) );

        $input_stream = '0:v:0';
        $alpha_stream = '';
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
            $input_stream = '0:' . $colors[0];
            if ( $plan['alpha'] ) {
                $alpha_stream = '0:' . $alphas[0];
            }
        }

        if ( $same && $lossless ) {
            // 无损重压直接使用原颜色与透明轨道，避免颜色换算。
            $command = array_merge( $command, array( '-map', $input_stream ) );
            if ( $plan['alpha'] ) {
                $command = array_merge( $command, array( '-map', $alpha_stream, '-colorspace:v:1', 'bt709' ) );
            }
        } elseif ( $lossless ) {
            // 无损转换用 RGB，避免色度抽样造成损失。
            if ( $plan['alpha'] ) {
                $filter = '[' . $input_stream . ']split[color][alpha];[color]format=gbrp[c];[alpha]alphaextract,format=gray,setparams=colorspace=bt709[a]';
                $command = array_merge( $command, array( '-filter_complex', $filter, '-map', '[c]', '-map', '[a]' ) );
            } else {
                $command = array_merge( $command, array( '-filter_complex', '[' . $input_stream . ']format=gbrp[c]', '-map', '[c]' ) );
            }
        } elseif ( $plan['alpha'] ) {
            // 透明背景独立无损编码；颜色轨道不指定像素格式，交给编码器默认值。
            $filter = '' !== $alpha_stream
                ? '[' . $alpha_stream . ']format=gray,setparams=colorspace=bt709[a]'
                : '[' . $input_stream . ']split[color][alpha];[alpha]alphaextract,format=gray,setparams=colorspace=bt709[a]';
            $command = array_merge(
                $command,
                array( '-filter_complex', $filter, '-map', '' !== $alpha_stream ? $input_stream : '[color]', '-map', '[a]' )
            );
        } else {
            $command = array_merge( $command, array( '-map', $input_stream ) );
        }

        $encoder = array( '-c:v', 'libaom-av1' );

        if ( $lossless ) {
            // libaom 自己的无损开关，无损时不传 CRF。
            $encoder = array_merge( $encoder, array( '-lossless', '1' ) );
        } else {
            // CRF 直接吃参数表里的 0-63，不做任何换算。
            $encoder = array_merge( $encoder, array( '-crf', (string) $crf ) );

            // 色彩抽样只存在于有损 YUV：4:4:4 保色最准，4:2:0 体积最小。
            // 只指定颜色轨道（第一条视频轨），透明轨道仍然按 gray 编码。
            $chroma = Libre_Compress_Settings::param_choice( $group . '_chroma' );

            if ( 'auto' !== $chroma ) {
                $encoder = array_merge( $encoder, array( '-pix_fmt:v:0', 'yuv' . $chroma . 'p' ) );
            }
        }

        $command = array_merge(
            $command,
            $encoder,
            array( '-b:v', '0', '-cpu-used', (string) $speed, '-threads', (string) $threads )
        );
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
            return __( 'APNG 动图压缩已关闭，或缺少 oxipng，已跳过；要压 APNG 请到“压缩工具”页勾选“压缩 APNG”', 'libre-compress' );
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
 * 形状与 Libre_Compress_Tool_Base 的压缩入口一致（get_name / get_used_tool_name / compress），
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
     * 写入压缩记录的工具名
     *
     * @return string
     */
    public function get_used_tool_name(): string {
        return $this->get_name();
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
