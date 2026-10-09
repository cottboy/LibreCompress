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
     * 恢复中间状态标记
     *
     * 引用与文件已退回原图，但转换结果、备份和记录尚未清理完时写入，
     * 让中断后的恢复可以从清理阶段继续，而不是重复改动引用。
     */
    const RESTORE_STATE_META_KEY = '_libre_compress_restore_ready';

    /**
     * 清除记录时单次处理的附件数量
     */
    const HISTORY_PAGE_SIZE = 20;

    /**
     * 原图备份到期清理任务的钩子名
     */
    const BACKUP_PRUNE_HOOK = 'libre_compress_backup_prune_event';

    /**
     * 备份到期清理单批处理的附件数量
     */
    const BACKUP_PRUNE_PAGE_SIZE = 20;

    /**
     * 删除兼容格式回退时单批处理的附件数量
     */
    const FALLBACK_PAGE_SIZE = 20;

    /**
     * 单次定时任务最多清理的附件数量，剩余积压延后到补排的任务
     */
    const BACKUP_PRUNE_LIMIT = 200;

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
        add_action( self::BACKUP_PRUNE_HOOK, array( $this, 'prune_expired_backups' ) );
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
            'total_files'          => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'pending_count'        => 0,
            'skipped_count'        => 0,
            'missing_count'        => 0,
            'total_original_size'  => 0,
            'total_compressed_size' => 0,
            'total_ratio'          => 0,
        );

        foreach ( $files as $file ) {
            // 元数据声明但磁盘上已不存在的尺寸不是待处理项：重试多少次都不会变，
            // 计入 pending 会让整个附件永远显示"待压缩"并被反复重试。
            if ( ! is_file( $file['file_path'] ) ) {
                $state['missing_count']++;
                continue;
            }

            $state['total_files']++;

            $record   = libre_compress()->database->get_record( $attachment_id, $file['size_type'] );
            $complete = $this->is_complete_record( $file['file_path'], $record );

            if ( $complete ) {
                $state['success_count']++;
                $state['total_original_size'] += max( 0, (int) $record['original_size'] );
                $state['total_compressed_size'] += max( 0, (int) $record['compressed_size'] );
            } elseif ( $record && 'failed' === $record['status'] ) {
                $state['failed_count']++;
            } elseif ( $record && 'skipped' === $record['status'] ) {
                // 主动跳过（如 APNG 动图、缺工具、超过大小限制）已记录在案，
                // 不该每轮批量都当成待处理重新尝试。
                $state['skipped_count']++;
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

            // 只对待压缩的图片生效：已全部压缩的附件在这里直接返回，
            // 不会对同一张图反复缩放，也不会动已压缩文件的大小。
            if ( empty( $this->collect_pending_files( $attachment_id, $files ) ) ) {
                return $this->empty_result( __( '图片已经压缩', 'libre-compress' ), 'skipped' );
            }

            // 超阈值的主文件先按大图缩放阈值重整为 -scaled 文件，再按新文件列表重算待办。
            $this->scale_oversized_if_needed( $attachment_id );

            $metadata = wp_get_attachment_metadata( $attachment_id );
            $files    = libre_compress()->compressor->get_attachment_files( $attachment_id, is_array( $metadata ) ? $metadata : null );

            if ( empty( $files ) ) {
                return $this->empty_result( __( '未找到可压缩的图片文件', 'libre-compress' ), 'failed' );
            }

            $pending = $this->collect_pending_files( $attachment_id, $files );

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

            // 引用、文章链接与源文件清理必须一起提交成功，否则退回原文件引用等待重试。
            if ( ! empty( $output_map ) && ! libre_compress()->output_processor->commit_attachment_format( $attachment_id, $output_map ) ) {
                foreach ( $result['details'] as $index => $detail ) {
                    if ( empty( $detail['to'] ) || ! isset( $output_map[ $detail['size_type'] ] ) ) {
                        continue;
                    }

                    $result['details'][ $index ]['status']  = 'failed';
                    $result['details'][ $index ]['message'] = __( '附件引用更新失败，已退回原文件', 'libre-compress' );
                    $result['saved_bytes']                 -= max( 0, (int) $detail['original_size'] - (int) $detail['compressed_size'] );
                    $result['saved_bytes']                  = max( 0, $result['saved_bytes'] );
                    $result['success']--;
                    $result['failed']++;
                }

                $result['status']  = 'failed';
                $result['message'] = __( '压缩结果已生成，但附件引用或文章链接更新失败，图片仍指向原文件，可重新压缩继续提交', 'libre-compress' );

                return $result;
            }

            $this->refresh_metadata_file_sizes( $attachment_id );

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
     * 收集本次需要压缩的文件
     *
     * @param int   $attachment_id 附件 ID
     * @param array $files         候选文件列表
     * @return array 待压缩文件列表
     */
    private function collect_pending_files( int $attachment_id, array $files ): array {
        $pending = array();

        foreach ( $files as $file ) {
            $record = libre_compress()->database->get_record( $attachment_id, $file['size_type'] );

            if ( ! $this->is_complete_record( $file['file_path'], $record ) ) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * 压缩前把超过大图缩放阈值的主文件重整为 -scaled 文件
     *
     * 只在压缩待压缩的图片时执行，完全沿用 WordPress 上传时的做法：
     * 用 WP 自带的图片编辑器缩到阈值内、另存为 xxx-scaled 文件、
     * 未缩放的源文件保留原名并写进 original_image，附件主文件指向缩放结果。
     * 已压缩的图片不会走到这里，因此不会反复缩放或改动已压缩文件。
     *
     * 任意一步失败都直接放弃：文件仍是原来那个，压缩照常继续，不留半成品状态。
     */
    private function scale_oversized_if_needed( int $attachment_id ): void {
        $threshold = Libre_Compress_Settings::image_size_threshold();

        if ( $threshold <= 0 ) {
            return;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $main_rel   = ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' );
        $main_path  = $base_dir . '/' . $main_rel;

        if ( ! is_file( $main_path ) || ! $this->is_inside_upload_dir( $main_path ) ) {
            return;
        }

        // 有 original_image 说明当前主文件本身就是缩放产物：从未缩放源文件重新派生，
        // 阈值调大时才能把主文件相应放大回去。
        $source_path = $main_path;

        if ( ! empty( $metadata['original_image'] ) ) {
            $dir_rel   = dirname( $main_rel );
            $file_rel  = ( '.' === $dir_rel ? '' : $dir_rel . '/' ) . wp_basename( (string) $metadata['original_image'] );
            $candidate = $base_dir . '/' . ltrim( wp_normalize_path( $file_rel ), '/' );

            if ( is_file( $candidate ) && $this->is_inside_upload_dir( $candidate ) ) {
                $source_path = $candidate;
            }
        }

        if ( ! $this->is_scalable_image( $source_path ) ) {
            return;
        }

        $imagesize = wp_getimagesize( $source_path );

        if ( ! is_array( $imagesize ) || empty( $imagesize[0] ) || empty( $imagesize[1] ) ) {
            return;
        }

        // 两个方向都没超过阈值就不动：只缩不放
        if ( (int) $imagesize[0] <= $threshold && (int) $imagesize[1] <= $threshold ) {
            return;
        }

        $scaled = $this->generate_scaled_file( $source_path, $threshold );

        if ( null === $scaled ) {
            return;
        }

        $new_rel = ltrim( $this->get_relative_upload_path( $scaled['path'] ), '/' );

        if ( ! is_file( $scaled['path'] ) || ! $this->is_inside_upload_dir( $scaled['path'] ) ) {
            return;
        }

        // 目标路径与现主文件相同说明覆盖的就是现主文件本身，引用和文章链接都不用动。
        $path_changed = $this->normalized_path( $new_rel ) !== $this->normalized_path( $main_rel );
        $old_path     = $main_path;

        $metadata['file']     = $new_rel;
        $metadata['width']    = isset( $scaled['width'] ) ? (int) $scaled['width'] : (int) ( $metadata['width'] ?? 0 );
        $metadata['height']   = isset( $scaled['height'] ) ? (int) $scaled['height'] : (int) ( $metadata['height'] ?? 0 );
        $metadata['filesize'] = isset( $scaled['filesize'] ) ? (int) $scaled['filesize'] : wp_filesize( $scaled['path'] );

        // 未缩放源文件按原名留在磁盘上，让 WordPress 的“原图”入口和插件的备份都能取到它。
        $metadata['original_image'] = wp_basename( $source_path );

        if ( ! wp_update_attachment_metadata( $attachment_id, $metadata ) ) {
            // 引用一个都没改，缩放文件下次会被重新生成，不会留下不一致状态。
            return;
        }

        if ( $path_changed ) {
            update_attached_file( $attachment_id, $scaled['path'] );

            // 文章里固化的旧地址要跟着改；旧文件仍然保留，改写失败也不会 404，
            // 只是正文继续加载旧文件，重试即可。
            libre_compress()->output_processor->update_content_references(
                array(
                    array(
                        'from' => $old_path,
                        'to'   => $scaled['path'],
                    ),
                )
            );
        }
    }

    /**
     * 判断文件能否安全缩放
     *
     * SVG 没有栅格尺寸；动画 GIF、动画 WebP 与 APNG 用 WP 编辑器缩放只会留下第一帧，
     * 与压缩、格式转换流程的取舍保持一致——宁可不动，也不能毁文件。
     *
     * @param string $file_path 文件绝对路径
     * @return bool 是否允许缩放
     */
    private function is_scalable_image( string $file_path ): bool {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( 'svg' === $extension ) {
            return false;
        }

        if ( 'gif' === $extension && Libre_Compress_Compressor::is_animated_gif( $file_path ) ) {
            return false;
        }

        if ( 'webp' === $extension && Libre_Compress_Compressor::is_animated_webp( $file_path ) ) {
            return false;
        }

        if ( 'png' === $extension && Libre_Compress_Compressor::is_apng( $file_path ) ) {
            return false;
        }

        return true;
    }

    /**
     * 用 WordPress 图片编辑器生成 -scaled 缩放文件
     *
     * 与核心上传流程一致：resize 到阈值内、按 EXIF 方向旋转、另存为 xxx-scaled 文件。
     * 目标必须与源文件不同，否则会把未缩放源文件直接覆盖掉。
     *
     * @param string $source_path 未缩放的源文件绝对路径
     * @param int    $threshold   阈值（像素）
     * @return array|null 图片编辑器保存结果；失败或目标与源相同时返回 null
     */
    private function generate_scaled_file( string $source_path, int $threshold ): ?array {
        $editor = wp_get_image_editor( $source_path );

        if ( is_wp_error( $editor ) ) {
            return null;
        }

        $resized = $editor->resize( $threshold, $threshold );

        if ( is_wp_error( $resized ) ) {
            return null;
        }

        // 与核心一致：取源图的 EXIF Orientation，避免缩放出来的图方向不对。
        $rotated = $editor->maybe_exif_rotate();

        if ( is_wp_error( $rotated ) ) {
            return null;
        }

        $target = $editor->generate_filename( 'scaled' );

        if ( ! is_string( $target ) || '' === $target ) {
            return null;
        }

        if ( $this->normalized_path( $target ) === $this->normalized_path( $source_path ) ) {
            return null;
        }

        if ( ! $this->is_inside_upload_dir( $target ) ) {
            return null;
        }

        $saved = $editor->save( $target );

        if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
            return null;
        }

        return $saved;
    }

    /**
     * 恢复附件原图
     *
     * 顺序固定为：备份写回原路径 → 还原引用与文章链接 → 记录中间状态 → 删除转换结果、
     * 备份索引与压缩记录。任一步失败都会保留可重试的线索，绝不会先删掉正在被引用的文件。
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

        $was_suppressed                 = $this->auto_compress_suppressed;
        $this->auto_compress_suppressed = true;

        try {
            if ( ! $this->has_restore_state( $attachment_id ) && ! $this->restore_files_and_refs( $attachment_id ) ) {
                return false;
            }

            return $this->finish_restore( $attachment_id );
        } finally {
            $this->auto_compress_suppressed = $was_suppressed;
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 把备份写回原路径并还原附件引用与文章链接
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function restore_files_and_refs( int $attachment_id ): bool {
        $entries = libre_compress()->output_processor->get_output_entries( $attachment_id );
        $backups = libre_compress()->backup->get_backups( $attachment_id );

        // 没有备份就没有可恢复的东西。备份被到期清理后附件仍留有映射和记录，
        // 若继续往下走会只清理不还原，把已压缩的图片误标成未处理。
        if ( empty( $backups ) ) {
            return false;
        }

        $live = array();
        foreach ( libre_compress()->compressor->get_attachment_files( $attachment_id ) as $file ) {
            $live[] = $this->normalized_path( $file['file_path'] );
        }

        // 只有源格式确实有备份时才允许退回源格式；此前未开启备份的历史映射必须忽略，
        // 否则会把当前正在使用的转换结果当成可删除的文件。
        $revertible = array();
        foreach ( $entries as $entry ) {
            if ( $this->backup_of( $backups, $entry['from'] ) ) {
                $revertible[ $entry['size_type'] ] = $entry;
            }
        }

        $targets   = array();
        $restored = 0;

        foreach ( $backups as $backup ) {
            $original = (string) $backup['original_path'];
            $mapped   = $this->entry_of_source( $revertible, $original );
            $is_live  = in_array( $this->normalized_path( $original ), $live, true );

            if ( null === $mapped && ! $is_live ) {
                // 既不是现用文件也不属于本次要退回的源文件，写回只会凭空多出无引用的图片。
                continue;
            }

            // 旧格式文件原地保留作为兼容格式回退，恢复时该路径上很可能是
            // 已压缩的旧格式文件：它同样是本次要退回的源文件，必须允许备份覆盖。
            if ( ! $this->restore_backup_row( $backup, $is_live || null !== $mapped ) ) {
                return false;
            }

            $restored++;

            if ( null !== $mapped ) {
                $targets[] = $mapped['to_relative'];
            }
        }

        // 一个文件都没还原就往下走清理，等于凭空的删除动作：格式转换映射可能被外部清掉，
        // 此时备份与源文件对不上号，若继续执行 finish_restore 会删掉备份文件和索引，
        // 用户就此永久失去恢复原图的能力，而界面还显示恢复成功。
        if ( 0 === $restored ) {
            return false;
        }

        if ( ! empty( $revertible ) && ! libre_compress()->output_processor->revert_attachment_format( $attachment_id, array_values( $revertible ) ) ) {
            return false;
        }

        return $this->write_restore_state( $attachment_id, $targets );
    }

    /**
     * 写回单个备份文件，已还原过的情况视为成功
     *
     * @param array $backup       备份索引行
     * @param bool  $allow_existing 原路径已有文件时是否允许覆盖；该文件不是本附件现用
     *                              文件、又不是本次要退回的源文件时必须为 false
     * @return bool
     */
    private function restore_backup_row( array $backup, bool $allow_existing ): bool {
        $original = (string) $backup['original_path'];

        if ( ! file_exists( $backup['backup_path'] ) ) {
            // 备份文件已不在：原路径仍有文件说明此前已还原，索引交给清理阶段删除。
            return file_exists( $original );
        }

        return libre_compress()->backup->restore_backup_file( $backup, $allow_existing );
    }

    /**
     * 清理恢复流程留下的文件与记录
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部清理成功
     */
    private function finish_restore( int $attachment_id ): bool {
        $state = $this->get_restore_state( $attachment_id );

        foreach ( $this->relative_targets( $state ) as $relative ) {
            $path = wp_upload_dir()['basedir'] . '/' . $relative;

            if ( ! file_exists( $path ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $path ) || file_exists( $path ) ) {
                return false;
            }
        }

        if ( ! libre_compress()->backup->delete_backup( $attachment_id ) ) {
            return false;
        }

        if ( ! libre_compress()->database->delete_records_by_attachment( $attachment_id ) ) {
            return false;
        }

        if ( ! libre_compress()->output_processor->forget_output_entries( $attachment_id ) ) {
            return false;
        }

        return $this->delete_restore_state( $attachment_id );
    }

    /**
     * 清除单个附件的全部处理痕迹
     *
     * 备份文件、备份索引、转换映射和压缩记录必须一起清除：只清记录会让下一轮压缩
     * 拿压缩结果当作原图备份；只清备份则等于悄悄丢掉用户唯一的恢复依据。
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部清除成功
     */
    private function clear_attachment_history( int $attachment_id ): bool {
        if ( ! libre_compress()->backup->delete_backup( $attachment_id ) ) {
            return false;
        }

        if ( ! libre_compress()->database->delete_records_by_attachment( $attachment_id ) ) {
            return false;
        }

        if ( ! libre_compress()->output_processor->forget_output_entries( $attachment_id ) ) {
            return false;
        }

        return $this->delete_restore_state( $attachment_id );
    }

    /**
     * 分页清除压缩记录及其备份、映射
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @return array cleared_count、failed_ids、next_after、has_more
     */
    public function clear_history_page( int $after_id = 0 ): array {
        $database = libre_compress()->database;
        $ids      = $database->get_history_attachment_ids_after( $after_id, self::HISTORY_PAGE_SIZE + 1 );
        $has_more = count( $ids ) > self::HISTORY_PAGE_SIZE;

        if ( $has_more ) {
            array_pop( $ids );
        }

        $cleared = 0;
        $failed  = array();

        foreach ( $ids as $attachment_id ) {
            $lock = $this->acquire_attachment_lock( $attachment_id );
            if ( false === $lock ) {
                $failed[] = $attachment_id;
                continue;
            }

            $global_lock = $this->acquire_global_lock( false );
            if ( false === $global_lock ) {
                $this->release_attachment_lock( $lock );
                $failed[] = $attachment_id;
                continue;
            }

            try {
                if ( $this->clear_attachment_history( $attachment_id ) ) {
                    $cleared++;
                } else {
                    $failed[] = $attachment_id;
                }
            } finally {
                $this->release_attachment_lock( $global_lock );
                $this->release_attachment_lock( $lock );
            }
        }

        return array(
            'cleared_count' => $cleared,
            'failed_ids'    => $failed,
            'next_after'    => empty( $ids ) ? $after_id : max( $ids ),
            'has_more'      => $has_more,
        );
    }

    /**
     * 定时清理超过保留时长的原图备份
     *
     * 只删备份文件和备份索引：图片与正文保持压缩、转换后的状态，压缩记录和格式转换
     * 映射一并保留，否则下一轮批量会把这批图重新压一遍。代价是从这一刻起无法再恢复原图。
     */
    public function prune_expired_backups(): void {
        // 无主数据与保留期无关：附件都没了，它的备份和记录已经无人认领，
        // 备份文件还会一直占磁盘，所以放在永久保留的判断之前。
        $this->prune_orphan_history();

        $settings = get_option( 'libre_compress_general', array() );
        $days     = Libre_Compress_Settings::normalize_retention_days(
            isset( $settings['backup_retention_days'] ) ? $settings['backup_retention_days'] : null
        );

        if ( Libre_Compress_Settings::RETENTION_PERMANENT === $days ) {
            return;
        }

        $after_id  = 0;
        $processed = 0;
        $has_more  = true;

        do {
            $page      = $this->prune_expired_backup_page( $days, $after_id );
            $after_id  = (int) $page['next_after'];
            $processed += count( $page['pruned_ids'] ) + count( $page['failed_ids'] );
            $has_more  = (bool) $page['has_more'];
        } while ( $has_more && $processed < self::BACKUP_PRUNE_LIMIT );

        // 还有积压时不必等到明天，稍后补排一次继续清理。
        if ( $has_more ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::BACKUP_PRUNE_HOOK );
        }
    }

    /**
     * 清理已删除附件留下的处理痕迹
     *
     * 附件被永久删除时，删除钩子只在当场尽力清理一次：拿不到锁就静默放弃，
     * 也没有任何重试入口。这里兜底扫描，删掉无主的备份文件、备份索引、
     * 压缩记录和转换映射，否则这些文件会一直占着磁盘。
     *
     * @return array removed_ids、failed_ids、removed_count、failed_count、has_more
     */
    public function prune_orphan_history(): array {
        $after_id  = 0;
        $removed   = array();
        $failed    = array();
        $processed = 0;
        $has_more  = true;

        do {
            $page      = $this->prune_orphan_history_page( $after_id );
            $after_id  = (int) $page['next_after'];
            $removed   = array_merge( $removed, $page['removed_ids'] );
            $failed    = array_merge( $failed, $page['failed_ids'] );
            $processed += count( $page['removed_ids'] ) + count( $page['failed_ids'] );
            $has_more  = (bool) $page['has_more'];
        } while ( $has_more && $processed < self::BACKUP_PRUNE_LIMIT );

        if ( $has_more ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::BACKUP_PRUNE_HOOK );
        }

        return array(
            'removed_ids'   => $removed,
            'failed_ids'    => $failed,
            'removed_count' => count( $removed ),
            'failed_count'  => count( $failed ),
            'has_more'      => $has_more,
        );
    }

    /**
     * 清理一批已删除附件的处理痕迹
     *
     * 附件已经不存在，不会有人再来压缩它，因此不取附件锁——否则只会给
     * 无主的附件 ID 留下永远不会用到的锁文件。仍要取全局维护锁，
     * 避免与“删除所有备份”并发。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @return array removed_ids、failed_ids、next_after、has_more
     */
    public function prune_orphan_history_page( int $after_id = 0 ): array {
        $ids      = libre_compress()->database->get_orphan_history_attachment_ids_after( $after_id, self::BACKUP_PRUNE_PAGE_SIZE + 1 );
        $has_more = count( $ids ) > self::BACKUP_PRUNE_PAGE_SIZE;

        if ( $has_more ) {
            array_pop( $ids );
        }

        $removed = array();
        $failed  = array();

        foreach ( $ids as $attachment_id ) {
            // 附件可能在扫描与清理之间被重新上传占用同一 ID，只处理确实已不存在的。
            if ( $this->attachment_exists( $attachment_id ) ) {
                continue;
            }

            $global_lock = $this->acquire_global_lock( false );

            if ( false === $global_lock ) {
                $failed[] = $attachment_id;
                continue;
            }

            try {
                if ( $this->clear_attachment_history( $attachment_id ) ) {
                    $removed[] = $attachment_id;
                } else {
                    $failed[] = $attachment_id;
                }
            } finally {
                $this->release_attachment_lock( $global_lock );
            }
        }

        return array(
            'removed_ids' => $removed,
            'failed_ids'  => $failed,
            'next_after'  => empty( $ids ) ? $after_id : (int) max( $ids ),
            'has_more'    => $has_more,
        );
    }

    /**
     * 清理一批到期原图备份
     *
     * 附件的备份索引逐条删除，任一条失败都保留其索引，等下次任务重试。
     *
     * @param int $days     保留天数
     * @param int $after_id 上一批最后处理的附件 ID
     * @return array pruned_ids、failed_ids、next_after、has_more
     */
    public function prune_expired_backup_page( int $days, int $after_id = 0 ): array {
        $ids      = libre_compress()->database->get_expired_backup_attachment_ids_after( $days, $after_id, self::BACKUP_PRUNE_PAGE_SIZE + 1 );
        $has_more = count( $ids ) > self::BACKUP_PRUNE_PAGE_SIZE;

        if ( $has_more ) {
            array_pop( $ids );
        }

        $pruned = array();
        $failed = array();

        foreach ( $ids as $attachment_id ) {
            $lock = $this->acquire_attachment_lock( $attachment_id );
            if ( false === $lock ) {
                $failed[] = $attachment_id;
                continue;
            }

            $global_lock = $this->acquire_global_lock( false );
            if ( false === $global_lock ) {
                $this->release_attachment_lock( $lock );
                $failed[] = $attachment_id;
                continue;
            }

            try {
                if ( libre_compress()->backup->delete_backup( $attachment_id ) ) {
                    $pruned[] = $attachment_id;
                } else {
                    $failed[] = $attachment_id;
                }
            } finally {
                $this->release_attachment_lock( $global_lock );
                $this->release_attachment_lock( $lock );
            }
        }

        return array(
            'pruned_ids' => $pruned,
            'failed_ids' => $failed,
            'next_after' => empty( $ids ) ? $after_id : (int) max( $ids ),
            'has_more'   => $has_more,
        );
    }

    /**
     * 是否存在引用已还原、但残留文件与记录尚未清理完的附件
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function has_pending_restore( int $attachment_id ): bool {
        return $this->has_restore_state( $attachment_id );
    }

    /**
     * 读取恢复中间状态
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    private function get_restore_state( int $attachment_id ): array {
        $state = get_post_meta( $attachment_id, self::RESTORE_STATE_META_KEY, true );

        return is_array( $state ) ? $state : array();
    }

    /**
     * 是否存在待清理的恢复中间状态
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function has_restore_state( int $attachment_id ): bool {
        $state = $this->get_restore_state( $attachment_id );

        return isset( $state['targets'] ) && is_array( $state['targets'] );
    }

    /**
     * 写入恢复中间状态
     *
     * @param int   $attachment_id 附件 ID
     * @param array $targets       待删除的转换结果相对路径
     * @return bool
     */
    private function write_restore_state( int $attachment_id, array $targets ): bool {
        $state = array( 'targets' => array_values( $targets ) );

        update_post_meta( $attachment_id, self::RESTORE_STATE_META_KEY, wp_slash( $state ) );

        $saved = $this->get_restore_state( $attachment_id );

        return isset( $saved['targets'] ) && $saved['targets'] === $state['targets'];
    }

    /**
     * 删除恢复中间状态
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function delete_restore_state( int $attachment_id ): bool {
        if ( ! $this->has_restore_state( $attachment_id ) ) {
            return true;
        }

        delete_post_meta( $attachment_id, self::RESTORE_STATE_META_KEY );

        return ! $this->has_restore_state( $attachment_id );
    }

    /**
     * 取出状态中待删除的转换结果路径
     *
     * @param array $state 恢复中间状态
     * @return array
     */
    private function relative_targets( array $state ): array {
        if ( empty( $state['targets'] ) || ! is_array( $state['targets'] ) ) {
            return array();
        }

        $targets = array();

        foreach ( $state['targets'] as $target ) {
            $target = ltrim( wp_normalize_path( (string) $target ), '/' );

            // 中间状态属于持久化数据，删除前重新校验路径。
            if ( '' === $target || false !== strpos( $target, '..' ) || false !== strpos( $target, ':' ) ) {
                continue;
            }

            $targets[] = $target;
        }

        return $targets;
    }

    /**
     * 按原始路径查找备份索引
     *
     * @param array  $backups 备份索引列表
     * @param string $path    原始路径
     * @return array|null
     */
    private function backup_of( array $backups, string $path ) {
        $normalized = $this->normalized_path( $path );

        foreach ( $backups as $backup ) {
            if ( $this->normalized_path( (string) $backup['original_path'] ) === $normalized ) {
                return $backup;
            }
        }

        return null;
    }

    /**
     * 按源文件路径查找可退回的转换条目
     *
     * @param array  $entries 条目集合
     * @param string $path    源文件绝对路径
     * @return array|null
     */
    private function entry_of_source( array $entries, string $path ) {
        $normalized = $this->normalized_path( $path );

        foreach ( $entries as $entry ) {
            if ( $this->normalized_path( $entry['from'] ) === $normalized ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * 删除附件的兼容格式回退文件
     *
     * 只删除仍有对应新格式文件的旧格式文件，并把正文里指向旧格式的链接改到新格式，
     * 删除后不支持新格式的浏览器也统一加载新格式图片。
     *
     * @param int $attachment_id 附件 ID
     * @return array deleted_files、replaced、failed
     */
    public function delete_fallback( int $attachment_id ): array {
        $result = array(
            'deleted_files' => 0,
            'replaced'      => 0,
            'failed'        => false,
        );

        if ( ! $attachment_id ) {
            $result['failed'] = true;
            return $result;
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );

        if ( false === $lock ) {
            $result['failed'] = true;
            return $result;
        }

        $global_lock = $this->acquire_global_lock( false );

        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            $result['failed'] = true;
            return $result;
        }

        try {
            $pairs   = array();
            $deleted = array();

            foreach ( libre_compress()->output_processor->get_fallback_entries( $attachment_id ) as $entry ) {
                // 回退文件必须位于 uploads 内且不是符号链接，否则一律不动。
                if ( ! $this->is_inside_upload_dir( $entry['from'] ) || is_link( $entry['from'] ) ) {
                    $result['failed'] = true;
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( ! unlink( $entry['from'] ) || file_exists( $entry['from'] ) ) {
                    $result['failed'] = true;
                    continue;
                }

                $deleted[] = $entry['size_type'];
                $pairs[]   = array(
                    'from' => $entry['from'],
                    'to'   => $entry['to'],
                );

                $result['deleted_files']++;
            }

            if ( empty( $pairs ) ) {
                return $result;
            }

            // 删除后正文里固化的旧格式地址会 404，必须改到新格式。
            $replaced              = 0;
            $updated               = libre_compress()->output_processor->update_content_references( $pairs, $replaced );
            $result['replaced']    = $replaced;
            $result['failed']      = $result['failed'] || ! $updated;

            // 回退文件已删除，指向它的压缩记录不再描述任何真实文件。
            libre_compress()->output_processor->forget_fallback_records( $attachment_id, $deleted );

            return $result;
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 分页删除所有附件的兼容格式回退
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @return array processed、deleted_files、replaced、failed_ids、next_after、has_more
     */
    public function delete_fallbacks_page( int $after_id = 0 ): array {
        $ids      = libre_compress()->database->get_fallback_attachment_ids_after( $after_id, self::FALLBACK_PAGE_SIZE + 1 );
        $has_more = count( $ids ) > self::FALLBACK_PAGE_SIZE;

        if ( $has_more ) {
            array_pop( $ids );
        }

        $deleted_files = 0;
        $replaced      = 0;
        $processed     = 0;
        $failed        = array();

        foreach ( $ids as $attachment_id ) {
            $result = $this->delete_fallback( $attachment_id );

            $deleted_files += (int) $result['deleted_files'];
            $replaced      += (int) $result['replaced'];

            if ( (int) $result['deleted_files'] > 0 ) {
                $processed++;
            }

            if ( ! empty( $result['failed'] ) ) {
                $failed[] = $attachment_id;
            }
        }

        return array(
            'processed'     => $processed,
            'deleted_files' => $deleted_files,
            'replaced'      => $replaced,
            'failed_ids'    => $failed,
            'next_after'    => empty( $ids ) ? $after_id : (int) max( $ids ),
            'has_more'      => $has_more,
        );
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
            // 旧格式回退文件不写进附件元数据，WordPress 删附件时不会带走它们，先按映射删掉。
            libre_compress()->output_processor->delete_fallback_files( $attachment_id );

            $this->clear_attachment_history( $attachment_id );
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
     * 同步附件元数据中记录的文件大小
     *
     * 主文件和每个尺寸都要同步：压缩或格式转换后文件已经变小，
     * 只更新主文件会让所有尺寸的 filesize 停留在压缩前的旧值，
     * 读取元数据的调用方拿到的就是错误大小。
     *
     * @param int $attachment_id 附件 ID
     */
    private function refresh_metadata_file_sizes( int $attachment_id ): void {
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return;
        }

        $changed = $this->sync_metadata_filesize( $metadata, (string) $metadata['file'] );

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $file_dir = dirname( (string) $metadata['file'] );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                $synced = $this->sync_metadata_filesize(
                    $metadata['sizes'][ $size_name ],
                    $file_dir . '/' . $size_data['file']
                );

                $changed = $changed || $synced;
            }
        }

        if ( ! $changed ) {
            return;
        }

        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    /**
     * 把单个文件条目的 filesize 对齐磁盘实际大小
     *
     * @param array  $target       待同步的元数据条目（引用传递）
     * @param string $relative_path 相对 uploads 根的路径
     * @return bool 是否发生改动
     */
    private function sync_metadata_filesize( array &$target, string $relative_path ): bool {
        if ( '' === $relative_path ) {
            return false;
        }

        $absolute_path = untrailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) ) . '/' . ltrim( $relative_path, '/' );

        if ( ! $this->is_inside_upload_dir( $absolute_path ) || ! is_file( $absolute_path ) ) {
            return false;
        }

        clearstatcache( true, $absolute_path );
        $filesize = (int) filesize( $absolute_path );

        if ( isset( $target['filesize'] ) && (int) $target['filesize'] === $filesize ) {
            return false;
        }

        $target['filesize'] = $filesize;

        return true;
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
    /**
     * 获取上传目录内的相对路径
     *
     * 返回值只统一分隔符、不动大小写，避免写入元数据的文件名大小写被改掉。
     *
     * @param string $path 绝对路径或相对路径
     * @return string
     */
    private function get_relative_upload_path( string $path ): string {
        $upload_dir = wp_upload_dir();
        $normalized = wp_normalize_path( $path );
        $base_dir   = $upload_dir['basedir'];

        if ( 0 === strpos( $this->normalized_path( $normalized ), $this->normalized_path( $base_dir ) . '/' ) ) {
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
