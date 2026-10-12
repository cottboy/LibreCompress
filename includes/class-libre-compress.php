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
     * 后台批量任务实例
     *
     * @var Libre_Compress_Task
     */
    public $task;

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

        // 初始化后台批量任务引擎
        $this->task = new Libre_Compress_Task();

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
        wp_localize_script(
            'libre-compress-admin',
            'libreCompressData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'libre_compress_nonce' ),
                'taskState' => $this->task->get_state(),
                'i18n'      => array(
                    'error'                   => __( '操作失败', 'libre-compress' ),
                    'detailsTitle'            => __( '图片详情', 'libre-compress' ),
                    'detailsClose'            => __( '关闭', 'libre-compress' ),
                    'detailsGroup'            => __( '类别', 'libre-compress' ),
                    'detailsItem'             => __( '文件项目', 'libre-compress' ),
                    'detailsFormat'           => __( '格式', 'libre-compress' ),
                    'detailsStatus'           => __( '状态', 'libre-compress' ),
                    'detailsOriginalFileSize' => __( '压缩前大小', 'libre-compress' ),
                    'detailsCurrentSize'      => __( '当前大小', 'libre-compress' ),
                    'detailsSavedSize'        => __( '节省大小', 'libre-compress' ),
                    'detailsEmpty'            => __( '没有可显示的文件', 'libre-compress' ),
                    'confirmClear'            => __( '确定要清除所有压缩记录和原图备份吗？清除后无法恢复到本轮压缩前的原图。', 'libre-compress' ),
                    'confirmRestoreAll'       => __( '确定要恢复所有原图备份吗？此操作不可撤销。', 'libre-compress' ),
                    'confirmDeleteThumbnails'   => __( '确定要删除未勾选尺寸的缩略图吗？文章里指向这些尺寸的链接会改到面积最接近的现有尺寸；关闭了自动缩放或缩放图与阈值不符时，也会删除缩放图让主图退回原图。此操作不可撤销。', 'libre-compress' ),
                    'confirmGenerateThumbnails' => __( '确定要为已勾选但缺失的尺寸补生成缩略图吗？开启了自动缩放且主图超过阈值时，还会生成或修正 -scaled 缩放图。这可能需要一些时间。', 'libre-compress' ),
                    'confirmDeleteBackup'     => __( '确定要删除此图片的备份吗？删除后将无法恢复原图。', 'libre-compress' ),
                    'confirmDeleteAllBackups' => __( '确定要删除所有原图备份吗？删除后将无法恢复原图。', 'libre-compress' ),
                    'confirmDeleteFallback'   => __( '确定要删除此图片的兼容格式回退吗？删除后不支持新格式的浏览器将直接加载新格式图片，此操作不可撤销。', 'libre-compress' ),
                    'confirmDeleteAllFallbacks' => __( '确定要删除所有兼容格式回退吗？删除后不支持新格式的浏览器将直接加载新格式图片，此操作不可撤销。', 'libre-compress' ),
                    'deleteBackup'            => __( '删除备份', 'libre-compress' ),
                    'deleteFallback'          => __( '删除兼容格式回退', 'libre-compress' ),
                    'processing'              => __( '处理中...', 'libre-compress' ),
                    'taskRunning'             => __( '执行中', 'libre-compress' ),
                    'taskDone'                => __( '已完成', 'libre-compress' ),
                    'taskStopped'             => __( '已停止', 'libre-compress' ),
                    'taskStalled'             => __( '任务已停滞，点“继续执行”接着跑', 'libre-compress' ),
                    /* translators: 1: 已处理数量, 2: 总数 */
                    'taskProgress'            => __( '已处理 %1$d / %2$d', 'libre-compress' ),
                    /* translators: 1: 已检查数量, 2: 图片总数 */
                    'taskScanned'             => __( '已检查 %1$d / %2$d 张图片', 'libre-compress' ),
                    /* translators: 1: 成功数, 2: 跳过数, 3: 失败数 */
                    'taskCounts'              => __( '成功 %1$d，跳过 %2$d，失败 %3$d', 'libre-compress' ),
                    /* translators: %s: 正在处理的图片名 */
                    'taskCurrent'             => __( '正在处理：%s', 'libre-compress' ),
                    /* translators: %s: 已用时长 */
                    'taskElapsed'             => __( '已用时 %s', 'libre-compress' ),
                    /* translators: %d: 秒数 */
                    'taskSeconds'             => __( '%d 秒', 'libre-compress' ),
                    /* translators: 1: 分钟数, 2: 秒数 */
                    'taskMinutes'             => __( '%1$d 分 %2$d 秒', 'libre-compress' ),
                    /* translators: %d: 失败数量 */
                    'taskRetry'               => __( '重试失败的 %d 个项目', 'libre-compress' ),
                    'taskStopConfirm'         => __( '确定停止当前任务吗？已经处理完的部分会保留下来。', 'libre-compress' ),
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
