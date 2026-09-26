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
     * 待自动处理标记
     */
    const PENDING_META_KEY = '_libre_compress_pending';

    /**
     * 单个请求在结束前最多处理的上传待办数量
     */
    const UPLOAD_QUEUE_LIMIT = 3;

    /**
     * 后台收尾任务单次最多处理的上传待办数量
     */
    const PENDING_SWEEP_LIMIT = 3;

    /**
     * 后台收尾任务最小间隔（秒）
     */
    const PENDING_SWEEP_INTERVAL = 60;

    /**
     * 后台收尾限流使用的 transient 名
     */
    const PENDING_SWEEP_TRANSIENT = 'libre_compress_pending_sweep';

    /**
     * 后台收尾定时任务的钩子名
     */
    const PENDING_SWEEP_HOOK = 'libre_compress_pending_sweep_event';

    /**
     * 是否暂停自动压缩
     *
     * @var bool
     */
    private $auto_compress_suppressed = false;

    /**
     * 本次请求登记的待处理附件
     *
     * @var array
     */
    private $queued_uploads = array();

    /**
     * 最近一次加锁失败的原因
     *
     * busy       = 锁被其他进程占用，稍后重试
     * unavailable = 锁文件无法创建，重试也不会成功
     *
     * @var string
     */
    private $lock_error = '';

    /**
     * 构造函数
     */
    public function __construct() {
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_compress_on_upload' ), 10, 3 );
        add_action( 'add_attachment', array( $this, 'queue_upload_for_compression' ) );
        add_action( 'delete_attachment', array( $this, 'handle_attachment_deleted' ) );
        add_action( 'shutdown', array( $this, 'process_queued_uploads' ), 1 );
        add_action( 'admin_init', array( $this, 'process_pending_uploads' ), 20 );
        add_action( self::PENDING_SWEEP_HOOK, array( $this, 'run_pending_sweep' ) );
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
            return $this->empty_result( $this->get_lock_error_message(), 'skipped', 'busy' === $this->lock_error );
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return $this->empty_result( $this->get_lock_error_message( true ), 'skipped', 'busy' === $this->lock_error );
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

            $output_settings = libre_compress()->output_processor->get_output_settings();
            $target          = $output_settings['target'];
            $output_map   = array();
            $result          = array(
                'status'      => 'success',
                'message'     => '',
                'busy'        => false,
                'total'       => count( $pending ),
                'success'     => 0,
                'failed'      => 0,
                'skipped'     => 0,
                'saved_bytes' => 0,
                'details'     => array(),
            );

            foreach ( $pending as $file ) {
                do_action( 'libre_compress_before_compress', $attachment_id, $file['file_path'], $file['size_type'] );

                if ( libre_compress()->output_processor->should_output_target( $file['file_path'] ) ) {
                    $file_result = libre_compress()->output_processor->compress_to_target_format(
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
                && ! libre_compress()->output_processor->sync_attachment_format( $attachment_id, $output_map, $target ) ) {
                $metadata_synced = false;
                $result['status']  = 'failed';
                $result['message'] = __( '压缩结果已生成，但附件路径同步失败，请检查数据库状态', 'libre-compress' );
            }

            if ( $metadata_synced ) {
                foreach ( $output_map as $entry ) {
                    libre_compress()->output_processor->remove_source_file( $entry['from'] );
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
        if ( ! $this->attachment_exists( $attachment_id ) ) {
            return false;
        }

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

            if ( ! libre_compress()->output_processor->handle_after_restore( $attachment_id ) ) {
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
     * 登记所有新建附件，覆盖不生成标准 metadata 的上传流程
     *
     * @param int $attachment_id 附件 ID
     */
    public function queue_upload_for_compression( $attachment_id ): void {
        $attachment_id = absint( $attachment_id );
        $settings      = get_option( 'libre_compress_general', array() );

        if ( $this->auto_compress_suppressed
            || empty( $settings['auto_compress'] )
            || ! $this->is_valid_attachment( $attachment_id ) ) {
            return;
        }

        update_post_meta( $attachment_id, self::PENDING_META_KEY, time() );
        $this->queued_uploads[ $attachment_id ] = true;
    }

    /**
     * 请求结束前处理没有走标准 metadata 生成钩子的上传
     *
     * 单个请求只处理限量附件，避免批量导入时把请求拖到超时；
     * 未处理完的附件保留待处理标记，由后台收尾任务继续处理。
     */
    public function process_queued_uploads(): void {
        if ( empty( $this->queued_uploads ) ) {
            return;
        }

        $settings = get_option( 'libre_compress_general', array() );
        if ( $this->auto_compress_suppressed || empty( $settings['auto_compress'] ) ) {
            foreach ( array_keys( $this->queued_uploads ) as $attachment_id ) {
                delete_post_meta( $attachment_id, self::PENDING_META_KEY );
            }
            $this->queued_uploads = array();
            return;
        }

        $processed = 0;

        foreach ( array_keys( $this->queued_uploads ) as $attachment_id ) {
            if ( $processed >= self::UPLOAD_QUEUE_LIMIT ) {
                break;
            }

            $this->run_pending_upload( $attachment_id );
            $processed++;
        }

        $this->queued_uploads = array();
    }

    /**
     * 后台收尾：处理请求中断留下的上传待办
     *
     * 只在普通后台页面加载时执行，AJAX 和 admin-post 不参与，
     * 避免和批量流程抢占资源。
     */
    public function process_pending_uploads(): void {
        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

        if ( wp_doing_ajax() || 'admin-post.php' === $pagenow ) {
            return;
        }

        $this->run_pending_sweep();
    }

    /**
     * 处理上传待办队列
     *
     * 单次限量并按最小间隔限流，避免长时间外部命令拖慢后台。
     */
    public function run_pending_sweep(): void {
        $settings = get_option( 'libre_compress_general', array() );
        if ( $this->auto_compress_suppressed || empty( $settings['auto_compress'] ) ) {
            // 自动压缩关闭时待办标记没有意义，按限流节奏清理即可。
            if ( $this->acquire_pending_sweep_token() ) {
                $this->clear_pending_uploads();
            }
            return;
        }

        $attachment_ids = $this->get_pending_upload_ids( self::PENDING_SWEEP_LIMIT );
        if ( empty( $attachment_ids ) || ! $this->acquire_pending_sweep_token() ) {
            return;
        }

        foreach ( $attachment_ids as $attachment_id ) {
            $this->run_pending_upload( $attachment_id );
        }
    }

    /**
     * 清理全部上传待办标记
     */
    private function clear_pending_uploads(): void {
        global $wpdb;

        // 一次性删除，避免逐条删除在标记堆积时拖慢后台页面。
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", self::PENDING_META_KEY ) );
    }

    /**
     * 获取收尾任务限流令牌
     *
     * @return bool 是否允许本次执行
     */
    private function acquire_pending_sweep_token(): bool {
        if ( get_transient( self::PENDING_SWEEP_TRANSIENT ) ) {
            return false;
        }

        set_transient( self::PENDING_SWEEP_TRANSIENT, time(), self::PENDING_SWEEP_INTERVAL );
        return true;
    }

    /**
     * 处理单个上传待办附件
     *
     * @param int $attachment_id 附件 ID
     */
    private function run_pending_upload( int $attachment_id ): void {
        if ( ! get_post_meta( $attachment_id, self::PENDING_META_KEY, true ) ) {
            return;
        }

        $result = $this->compress_attachment( $attachment_id );

        // 锁被占用说明其他进程正在处理，刷新时间戳排到队尾等待下次收尾。
        if ( ! empty( $result['busy'] ) ) {
            update_post_meta( $attachment_id, self::PENDING_META_KEY, time() );
            return;
        }

        delete_post_meta( $attachment_id, self::PENDING_META_KEY );
    }

    /**
     * 查询残留的上传待办附件
     *
     * 按登记时间升序返回，时间最久的优先处理；
     * 已删除或已进入回收站的附件不再处理。
     *
     * @param int $limit 数量上限
     * @return int[]
     */
    private function get_pending_upload_ids( int $limit ): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.post_id
                 FROM {$wpdb->postmeta} p
                 INNER JOIN {$wpdb->posts} o ON o.ID = p.post_id
                 WHERE p.meta_key = %s
                   AND o.post_type = 'attachment'
                   AND o.post_status <> 'trash'
                 GROUP BY p.post_id
                 ORDER BY MIN( p.meta_value + 0 ) ASC
                 LIMIT %d",
                self::PENDING_META_KEY,
                max( 1, min( 50, $limit ) )
            )
        );

        return array_map( 'absint', $ids );
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

        $is_pending = ! empty( get_post_meta( $attachment_id, self::PENDING_META_KEY, true ) );

        // 新版 WordPress 明确提供 create/update；旧版通过待处理标记区分。
        if ( is_string( $context ) && 'create' !== $context ) {
            return $metadata;
        }
        if ( null === $context && ! $is_pending ) {
            return $metadata;
        }

        // 首次上传时核心尚未保存本次 metadata，先持久化再交给统一处理器。
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $result = $this->compress_attachment( absint( $attachment_id ) );
        if ( empty( $result['busy'] ) ) {
            delete_post_meta( $attachment_id, self::PENDING_META_KEY );
        }
        $latest = wp_get_attachment_metadata( $attachment_id );

        return is_array( $latest ) ? $latest : $metadata;
    }

    /**
     * 附件永久删除时清理插件残留数据
     *
     * @param int $post_id 附件 ID
     */
    public function handle_attachment_deleted( $post_id ): void {
        $attachment_id = absint( $post_id );
        if ( ! $attachment_id ) {
            return;
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return;
        }

        // 与压缩/恢复保持一致的共享锁：只需防同附件并发，不必独占整个插件。
        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return;
        }

        try {
            libre_compress()->database->delete_records_by_attachment( $attachment_id );
            libre_compress()->backup->delete_backup( $attachment_id );
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 附件是否仍然存在
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function attachment_exists( int $attachment_id ): bool {
        $post = $attachment_id ? get_post( $attachment_id ) : null;

        return $post && 'attachment' === $post->post_type;
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
            $this->lock_error = 'unavailable';
            return false;
        }

        $handle = fopen( $lock_dir . '/' . absint( $attachment_id ) . '.lock', 'c' );
        if ( false === $handle ) {
            $this->lock_error = 'unavailable';
            return false;
        }

        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            $this->lock_error = 'busy';
            return false;
        }

        $this->lock_error = '';
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
            $this->lock_error = 'unavailable';
            return false;
        }

        $handle = fopen( $lock_dir . '/maintenance.lock', 'c' );
        if ( false === $handle ) {
            $this->lock_error = 'unavailable';
            return false;
        }

        $flags = $exclusive ? ( LOCK_EX | LOCK_NB ) : ( LOCK_SH | LOCK_NB );
        if ( ! flock( $handle, $flags ) ) {
            fclose( $handle );
            $this->lock_error = 'busy';
            return false;
        }

        $this->lock_error = '';
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
        if ( ! $this->is_inside_upload_dir( $main_file ) || ! is_file( $main_file ) ) {
            return;
        }

        $metadata['filesize'] = (int) filesize( $main_file );
        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    /**
     * 判断路径是否位于上传目录内
     *
     * @param string $path 文件路径
     * @return bool
     */
    private function is_inside_upload_dir( string $path ): bool {
        $upload_dir = wp_upload_dir();
        // 两侧都做 realpath，兼容 uploads 目录是符号链接或独立卷的部署方式。
        $base_dir = realpath( $upload_dir['basedir'] );
        $base_dir = $this->normalized_path( $base_dir ? $base_dir : $upload_dir['basedir'] );
        $target   = realpath( $path );
        // realpath 失败时按字面解析 . 和 ..，避免穿越路径被误判为目录内。
        $target   = $this->normalized_path( $target ? $target : $this->collapse_path_segments( $path ) );

        return 0 === strpos( $target, rtrim( $base_dir, '/' ) . '/' );
    }

    /**
     * 按字面解析路径中的 . 和 ..
     *
     * @param string $path 文件路径
     * @return string
     */
    private function collapse_path_segments( string $path ): string {
        $normalized = str_replace( '\\', '/', $path );
        $prefix     = '';

        if ( preg_match( '#^([a-z]:/|/)#i', $normalized, $matches ) ) {
            $prefix     = $matches[1];
            $normalized = substr( $normalized, strlen( $prefix ) );
        }

        $segments = array();
        foreach ( explode( '/', $normalized ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }

            if ( '..' === $segment ) {
                array_pop( $segments );
                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . implode( '/', $segments );
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
     * 获取加锁失败提示
     *
     * @param bool $global 是否为全局维护锁
     * @return string
     */
    private function get_lock_error_message( bool $global = false ): string {
        if ( 'busy' === $this->lock_error ) {
            return $global
                ? __( '插件正在执行维护操作，请稍后重试', 'libre-compress' )
                : __( '该图片正在处理中，请稍后重试', 'libre-compress' );
        }

        return __( '无法创建锁文件，请检查上传目录的写入权限', 'libre-compress' );
    }

    /**
     * 创建空结果
     *
     * @param string $message 提示
     * @param string $status  状态
     * @param bool   $busy    是否因锁冲突未执行
     * @return array
     */
    private function empty_result( string $message, string $status, bool $busy = false ): array {
        return array(
            'status'      => $status,
            'message'     => $message,
            'busy'        => $busy,
            'total'       => 0,
            'success'     => 0,
            'failed'      => $status === 'failed' ? 1 : 0,
            'skipped'     => $status === 'skipped' ? 1 : 0,
            'saved_bytes' => 0,
            'details'     => array(),
        );
    }
}
