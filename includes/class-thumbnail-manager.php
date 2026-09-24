<?php
/**
 * 缩略图管理类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 缩略图管理类
 *
 * 负责管理 WordPress 缩略图的生成、删除和重新生成
 */
class Libre_Compress_Thumbnail_Manager {

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
        // 禁止生成缩略图
        add_filter( 'intermediate_image_sizes_advanced', array( $this, 'maybe_disable_thumbnails' ), 10, 3 );

        // 注册 AJAX 接口
        add_action( 'wp_ajax_libre_compress_thumbnail_action', array( $this, 'ajax_thumbnail_action' ) );
    }

    /**
     * 根据设置决定是否禁止生成缩略图
     *
     * @param array  $sizes    缩略图尺寸
     * @param array  $metadata 图片元数据
     * @param int    $attachment_id 附件 ID
     * @return array 修改后的尺寸
     */
    public function maybe_disable_thumbnails( $sizes, $metadata = array(), $attachment_id = 0 ) {
        $settings = get_option( 'libre_compress_general', array() );
        $disabled = isset( $settings['disable_thumbnails'] ) ? (bool) $settings['disable_thumbnails'] : false;

        if ( $disabled ) {
            return array();
        }

        return $sizes;
    }

    /**
     * 禁止生成缩略图
     *
     * @return bool 是否成功
     */
    public function disable_thumbnails(): bool {
        $settings = get_option( 'libre_compress_general', array() );
        $settings['disable_thumbnails'] = true;
        return update_option( 'libre_compress_general', $settings );
    }

    /**
     * 启用生成缩略图
     *
     * @return bool 是否成功
     */
    public function enable_thumbnails(): bool {
        $settings = get_option( 'libre_compress_general', array() );
        $settings['disable_thumbnails'] = false;
        return update_option( 'libre_compress_general', $settings );
    }

    /**
     * 删除附件的所有缩略图
     *
     * @param int $attachment_id 附件 ID
     * @return int 删除的文件数量
     */
    public function delete_attachment_thumbnails( int $attachment_id ): int {
        $lock = libre_compress()->processor->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return 0;
        }

        $global_lock = libre_compress()->processor->acquire_global_lock( false );
        if ( false === $global_lock ) {
            libre_compress()->processor->release_attachment_lock( $lock );
            return 0;
        }

        try {
            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( empty( $metadata ) || empty( $metadata['file'] ) || empty( $metadata['sizes'] ) ) {
                return 0;
            }

            $upload_dir = wp_upload_dir();
            $base_dir   = $upload_dir['basedir'];
            $file_dir   = dirname( $metadata['file'] );
            $deleted    = 0;
            $removed    = array();

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $thumb_path = $base_dir . '/' . $file_dir . '/' . $size_data['file'];
                if ( file_exists( $thumb_path ) && $this->is_safe_upload_path( $thumb_path ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    if ( unlink( $thumb_path ) ) {
                        $deleted++;
                        $removed[ $size_name ] = true;
                    }
                }
            }

            foreach ( array_keys( $removed ) as $size_name ) {
                unset( $metadata['sizes'][ $size_name ] );
            }

            if ( ! empty( $removed ) ) {
                $metadata_updated = false !== wp_update_attachment_metadata( $attachment_id, $metadata );
                if ( ! $metadata_updated ) {
                    return $deleted;
                }

                $database = libre_compress()->database;
                foreach ( array_keys( $removed ) as $size_name ) {
                    $record = $database->get_record( $attachment_id, $size_name );
                    if ( $record ) {
                        $database->delete_record( $record['id'] );
                    }
                }
            }

            return $deleted;
        } finally {
            libre_compress()->processor->release_attachment_lock( $global_lock );
            libre_compress()->processor->release_attachment_lock( $lock );
        }
    }

    /**
     * 删除所有附件的缩略图
     *
     * @return array 操作结果
     */
    public function delete_all_thumbnails(): array {
        $database            = libre_compress()->database;
        $after_id            = 0;
        $total_deleted       = 0;
        $affected_attachments = 0;

        do {
            $attachments = $database->get_image_attachment_ids_after( $after_id, 100 );
            foreach ( $attachments as $attachment_id ) {
                $deleted = $this->delete_attachment_thumbnails( absint( $attachment_id ) );
                if ( $deleted > 0 ) {
                    $total_deleted += $deleted;
                    $affected_attachments++;
                }
            }
            if ( ! empty( $attachments ) ) {
                $after_id = max( array_map( 'absint', $attachments ) );
            }
        } while ( count( $attachments ) === 100 );

        return array(
            'deleted_files'        => $total_deleted,
            'affected_attachments' => $affected_attachments,
        );
    }

    /**
     * 替换文章中的图片链接为原图
     *
     * @return int 替换的数量
     */
    public function replace_thumbnails_with_original(): int {
        global $wpdb;

        $replaced  = 0;
        $after_id  = 0;

        do {
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                    WHERE ID > %d
                    AND post_content LIKE '%<img%'
                    AND post_status != 'trash'
                    ORDER BY ID ASC
                    LIMIT 100",
                    $after_id
                )
            );

            foreach ( $posts as $post ) {
                $content     = $post->post_content;
                $new_content = $content;

                preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
                foreach ( $matches[1] as $img_url ) {
                    if ( preg_match( '/-\d+x\d+\.(jpg|jpeg|png|gif|webp|avif|svg)$/i', $img_url ) ) {
                        $original_url = preg_replace( '/-\d+x\d+\./', '.', $img_url );
                        $new_content  = str_replace( $img_url, $original_url, $new_content );
                        $replaced++;
                    }
                }

                if ( $new_content !== $content ) {
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'post_content' => $new_content ),
                        array( 'ID' => $post->ID ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }
            }

            if ( ! empty( $posts ) ) {
                $after_id = max( array_map( 'absint', wp_list_pluck( $posts, 'ID' ) ) );
            }
        } while ( count( $posts ) === 100 );

        return $replaced;
    }

    /**
     * 重新生成附件的缩略图
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否成功
     */
    public function regenerate_attachment_thumbnails( int $attachment_id ): bool {
        $lock = libre_compress()->processor->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return false;
        }

        $global_lock = libre_compress()->processor->acquire_global_lock( false );
        if ( false === $global_lock ) {
            libre_compress()->processor->release_attachment_lock( $lock );
            return false;
        }

        try {
            $processor = libre_compress()->processor;
            $file_path = get_attached_file( $attachment_id );

            if ( ! $file_path || ! file_exists( $file_path ) ) {
                return false;
            }

            // 元数据重建期间明确暂停自动压缩，避免插件自己处理刚生成的尺寸。
            $was_suppressed = $processor->is_auto_compress_suppressed();
            $processor->set_auto_compress_suppressed( true );
            try {
                $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
            } finally {
                $processor->set_auto_compress_suppressed( $was_suppressed );
            }

            if ( empty( $metadata ) ) {
                return false;
            }

            return false !== wp_update_attachment_metadata( $attachment_id, $metadata );
        } finally {
            libre_compress()->processor->release_attachment_lock( $global_lock );
            libre_compress()->processor->release_attachment_lock( $lock );
        }
    }

    /**
     * 重新生成缺少缩略图的附件
     *
     * @return array 操作结果
     */
    public function regenerate_missing_thumbnails(): array {
        $database = libre_compress()->database;
        $after_id = 0;
        $success  = 0;
        $failed   = 0;

        do {
            $attachments = $database->get_image_attachment_ids_after( $after_id, 100 );
            foreach ( $attachments as $attachment_id ) {
                $metadata = wp_get_attachment_metadata( $attachment_id );
                if ( ! empty( $metadata['sizes'] ) ) {
                    continue;
                }

                if ( $this->regenerate_attachment_thumbnails( $attachment_id ) ) {
                    $success++;
                } else {
                    $failed++;
                }
            }

            if ( ! empty( $attachments ) ) {
                $after_id = max( $attachments );
            }
        } while ( count( $attachments ) === 100 );

        return array(
            'success' => $success,
            'failed'  => $failed,
        );
    }

    /**
     * 替换文章中的图片链接为"大"尺寸
     *
     * @return int 替换的数量
     */
    public function replace_original_with_large(): int {
        global $wpdb;

        $replaced = 0;
        $after_id = 0;

        do {
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                    WHERE ID > %d
                    AND post_content LIKE '%<img%'
                    AND post_status != 'trash'
                    ORDER BY ID ASC
                    LIMIT 100",
                    $after_id
                )
            );

            foreach ( $posts as $post ) {
                $content     = $post->post_content;
                $new_content = $content;
                preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

                foreach ( $matches[1] as $img_url ) {
                    if ( preg_match( '/-\d+x\d+\.(jpg|jpeg|png|gif|webp|avif|svg)$/i', $img_url ) ) {
                        continue;
                    }

                    $attachment_id = attachment_url_to_postid( $img_url );
                    if ( $attachment_id ) {
                        $large_url = wp_get_attachment_image_url( $attachment_id, 'large' );
                        if ( $large_url && $large_url !== $img_url ) {
                            $new_content = str_replace( $img_url, $large_url, $new_content );
                            $replaced++;
                        }
                    }
                }

                if ( $new_content !== $content ) {
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'post_content' => $new_content ),
                        array( 'ID' => $post->ID ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }
            }

            if ( ! empty( $posts ) ) {
                $after_id = max( array_map( 'absint', wp_list_pluck( $posts, 'ID' ) ) );
            }
        } while ( count( $posts ) === 100 );

        return $replaced;
    }

    /**
     * 验证文件位于 uploads 目录内
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    private function is_safe_upload_path( string $file_path ): bool {
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $file_path );

        if ( false === $base_dir || false === $real_path || false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $real_path = untrailingslashit( wp_normalize_path( $real_path ) );

        return 0 === strpos( $real_path, $base_dir . '/' );
    }

    /**
     * AJAX: 缩略图管理操作
     */
    public function ajax_thumbnail_action() {
        // 验证 nonce
        check_ajax_referer( 'libre_compress_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'libre-compress' ) ) );
        }

        // 获取操作类型
        $action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

        switch ( $action_type ) {
            case 'disable':
                // 禁止生成缩略图
                $this->disable_thumbnails();
                wp_send_json_success(
                    array(
                        'message' => __( '已禁止生成缩略图', 'libre-compress' ),
                    )
                );
                break;

            case 'delete':
                // 删除已有缩略图
                $result = $this->delete_all_thumbnails();

                // 替换文章中的图片链接
                $replaced = $this->replace_thumbnails_with_original();

                wp_send_json_success(
                    array(
                        'message'        => sprintf(
                            /* translators: 1: 删除的文件数, 2: 影响的附件数, 3: 替换的链接数 */
                            __( '已删除 %1$d 个缩略图文件（%2$d 个附件），替换了 %3$d 个图片链接', 'libre-compress' ),
                            $result['deleted_files'],
                            $result['affected_attachments'],
                            $replaced
                        ),
                        'deleted_files'  => $result['deleted_files'],
                        'affected_count' => $result['affected_attachments'],
                        'replaced_links' => $replaced,
                    )
                );
                break;

            case 'enable':
                // 仅启用缩略图生成
                $this->enable_thumbnails();
                wp_send_json_success(
                    array(
                        'message' => __( '已启用缩略图生成', 'libre-compress' ),
                    )
                );
                break;

            case 'regenerate':
                // 重新生成缩略图（不改变启用状态）
                $result = $this->regenerate_missing_thumbnails();

                // 替换文章中的图片链接
                $replaced = $this->replace_original_with_large();

                wp_send_json_success(
                    array(
                        'message'        => sprintf(
                            /* translators: 1: 成功数, 2: 失败数, 3: 替换的链接数 */
                            __( '已重新生成 %1$d 个附件的缩略图（%2$d 个失败），替换了 %3$d 个图片链接', 'libre-compress' ),
                            $result['success'],
                            $result['failed'],
                            $replaced
                        ),
                        'success_count'  => $result['success'],
                        'failed_count'   => $result['failed'],
                        'replaced_links' => $replaced,
                    )
                );
                break;

            default:
                wp_send_json_error( array( 'message' => __( '无效的操作类型', 'libre-compress' ) ) );
        }
    }
}
