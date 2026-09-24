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
                    if ( ! empty( $size_data['file'] ) ) {
                        $thumb_file = $base_dir . '/' . $file_dir . '/' . $size_data['file'];
                        if ( file_exists( $thumb_file ) ) {
                            $files[] = array(
                                'size_type' => sanitize_text_field( $size_name ),
                                'file_path' => $thumb_file,
                            );
                        }
                    }
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
        $settings       = get_option( 'libre_compress_general', array() );
        $backup_enabled = isset( $settings['backup_enabled'] ) ? (bool) $settings['backup_enabled'] : true;
        $tool           = $this->get_tool_for_format( $extension );

        if ( ! $tool ) {
            return array(
                'success' => false,
                'message' => __( '没有可用的压缩工具', 'libre-compress' ),
                'status'  => 'skipped',
            );
        }

        $file_size      = filesize( $file_path );
        $max_size_mb    = $tool->get_max_file_size();
        $max_size_bytes = $max_size_mb * 1024 * 1024;

        if ( $max_size_mb > 0 && $file_size > $max_size_bytes ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( '文件大小超过限制（最大 %d MB）', 'libre-compress' ),
                    $max_size_mb
                ),
                'status'  => 'skipped',
            );
        }

        if ( $backup_enabled ) {
            $backup = libre_compress()->backup;
            if ( ! $backup->create_backup( $attachment_id, $file_path ) ) {
                return array(
                    'success' => false,
                    'message' => __( '无法创建原图备份，已停止压缩', 'libre-compress' ),
                    'status'  => 'failed',
                );
            }
        }

        // 使用唯一回滚副本，避免异常中断或并发遗留文件互相覆盖。
        $rollback_path = $file_path . '.lc-rollback-' . wp_generate_password( 12, false, false );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $file_path, $rollback_path ) ) {
            return array(
                'success' => false,
                'message' => __( '无法创建回滚副本，已跳过压缩', 'libre-compress' ),
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
            $status      = $rolled_back ? 'skipped' : 'failed';
            $message     = $rolled_back
                ? __( '压缩后体积没有变小，已跳过', 'libre-compress' )
                : __( '压缩结果无效且回滚失败，请保留回滚副本并检查磁盘状态', 'libre-compress' );

            $this->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool->get_name(),
                $status,
                $message
            );

            return array(
                'success'         => $rolled_back,
                'message'         => $message,
                'status'          => $status,
                'original_size'   => $original_size,
                'compressed_size' => $original_size,
            );
        }

        $ratio = round( ( 1 - $compressed_size / $original_size ) * 100, 2 );

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
        $relative_path = 0 === strpos( $normalized, $base_dir . '/' )
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
