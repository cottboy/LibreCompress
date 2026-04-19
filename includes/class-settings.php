<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Libre_Compress_Settings {

    private $current_tab = 'general';

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
        $sanitized = array();

        $sanitized['auto_compress']      = ! empty( $input['auto_compress'] );
        $sanitized['backup_enabled']     = ! empty( $input['backup_enabled'] );
        $sanitized['tool_concurrency']   = isset( $input['tool_concurrency'] ) ? absint( $input['tool_concurrency'] ) : 5;
        $sanitized['disable_thumbnails'] = ! empty( $input['disable_thumbnails'] );

        $sanitized['tool_concurrency'] = max( 1, min( 100, $sanitized['tool_concurrency'] ) );

        return $sanitized;
    }

    public function sanitize_tools_settings( $input ) {
        $sanitized = array();

        $sanitized['jpeg_mode']    = isset( $input['jpeg_mode'] ) && in_array( $input['jpeg_mode'], array( 'lossy', 'lossless' ), true ) ? $input['jpeg_mode'] : 'lossy';
        $sanitized['jpeg_quality'] = isset( $input['jpeg_quality'] ) ? absint( $input['jpeg_quality'] ) : 80;

        $sanitized['png_mode']           = isset( $input['png_mode'] ) && in_array( $input['png_mode'], array( 'lossy', 'lossless' ), true ) ? $input['png_mode'] : 'lossy';
        $sanitized['png_lossy_quality']  = isset( $input['png_lossy_quality'] ) ? absint( $input['png_lossy_quality'] ) : 80;
        $sanitized['png_lossless_level'] = isset( $input['png_lossless_level'] ) ? absint( $input['png_lossless_level'] ) : 6;

        $sanitized['webp_mode']    = isset( $input['webp_mode'] ) && in_array( $input['webp_mode'], array( 'lossy', 'lossless' ), true ) ? $input['webp_mode'] : 'lossy';
        $sanitized['webp_quality'] = isset( $input['webp_quality'] ) ? absint( $input['webp_quality'] ) : 80;

        $sanitized['jpeg_quality']       = max( 0, min( 100, $sanitized['jpeg_quality'] ) );
        $sanitized['png_lossy_quality']  = max( 0, min( 100, $sanitized['png_lossy_quality'] ) );
        $sanitized['png_lossless_level'] = max( 0, min( 6, $sanitized['png_lossless_level'] ) );
        $sanitized['webp_quality']       = max( 0, min( 100, $sanitized['webp_quality'] ) );

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
        ?>
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
                    <th scope="row"><?php esc_html_e( '备份原图', 'libre-compress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="libre_compress_general[backup_enabled]" value="1" <?php checked( $options['backup_enabled'] ?? true ); ?>>
                            <?php esc_html_e( '压缩前备份原图', 'libre-compress' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '压缩并发数', 'libre-compress' ); ?></th>
                    <td>
                        <input type="number" name="libre_compress_general[tool_concurrency]" value="<?php echo esc_attr( $options['tool_concurrency'] ?? 5 ); ?>" min="1" max="100" class="small-text">
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
                    <span class="description"><?php esc_html_e( '压缩媒体库中所有未压缩的图片', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-clear-records">
                        <?php esc_html_e( '清除所有压缩记录', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '清除后可重新压缩所有图片', 'libre-compress' ); ?></span>
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
                    <button type="button" class="button" id="libre-compress-disable-thumbnails">
                        <?php esc_html_e( '禁止生成缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '后续上传的图片将不再生成缩略图', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-enable-thumbnails">
                        <?php esc_html_e( '重新启用缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '后续上传的图片将重新生成缩略图', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-delete-thumbnails">
                        <?php esc_html_e( '删除已有缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除所有缩略图并将文章中的图片链接替换为原图', 'libre-compress' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="libre-compress-regenerate-thumbnails">
                        <?php esc_html_e( '重新生成缩略图', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '为缺少缩略图的图片生成缩略图，并将文章中的图片链接替换为"大"尺寸', 'libre-compress' ); ?></span>
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

        $compressor = libre_compress()->compressor;
        $tools      = $compressor->get_tools();

        $exec_available = function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
        ?>
        <h2><?php esc_html_e( '系统状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 700px;">
            <tr>
                <td style="width: 120px;"><strong>exec()</strong></td>
                <td style="width: 150px;"></td>
                <td>
                    <?php if ( $exec_available ) : ?>
                        <span style="color: #00a32a;">✓ <?php esc_html_e( '可用', 'libre-compress' ); ?></span>
                    <?php else : ?>
                        <span style="color: #d63638;">✗ <?php esc_html_e( '不可用', 'libre-compress' ); ?></span>
                        <p class="description"><?php esc_html_e( '压缩依赖 exec() 函数，请联系主机商启用', 'libre-compress' ); ?></p>
                    <?php endif; ?>
                </td>
                <td></td>
            </tr>
        </table>

        <h2><?php esc_html_e( '压缩工具状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 700px;">
            <thead>
                <tr>
                    <th style="width: 120px;"><?php esc_html_e( '工具', 'libre-compress' ); ?></th>
                    <th style="width: 150px;"><?php esc_html_e( '格式', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '链接', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tools as $tool ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $tool->get_name() ); ?></strong></td>
                        <td><?php echo esc_html( strtoupper( implode( ', ', $tool->get_supported_formats() ) ) ); ?></td>
                        <td>
                            <?php if ( $tool->is_tool_available() ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '已安装', 'libre-compress' ); ?></span>
                            <?php else : ?>
                                <span style="color: #d63638;">✗ <?php esc_html_e( '未安装', 'libre-compress' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $tool->get_download_url() ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( '跳转', 'libre-compress' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="max-width: 700px; margin-top: 10px;">
            <?php esc_html_e( '安装方式：下载或自行编译二进制文件放到 /wp-content/LibreCompress-bin 目录 或 通过系统包管理器安装', 'libre-compress' ); ?>
        </p>

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
                <tr>
                    <th scope="row"><?php esc_html_e( '无损优化级别', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[png_lossless_level]" value="<?php echo esc_attr( $options['png_lossless_level'] ?? 6 ); ?>" min="0" max="6" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['png_lossless_level'] ?? 6 ); ?></output>
                        <p class="description"><?php esc_html_e( 'oxipng 优化级别，0-6，数值越高压缩越慢但效果越好', 'libre-compress' ); ?></p>
                    </td>
                </tr>
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
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }
}
