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

        // 格式转换：勾选启用的源格式，目标格式二选一（默认 WebP）
        $sanitized['convert_png'] = ! empty( $input['convert_png'] );
        $sanitized['convert_jpg'] = ! empty( $input['convert_jpg'] );
        $sanitized['convert_gif'] = ! empty( $input['convert_gif'] );
        $sanitized['convert_svg'] = ! empty( $input['convert_svg'] );
        $sanitized['convert_target'] = ( isset( $input['convert_target'] ) && in_array( $input['convert_target'], array( 'webp', 'avif' ), true ) ) ? $input['convert_target'] : 'webp';

        // SVG 上传开关
        $sanitized['allow_svg_upload'] = ! empty( $input['allow_svg_upload'] );

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

        $sanitized['avif_mode']    = isset( $input['avif_mode'] ) && in_array( $input['avif_mode'], array( 'lossy', 'lossless' ), true ) ? $input['avif_mode'] : 'lossy';
        $sanitized['avif_quality'] = isset( $input['avif_quality'] ) ? absint( $input['avif_quality'] ) : 80;

        $sanitized['gif_mode']    = isset( $input['gif_mode'] ) && in_array( $input['gif_mode'], array( 'lossy', 'lossless' ), true ) ? $input['gif_mode'] : 'lossy';
        $sanitized['gif_quality'] = isset( $input['gif_quality'] ) ? absint( $input['gif_quality'] ) : 60;

        $sanitized['svg_precision'] = isset( $input['svg_precision'] ) ? absint( $input['svg_precision'] ) : 3;

        $sanitized['jpeg_quality']       = max( 0, min( 100, $sanitized['jpeg_quality'] ) );
        $sanitized['png_lossy_quality']  = max( 0, min( 100, $sanitized['png_lossy_quality'] ) );
        $sanitized['png_lossless_level'] = max( 0, min( 6, $sanitized['png_lossless_level'] ) );
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
                            <input type="checkbox" name="libre_compress_general[convert_png]" value="1" <?php checked( ! empty( $options['convert_png'] ) ); ?>>
                            <?php esc_html_e( 'PNG', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[convert_jpg]" value="1" <?php checked( ! empty( $options['convert_jpg'] ) ); ?>>
                            <?php esc_html_e( 'JPG', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[convert_gif]" value="1" <?php checked( ! empty( $options['convert_gif'] ) ); ?>>
                            <?php esc_html_e( 'GIF', 'libre-compress' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="libre_compress_general[convert_svg]" value="1" <?php checked( ! empty( $options['convert_svg'] ) ); ?>>
                            <?php esc_html_e( 'SVG', 'libre-compress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '勾选的格式上传后将直接转换为目标新格式（原文件按备份设置处理），前端直接显示新格式。默认不勾选即不转换。', 'libre-compress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '转换目标格式', 'libre-compress' ); ?></th>
                    <td>
                        <span class="libre-compress-seg">
                            <input type="radio" name="libre_compress_general[convert_target]" value="webp" id="libre-compress-seg-webp" <?php checked( ( $options['convert_target'] ?? 'webp' ), 'webp' ); ?>>
                            <label for="libre-compress-seg-webp">WebP</label>
                            <input type="radio" name="libre_compress_general[convert_target]" value="avif" id="libre-compress-seg-avif" <?php checked( ( $options['convert_target'] ?? 'webp' ), 'avif' ); ?>>
                            <label for="libre-compress-seg-avif">AVIF</label>
                            <span class="seg-thumb"></span>
                        </span>
                        <p class="description"><?php esc_html_e( '选择转换的目标格式：AVIF 压缩率更高但编码更慢，WebP 兼容性更好', 'libre-compress' ); ?></p>
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
                    <button type="button" class="button" id="libre-compress-bulk-convert">
                        <?php esc_html_e( '批量转换未转换的图片', 'libre-compress' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '为媒体库中已启用格式的图片执行格式转换', 'libre-compress' ); ?></span>
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
        $converter  = libre_compress()->converter;
        $tools      = $compressor->get_tools();

        $exec_available = function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );

        // 转换/压缩流程用到的辅助工具（不属于压缩渠道，单独列出）
        $aux_tools = array(
            array(
                'name'  => 'avifdec',
                'usage' => __( 'AVIF 解码（压缩 AVIF 时先解码为 PNG 再重新编码）', 'libre-compress' ),
                'path'  => isset( $tools['libavif'] ) ? $tools['libavif']->get_decoder_path() : false,
                'url'   => 'https://github.com/AOMediaCodec/libavif/releases',
            ),
            array(
                'name'  => 'gif2webp',
                'usage' => __( '动画 GIF 转 WebP', 'libre-compress' ),
                'path'  => $converter->find_local_tool( 'gif2webp' ),
                'url'   => 'https://developers.google.com/speed/webp/download',
            ),
            array(
                'name'  => 'ffmpeg',
                'usage' => __( '动画 GIF 转 AVIF（解码 GIF 帧序列）', 'libre-compress' ),
                'path'  => $converter->find_local_tool( 'ffmpeg' ),
                'url'   => 'https://ffmpeg.org/download.html',
            ),
            array(
                'name'  => 'resvg',
                'usage' => __( 'SVG 栅格化为 PNG（SVG 转换依赖）', 'libre-compress' ),
                'path'  => $converter->find_local_tool( 'resvg' ),
                'url'   => 'https://github.com/linebender/resvg/releases',
            ),
        );
        ?>
        <h2><?php esc_html_e( '系统状态', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
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
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 110px;"><?php esc_html_e( '工具', 'libre-compress' ); ?></th>
                    <th style="width: 80px;"><?php esc_html_e( '格式', 'libre-compress' ); ?></th>
                    <th style="width: 90px;"><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '安装路径', 'libre-compress' ); ?></th>
                    <th style="width: 50px;"><?php esc_html_e( '链接', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tools as $tool ) : ?>
                    <?php $binary_path = $tool->get_tool_binary_path(); ?>
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
                            <?php if ( false !== $binary_path ) : ?>
                                <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html( $binary_path ); ?></code>
                            <?php else : ?>
                                —
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

        <h2 style="margin-top: 30px;"><?php esc_html_e( '转换辅助工具', 'libre-compress' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 110px;"><?php esc_html_e( '工具', 'libre-compress' ); ?></th>
                    <th style="width: 260px;"><?php esc_html_e( '用途', 'libre-compress' ); ?></th>
                    <th style="width: 90px;"><?php esc_html_e( '状态', 'libre-compress' ); ?></th>
                    <th><?php esc_html_e( '安装路径', 'libre-compress' ); ?></th>
                    <th style="width: 50px;"><?php esc_html_e( '链接', 'libre-compress' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $aux_tools as $aux ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $aux['name'] ); ?></strong></td>
                        <td><?php echo esc_html( $aux['usage'] ); ?></td>
                        <td>
                            <?php if ( false !== $aux['path'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '已安装', 'libre-compress' ); ?></span>
                            <?php else : ?>
                                <span style="color: #d63638;">✗ <?php esc_html_e( '未安装', 'libre-compress' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( false !== $aux['path'] ) : ?>
                                <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html( $aux['path'] ); ?></code>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $aux['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( '跳转', 'libre-compress' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="max-width: 900px; margin-top: 10px;">
            <?php esc_html_e( '安装方式（二选一）：① 推荐：把工具的可执行文件直接放进 /wp-content/LibreCompress-bin/ 目录，无需其他配置，随站点一起备份迁移；② 或将工具安装到电脑任意位置后，把它所在的文件夹加入系统的 Path 环境变量。', 'libre-compress' ); ?>
        </p>
        <p class="description" style="max-width: 900px;">
            <?php esc_html_e( 'Path 环境变量的添加方法（Windows）：右键「此电脑」→ 属性 → 高级系统设置 → 环境变量，在「用户变量」或「系统变量」中选中 Path 点「编辑」→「新建」，粘贴工具所在文件夹的完整路径后一路确定，改完重启 PHP 站点生效。Linux/macOS 可用包管理器安装（如 apt install、brew install）。两处同时存在同一种工具时，优先使用 LibreCompress-bin 目录中的版本。', 'libre-compress' ); ?>
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
            </table>

            <h3><?php esc_html_e( 'SVG 优化', 'libre-compress' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '优化精度', 'libre-compress' ); ?></th>
                    <td>
                        <input type="range" name="libre_compress_tools[svg_precision]" value="<?php echo esc_attr( $options['svg_precision'] ?? 3 ); ?>" min="0" max="8" oninput="this.nextElementSibling.value = this.value">
                        <output><?php echo esc_html( $options['svg_precision'] ?? 3 ); ?></output>
                        <p class="description"><?php esc_html_e( '坐标小数有效位数 0-8，数值越高越保真，文件越大', 'libre-compress' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * 构建压缩/转换路径支持状态行
     *
     * groups 为"或"关系：任一组内依赖全部可用即视为支持该路径，
     * 组内为"与"关系（如动画 GIF 转 AVIF 需 ffmpeg 与 avifenc 同时可用）
     *
     * @return array[] 每行包含 label、deps_display、supported、missing
     */
    private function get_path_support_rows(): array {
        $tools     = libre_compress()->compressor->get_tools();
        $converter = libre_compress()->converter;

        $available = array();
        foreach ( $tools as $name => $tool ) {
            $available[ $name ] = $tool->is_tool_available();
        }

        $available['avifdec']  = isset( $tools['libavif'] ) && false !== $tools['libavif']->get_decoder_path();
        $available['gif2webp'] = false !== $converter->find_local_tool( 'gif2webp' );
        $available['ffmpeg']   = false !== $converter->find_local_tool( 'ffmpeg' );
        $available['resvg']    = false !== $converter->find_local_tool( 'resvg' );
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
            array( 'label' => __( 'PNG 压缩', 'libre-compress' ), 'groups' => array( array( 'pngquant' ), array( 'oxipng' ) ) ),
            array( 'label' => __( 'WEBP 压缩', 'libre-compress' ), 'groups' => array( array( 'cwebp' ) ) ),
            array( 'label' => __( 'AVIF 压缩', 'libre-compress' ), 'groups' => array( array( 'avifenc', 'avifdec' ) ) ),
            array( 'label' => __( 'GIF 压缩', 'libre-compress' ), 'groups' => array( array( 'gifsicle' ) ) ),
            array( 'label' => __( 'SVG 优化', 'libre-compress' ), 'groups' => array( array( 'svgo' ) ) ),
            array( 'label' => __( 'PNG/JPG 转 WebP', 'libre-compress' ),          'groups' => array( array( 'cwebp' ) ) ),
            array( 'label' => __( 'PNG/JPG 转 AVIF', 'libre-compress' ),          'groups' => array( array( 'avifenc' ) ) ),
            array( 'label' => __( '静态 GIF 转 WebP', 'libre-compress' ),          'groups' => array( array( 'gd', 'cwebp' ) ) ),
            array( 'label' => __( '静态 GIF 转 AVIF', 'libre-compress' ),          'groups' => array( array( 'gd', 'avifenc' ) ) ),
            array( 'label' => __( '动画 GIF 转 WebP', 'libre-compress' ),          'groups' => array( array( 'gif2webp' ) ) ),
            array( 'label' => __( '动画 GIF 转 AVIF', 'libre-compress' ),          'groups' => array( array( 'ffmpeg', 'avifenc' ) ) ),
            array( 'label' => __( 'SVG 转 WebP', 'libre-compress' ),              'groups' => array( array( 'resvg', 'cwebp' ) ) ),
            array( 'label' => __( 'SVG 转 AVIF', 'libre-compress' ),              'groups' => array( array( 'resvg', 'avifenc' ) ) ),
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
