<?php
/**
 * 统一图片压缩处理器
 *
 * @package LibreCompress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 统一图片压缩处理器
 *
 * 根据设置为每个文件选择同格式压缩或目标格式输出，两种路径都写入同一
 * 压缩记录表，并统一提供批量、媒体库单项和上传自动处理入口。
 */
class Libre_Compress_Processor {

    /**
     * 锁目录名称
     */
    const LOCK_DIR_NAME = '.libre-compress-locks';

    /**
     * 是否暂停自动压缩
     *
     * @var bool
     */
    private $auto_compress_suppressed = false;

    /**
     * 构造函数
     */
    public function __construct() {
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_compress_on_upload' ), 10, 3 );
    }

    /**
     * 获取支持的图片 MIME
     *
     * @return array
     */
    public function get_supported_mimes(): array {
        return array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml' );
    }

    /**
     * 验证附件是否存在且为支持的图片
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function is_valid_attachment( int $attachment_id ): bool {
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
            return false;
        }

        return in_array( get_post_mime_type( $attachment_id ), $this->get_supported_mimes(), true );
    }

    /**
     * 暂停或恢复自动压缩
     *
     * @param bool $suppressed 是否暂停
     */
    public function set_auto_compress_suppressed( bool $suppressed ): void {
        $this->auto_compress_suppressed = $suppressed;
    }

    /**
     * 自动压缩当前是否被暂停
     *
     * @return bool
     */
    public function is_auto_compress_suppressed(): bool {
        return $this->auto_compress_suppressed;
    }

    /**
     * 获取附件统一压缩状态
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_attachment_state( int $attachment_id ): array {
        $files = libre_compress()->compressor->get_attachment_files( $attachment_id );
        $state = array(
            'status'               => 'empty',
            'total_files'          => count( $files ),
            'success_count'        => 0,
            'failed_count'         => 0,
            'pending_count'        => 0,
            'total_original_size'  => 0,
            'total_compressed_size' => 0,
            'total_ratio'          => 0,
        );

        foreach ( $files as $file ) {
            $record   = libre_compress()->database->get_record( $attachment_id, $file['size_type'] );
            $complete = $this->is_complete_record( $file['file_path'], $record );

            if ( $complete ) {
                $state['success_count']++;
                $state['total_original_size'] += max( 0, (int) $record['original_size'] );
                $state['total_compressed_size'] += max( 0, (int) $record['compressed_size'] );
            } elseif ( $record && 'failed' === $record['status'] ) {
                $state['failed_count']++;
            } else {
                $state['pending_count']++;
            }
        }

        if ( $state['total_files'] > 0 && $state['success_count'] === $state['total_files'] ) {
            $state['status'] = 'complete';
        } elseif ( $state['success_count'] > 0 ) {
            $state['status'] = 'partial';
        } elseif ( $state['failed_count'] > 0 ) {
            $state['status'] = 'failed';
        } elseif ( $state['pending_count'] > 0 ) {
            $state['status'] = 'pending';
        }

        if ( $state['total_original_size'] > 0 ) {
            $state['total_ratio'] = round(
                ( 1 - $state['total_compressed_size'] / $state['total_original_size'] ) * 100,
                2
            );
        }

        return $state;
    }

    /**
     * 判断附件是否还有未压缩文件
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function has_pending_files( int $attachment_id ): bool {
        if ( ! $this->is_valid_attachment( $attachment_id ) ) {
            return false;
        }

        $state = $this->get_attachment_state( $attachment_id );
        return in_array( $state['status'], array( 'partial', 'failed', 'pending' ), true );
    }

    /**
     * 统一压缩一个附件
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function compress_attachment( int $attachment_id ): array {
        if ( ! $this->is_valid_attachment( $attachment_id ) ) {
            return $this->empty_result( __( '无效的图片附件', 'libre-compress' ), 'failed' );
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return $this->empty_result( __( '该图片正在处理中，请稍后重试', 'libre-compress' ), 'skipped' );
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return $this->empty_result( __( '插件正在执行维护操作，请稍后重试', 'libre-compress' ), 'skipped' );
        }

        try {
            $metadata = wp_get_attachment_metadata( $attachment_id );
            $files    = libre_compress()->compressor->get_attachment_files( $attachment_id, is_array( $metadata ) ? $metadata : null );
            if ( empty( $files ) ) {
                return $this->empty_result( __( '未找到可压缩的图片文件', 'libre-compress' ), 'failed' );
            }

            $pending = array();

            foreach ( $files as $file ) {
                $record = libre_compress()->database->get_record( $attachment_id, $file['size_type'] );
                if ( ! $this->is_complete_record( $file['file_path'], $record ) ) {
                    $pending[] = $file;
                }
            }

            if ( empty( $pending ) ) {
                return $this->empty_result( __( '图片已经压缩', 'libre-compress' ), 'skipped' );
            }

            $output_settings = libre_compress()->converter->get_output_settings();
            $target          = $output_settings['target'];
            $output_map   = array();
            $result          = array(
                'status'      => 'success',
                'message'     => '',
                'total'       => count( $pending ),
                'success'     => 0,
                'failed'      => 0,
                'skipped'     => 0,
                'saved_bytes' => 0,
                'details'     => array(),
            );

            foreach ( $pending as $file ) {
                do_action( 'libre_compress_before_compress', $attachment_id, $file['file_path'], $file['size_type'] );

                if ( libre_compress()->converter->should_output_target( $file['file_path'] ) ) {
                    $file_result = libre_compress()->converter->compress_to_target_format(
                        $attachment_id,
                        $file['file_path'],
                        $file['size_type'],
                        $target
                    );
                } else {
                    $file_result = libre_compress()->compressor->compress_file(
                        $attachment_id,
                        $file['file_path'],
                        $file['size_type']
                    );
                }

                $file_result['size_type'] = $file['size_type'];
                $result['details'][]      = array_merge( $file, $file_result );

                if ( 'success' === $file_result['status'] ) {
                    $result['success']++;
                    $result['saved_bytes'] += max(
                        0,
                        (int) $file_result['original_size'] - (int) $file_result['compressed_size']
                    );

                    if ( ! empty( $file_result['to'] ) ) {
                        $output_map[ $file['size_type'] ] = array(
                            'from' => $file_result['from'],
                            'to'   => $file_result['to'],
                        );
                    }

                    do_action( 'libre_compress_after_compress', $attachment_id, $file['file_path'], $file['size_type'], $file_result );
                } elseif ( 'failed' === $file_result['status'] ) {
                    $result['failed']++;
                } else {
                    $result['skipped']++;
                }
            }

            $metadata_synced = true;
            if ( ! empty( $output_map )
                && ! libre_compress()->converter->sync_attachment_format( $attachment_id, $output_map, $target ) ) {
                $metadata_synced = false;
                $result['status']  = 'failed';
                $result['message'] = __( '压缩结果已生成，但附件路径同步失败，请检查数据库状态', 'libre-compress' );
            }

            if ( $metadata_synced ) {
                foreach ( $output_map as $entry ) {
                    libre_compress()->converter->remove_source_file( $entry['from'] );
                }
                $this->refresh_metadata_file_size( $attachment_id );
            }

            if ( empty( $result['message'] ) ) {
                if ( $result['success'] === $result['total'] ) {
                    $result['status']  = 'success';
                    $result['message'] = __( '图片压缩完成', 'libre-compress' );
                } elseif ( $result['success'] > 0 ) {
                    $result['status']  = 'partial';
                    $result['message'] = __( '图片仅部分压缩完成，仍有文件需要重试', 'libre-compress' );
                } elseif ( $result['failed'] > 0 ) {
                    $result['status']  = 'failed';
                    $result['message'] = __( '图片压缩失败', 'libre-compress' );
                } else {
                    $result['status']  = 'skipped';
                    $result['message'] = __( '没有可用的压缩结果', 'libre-compress' );
                }
            }

            return $result;
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 恢复附件原图
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function restore_attachment( int $attachment_id ): bool {
        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return false;
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return false;
        }

        $was_suppressed              = $this->auto_compress_suppressed;
        $this->auto_compress_suppressed = true;

        try {
            if ( ! libre_compress()->backup->restore_backup( $attachment_id ) ) {
                return false;
            }

            if ( ! libre_compress()->converter->handle_after_restore( $attachment_id ) ) {
                return false;
            }

            return libre_compress()->backup->finalize_restored_backups( $attachment_id );
        } finally {
            $this->auto_compress_suppressed = $was_suppressed;
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 删除附件备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function delete_backup( int $attachment_id ): bool {
        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return false;
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return false;
        }

        try {
            return libre_compress()->backup->delete_backup( $attachment_id );
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 上传创建附件时自动执行统一压缩
     *
     * @param array       $metadata 附件元数据
     * @param int         $attachment_id 附件 ID
     * @param string|null $context  WordPress 元数据上下文
     * @return array
     */
    public function auto_compress_on_upload( $metadata, $attachment_id, $context = null ) {
        $settings = get_option( 'libre_compress_general', array() );

        if ( $this->auto_compress_suppressed
            || empty( $settings['auto_compress'] )
            || ! $this->is_valid_attachment( absint( $attachment_id ) ) ) {
            return $metadata;
        }

        // 新版 WordPress 明确提供 create/update；旧版通过是否已有元数据区分。
        if ( is_string( $context ) && 'create' !== $context ) {
            return $metadata;
        }
        if ( null === $context && ! empty( wp_get_attachment_metadata( $attachment_id ) ) ) {
            return $metadata;
        }

        // 首次上传时核心尚未保存本次 metadata，先持久化再交给统一处理器。
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $this->compress_attachment( absint( $attachment_id ) );
        $latest = wp_get_attachment_metadata( $attachment_id );

        return is_array( $latest ) ? $latest : $metadata;
    }

    /**
     * 获取附件级排他锁
     *
     * @param int $attachment_id 附件 ID
     * @return resource|false
     */
    public function acquire_attachment_lock( int $attachment_id ) {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/' . self::LOCK_DIR_NAME;

        if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) {
            return false;
        }

        $handle = fopen( $lock_dir . '/' . absint( $attachment_id ) . '.lock', 'c' );
        if ( false === $handle ) {
            return false;
        }

        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            return false;
        }

        return $handle;
    }

    /**
     * 获取全局维护锁
     *
     * 压缩/恢复使用共享锁，维护操作使用排他锁。
     *
     * @param bool $exclusive 是否排他
     * @return resource|false
     */
    public function acquire_global_lock( bool $exclusive = false ) {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/' . self::LOCK_DIR_NAME;

        if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) {
            return false;
        }

        $handle = fopen( $lock_dir . '/maintenance.lock', 'c' );
        if ( false === $handle ) {
            return false;
        }

        $flags = $exclusive ? ( LOCK_EX | LOCK_NB ) : ( LOCK_SH | LOCK_NB );
        if ( ! flock( $handle, $flags ) ) {
            fclose( $handle );
            return false;
        }

        return $handle;
    }

    /**
     * 释放文件锁
     *
     * @param resource $lock 锁句柄
     */
    public function release_attachment_lock( $lock ): void {
        if ( ! is_resource( $lock ) ) {
            return;
        }

        flock( $lock, LOCK_UN );
        fclose( $lock );
    }

    /**
     * 判断压缩记录是否与当前文件完全匹配
     *
     * @param string      $file_path 当前文件绝对路径
     * @param array|null  $record    压缩记录
     * @return bool
     */
    private function is_complete_record( string $file_path, $record ): bool {
        if ( ! is_array( $record ) || 'success' !== $record['status'] || empty( $record['file_path'] ) ) {
            return false;
        }

        clearstatcache( true, $file_path );
        if ( ! is_file( $file_path ) || (int) filesize( $file_path ) !== (int) $record['compressed_size'] ) {
            return false;
        }

        $current_relative = $this->get_relative_upload_path( $file_path );
        return $this->normalized_path( $record['file_path'] ) === $this->normalized_path( $current_relative );
    }

    /**
     * 更新当前主文件大小元数据
     *
     * @param int $attachment_id 附件 ID
     */
    private function refresh_metadata_file_size( int $attachment_id ): void {
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $main_file  = $upload_dir['basedir'] . '/' . ltrim( $metadata['file'], '/' );
        if ( ! is_file( $main_file ) ) {
            return;
        }

        $metadata['filesize'] = (int) filesize( $main_file );
        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    /**
     * 获取上传目录内相对路径
     *
     * @param string $path 文件路径
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
     * 规范化路径用于比较
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 创建空结果
     *
     * @param string $message 提示
     * @param string $status  状态
     * @return array
     */
    private function empty_result( string $message, string $status ): array {
        return array(
            'status'      => $status,
            'message'     => $message,
            'total'       => 0,
            'success'     => 0,
            'failed'      => $status === 'failed' ? 1 : 0,
            'skipped'     => $status === 'skipped' ? 1 : 0,
            'saved_bytes' => 0,
            'details'     => array(),
        );
    }
}
