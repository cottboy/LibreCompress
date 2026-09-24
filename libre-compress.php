<?php
/**
 * Plugin Name: LibreCompress
 * Plugin URI: https://github.com/cottboy/libre-compress
 * Description: 免费的 WordPress 图片压缩插件。
 * Version: 1.2.0
 * Author: cottboy
 * Author URI: https://github.com/cottboy
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: libre-compress
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 插件版本号
 */
define( 'LIBRE_COMPRESS_VERSION', '1.2.0' );

/**
 * 插件文件路径
 */
define( 'LIBRE_COMPRESS_FILE', __FILE__ );

/**
 * 插件目录路径
 */
define( 'LIBRE_COMPRESS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * 插件目录 URL
 */
define( 'LIBRE_COMPRESS_URL', plugin_dir_url( __FILE__ ) );

/**
 * 插件基础名称
 */
define( 'LIBRE_COMPRESS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * 压缩工具二进制文件目录路径
 * 存储在 wp-content 目录下，避免插件更新时被覆盖
 */
define( 'LIBRE_COMPRESS_BIN_PATH', WP_CONTENT_DIR . '/LibreCompress-bin/' );

/**
 * 数据库版本号
 */
define( 'LIBRE_COMPRESS_DB_VERSION', '1.2.0' );

/**
 * 加载插件文本域
 */
function libre_compress_load_textdomain() {
    load_plugin_textdomain(
        'libre-compress',
        false,
        dirname( LIBRE_COMPRESS_BASENAME ) . '/languages'
    );
}
add_action( 'plugins_loaded', 'libre_compress_load_textdomain' );

/**
 * 允许上传 AVIF 图片
 *
 * WordPress 6.5 起原生支持 AVIF，此过滤器保证旧版本也能上传
 *
 * @param array $mimes 允许的 MIME 类型
 * @return array 修改后的 MIME 类型
 */
function libre_compress_allow_avif_upload( $mimes ) {
    if ( ! isset( $mimes['avif'] ) ) {
        $mimes['avif'] = 'image/avif';
    }
    return $mimes;
}
add_filter( 'upload_mimes', 'libre_compress_allow_avif_upload' );

/**
 * 检查设置中是否允许上传 SVG
 *
 * @return bool 是否允许
 */
function libre_compress_svg_upload_allowed(): bool {
    $general = get_option( 'libre_compress_general', array() );
    return ! empty( $general['allow_svg_upload'] );
}

/**
 * 允许上传 SVG 图片（需在设置中开启）
 *
 * @param array $mimes 允许的 MIME 类型
 * @return array 修改后的 MIME 类型
 */
function libre_compress_allow_svg_upload( $mimes ) {
    if ( libre_compress_svg_upload_allowed() && ! isset( $mimes['svg'] ) ) {
        $mimes['svg'] = 'image/svg+xml';
    }
    return $mimes;
}
add_filter( 'upload_mimes', 'libre_compress_allow_svg_upload' );

/**
 * 清理 SVG 内容中的危险代码
 *
 * 移除 script 与 foreignObject 元素、on* 事件属性、javascript/vbscript
 * 协议与 data:text/html 伪协议；包含 DOCTYPE/ENTITY 的文件直接拒绝（防 XXE）
 *
 * @param string $content SVG 文件内容
 * @return string|false 清理后的内容，不合法时返回 false
 */
function libre_compress_sanitize_svg_content( string $content ) {
    // SVG 图片不需要 DOCTYPE/ENTITY，存在即拒绝，杜绝 XXE 向量
    if ( false !== stripos( $content, '<!DOCTYPE' ) || false !== stripos( $content, '<!ENTITY' ) ) {
        return false;
    }

    if ( false === stripos( $content, '<svg' ) ) {
        return false;
    }

    if ( ! class_exists( 'DOMDocument' ) ) {
        return false;
    }

    $doc = new DOMDocument();

    libxml_use_internal_errors( true );
    // LIBXML_NONET：禁止加载外部实体
    $loaded = $doc->loadXML( $content, LIBXML_NONET );
    libxml_clear_errors();

    if ( ! $loaded ) {
        return false;
    }

    $xpath = new DOMXPath( $doc );

    // 移除 script 与 foreignObject 元素
    foreach ( array( 'script', 'foreignObject' ) as $tag ) {
        $nodes = $xpath->query( '//*[local-name()="' . $tag . '"]' );

        if ( $nodes ) {
            foreach ( iterator_to_array( $nodes ) as $node ) {
                if ( null !== $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }
    }

    // 移除危险属性：on* 事件、脚本伪协议、HTML 数据协议
    $elements = $xpath->query( '//*' );

    if ( $elements ) {
        foreach ( iterator_to_array( $elements ) as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
                $name  = strtolower( $attribute->nodeName );
                $value = strtolower( trim( (string) $attribute->nodeValue ) );
                // 浏览器会忽略 URL 协议中的 ASCII 控制字符，清理后再判断协议。
                $value = preg_replace( '/[\x00-\x20\x7F]+/', '', $value );

                if ( 0 === strpos( $name, 'on' ) || preg_match( '/^(javascript|vbscript):|^data:text\/html/', $value ) ) {
                    $element->removeAttributeNode( $attribute );
                }
            }
        }
    }

    return $doc->saveXML();
}

/**
 * 上传前清理 SVG 文件内容
 *
 * @param array $file 上传文件信息
 * @return array 修改后的上传文件信息
 */
function libre_compress_sanitize_svg_upload( $file ) {
    if ( ! libre_compress_svg_upload_allowed() ) {
        return $file;
    }

    if ( empty( $file['type'] ) || 'image/svg+xml' !== $file['type'] || empty( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
        return $file;
    }

    if ( ! class_exists( 'DOMDocument' ) ) {
        $file['error'] = __( '服务器缺少 DOM 扩展，无法安全处理 SVG', 'libre-compress' );
        return $file;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
    $content = file_get_contents( $file['tmp_name'] );

    if ( false === $content ) {
        $file['error'] = __( '无法读取上传的 SVG 文件', 'libre-compress' );
        return $file;
    }

    $clean = libre_compress_sanitize_svg_content( $content );

    if ( false === $clean ) {
        $file['error'] = __( 'SVG 内容不合法或包含危险代码', 'libre-compress' );
        return $file;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    $written = file_put_contents( $file['tmp_name'], $clean );

    if ( false === $written || $written !== strlen( $clean ) ) {
        $file['error'] = __( '无法安全写入清理后的 SVG 文件', 'libre-compress' );
    }

    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'libre_compress_sanitize_svg_upload' );

/**
 * 加载依赖文件
 */
function libre_compress_load_dependencies() {
    // 加载压缩工具基类和实现
    require_once LIBRE_COMPRESS_PATH . 'compression/class-tool-base.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-jpegoptim.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-pngquant.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-oxipng.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-cwebp.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-avif.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-gifsicle.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-svgo.php';

    // 加载核心类
    require_once LIBRE_COMPRESS_PATH . 'includes/class-database.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-backup.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-compressor.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-converter.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-processor.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-media-library.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-thumbnail-manager.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-settings.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-libre-compress.php';
}

/**
 * 插件激活时执行
 */
function libre_compress_activate() {
    // 加载依赖
    libre_compress_load_dependencies();

    // 创建压缩工具二进制文件目录
    if ( ! file_exists( LIBRE_COMPRESS_BIN_PATH ) ) {
        wp_mkdir_p( LIBRE_COMPRESS_BIN_PATH );

        // 添加 .htaccess 保护文件
        $htaccess_content = "# 禁止直接访问\nOrder deny,allow\nDeny from all\n";
        file_put_contents( LIBRE_COMPRESS_BIN_PATH . '.htaccess', $htaccess_content );

        // 添加 index.php 保护文件
        $index_content = "<?php\n// 禁止直接访问\n";
        file_put_contents( LIBRE_COMPRESS_BIN_PATH . 'index.php', $index_content );
    }

    // 创建数据库表
    $database = new Libre_Compress_Database();
    $database->create_tables();

    // 设置默认选项
    $default_general = array(
        'auto_compress'      => false,
        'backup_enabled'     => true,
        'tool_concurrency'   => 5,
        'disable_thumbnails' => false,
        'convert_target'     => 'webp',
    );

    $default_tools = array(
        'jpeg_mode'          => 'lossy',
        'jpeg_quality'       => 80,
        'png_mode'           => 'lossy',
        'png_lossy_quality'  => 80,
        'png_lossless_level' => 4,
        'webp_mode'          => 'lossy',
        'webp_quality'       => 80,
        'avif_mode'          => 'lossy',
        'avif_quality'       => 80,
        'gif_mode'           => 'lossy',
        'gif_quality'        => 60,
        'svg_precision'      => 3,
    );

    // 只在选项不存在时添加默认值
    if ( false === get_option( 'libre_compress_general' ) ) {
        add_option( 'libre_compress_general', $default_general );
    }

    if ( false === get_option( 'libre_compress_tools' ) ) {
        add_option( 'libre_compress_tools', $default_tools );
    }

    // 刷新重写规则
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'libre_compress_activate' );

/**
 * 插件停用时执行
 */
function libre_compress_deactivate() {
    // 刷新重写规则
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'libre_compress_deactivate' );

/**
 * 初始化插件
 */
function libre_compress_init() {
    // 加载依赖
    libre_compress_load_dependencies();

    // 初始化主类
    Libre_Compress::get_instance();
}
add_action( 'plugins_loaded', 'libre_compress_init', 20 );

/**
 * 获取插件实例
 *
 * @return Libre_Compress
 */
function libre_compress() {
    return Libre_Compress::get_instance();
}
