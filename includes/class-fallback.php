<?php
/**
 * 浏览器原图回退。
 *
 * @package LibreCompress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 暴露解析器当前标签范围，插入 picture 时保留其余 HTML 的原始字节。
 */
class Libre_Compress_Image_Tag_Processor extends WP_HTML_Tag_Processor {
    public function current_span(): array {
        $this->set_bookmark( 'libre-compress-image' );
        $span = $this->bookmarks['libre-compress-image'];
        $this->release_bookmark( 'libre-compress-image' );
        return array( $span->start, $span->length );
    }
}

class Libre_Compress_Fallback {
    const CACHE_PREFIX = 'libre_compress_fallback_';
    const GENERATION_OPTION = 'libre_compress_fallback_generation';
    const CACHE_TTL = 7 * DAY_IN_SECONDS;

    private static $request_paths = array();
    private static $request_ids = array();

    public function __construct() {
        add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 2 );
        // 排在 WordPress 补充尺寸和加载属性之后，避免新格式 srcset 被补到回退 img 上。
        add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
        add_filter( 'the_excerpt', array( $this, 'filter_content' ), 20 );
        add_filter( 'widget_text_content', array( $this, 'filter_content' ), 20 );
        add_filter( 'widget_block_content', array( $this, 'filter_content' ), 20 );
        foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'metadata_changed' ), 10, 4 );
        }
        add_action( 'delete_attachment', array( __CLASS__, 'invalidate_attachment' ) );
        add_action( 'libre_compress_after_compress', array( __CLASS__, 'invalidate_attachment' ) );
        foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'option_changed' ) );
        }
    }

    private static function context(): string {
        $generation = get_option( self::GENERATION_OPTION, '' );
        if ( ! is_string( $generation ) || '' === $generation ) {
            update_option( self::GENERATION_OPTION, wp_generate_uuid4(), true );
            $generation = get_option( self::GENERATION_OPTION );
        }
        $generation = is_string( $generation ) ? $generation : '';
        $uploads = wp_upload_dir();
        return md5( get_current_blog_id() . '|' . (string) $generation . '|' . $uploads['basedir'] . '|' . $uploads['baseurl'] );
    }

    public static function cache_key( int $attachment_id ): string {
        return self::CACHE_PREFIX . self::context() . '_' . $attachment_id;
    }

    public static function invalidate_attachment( int $attachment_id ): void {
        if ( $attachment_id > 0 ) {
            delete_transient( self::cache_key( $attachment_id ) );
            foreach ( self::$request_paths as &$paths ) {
                unset( $paths[ $attachment_id ] );
            }
            unset( $paths );
            self::$request_ids = array();
        }
    }

    public static function metadata_changed( $meta_id, $attachment_id, $meta_key, $value ): void {
        if ( in_array( $meta_key, array( Libre_Compress_Output::OUTPUT_META_KEY, '_wp_attachment_metadata', '_wp_attached_file' ), true ) ) {
            self::invalidate_attachment( (int) $attachment_id );
        }
    }

    public static function option_changed( string $option ): void {
        if ( in_array( $option, array( 'libre_compress_general', 'libre_compress_tools', 'home', 'siteurl', 'upload_path', 'upload_url_path' ), true ) ) {
            self::invalidate_all();
        }
    }

    public static function invalidate_all(): void {
        global $wpdb;

        // 更换命名空间也能使外部对象缓存及并发请求持有的旧结果立即失效。
        update_option( self::GENERATION_OPTION, wp_generate_uuid4(), true );
        self::$request_paths = self::$request_ids = array();
        $where = $wpdb->prepare(
            'option_name LIKE %s OR option_name LIKE %s',
            $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
            $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
        );
        $names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE {$where}" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE {$where}" );
        if ( $names ) {
            wp_cache_delete_multiple( $names, 'options' );
        }
    }

    /**
     * 缓存也是不可信数据，命中时仍校验路径结构，但不重复读取图片文件。
     */
    private function valid_paths( $paths ): bool {
        if ( ! is_array( $paths ) ) {
            return false;
        }
        foreach ( $paths as $modern => $original ) {
            foreach ( array( $modern, $original ) as $path ) {
                if ( ! is_string( $path ) || '' === $path || '/' === $path[0]
                    || false !== strpos( $path, '..' ) || false !== strpbrk( $path, "\0\\:" ) ) {
                    return false;
                }
            }
            if ( ! in_array( strtolower( pathinfo( $modern, PATHINFO_EXTENSION ) ), array( 'webp', 'avif' ), true )
                || 0 !== strpos( $original, Libre_Compress_Backup::BACKUP_DIR_NAME . '/' )
                || ! in_array( strtolower( pathinfo( $original, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'png', 'gif', 'svg' ), true ) ) {
                return false;
            }
        }
        return true;
    }

    private function prepare_paths( array $ids, string $context ): void {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( $ids ) ) );
        $keys = array();
        foreach ( $ids as $id ) {
            if ( ! isset( self::$request_paths[ $context ][ $id ] ) ) {
                $keys[ $id ] = self::CACHE_PREFIX . $context . '_' . $id;
            }
        }
        if ( ! $keys ) {
            return;
        }
        if ( ! wp_using_ext_object_cache() ) {
            $options = array();
            foreach ( $keys as $key ) {
                $options[] = '_transient_' . $key;
                $options[] = '_transient_timeout_' . $key;
            }
            wp_prime_option_caches( $options );
        }

        $locks = array();
        $global_lock = false;
        $processor = libre_compress()->processor;
        try {
            foreach ( $keys as $id => $key ) {
                $cached = get_transient( $key );
                if ( $this->valid_paths( $cached ) ) {
                    self::$request_paths[ $context ][ $id ] = $cached;
                    continue;
                }
                // 未取得锁时暂时跳过，不把压缩或恢复中的中间状态持久化。
                $lock = $processor->acquire_attachment_lock( $id );
                if ( false !== $lock ) {
                    $locks[ $id ] = $lock;
                }
            }
            if ( ! $locks ) {
                return;
            }
            $global_lock = $processor->acquire_global_lock( false );
            if ( false === $global_lock ) {
                return;
            }
            $query_count = $wpdb->num_queries;
            _prime_post_caches( array_keys( $locks ), false, false );
            if ( $wpdb->num_queries > $query_count && '' !== $wpdb->last_error ) {
                return;
            }
            $query_count = $wpdb->num_queries;
            update_meta_cache( 'post', array_keys( $locks ) );
            if ( $wpdb->num_queries > $query_count && '' !== $wpdb->last_error ) {
                // WordPress 在读取失败时也会填入空元数据缓存，清除后允许下次重试。
                wp_cache_delete_multiple( array_keys( $locks ), 'post_meta' );
                return;
            }
            $entries = array();
            foreach ( $locks as $id => $lock ) {
                $query_count = $wpdb->num_queries;
                $entries[ $id ] = 'attachment' === get_post_type( $id ) ? libre_compress()->output_processor->get_output_entries( $id ) : array();
                if ( $wpdb->num_queries > $query_count && '' !== $wpdb->last_error ) {
                    return;
                }
            }
            $backups = libre_compress()->backup->get_backups_batch( array_keys( array_filter( $entries ) ) );
            if ( null === $backups ) {
                return;
            }
            foreach ( $entries as $id => $rows ) {
                $paths = array();
                $originals = array();
                foreach ( $rows as $entry ) {
                    if ( ! in_array( strtolower( pathinfo( $entry['to_relative'], PATHINFO_EXTENSION ) ), array( 'webp', 'avif' ), true ) ) {
                        continue;
                    }
                    if ( ! array_key_exists( $entry['from'], $originals ) ) {
                        $url = libre_compress()->backup->get_original_url( $id, $entry['from'], $backups[ $id ] ?? array() );
                        $originals[ $entry['from'] ] = '' !== $url ? $this->relative_url_path( $url ) : '';
                    }
                    if ( '' !== $originals[ $entry['from'] ] ) {
                        $paths[ $entry['to_relative'] ] = $originals[ $entry['from'] ];
                    }
                }
                // 设置变化时不允许在新命名空间中发布旧请求计算的结果。
                if ( $context === self::context() && $this->valid_paths( $paths ) ) {
                    set_transient( $keys[ $id ], $paths, self::CACHE_TTL );
                    self::$request_paths[ $context ][ $id ] = $paths;
                }
            }
        } finally {
            $processor->release_attachment_lock( $global_lock );
            foreach ( $locks as $lock ) {
                $processor->release_attachment_lock( $lock );
            }
        }
    }

    private function enabled(): bool {
        $settings = get_option( 'libre_compress_general', array() );
        return ! is_admin() && ! empty( $settings['original_fallback'] )
            && in_array( $settings['backup_enabled'] ?? true, array( true, 1, '1' ), true );
    }

    public function filter_attachment_image( string $html, int $attachment_id ): string {
        return $this->enabled() ? $this->replace_images( $html, $attachment_id ) : $html;
    }

    public function filter_content( string $html ): string {
        return $this->enabled() ? $this->replace_images( $html, 0 ) : $html;
    }

    private function replace_images( string $html, int $attachment_id ): string {
        $parser       = new Libre_Compress_Image_Tag_Processor( $html );
        $replacements = array();
        $images       = array();
        $context      = self::context();
        $picture_depth = 0;

        while ( $parser->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
            if ( 'PICTURE' === $parser->get_tag() ) {
                $picture_depth = max( 0, $picture_depth + ( $parser->is_tag_closer() ? -1 : 1 ) );
                continue;
            }
            if ( $picture_depth || 'IMG' !== $parser->get_tag() || $parser->is_tag_closer() ) {
                continue;
            }

            list( $start, $length ) = $parser->current_span();
            $image = substr( $html, $start, $length );
            $id    = $attachment_id;
            if ( ! $id && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', (string) $parser->get_attribute( 'class' ), $matches ) ) {
                $id = (int) $matches[1];
            }
            $src = $parser->get_attribute( 'src' );
            if ( ! is_string( $src ) ) {
                continue;
            }
            $relative = $this->relative_url_path( $src );
            if ( ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), array( 'webp', 'avif' ), true ) ) {
                continue;
            }
            if ( ! $id ) {
                $uploads = wp_upload_dir();
                if ( ! isset( self::$request_ids[ $context ][ $relative ] ) ) {
                    self::$request_ids[ $context ][ $relative ] = attachment_url_to_postid( trailingslashit( $uploads['baseurl'] ) . $relative );
                }
                $id = self::$request_ids[ $context ][ $relative ];
            }
            if ( $id > 0 ) {
                $images[] = array( 'start' => $start, 'length' => $length, 'html' => $image, 'id' => $id );
            }
        }

        foreach ( array_chunk( array_values( array_unique( array_column( $images, 'id' ) ) ), 200 ) as $ids ) {
            $this->prepare_paths( $ids, $context );
        }
        foreach ( $images as $item ) {
            $image = $item['html'];
            $replacement = $this->wrap_image( $image, self::$request_paths[ $context ][ $item['id'] ] ?? array() );
            if ( $replacement !== $image ) {
                $replacements[] = array( $item['start'], $item['length'], $replacement );
            }
        }

        // 从后往前替换，前面标签的偏移不会因新增内容而改变。
        foreach ( array_reverse( $replacements ) as $replacement ) {
            $html = substr_replace( $html, $replacement[2], $replacement[0], $replacement[1] );
        }
        return $html;
    }

    private function wrap_image( string $html, array $paths ): string {
        if ( ! $paths ) {
            return $html;
        }
        $parser = new WP_HTML_Tag_Processor( $html );
        if ( ! $parser->next_tag( 'IMG' ) ) {
            return $html;
        }

        $src = $parser->get_attribute( 'src' );
        $relative = is_string( $src ) ? $this->relative_url_path( $src ) : '';
        $format = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
        if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
            return $html;
        }

        $fallback = $this->fallback_url( $relative, $paths );
        if ( '' === $fallback ) {
            return $html;
        }

        $srcset = $parser->get_attribute( 'srcset' );
        $sizes  = $parser->get_attribute( 'sizes' );
        $source = '<source type="image/' . $format . '" srcset="' . esc_attr( is_string( $srcset ) && '' !== $srcset ? $srcset : $src ) . '"';
        if ( is_string( $sizes ) && '' !== $sizes ) {
            $source .= ' sizes="' . esc_attr( $sizes ) . '"';
        }

        $parser->set_attribute( 'src', $fallback );
        $parser->remove_attribute( 'srcset' );
        $parser->remove_attribute( 'sizes' );

        // 只把存在备份的候选加入原图 srcset；缺备份的尺寸不能指向新格式。
        $original_candidates = array();
        if ( is_string( $srcset ) ) {
            foreach ( explode( ',', $srcset ) as $candidate ) {
                if ( ! preg_match( '/^\s*(\S+)\s+(\d+w|\d+(?:\.\d+)?x)\s*$/D', $candidate, $matches ) ) {
                    continue;
                }
                $url = $this->fallback_url( $this->relative_url_path( $matches[1] ), $paths );
                if ( '' !== $url ) {
                    $original_candidates[] = $url . ' ' . $matches[2];
                }
            }
        }
        if ( $original_candidates ) {
            $parser->set_attribute( 'srcset', implode( ', ', $original_candidates ) );
            if ( is_string( $sizes ) && '' !== $sizes ) {
                $parser->set_attribute( 'sizes', $sizes );
            }
        }

        return '<picture>' . $source . '>' . $parser->get_updated_html() . '</picture>';
    }

    private function fallback_url( string $relative, array $paths ): string {
        if ( ! isset( $paths[ $relative ] ) ) {
            return '';
        }
        $uploads = wp_upload_dir();
        return trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $paths[ $relative ] ) ) );
    }

    /**
     * 仅接受当前上传目录的 URL，外站同路径、回溯和协议注入均不参与匹配。
     */
    private function relative_url_path( string $url ): string {
        $uploads = wp_upload_dir();
        $base = wp_parse_url( $uploads['baseurl'] );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $base ) || ! is_array( $parts ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
            return '';
        }
        if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return '';
        }
        if ( isset( $parts['host'] ) && ( strtolower( $parts['host'] ) !== strtolower( $base['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $base['port'] ?? null ) ) ) {
            return '';
        }
        $path = rawurldecode( $parts['path'] ?? '' );
        $prefix = trailingslashit( rawurldecode( $base['path'] ?? '' ) );
        if ( 0 !== strpos( $path, $prefix ) || strpbrk( $path, "\0\\:" ) !== false || false !== strpos( $path, '..' ) ) {
            return '';
        }
        return substr( $path, strlen( $prefix ) );
    }
}
