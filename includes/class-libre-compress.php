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
     * 目标格式底层处理器实例
     *
     * @var Libre_Compress_Output
     */
    public $output_processor;

    /**
     * 统一图片压缩处理器实例
     *
     * @var Libre_Compress_Processor
     */
    public $processor;

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

        // 初始化目标格式底层处理器
        $this->output_processor = new Libre_Compress_Output();

        // 前台图片按浏览器能力选择新格式或旧格式回退文件。
        new Libre_Compress_Compatible_Fallback();

        // 初始化统一图片压缩处理器
        $this->processor = new Libre_Compress_Processor();

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

        // 确保上传待办收尾任务已注册
        add_action( 'admin_init', array( $this, 'ensure_scheduled_tasks' ), 30 );

        // 添加插件设置链接
        add_filter( 'plugin_action_links_' . LIBRE_COMPRESS_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * 补注册定时任务，兼容插件升级前已启用的站点
     */
    public function ensure_scheduled_tasks(): void {
        if ( ! wp_next_scheduled( Libre_Compress_Processor::PENDING_SWEEP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Libre_Compress_Processor::PENDING_SWEEP_HOOK );
        }

        if ( ! wp_next_scheduled( Libre_Compress_Processor::BACKUP_PRUNE_HOOK ) ) {
            wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', Libre_Compress_Processor::BACKUP_PRUNE_HOOK );
        }
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
        $general_settings = get_option( 'libre_compress_general', array() );
        wp_localize_script(
            'libre-compress-admin',
            'libreCompressData',
            array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'libre_compress_nonce' ),
                'concurrency' => max( 1, min( 100, absint( isset( $general_settings['tool_concurrency'] ) ? $general_settings['tool_concurrency'] : 5 ) ) ),
                'i18n'        => array(
                    'error'                   => __( '操作失败', 'libre-compress' ),
                    'confirmClear'            => __( '确定要清除所有压缩记录和原图备份吗？清除后无法恢复到本轮压缩前的原图。', 'libre-compress' ),
                    'confirmRestoreAll'       => __( '确定要恢复所有原图备份吗？此操作不可撤销。', 'libre-compress' ),
                    'confirmDeleteThumbnails'   => __( '确定要删除未勾选尺寸的缩略图吗？文章里指向这些尺寸的链接会改到最相邻的尺寸，此操作不可撤销。', 'libre-compress' ),
                    'confirmGenerateThumbnails' => __( '确定要为已勾选但缺失的尺寸补生成缩略图吗？这可能需要一些时间。', 'libre-compress' ),
                    'confirmDeleteBackup'     => __( '确定要删除此图片的备份吗？删除后将无法恢复原图。', 'libre-compress' ),
                    'confirmDeleteAllBackups' => __( '确定要删除所有原图备份吗？删除后将无法恢复原图。', 'libre-compress' ),
                    'confirmDeleteFallback'   => __( '确定要删除此图片的兼容格式回退吗？删除后不支持新格式的浏览器将直接加载新格式图片，此操作不可撤销。', 'libre-compress' ),
                    'confirmDeleteAllFallbacks' => __( '确定要删除所有兼容格式回退吗？删除后不支持新格式的浏览器将直接加载新格式图片，此操作不可撤销。', 'libre-compress' ),
                    'deleteBackup'            => __( '删除备份', 'libre-compress' ),
                    'deleteFallback'          => __( '删除兼容格式回退', 'libre-compress' ),
                    'noCompressItems'         => __( '没有需要压缩的图片', 'libre-compress' ),
                    'processing'              => __( '处理中...', 'libre-compress' ),
                    'batchProgress'           => __( '已处理 %d 个图片', 'libre-compress' ),
                    /* translators: %1$s: 任务名称, %2$d: 成功数, %3$d: 跳过数, %4$d: 失败数 */
                    'batchSummary'            => __( '%1$s - 成功: %2$d, 跳过: %3$d, 失败: %4$d', 'libre-compress' ),
                    /* translators: %1$s: 任务名称, %2$d: 成功数, %3$d: 失败数 */
                    'operationSummary'        => __( '%1$s - 成功: %2$d, 失败: %3$d', 'libre-compress' ),
                    /* translators: %s: 附件 ID 列表 */
                    'failedIds'               => __( '失败附件 ID：%s', 'libre-compress' ),
                    /* translators: %d: 失败附件总数 */
                    'failedMore'              => __( '（共 %d 个，仅显示前 10 个）', 'libre-compress' ),
                    'restoreProgress'         => __( '已恢复 %d 个附件', 'libre-compress' ),
                    'restoreFinished'         => __( '恢复完成', 'libre-compress' ),
                    /* translators: %d: 已处理附件数 */
                    'restoreInterrupted'      => __( '恢复中断：已完成 %d 个附件，再次执行可继续恢复剩余附件。', 'libre-compress' ),
                    'clearProgress'           => __( '已清除 %d 个图片', 'libre-compress' ),
                    'clearFinished'           => __( '清除完成', 'libre-compress' ),
                    /* translators: %d: 已处理附件数 */
                    'clearInterrupted'        => __( '清除中断：已完成 %d 个附件，再次执行可继续清除剩余附件。', 'libre-compress' ),
                    /* translators: %d: 已处理附件数 */
                    'thumbnailProgress'       => __( '已处理 %d 个附件', 'libre-compress' ),
                    /* translators: 1: 删除的文件数, 2: 影响的附件数, 3: 替换的链接数 */
                    'thumbnailDeleteSummary'  => __( '已删除 %1$d 个未勾选尺寸的缩略图（涉及 %2$d 个附件），替换了 %3$d 个图片链接', 'libre-compress' ),
                    /* translators: 1: 补生成的附件数, 2: 新增文件数, 3: 跳过的附件数, 4: 失败的附件数 */
                    'thumbnailGenerateSummary' => __( '已为 %1$d 个附件补生成 %2$d 个缩略图（%3$d 个无需处理或已跳过，%4$d 个失败）', 'libre-compress' ),
                    'thumbnailContentFailed'  => __( '部分正文链接未更新成功，可再次执行本操作继续处理。', 'libre-compress' ),
                    'thumbnailInterrupted'    => __( '处理中断，再次执行可继续处理剩余附件。', 'libre-compress' ),
                    'fallbackProgress'        => __( '已处理 %d 个附件', 'libre-compress' ),
                    'fallbackFinished'        => __( '删除完成', 'libre-compress' ),
                    /* translators: %d: 已处理附件数 */
                    'fallbackInterrupted'     => __( '删除中断：已完成 %d 个附件，再次执行可继续删除剩余附件的兼容格式回退。', 'libre-compress' ),
                    /* translators: 1: 涉及的附件数, 2: 删除的回退文件数, 3: 替换的链接数 */
                    'fallbackSummary'         => __( '已删除 %1$d 个附件上的 %2$d 个兼容格式回退文件，替换了 %3$d 个图片链接', 'libre-compress' ),
                    'fallbackContentFailed'   => __( '部分正文链接未更新成功，可再次执行本操作继续处理。', 'libre-compress' ),
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
