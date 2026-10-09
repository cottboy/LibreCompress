<?php
/**
 * 兼容格式回退
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 暴露解析器当前标签范围，插入 picture 时保留其余 HTML 的原始字节。
 */
class Libre_Compress_Image_Tag_Processor extends WP_HTML_Tag_Processor {

    /**
     * 当前标签在原文中的起始位置与长度
     *
     * @return array 位置为 -1 表示取不到当前标签范围
     */
    public function current_span(): array {
        if ( ! $this->set_bookmark( 'libre-compress-image' ) || ! isset( $this->bookmarks['libre-compress-image'] ) ) {
            return array( -1, 0 );
        }

        $span = $this->bookmarks['libre-compress-image'];
        $this->release_bookmark( 'libre-compress-image' );

        return array( (int) $span->start, (int) $span->length );
    }
}

/**
 * 兼容格式回退
 *
 * 格式转换后新格式文件以"源文件名.目标格式"的双扩展名生成（photo.jpg → photo.jpg.webp），
 * 源文件原地不动并同样经过压缩，作为回退文件。前台输出新格式图片时，把链接去掉最后一段
 * 扩展名就得到回退地址，是确定性推导而非探头猜测，不需要查询数据库。
 */
class Libre_Compress_Compatible_Fallback {

    /**
     * 构造函数
     */
    public function __construct() {
        add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 2 );
        // 排在 WordPress 补充尺寸和加载属性之后，避免新格式 srcset 被补到回退 img 上。
        add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
        add_filter( 'the_excerpt', array( $this, 'filter_content' ), 20 );
        add_filter( 'widget_text_content', array( $this, 'filter_content' ), 20 );
        add_filter( 'widget_block_content', array( $this, 'filter_content' ), 20 );
    }

    /**
     * 是否输出兼容格式回退
     *
     * 后台不参与：媒体库列表和编辑器预览没有必要包一层 picture。
     * 前台还要看设置里的开关，关掉后只输出新格式，不支持新格式的浏览器直接加载新格式图。
     *
     * @return bool
     */
    private function enabled(): bool {
        if ( is_admin() ) {
            return false;
        }

        $general = get_option( 'libre_compress_general', array() );

        return ! empty( $general['fallback_enabled'] );
    }

    /**
     * 过滤附件图片 HTML
     *
     * @param string $html          图片 HTML
     * @param int    $attachment_id 附件 ID
     * @return string
     */
    public function filter_attachment_image( $html, $attachment_id ) {
        // 不写死参数类型：链上前面的过滤器可能返回非字符串，类型声明会直接变成致命错误。
        return $this->enabled() && is_string( $html ) ? $this->replace_images( $html ) : $html;
    }

    /**
     * 过滤正文类内容
     *
     * @param string $html 内容 HTML
     * @return string
     */
    public function filter_content( $html ) {
        return $this->enabled() && is_string( $html ) ? $this->replace_images( $html ) : $html;
    }

    /**
     * 把内容里的新格式图片包上旧格式回退
     *
     * @param string $html 内容 HTML
     * @return string
     */
    private function replace_images( string $html ): string {
        // 没有新格式链接的正文直接返回，省掉整棵树的标签解析。
        if ( false === stripos( $html, '.webp' ) && false === stripos( $html, '.avif' ) ) {
            return $html;
        }

        $parser       = new Libre_Compress_Image_Tag_Processor( $html );
        $replacements = array();
        $images       = array();
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

            if ( $start < 0 || $length <= 0 ) {
                continue;
            }

            $image = substr( $html, $start, $length );
            $src   = $parser->get_attribute( 'src' );

            if ( ! is_string( $src ) || '' === $this->relative_url_path( $src ) ) {
                continue;
            }

            $images[] = array( 'start' => $start, 'length' => $length, 'html' => $image );
        }

        foreach ( $images as $item ) {
            $replacement = $this->wrap_image( $item['html'] );

            if ( null !== $replacement && $replacement !== $item['html'] ) {
                $replacements[] = array( $item['start'], $item['length'], $replacement );
            }
        }

        // 从后往前替换，前面标签的偏移不会因新增内容而改变。
        foreach ( array_reverse( $replacements ) as $replacement ) {
            $html = substr_replace( $html, $replacement[2], $replacement[0], $replacement[1] );
        }

        return $html;
    }

    /**
     * 把单个新格式 img 包成带旧格式回退的 picture
     *
     * @param string $html img 标签 HTML
     * @return string|null 没有可用回退时返回 null
     */
    private function wrap_image( string $html ): ?string {
        $parser = new WP_HTML_Tag_Processor( $html );

        if ( ! $parser->next_tag( 'IMG' ) ) {
            return null;
        }

        $src = $parser->get_attribute( 'src' );

        if ( ! is_string( $src ) ) {
            return null;
        }

        $relative = $this->relative_url_path( $src );
        $format   = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

        if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
            return null;
        }

        $fallback = $this->fallback_url( $relative );

        if ( '' === $fallback ) {
            return null;
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

        // 只把磁盘上确实存在的旧格式候选加入回退 srcset；缺文件的尺寸不能指向新格式。
        $original_candidates = array();

        if ( is_string( $srcset ) ) {
            foreach ( explode( ',', $srcset ) as $candidate ) {
                if ( ! preg_match( '/^\s*(\S+)\s+(\d+w|\d+(?:\.\d+)?x)\s*$/D', $candidate, $matches ) ) {
                    continue;
                }

                $url = $this->fallback_url( $this->relative_url_path( $matches[1] ) );

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

    /**
     * 按双扩展名规则推导旧格式回退地址
     *
     * 不是探头猜：新格式文件名 = 源文件名 + "." + 目标格式，去掉最后一段扩展名剩下的
     * 就是回退文件，确定性推导，没有候选列表和优先级。去掉后剩下的部分必须自己带一个
     * 非空扩展名，否则说明这是用户直接上传的 webp/avif，不是插件转换的产物，没有对应
     * 的旧格式原图，不能包 <picture>。
     *
     * @param string $relative 新格式文件在 uploads 内的相对路径
     * @return string 回退地址，没有对应旧格式文件时返回空字符串
     */
    private function fallback_url( string $relative ): string {
        if ( '' === $relative ) {
            return '';
        }

        // 取原始大小写的扩展名做截断：先 lower 再算长度会让 .WEBP 少截一个字符。
        $extension = pathinfo( $relative, PATHINFO_EXTENSION );

        if ( ! in_array( strtolower( $extension ), array( 'webp', 'avif' ), true ) ) {
            return '';
        }

        // 去掉最后一段（扩展名加前面的点）得到回退文件名。
        $stem = substr( $relative, 0, strlen( $relative ) - strlen( $extension ) - 1 );

        // 回退文件名必须自己带一个非空扩展名：photo.jpg.webp → photo.jpg 合法；
        // banner.webp → banner 没有扩展名，是直接上传的新格式，没有旧格式原图。
        if ( '' === pathinfo( $stem, PATHINFO_EXTENSION ) ) {
            return '';
        }

        if ( ! $this->fallback_file_exists( $stem ) ) {
            return '';
        }

        return $this->url_of( $stem );
    }

    /**
     * 判断推导出的旧格式回退文件是否存在于磁盘
     *
     * @param string $relative uploads 内的相对路径
     * @return bool
     */
    private function fallback_file_exists( string $relative ): bool {
        // 路径由本站上传目录的 URL 解析而来，仍按不可信数据再校验一次。
        if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpbrk( $relative, "\0\\:" ) ) {
            return false;
        }

        $uploads = wp_upload_dir();
        $path    = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . '/' . ltrim( wp_normalize_path( $relative ), '/' );

        clearstatcache( true, $path );

        return is_file( $path );
    }

    /**
     * 由 uploads 内相对路径生成公开 URL
     *
     * @param string $relative uploads 内的相对路径
     * @return string
     */
    private function url_of( string $relative ): string {
        $uploads = wp_upload_dir();

        return trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
    }

    /**
     * 仅接受当前上传目录的 URL，外站同路径、回溯和协议注入均不参与匹配。
     *
     * @param string $url 图片 URL
     * @return string uploads 内的相对路径，不属于本站上传目录时返回空字符串
     */
    private function relative_url_path( string $url ): string {
        $uploads = wp_upload_dir();
        $base    = wp_parse_url( $uploads['baseurl'] );
        $parts   = wp_parse_url( $url );

        if ( ! is_array( $base ) || ! is_array( $parts ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
            return '';
        }

        if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return '';
        }

        if ( isset( $parts['host'] ) && ( strtolower( $parts['host'] ) !== strtolower( $base['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $base['port'] ?? null ) ) ) {
            return '';
        }

        $path   = rawurldecode( $parts['path'] ?? '' );
        $prefix = trailingslashit( rawurldecode( $base['path'] ?? '' ) );

        if ( 0 !== strpos( $path, $prefix ) || strpbrk( $path, "\0\\:" ) !== false || false !== strpos( $path, '..' ) ) {
            return '';
        }

        return substr( $path, strlen( $prefix ) );
    }
}
