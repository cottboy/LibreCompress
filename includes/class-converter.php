<?php
/**
 * 目标格式输出处理器
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 目标格式输出处理器
 *
 * 将勾选格式的图片（PNG/JPG/GIF/SVG）压缩输出为 WebP 或 AVIF：
 * 压缩结果小于源文件时，以同名不同扩展名的文件替换源文件，并同步更新
 * 附件 MIME 与元数据。结果不小于源文件时保留原文件。
 */
class Libre_Compress_Output {

    /**
     * 目标格式输出映射 post meta 键（供恢复原图时反向处理）
     *
     * @var string
     */
    const OUTPUT_META_KEY = '_libre_compress_output';

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
     * 支持目标格式输出的源扩展名（jpg 涵盖 jpeg）
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
     * 初始化钩子
     */
    public function init_hooks() {
        // 文章内容中已固化的源格式 URL，根据实际压缩记录替换为结果 URL。
        add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
    }

    /**
     * 获取目标格式输出设置
     *
     * @return array target: webp|avif, formats: 已勾选的源格式列表
     */
    public function get_output_settings(): array {
        $general = get_option( 'libre_compress_general', array() );

        $target = ( isset( $general['output_format'] ) && 'avif' === $general['output_format'] ) ? 'avif' : 'webp';

        $formats = array();
        foreach ( $this->source_formats as $format ) {
            if ( ! empty( $general[ 'output_' . $format ] ) ) {
                $formats[] = $format;
            }
        }

        return array(
            'target'  => $target,
            'formats' => $formats,
        );
    }

    /**
     * 判断文件是否应按设置输出为目标格式
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    public function should_output_target( string $file_path ): bool {
        $settings = $this->get_output_settings();
        $format   = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( 'jpeg' === $format ) {
            $format = 'jpg';
        }

        // WebP / AVIF 本身是可直接压缩的格式，不允许再次输出目标格式。
        if ( in_array( $format, array( 'webp', 'avif' ), true ) ) {
            return false;
        }

        return in_array( $format, $settings['formats'], true );
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
     * 将单个文件压缩为目标格式
     *
     * 结果不小于源文件时保留源文件；提交替换前按设置创建可恢复备份。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @param string $size_type     尺寸类型
     * @param string $target        本次操作固定的目标格式
     * @return array 压缩结果
     */
    public function compress_to_target_format( int $attachment_id, string $file_path, string $size_type = 'full', string $target = '' ): array {
        $result_template = array(
            'file_path'      => $file_path,
            'from'           => $file_path,
            'to'             => '',
            'status'         => 'failed',
            'message'        => '',
            'original_size'  => 0,
            'compressed_size' => 0,
        );

        if ( ! file_exists( $file_path ) ) {
            $result_template['message'] = __( '文件不存在', 'libre-compress' );
            return $result_template;
        }

        if ( ! $this->is_safe_path( $file_path ) ) {
            $result_template['message'] = __( '文件路径不安全', 'libre-compress' );
            return $result_template;
        }

        $settings       = $this->get_output_settings();
        $resolved_target = in_array( $target, array( 'webp', 'avif' ), true ) ? $target : $settings['target'];
        $target           = $resolved_target;

        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'jpeg' === $extension ) {
            $extension = 'jpg';
        }

        if ( ! in_array( $extension, $settings['formats'], true ) ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '该格式未配置目标格式输出', 'libre-compress' );
            return $result_template;
        }

        $target_path = $this->get_target_path( $file_path, $target );

        // 同名目标文件已存在时，只有确认它属于本插件的持久化映射才接管，
        // 避免把无关文件误当成压缩结果，也允许中断后的请求完成提交。
        if ( file_exists( $target_path ) ) {
            $existing_mapping = $this->get_output_record_for_file( $attachment_id, $target_path );
            $existing_record  = libre_compress()->database->get_record( $attachment_id, $size_type );
            $owned_target     = null !== $existing_mapping
                && isset( $existing_mapping['from'] )
                && $this->normalized_path( $existing_mapping['from'] ) === $this->normalized_path( $file_path );
            $owned_target     = $owned_target || (
                is_array( $existing_record )
                && 'success' === $existing_record['status']
                && $this->normalized_path( $existing_record['file_path'] ) === $this->normalized_path( $this->get_relative_upload_path( $target_path ) )
            );
            $source_backup    = libre_compress()->database->get_backup_by_path( $file_path );
            $owned_target     = $owned_target || (
                is_array( $source_backup )
                && ! empty( $source_backup['backup_path'] )
                && file_exists( $source_backup['backup_path'] )
            );
            $owned_target     = $owned_target && (int) filesize( $target_path ) < (int) filesize( $file_path );

            if ( $owned_target ) {
                $original_size   = is_array( $existing_mapping ) && isset( $existing_mapping['original_size'] )
                    ? absint( $existing_mapping['original_size'] )
                    : (int) filesize( $file_path );
                $compressed_size = (int) filesize( $target_path );
                $record_saved = libre_compress()->compressor->save_compression_record(
                    $attachment_id,
                    $target_path,
                    $size_type,
                    $original_size,
                    $compressed_size,
                    'target-output',
                    'success'
                );
                if ( ! $record_saved ) {
                    $result_template['message'] = __( '压缩记录保存失败，已保留原文件', 'libre-compress' );
                    return $result_template;
                }

                if ( ! is_array( $existing_mapping )
                    && ! $this->add_output_record(
                        $attachment_id,
                        $file_path,
                        $target_path,
                        $size_type,
                        $original_size,
                        $compressed_size
                    ) ) {
                    $result_template['message'] = __( '恢复映射保存失败，已保留原文件', 'libre-compress' );
                    return $result_template;
                }

                return array(
                    'file_path'              => $target_path,
                    'from'                   => $file_path,
                    'to'                     => $target_path,
                    'size_type'              => $size_type,
                    'status'                 => 'success',
                    'message'                => __( '压缩成功', 'libre-compress' ),
                    'original_size'          => $original_size,
                    'compressed_size'        => $compressed_size,
                    'tool_name'              => 'target-output',
                    'source_cleanup_deferred' => true,
                );
            }

            $result_template['to']             = $target_path;
            $result_template['status']         = 'failed';
            $result_template['message']        = __( '同名目标文件已存在', 'libre-compress' );
            $result_template['original_size']  = (int) filesize( $file_path );
            $result_template['compressed_size'] = (int) filesize( $target_path );
            return $result_template;
        }

        $original_size = (int) filesize( $file_path );
        $temp_token    = wp_generate_password( 12, false, false );

        // 编码到同目录唯一临时文件，正式提交前源文件保持不变。
        $temp_output = $file_path . '.lc-compress-' . $temp_token . '.' . $target;
        $encode_ok   = false;
        $error_msg   = '';
        $temp_source = '';
        $tool_name   = $target;

        if ( 'gif' === $extension ) {
            // 动画 GIF 使用专用工具，静态 GIF 先由 GD 解码为 PNG。
            if ( $this->is_animated_gif( $file_path ) ) {
                $animated = $this->build_animated_gif_command( $file_path, $temp_output, $target );

                if ( false === $animated ) {
                    // 缺工具：环境未配齐，记为跳过
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = ( 'webp' === $target )
                        ? __( '动画 GIF 压缩为 WebP 需要 gif2webp 工具（libwebp 套件）', 'libre-compress' )
                        : __( '动画 GIF 压缩为 AVIF 需要 ffmpeg', 'libre-compress' );
                    return $result_template;
                }

                $command     = $animated['command'];
                $temp_source = $animated['temp'];
                $tool_name   = 'webp' === $target ? 'gif2webp' : 'ffmpeg+avifenc';
            } else {
                $temp_source = $file_path . '.lc-source-' . $temp_token . '.png';
                if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
                    // 缺 GD 扩展：环境未配齐，记为跳过
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = __( '静态 GIF 压缩需要 GD 扩展', 'libre-compress' );
                    return $result_template;
                }
                if ( ! $this->decode_gif_to_png( $file_path, $temp_source ) ) {
                    if ( file_exists( $temp_source ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        unlink( $temp_source );
                    }
                    $result_template['message'] = __( 'GIF 压缩预处理失败', 'libre-compress' );
                    return $result_template;
                }
                $command = $this->build_encode_command( $temp_source, $temp_output, $target );
            }
        } elseif ( 'svg' === $extension ) {
            // SVG 需先栅格化为 PNG 中间文件
            $temp_source = $file_path . '.lc-source-' . $temp_token . '.png';
            if ( false === $this->get_resvg_path() ) {
                // 缺 resvg：环境未配齐，记为跳过
                $result_template['status']  = 'skipped';
                $result_template['message'] = __( 'SVG 压缩需要 resvg 工具', 'libre-compress' );
                return $result_template;
            }
            if ( ! $this->rasterize_svg_to_png( $file_path, $temp_source ) ) {
                if ( file_exists( $temp_source ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_source );
                }
                $result_template['message'] = __( 'SVG 压缩预处理失败', 'libre-compress' );
                return $result_template;
            }
            $command = $this->build_encode_command( $temp_source, $temp_output, $target );
        } else {
            // PNG/JPG 由编码工具直接支持
            $command = $this->build_encode_command( $file_path, $temp_output, $target );
        }

        if ( false === $command ) {
            // 缺编码工具（cwebp/avifenc）：环境未配齐，记为跳过
            $error_msg                 = __( '没有可用的压缩工具', 'libre-compress' );
            $result_template['status'] = 'skipped';
        } else {
            $exec_result = Libre_Compress_Tool_Base::run_command( $command );
            clearstatcache( true, $temp_output );

            if ( ! $exec_result['success'] || ! file_exists( $temp_output ) || filesize( $temp_output ) <= 0 ) {
                $error_msg = __( '压缩失败', 'libre-compress' );

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

        $compressed_size = (int) filesize( $temp_output );

        if ( $compressed_size >= $original_size ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );

            $message = __( '压缩结果没有变小，已保留原文件', 'libre-compress' );
            libre_compress()->compressor->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool_name,
                'skipped',
                $message
            );

            $result_template['status']          = 'skipped';
            $result_template['message']         = $message;
            $result_template['original_size']   = $original_size;
            $result_template['compressed_size'] = $original_size;
            return $result_template;
        }

        // 只在确定要提交替换时创建备份；备份失败必须终止。
        $settings_general = get_option( 'libre_compress_general', array() );
        $backup_enabled   = isset( $settings_general['backup_enabled'] ) ? (bool) $settings_general['backup_enabled'] : true;
        if ( $backup_enabled
            && ! libre_compress()->backup->create_backup( $attachment_id, $file_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '无法创建原图备份，已停止压缩', 'libre-compress' );
            return $result_template;
        }

        // 先复制到目标路径并保留临时文件，状态写入失败时源文件仍然完好。
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $temp_output, $target_path ) || filesize( $target_path ) !== $compressed_size ) {
            if ( file_exists( $target_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $target_path );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '压缩结果文件写入失败', 'libre-compress' );
            return $result_template;
        }

        $record_saved = libre_compress()->compressor->save_compression_record(
            $attachment_id,
            $target_path,
            $size_type,
            $original_size,
            $compressed_size,
            $tool_name,
            'success'
        );

        if ( ! $record_saved ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '压缩记录保存失败，已保留原文件', 'libre-compress' );
            return $result_template;
        }

        $mapping_saved = $this->add_output_record(
            $attachment_id,
            $file_path,
            $target_path,
            $size_type,
            $original_size,
            $compressed_size
        );

        if ( ! $mapping_saved ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );
            libre_compress()->compressor->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool_name,
                'failed',
                __( '恢复映射保存失败，已保留原文件', 'libre-compress' )
            );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '恢复映射保存失败，已保留原文件', 'libre-compress' );
            return $result_template;
        }

        // 源文件由统一处理器在 metadata/MIME 提交成功后再清理。
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $temp_output );

        return array(
            'file_path'              => $target_path,
            'from'                   => $file_path,
            'to'                     => $target_path,
            'size_type'              => $size_type,
            'status'                 => 'success',
            'message'                => __( '压缩成功', 'libre-compress' ),
            'original_size'          => $original_size,
            'compressed_size'        => $compressed_size,
            'tool_name'              => $tool_name,
            'source_cleanup_deferred' => true,
        );
    }

    /**
     * 在附件路径和元数据提交成功后清理源文件
     *
     * @param string $source_path 源文件绝对路径
     * @return bool
     */
    public function remove_source_file( string $source_path ): bool {
        if ( ! file_exists( $source_path ) ) {
            return true;
        }

        if ( ! $this->is_safe_path( $source_path ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return unlink( $source_path );
    }

    /**
     * 查找当前文件对应的目标格式输出映射
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     当前文件路径
     * @return array|null
     */
    public function get_output_record_for_file( int $attachment_id, string $file_path ): ?array {
        $records = get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );
        if ( ! is_array( $records ) ) {
            return null;
        }

        $current = $this->normalized_path( $file_path );
        foreach ( $records as $record ) {
            if ( empty( $record['to'] ) || $this->normalized_path( $record['to'] ) !== $current ) {
                continue;
            }

            if ( file_exists( $record['to'] ) && $this->is_safe_path( $record['to'] ) ) {
                return $record;
            }
        }

        return null;
    }

    /**
     * 追加目标格式输出映射，供恢复原图时反向处理
     *
     * @param int    $attachment_id  附件 ID
     * @param string $from_path      源文件路径
     * @param string $to_path        压缩结果路径
     * @param string $size_type      尺寸类型
     * @param int    $original_size  源文件大小
     * @param int    $compressed_size 压缩结果大小
     * @return bool 是否保存成功
     */
    private function add_output_record(
        int $attachment_id,
        string $from_path,
        string $to_path,
        string $size_type,
        int $original_size,
        int $compressed_size
    ): bool {
        $records = get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );

        if ( ! is_array( $records ) ) {
            $records = array();
        }

        $records[] = array(
            'from'            => $from_path,
            'to'              => $to_path,
            'size_type'       => sanitize_text_field( $size_type ),
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
        );

        return false !== update_post_meta( $attachment_id, self::OUTPUT_META_KEY, $records );
    }

    /**
     * 同步目标格式输出后的附件路径与元数据
     *
     * 附件级 MIME 只跟随主文件；部分尺寸成功不会伪装成整个附件已输出目标格式。
     *
     * @param int    $attachment_id 附件 ID
     * @param array  $output_map size_type => array( from, to )
     * @param string $target        目标格式
     * @return bool 是否同步成功
     */
    public function sync_attachment_format( int $attachment_id, array $output_map, string $target ): bool {
        $mime    = isset( $this->target_mimes[ $target ] ) ? $this->target_mimes[ $target ] : 'image/webp';
        $success = true;
        $original_attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $original_metadata = wp_get_attachment_metadata( $attachment_id );
        $original_mime     = get_post_mime_type( $attachment_id );
        $full    = isset( $output_map['full'] ) ? $output_map['full'] : null;
        $full_attached_synced = false;
        $full_metadata_synced = false;
        $from_relative = '';
        $to_relative   = '';

        if ( is_array( $full ) && ! empty( $full['from'] ) && ! empty( $full['to'] ) ) {
            $from_relative = $this->get_relative_upload_path( $full['from'] );
            $to_relative   = $this->get_relative_upload_path( $full['to'] );
            $metadata_snapshot = wp_get_attachment_metadata( $attachment_id );
            $full_metadata_exists = is_array( $metadata_snapshot ) && ! empty( $metadata_snapshot['file'] )
                && (
                    $this->normalized_path( $metadata_snapshot['file'] ) === $this->normalized_path( $from_relative )
                    || $this->normalized_path( $metadata_snapshot['file'] ) === $this->normalized_path( $to_relative )
                );

            if ( ! $full_metadata_exists ) {
                $success = false;
            }

            if ( ! $full_metadata_exists ) {
                $full_attached_synced = false;
            } elseif ( function_exists( 'update_attached_file' ) ) {
                $full_attached_synced = false !== update_attached_file( $attachment_id, $full['to'] );
            } else {
                $full_attached_synced = false !== update_post_meta( $attachment_id, '_wp_attached_file', $to_relative );
            }
            if ( ! $full_attached_synced ) {
                $success = false;
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( is_array( $metadata ) ) {
            $changed = false;

            if ( is_array( $full ) && ! empty( $metadata['file'] )
                && (
                    $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $from_relative )
                    || $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $to_relative )
                ) ) {
                $metadata['file']      = $this->get_relative_upload_path( $full['to'] );
                $metadata['filesize']   = is_file( $full['to'] ) ? (int) filesize( $full['to'] ) : 0;
                $full_metadata_synced   = true;
                $changed                = true;
            }

            if ( ! empty( $metadata['original_image'] ) && isset( $output_map['original_image'] ) ) {
                $entry = $output_map['original_image'];
                $from_name = basename( $entry['from'] );
                $to_name   = basename( $entry['to'] );
                if ( $this->normalized_path( $metadata['original_image'] ) === $this->normalized_path( $from_name )
                    || $this->normalized_path( $metadata['original_image'] ) === $this->normalized_path( $to_name ) ) {
                    $metadata['original_image'] = $to_name;
                    $changed                    = true;
                }
            }

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                    if ( empty( $size_data['file'] ) || ! isset( $output_map[ $size_name ] ) ) {
                        continue;
                    }

                    $entry    = $output_map[ $size_name ];
                    $from_name = basename( $entry['from'] );
                    $to_name   = basename( $entry['to'] );
                    if ( $this->normalized_path( $size_data['file'] ) !== $this->normalized_path( $from_name )
                        && $this->normalized_path( $size_data['file'] ) !== $this->normalized_path( $to_name ) ) {
                        continue;
                    }

                    $metadata['sizes'][ $size_name ]['file']      = $to_name;
                    $metadata['sizes'][ $size_name ]['mime-type'] = $mime;
                    $changed                                      = true;
                }
            }

            if ( $changed && false === wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
                $success = false;
            }
        }

        if ( $success && $full_attached_synced && $full_metadata_synced ) {
            $post_updated = wp_update_post(
                array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $mime,
                ),
                true
            );
            if ( is_wp_error( $post_updated ) || $mime !== get_post_mime_type( $attachment_id ) ) {
                $success = false;
            }
        } elseif ( is_array( $full ) ) {
            $success = false;
        }

        if ( ! $success ) {
            if ( is_string( $original_attached ) ) {
                update_post_meta( $attachment_id, '_wp_attached_file', $original_attached );
            } else {
                delete_post_meta( $attachment_id, '_wp_attached_file' );
            }
            if ( is_array( $original_metadata ) ) {
                wp_update_attachment_metadata( $attachment_id, $original_metadata );
            } else {
                delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
            }
            if ( is_string( $original_mime ) ) {
                wp_update_post(
                    array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $original_mime,
                    )
                );
            }
        }

        return $success;
    }

    /**
     * 获取上传目录内的相对路径
     *
     * @param string $path 绝对路径或相对路径
     * @return string
     */
    private function get_relative_upload_path( string $path ): string {
        $upload_dir = wp_upload_dir();
        $normalized = $this->normalized_path( $path );
        $base_dir   = $this->normalized_path( $upload_dir['basedir'] );

        if ( 0 === strpos( $normalized, $base_dir . '/' ) ) {
            return ltrim( substr( $normalized, strlen( $base_dir ) ), '/' );
        }

        return ltrim( $normalized, '/' );
    }

    /**
     * 规范化路径用于精确比较
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
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

        if ( '' === $tool_name || empty( $tools[ $tool_name ] ) ) {
            return false;
        }

        $tool_available = 'avifenc' === $tool_name && method_exists( $tools[ $tool_name ], 'has_encoder' )
            ? $tools[ $tool_name ]->has_encoder()
            : $tools[ $tool_name ]->is_tool_available();

        if ( ! $tool_available ) {
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

        $exec_result = Libre_Compress_Tool_Base::run_command( $command );
        clearstatcache( true, $png_path );

        return $exec_result['success'] && file_exists( $png_path ) && filesize( $png_path ) > 0;
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
     * 构建动画 GIF 的目标格式压缩命令
     *
     * WebP 使用 gif2webp（libwebp 套件）直接压缩；
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
        $temp_y4m = $gif_path . '.lc-animation-' . wp_generate_password( 12, false, false ) . '.y4m';

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

        // 没有 exec() 就不能执行本地工具，直接返回不可用，避免后续调用不存在的函数。
        if ( ! Libre_Compress_Tool_Base::is_command_execution_available() ) {
            $cache[ $name ] = false;
            return false;
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
     * 恢复目标格式输出前的反向处理
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否完整恢复路径和元数据
     */
    public function handle_after_restore( int $attachment_id ): bool {
        $records = get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );

        if ( ! is_array( $records ) || empty( $records ) ) {
            return true;
        }

        $success      = true;
        $metadata     = wp_get_attachment_metadata( $attachment_id );
        $attached     = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $full_from    = '';
        $metadata_new = is_array( $metadata ) ? $metadata : array();

        foreach ( $records as $record ) {
            if ( empty( $record['from'] ) || empty( $record['to'] )
                || ! $this->is_safe_destination_path( $record['from'] )
                || ! $this->is_safe_destination_path( $record['to'] ) ) {
                $success = false;
                continue;
            }

            // 只处理本次确实有备份可恢复的映射，不能因历史记录删除无备份目标文件。
            $backup = libre_compress()->database->get_backup_by_path( $record['from'] );
            if ( ! $backup || empty( $backup['backup_path'] ) || ! file_exists( $backup['backup_path'] ) ) {
                $success = false;
                continue;
            }

            if ( file_exists( $record['to'] ) ) {
                if ( ! $this->is_safe_path( $record['to'] )
                    || ! unlink( $record['to'] ) ) {
                    $success = false;
                    continue;
                }
            }

            $from_relative = $this->get_relative_upload_path( $record['from'] );
            $to_relative   = $this->get_relative_upload_path( $record['to'] );
            $size_type     = isset( $record['size_type'] ) ? sanitize_text_field( $record['size_type'] ) : '';

            if ( is_string( $attached ) && '' !== $attached
                && $this->normalized_path( $attached ) === $this->normalized_path( $to_relative ) ) {
                $attached = $from_relative;
            }

            if ( ! empty( $metadata_new['file'] )
                && $this->normalized_path( $metadata_new['file'] ) === $this->normalized_path( $to_relative ) ) {
                $metadata_new['file']      = $from_relative;
                $metadata_new['filesize'] = isset( $record['original_size'] ) ? absint( $record['original_size'] ) : 0;
                $full_from                = $record['from'];
            } elseif ( 'full' === $size_type ) {
                $full_from = $record['from'];
            }

            if ( 'original_image' === $size_type && ! empty( $metadata_new['original_image'] )
                && in_array(
                    $this->normalized_path( $metadata_new['original_image'] ),
                    array(
                        $this->normalized_path( basename( $record['from'] ) ),
                        $this->normalized_path( basename( $record['to'] ) ),
                    ),
                    true
                ) ) {
                $metadata_new['original_image'] = basename( $record['from'] );
            }

            if ( ! empty( $metadata_new['sizes'] ) && is_array( $metadata_new['sizes'] ) ) {
                foreach ( $metadata_new['sizes'] as $name => $size_data ) {
                    if ( empty( $size_data['file'] ) ) {
                        continue;
                    }

                    $matches_record = $name === $size_type
                        || in_array(
                            $this->normalized_path( $size_data['file'] ),
                            array(
                                $this->normalized_path( basename( $record['from'] ) ),
                                $this->normalized_path( basename( $record['to'] ) ),
                            ),
                            true
                        );
                    if ( $matches_record ) {
                        $source_format = strtolower( pathinfo( $record['from'], PATHINFO_EXTENSION ) );
                        if ( 'jpeg' === $source_format ) {
                            $source_format = 'jpg';
                        }

                        $metadata_new['sizes'][ $name ]['file']      = basename( $record['from'] );
                        $metadata_new['sizes'][ $name ]['mime-type'] = isset( $this->format_mimes[ $source_format ] )
                            ? $this->format_mimes[ $source_format ]
                            : 'image/jpeg';
                    }
                }
            }
        }

        if ( is_string( $attached ) && '' !== $attached
            && false === update_post_meta( $attachment_id, '_wp_attached_file', $attached ) ) {
            $success = false;
        }

        if ( ! empty( $metadata_new ) && false === wp_update_attachment_metadata( $attachment_id, $metadata_new ) ) {
            $success = false;
        }

        if ( '' !== $full_from ) {
            $extension = strtolower( pathinfo( $full_from, PATHINFO_EXTENSION ) );
            if ( 'jpeg' === $extension ) {
                $extension = 'jpg';
            }

            if ( isset( $this->format_mimes[ $extension ] ) ) {
                $expected_mime = $this->format_mimes[ $extension ];
                $post_updated  = wp_update_post(
                    array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $expected_mime,
                    ),
                    true
                );
                if ( is_wp_error( $post_updated ) || $expected_mime !== get_post_mime_type( $attachment_id ) ) {
                    $success = false;
                }
            }
        }

        if ( $success && false === delete_post_meta( $attachment_id, self::OUTPUT_META_KEY ) ) {
            $success = false;
        }

        return $success;
    }

    /**
     * 将 URL 映射到 uploads 目录内的本地路径
     *
     * 不要求源文件仍然存在（目标格式输出后源文件可能已移除）
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
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        $base_host = wp_parse_url( $base_url, PHP_URL_HOST );

        if ( ! is_string( $url_path ) || '' === $url_path || ! is_string( $base_path ) || '' === $base_path ) {
            return null;
        }

        if ( is_string( $url_host ) && '' !== $url_host && $url_host !== $base_host ) {
            return null;
        }

        $base_path = untrailingslashit( $base_path );
        if ( 0 !== strpos( $url_path, $base_path . '/' ) ) {
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
     * URL 指向已产生目标格式输出的源文件时，按实际映射替换 URL。
     * 源文件移除后不替换会形成死链。
     *
     * @param string $content 文章内容
     * @return string
     */
    public function filter_content( $content ) {
        if ( ! is_string( $content ) || '' === $content ) {
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
        $resolved = $this->resolve_url_to_local( $url );

        if ( null === $resolved ) {
            return $url;
        }

        $output_path = $this->find_output_path_for_source( $resolved['local_path'] );
        if ( '' === $output_path ) {
            return $url;
        }

        $upload    = wp_upload_dir();
        $base_path = untrailingslashit( wp_parse_url( $upload['baseurl'], PHP_URL_PATH ) );
        $relative  = ltrim( $this->get_relative_upload_path( $output_path ), '/' );
        $new_path  = $base_path . '/' . $relative;

        return str_replace( $resolved['url_path'], $new_path, $url );
    }

    /**
     * 根据持久化输出映射查找源文件对应的压缩结果
     *
     * @param string $source_path 源文件绝对路径
     * @return string 结果绝对路径，找不到时返回空字符串
     */
    private function find_output_path_for_source( string $source_path ): string {
        global $wpdb;

        $normalized = $this->normalized_path( $source_path );
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = %s AND meta_value LIKE %s
                LIMIT 1",
                self::OUTPUT_META_KEY,
                '%' . $wpdb->esc_like( $normalized ) . '%'
            )
        );

        if ( ! $attachment_id ) {
            return '';
        }

        $records = get_post_meta( absint( $attachment_id ), self::OUTPUT_META_KEY, true );
        if ( ! is_array( $records ) ) {
            return '';
        }

        foreach ( $records as $record ) {
            if ( empty( $record['from'] ) || empty( $record['to'] ) ) {
                continue;
            }

            if ( $this->normalized_path( $record['from'] ) === $normalized
                && file_exists( $record['to'] ) && $this->is_safe_path( $record['to'] ) ) {
                return $record['to'];
            }
        }

        return '';
    }

    /**
     * 验证上传目录内的目标路径，允许文件当前不存在
     *
     * @param string $file_path 目标路径
     * @return bool
     */
    private function is_safe_destination_path( string $file_path ): bool {
        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $parent_dir = realpath( dirname( $file_path ) );

        if ( false === $base_dir || false === $parent_dir ) {
            return false;
        }

        $base_dir   = untrailingslashit( wp_normalize_path( $base_dir ) );
        $parent_dir = untrailingslashit( wp_normalize_path( $parent_dir ) );

        return 0 === strpos( $parent_dir . '/', $base_dir . '/' );
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

        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $real_path = untrailingslashit( wp_normalize_path( $real_path ) );

        if ( 0 !== strpos( $real_path, $base_dir . '/' ) ) {
            return false;
        }

        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        return true;
    }
}
