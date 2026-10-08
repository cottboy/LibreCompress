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
    public function __construct() {
        add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 2 );
        // 排在 WordPress 补充尺寸和加载属性之后，避免新格式 srcset 被补到回退 img 上。
        add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
        add_filter( 'the_excerpt', array( $this, 'filter_content' ), 20 );
        add_filter( 'widget_text_content', array( $this, 'filter_content' ), 20 );
        add_filter( 'widget_block_content', array( $this, 'filter_content' ), 20 );
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
            if ( ! is_string( $src ) || '' === $this->relative_url_path( $src ) ) {
                continue;
            }
            if ( ! $id ) {
                $uploads = wp_upload_dir();
                $id = attachment_url_to_postid( trailingslashit( $uploads['baseurl'] ) . $this->relative_url_path( $src ) );
            }
            $replacement = $this->wrap_image( $image, $id );
            if ( $replacement !== $image ) {
                $replacements[] = array( $start, $length, $replacement );
            }
        }

        // 从后往前替换，前面标签的偏移不会因新增内容而改变。
        foreach ( array_reverse( $replacements ) as $replacement ) {
            $html = substr_replace( $html, $replacement[2], $replacement[0], $replacement[1] );
        }
        return $html;
    }

    private function wrap_image( string $html, int $attachment_id ): string {
        if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
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

        $entries = libre_compress()->output_processor->get_output_entries( $attachment_id );
        // 一张图的响应式候选共用一份索引，避免逐个候选重复查询数据库。
        $backups = libre_compress()->backup->get_backups( $attachment_id );
        $fallback = $this->fallback_url( $attachment_id, $relative, $entries, $backups );
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
                $url = $this->fallback_url( $attachment_id, $this->relative_url_path( $matches[1] ), $entries, $backups );
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

    private function fallback_url( int $attachment_id, string $relative, array $entries, array $backups ): string {
        if ( '' === $relative ) {
            return '';
        }
        foreach ( $entries as $entry ) {
            if ( $entry['to_relative'] === $relative ) {
                return libre_compress()->backup->get_original_url( $attachment_id, $entry['from'], $backups );
            }
        }
        return '';
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
