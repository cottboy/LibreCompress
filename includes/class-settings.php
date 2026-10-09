<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Libre_Compress_Settings {

    /**
     * 原图备份永久保留的取值
     */
    const RETENTION_PERMANENT = -1;

    /**
     * 大图缩放阈值：与 WordPress 默认值保持一致
     */
    const IMAGE_SIZE_THRESHOLD_DEFAULT = 2560;

    /**
     * 大图缩放阈值上限，防止误填夸张数值
     */
    const IMAGE_SIZE_THRESHOLD_MAX = 20000;

    // 统一约束保存值与命令参数，防止篡改选项绕过表单范围。
    const SPEED_SETTINGS = array(
        'pngquant_speed'     => array( 1, 11, 3 ),
        'oxipng_level'       => array( 0, 6, 4 ),
        'webp_method'        => array( 0, 6, 4 ),
        'webp_lossless_level' => array( 0, 9, 6 ),
        'gif2webp_method'    => array( 0, 6, 4 ),
        'avif_speed'         => array( 0, 10, 6 ),
        'gifsicle_level'     => array( 1, 3, 2 ),
    );

    public static function normalize_speed( string $key, $value ): int {
        list( $min, $max, $default ) = self::SPEED_SETTINGS[ $key ];

        if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^-?\d+$/D', (string) $value ) ) {
            return $default;
        }

        return (int) max( $min, min( $max, (float) $value ) );
    }

    public static function tool_speed( string $key ): int {
        $settings = get_option( 'libre_compress_tools', array() );
        return self::normalize_speed( $key, $settings[ $key ] ?? null );
    }

    private $current_tab = 'general';

    /**
     * 归一化原图备份保留时长
     *
     * -1 为永久保留；0 不是合法值，按步进方向落到 1；其他负数和非数字一律按永久处理；
     * 正整数不设上限。
     *
     * @param mixed $value 待归一化的原始值
     * @return int
     */
    public static function normalize_retention_days( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::RETENTION_PERMANENT;
        }

        $days = (int) $value;

        if ( 0 === $days ) {
            return 1;
        }

        return $days < 0 ? self::RETENTION_PERMANENT : $days;
    }

    /**
     * 归一化大图缩放阈值
     *
     * 对应 WordPress 的 big_image_size_threshold：上传图片的宽或高超过此值时会生成
     * -scaled 缩放图并接管原文件位置；0 表示关闭自动缩放。非数字按默认值处理，
     * 负数一律按关闭缩放。
     *
     * @param mixed $value 待归一化的原始值
     * @return int
     */
    public static function normalize_image_size_threshold( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::IMAGE_SIZE_THRESHOLD_DEFAULT;
        }

        return (int) max( 0, min( self::IMAGE_SIZE_THRESHOLD_MAX, (int) $value ) );
    }

    /**
     * 大图缩放阈值
     *
     * @return int 像素值，0 表示关闭自动缩放
     */
    public static function image_size_threshold(): int {
        $general = get_option( 'libre_compress_general', array() );

        return self::normalize_image_size_threshold( $general['image_size_threshold'] ?? null );
    }

    /**
     * 压缩与格式转换时是否删除图片元数据
     *
     * 选项缺失时按删除处理：拍摄地点、设备型号这类信息留在对外展示的图片上属于隐私泄露，
     * 默认不删不如默认删。
     *
     * @return bool
     */
    public static function strips_metadata(): bool {
        $general = get_option( 'libre_compress_general', array() );

        return ! isset( $general['strip_metadata'] ) || (bool) $general['strip_metadata'];
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_settings_menu() {
        add_options_page(
            __( 'LibreCompress', 'libre-compress' ),
            __( 'LibreCompress', 'libre-compress' ),
            'manage_options',
            'libre-compress',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'libre_compress_general_group',
            'libre_compress_general',
            array( $this, 'sanitize_general_settings' )
        );

        register_setting(
            'libre_compress_tools_group',
            'libre_compress_tools',
            array( $this, 'sanitize_tools_settings' )
        );
    }

    public function sanitize_general_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $sanitized = array();

        $current_general = get_option( 'libre_compress_general', array() );
        $sanitized['auto_compress']      = ! empty( $input['auto_compress'] );
        $sanitized['backup_enabled']     = ! empty( $input['backup_enabled'] );
        $sanitized['backup_retention_days'] = self::normalize_retention_days( isset( $input['backup_retention_days'] ) ? $input['backup_retention_days'] : null );
        $sanitized['image_size_threshold']   = self::normalize_image_size_threshold( isset( $input['image_size_threshold'] ) ? $input['image_size_threshold'] : null );
        $sanitized['strip_metadata']        = ! empty( $input['strip_metadata'] );
        $sanitized['tool_concurrency']   = isset( $input['tool_concurrency'] ) ? absint( $input['tool_concurrency'] ) : 5;
        // 缩略图尺寸：每个尺寸都有隐藏域 0 和复选框 1 两个同名输入，后提交的复选框生效，
        // 所以未勾选时也会带上 0；表单里没有这组字段时保留原状态。
        if ( array_key_exists( 'thumbnail_state', $input ) ) {
            $state    = (array) $input['thumbnail_state'];
            $disabled = array();

            foreach ( array_keys( wp_get_registered_image_subsizes() ) as $size_name ) {
                if ( ! isset( $state[ $size_name ] ) || '1' !== (string) $state[ $size_name ] ) {
                    $disabled[] = $size_name;
                }
            }

            $sanitized['disabled_thumbnail_sizes'] = $disabled;
        } else {
            $stored                                = isset( $current_general['disabled_thumbnail_sizes'] ) ? (array) $current_general['disabled_thumbnail_sizes'] : array();
            $sanitized['disabled_thumbnail_sizes'] = array_values( array_filter( array_map( 'sanitize_key', $stored ) ) );
        }

        $sanitized['tool_concurrency'] = max( 1, min( 100, $sanitized['tool_concurrency'] ) );

        // 压缩输出设置：勾选的源格式输出为目标格式，未勾选格式执行同格式压缩。
        $sanitized['output_png'] = ! empty( $input['output_png'] );
        $sanitized['output_jpg'] = ! empty( $input['output_jpg'] );
        $sanitized['output_gif'] = ! empty( $input['output_gif'] );
        $sanitized['output_svg'] = ! empty( $input['output_svg'] );
        $sanitized['output_format'] = ( isset( $input['output_format'] ) && in_array( $input['output_format'], array( 'webp', 'avif' ), true ) ) ? $input['output_format'] : 'webp';

        // 兼容格式回退开关
        $sanitized['fallback_enabled'] = ! empty( $input['fallback_enabled'] );

        // SVG 上传开关
        $sanitized['allow_svg_upload'] = ! empty( $input['allow_svg_upload'] );

        return $sanitized;
    }

    public function sanitize_tools_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $sanitized = array();

        foreach ( self::SPEED_SETTINGS as $key => $range ) {
            $sanitized[ $key ] = self::normalize_speed( $key, $input[ $key ] ?? null );
        }

        $sanitized['jpeg_mode']    = isset( $input['jpeg_mode'] ) && in_array( $input['jpeg_mode'], array( 'lossy', 'lossless' ), true ) ? $input['jpeg_mode'] : 'lossy';
        $sanitized['jpeg_quality'] = isset( $input['jpeg_quality'] ) ? absint( $input['jpeg_quality'] ) : 80;

        $sanitized['png_mode']           = isset( $input['png_mode'] ) && in_array( $input['png_mode'], array( 'lossy', 'lossless' ), true ) ? $input['png_mode'] : 'lossy';
        $sanitized['png_lossy_quality']  = isset( $input['png_lossy_quality'] ) ? absint( $input['png_lossy_quality'] ) : 80;

        $sanitized['webp_mode']    = isset( $input['webp_mode'] ) && in_array( $input['webp_mode'], array( 'lossy', 'lossless' ), true ) ? $input['webp_mode'] : 'lossy';
        $sanitized['webp_quality'] = isset( $input['webp_quality'] ) ? absint( $input['webp_quality'] ) : 80;

        $sanitized['avif_mode']    = isset( $input['avif_mode'] ) && in_array( $input['avif_mode'], array( 'lossy', 'lossless' ), true ) ? $input['avif_mode'] : 'lossy';
        $sanitized['avif_quality'] = isset( $input['avif_quality'] ) ? absint( $input['avif_quality'] ) : 80;

        $sanitized['gif_mode']    = isset( $input['gif_mode'] ) && in_array( $input['gif_mode'], array( 'lossy', 'lossless' ), true ) ? $input['gif_mode'] : 'lossy';
        $sanitized['gif_quality'] = isset( $input['gif_quality'] ) ? absint( $input['gif_quality'] ) : 60;

        $sanitized['svg_precision'] = isset( $input['svg_precision'] ) ? absint( $input['svg_precision'] ) : 3;
        $sanitized['svg_multipass'] = isset( $input['svg_multipass'] ) && in_array( $input['svg_multipass'], array( true, 1, '1' ), true );

        $sanitized['jpeg_quality']       = max( 0, min( 100, $sanitized['jpeg_quality'] ) );
        $sanitized['png_lossy_quality']  = max( 0, min( 100, $sanitized['png_lossy_quality'] ) );
        $sanitized['webp_quality']       = max( 0, min( 100, $sanitized['webp_quality'] ) );
        $sanitized['avif_quality']       = max( 0, min( 100, $sanitized['avif_quality'] ) );
        $sanitized['gif_quality']        = max( 0, min( 100, $sanitized['gif_quality'] ) );
        $sanitized['svg_precision']      = max( 0, min( 8, $sanitized['svg_precision'] ) );

        return $sanitized;
    }

    public function render_settings_page() {
        $this->current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

        $valid_tabs = array( 'general', 'tools' );
        if ( ! in_array( $this->current_tab, $valid_tabs, true ) ) {
            $this->current_tab = 'general';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LibreCompress', 'libre-compress' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=libre-compress&tab=general' ) ); ?>" class="nav-tab <?php echo 'general' === $this->current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '基本设置', 'libre-compress' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=libre-compress&tab=tools' ) ); ?>" class="nav-tab <?php echo 'tools' === $this->current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '压缩工具', 'libre-compress' ); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ( $this->current_tab ) {
                    case 'tools':
                        $this->render_tools_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_general_tab() {
        $options = get_option( 'libre_compress_general', array() );
        $retention_days = Libre_Compress_Settings::normalize_retention_days( isset( $options['backup_retention_days'] ) ? $options['backup_retention_days'] : null );
        $registered_sizes = wp_get_registered_image_subsizes();
        $disabled_sizes   = Libre_Compress_Thumbnail_Manager::disabled_sizes();
        ?>
        <style>
            .libre-compress-seg { position: relative; display: inline-flex; background: #dcdcde; border-radius: 8px; padding: 3px; vertical-align: middle; }
            .libre-compress-seg input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
            .libre-compress-seg label { position: relative; z-index: 2; flex: 1; min-width: 64px; padding: 5px 16px; text-align: center; color: #50575e; font-weight: 500; cursor: pointer; border-radius: 6px; transition: color .15s; }
            .libre-compress-seg .seg-thumb { position: absolute; z-index: 1; top: 3px; bottom: 3px; left: 3px; width: calc(50% - 3px); background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0, 0, 0, .15); transition: transform .2s ease; }
            #libre-compress-seg-avif:checked ~ .seg-thumb { transform: translateX(100%); }
            #libre-compress-seg-webp:checked ~ label[for="libre-compress-seg-webp"],
            #libre-compress-seg-avif:checked ~ label[for="libre-compress-seg-avif"] { color: #1d2327; font-weight: 600; }
        </style>
        <form method="post" action="options.php">
            <?php settings_fields( 'libre_compress_general_group' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '自动压缩', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[auto_compress]" value="1" <?php checked( ! empty( $options['auto_compress'] ) ); ?>>
                            <?php esc_html_e( '上传图片时自动压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩并发数', 'libre-compress' ); ?></th>
                    <td>
                        <input type="number" name="libre_compress_general[tool_concurrency]" value="<?php echo esc_attr( $options['tool_concurrency'] ?? 5 ); ?>" min="1" max="100" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '备份原图', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[backup_enabled]" value="1" <?php checked( $options['backup_enabled'] ?? true ); ?>>
                            <?php esc_html_e( '压缩前备份原图', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="libre-compress-retention-days"><?php esc_html_e( '原图备份保留时长', 'libre-compress' ); ?></label></th>
                    <td>
                        <input type="number" id="libre-compress-retention-days" name="libre_compress_general[backup_retention_days]" value="<?php echo esc_attr( $retention_days ); ?>" min="-1" step="1" class="small-text">
                        <?php esc_html_e( '天', 'libre-compress' ); ?>
                        <p class="description"><?php esc_html_e( '填 -1 表示永久保留；填天数则备份到期后自动删除，届时这张图将无法再恢复原图。0 无效，步进时会自动跳过 0。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '图片元数据', 'libre-compress' ); ?></th>
                    <td>
                        <label style="display:block;">
                            <input type="checkbox" name="libre_compress_general[strip_metadata]" value="1" <?php checked( $options['strip_metadata'] ?? true ); ?>>
                            <?php esc_html_e( '压缩时删除图片元数据', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '删除 EXIF、GPS 拍摄位置、设备型号、作者与软件信息等隐私数据，ICC 色彩配置会保留以免偏色。取消勾选则尽量保留元数据，但 WebP 和经 PNG 中转的 AVIF 由编码工具决定，不保证留得住。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'SVG 上传', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[allow_svg_upload]" value="1" <?php checked( ! empty( $options['allow_svg_upload'] ) ); ?>>
                            <?php esc_html_e( '允许上传 SVG 文件', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '上传时自动清理脚本等危险内容；SVG 可能被用于 XSS 攻击，仅在信任上传者时开启。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '格式转换', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_png]" value="1" <?php checked( ! empty( $options['output_png'] ) ); ?>>
                            <?php esc_html_e( 'PNG', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_jpg]" value="1" <?php checked( ! empty( $options['output_jpg'] ) ); ?>>
                            <?php esc_html_e( 'JPG', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_gif]" value="1" <?php checked( ! empty( $options['output_gif'] ) ); ?>>
                            <?php esc_html_e( 'GIF', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[output_svg]" value="1" <?php checked( ! empty( $options['output_svg'] ) ); ?>>
                            <?php esc_html_e( 'SVG', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '勾选的源格式执行压缩时输出为目标格式；未勾选的格式只执行同格式压缩。所有成功结果都记为已压缩。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '兼容格式回退', 'libre-compress' ); ?></th>
                    <td>
                        <label style="display:block;">
                            <input type="checkbox" name="libre_compress_general[fallback_enabled]" value="1" <?php checked( $options['fallback_enabled'] ?? false ); ?>>
                            <?php esc_html_e( '为转换后的图片提供旧格式回退', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '格式转换后旧格式文件原地保留，新的 WebP/AVIF 以“原文件名.webp”“原文件名.avif”的双扩展名形式生成，前台用 <picture> 输出：支持新格式的浏览器加载新格式，不支持的浏览器加载同样压缩过的旧格式。关闭后只输出新格式，旧格式文件仍留在磁盘上，可用下方的“删除所有兼容格式回退”清理。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '目标格式', 'libre-compress' ); ?></th>
                    <td>
                        <span class="libre-compress-seg">
                            <input type="radio" name="libre_compress_general[output_format]" value="webp" id="libre-compress-seg-webp" <?php checked( ( $options['output_format'] ?? 'webp' ), 'webp' ); ?>>
                            <label for="libre-compress-seg-webp"><?php esc_html_e( 'WebP', 'libre-compress' ); ?></label>
                            <input type="radio" name="libre_compress_general[output_format]" value="avif" id="libre-compress-seg-avif" <?php checked( ( $options['output_format'] ?? 'webp' ), 'avif' ); ?>>
                            <label for="libre-compress-seg-avif"><?php esc_html_e( 'AVIF', 'libre-compress' ); ?></label>
                            <span class="seg-thumb"></span>
                        </span>
                        <p class="description"><?php esc_html_e( '选择目标格式：AVIF 压缩率更高但压缩更慢，WebP 兼容性更好。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="libre-compress-image-threshold"><?php esc_html_e( '大图缩放阈值', 'libre-compress' ); ?></label></th>
                    <td>
                        <input type="number" id="libre-compress-image-threshold" name="libre_compress_general[image_size_threshold]" value="<?php echo esc_attr( Libre_Compress_Settings::image_size_threshold() ); ?>" min="0" max="20000" step="1" class="small-text">
                        <?php esc_html_e( '像素', 'libre-compress' ); ?>
                        <p class="description"><?php esc_html_e( 'WordPress 上传新图片时，宽或高超过此阈值会重新编码出一张 -scaled 缩放图并接管原文件位置，未缩放的源文件仍留在磁盘上；填 0 表示关闭自动缩放，原图文件直接投入使用。压缩未压缩的图片时也会按此阈值重新缩放：主文件超出就生成 -scaled 接管主文件，未缩放源文件保留，正文里的旧图片地址一并改写；已压缩的图片不再改动。默认 2560，与 WordPress 默认一致。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '缩略图尺寸', 'libre-compress' ); ?></th>
                    <td>
                        <?php if ( empty( $registered_sizes ) ) : ?>
                            <p class="description"><?php esc_html_e( '当前没有注册额外的缩略图尺寸。', 'libre-compress' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $registered_sizes as $size_name => $size_config ) : ?>
                                <?php
                                $width     = (int) $size_config['width'];
                                $height    = (int) $size_config['height'];
                                $dimension = $height > 0 ? $width . '×' . $height : $width . '×' . __( '自动', 'libre-compress' );
                                $crop      = empty( $size_config['crop'] ) ? __( '等比', 'libre-compress' ) : __( '裁剪', 'libre-compress' );
                                ?>
                                <label style="display:block;">
                                    <input type="hidden" name="libre_compress_general[thumbnail_state][<?php echo esc_attr( $size_name ); ?>]" value="0">
                                    <input type="checkbox" name="libre_compress_general[thumbnail_state][<?php echo esc_attr( $size_name ); ?>]" value="1" <?php checked( ! in_array( $size_name, $disabled_sizes, true ) ); ?>>
                                    <?php
                                    printf(
                                        /* translators: 1: 尺寸名, 2: 目标像素, 3: 裁剪方式 */
                                        esc_html__( '%1$s（%2$s，%3$s）', 'libre-compress' ),
                                        esc_html( $size_name ),
                                        esc_html( $dimension ),
                                        esc_html( $crop )
                                    );
                                    ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( '取消勾选的尺寸在上传图片时不再生成；已经存在的文件用下方“删除未勾选尺寸的缩略图”清理。以后新注册的尺寸默认勾选。', 'libre-compress' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <table class="form-table">
            <tr>
                <td style="width: 200px; padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-bulk-compress">
                        <?php esc_html_e( '批量压缩未压缩的图片', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '按当前设置压缩媒体库中所有未压缩的图片', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-clear-records">
                        <?php esc_html_e( '清除压缩记录与原图备份', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '同时删除备份文件、备份索引和格式转换映射，之后所有图片都可重新压缩；此操作不可撤销。', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-restore-all">
                        <?php esc_html_e( '恢复所有原图备份', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '将所有已压缩的图片恢复为原图', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-all-backups">
                        <?php esc_html_e( '删除所有原图备份', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除所有备份文件，释放磁盘空间', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-all-fallbacks">
                        <?php esc_html_e( '删除所有兼容格式回退', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除媒体库中转换后保留的旧格式回退文件，并把文章里指向它们的图片链接改到新格式；删除后不支持新格式的浏览器将直接加载新格式图片', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-thumbnails">
                        <?php esc_html_e( '删除未勾选尺寸的缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除上方未勾选尺寸已存在的缩略图文件，并把文章里指向它们的图片链接改到最相邻的尺寸', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-generate-thumbnails">
                        <?php esc_html_e( '补生成缺失尺寸的缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '只为上方已勾选但文件缺失的尺寸生成缩略图，不会重建已存在的文件，也不改动任何文章链接', 'libre-compress' ); ?></span>
                </td>
            </tr>
        </table>
        <div id="libre-compress-bulk-progress" style="display: none; margin-top: 10px;">
            <div class="progress-bar" style="width: 100%; height: 20px; background: #f0f0f0; border-radius: 3px;">
                <div class="progress-fill" style="width: 0%; height: 100%; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
            </div>
            <p class="progress-text" style="margin-top: 5px;"></p>
        </div>
        <?php
    }

    private function render_tools_tab() {
        $options = get_option( 'libre_compress_tools', array() );

        $compressor       = libre_compress()->compressor;
        $output_processor = libre_compress()->output_processor;
        $tools            = $compressor->get_tools();

        $disabled_functions = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        $exec_available = function_exists( 'exec' )
            && function_exists( 'proc_open' )
            && ! in_array( 'exec', $disabled_functions, true )
            && ! in_array( 'proc_open', $disabled_functions, true );

        // 全部压缩工具，按格式相邻排序
        $all_tools = array(
            array( 'name' => 'jpegoptim', 'tool' => $tools['jpegoptim'] ),
            array( 'name' => 'pngquant', 'tool' => $tools['pngquant'] ),
            array( 'name' => 'oxipng', 'tool' => $tools['oxipng'] ),
            array( 'name' => 'gifsicle', 'tool' => $tools['gifsicle'] ),
            array( 'name' => 'gif2webp', 'tool' => null, 'path' => $output_processor->find_local_tool( 'gif2webp' ), 'url' => 'https://developers.google.com/speed/webp/download' ),
            array( 'name' => 'ffmpeg', 'tool' => null, 'path' => $output_processor->find_local_tool( 'ffmpeg' ), 'url' => 'https://ffmpeg.org/download.html' ),
            array( 'name' => 'cwebp', 'tool' => $tools['cwebp'] ),
            array( 'name' => 'avifenc', 'tool' => null, 'path' => $output_processor->find_local_tool( 'avifenc' ), 'url' => 'https://github.com/AOMediaCodec/libavif' ),
            array( 'name' => 'avifdec', 'tool' => null, 'path' => $output_processor->find_local_tool( 'avifdec' ), 'url' => 'https://github.com/AOMediaCodec/libavif' ),
            array( 'name' => 'svgo', 'tool' => $tools['svgo'] ),
            array( 'name' => 'resvg', 'tool' => null, 'path' => $output_processor->find_local_tool( 'resvg' ), 'url' => 'https://github.com/linebender/resvg' ),
        );

        // 统一为渲染字段：available(bool)、path、url
        foreach ( $all_tools as $index => $row ) {
            if ( null !== $row['tool'] ) {
                $all_tools[ $index ]['available'] = $row['tool']->is_tool_available();
                $all_tools[ $index ]['path']      = $row['tool']->get_tool_binary_path();
                $all_tools[ $index ]['url']       = $row['tool']->get_download_url();
            } else {
                $all_tools[ $index ]['available'] = false !== $row['path'];
            }
        }
        ?>
        <h2><?php esc_html_e( '系统状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <tr>
                <td style="width: 120px;"><strong>proc_open()</strong></td>
                <td style="width: 150px;"></td>
                <td>
                    <?php if ( $exec_available ) : ?>
                        <span style="color: #00a32a;">✓ <?php esc_html_e( '可用', 'libre-compress' ); ?></span>
                    <?php else : ?>
                        <span style="color: #d63638;">✗ <?php esc_html_e( '不可用', 'libre-compress' ); ?></span>
                        <p class="description"><?php esc_html_e( '压缩依赖 exec() 和 proc_open() 函数，请联系主机商启用', 'libre-compress' ); ?></p>
                    <?php endif; ?>
                </td>
                <td></td>
            </tr>
        </table>

        <h2><?php esc_html_e( '工具状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 170px;"><?php esc_html_e( '工具', 'libre-compress' ); ?></th>
                    <th style="width: 90px;"><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '安装路径', 'libre-compress' ); ?></th>
                    <th style="width: 50px;"><?php esc_html_e( '链接', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all_tools as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
                        <td>
                            <?php if ( $row['available'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '已安装', 'libre-compress' ); ?></span>
                            <?php else : ?>
                                <span style="color: #d63638;">✗ <?php esc_html_e( '未安装', 'libre-compress' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( false !== $row['path'] ) : ?>
                                <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html( $row['path'] ); ?></code>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( '跳转', 'libre-compress' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="max-width: 900px; margin-top: 10px;">
            <?php esc_html_e( '安装方式：① 把可执行文件直接放进 /wp-content/LibreCompress-bin 目录；② 把工具所在文件夹加入系统 Path 环境变量；③ 使用包管理器安装。', 'libre-compress' ); ?>
        </p>

        <h2 style="margin-top: 30px;"><?php esc_html_e( '路径支持状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 220px;"><?php esc_html_e( '路径', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '依赖', 'libre-compress' ); ?></th>
                    <th style="width: 280px;"><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $this->get_path_support_rows() as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['label'] ); ?></td>
                        <td><?php echo wp_kses_post( $row['deps_display'] ); ?></td>
                        <td>
                            <?php if ( $row['supported'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '支持', 'libre-compress' ); ?></span>
                            <?php else : ?>
                                <span style="color: #d63638;">✗ <?php esc_html_e( '不支持', 'libre-compress' ); ?></span>
                                <?php if ( ! empty( $row['missing'] ) ) : ?>
                                    <span class="description">— <?php echo esc_html( sprintf( __( '缺少：%s', 'libre-compress' ), implode( '、', $row['missing'] ) ) ); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <hr>

        <h2><?php esc_html_e( '压缩参数设置', 'libre-compress' ); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'libre_compress_tools_group' ); ?>

            <h3 style="margin-top: 30px;"><?php esc_html_e( 'JPEG 压缩', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩模式', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="libre_compress_tools[jpeg_mode]" value="lossy" <?php checked( ( $options['jpeg_mode'] ?? 'lossy' ), 'lossy' ); ?>>
                            <?php esc_html_e( '有损压缩', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="radio" name="libre_compress_tools[jpeg_mode]" value="lossless" <?php checked( ( $options['jpeg_mode'] ?? 'lossy' ), 'lossless' ); ?>>
                            <?php esc_html_e( '无损压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩质量', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[jpeg_quality]" value="<?php echo esc_attr( $options['jpeg_quality'] ?? 80 ); ?>" min="0" max="100" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['jpeg_quality'] ?? 80 ); ?></output>
                        <p class="description"><?php esc_html_e( '0-100，数值越高质量越好，文件越大（仅有损压缩有效）', 'libre-compress' ); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'PNG 压缩', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩模式', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="libre_compress_tools[png_mode]" value="lossy" <?php checked( ( $options['png_mode'] ?? 'lossy' ), 'lossy' ); ?>>
                            <?php esc_html_e( '有损压缩', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="radio" name="libre_compress_tools[png_mode]" value="lossless" <?php checked( ( $options['png_mode'] ?? 'lossy' ), 'lossless' ); ?>>
                            <?php esc_html_e( '无损压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '有损压缩质量', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[png_lossy_quality]" value="<?php echo esc_attr( $options['png_lossy_quality'] ?? 80 ); ?>" min="0" max="100" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['png_lossy_quality'] ?? 80 ); ?></output>
                        <p class="description"><?php esc_html_e( 'pngquant 质量参数，0-100', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <?php $this->render_speed_field( 'pngquant_speed', __( 'pngquant 有损 PNG 速度', 'libre-compress' ), $options ); ?>
                <?php $this->render_speed_field( 'oxipng_level', __( 'oxipng 无损 PNG 级别', 'libre-compress' ), $options ); ?>
            </table>

            <h3><?php esc_html_e( 'WEBP 压缩', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩模式', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="libre_compress_tools[webp_mode]" value="lossy" <?php checked( ( $options['webp_mode'] ?? 'lossy' ), 'lossy' ); ?>>
                            <?php esc_html_e( '有损压缩', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="radio" name="libre_compress_tools[webp_mode]" value="lossless" <?php checked( ( $options['webp_mode'] ?? 'lossy' ), 'lossless' ); ?>>
                            <?php esc_html_e( '无损压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩质量', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[webp_quality]" value="<?php echo esc_attr( $options['webp_quality'] ?? 80 ); ?>" min="0" max="100" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['webp_quality'] ?? 80 ); ?></output>
                        <p class="description"><?php esc_html_e( '0-100，数值越高质量越好，文件越大（仅有损压缩有效）', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <?php $this->render_speed_field( 'webp_method', __( 'cwebp 有损 WebP 方法', 'libre-compress' ), $options ); ?>
                <?php $this->render_speed_field( 'webp_lossless_level', __( 'cwebp 无损 WebP 级别', 'libre-compress' ), $options ); ?>
                <?php $this->render_speed_field( 'gif2webp_method', __( 'gif2webp 动画 WebP 方法', 'libre-compress' ), $options ); ?>
            </table>

            <h3><?php esc_html_e( 'AVIF 压缩', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩模式', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="libre_compress_tools[avif_mode]" value="lossy" <?php checked( ( $options['avif_mode'] ?? 'lossy' ), 'lossy' ); ?>>
                            <?php esc_html_e( '有损压缩', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="radio" name="libre_compress_tools[avif_mode]" value="lossless" <?php checked( ( $options['avif_mode'] ?? 'lossy' ), 'lossless' ); ?>>
                            <?php esc_html_e( '无损压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩质量', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[avif_quality]" value="<?php echo esc_attr( $options['avif_quality'] ?? 80 ); ?>" min="0" max="100" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['avif_quality'] ?? 80 ); ?></output>
                        <p class="description"><?php esc_html_e( '0-100，数值越高质量越好，文件越大（仅有损压缩有效）', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <?php $this->render_speed_field( 'avif_speed', __( 'avifenc AVIF 速度', 'libre-compress' ), $options ); ?>
            </table>

            <h3><?php esc_html_e( 'GIF 压缩', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩模式', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="libre_compress_tools[gif_mode]" value="lossy" <?php checked( ( $options['gif_mode'] ?? 'lossy' ), 'lossy' ); ?>>
                            <?php esc_html_e( '有损压缩', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="radio" name="libre_compress_tools[gif_mode]" value="lossless" <?php checked( ( $options['gif_mode'] ?? 'lossy' ), 'lossless' ); ?>>
                            <?php esc_html_e( '无损压缩', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩质量', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[gif_quality]" value="<?php echo esc_attr( $options['gif_quality'] ?? 60 ); ?>" min="0" max="100" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['gif_quality'] ?? 60 ); ?></output>
                        <p class="description"><?php esc_html_e( '0-100，数值越高质量越好，文件越大（仅有损压缩有效）', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <?php $this->render_speed_field( 'gifsicle_level', __( 'gifsicle GIF 优化级别', 'libre-compress' ), $options ); ?>
            </table>

            <h3><?php esc_html_e( 'SVG 压缩', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩精度', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[svg_precision]" value="<?php echo esc_attr( $options['svg_precision'] ?? 3 ); ?>" min="0" max="8" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['svg_precision'] ?? 3 ); ?></output>
                        <p class="description"><?php esc_html_e( '坐标小数有效位数 0-8，数值越高越保真，文件越大', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'SVGO 多轮优化', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_tools[svg_multipass]" value="1" <?php checked( in_array( $options['svg_multipass'] ?? true, array( true, 1, '1' ), true ) ); ?>>
                            <?php esc_html_e( '启用多轮优化', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '多轮优化压缩更慢，通常文件更小；关闭后只优化一轮，速度更快。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_speed_field( string $key, string $label, array $options ): void {
        list( $min, $max, $default ) = self::SPEED_SETTINGS[ $key ];
        $value = self::normalize_speed( $key, $options[ $key ] ?? null );
        $description = in_array( $key, array( 'pngquant_speed', 'avif_speed' ), true )
            ? __( '数值越大速度越快，通常文件越大；数值越小压缩越慢，通常文件越小。', 'libre-compress' )
            : __( '数值越大压缩越慢，通常文件越小；数值越小速度越快，通常文件越大。', 'libre-compress' );
        ?>
        <tr>
            <th scope="row"><label for="libre-compress-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="range" id="libre-compress-<?php echo esc_attr( $key ); ?>" name="libre_compress_tools[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="1" oninput="this.nextElementSibling.value = this.value">
                <output><?php echo esc_html( $value ); ?></output>
                <p class="description"><?php
                    /* translators: 1: 最小档位，2: 最大档位，3: 默认档位。 */
                    echo esc_html( sprintf( __( '范围 %1$d-%2$d，默认 %3$d。', 'libre-compress' ), $min, $max, $default ) . $description );
                ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * 构建压缩路径支持状态行
     *
     * groups 为"或"关系：任一组内依赖全部可用即视为支持该路径，
     * 组内为"与"关系（如动画 GIF 压缩为 AVIF 需 ffmpeg 与 avifenc 同时可用）
     *
     * @return array[] 每行包含 label、deps_display、supported、missing
     */
    private function get_path_support_rows(): array {
        $tools            = libre_compress()->compressor->get_tools();
        $output_processor = libre_compress()->output_processor;

        $available = array();
        foreach ( $tools as $name => $tool ) {
            $available[ $name ] = $tool->is_tool_available();
        }

        // avifenc/avifdec 按可执行文件独立判定（同格式 AVIF 需要两者，目标输出只需要 avifenc）
        $available['avifenc'] = false !== $output_processor->find_local_tool( 'avifenc' );
        $available['avifdec'] = false !== $output_processor->find_local_tool( 'avifdec' );
        $available['gif2webp'] = false !== $output_processor->find_local_tool( 'gif2webp' );
        $available['ffmpeg']   = false !== $output_processor->find_local_tool( 'ffmpeg' );
        $available['resvg']    = false !== $output_processor->find_local_tool( 'resvg' );
        $available['gd']       = function_exists( 'imagecreatefromgif' ) && function_exists( 'imagepng' );

        $dep_labels = array(
            'jpegoptim' => 'jpegoptim',
            'pngquant'  => 'pngquant',
            'oxipng'    => 'oxipng',
            'cwebp'     => 'cwebp',
            'avifenc'   => 'avifenc',
            'avifdec'   => 'avifdec',
            'gifsicle'  => 'gifsicle',
            'svgo'      => 'svgo',
            'gif2webp'  => 'gif2webp',
            'ffmpeg'    => 'ffmpeg',
            'resvg'     => 'resvg',
            'gd'        => __( 'GD 扩展', 'libre-compress' ),
        );

        $rows_definition = array(
            array( 'label' => __( 'JPEG 压缩', 'libre-compress' ), 'groups' => array( array( 'jpegoptim' ) ) ),
            array( 'label' => __( 'PNG 压缩（按模式使用其中一种）', 'libre-compress' ), 'groups' => array( array( 'pngquant' ), array( 'oxipng' ) ) ),
            array( 'label' => __( 'WEBP 压缩', 'libre-compress' ), 'groups' => array( array( 'cwebp' ) ) ),
            array( 'label' => __( 'AVIF 压缩', 'libre-compress' ), 'groups' => array( array( 'avifenc', 'avifdec' ) ) ),
            array( 'label' => __( 'GIF 压缩', 'libre-compress' ), 'groups' => array( array( 'gifsicle' ) ) ),
            array( 'label' => __( 'SVG 压缩', 'libre-compress' ), 'groups' => array( array( 'svgo' ) ) ),
            array( 'label' => __( 'PNG/JPG 压缩为 WebP', 'libre-compress' ),          'groups' => array( array( 'cwebp' ) ) ),
            array( 'label' => __( 'PNG/JPG 压缩为 AVIF', 'libre-compress' ),          'groups' => array( array( 'avifenc' ) ) ),
            array( 'label' => __( '静态 GIF 压缩为 WebP', 'libre-compress' ),          'groups' => array( array( 'gd', 'cwebp' ) ) ),
            array( 'label' => __( '静态 GIF 压缩为 AVIF', 'libre-compress' ),          'groups' => array( array( 'gd', 'avifenc' ) ) ),
            array( 'label' => __( '动画 GIF 压缩为 WebP', 'libre-compress' ),          'groups' => array( array( 'gif2webp' ) ) ),
            array( 'label' => __( '动画 GIF 压缩为 AVIF', 'libre-compress' ),          'groups' => array( array( 'ffmpeg', 'avifenc' ) ) ),
            array( 'label' => __( 'SVG 压缩为 WebP', 'libre-compress' ),              'groups' => array( array( 'resvg', 'cwebp' ) ) ),
            array( 'label' => __( 'SVG 压缩为 AVIF', 'libre-compress' ),              'groups' => array( array( 'resvg', 'avifenc' ) ) ),
        );

        $rows = array();

        foreach ( $rows_definition as $definition ) {
            $supported    = false;
            $missing_all  = array();
            $deps_display = array();

            foreach ( $definition['groups'] as $group ) {
                $group_missing = array();
                $group_labels  = array();

                foreach ( $group as $dep ) {
                    $label          = isset( $dep_labels[ $dep ] ) ? $dep_labels[ $dep ] : $dep;
                    $group_labels[] = '<code>' . esc_html( $label ) . '</code>';

                    if ( empty( $available[ $dep ] ) ) {
                        $group_missing[] = $label;
                    }
                }

                if ( empty( $group_missing ) ) {
                    $supported = true;
                }

                $missing_all    = array_merge( $missing_all, $group_missing );
                $deps_display[] = implode( ' + ', $group_labels );
            }

            $rows[] = array(
                'label'        => $definition['label'],
                'deps_display' => implode( ' ' . __( '或', 'libre-compress' ) . ' ', $deps_display ),
                'supported'    => $supported,
                'missing'      => array_values( array_unique( $missing_all ) ),
            );
        }

        return $rows;
    }
}
