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
 * 按设置决定生成哪些尺寸的缩略图、调整大图缩放阈值，并负责清理未勾选尺寸的旧文件、补生成缺失的尺寸
 */
class Libre_Compress_Thumbnail_Manager {

    /**
     * AJAX 分页单页处理的附件数量
     *
     * 一次请求只处理一页，避免大媒体库下单次 PHP 请求超时；
     * 前端按 next_after 游标循环，直到 has_more 为 false。
     */
    const PAGE_SIZE = 20;

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
        // 按设置跳过未勾选的缩略图尺寸
        add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_sizes_for_generation' ), 10, 3 );

        // 按设置调整大图缩放阈值
        add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_size_threshold' ) );

        // 注册 AJAX 接口
        add_action( 'wp_ajax_libre_compress_thumbnail_action', array( $this, 'ajax_thumbnail_action' ) );
    }

    /**
     * 未勾选、即不再生成的缩略图尺寸
     *
     * 存黑名单而不是白名单：以后主题或插件新注册的尺寸默认会生成，
     * 与设置页“默认全部勾选”的说法一致。
     *
     * @return string[]
     */
    public static function disabled_sizes(): array {
        $settings = get_option( 'libre_compress_general', array() );
        $disabled = isset( $settings['disabled_thumbnail_sizes'] ) ? (array) $settings['disabled_thumbnail_sizes'] : array();

        return array_values( array_filter( array_map( 'sanitize_key', $disabled ) ) );
    }

    /**
     * 判断某个缩略图尺寸是否已勾选生成
     *
     * @param string $size_name 尺寸名
     * @return bool
     */
    public static function is_size_enabled( string $size_name ): bool {
        return ! in_array( $size_name, self::disabled_sizes(), true );
    }

    /**
     * 当前已注册且已勾选的尺寸
     *
     * @return string[]
     */
    public static function enabled_sizes(): array {
        $registered = array_keys( wp_get_registered_image_subsizes() );

        return array_values( array_diff( $registered, self::disabled_sizes() ) );
    }

    /**
     * 生成缩略图时剔除未勾选的尺寸
     *
     * @param array $sizes         待生成的尺寸
     * @param array $metadata      图片元数据
     * @param int   $attachment_id 附件 ID
     * @return array
     */
    public function filter_sizes_for_generation( $sizes, $metadata = array(), $attachment_id = 0 ) {
        if ( ! is_array( $sizes ) || empty( $sizes ) ) {
            return $sizes;
        }

        foreach ( self::disabled_sizes() as $size_name ) {
            unset( $sizes[ $size_name ] );
        }

        return $sizes;
    }

    /**
     * 按设置覆盖 WordPress 的大图缩放阈值
     *
     * 接的是 WordPress 生成 -scaled 缩放图时用的那个阈值，官方默认 2560。
     * 勾选自动缩放时返回设定阈值，未勾选时返回 0——0 与 false 一样会被核心
     * 当作“不缩放”处理，上传的大图直接作为主文件投入使用。
     *
     * @param int|false $threshold WordPress 当前阈值
     * @return int 开启自动缩放时为设定阈值，关闭时为 0
     */
    public function filter_big_image_size_threshold( $threshold ) {
        return Libre_Compress_Settings::image_scale_enabled()
            ? Libre_Compress_Settings::image_size_threshold()
            : 0;
    }

    /**
     * 按附件 ID 游标删除一页未勾选尺寸的缩略图
     *
     * 前端按 next_after 游标循环调用，每请求只处理一页，
     * 避免大媒体库下单次 PHP 请求超时。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本页数量
     * @return array deleted_files、affected_attachments、replaced_links、failed_ids、content_failed、processed、next_after、has_more
     */
    public function delete_disabled_page( int $after_id = 0, int $limit = 20 ): array {
        $result = array(
            'deleted_files'        => 0,
            'affected_attachments' => 0,
            'replaced_links'       => 0,
            'failed_ids'           => array(),
            'content_failed'       => false,
            'processed'            => 0,
            'next_after'           => $after_id,
            'has_more'             => false,
        );

        // 不能因为“没有未勾选的尺寸”就整页跳过：-scaled 缩放图的清理与尺寸勾选无关，
        // 关闭自动缩放或阈值改小后，即使尺寸全部勾选也要进来把缩放图退回原图。
        $limit       = max( 1, min( 100, $limit ) );
        $attachments = libre_compress()->database->get_image_attachment_ids_after( $after_id, $limit + 1 );
        $has_more    = count( $attachments ) > $limit;

        if ( $has_more ) {
            array_pop( $attachments );
        }

        foreach ( $attachments as $attachment_id ) {
            $outcome = $this->delete_disabled_for_attachment( (int) $attachment_id );

            if ( null === $outcome ) {
                $result['failed_ids'][] = (int) $attachment_id;
                continue;
            }

            if ( ! empty( $outcome['content_failed'] ) ) {
                $result['failed_ids'][]   = (int) $attachment_id;
                $result['content_failed'] = true;
                $result['replaced_links'] += (int) $outcome['replaced'];
                continue;
            }

            $result['deleted_files']  += $outcome['deleted'];
            $result['replaced_links'] += $outcome['replaced'];

            if ( $outcome['deleted'] > 0 ) {
                $result['affected_attachments']++;
            }
        }

        $result['processed']  = count( $attachments );
        $result['next_after'] = empty( $attachments ) ? $after_id : (int) max( array_map( 'absint', $attachments ) );
        $result['has_more']   = $has_more;

        return $result;
    }

    /**
     * 删除单个附件中未勾选尺寸的缩略图
     *
     * 先更新正文链接，成功后再改元数据和删文件；正文更新失败时不做任何清理。
     *
     * @param int $attachment_id 附件 ID
     * @return array|null 计划结果；锁被占用或元数据写入失败时返回 null
     */
    private function delete_disabled_for_attachment( int $attachment_id ): ?array {
        $lock = libre_compress()->processor->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return null;
        }

        $global_lock = libre_compress()->processor->acquire_global_lock( false );
        if ( false === $global_lock ) {
            libre_compress()->processor->release_attachment_lock( $lock );
            return null;
        }

        try {
            // 先按当前缩放设置清理 -scaled 缩放图（与尺寸勾选无关）
            $scaled = $this->cleanup_scaled( $attachment_id );

            if ( null === $scaled ) {
                return null;
            }

            $deleted_base  = (int) $scaled['deleted'];
            $replaced_base = (int) $scaled['replaced'];

            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( empty( $metadata ) || empty( $metadata['file'] ) || empty( $metadata['sizes'] ) ) {
                return array(
                    'deleted'  => $deleted_base,
                    'replaced' => $replaced_base,
                );
            }

            $upload   = wp_upload_dir();
            $base_dir = untrailingslashit( $upload['basedir'] );
            $file_dir = dirname( $metadata['file'] );

            $victims = array();
            $kept    = array();

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $path = $base_dir . '/' . $file_dir . '/' . $size_data['file'];

                if ( ! file_exists( $path ) ) {
                    continue;
                }

                $entry = array(
                    'file'   => $path,
                    'width'  => isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
                    'height' => isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
                );

                if ( self::is_size_enabled( $size_name ) ) {
                    $kept[ $size_name ] = $entry;
                } else {
                    $victims[ $size_name ] = $entry;
                }
            }

            if ( empty( $victims ) ) {
                return array(
                    'deleted'  => $deleted_base,
                    'replaced' => $replaced_base,
                );
            }

            // 原图永远在，作为最后兜底的替代尺寸
            $full_path = $base_dir . '/' . $metadata['file'];

            if ( file_exists( $full_path ) ) {
                $kept['full'] = array(
                    'file'   => $full_path,
                    'width'  => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
                    'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
                );
            }

            $targets = array();

            foreach ( $victims as $size_name => $size_data ) {
                $replacement = $this->nearest_replacement( $size_data, $kept );

                if ( null === $replacement ) {
                    continue;
                }

                $targets[ $size_name ] = $replacement['file'];
            }

            if ( empty( $targets ) ) {
                return array(
                    'deleted'  => $deleted_base,
                    'replaced' => $replaced_base,
                );
            }

            $replacements = array();
            foreach ( $targets as $size_name => $target_path ) {
                $replacements[] = array(
                    'from' => $victims[ $size_name ]['file'],
                    'to'   => $target_path,
                );
            }

            $replaced = 0;

            if ( ! libre_compress()->output_processor->update_content_references( $replacements, $replaced ) ) {
                return array(
                    'deleted'        => $deleted_base,
                    'replaced'       => $replaced_base + $replaced,
                    'content_failed' => true,
                );
            }

            foreach ( array_keys( $targets ) as $size_name ) {
                unset( $metadata['sizes'][ $size_name ] );
            }

            if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
                return null;
            }

            $deleted = 0;

            foreach ( $targets as $size_name => $target_path ) {
                $path = $victims[ $size_name ]['file'];

                if ( ! $this->is_safe_upload_path( $path ) ) {
                    continue;
                }

                if ( file_exists( $path ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $path );
                }

                if ( file_exists( $path ) ) {
                    continue;
                }

                $deleted++;
                $database = libre_compress()->database;
                $record   = $database->get_record( $attachment_id, $size_name );

                if ( $record ) {
                    $database->delete_record( $record['id'] );
                }
            }

            return array(
                'deleted'  => $deleted_base + $deleted,
                'replaced' => $replaced_base + $replaced,
            );
        } finally {
            libre_compress()->processor->release_attachment_lock( $global_lock );
            libre_compress()->processor->release_attachment_lock( $lock );
        }
    }

    /**
     * 替代尺寸允许的最大像素面积倍数
     *
     * 删缩略图是为了省空间，把正文链接改写成完整原图会让页面体积反而暴涨，
     * 因此面积差超过这个倍数时放弃替换，宁可让浏览器加载不到该尺寸。
     */
    const REPLACEMENT_AREA_RATIO = 4;

    /**
     * 为被删尺寸挑一个像素面积最接近的替代尺寸，平手时选更大的
     *
     * 删除按钮与恢复原图都要把正文链接改到仍然存在的尺寸上，共用同一套挑选规则。
     *
     * @param array $victim     被删尺寸的文件与宽高
     * @param array $candidates 仍然存在的候选尺寸
     * @return array|null 面积差过大时返回 null
     */
    public function nearest_replacement( array $victim, array $candidates ) {
        $victim_area = max( 1, $victim['width'] * $victim['height'] );
        $best        = null;
        $best_score  = null;

        foreach ( $candidates as $candidate ) {
            $area  = max( 1, $candidate['width'] * $candidate['height'] );
            $score = abs( $area - $victim_area );

            if ( null === $best_score || $score < $best_score || ( $score === $best_score && $area > $best['area'] ) ) {
                $best       = array(
                    'file' => $candidate['file'],
                    'area' => $area,
                );
                $best_score = $score;
            }
        }

        // 最接近的替代尺寸仍然大出太多倍，说明没有真正合适的替代：宁可留空也不换成原图。
        if ( null !== $best && $best['area'] > $victim_area * self::REPLACEMENT_AREA_RATIO ) {
            return null;
        }

        return $best;
    }

    /**
     * 按附件 ID 游标为一页附件补齐缺失尺寸
     *
     * 前端按 next_after 游标循环调用，每请求只处理一页，
     * 避免大媒体库下单次 PHP 请求超时。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本页数量
     * @return array generated_attachments、generated_files、skipped_attachments、failed_ids、processed、next_after、has_more
     */
    public function generate_missing_page( int $after_id = 0, int $limit = 20 ): array {
        $result = array(
            'generated_attachments' => 0,
            'generated_files'       => 0,
            'skipped_attachments'   => 0,
            'failed_ids'            => array(),
            'processed'             => 0,
            'next_after'            => $after_id,
            'has_more'              => false,
        );

        $enabled    = self::enabled_sizes();
        $registered = wp_get_registered_image_subsizes();

        // 与删除按钮同理：没有勾选任何尺寸时也要进来处理 -scaled 缩放图，
        // 缩放图由独立的勾选框负责，不该被“没有要补的尺寸”挡在门外。
        $limit       = max( 1, min( 100, $limit ) );
        $attachments = libre_compress()->database->get_image_attachment_ids_after( $after_id, $limit + 1 );
        $has_more    = count( $attachments ) > $limit;

        if ( $has_more ) {
            array_pop( $attachments );
        }

        foreach ( $attachments as $attachment_id ) {
            $outcome = $this->generate_missing_for_attachment( (int) $attachment_id, $enabled, $registered );

            if ( null === $outcome ) {
                $result['failed_ids'][] = (int) $attachment_id;
            } elseif ( is_int( $outcome ) ) {
                $result['generated_attachments']++;
                $result['generated_files'] += $outcome;
            } else {
                $result['skipped_attachments']++;
            }
        }

        $result['processed']  = count( $attachments );
        $result['next_after'] = empty( $attachments ) ? $after_id : (int) max( array_map( 'absint', $attachments ) );
        $result['has_more']   = $has_more;

        return $result;
    }

    /**
     * 给单个附件补齐缺失尺寸
     *
     * 只生成缺失的尺寸：核心生成完会整体覆盖元数据，所以事后把原有尺寸并回去，
     * 已存在的缩略图文件不会被重建。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $enabled       已勾选的尺寸名
     * @param array $registered    注册尺寸配置
     * @return int|string|null 新增文件数；无需处理时返回原因字符串；失败返回 null
     */
    private function generate_missing_for_attachment( int $attachment_id, array $enabled, array $registered ) {
        $lock = libre_compress()->processor->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return null;
        }

        $global_lock = libre_compress()->processor->acquire_global_lock( false );
        if ( false === $global_lock ) {
            libre_compress()->processor->release_attachment_lock( $lock );
            return null;
        }

        try {
            $file = get_attached_file( $attachment_id );

            if ( ! $file || ! file_exists( $file ) ) {
                return null;
            }

            // APNG 生成缩略图只会取第一帧，与“APNG 不处理”保持一致
            if ( Libre_Compress_Compressor::is_apng( $file ) ) {
                return 'apng';
            }

            // 先按当前缩放设置补建/修正 -scaled 缩放图（与缩略图缺失无关）
            $scaled = $this->ensure_scaled( $attachment_id );

            if ( null === $scaled ) {
                return null;
            }

            $scaled_count = isset( $scaled['generated'] ) ? (int) $scaled['generated'] : 0;

            // ensure_scaled 可能改写了主文件，重新取当前主文件用于后续生成
            $file = get_attached_file( $attachment_id );

            if ( ! $file || ! file_exists( $file ) ) {
                return null;
            }

            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
                return 'no_metadata';
            }

            $upload   = wp_upload_dir();
            $base_dir = untrailingslashit( $upload['basedir'] );
            $file_dir = dirname( $metadata['file'] );
            $width    = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
            $height   = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

            $missing = array();

            foreach ( $enabled as $size_name ) {
                if ( ! isset( $registered[ $size_name ] ) ) {
                    continue;
                }

                if ( ! empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
                    $existing = $base_dir . '/' . $file_dir . '/' . $metadata['sizes'][ $size_name ]['file'];

                    if ( file_exists( $existing ) ) {
                        continue;
                    }
                }

                if ( ! $this->size_applies_to_image( $registered[ $size_name ], $width, $height ) ) {
                    continue;
                }

                $missing[] = $size_name;
            }

            if ( empty( $missing ) ) {
                return 0 === $scaled_count ? 'nothing' : $scaled_count;
            }

            $filter = function ( $sizes ) use ( $missing ) {
                return array_intersect_key( is_array( $sizes ) ? $sizes : array(), array_flip( $missing ) );
            };

            add_filter( 'intermediate_image_sizes_advanced', $filter, 20 );

            $processor = libre_compress()->processor;
            $was       = $processor->is_auto_compress_suppressed();
            $processor->set_auto_compress_suppressed( true );

            try {
                $fresh = wp_generate_attachment_metadata( $attachment_id, $file );
            } finally {
                $processor->set_auto_compress_suppressed( $was );
                remove_filter( 'intermediate_image_sizes_advanced', $filter, 20 );
            }

            if ( empty( $fresh['sizes'] ) || ! is_array( $fresh['sizes'] ) ) {
                return 0 === $scaled_count ? 'nothing' : $scaled_count;
            }

            $merged          = $metadata;
            $merged['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
            $generated       = 0;

            foreach ( $fresh['sizes'] as $size_name => $size_data ) {
                if ( ! in_array( $size_name, $missing, true ) ) {
                    continue;
                }

                $merged['sizes'][ $size_name ] = $size_data;
                $generated++;
            }

            if ( 0 === $generated ) {
                return 0 === $scaled_count ? 'nothing' : $scaled_count;
            }

            if ( ! $this->write_metadata( $attachment_id, $merged ) ) {
                // 核心已把元数据覆盖成只含新尺寸，写回失败时先还原，别让已有尺寸的记录丢失
                $this->write_metadata( $attachment_id, $metadata );

                return null;
            }

            return $generated + $scaled_count;
        } finally {
            libre_compress()->processor->release_attachment_lock( $global_lock );
            libre_compress()->processor->release_attachment_lock( $lock );
        }
    }

    /**
     * 判断某个尺寸对这张图是否适用
     *
     * 等比缩放是按最长边缩的：只要原图在任一维度大于目标就会产出（2000x1500 对
     * 1536x1536 会得到 1536x1152）。核心不产出的尺寸不能算“缺失”，否则每次点击都会
     * 白跑一遍，永远补不齐。
     *
     * @param array $config 尺寸配置
     * @param int   $width  原图宽
     * @param int   $height 原图高
     * @return bool
     */
    private function size_applies_to_image( array $config, int $width, int $height ): bool {
        $target_width  = isset( $config['width'] ) ? (int) $config['width'] : 0;
        $target_height = isset( $config['height'] ) ? (int) $config['height'] : 0;

        if ( 0 === $target_width && 0 === $target_height ) {
            return false;
        }

        if ( ! empty( $config['crop'] ) ) {
            return true;
        }

        if ( $width <= 0 || $height <= 0 ) {
            return false;
        }

        return ( $target_width > 0 && $width > $target_width ) || ( $target_height > 0 && $height > $target_height );
    }

    /**
     * 规范化路径用于大小写不敏感的比较
     *
     * Windows 文件系统不区分大小写，比较缩放图与原图是否为同一文件时必须忽略大小写。
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );

        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 计算附件的 -scaled 缩放图上下文
     *
     * @param array $metadata 附件元数据
     * @return array threshold、enabled、main_rel、orig_rel、source_rel、is_scaled
     */
    private function scaled_context( array $metadata ): array {
        $threshold = Libre_Compress_Settings::image_size_threshold();
        $enabled   = Libre_Compress_Settings::image_scale_enabled();

        $main_rel  = isset( $metadata['file'] ) ? ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' ) : '';
        $orig_name = isset( $metadata['original_image'] ) ? (string) $metadata['original_image'] : '';
        $is_scaled = '' !== $orig_name && '' !== $main_rel;

        // original_image 存的是文件名，与主文件同目录，拼成完整相对路径
        $file_dir   = dirname( $main_rel );
        $orig_rel   = $is_scaled ? ( ( '.' === $file_dir ? '' : $file_dir . '/' ) . $orig_name ) : '';
        $source_rel = $is_scaled ? $orig_rel : $main_rel;

        return array(
            'threshold'  => $threshold,
            'enabled'    => $enabled,
            'main_rel'   => $main_rel,
            'orig_rel'   => $orig_rel,
            'source_rel' => $source_rel,
            'is_scaled'  => $is_scaled,
        );
    }

    /**
     * 删除主文件相关的压缩记录
     *
     * 缩放图的生成与去缩放都改变了主文件结构，full 与 original_image 两条记录
     * 描述的是旧文件，删除后让主文件回到待压缩状态，用户可重新压缩。
     *
     * @param int $attachment_id 附件 ID
     */
    private function forget_main_records( int $attachment_id ): void {
        $database = libre_compress()->database;

        foreach ( array( 'full', 'original_image' ) as $size_type ) {
            $record = $database->get_record( $attachment_id, $size_type );

            if ( $record ) {
                $database->delete_record( (int) $record['id'] );
            }
        }
    }

    /**
     * 删除按钮：按当前缩放设置清理 -scaled 缩放图
     *
     * 关闭了自动缩放，或现有缩放图长边超过阈值（阈值被调小）时，删掉缩放图、
     * 让主文件退回未缩放的原图，并同步元数据、正文链接和压缩记录。
     * 缩放图长边不超阈值（符合当前设置）时不动。调用前须已持有附件锁。
     *
     * @param int $attachment_id 附件 ID
     * @return array|null deleted、replaced；处理失败返回 null
     */
    private function cleanup_scaled( int $attachment_id ): ?array {
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        $context = $this->scaled_context( $metadata );

        if ( ! $context['is_scaled'] ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        $upload   = wp_upload_dir();
        $base_dir = untrailingslashit( wp_normalize_path( $upload['basedir'] ) );
        $main_abs = $base_dir . '/' . ltrim( wp_normalize_path( $context['main_rel'] ), '/' );
        $orig_abs = $base_dir . '/' . ltrim( wp_normalize_path( $context['orig_rel'] ), '/' );

        // 原图必须还在，否则无法退回
        if ( ! is_file( $orig_abs ) ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        // 关闭缩放，或缩放图长边超过阈值，才需要清理
        $need_cleanup = ! $context['enabled'];

        if ( ! $need_cleanup ) {
            if ( ! is_file( $main_abs ) ) {
                // 缩放图文件已不在，元数据还挂着 original_image，属悬空条目，清理元数据即可
                $need_cleanup = true;
            } else {
                $main_size = wp_getimagesize( $main_abs );

                if ( is_array( $main_size ) && ! empty( $main_size[0] ) && ! empty( $main_size[1] )
                    && max( (int) $main_size[0], (int) $main_size[1] ) > $context['threshold'] ) {
                    $need_cleanup = true;
                }
            }
        }

        if ( ! $need_cleanup ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        $deleted  = 0;
        $replaced = 0;

        if ( is_file( $main_abs ) ) {
            // 绝不删到原图头上，也不删 uploads 外的路径
            if ( ! $this->is_safe_upload_path( $main_abs )
                || $this->normalized_path( $main_abs ) === $this->normalized_path( $orig_abs ) ) {
                return null;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $main_abs ) || file_exists( $main_abs ) ) {
                return null;
            }

            $deleted = 1;

            // 正文里固化的缩放图地址改回原图
            libre_compress()->output_processor->update_content_references(
                array(
                    array(
                        'from' => $main_abs,
                        'to'   => $orig_abs,
                    ),
                ),
                $replaced
            );
        }

        $metadata['file'] = $context['orig_rel'];
        unset( $metadata['original_image'] );

        $orig_size = wp_getimagesize( $orig_abs );

        if ( is_array( $orig_size ) && ! empty( $orig_size[0] ) && ! empty( $orig_size[1] ) ) {
            $metadata['width']  = (int) $orig_size[0];
            $metadata['height'] = (int) $orig_size[1];
        }

        clearstatcache( true, $orig_abs );
        $metadata['filesize'] = (int) filesize( $orig_abs );

        if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
            return null;
        }

        update_attached_file( $attachment_id, $context['orig_rel'] );

        $this->forget_main_records( $attachment_id );

        return array( 'deleted' => $deleted, 'replaced' => $replaced );
    }

    /**
     * 补生成按钮：按当前缩放设置补建或修正 -scaled 缩放图
     *
     * 开启自动缩放且未缩放原图长边超过阈值时，若无缩放图或缩放图长边超过阈值，
     * 就从原图重新生成 -scaled 缩放图并接管主文件，同步元数据、正文链接和压缩记录。
     * 调用前须已持有附件锁。
     *
     * @param int $attachment_id 附件 ID
     * @return array|null generated、replaced；处理失败返回 null
     */
    private function ensure_scaled( int $attachment_id ): ?array {
        if ( ! Libre_Compress_Settings::image_scale_enabled() ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        $context    = $this->scaled_context( $metadata );
        $upload     = wp_upload_dir();
        $base_dir   = untrailingslashit( wp_normalize_path( $upload['basedir'] ) );
        $source_abs = $base_dir . '/' . ltrim( wp_normalize_path( $context['source_rel'] ), '/' );
        $main_abs   = $base_dir . '/' . ltrim( wp_normalize_path( $context['main_rel'] ), '/' );

        if ( ! is_file( $source_abs ) || ! libre_compress()->processor->is_scalable_image( $source_abs ) ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        $source_size = wp_getimagesize( $source_abs );

        if ( ! is_array( $source_size ) || empty( $source_size[0] ) || empty( $source_size[1] ) ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        // 原图没超阈值，不需要缩放图
        if ( max( (int) $source_size[0], (int) $source_size[1] ) <= $context['threshold'] ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        // 已有缩放图且长边不超阈值（符合当前设置），不用重建；超过则先删掉旧的
        if ( $context['is_scaled'] && is_file( $main_abs ) ) {
            $main_size = wp_getimagesize( $main_abs );

            if ( is_array( $main_size ) && ! empty( $main_size[0] ) && ! empty( $main_size[1] )
                && max( (int) $main_size[0], (int) $main_size[1] ) <= $context['threshold'] ) {
                return array( 'generated' => 0, 'replaced' => 0 );
            }

            if ( $this->is_safe_upload_path( $main_abs )
                && $this->normalized_path( $main_abs ) !== $this->normalized_path( $source_abs ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( ! unlink( $main_abs ) || file_exists( $main_abs ) ) {
                    return null;
                }
            }
        }

        $scaled = libre_compress()->processor->generate_scaled_file( $source_abs, $context['threshold'] );

        if ( ! is_array( $scaled ) || empty( $scaled['path'] ) || ! is_file( $scaled['path'] ) ) {
            return null;
        }

        $scaled_abs = wp_normalize_path( (string) $scaled['path'] );
        $scaled_rel = ltrim( substr( $scaled_abs, strlen( $base_dir ) ), '/' );

        $replaced = 0;

        // 主文件名变化时，正文里固化的旧地址改到新缩放图
        if ( $this->normalized_path( $scaled_rel ) !== $this->normalized_path( $context['main_rel'] ) ) {
            libre_compress()->output_processor->update_content_references(
                array(
                    array(
                        'from' => $main_abs,
                        'to'   => $scaled_abs,
                    ),
                ),
                $replaced
            );
        }

        $metadata['file']           = $scaled_rel;
        $metadata['original_image'] = wp_basename( $source_abs );
        $metadata['width']          = isset( $scaled['width'] ) ? (int) $scaled['width'] : (int) $source_size[0];
        $metadata['height']         = isset( $scaled['height'] ) ? (int) $scaled['height'] : (int) $source_size[1];

        clearstatcache( true, $scaled_abs );
        $metadata['filesize'] = isset( $scaled['filesize'] ) ? (int) $scaled['filesize'] : (int) filesize( $scaled_abs );

        if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
            return null;
        }

        update_attached_file( $attachment_id, $scaled_rel );

        $this->forget_main_records( $attachment_id );

        return array( 'generated' => 1, 'replaced' => $replaced );
    }

    /**
     * 写入元数据并读回校验
     *
     * 只比较尺寸集合不比较顺序：WordPress 按键名取用缩略图，顺序没有语义，
     * 其他插件重排顺序不再误判；真正增删尺寸仍对不上，失败保护还在。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $metadata      待写入的元数据
     * @return bool
     */
    private function write_metadata( int $attachment_id, array $metadata ): bool {
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $stored  = wp_get_attachment_metadata( $attachment_id );
        $current = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : array();

        if ( ! is_array( $stored ) ) {
            return false;
        }

        $stored_sizes = isset( $stored['sizes'] ) && is_array( $stored['sizes'] ) ? array_keys( $stored['sizes'] ) : array();

        sort( $stored_sizes );
        sort( $current );

        return $stored_sizes === $current;
    }

    /**
     * 验证文件位于 uploads 目录内
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    private function is_safe_upload_path( string $file_path ): bool {
        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );

        if ( false === $base_dir ) {
            return false;
        }

        $real_path = realpath( $file_path );

        if ( false !== $real_path && is_link( $real_path ) ) {
            return false;
        }

        // 文件已不存在时按规范化后的字面路径判断，保证删除动作只落在 uploads 内
        $candidate = false === $real_path ? $file_path : $real_path;
        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $candidate = untrailingslashit( wp_normalize_path( $candidate ) );

        return 0 === strpos( $candidate, $base_dir . '/' );
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
        $after_id    = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;

        switch ( $action_type ) {
            case 'delete':
                $page = $this->delete_disabled_page( $after_id, self::PAGE_SIZE );

                wp_send_json_success(
                    array(
                        'deleted_files'  => $page['deleted_files'],
                        'affected_count' => $page['affected_attachments'],
                        'replaced_links' => $page['replaced_links'],
                        'failed_ids'     => $page['failed_ids'],
                        'content_failed' => $page['content_failed'],
                        'processed'      => $page['processed'],
                        'next_after'     => $page['next_after'],
                        'has_more'       => $page['has_more'],
                    )
                );
                break;

            case 'generate':
                $page = $this->generate_missing_page( $after_id, self::PAGE_SIZE );

                wp_send_json_success(
                    array(
                        'generated_attachments' => $page['generated_attachments'],
                        'generated_files'       => $page['generated_files'],
                        'skipped_attachments'   => $page['skipped_attachments'],
                        'failed_ids'            => $page['failed_ids'],
                        'processed'             => $page['processed'],
                        'next_after'            => $page['next_after'],
                        'has_more'              => $page['has_more'],
                    )
                );
                break;

            default:
                wp_send_json_error( array( 'message' => __( '无效的操作类型', 'libre-compress' ) ) );
        }
    }
}
