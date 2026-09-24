<?php
/**
 * 图片格式转换器
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 图片格式转换器
 *
 * 将勾选格式的图片（PNG/JPG/GIF/SVG）直接转换为 WebP 或 AVIF：
 * 原文件从原位置移除（开启了备份原图时先备份到备份文件夹），新格式
 * 文件以原文件名仅替换扩展名的形式落在原位置，并同步更新附件的
 * MIME 与元数据，保证 WordPress 原生输出的 URL 即为新格式。
 *
 * 转换产物不小于源文件时放弃转换（原文件保留，即回退）。
 */
class Libre_Compress_Converter {

    /**
     * 转换记录 post meta 键（供恢复原图时反向处理）
     *
     * @var string
     */
    const CONVERTED_META_KEY = '_libre_compress_converted';

    /**
     * 目标格式对应的编码工具注册名
     *
     * @var array
     */
    private $target_tools = array(
        'webp' => 'cwebp',
        'avif' => 'avifenc',
    );

    /**
     * 目标格式对应的 MIME 类型
     *
     * @var array
     */
    private $target_mimes = array(
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    );

    /**
     * 转换支持的源格式（jpg 涵盖 jpeg 扩展名）
     *
     * @var array
     */
    private $source_formats = array( 'png', 'jpg', 'gif', 'svg' );

    /**
     * 源格式对应的 MIME 类型
     *
     * @var array
     */
    private $format_mimes = array(
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    );

    /**
     * 恢复原图处理中标记（防止恢复后重新生成元数据时再次触发转换）
     *
     * @var bool
     */
    private $restoring = false;

    /**
     * 初始化钩子
     */
    public function init_hooks() {
        // 上传后自动转换（优先级 20，晚于压缩钩子的 10，转换基准为压缩后的文件）
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_convert_on_upload' ), 20, 2 );

        // 文章内容中已固化的旧格式 URL，在同名新格式文件存在时替换
        add_filter( 'the_content', array( $this, 'filter_content' ), 20 );

        // 恢复原图后反向处理：移除新格式文件并还原 MIME/元数据
        add_action( 'libre_compress_after_restore', array( $this, 'handle_after_restore' ) );

        // AJAX 接口
        add_action( 'wp_ajax_libre_compress_get_unconverted', array( $this, 'ajax_get_unconverted' ) );
        add_action( 'wp_ajax_libre_compress_convert_single', array( $this, 'ajax_convert_single' ) );
    }

    /**
     * 获取转换设置
     *
     * @return array target: webp|avif, formats: 已勾选的源格式列表
     */
    public function get_convert_settings(): array {
        $general = get_option( 'libre_compress_general', array() );

        $target = ( isset( $general['convert_target'] ) && 'avif' === $general['convert_target'] ) ? 'avif' : 'webp';

        $formats = array();
        foreach ( $this->source_formats as $format ) {
            if ( ! empty( $general[ 'convert_' . $format ] ) ) {
                $formats[] = $format;
            }
        }

        return array(
            'target'  => $target,
            'formats' => $formats,
        );
    }

    /**
     * 检查是否有任何格式启用了转换
     *
     * @return bool 是否启用
     */
    public function is_conversion_enabled(): bool {
        $settings = $this->get_convert_settings();
        return ! empty( $settings['formats'] );
    }

    /**
     * 获取目标格式文件路径（同目录同名，仅替换扩展名）
     *
     * @param string $file_path 源文件路径
     * @param string $target    目标格式
     * @return string 目标路径
     */
    private function get_target_path( string $file_path, string $target ): string {
        return $this->replace_extension( $file_path, $target );
    }

    /**
     * 替换路径的扩展名
     *
     * @param string $path   文件路径
     * @param string $target 目标扩展名
     * @return string 替换后的路径
     */
    private function replace_extension( string $path, string $target ): string {
        $extension = pathinfo( $path, PATHINFO_EXTENSION );

        if ( '' === $extension ) {
            return $path . '.' . $target;
        }

        return substr( $path, 0, -strlen( $extension ) ) . $target;
    }

    /**
     * 转换附件的所有相关文件（原图 + 缩略图）
     *
     * @param int $attachment_id 附件 ID
     * @return array 统计结果
     */
    public function convert_attachment( int $attachment_id ): array {
        $results = array(
            'total'       => 0,
            'success'     => 0,
            'failed'      => 0,
            'skipped'     => 0,
            'saved_bytes' => 0,
            'details'     => array(),
        );

        if ( ! $this->is_conversion_enabled() ) {
            return $results;
        }

        $files = libre_compress()->compressor->get_attachment_files( $attachment_id );

        // SVG 等非 WP 原生格式可能没有元数据，回退到 _wp_attached_file
        if ( empty( $files ) ) {
            $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

            if ( is_string( $attached_file ) && '' !== $attached_file ) {
                $file_path = wp_get_upload_dir()['basedir'] . '/' . $attached_file;

                if ( file_exists( $file_path ) ) {
                    $files[] = array(
                        'size_type' => 'full',
                        'file_path' => $file_path,
                    );
                }
            }
        }

        // 记录成功转换的源文件 basename，用于元数据同步
        $converted_map = array();

        foreach ( $files as $file ) {
            $result              = $this->convert_file( $attachment_id, $file['file_path'] );
            $result['size_type'] = $file['size_type'];

            $results['details'][] = $result;

            $results['total']++;
            if ( 'success' === $result['status'] ) {
                $results['success']++;
                $results['saved_bytes'] += (int) $result['original_size'] - (int) $result['converted_size'];
                $converted_map[ basename( $result['from'] ) ] = true;
            } elseif ( 'failed' === $result['status'] ) {
                $results['failed']++;
            } else {
                $results['skipped']++;
            }
        }

        // 有文件成功转换后同步附件的 MIME 与元数据
        if ( ! empty( $converted_map ) ) {
            $this->sync_attachment_format( $attachment_id, $converted_map );
        }

        return $results;
    }

    /**
     * 转换单个文件（原地替换格式）
     *
     * 开启备份时先把源文件备份到备份文件夹；转换产物不小于源文件时放弃（回退）。
     * 成功后源文件从原位置删除，新格式文件落位。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @return array 转换结果
     */
    public function convert_file( int $attachment_id, string $file_path ): array {
        $result_template = array(
            'file_path'      => $file_path,
            'from'           => $file_path,
            'to'             => '',
            'status'         => 'failed',
            'message'        => '',
            'original_size'  => 0,
            'converted_size' => 0,
        );

        if ( ! file_exists( $file_path ) ) {
            $result_template['message'] = __( '文件不存在', 'libre-compress' );
            return $result_template;
        }

        if ( ! $this->is_safe_path( $file_path ) ) {
            $result_template['message'] = __( '文件路径不安全', 'libre-compress' );
            return $result_template;
        }

        $settings = $this->get_convert_settings();
        $target   = $settings['target'];

        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'jpeg' === $extension ) {
            $extension = 'jpg';
        }

        if ( ! in_array( $extension, $settings['formats'], true ) ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '该格式未启用转换', 'libre-compress' );
            return $result_template;
        }

        $target_path = $this->get_target_path( $file_path, $target );

        // 同名目标文件已存在时不覆盖（避免不同源格式转换后互相冲突）——轮到了但没办成，记为失败
        if ( file_exists( $target_path ) ) {
            $result_template['to']       = $target_path;
            $result_template['status']   = 'failed';
            $result_template['message']  = __( '同名目标文件已存在', 'libre-compress' );
            $result_template['original_size']  = (int) filesize( $file_path );
            $result_template['converted_size'] = (int) filesize( $target_path );
            return $result_template;
        }

        // 开启备份时先把源文件备份到备份文件夹
        $settings_general = get_option( 'libre_compress_general', array() );
        if ( ! empty( $settings_general['backup_enabled'] ) ) {
            libre_compress()->backup->create_backup( $attachment_id, $file_path );
        }

        $original_size = (int) filesize( $file_path );

        // 编码为目标格式的临时文件
        $temp_output = $file_path . '.tmp-conv.' . $target;
        $encode_ok   = false;
        $error_msg   = '';
        $temp_source = '';

        if ( 'gif' === $extension ) {
            // 动画 GIF 用专用工具整段转换，静态 GIF 先 GD 解码为 PNG
            if ( $this->is_animated_gif( $file_path ) ) {
                $animated = $this->build_animated_gif_command( $file_path, $temp_output, $target );

                if ( false === $animated ) {
                    // 缺工具：环境未配齐，记为跳过
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = ( 'webp' === $target )
                        ? __( '动画 GIF 转 WebP 需要 gif2webp 工具（libwebp 套件）', 'libre-compress' )
                        : __( '动画 GIF 转 AVIF 需要 ffmpeg', 'libre-compress' );
                    return $result_template;
                }

                $command     = $animated['command'];
                $temp_source = $animated['temp'];
            } else {
                $temp_source = $file_path . '.tmp-src.png';
                if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
                    // 缺 GD 扩展：环境未配齐，记为跳过
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = __( '静态 GIF 转换需要 GD 扩展', 'libre-compress' );
                    return $result_template;
                }
                if ( ! $this->decode_gif_to_png( $file_path, $temp_source ) ) {
                    $result_template['message'] = __( 'GIF 解码失败', 'libre-compress' );
                    return $result_template;
                }
                $command = $this->build_encode_command( $temp_source, $temp_output, $target );
            }
        } elseif ( 'svg' === $extension ) {
            // SVG 需先栅格化为 PNG 中间文件
            $temp_source = $file_path . '.tmp-src.png';
            if ( false === $this->get_resvg_path() ) {
                // 缺 resvg：环境未配齐，记为跳过
                $result_template['status']  = 'skipped';
                $result_template['message'] = __( 'SVG 转换需要 resvg 工具', 'libre-compress' );
                return $result_template;
            }
            if ( ! $this->rasterize_svg_to_png( $file_path, $temp_source ) ) {
                $result_template['message'] = __( 'SVG 栅格化失败', 'libre-compress' );
                return $result_template;
            }
            $command = $this->build_encode_command( $temp_source, $temp_output, $target );
        } else {
            // PNG/JPG 由编码工具直接支持
            $command = $this->build_encode_command( $file_path, $temp_output, $target );
        }

        if ( false === $command ) {
            // 缺编码工具（cwebp/avifenc）：环境未配齐，记为跳过
            $error_msg                 = __( '没有可用的编码工具', 'libre-compress' );
            $result_template['status'] = 'skipped';
        } else {
            $output  = array();
            $exec_rc = 0;
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            exec( '(' . $command . ') 2>&1', $output, $exec_rc );
            clearstatcache( true, $temp_output );

            if ( 0 !== $exec_rc || ! file_exists( $temp_output ) || filesize( $temp_output ) <= 0 ) {
                $error_msg = __( '编码失败', 'libre-compress' ) . ': ' . implode( "\n", array_slice( $output, 0, 5 ) );

                // 编码失败时清理可能残留的部分输出文件
                if ( file_exists( $temp_output ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_output );
                }
            } else {
                $encode_ok = true;
            }
        }

        // 清理临时源文件
        if ( '' !== $temp_source && file_exists( $temp_source ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_source );
        }

        if ( ! $encode_ok ) {
            $result_template['message'] = $error_msg;
            return $result_template;
        }

        // 大小对比回退：转换产物不小于源文件时放弃，源文件原样保留
        $converted_size = (int) filesize( $temp_output );

        if ( $converted_size >= $original_size ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );

            $result_template['status']         = 'skipped';
            $result_template['message']        = __( '转换结果更大，已放弃', 'libre-compress' );
            $result_template['original_size']  = $original_size;
            $result_template['converted_size'] = $converted_size;
            return $result_template;
        }

        // 落位新格式文件
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        $renamed = @rename( $temp_output, $target_path );

        if ( ! $renamed ) {
            if ( file_exists( $temp_output ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $temp_output );
            }
            $result_template['message'] = __( '新格式文件写入失败', 'libre-compress' );
            return $result_template;
        }

        // 删除原位置源文件；删除失败则撤销转换，保证状态一致
        if ( ! unlink( $file_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );

            clearstatcache( true, $file_path );
            $result_template['message'] = __( '原文件清理失败，已撤销转换', 'libre-compress' );
            return $result_template;
        }

        // 记录转换映射，供恢复原图时反向处理
        $this->add_converted_record( $attachment_id, $file_path, $target_path );

        return array(
            'file_path'      => $file_path,
            'from'           => $file_path,
            'to'             => $target_path,
            'status'         => 'success',
            'message'        => __( '转换成功', 'libre-compress' ),
            'original_size'  => $original_size,
            'converted_size' => $converted_size,
        );
    }

    /**
     * 追加转换记录到附件 post meta
     *
     * @param int    $attachment_id 附件 ID
     * @param string $from_path     原文件路径
     * @param string $to_path       新格式文件路径
     */
    private function add_converted_record( int $attachment_id, string $from_path, string $to_path ): void {
        $records = get_post_meta( $attachment_id, self::CONVERTED_META_KEY, true );

        if ( ! is_array( $records ) ) {
            $records = array();
        }

        $records[] = array(
            'from' => $from_path,
            'to'   => $to_path,
        );

        update_post_meta( $attachment_id, self::CONVERTED_META_KEY, $records );
    }

    /**
     * 同步附件的 MIME 与元数据到目标格式
     *
     * 仅更新成功转换的文件对应的记录，未转换成功（回退）的保持不变
     *
     * @param int   $attachment_id 附件 ID
     * @param array $converted_map 成功转换的源文件 basename 列表
     */
    private function sync_attachment_format( int $attachment_id, array $converted_map ): void {
        $settings = $this->get_convert_settings();
        $target   = $settings['target'];
        $mime     = isset( $this->target_mimes[ $target ] ) ? $this->target_mimes[ $target ] : 'image/webp';

        // _wp_attached_file（相对路径）
        $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

        if ( is_string( $attached_file ) && '' !== $attached_file && isset( $converted_map[ basename( $attached_file ) ] ) ) {
            update_post_meta( $attachment_id, '_wp_attached_file', $this->replace_extension( $attached_file, $target ) );
        }

        // 附件元数据：主文件、original_image、各尺寸
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( is_array( $metadata ) ) {
            $changed = false;

            if ( ! empty( $metadata['file'] ) && isset( $converted_map[ basename( $metadata['file'] ) ] ) ) {
                $metadata['file'] = $this->replace_extension( $metadata['file'], $target );
                $changed          = true;
            }

            if ( ! empty( $metadata['original_image'] ) && isset( $converted_map[ basename( $metadata['original_image'] ) ] ) ) {
                $metadata['original_image'] = $this->replace_extension( $metadata['original_image'], $target );
                $changed                    = true;
            }

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                    if ( empty( $size_data['file'] ) || ! isset( $converted_map[ basename( $size_data['file'] ) ] ) ) {
                        continue;
                    }

                    $metadata['sizes'][ $size_name ]['file']      = $this->replace_extension( $size_data['file'], $target );
                    $metadata['sizes'][ $size_name ]['mime-type'] = $mime;
                    $changed                                      = true;
                }
            }

            if ( $changed ) {
                wp_update_attachment_metadata( $attachment_id, $metadata );
            }
        }

        // MIME 类型（转换后原格式文件已不存在）
        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => $mime,
            )
        );
    }

    /**
     * 构建编码命令
     *
     * 复用压缩工具的二进制查找与质量设置
     *
     * @param string $source     编码输入文件路径
     * @param string $output     编码输出文件路径
     * @param string $target     目标格式 webp|avif
     * @return string|false 完整命令或 false
     */
    private function build_encode_command( string $source, string $output, string $target ) {
        $tool_name = isset( $this->target_tools[ $target ] ) ? $this->target_tools[ $target ] : '';
        $tools     = libre_compress()->compressor->get_tools();

        if ( '' === $tool_name || empty( $tools[ $tool_name ] ) || ! $tools[ $tool_name ]->is_tool_available() ) {
            return false;
        }

        $binary     = escapeshellarg( $tools[ $tool_name ]->get_tool_binary_path() );
        $settings   = get_option( 'libre_compress_tools', array() );
        $source_esc = escapeshellarg( $source );
        $output_esc = escapeshellarg( $output );

        if ( 'webp' === $target ) {
            $mode = isset( $settings['webp_mode'] ) ? $settings['webp_mode'] : 'lossy';

            if ( 'lossless' === $mode ) {
                return $binary . ' -quiet -lossless -z 9 ' . $source_esc . ' -o ' . $output_esc;
            }

            $quality = isset( $settings['webp_quality'] ) ? absint( $settings['webp_quality'] ) : 80;
            $quality = max( 0, min( 100, $quality ) );

            return $binary . ' -quiet -mt ' . sprintf( '-q %d', $quality ) . ' ' . $source_esc . ' -o ' . $output_esc;
        }

        $mode = isset( $settings['avif_mode'] ) ? $settings['avif_mode'] : 'lossy';

        if ( 'lossless' === $mode ) {
            return $binary . ' -j 4 --lossless ' . $source_esc . ' ' . $output_esc;
        }

        $quality = isset( $settings['avif_quality'] ) ? absint( $settings['avif_quality'] ) : 80;
        $quality = max( 0, min( 100, $quality ) );

        return $binary . ' -j 4 ' . sprintf( '-q %d', $quality ) . ' ' . $source_esc . ' ' . $output_esc;
    }

    /**
     * 用 GD 将 GIF 解码为 PNG（仅用于静态 GIF，动画 GIF 走专用工具）
     *
     * @param string $gif_path GIF 源路径
     * @param string $png_path PNG 输出路径
     * @return bool 是否成功
     */
    private function decode_gif_to_png( string $gif_path, string $png_path ): bool {
        if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $image = @imagecreatefromgif( $gif_path );

        if ( false === $image ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $ok = @imagepng( $image, $png_path );
        imagedestroy( $image );

        return $ok && file_exists( $png_path ) && filesize( $png_path ) > 0;
    }

    /**
     * 用 resvg 将 SVG 栅格化为 PNG
     *
     * @param string $svg_path SVG 源路径
     * @param string $png_path PNG 输出路径
     * @return bool 是否成功
     */
    private function rasterize_svg_to_png( string $svg_path, string $png_path ): bool {
        $binary = $this->get_resvg_path();

        if ( false === $binary ) {
            return false;
        }

        $command = escapeshellarg( $binary ) . ' ' . escapeshellarg( $svg_path ) . ' ' . escapeshellarg( $png_path );

        $output = array();
        $rc     = 0;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec( '(' . $command . ') 2>&1', $output, $rc );
        clearstatcache( true, $png_path );

        return 0 === $rc && file_exists( $png_path ) && filesize( $png_path ) > 0;
    }

    /**
     * 检测 GIF 是否为动画（解析 GIF 块结构统计图像帧数）
     *
     * @param string $gif_path GIF 文件路径
     * @return bool 是否为动画
     */
    private function is_animated_gif( string $gif_path ): bool {
        $size = filesize( $gif_path );

        if ( false === $size ) {
            return false;
        }

        // 超大 GIF 基本都是动画，跳过解析
        if ( $size > 30 * 1024 * 1024 ) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $data = file_get_contents( $gif_path );

        if ( false === $data || strlen( $data ) < 14 || 'GIF' !== substr( $data, 0, 3 ) ) {
            return false;
        }

        // 跳过文件头（签名 6 字节 + 逻辑屏幕描述符 7 字节）与全局调色板
        $pos   = 13;
        $flags = ord( $data[10] );

        if ( $flags & 0x80 ) {
            $pos += 3 * ( 2 << ( $flags & 0x07 ) );
        }

        $length = strlen( $data );
        $frames = 0;

        while ( $pos < $length ) {
            $block = ord( $data[ $pos ] );

            if ( 0x21 === $block ) {
                // 扩展块：跳过子块序列（长度前缀，0 结束）
                $pos += 2;
                while ( $pos < $length ) {
                    $chunk = ord( $data[ $pos ] );
                    $pos++;
                    if ( 0 === $chunk ) {
                        break;
                    }
                    $pos += $chunk;
                }
            } elseif ( 0x2C === $block ) {
                // 图像描述符：一帧
                $frames++;
                if ( $frames > 1 ) {
                    return true;
                }
                $pos += 9;
                $local_flags = ord( $data[ $pos ] );
                $pos++;
                if ( $local_flags & 0x80 ) {
                    $pos += 3 * ( 2 << ( $local_flags & 0x07 ) );
                }
                // LZW 最小码长字节，其后才是数据子块序列
                $pos++;
                while ( $pos < $length ) {
                    $chunk = ord( $data[ $pos ] );
                    $pos++;
                    if ( 0 === $chunk ) {
                        break;
                    }
                    $pos += $chunk;
                }
            } else {
                // 块结束符或未知结构，停止解析
                break;
            }
        }

        return $frames > 1;
    }

    /**
     * 构建动画 GIF 的转换命令
     *
     * WebP 用 gif2webp（libwebp 套件）直接转换；
     * AVIF 无 GIF 输入能力，先由 ffmpeg 无损解码为 Y4M 序列，再由 avifenc 编码为动画 AVIF
     *
     * @param string $gif_path GIF 源路径
     * @param string $output   输出文件路径
     * @param string $target   目标格式 webp|avif
     * @return array|false array( command, temp )，工具不可用时返回 false
     */
    private function build_animated_gif_command( string $gif_path, string $output, string $target ) {
        $settings = get_option( 'libre_compress_tools', array() );
        $gif_esc  = escapeshellarg( $gif_path );
        $out_esc  = escapeshellarg( $output );

        if ( 'webp' === $target ) {
            $binary = $this->find_local_tool( 'gif2webp' );

            if ( false === $binary ) {
                return false;
            }

            $mode = isset( $settings['webp_mode'] ) ? $settings['webp_mode'] : 'lossy';

            // gif2webp 默认即无损编码，有损模式需显式开启并指定质量
            if ( 'lossy' !== $mode ) {
                $command = escapeshellarg( $binary ) . ' ' . $gif_esc . ' -o ' . $out_esc;
            } else {
                $quality = isset( $settings['webp_quality'] ) ? absint( $settings['webp_quality'] ) : 80;
                $quality = max( 0, min( 100, $quality ) );

                $command = escapeshellarg( $binary ) . ' -lossy ' . sprintf( '-q %d', $quality ) . ' ' . $gif_esc . ' -o ' . $out_esc;
            }

            return array(
                'command' => $command,
                'temp'    => '',
            );
        }

        $ffmpeg = $this->find_local_tool( 'ffmpeg' );
        $avifenc = $this->find_local_tool( 'avifenc' );

        if ( false === $ffmpeg || false === $avifenc ) {
            return false;
        }

        // Y4M 中间文件保留 GIF 的完整帧序列，由 avifenc 读取全部帧
        $temp_y4m = $gif_path . '.tmp-anim.y4m';

        $mode    = isset( $settings['avif_mode'] ) ? $settings['avif_mode'] : 'lossy';
        $quality = isset( $settings['avif_quality'] ) ? absint( $settings['avif_quality'] ) : 80;
        $quality = max( 0, min( 100, $quality ) );

        if ( 'lossless' === $mode ) {
            $encode_args = '--lossless';
        } else {
            $encode_args = sprintf( '-q %d', $quality );
        }

        $command = escapeshellarg( $ffmpeg ) . ' -y -loglevel error -i ' . $gif_esc
            . ' -pix_fmt yuv420p -f yuv4mpegpipe ' . escapeshellarg( $temp_y4m )
            . ' && ' . escapeshellarg( $avifenc ) . ' -j 4 ' . $encode_args . ' '
            . escapeshellarg( $temp_y4m ) . ' ' . $out_esc;

        return array(
            'command' => $command,
            'temp'    => $temp_y4m,
        );
    }

    /**
     * 查找本地辅助工具（resvg、gif2webp、ffmpeg 等）
     *
     * 优先 wp-content/LibreCompress-bin 目录，其次系统 PATH。
     * 设置页复用此方法展示辅助工具的安装状态与路径。
     *
     * @param string $name 可执行文件名
     * @return string|false 路径或 false
     */
    public function find_local_tool( string $name ) {
        static $cache = array();

        if ( isset( $cache[ $name ] ) ) {
            return $cache[ $name ];
        }

        // exec() 不可用时跳过系统 PATH 查找（bin 目录查找无需 exec）
        if ( ! function_exists( 'exec' ) || in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
            $is_windows_early = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
            $bin_path_early   = LIBRE_COMPRESS_BIN_PATH . $name . ( $is_windows_early ? '.exe' : '' );

            $cache[ $name ] = file_exists( $bin_path_early ) ? $bin_path_early : false;
            return $cache[ $name ];
        }

        $is_windows = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
        $bin_path   = LIBRE_COMPRESS_BIN_PATH . $name;

        if ( $is_windows ) {
            $bin_path .= '.exe';
        }

        if ( file_exists( $bin_path ) ) {
            $cache[ $name ] = $bin_path;
            return $bin_path;
        }

        $command = $is_windows
            ? 'where ' . escapeshellarg( $name ) . ' 2>nul'
            : 'which ' . escapeshellarg( $name ) . ' 2>/dev/null';

        $output = array();
        $rc     = 0;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec( '(' . $command . ') 2>&1', $output, $rc );

        if ( 0 === $rc && ! empty( $output[0] ) ) {
            $path = trim( $output[0] );
            if ( file_exists( $path ) ) {
                $cache[ $name ] = $path;
                return $path;
            }
        }

        $cache[ $name ] = false;
        return false;
    }

    /**
     * 查找 resvg 可执行文件
     *
     * 优先 wp-content/LibreCompress-bin 目录，其次系统 PATH
     *
     * @return string|false 路径或 false
     */
    private function get_resvg_path() {
        return $this->find_local_tool( 'resvg' );
    }

    /**
     * 上传后自动转换
     *
     * @param array $metadata      附件元数据
     * @param int   $attachment_id 附件 ID
     * @return array
     */
    public function auto_convert_on_upload( $metadata, $attachment_id ) {
        // 恢复原图流程中重新生成元数据时不再触发转换，避免循环
        if ( $this->restoring || ! $this->is_conversion_enabled() ) {
            return $metadata;
        }

        $mime_type = get_post_mime_type( $attachment_id );

        if ( ! in_array( $mime_type, array_values( $this->format_mimes ), true ) ) {
            return $metadata;
        }

        $this->convert_attachment( $attachment_id );

        // 转换可能已替换文件并同步元数据，返回最新元数据
        return wp_get_attachment_metadata( $attachment_id );
    }

    /**
     * 恢复原图后的反向处理
     *
     * 备份系统已把原格式文件复制回原位置，这里移除新格式文件、
     * 还原 MIME 并重新生成元数据，最后清除转换记录
     *
     * @param int $attachment_id 附件 ID
     */
    public function handle_after_restore( int $attachment_id ): void {
        $records = get_post_meta( $attachment_id, self::CONVERTED_META_KEY, true );

        if ( ! is_array( $records ) || empty( $records ) ) {
            return;
        }

        $this->restoring = true;

        $first_from = '';

        foreach ( $records as $record ) {
            if ( empty( $record['to'] ) ) {
                continue;
            }

            // 移除转换产生的新格式文件
            if ( file_exists( $record['to'] ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $record['to'] );
            }

            if ( '' === $first_from && ! empty( $record['from'] ) ) {
                $first_from = $record['from'];
            }
        }

        // 按备份的原文件扩展名还原 MIME
        if ( '' !== $first_from ) {
            $extension = strtolower( pathinfo( $first_from, PATHINFO_EXTENSION ) );
            if ( 'jpeg' === $extension ) {
                $extension = 'jpg';
            }

            if ( isset( $this->format_mimes[ $extension ] ) ) {
                wp_update_post(
                    array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $this->format_mimes[ $extension ],
                    )
                );
            }

            // 原图已恢复，重新生成元数据（含缩略图）
            if ( file_exists( $first_from ) ) {
                if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }

                if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
                    $metadata = wp_generate_attachment_metadata( $attachment_id, $first_from );
                    wp_update_attachment_metadata( $attachment_id, $metadata );
                }
            }
        }

        delete_post_meta( $attachment_id, self::CONVERTED_META_KEY );

        $this->restoring = false;
    }

    /**
     * 将 URL 映射到 uploads 目录内的本地路径
     *
     * 不要求原格式文件仍然存在（转换后已被移除）
     *
     * @param string $url 图片 URL
     * @return array|null array( local_path, url_path ) 或 null
     */
    private function resolve_url_to_local( string $url ) {
        $upload   = wp_get_upload_dir();
        $base_url = isset( $upload['baseurl'] ) ? $upload['baseurl'] : '';
        $base_dir = isset( $upload['basedir'] ) ? $upload['basedir'] : '';

        if ( '' === $base_url || '' === $base_dir ) {
            return null;
        }

        $url_path  = wp_parse_url( $url, PHP_URL_PATH );
        $base_path = wp_parse_url( $base_url, PHP_URL_PATH );

        if ( ! is_string( $url_path ) || '' === $url_path || ! is_string( $base_path ) || '' === $base_path ) {
            return null;
        }

        if ( 0 !== strpos( $url_path, $base_path ) ) {
            return null;
        }

        $relative = substr( $url_path, strlen( $base_path ) );

        // 防目录穿越
        if ( false !== strpos( $relative, '..' ) ) {
            return null;
        }

        return array(
            'local_path' => $base_dir . str_replace( '/', DIRECTORY_SEPARATOR, $relative ),
            'url_path'   => $url_path,
        );
    }

    /**
     * 文章内容中的旧格式 URL 替换
     *
     * URL 指向已转换格式的文件（同名新格式文件存在）时替换扩展名。
     * 原格式文件转换后已从 uploads 移除，不替换则只能是死链。
     *
     * @param string $content 文章内容
     * @return string
     */
    public function filter_content( $content ) {
        if ( ! is_string( $content ) || '' === $content || ! $this->is_conversion_enabled() ) {
            return $content;
        }

        return preg_replace_callback(
            '/[^\s"\'<>()]+\.(?:png|jpe?g|gif|svg)(?=[\s"\'<>()]|$)/i',
            array( $this, 'replace_content_url' ),
            $content
        );
    }

    /**
     * the_content 回调：命中 URL 时尝试替换为新格式
     *
     * @param array $matches 正则匹配
     * @return string 原始或替换后的 URL
     */
    private function replace_content_url( $matches ): string {
        $url      = $matches[0];
        $settings = $this->get_convert_settings();

        if ( empty( $settings['formats'] ) ) {
            return $url;
        }

        $resolved = $this->resolve_url_to_local( $url );

        if ( null === $resolved ) {
            return $url;
        }

        $extension = strtolower( pathinfo( $resolved['local_path'], PATHINFO_EXTENSION ) );
        if ( 'jpeg' === $extension ) {
            $extension = 'jpg';
        }

        if ( ! in_array( $extension, $settings['formats'], true ) ) {
            return $url;
        }

        $target_path = $this->get_target_path( $resolved['local_path'], $settings['target'] );

        if ( ! file_exists( $target_path ) ) {
            return $url;
        }

        $new_url_path = substr( $resolved['url_path'], 0, -strlen( $extension ) ) . $settings['target'];

        return str_replace( $resolved['url_path'], $new_url_path, $url );
    }

    /**
     * AJAX: 获取未转换的附件列表
     *
     * 附件 MIME 仍为源格式即视为未转换（转换后 MIME 已更新）
     */
    public function ajax_get_unconverted() {
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        $settings = $this->get_convert_settings();

        if ( empty( $settings['formats'] ) ) {
            wp_send_json_error( array( 'message' => __( '尚未启用任何格式的转换', 'libre-compress' ) ) );
        }

        $mimes = array();
        foreach ( $settings['formats'] as $format ) {
            $mimes[] = $this->format_mimes[ $format ];
        }

        global $wpdb;
        $mime_placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $attachment_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_mime_type IN ( {$mime_placeholders} )
                LIMIT 2000",
                $mimes
            )
        );

        $compressor = libre_compress()->compressor;

        // 转换会同时处理原图和缩略图，因此每个附件只创建一个任务。
        // 若按尺寸创建任务，后端处理整个附件时会重复转换同一批文件。
        $items = array();
        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            $files         = $compressor->get_attachment_files( $attachment_id );

            foreach ( $files as $file ) {
                $extension = strtolower( pathinfo( $file['file_path'], PATHINFO_EXTENSION ) );
                if ( 'jpeg' === $extension ) {
                    $extension = 'jpg';
                }

                if ( ! in_array( $extension, $settings['formats'], true ) ) {
                    continue;
                }

                // 整个附件由单次 AJAX 请求处理，不再按尺寸重复提交。
                $items[] = array(
                    'attachment_id' => $attachment_id,
                );
                break;
            }
        }

        wp_send_json_success(
            array(
                'total' => count( $items ),
                'items' => $items,
            )
        );
    }

    /**
     * AJAX: 转换单个附件
     */
    public function ajax_convert_single() {
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( array( 'message' => __( '无效的附件 ID', 'libre-compress' ) ) );
        }

        $result = $this->convert_attachment( $attachment_id );

        wp_send_json_success( $result );
    }

    /**
     * 检查文件路径是否安全（必须位于上传目录内）
     *
     * @param string $file_path 文件路径
     * @return bool 是否安全
     */
    private function is_safe_path( string $file_path ): bool {
        $upload_dir = wp_get_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $file_path );

        if ( false === $real_path || false === $base_dir ) {
            return false;
        }

        if ( 0 !== strpos( $real_path, $base_dir ) ) {
            return false;
        }

        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        return true;
    }
}
