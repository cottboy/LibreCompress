<?php
/**
 * 压缩调度器类
 *
 * @package LibreCompress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 压缩调度器类
 *
 * 负责管理压缩工具并执行压缩流程。
 */
class Libre_Compress_Compressor {

    /**
     * 压缩工具列表
     *
     * @var array
     */
    private $tools = array();

    /**
     * 构造函数
     */
    public function __construct() {
        $this->register_default_tools();
    }

    /**
     * 注册默认压缩工具
     */
    private function register_default_tools() {
        $this->register_tool( new Libre_Compress_Jpegoptim() );
        $this->register_tool( new Libre_Compress_Pngquant() );
        $this->register_tool( new Libre_Compress_Oxipng() );
        $this->register_tool( new Libre_Compress_Cwebp() );
        $this->register_tool( new Libre_Compress_Avif() );
        $this->register_tool( new Libre_Compress_Gifsicle() );
        $this->register_tool( new Libre_Compress_Svgo() );

        do_action( 'libre_compress_register_tools', $this );
    }

    /**
     * 注册压缩工具
     *
     * @param Libre_Compress_Tool_Base $tool 工具实例
     */
    public function register_tool( Libre_Compress_Tool_Base $tool ) {
        $this->tools[ $tool->get_name() ] = $tool;
    }

    /**
     * 获取所有压缩工具
     *
     * @return array 工具列表
     */
    public function get_tools(): array {
        return $this->tools;
    }

    /**
     * 根据文件格式获取可用的压缩工具
     *
     * @param string $extension 文件扩展名
     * @return Libre_Compress_Tool_Base|null 工具实例或 null
     */
    public function get_tool_for_format( string $extension ): ?Libre_Compress_Tool_Base {
        $extension = strtolower( $extension );

        if ( 'png' === $extension ) {
            $settings  = get_option( 'libre_compress_tools', array() );
            $png_mode  = isset( $settings['png_mode'] ) ? $settings['png_mode'] : 'lossy';
            $use_lossy = ( 'lossy' === $png_mode );

            $tool_name = $use_lossy ? 'pngquant' : 'oxipng';

            if ( isset( $this->tools[ $tool_name ] ) && $this->tools[ $tool_name ]->is_tool_available() ) {
                return $this->tools[ $tool_name ];
            }

            // 不跨有损/无损模式回退，避免实际行为偏离用户设置。
            return null;
        }

        foreach ( $this->tools as $tool ) {
            if ( in_array( $extension, $tool->get_supported_formats(), true ) && $tool->is_tool_available() ) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * 判断 WebP 是否为动画
     *
     * 静态 WebP 压缩由 cwebp 完成，cwebp 不支持动画输入，
     * 处理动画 WebP 会只保留第一帧。这里在 RIFF 容器的 chunk 序列里
     * 找 ANIM 或 ANMF 标记：有即为动画，压缩时跳过。
     *
     * @param string $file_path 文件绝对路径
     * @return bool
     */
    public static function is_animated_webp( string $file_path ): bool {
        $handle = @fopen( $file_path, 'rb' );

        if ( false === $handle ) {
            return false;
        }

        // RIFF 签名 + 文件大小 + WEBP 签名
        $header = fread( $handle, 12 );

        if ( 12 !== strlen( $header ) || 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) ) {
            fclose( $handle );
            return false;
        }

        $file_size = (int) filesize( $file_path );
        $offset    = 12;

        while ( $offset + 8 <= $file_size ) {
            $chunk_header = fread( $handle, 8 );

            if ( 8 !== strlen( $chunk_header ) ) {
                fclose( $handle );
                return false;
            }

            $chunk_size = (int) unpack( 'V', substr( $chunk_header, 4, 4 ) )[1];
            $chunk_type = substr( $chunk_header, 0, 4 );

            if ( 'ANIM' === $chunk_type || 'ANMF' === $chunk_type ) {
                fclose( $handle );
                return true;
            }

            // chunk 数据按偶数字节对齐
            $padded = $chunk_size + ( $chunk_size % 2 );

            if ( false === fseek( $handle, $padded, SEEK_CUR ) ) {
                fclose( $handle );
                return false;
            }

            $offset += 8 + $padded;
        }

        fclose( $handle );
        return false;
    }

    /**
     * 判断 PNG 是否为 APNG 动图
     *
     * APNG 向后兼容 PNG，后缀和 MIME 都是 png / image/png，只能靠 acTL 块识别。
     * pngquant、cwebp、avifenc 都只读第一帧，当成普通 PNG 处理就会把动图压成静图。
     *
     * @param string $file_path 文件绝对路径
     * @return bool
     */
    public static function is_apng( string $file_path ): bool {
        $handle = @fopen( $file_path, 'rb' );

        if ( false === $handle ) {
            return false;
        }

        // PNG 签名：89 50 4E 47 0D 0A 1A 0A
        $signature = fread( $handle, 8 );

        if ( chr( 137 ) . 'PNG' . chr( 13 ) . chr( 10 ) . chr( 26 ) . chr( 10 ) !== $signature ) {
            fclose( $handle );
            return false;
        }

        // acTL 一定在首帧 IDAT 之前；按 chunk 长度逐个扫描，避免固定块数或大小上限误判。
        $file_size = filesize( $file_path );
        $offset    = 8;

        while ( $offset + 8 <= $file_size ) {
            $header = fread( $handle, 8 );

            if ( 8 !== strlen( $header ) ) {
                fclose( $handle );
                return false;
            }

            $chunk  = unpack( 'Nlen', substr( $header, 0, 4 ) );
            $length = (int) $chunk['len'];
            $type   = substr( $header, 4, 4 );

            if ( $length > $file_size - $offset - 12 ) {
                fclose( $handle );
                return false;
            }

            if ( 'acTL' === $type ) {
                fclose( $handle );
                return true;
            }

            if ( 'IDAT' === $type || 'IEND' === $type ) {
                fclose( $handle );
                return false;
            }

            if ( false === fseek( $handle, $length + 4, SEEK_CUR ) ) {
                fclose( $handle );
                return false;
            }

            $offset += 12 + $length;
        }

        fclose( $handle );
        return false;
    }

    /**
     * 检测 GIF 是否为动画（解析 GIF 块结构统计图像帧数）
     *
     * WordPress 的图片编辑器缩放 GIF 只会留下第一帧，动图被当静态图处理就毁掉了，
     * 所以压缩、格式转换和缩放流程都先过这一关。
     *
     * @param string $file_path 文件绝对路径
     * @return bool 是否为动画
     */
    public static function is_animated_gif( string $file_path ): bool {
        $size = filesize( $file_path );

        if ( false === $size ) {
            return false;
        }

        // 超大 GIF 基本都是动画，跳过解析
        if ( $size > 30 * 1024 * 1024 ) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $data = file_get_contents( $file_path );

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
     * 获取附件对应的原图和缩略图文件
     *
     * @param int $attachment_id 附件 ID
     * @return array 文件列表
     */
    public function get_attachment_files( int $attachment_id, ?array $metadata = null ): array {
        $files = array();

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        if ( null === $metadata ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
        }

        $has_full = false;

        if ( ! empty( $metadata ) && ! empty( $metadata['file'] ) ) {
            $original_file = $base_dir . '/' . $metadata['file'];
            if ( file_exists( $original_file ) ) {
                $files[] = array(
                    'size_type' => 'full',
                    'file_path' => $original_file,
                );
                $has_full = true;
            }

            if ( ! empty( $metadata['original_image'] ) ) {
                $file_dir            = dirname( $metadata['file'] );
                $original_image_path = $base_dir . '/' . $file_dir . '/' . $metadata['original_image'];
                if ( file_exists( $original_image_path ) ) {
                    $files[] = array(
                        'size_type' => 'original_image',
                        'file_path' => $original_image_path,
                    );
                }
            }

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                $file_dir = dirname( $metadata['file'] );

                foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                    if ( empty( $size_data['file'] ) ) {
                        continue;
                    }

                    // 元数据声明的尺寸即使文件缺失也要计入，否则附件会被误报成全部已压缩。
                    $files[] = array(
                        'size_type' => sanitize_text_field( $size_name ),
                        'file_path' => $base_dir . '/' . $file_dir . '/' . $size_data['file'],
                    );
                }
            }
        }

        // SVG 和部分第三方图片可能没有完整元数据，始终用规范附件路径补齐主文件。
        if ( ! $has_full ) {
            $attached_file = get_attached_file( $attachment_id );

            if ( is_string( $attached_file ) && file_exists( $attached_file ) ) {
                array_unshift(
                    $files,
                    array(
                        'size_type' => 'full',
                        'file_path' => $attached_file,
                    )
                );
            }
        }

        return $files;
    }

    /**
     * 压缩单个文件
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     文件路径
     * @param string $size_type     尺寸类型
     * @param array  $options       压缩选项
     * @return array 压缩结果
     */
    public function compress_file( int $attachment_id, string $file_path, string $size_type = 'full', array $options = array() ): array {
        if ( ! file_exists( $file_path ) ) {
            return array(
                'success' => false,
                'message' => __( '文件不存在', 'libre-compress' ),
                'status'  => 'failed',
            );
        }

        if ( ! $this->is_safe_path( $file_path ) ) {
            return array(
                'success' => false,
                'message' => __( '文件路径不安全', 'libre-compress' ),
                'status'  => 'failed',
            );
        }

        $extension      = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        // APNG 的后缀与 MIME 都是 png，但 pngquant 只会留下第一帧，动图就毁了。
        if ( 'png' === $extension && self::is_apng( $file_path ) ) {
            $message = __( 'APNG 动图不压缩', 'libre-compress' );
            $this->record_skipped_file( $attachment_id, $file_path, $size_type, $message );

            return array(
                'success' => false,
                'message' => $message,
                'status'  => 'skipped',
            );
        }

        // 动画 WebP 同样只保留第一帧，cwebp 不支持多帧输入。
        if ( 'webp' === $extension && self::is_animated_webp( $file_path ) ) {
            $message = __( '动画 WebP 不压缩', 'libre-compress' );
            $this->record_skipped_file( $attachment_id, $file_path, $size_type, $message );

            return array(
                'success' => false,
                'message' => $message,
                'status'  => 'skipped',
            );
        }

        $settings       = get_option( 'libre_compress_general', array() );
        $backup_enabled = isset( $settings['backup_enabled'] ) ? (bool) $settings['backup_enabled'] : true;
        $tool           = $this->get_tool_for_format( $extension );

        if ( ! $tool ) {
            $message = __( '没有可用的压缩工具', 'libre-compress' );
            $this->record_skipped_file( $attachment_id, $file_path, $size_type, $message );

            return array(
                'success' => false,
                'message' => $message,
                'status'  => 'skipped',
            );
        }

        $file_size      = filesize( $file_path );
        $max_size_mb    = $tool->get_max_file_size();
        $max_size_bytes = $max_size_mb * 1024 * 1024;

        if ( $max_size_mb > 0 && $file_size > $max_size_bytes ) {
            $message = sprintf(
                __( '文件大小超过限制（最大 %d MB）', 'libre-compress' ),
                $max_size_mb
            );
            $this->record_skipped_file( $attachment_id, $file_path, $size_type, $message );

            return array(
                'success' => false,
                'message' => $message,
                'status'  => 'skipped',
            );
        }

        if ( $backup_enabled ) {
            $backup = libre_compress()->backup;
            if ( ! $backup->create_backup( $attachment_id, $file_path ) ) {
                $message = __( '无法创建原图备份，已停止压缩', 'libre-compress' );
                $this->record_skipped_file( $attachment_id, $file_path, $size_type, $message );

                return array(
                    'success' => false,
                    'message' => $message,
                    'status'  => 'failed',
                );
            }
        }

        // 使用唯一回滚副本，避免异常中断或并发遗留文件互相覆盖。
        $rollback_path = $file_path . '.lc-rollback-' . wp_generate_password( 12, false, false );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $file_path, $rollback_path ) ) {
            $message = __( '无法创建回滚副本，已跳过压缩', 'libre-compress' );
            $this->record_skipped_file( $attachment_id, $file_path, $size_type, $message );

            return array(
                'success' => false,
                'message' => $message,
                'status'  => 'failed',
            );
        }

        $original_size = filesize( $file_path );
        $result        = $tool->compress( $file_path, $options );

        if ( ! $result['success'] ) {
            // 工具执行失败后必须成功还原，才能清理回滚副本。
            $rolled_back = $this->rollback_file( $rollback_path, $file_path );
            $message     = $rolled_back
                ? $result['message']
                : __( '压缩失败且回滚失败，请保留回滚副本并检查磁盘状态', 'libre-compress' );

            $this->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool->get_name(),
                'failed',
                $message
            );

            return array(
                'success'         => false,
                'message'         => $message,
                'status'          => 'failed',
                'original_size'   => $original_size,
                'compressed_size' => $original_size,
            );
        }

        clearstatcache( true, $file_path );
        $compressed_size = filesize( $file_path );

        if ( $compressed_size >= $original_size ) {
            // 压缩后没有变小，使用回滚副本还原原文件。
            $rolled_back = $this->rollback_file( $rollback_path, $file_path );

            if ( ! $rolled_back ) {
                $message = __( '压缩结果无效且回滚失败，请保留回滚副本并检查磁盘状态', 'libre-compress' );
                $this->save_compression_record(
                    $attachment_id,
                    $file_path,
                    $size_type,
                    $original_size,
                    $original_size,
                    $tool->get_name(),
                    'failed',
                    $message
                );

                return array(
                    'success'         => false,
                    'message'         => $message,
                    'status'          => 'failed',
                    'original_size'   => $original_size,
                    'compressed_size' => $original_size,
                );
            }

            // 没有压缩收益同样视为已处理：文件已回到原状，重复压缩只会得到相同结果。
            $message = __( '压缩后体积没有变小，已保留原图', 'libre-compress' );

            if ( ! $this->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool->get_name(),
                'success'
            ) ) {
                return array(
                    'success'         => false,
                    'message'         => __( '压缩记录保存失败，请重新压缩', 'libre-compress' ),
                    'status'          => 'failed',
                    'original_size'   => $original_size,
                    'compressed_size' => $original_size,
                );
            }

            return array(
                'success'         => true,
                'message'         => $message,
                'status'          => 'success',
                'original_size'   => $original_size,
                'compressed_size' => $original_size,
                'ratio'           => 0.0,
            );
        }

        // original_size 为 0 时不做除法，直接按 0% 处理。
        $ratio = $original_size > 0 ? round( ( 1 - $compressed_size / $original_size ) * 100, 2 ) : 0.0;

        // 统一状态必须先可靠写入，记录失败时恢复原文件。
        $record_saved = $this->save_compression_record(
            $attachment_id,
            $file_path,
            $size_type,
            $original_size,
            $compressed_size,
            $tool->get_name(),
            'success'
        );

        if ( ! $record_saved ) {
            $rolled_back = $this->rollback_file( $rollback_path, $file_path );
            $message     = $rolled_back
                ? __( '压缩记录保存失败，已恢复原文件', 'libre-compress' )
                : __( '压缩记录保存失败且回滚失败，请保留回滚副本并检查数据库状态', 'libre-compress' );

            return array(
                'success'         => false,
                'message'         => $message,
                'status'          => 'failed',
                'original_size'   => $original_size,
                'compressed_size' => $rolled_back ? $original_size : $compressed_size,
            );
        }

        $this->cleanup_rollback( $rollback_path );

        return array(
            'success'         => true,
            'message'         => __( '压缩成功', 'libre-compress' ),
            'status'          => 'success',
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'ratio'           => $ratio,
        );
    }

    /**
     * 记录一个"已决定不压缩"的文件
     *
     * 不落库的话这条记录永远不存在，附件状态判定会把它当成待处理，
     * 每轮批量都重复尝试一次（APNG、缺工具、超过大小限制都会卡在这里）。
     * 文件字节未变，所以压缩前后体积一致，按 0% 记为已处理。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     文件绝对路径
     * @param string $size_type     尺寸类型
     * @param string $message       跳过原因
     * @return bool 是否写入成功
     */
    private function record_skipped_file( int $attachment_id, string $file_path, string $size_type, string $message ): bool {
        $size = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;

        return $this->save_compression_record(
            $attachment_id,
            $file_path,
            $size_type,
            $size,
            $size,
            'skipped',
            'skipped',
            $message
        );
    }

    /**
     * 规范化路径用于大小写不敏感的前缀比较
     *
     * Windows 上文件系统不区分大小写，比较时必须忽略大小写；
     * Linux 上保持原样，避免把不同的文件误判成同一个。
     *
     * @param string $path 文件路径
     * @return string
     */
    private static function normalized_compare_path( string $path ): string {
        $normalized = wp_normalize_path( $path );

        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 保存压缩记录
     *
     * @param int    $attachment_id   附件 ID
     * @param string $file_path       文件路径
     * @param string $size_type       尺寸类型
     * @param int    $original_size   原始大小
     * @param int    $compressed_size 压缩后大小
     * @param string $tool_name       工具名称
     * @param string $status          状态
     * @param string $error_message   错误信息
     */
    public function save_compression_record(
        int $attachment_id,
        string $file_path,
        string $size_type,
        int $original_size,
        int $compressed_size,
        string $tool_name,
        string $status,
        string $error_message = ''
    ): bool {
        $database = libre_compress()->database;

        $upload_dir    = wp_upload_dir();
        $base_dir      = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $normalized    = wp_normalize_path( $file_path );

        // 前缀比对必须和 is_complete_record 用同一套大小写规则，
        // 否则 Windows 上大小写不同会落进 else 分支把绝对路径存进记录，
        // 而完整性校验永远比对不上，该附件就会被无限重复压缩。
        $relative_path = 0 === strpos( self::normalized_compare_path( $normalized ), self::normalized_compare_path( $base_dir ) . '/' )
            ? ltrim( substr( $normalized, strlen( $base_dir ) ), '/' )
            : ltrim( $normalized, '/' );
        $ratio         = $original_size > 0 ? round( ( 1 - $compressed_size / $original_size ) * 100, 2 ) : 0;
        $existing      = $database->get_record( $attachment_id, $size_type );
        $record_data   = array(
            'file_path'         => $relative_path,
            'original_size'     => $original_size,
            'compressed_size'   => $compressed_size,
            'compression_ratio' => $ratio,
            'tool_name'         => $tool_name,
            'status'            => $status,
            'error_message'     => $error_message,
        );

        if ( $existing ) {
            return $database->update_record( $existing['id'], $record_data );
        }

        $record_data['attachment_id'] = $attachment_id;
        $record_data['size_type']     = $size_type;

        return false !== $database->add_record( $record_data );
    }

    /**
     * 用回滚副本还原原文件并清理副本
     *
     * @param string $rollback_path 回滚副本路径
     * @param string $file_path     原文件路径
     */
    private function rollback_file( string $rollback_path, string $file_path ): bool {
        if ( ! file_exists( $rollback_path ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $rollback_path, $file_path ) ) {
            return false;
        }

        $this->cleanup_rollback( $rollback_path );
        return true;
    }

    /**
     * 清理回滚副本
     *
     * @param string $rollback_path 回滚副本路径
     */
    private function cleanup_rollback( string $rollback_path ): void {
        if ( file_exists( $rollback_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $rollback_path );
        }
    }

    /**
     * 检查文件路径是否安全
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    private function is_safe_path( string $file_path ): bool {
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $file_path );

        if ( false === $real_path || false === $base_dir ) {
            return false;
        }

        $base_dir = untrailingslashit( wp_normalize_path( $base_dir ) );
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
