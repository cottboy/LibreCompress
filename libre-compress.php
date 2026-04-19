<?php
/**
 * Plugin Name: LibreCompress
 * Plugin URI: https://github.com/cottboy/libre-compress
 * Description: 免费的 WordPress 图片压缩插件。
 * Version: 1.0.0
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
define( 'LIBRE_COMPRESS_VERSION', '1.1.0' );

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
 * 本地压缩工具二进制文件目录路径
 * 存储在 wp-content 目录下，避免插件更新时被覆盖
 */
define( 'LIBRE_COMPRESS_BIN_PATH', WP_CONTENT_DIR . '/LibreCompress-bin/' );

/**
 * 数据库版本号
 */
define( 'LIBRE_COMPRESS_DB_VERSION', '1.1.0' );

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
 * 加载依赖文件
 */
function libre_compress_load_dependencies() {
    // 加载本地压缩基类和实现
    require_once LIBRE_COMPRESS_PATH . 'compression/class-local-base.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-jpegoptim.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-pngquant.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-oxipng.php';
    require_once LIBRE_COMPRESS_PATH . 'compression/class-cwebp.php';

    // 加载核心类
    require_once LIBRE_COMPRESS_PATH . 'includes/class-database.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-backup.php';
    require_once LIBRE_COMPRESS_PATH . 'includes/class-compressor.php';
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

    // 创建本地压缩工具二进制文件目录
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
        'local_concurrency'  => 5,
        'disable_thumbnails' => false,
    );

    $default_local = array(
        'jpeg_mode'          => 'lossy',
        'jpeg_quality'       => 80,
        'png_mode'           => 'lossy',
        'png_lossy_quality'  => 80,
        'png_lossless_level' => 4,
        'webp_mode'          => 'lossy',
        'webp_quality'       => 80,
    );

    // 只在选项不存在时添加默认值
    if ( false === get_option( 'libre_compress_general' ) ) {
        add_option( 'libre_compress_general', $default_general );
    }

    if ( false === get_option( 'libre_compress_local' ) ) {
        add_option( 'libre_compress_local', $default_local );
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
