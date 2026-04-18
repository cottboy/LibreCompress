<?php
/**
 * 媒体库集成类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 媒体库集成类
 *
 * 负责在媒体库中显示压缩状态和操作按钮
 */
class Libre_Compress_Media_Library {

    /**
     * 构造函数
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 添加媒体库列
        add_filter( 'manage_media_columns', array( $this, 'add_compression_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_compression_column' ), 10, 2 );
        add_action( 'admin_head-upload.php', array( $this, 'output_media_library_styles' ) );

        // 注册 AJAX 接口
        add_action( 'wp_ajax_libre_compress_get_uncompressed', array( $this, 'ajax_get_uncompressed' ) );
        add_action( 'wp_ajax_libre_compress_compress_single', array( $this, 'ajax_compress_single' ) );
        add_action( 'wp_ajax_libre_compress_restore', array( $this, 'ajax_restore' ) );
        add_action( 'wp_ajax_libre_compress_restore_all', array( $this, 'ajax_restore_all' ) );
        add_action( 'wp_ajax_libre_compress_clear_records', array( $this, 'ajax_clear_records' ) );
        add_action( 'wp_ajax_libre_compress_delete_backup', array( $this, 'ajax_delete_backup' ) );
        add_action( 'wp_ajax_libre_compress_delete_all_backups', array( $this, 'ajax_delete_all_backups' ) );
    }

    /**
     * 添加压缩状态列
     *
     * @param array $columns 现有列
     * @return array 修改后的列
     */
    public function add_compression_column( $columns ) {
        $columns['libre_compress'] = __( '压缩', 'libre-compress' );
        return $columns;
    }

    /**
     * 输出媒体库列样式
     */
    public function output_media_library_styles() {
        ?>
        <style>
            .wp-list-table .column-libre_compress {
                width: 160px;
            }
        </style>
        <?php
    }

    /**
     * 渲染压缩状态列内容
     *
     * @param string $column_name 列名
     * @param int    $attachment_id 附件 ID
     */
    public function render_compression_column( $column_name, $attachment_id ) {
        if ( 'libre_compress' !== $column_name ) {
            return;
        }

        // 检查是否为图片
        $mime_type     = get_post_mime_type( $attachment_id );
        $allowed_types = array( 'image/jpeg', 'image/png', 'image/webp' );

        if ( ! in_array( $mime_type, $allowed_types, true ) ) {
            echo '<span class="libre-compress-na">—</span>';
            return;
        }

        // 获取压缩统计
        $database = libre_compress()->database;
        $stats    = $database->get_attachment_stats( $attachment_id );

        // 检查是否有备份
        $has_backup = libre_compress()->backup->has_backup( $attachment_id );

        // 渲染状态
        if ( empty( $stats ) || 0 === (int) $stats['total_files'] ) {
            // 未压缩
            $this->render_uncompressed_status( $attachment_id );
        } elseif ( (int) $stats['failed_count'] > 0 && 0 === (int) $stats['success_count'] ) {
            // 全部失败
            $this->render_failed_status( $attachment_id );
        } else {
            // 已压缩
            $this->render_compressed_status( $attachment_id, $stats, $has_backup );
        }
    }

    /**
     * 渲染未压缩状态
     *
     * @param int $attachment_id 附件 ID
     */
    private function render_uncompressed_status( int $attachment_id ) {
        ?>
        <div class="libre-compress-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <span class="status-text"><?php esc_html_e( '未压缩', 'libre-compress' ); ?></span>
            <button type="button" class="button button-small libre-compress-btn" data-action="compress" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
                <?php esc_html_e( '压缩', 'libre-compress' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * 渲染压缩失败状态
     *
     * @param int $attachment_id 附件 ID
     */
    private function render_failed_status( int $attachment_id ) {
        ?>
        <div class="libre-compress-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <span class="status-text" style="color: #d63638;"><?php esc_html_e( '压缩失败', 'libre-compress' ); ?></span>
            <button type="button" class="button button-small libre-compress-btn" data-action="compress" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
                <?php esc_html_e( '重试', 'libre-compress' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * 渲染已压缩状态
     *
     * @param int   $attachment_id 附件 ID
     * @param array $stats         压缩统计
     * @param bool  $has_backup    是否有备份
     */
    private function render_compressed_status( int $attachment_id, array $stats, bool $has_backup ) {
        $original_size   = isset( $stats['total_original_size'] ) ? max( 0, absint( $stats['total_original_size'] ) ) : 0;
        $compressed_size = isset( $stats['total_compressed_size'] ) ? max( 0, absint( $stats['total_compressed_size'] ) ) : 0;
        $saved_bytes     = max( 0, $original_size - $compressed_size );
        ?>
        <div class="libre-compress-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <span class="status-text" style="color: #00a32a;">
                <?php
                printf(
                    /* translators: %s: 压缩比例 */
                    esc_html__( '已压缩%s%%', 'libre-compress' ),
                    esc_html( $stats['total_ratio'] )
                );
                ?>
            </span>
            <br>
            <small style="color: #666;">
                <?php
                printf(
                    /* translators: 1: 原始大小，2: 节省大小，3: 当前大小 */
                    esc_html__( '%1$s-%2$s=%3$s', 'libre-compress' ),
                    esc_html( $this->format_file_size( $original_size ) ),
                    esc_html( $this->format_file_size( $saved_bytes ) ),
                    esc_html( $this->format_file_size( $compressed_size ) )
                );
                ?>
            </small>
            <?php if ( $has_backup ) : ?>
                <br>
                <button type="button" class="button button-small libre-compress-btn" data-action="restore" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
                    <?php esc_html_e( '恢复原图', 'libre-compress' ); ?>
                </button>
                <button type="button" class="button button-small libre-compress-btn" data-action="delete-backup" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
                    <?php esc_html_e( '删除备份', 'libre-compress' ); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: 获取未压缩图片列表
     */
    public function ajax_get_uncompressed() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        $database = libre_compress()->database;
        $compressor = libre_compress()->compressor;

        // 获取未压缩的附件
        $attachments = $database->get_uncompressed_attachments( 1000, 0 );

        $items = array();
        foreach ( $attachments as $attachment ) {
            $attachment_id = absint( $attachment['ID'] );
            $files = $compressor->get_attachment_files( $attachment_id );

            foreach ( $files as $file ) {
                // 检查该文件是否已有成功的压缩记录
                $record = $database->get_record( $attachment_id, $file['size_type'] );
                if ( $record && 'success' === $record['status'] ) {
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
     * AJAX: 压缩单个文件
     */
    public function ajax_compress_single() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        // 获取参数
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $size_type     = isset( $_POST['size_type'] ) ? sanitize_text_field( wp_unslash( $_POST['size_type'] ) ) : '';

        if ( ! $attachment_id ) {
            wp_send_json_error( array( 'message' => __( '无效的附件 ID', 'libre-compress' ) ) );
        }

        $compressor = libre_compress()->compressor;

        // 如果指定了 size_type，只压缩该文件
        if ( ! empty( $size_type ) ) {
            $files = $compressor->get_attachment_files( $attachment_id );
            $target_file = null;

            foreach ( $files as $file ) {
                if ( $file['size_type'] === $size_type ) {
                    $target_file = $file;
                    break;
                }
            }

            if ( ! $target_file ) {
                wp_send_json_error( array( 'message' => __( '文件不存在', 'libre-compress' ) ) );
            }

            $result = $compressor->compress_file(
                $attachment_id,
                $target_file['file_path'],
                $target_file['size_type']
            );

            wp_send_json_success( $result );
        }

        // 否则压缩整个附件
        $result = $compressor->compress_attachment( $attachment_id );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 恢复原图
     */
    public function ajax_restore() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        // 获取参数
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( array( 'message' => __( '无效的附件 ID', 'libre-compress' ) ) );
        }

        $backup = libre_compress()->backup;

        // 检查是否有备份
        if ( ! $backup->has_backup( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( '没有可用的备份', 'libre-compress' ) ) );
        }

        // 恢复备份
        $result = $backup->restore_backup( $attachment_id );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( '恢复成功', 'libre-compress' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '恢复失败', 'libre-compress' ) ) );
        }
    }

    /**
     * AJAX: 清除压缩记录
     */
    public function ajax_clear_records() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        $database = libre_compress()->database;

        // 清除所有压缩记录
        $count = $database->clear_all_records();

        wp_send_json_success(
            array(
                'message'       => __( '压缩记录已清除', 'libre-compress' ),
                'cleared_count' => $count,
            )
        );
    }

    /**
     * AJAX: 删除单张图片的备份
     */
    public function ajax_delete_backup() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        // 获取参数
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( array( 'message' => __( '无效的附件 ID', 'libre-compress' ) ) );
        }

        $backup = libre_compress()->backup;

        // 检查是否有备份
        if ( ! $backup->has_backup( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( '没有可用的备份', 'libre-compress' ) ) );
        }

        // 删除备份
        $result = $backup->delete_backup( $attachment_id );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( '备份已删除', 'libre-compress' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '删除失败', 'libre-compress' ) ) );
        }
    }

    /**
     * AJAX: 删除所有原图备份
     */
    public function ajax_delete_all_backups() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        $backup = libre_compress()->backup;

        // 删除所有备份
        $count = $backup->delete_all_backups();

        wp_send_json_success(
            array(
                'message'       => sprintf(
                    /* translators: %d: 删除的备份数量 */
                    __( '已删除 %d 个备份文件', 'libre-compress' ),
                    $count
                ),
                'deleted_count' => $count,
            )
        );
    }

    /**
     * AJAX: 恢复所有原图备份
     */
    public function ajax_restore_all() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        $database = libre_compress()->database;
        $backup   = libre_compress()->backup;

        // 获取所有备份记录
        $backups = $database->get_all_backups();

        if ( empty( $backups ) ) {
            wp_send_json_error( array( 'message' => __( '没有可用的备份', 'libre-compress' ) ) );
        }

        // 按附件 ID 分组
        $attachment_ids = array_unique( array_column( $backups, 'attachment_id' ) );

        $success_count = 0;
        $failed_count  = 0;

        foreach ( $attachment_ids as $attachment_id ) {
            if ( $backup->restore_backup( absint( $attachment_id ) ) ) {
                $success_count++;
            } else {
                $failed_count++;
            }
        }

        wp_send_json_success(
            array(
                'message'       => sprintf(
                    /* translators: %1$d: 成功数量, %2$d: 失败数量 */
                    __( '恢复完成，成功 %1$d 个，失败 %2$d 个', 'libre-compress' ),
                    $success_count,
                    $failed_count
                ),
                'success_count' => $success_count,
                'failed_count'  => $failed_count,
            )
        );
    }

    /**
     * 格式化文件大小
     *
     * @param int $bytes 字节数
     * @return string 格式化后的大小
     */
    private function format_file_size( int $bytes ): string {
        if ( $bytes < 1024 ) {
            return $bytes . 'B';
        } elseif ( $bytes < 1024 * 1024 ) {
            return round( $bytes / 1024, 2 ) . 'KB';
        } else {
            return round( $bytes / ( 1024 * 1024 ), 2 ) . 'MB';
        }
    }
}
