<?php
/**
 * LibreCompress 主类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LibreCompress 主类
 *
 * 负责初始化和协调所有子模块
 */
class Libre_Compress {

    /**
     * 单例实例
     *
     * @var Libre_Compress|null
     */
    private static $instance = null;

    /**
     * 数据库模块实例
     *
     * @var Libre_Compress_Database
     */
    public $database;

    /**
     * 备份模块实例
     *
     * @var Libre_Compress_Backup
     */
    public $backup;

    /**
     * 压缩调度器实例
     *
     * @var Libre_Compress_Compressor
     */
    public $compressor;

    /**
     * 媒体库集成模块实例
     *
     * @var Libre_Compress_Media_Library
     */
    public $media_library;

    /**
     * 缩略图管理模块实例
     *
     * @var Libre_Compress_Thumbnail_Manager
     */
    public $thumbnail_manager;

    /**
     * 设置页面模块实例
     *
     * @var Libre_Compress_Settings
     */
    public $settings;

    /**
     * 获取单例实例
     *
     * @return Libre_Compress
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     *
     * 私有化以实现单例模式
     */
    private function __construct() {
        $this->init_modules();
        $this->init_hooks();
    }

    /**
     * 禁止克隆
     */
    private function __clone() {}

    /**
     * 禁止反序列化
     *
     * @throws Exception 禁止反序列化
     */
    public function __wakeup() {
        throw new Exception( __( '不允许反序列化单例实例', 'libre-compress' ) );
    }

    /**
     * 初始化各子模块
     */
    private function init_modules() {
        // 初始化数据库模块
        $this->database = new Libre_Compress_Database();

        // 初始化备份模块
        $this->backup = new Libre_Compress_Backup();

        // 初始化压缩调度器
        $this->compressor = new Libre_Compress_Compressor();

        // 初始化媒体库集成模块
        $this->media_library = new Libre_Compress_Media_Library();

        // 初始化缩略图管理模块
        $this->thumbnail_manager = new Libre_Compress_Thumbnail_Manager();

        // 初始化设置页面模块（仅在后台）
        if ( is_admin() ) {
            $this->settings = new Libre_Compress_Settings();
        }
    }

    /**
     * 注册 WordPress 钩子
     */
    private function init_hooks() {
        // 加载后台脚本和样式
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // 检查数据库版本并升级
        add_action( 'admin_init', array( $this, 'check_db_version' ) );

        // 添加插件设置链接
        add_filter( 'plugin_action_links_' . LIBRE_COMPRESS_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * 加载后台脚本和样式
     *
     * @param string $hook 当前页面钩子
     */
    public function enqueue_admin_scripts( $hook ) {
        // 仅在媒体库和设置页面加载
        $allowed_hooks = array(
            'upload.php',
            'post.php',
            'post-new.php',
            'settings_page_libre-compress',
        );

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        // 加载后台 JavaScript
        wp_enqueue_script(
            'libre-compress-admin',
            LIBRE_COMPRESS_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            LIBRE_COMPRESS_VERSION,
            true
        );

        // 传递数据到 JavaScript
        wp_localize_script(
            'libre-compress-admin',
            'libreCompressData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'libre_compress_nonce' ),
                'i18n'      => array(
                    'compressing'             => __( '压缩中...', 'libre-compress' ),
                    'restoring'               => __( '恢复中...', 'libre-compress' ),
                    'success'                 => __( '操作成功', 'libre-compress' ),
                    'error'                   => __( '操作失败', 'libre-compress' ),
                    'confirmClear'            => __( '确定要清除所有压缩记录吗？此操作不可撤销。', 'libre-compress' ),
                    'confirmRestoreAll'       => __( '确定要恢复所有原图备份吗？此操作不可撤销。', 'libre-compress' ),
                    'confirmDelete'           => __( '确定要删除所有缩略图吗？此操作不可撤销。', 'libre-compress' ),
                    'confirmRegenerate'       => __( '确定要为缺少缩略图的图片重新生成缩略图吗？这可能需要一些时间。', 'libre-compress' ),
                    'confirmDeleteBackup'     => __( '确定要删除此图片的备份吗？删除后将无法恢复原图。', 'libre-compress' ),
                    'confirmDeleteAllBackups' => __( '确定要删除所有原图备份吗？删除后将无法恢复原图。', 'libre-compress' ),
                    'deleteBackup'            => __( '删除备份', 'libre-compress' ),
                    'processing'              => __( '处理中...', 'libre-compress' ),
                    'completed'               => __( '已完成', 'libre-compress' ),
                ),
            )
        );
    }

    /**
     * 检查数据库版本并升级
     */
    public function check_db_version() {
        $installed_version = get_option( 'libre_compress_db_version' );

        if ( $installed_version !== LIBRE_COMPRESS_DB_VERSION ) {
            $this->database->create_tables();
            update_option( 'libre_compress_db_version', LIBRE_COMPRESS_DB_VERSION );
        }
    }

    /**
     * 添加插件设置链接
     *
     * @param array $links 现有链接
     * @return array 修改后的链接
     */
    public function add_settings_link( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'options-general.php?page=libre-compress' ),
            __( '设置', 'libre-compress' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 获取插件设置
     *
     * @param string $group   设置组：general, local
     * @param string $key     设置键名
     * @param mixed  $default 默认值
     * @return mixed 设置值
     */
    public function get_option( $group, $key = null, $default = null ) {
        $option_name = 'libre_compress_' . $group;
        $options     = get_option( $option_name, array() );

        if ( null === $key ) {
            return $options;
        }

        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    /**
     * 更新插件设置
     *
     * @param string $group 设置组：general, local
     * @param string $key   设置键名
     * @param mixed  $value 设置值
     * @return bool 是否更新成功
     */
    public function update_option( $group, $key, $value ) {
        $option_name = 'libre_compress_' . $group;
        $options     = get_option( $option_name, array() );

        $options[ $key ] = $value;

        return update_option( $option_name, $options );
    }
}
