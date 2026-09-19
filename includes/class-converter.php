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
 * 将勾选格式的图片（PNG/JPG/GIF/SVG）转换为 WebP 或 AVIF 副本，
 * 并在前端直接以新格式 URL 显示（不考虑浏览器兼容性）。
 *
 * 副本为双扩展名 sidecar 文件（如 photo.png.webp），转换产物不小于
 * 源文件时放弃副本（回退），源文件永远不会被改动。
 */
class Libre_Compress_Converter {

    /**
     * 目标格式对应的编码工具注册名
     *
     * @var array
     */
    private $target_tools = array(
        'webp' => 'cwebp',
        'avif' => 'libavif',
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
     * 缓存的 resvg 路径
     *
     * @var string|false|null
     */
    private $resvg_path = null;

    /**
     * 初始化钩子
     */
    public function init_hooks() {
        // 上传后自动转换（优先级 20，晚于压缩钩子的 10，转换基准为压缩后的文件）
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_convert_on_upload' ), 20, 2 );

        // 前端 URL 硬替换：直接输出新格式 URL，不检测浏览器支持
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 10 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10 );
        add_filter( 'the_content', array( $this, 'filter_content' ), 20 );

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
     * 获取 sidecar 副本路径
     *
     * @param string $file_path 源文件路径
     * @param string $target    目标格式
     * @return string 副本路径
     */
    private function get_sidecar_path( string $file_path, string $target ): string {
        return $file_path . '.' . $target;
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

        foreach ( $files as $file ) {
            $result           = $this->convert_file( $file['file_path'] );
            $result['size_type'] = $file['size_type'];

            $results['details'][] = $result;

            $results['total']++;
            if ( 'success' === $result['status'] ) {
                $results['success']++;
                $results['saved_bytes'] += (int) $result['original_size'] - (int) $result['converted_size'];
            } elseif ( 'failed' === $result['status'] ) {
                $results['failed']++;
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * 转换单个文件
     *
     * 生成 {name}.{ext}.{target} 副本；转换产物不小于源文件时放弃副本
     *
     * @param string $file_path 源文件绝对路径
     * @return array 转换结果
     */
    public function convert_file( string $file_path ): array {
        $result_template = array(
            'file_path'      => $file_path,
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

        $sidecar_path = $this->get_sidecar_path( $file_path, $target );
        if ( file_exists( $sidecar_path ) ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '已存在转换副本', 'libre-compress' );
            return $result_template;
        }

        $original_size = (int) filesize( $file_path );

        // 准备编码输入源：GIF/SVG 需要先解码为 PNG 中间文件
        $source         = $file_path;
        $temp_source    = '';
        $temp_source_ok = true;

        if ( 'gif' === $extension ) {
            $temp_source    = $file_path . '.tmp-src.png';
            $temp_source_ok = $this->decode_gif_to_png( $file_path, $temp_source );
            if ( ! $temp_source_ok ) {
                $result_template['message'] = __( 'GIF 解码失败', 'libre-compress' );
                return $result_template;
            }
            $source = $temp_source;
        } elseif ( 'svg' === $extension ) {
            $temp_source    = $file_path . '.tmp-src.png';
            $temp_source_ok = $this->rasterize_svg_to_png( $file_path, $temp_source );
            if ( ! $temp_source_ok ) {
                $result_template['message'] = __( 'SVG 栅格化失败（需要 resvg 工具）', 'libre-compress' );
                return $result_template;
            }
            $source = $temp_source;
        }

        // 编码为目标格式
        $temp_output = $file_path . '.tmp-conv.' . $target;
        $encode_ok   = false;
        $error_msg   = '';

        $command = $this->build_encode_command( $source, $temp_output, $target );
        if ( false === $command ) {
            $error_msg = __( '没有可用的编码工具', 'libre-compress' );
        } else {
            $output   = array();
            $exec_rc  = 0;
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

        // 大小对比回退：转换产物不小于源文件时放弃副本（源文件从未被改动）
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

        // 原子化落地副本
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        $renamed = @rename( $temp_output, $sidecar_path );

        if ( ! $renamed ) {
            if ( file_exists( $temp_output ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $temp_output );
            }
            $result_template['message'] = __( '副本写入失败', 'libre-compress' );
            return $result_template;
        }

        return array(
            'file_path'      => $file_path,
            'sidecar_path'   => $sidecar_path,
            'status'         => 'success',
            'message'        => __( '转换成功', 'libre-compress' ),
            'original_size'  => $original_size,
            'converted_size' => $converted_size,
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

        $binary   = escapeshellarg( $tools[ $tool_name ]->get_tool_binary_path() );
        $settings = get_option( 'libre_compress_tools', array() );

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
     * 用 GD 将 GIF 解码为 PNG（动画 GIF 仅取第一帧）
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
     * 查找 resvg 可执行文件
     *
     * 优先 wp-content/LibreCompress-bin 目录，其次系统 PATH
     *
     * @return string|false 路径或 false
     */
    private function get_resvg_path() {
        if ( null !== $this->resvg_path ) {
            return $this->resvg_path;
        }

        $executable = 'resvg';
        $bin_path   = LIBRE_COMPRESS_BIN_PATH . $executable;

        if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
            $bin_path .= '.exe';
        }

        if ( file_exists( $bin_path ) ) {
            $this->resvg_path = $bin_path;
            return $this->resvg_path;
        }

        $command = ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) )
            ? 'where ' . escapeshellarg( $executable ) . ' 2>nul'
            : 'which ' . escapeshellarg( $executable ) . ' 2>/dev/null';

        $output = array();
        $rc     = 0;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec( '(' . $command . ') 2>&1', $output, $rc );

        if ( 0 === $rc && ! empty( $output[0] ) ) {
            $path = trim( $output[0] );
            if ( file_exists( $path ) ) {
                $this->resvg_path = $path;
                return $this->resvg_path;
            }
        }

        $this->resvg_path = false;
        return $this->resvg_path;
    }

    /**
     * 上传后自动转换
     *
     * @param array $metadata      附件元数据
     * @param int   $attachment_id 附件 ID
     * @return array
     */
    public function auto_convert_on_upload( $metadata, $attachment_id ) {
        if ( ! $this->is_conversion_enabled() ) {
            return $metadata;
        }

        $mime_type = get_post_mime_type( $attachment_id );

        if ( ! in_array( $mime_type, array_values( $this->format_mimes ), true ) ) {
            return $metadata;
        }

        $this->convert_attachment( $attachment_id );

        return $metadata;
    }

    /**
     * 将 URL 映射到 uploads 目录内的本地文件
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

        $url_path = wp_parse_url( $url, PHP_URL_PATH );
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

        $local_path = $base_dir . str_replace( '/', DIRECTORY_SEPARATOR, $relative );

        if ( ! file_exists( $local_path ) ) {
            return null;
        }

        return array(
            'local_path' => $local_path,
            'url_path'   => $url_path,
        );
    }

    /**
     * 重写单个 URL 为新格式副本 URL（副本存在时）
     *
     * @param string $url 图片 URL
     * @return string 重写后的 URL
     */
    private function rewrite_url( string $url ): string {
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

        $sidecar_path = $this->get_sidecar_path( $resolved['local_path'], $settings['target'] );

        if ( ! file_exists( $sidecar_path ) ) {
            return $url;
        }

        // 仅替换 URL 路径部分的扩展名，保留查询串等其他内容
        $new_url_path = substr( $resolved['url_path'], 0, -strlen( $extension ) ) . $extension . '.' . $settings['target'];

        return str_replace( $resolved['url_path'], $new_url_path, $url );
    }

    /**
     * 过滤附件 URL
     *
     * @param string $url           附件 URL
     * @param int    $attachment_id 附件 ID
     * @return string
     */
    public function filter_attachment_url( $url, $attachment_id = 0 ) {
        return $this->rewrite_url( (string) $url );
    }

    /**
     * 过滤附件图片 src
     *
     * @param array|false $image 图片数据
     * @return array|false
     */
    public function filter_image_src( $image ) {
        if ( is_array( $image ) && ! empty( $image[0] ) && is_string( $image[0] ) ) {
            $image[0] = $this->rewrite_url( $image[0] );
        }

        return $image;
    }

    /**
     * 过滤响应式图片 srcset
     *
     * @param array $sources srcset 候选列表
     * @return array
     */
    public function filter_image_srcset( $sources ) {
        if ( is_array( $sources ) ) {
            foreach ( $sources as $key => $source ) {
                if ( ! empty( $source['url'] ) && is_string( $source['url'] ) ) {
                    $sources[ $key ]['url'] = $this->rewrite_url( $source['url'] );
                }
            }
        }

        return $sources;
    }

    /**
     * 过滤文章内容中的图片 URL
     *
     * 文章内已固化的原图 URL 在副本存在时替换为新格式
     *
     * @param string $content 文章内容
     * @return string
     */
    public function filter_content( $content ) {
        if ( ! is_string( $content ) || '' === $content || ! $this->is_conversion_enabled() ) {
            return $content;
        }

        // 已带目标扩展的 URL（如 .png.webp）不会被匹配：正则要求扩展名后紧跟边界
        return preg_replace_callback(
            '/[^\s"\'<>()]+\.(?:png|jpe?g|gif|svg)(?=[\s"\'<>()]|$)/i',
            array( $this, 'replace_content_url' ),
            $content
        );
    }

    /**
     * the_content 回调：命中 URL 时尝试重写
     *
     * @param array $matches 正则匹配
     * @return string 原始或重写后的 URL
     */
    private function replace_content_url( $matches ): string {
        $url       = $matches[0];
        $rewritten = $this->rewrite_url( $url );

        return $rewritten;
    }

    /**
     * AJAX: 获取未转换的附件列表
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

        $target = $settings['target'];

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

        $items = array();
        foreach ( $attachment_ids as $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            $files         = $compressor->get_attachment_files( $attachment_id );

            foreach ( $files as $file ) {
                // 跳过已存在副本的文件
                if ( file_exists( $this->get_sidecar_path( $file['file_path'], $target ) ) ) {
                    continue;
                }

                // 跳过格式未启用的文件（同附件可能混有其他格式缩略图）
                $extension = strtolower( pathinfo( $file['file_path'], PATHINFO_EXTENSION ) );
                if ( 'jpeg' === $extension ) {
                    $extension = 'jpg';
                }
                if ( ! in_array( $extension, $settings['formats'], true ) ) {
                    continue;
                }

                $items[] = array(
                    'attachment_id' => $attachment_id,
                    'size_type'     => $file['size_type'],
                    'file_path'     => $file['file_path'],
                );
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
