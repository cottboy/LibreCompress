<?php
/**
 * 在真实 WordPress 和本地工具上运行回归测试。
 * 用数据库事务回滚记录，所有文件限定在本轮独立测试目录内。
 */
if ( PHP_SAPI !== 'cli' ) {
    exit( '仅允许命令行运行。' );
}

$wp_root = isset( $argv[1] ) ? realpath( $argv[1] ) : false;
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
    exit( "用法：php tests/run.php WordPress根目录 [--keep-files]\n" );
}
$_SERVER['HTTP_HOST'] = 'localhost';
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

if ( ! class_exists( 'Libre_Compress_Fallback' ) || ! extension_loaded( 'gd' ) ) {
    exit( "请启用 LibreCompress 和 GD 扩展。\n" );
}

$plugin = libre_compress();
$plugin->processor->set_auto_compress_suppressed( true );
remove_action( 'shutdown', array( $plugin->processor, 'process_queued_uploads' ), 1 );
$uploads = wp_upload_dir();
$test_dir = $uploads['basedir'] . '/libre-compress-tests-' . wp_generate_password( 12, false, false );
$test_url = $uploads['baseurl'] . '/' . basename( $test_dir );
$keep_files = in_array( '--keep-files', $argv, true );
$checks = 0;
$fixture_ids = array();
$option_keys = array( 'libre_compress_general', 'libre_compress_tools' );

function lc_check( bool $condition, string $message ): void {
    global $checks;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    $checks++;
}

function lc_call( $object, string $method, array $args = array() ) {
    $reflection = new ReflectionMethod( $object, $method );
    return $reflection->invokeArgs( $object, $args );
}

function lc_argument( array $command, string $flag ): string {
    $index = array_search( $flag, $command, true );
    return false !== $index ? ( $command[ $index + 1 ] ?? '' ) : '';
}

function lc_options( array $general = array(), array $tools = array() ): void {
    update_option( 'libre_compress_general', array_merge( array(
        'auto_compress' => false, 'backup_enabled' => true, 'backup_retention_days' => -1,
        'strip_metadata' => true, 'original_fallback' => true, 'output_format' => 'webp',
    ), $general ) );
    update_option( 'libre_compress_tools', array_merge( array(
        'jpeg_quality' => 80, 'png_lossy_quality' => 80, 'webp_quality' => 80,
        'avif_quality' => 80, 'gif_quality' => 60, 'svg_precision' => 3,
        'jpeg_mode' => 'lossy', 'png_mode' => 'lossy', 'webp_mode' => 'lossy',
        'avif_mode' => 'lossy', 'gif_mode' => 'lossy',
    ), $tools ) );
}

function lc_image( string $path, string $format = 'png', int $width = 96, int $height = 72 ): void {
    $image = imagecreatetruecolor( $width, $height );
    for ( $y = 0; $y < $height; $y++ ) {
        for ( $x = 0; $x < $width; $x++ ) {
            imagesetpixel( $image, $x, $y, ( ( $x * 3 ) % 256 << 16 ) | ( ( $y * 4 ) % 256 << 8 ) | ( ( $x + $y ) % 256 ) );
        }
    }
    if ( 'jpg' === $format ) {
        imagejpeg( $image, $path, 100 );
    } elseif ( 'gif' === $format ) {
        imagegif( $image, $path );
    } else {
        imagepng( $image, $path, 0 );
    }
}

function lc_fixture( string $format = 'png', bool $thumbnail = true, bool $special_name = false ): array {
    global $wpdb, $test_dir, $test_url, $fixture_ids;
    $name = ( $special_name ? '图片 %PATH% & ' : 'fixture-' ) . count( $fixture_ids );
    $path = $test_dir . '/' . $name . '.' . $format;
    if ( 'svg' === $format ) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="72"><rect width="96" height="72" fill="red"/></svg>';
        file_put_contents( $path, libre_compress_sanitize_svg_content( $svg ) );
    } else {
        lc_image( $path, $format );
    }
    $mime = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml' )[ $format ];
    $wpdb->insert( $wpdb->posts, array(
        'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $name,
        'post_mime_type' => $mime, 'guid' => $test_url . '/' . rawurlencode( basename( $path ) ),
    ) );
    $id = (int) $wpdb->insert_id;
    $fixture_ids[] = $id;
    update_attached_file( $id, $path );
    $metadata = array( 'file' => basename( $path ), 'width' => 96, 'height' => 72, 'filesize' => filesize( $path ), 'sizes' => array() );
    if ( $thumbnail && 'svg' !== $format ) {
        $thumb_path = $test_dir . '/' . $name . '-48x36.' . $format;
        lc_image( $thumb_path, $format, 48, 36 );
        $metadata['sizes']['lc-small'] = array( 'file' => basename( $thumb_path ), 'width' => 48, 'height' => 36, 'mime-type' => $mime, 'filesize' => filesize( $thumb_path ) );
    }
    wp_update_attachment_metadata( $id, $metadata );
    return array( $id, $path, hash_file( 'sha256', $path ) );
}

function lc_cleanup( string $directory ): void {
    global $test_dir;
    // 仅删除本轮目录的普通文件，遇到链接立即拒绝，不遍历外部路径。
    $real = realpath( $directory );
    $root = realpath( $test_dir );
    if ( ! $real || ! $root || is_link( $directory ) || 0 !== strpos( wp_normalize_path( $real ) . '/', wp_normalize_path( $root ) . '/' ) ) {
        throw new RuntimeException( '测试清理路径越界。' );
    }
    foreach ( new DirectoryIterator( $directory ) as $entry ) {
        if ( $entry->isDot() ) {
            continue;
        }
        if ( $entry->isLink() ) {
            throw new RuntimeException( '测试目录存在链接，拒绝清理。' );
        }
        if ( $entry->isDir() ) {
            lc_cleanup( $entry->getPathname() );
        } else {
            unlink( $entry->getPathname() );
        }
    }
    rmdir( $directory );
}

// 清理入口仅接受 uploads 下带有本测试标记的独立目录。
if ( '--cleanup-files' === ( $argv[2] ?? '' ) ) {
    $targets = array();
    foreach ( array_slice( $argv, 3 ) as $name ) {
        if ( ! preg_match( '/^libre-compress-tests-[A-Za-z0-9]{12}$/D', $name ) ) {
            throw new RuntimeException( '测试目录名不合法。' );
        }
        $directory = $uploads['basedir'] . '/' . $name;
        $marker = $directory . '/.libre-compress-test';
        if ( ! is_dir( $directory ) || is_link( $directory ) || is_link( $marker )
            || ! is_file( $marker ) || "LibreCompress regression fixtures\n" !== file_get_contents( $marker )
            || realpath( dirname( $directory ) ) !== realpath( $uploads['basedir'] ) ) {
            throw new RuntimeException( '目录不是可清理的测试目录。' );
        }
        $targets[] = $directory;
    }
    foreach ( $targets as $directory ) {
        $test_dir = $directory;
        lc_cleanup( $directory );
        echo '已清理：' . basename( $directory ) . "\n";
    }
    exit( 0 );
}

// 插件会更改这些表，非事务表会让测试数据无法可靠回滚。
foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $plugin->database->get_records_table(), $plugin->database->get_backups_table() ) as $table ) {
    $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
    if ( ! $status || 'InnoDB' !== $status->Engine ) {
        exit( "测试要求相关数据库表使用 InnoDB。\n" );
    }
}
wp_mkdir_p( $test_dir );
file_put_contents( $test_dir . '/.libre-compress-test', "LibreCompress regression fixtures\n" );
$upload_filter = static function ( $value ) use ( $test_dir, $test_url ) {
    $value['basedir'] = $value['path'] = $test_dir;
    $value['baseurl'] = $value['url'] = $test_url;
    $value['subdir'] = '';
    return $value;
};
add_filter( 'upload_dir', $upload_filter );
$wpdb->query( 'START TRANSACTION' );
$exit_code = 0;

try {
    $settings = new Libre_Compress_Settings();
    $expected_defaults = array( 'pngquant_speed' => 4, 'oxipng_level' => 2, 'webp_method' => 4, 'webp_lossless_level' => 6, 'gif2webp_method' => 4, 'avif_speed' => 6, 'gifsicle_level' => 2 );
    foreach ( Libre_Compress_Settings::SPEED_SETTINGS as $key => $range ) {
        list( $min, $max, $default ) = $range;
        lc_check( $expected_defaults[ $key ] === $default, $key . ' 均衡默认档位' );
        foreach ( array( null, array( 1 ), new stdClass(), '1;whoami', '1.5', true ) as $invalid ) {
            lc_check( $default === Libre_Compress_Settings::normalize_speed( $key, $invalid ), $key . ' 拒绝非法速度' );
        }
        lc_check( $min === Libre_Compress_Settings::normalize_speed( $key, '-999999999999999999999999' ), $key . ' 下限' );
        lc_check( $max === Libre_Compress_Settings::normalize_speed( $key, '999999999999999999999999' ), $key . ' 上限' );
        for ( $value = $min; $value <= $max; $value++ ) {
            $saved = $settings->sanitize_tools_settings( array( $key => (string) $value ) );
            lc_check( $value === $saved[ $key ], $key . ' 保存全部档位' );
        }
    }
    lc_check( ! $settings->sanitize_general_settings( array( 'original_fallback' => array( '1' ) ) )['original_fallback'], '回退拒绝数组输入' );
    lc_check( $settings->sanitize_general_settings( array( 'original_fallback' => '1' ) )['original_fallback'], '回退开启' );
    lc_check( ! $settings->sanitize_general_settings( array() )['original_fallback'], '回退关闭' );
    lc_check( ! $settings->sanitize_tools_settings( array( 'svg_multipass' => array( '1' ) ) )['svg_multipass'], '多轮优化拒绝数组输入' );
    lc_check( $settings->sanitize_tools_settings( array( 'svg_multipass' => '1' ) )['svg_multipass'], '多轮优化保存开启' );
    lc_check( ! $settings->sanitize_tools_settings( array() )['svg_multipass'], '多轮优化保存关闭' );
    lc_check( 1 === Libre_Compress_Settings::normalize_retention_days( 0 ), '保留时长跳过零' );
    lc_check( -1 === Libre_Compress_Settings::normalize_retention_days( -2 ), '永久保留' );
    echo "通过：设置校验与全部速度档位\n";

    $tools = $plugin->compressor->get_tools();
    foreach ( array( 'min', 'max' ) as $edge ) {
        $speeds = array();
        foreach ( Libre_Compress_Settings::SPEED_SETTINGS as $key => $range ) {
            $speeds[ $key ] = $range[ 'min' === $edge ? 0 : 1 ];
        }
        lc_options( array(), $speeds );
        $path = $test_dir . '/参数.png';
        lc_check( lc_argument( lc_call( $tools['pngquant'], 'build_command_chain', array( $path, array() ) )[0], '--speed' ) === (string) $speeds['pngquant_speed'], 'pngquant 命令' );
        lc_check( in_array( '-o' . $speeds['oxipng_level'], lc_call( $tools['oxipng'], 'build_command_chain', array( $path, array() ) )[0], true ), 'oxipng 命令' );
        lc_check( in_array( '-O' . $speeds['gifsicle_level'], lc_call( $tools['gifsicle'], 'build_command_chain', array( $path, array() ) )[0], true ), 'gifsicle 命令' );
        foreach ( array( 'lossy', 'lossless' ) as $mode ) {
            lc_options( array(), array_merge( $speeds, array( 'webp_mode' => $mode, 'avif_mode' => $mode ) ) );
            $flag = 'lossy' === $mode ? '-m' : '-z';
            $key = 'lossy' === $mode ? 'webp_method' : 'webp_lossless_level';
            lc_check( lc_argument( lc_call( $tools['cwebp'], 'build_command_chain', array( $path, array() ) )[0], $flag ) === (string) $speeds[ $key ], 'cwebp ' . $mode );
            lc_check( lc_argument( lc_call( $plugin->output_processor, 'build_encode_command', array( $path, $path . '.webp', 'webp' ) )[0], $flag ) === (string) $speeds[ $key ], '静态 WebP 转换 ' . $mode );
            lc_check( lc_argument( lc_call( $tools['avifenc'], 'build_command_chain', array( $path, array() ) )[1], '-s' ) === (string) $speeds['avif_speed'], '同格式 AVIF ' . $mode );
            lc_check( lc_argument( lc_call( $plugin->output_processor, 'build_encode_command', array( $path, $path . '.avif', 'avif' ) )[0], '-s' ) === (string) $speeds['avif_speed'], '静态 AVIF 转换 ' . $mode );
            foreach ( array( 'webp', 'avif' ) as $target ) {
                $chain = lc_call( $plugin->output_processor, 'build_animated_gif_command', array( $path, $path . '.' . $target, $target ) );
                lc_check( is_array( $chain ), '动画转换工具齐全 ' . $target );
                $command = $chain['command'][ 'webp' === $target ? 0 : 1 ];
                lc_check( lc_argument( $command, 'webp' === $target ? '-m' : '-s' ) === (string) $speeds[ 'webp' === $target ? 'gif2webp_method' : 'avif_speed' ], '动画速度参数 ' . $target . ' ' . $mode );
            }
        }
    }
    echo "通过：所有编码分支的速度命令参数\n";

    lc_options();
    lc_check( $plugin->backup->ensure_backup_dir(), '创建可公开备份目录' );
    $backup_dir = $plugin->backup->get_backup_dir();
    file_put_contents( $backup_dir . '/.htaccess', Libre_Compress_Backup::protection_htaccess_content() );
    file_put_contents( $backup_dir . '/web.config', '<configuration><system.webServer><authorization /></system.webServer></configuration>' );
    lc_check( $plugin->backup->ensure_backup_dir(), '改写访问限制文件' );
    lc_check( false === strpos( file_get_contents( $backup_dir . '/.htaccess' ), 'denied' ), 'Apache 不禁止访问' );
    lc_check( false === strpos( file_get_contents( $backup_dir . '/web.config' ), 'authorization' ), 'IIS 不禁止访问' );

    $fallback = new Libre_Compress_Fallback();
    $browser_images = array();
    foreach ( array( 'webp', 'avif' ) as $target ) {
        lc_options( array( 'output_png' => true, 'output_format' => $target ), array( 'avif_speed' => 10 ) );
        list( $id, $path, $hash ) = lc_fixture();
        $original_meta = wp_get_attachment_metadata( $id );
        $original_url = wp_get_attachment_url( $id );
        $wpdb->insert( $wpdb->posts, array( 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => '<img class="wp-image-' . $id . '" src="' . esc_url( $original_url ) . '">' ) );
        $post_id = (int) $wpdb->insert_id;
        $fixture_ids[] = $post_id;
        $result = $plugin->processor->compress_attachment( $id );
        lc_check( 'success' === $result['status'] && 2 === $result['success'], $target . ' 真实转换完整附件：' . wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) );
        lc_check( get_post_mime_type( $id ) === 'image/' . $target, $target . ' MIME 更新' );
        lc_check( ! is_file( $path ) && is_file( get_attached_file( $id ) ), $target . ' 源文件替换' );
        lc_check( 2 === count( $plugin->backup->get_backups( $id ) ), $target . ' 原图及缩略图备份' );
        lc_check( 'complete' === $plugin->processor->get_attachment_state( $id )['status'], $target . ' 完成状态' );
        lc_check( 'skipped' === $plugin->processor->compress_attachment( $id )['status'], $target . ' 不重复压缩' );
        $converted_url = wp_get_attachment_url( $id );
        lc_check( false !== strpos( get_post( $post_id )->post_content, '.' . $target ), $target . ' 文章引用更新' );
        $html = wp_get_attachment_image( $id, 'full' );
        lc_check( 1 === substr_count( $html, '<picture>' ), $target . ' 附件图片回退且不重复包装' );
        $image = new WP_HTML_Tag_Processor( $html );
        $image->next_tag( 'IMG' );
        $backup_url = $image->get_attribute( 'src' );
        lc_check( false !== strpos( $backup_url, '/libre-compress-backups/' ), $target . ' 使用备份原图' );
        $response = wp_remote_get( $backup_url );
        lc_check( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), $target . ' 备份通过 HTTP 可读取' );
        lc_check( hash( 'sha256', wp_remote_retrieve_body( $response ) ) === $hash, $target . ' HTTP 备份内容与原图一致' );
        lc_check( false === strpos( (string) $image->get_attribute( 'srcset' ), '.' . $target ), $target . ' 回退 srcset 不含新格式' );
        $source = new WP_HTML_Tag_Processor( $html );
        $source->next_tag( 'SOURCE' );
        lc_check( 'image/' . $target === $source->get_attribute( 'type' ), $target . ' source MIME' );
        lc_check( false !== strpos( (string) $source->get_attribute( 'srcset' ), '.' . $target ), $target . ' 新格式 srcset' );

        $raw = '<img class="wp-image-' . $id . '" src="' . esc_url( $converted_url ) . '" alt="中文 &amp; 测试" width="96" height="72" loading="lazy">';
        foreach ( array( 'the_content', 'the_excerpt', 'widget_text_content', 'widget_block_content' ) as $hook ) {
            $filtered = apply_filters( $hook, $raw );
            lc_check( 1 === substr_count( $filtered, '<picture>' ), $hook . ' 回退' );
        }
        $wrapped = $fallback->filter_content( $raw );
        lc_options( array( 'backup_enabled' => false ) );
        lc_check( $raw === $fallback->filter_content( $raw ), '关闭备份后即使历史备份存在也不回退' );
        lc_options();
        lc_check( $wrapped === $fallback->filter_content( $wrapped ), '重复过滤幂等' );
        $existing = '<picture><source type="image/' . $target . '" srcset="' . esc_url( $converted_url ) . '">' . $raw . '</picture>';
        lc_check( $existing === $fallback->filter_content( $existing ), '已有 picture 保持完整' );
        $surrounding = '<!-- <img src="x"> --><script>const t = "<img src=x>";</script><a href="/文章">' . $raw . '</a>';
        lc_check( str_replace( $raw, $wrapped, $surrounding ) === $fallback->filter_content( $surrounding ), '保留周围 HTML 和脚本文字' );
        $external = str_replace( $converted_url, str_replace( $uploads['baseurl'], 'https://example.invalid/wp-content/uploads', $converted_url ), $raw );
        lc_check( $external === $fallback->filter_content( $external ), '外站同路径图片不回退' );
        lc_check( '<img src="javascript:alert(1)">' === $fallback->filter_attachment_image( '<img src="javascript:alert(1)">', $id ), '拒绝非法协议' );
        lc_options( array( 'original_fallback' => false ) );
        lc_check( $raw === $fallback->filter_content( $raw ), '回退开关关闭' );
        lc_options();

        // 为浏览器验证保留独立副本，避免后续恢复测试清理素材。
        $browser_modern = 'browser-' . $target . '.' . $target;
        $browser_original = 'browser-' . $target . '.png';
        copy( get_attached_file( $id ), $test_dir . '/' . $browser_modern );
        $backups = $plugin->backup->get_backups( $id );
        $backup_path = $backups[0]['backup_path'];
        copy( $backup_path, $test_dir . '/' . $browser_original );
        $browser_images[] = '<picture><source type="image/' . $target . '" srcset="' . $browser_modern . '"><img src="' . $browser_original . '" alt="' . $target . '"></picture>';
        $browser_images[] = '<picture><source type="image/x-unsupported-test" srcset="' . $browser_modern . '"><img src="' . $browser_original . '" alt="原图回退"></picture>';

        lc_check( $plugin->processor->restore_attachment( $id ), $target . ' 恢复原图' );
        lc_check( hash_file( 'sha256', $path ) === $hash, $target . ' 恢复字节一致' );
        lc_check( get_post_mime_type( $id ) === 'image/png', $target . ' 恢复 MIME' );
        lc_check( empty( $plugin->output_processor->get_output_entries( $id ) ) && ! $plugin->backup->has_backup( $id ), $target . ' 恢复清理映射与备份' );
        lc_check( false !== strpos( get_post( $post_id )->post_content, '.png' ), $target . ' 恢复文章引用' );
    }
    echo "通过：WebP/AVIF 转换、前台回退、响应式候选与逐字节恢复\n";

    // 在两个端点实际调用所有同格式压缩工具，校验产物仍是正确格式。
    foreach ( array( 0, 1 ) as $edge ) {
        $speeds = array();
        foreach ( Libre_Compress_Settings::SPEED_SETTINGS as $key => $range ) {
            $speeds[ $key ] = $range[ $edge ];
        }
        foreach ( array( 'lossy', 'lossless' ) as $mode ) {
            lc_options( array(), array_merge( $speeds, array( 'jpeg_mode' => $mode, 'png_mode' => $mode, 'webp_mode' => $mode, 'avif_mode' => $mode, 'gif_mode' => $mode, 'svg_multipass' => (bool) $edge ) ) );
            lc_check( in_array( '--multipass', lc_call( $tools['svgo'], 'build_command_chain', array( $test_dir . '/参数.svg', array() ) )[0], true ) === (bool) $edge, 'SVGO 多轮优化命令' );
            foreach ( array( 'jpegoptim' => 'jpg', 'pngquant' => 'png', 'oxipng' => 'png', 'cwebp' => 'webp', 'avifenc' => 'avif', 'gifsicle' => 'gif', 'svgo' => 'svg' ) as $name => $format ) {
                if ( in_array( $format, array( 'webp', 'avif' ), true ) ) {
                    $source = $test_dir . '/源.png';
                    lc_image( $source );
                    $path = $test_dir . '/编码 ' . $edge . $mode . '.' . $format;
                    $chain = lc_call( $plugin->output_processor, 'build_encode_command', array( $source, $path, $format ) );
                    lc_check( Libre_Compress_Tool_Base::run_command( $chain[0], 120 )['success'], $format . ' 创建编码输入' );
                } else {
                    list( $id, $path ) = lc_fixture( $format, false, true );
                }
                $result = $tools[ $name ]->compress( $path );
                lc_check( $result['success'], $name . ' 真实压缩 ' . $mode . '/' . $edge . '：' . ( $result['message'] ?? '' ) );
                lc_check( is_file( $path ) && filesize( $path ) > 0, $name . ' 输出非空' );
                if ( 'svg' !== $format ) {
                    lc_check( false !== wp_get_image_mime( $path ), $name . ' 输出可识别' );
                }
            }
        }
    }
    echo "通过：七个本地压缩工具的有损/无损与速度端点实测\n";

    // 制作两帧 GIF，完整验证动画转换与帧数保护。
    $frame_a = $test_dir . '/frame-a.gif';
    $frame_b = $test_dir . '/frame-b.gif';
    lc_image( $frame_a, 'gif', 32, 24 );
    $second_frame = imagecreatetruecolor( 32, 24 );
    imagefilledrectangle( $second_frame, 0, 0, 31, 23, 0xff3366 );
    imagegif( $second_frame, $frame_b );
    $animated = $test_dir . '/animation.gif';
    lc_check( Libre_Compress_Tool_Base::run_command( array( $tools['gifsicle']->get_tool_binary_path(), '--delay=10', '--loopcount=0', $frame_a, $frame_b, '-o', $animated ), 30 )['success'], '生成两帧 GIF' );
    foreach ( array( 'lossy', 'lossless' ) as $mode ) {
        lc_options( array(), array( 'webp_mode' => $mode, 'avif_mode' => $mode, 'avif_speed' => 10, 'gif2webp_method' => 0 ) );
        foreach ( array( 'webp', 'avif' ) as $target ) {
            $output = $test_dir . '/animation-' . $mode . '.' . $target;
            $chain = lc_call( $plugin->output_processor, 'build_animated_gif_command', array( $animated, $output, $target ) );
            foreach ( $chain['command'] as $command ) {
                $result = Libre_Compress_Tool_Base::run_command( $command, 120 );
                lc_check( $result['success'], '动画 ' . $target . ' ' . $mode . '：' . $result['output'] );
            }
            if ( 'webp' === $target ) {
                lc_check( Libre_Compress_Compressor::is_animated_webp( $output ), 'WebP 动画保持多帧' );
            } else {
                $info = Libre_Compress_Tool_Base::run_command( array( $tools['avifenc']->get_decoder_path(), '--info', $output ), 30 );
                lc_check( lc_call( $tools['avifenc'], 'parse_frame_count', array( $info['output'] ) ) >= 2 && false !== strpos( $info['output'], '0.20 seconds' ), 'AVIF 动画保留多帧与时长' );
                $hash = hash_file( 'sha256', $output );
                lc_check( ! $tools['avifenc']->compress( $output )['success'] && $hash === hash_file( 'sha256', $output ), '多帧 AVIF 同格式压缩保持原图' );
            }
        }
    }
    echo "通过：动画 GIF 转换 WebP/AVIF 与多帧安全保护\n";

    lc_options( array( 'output_png' => true ) );
    list( $id, $path ) = lc_fixture();
    lc_check( 'success' === $plugin->processor->compress_attachment( $id )['status'], '准备备份失效测试' );
    $modern = '<img class="wp-image-' . $id . '" src="' . esc_url( wp_get_attachment_url( $id ) ) . '">';
    $rows = $plugin->backup->get_backups( $id );
    rename( $rows[0]['backup_path'], $rows[0]['backup_path'] . '.missing' );
    lc_check( $modern === $fallback->filter_content( $modern ), '原图备份缺失不生成死链' );
    rename( $rows[0]['backup_path'] . '.missing', $rows[0]['backup_path'] );
    $table = $plugin->database->get_backups_table();
    $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE attachment_id = %d", $id ) );
    $result = $plugin->processor->prune_expired_backup_page( 1, $id - 1 );
    lc_check( in_array( $id, $result['pruned_ids'], true ), '到期清理测试备份' );
    lc_check( $modern === $fallback->filter_content( $modern ), '备份到期停止回退' );
    lc_check( 'complete' === $plugin->processor->get_attachment_state( $id )['status'], '清理备份保留完成状态' );
    lc_check( ! empty( $plugin->output_processor->get_output_entries( $id ) ), '清理备份保留转换映射' );
    lc_check( ! $plugin->backup->create_backup( $id, __FILE__ ), '拒绝上传目录外备份' );

    // 从未启用备份的转换附件不能输出回退。
    lc_options( array( 'backup_enabled' => false, 'output_png' => true ) );
    list( $no_backup_id ) = lc_fixture( 'png', false );
    lc_check( 'success' === $plugin->processor->compress_attachment( $no_backup_id )['status'], '无备份转换成功' );
    lc_check( ! $plugin->backup->has_backup( $no_backup_id ), '未生成原图备份' );
    $no_backup_html = '<img class="wp-image-' . $no_backup_id . '" src="' . esc_url( wp_get_attachment_url( $no_backup_id ) ) . '">';
    lc_check( $no_backup_html === $fallback->filter_content( $no_backup_html ), '没有原图备份的转换图片不回退' );
    lc_options();
    lc_check( $no_backup_html === $fallback->filter_content( $no_backup_html ), '之后开启备份也不能凭空生成旧格式回退' );

    // 备份索引受到篡改时，不能把上传目录内普通文件公开为备份。
    list( $tamper_id, $tamper_path ) = lc_fixture( 'png', false );
    lc_check( $plugin->backup->create_backup( $tamper_id, $tamper_path ), '创建安全校验备份' );
    $wpdb->update( $table, array( 'backup_path' => basename( $tamper_path ) ), array( 'attachment_id' => $tamper_id ) );
    lc_check( '' === $plugin->backup->get_original_url( $tamper_id, $tamper_path ), '拒绝备份索引越界' );
    echo "通过：备份到期、缺失、篡改与路径边界\n";

    foreach ( array( 'jpg', 'gif', 'svg' ) as $format ) {
        list( $original_id, $original_path ) = lc_fixture( $format, false );
        lc_check( $plugin->backup->create_backup( $original_id, $original_path ), $format . ' 创建原图备份' );
        lc_check( '' !== $plugin->backup->get_original_url( $original_id, $original_path ), $format . ' 原图可回退' );
    }
    list( $native_id, $native_source ) = lc_fixture( 'png', false );
    $native_webp = $native_source . '.webp';
    $native_chain = lc_call( $plugin->output_processor, 'build_encode_command', array( $native_source, $native_webp, 'webp' ) );
    lc_check( Libre_Compress_Tool_Base::run_command( $native_chain[0], 30 )['success'], '准备原生 WebP' );
    lc_check( $plugin->backup->create_backup( $native_id, $native_webp ), '备份原生 WebP' );
    lc_check( '' === $plugin->backup->get_original_url( $native_id, $native_webp ), '原生 WebP 不能作为旧格式回退' );
    foreach ( array( 'webp', 'avif' ) as $native_format ) {
        list( $native_id, $native_source ) = lc_fixture( 'png', false );
        $native_path = $native_source . '.' . $native_format;
        $chain = lc_call( $plugin->output_processor, 'build_encode_command', array( $native_source, $native_path, $native_format ) );
        lc_check( Libre_Compress_Tool_Base::run_command( $chain[0], 30 )['success'], '创建原生 ' . $native_format );
        update_attached_file( $native_id, $native_path );
        $wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/' . $native_format ), array( 'ID' => $native_id ) );
        clean_post_cache( $native_id );
        wp_update_attachment_metadata( $native_id, array( 'file' => basename( $native_path ), 'width' => 96, 'height' => 72, 'sizes' => array() ) );
        lc_check( 'success' === $plugin->processor->compress_attachment( $native_id )['status'], '原生 ' . $native_format . ' 同格式压缩' );
        lc_check( $plugin->backup->has_backup( $native_id ), '原生新格式具有备份' );
        lc_check( empty( $plugin->output_processor->get_output_entries( $native_id ) ), '同格式压缩没有转换映射' );
        $native_html = '<img class="wp-image-' . $native_id . '" src="' . esc_url( wp_get_attachment_url( $native_id ) ) . '">';
        lc_check( $native_html === $fallback->filter_content( $native_html ), '原生新格式同格式压缩不提供旧格式回退' );
    }

    list( $unsafe_svg_id, $unsafe_svg_path ) = lc_fixture( 'svg', false );
    file_put_contents( $unsafe_svg_path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
    lc_check( $plugin->backup->create_backup( $unsafe_svg_id, $unsafe_svg_path ), '准备不安全历史 SVG 备份' );
    lc_check( '' === $plugin->backup->get_original_url( $unsafe_svg_id, $unsafe_svg_path ), '不安全 SVG 备份不加入回退' );
    echo "通过：JPG/GIF/SVG 回退与原生新格式、SVG 安全校验\n";

    lc_options( array( 'disabled_thumbnail_sizes' => array( 'lc-small' ) ) );
    list( $thumb_id, $thumb_path ) = lc_fixture();
    $before = wp_get_attachment_metadata( $thumb_id );
    $outcome = lc_call( $plugin->thumbnail_manager, 'delete_disabled_for_attachment', array( $thumb_id ) );
    lc_check( is_array( $outcome ) && 1 === $outcome['deleted'], '删除未启用测试缩略图' );
    lc_check( empty( wp_get_attachment_metadata( $thumb_id )['sizes'] ), '同步缩略图元数据' );
    lc_options();
    add_image_size( 'lc-small', 48, 36, false );
    $outcome = lc_call( $plugin->thumbnail_manager, 'generate_missing_for_attachment', array( $thumb_id, array( 'lc-small' ), wp_get_registered_image_subsizes() ) );
    lc_check( ! empty( wp_get_attachment_metadata( $thumb_id )['sizes']['lc-small'] ), '补生成测试缩略图' );
    remove_image_size( 'lc-small' );
    echo "通过：缩略图删除与补生成回归\n";

    lc_options();
    foreach ( array( 'general', 'tools' ) as $tab ) {
        $_GET['tab'] = $tab;
        ob_start();
        $settings->render_settings_page();
        $page = ob_get_clean();
        if ( 'general' === $tab ) {
            lc_check( strpos( $page, '[backup_retention_days]' ) < strpos( $page, '[original_fallback]' ) && strpos( $page, '[original_fallback]' ) < strpos( $page, '[strip_metadata]' ), '回退设置位于保留时长下方' );
            lc_check( false !== strpos( $page, '为不支持新格式的浏览器提供备份原图' ) && false === strpos( $page, '为不支持 WebP / AVIF' ), '回退文案使用新格式' );
        } else {
            lc_check( false === strpos( $page, '<h3>压缩速度</h3>' ), '不再单独提供压缩速度区域' );
            foreach ( Libre_Compress_Settings::SPEED_SETTINGS as $key => $range ) {
                lc_check( false !== strpos( $page, 'libre_compress_tools[' . $key . ']' ), '设置页包含速度项 ' . $key );
            }
        }
        file_put_contents( $test_dir . '/settings-' . $tab . '.html', '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="' . admin_url( 'load-styles.php?c=0&dir=ltr&load=common,forms,admin-menu,dashboard,buttons' ) . '"><body class="wp-admin">' . $page . '</body></html>' );
    }
    file_put_contents( $test_dir . '/browser.html', '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>图片回退测试</title><body>' . implode( '', $browser_images ) . '</body></html>' );
    echo "通过：中文设置页面与全部控件渲染\n";
    echo '总计通过 ' . $checks . " 项断言。\n";
    if ( $keep_files ) {
        echo '浏览器测试：' . $test_url . "/browser.html\n";
        echo '测试目录：' . $test_dir . "\n";
    }
} catch ( Throwable $error ) {
    $exit_code = 1;
    fwrite( STDERR, '失败：' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
    if ( $keep_files ) {
        fwrite( STDERR, '测试目录：' . $test_dir . "\n" );
    }
} finally {
    $wpdb->query( 'ROLLBACK' );
    foreach ( $option_keys as $key ) {
        wp_cache_delete( $key, 'options' );
    }
    wp_cache_delete( 'alloptions', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    foreach ( $fixture_ids as $id ) {
        clean_post_cache( $id );
    }
    remove_filter( 'upload_dir', $upload_filter );
    if ( ! $keep_files ) {
        lc_cleanup( $test_dir );
    }
}
exit( $exit_code );
