<?php
/**
 * 备份恢复类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 备份恢复类
 *
 * 负责管理图片备份和恢复功能
 */
class Libre_Compress_Backup {

    /**
     * 备份目录名称
     *
     * @var string
     */
    const BACKUP_DIR_NAME = 'libre-compress-backups';

    /**
     * 构造函数
     */
    public function __construct() {
        // 确保备份目录存在
        $this->ensure_backup_dir();
    }

    /**
     * 目录访问保护 .htaccess 内容
     *
     * 新旧 Apache 双兼容（2.4 走 Require，2.2 走 Order/Deny），
     * OpenLiteSpeed 同样识别 .htaccess 的 IfModule 条件与这两套访问控制写法。
     *
     * @return string
     */
    public static function protection_htaccess_content(): string {
        return "# 禁止直接访问\n"
            . "<IfModule mod_authz_core.c>\n"
            . "  Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "  Order deny,allow\n"
            . "  Deny from all\n"
            . "</IfModule>\n";
    }

    /**
     * 获取上传目录根路径（纯正斜杠、保留原大小写）
     *
     * @return string
     */
    private function upload_basedir(): string {
        $upload_dir = wp_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';

        if ( '' === $basedir ) {
            return '';
        }

        return untrailingslashit( wp_normalize_path( $basedir ) );
    }

    /**
     * 规范化路径用于不区分大小写的比较
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 绝对路径转 uploads 目录内的相对路径
     *
     * 数据库一律只存相对路径：站点换目录或迁移到别的服务器后，存的绝对路径会全部指向
     * 不存在的文件，格式转换后仅存的原图备份就再也恢复不了。备份文件必然位于 uploads 内，
     * 相对路径承载的信息是完整的。
     *
     * @param string $absolute_path 绝对路径
     * @return string 不在 uploads 内或路径非法时返回空字符串
     */
    private function to_relative_path( string $absolute_path ): string {
        $basedir = $this->upload_basedir();

        if ( '' === $basedir ) {
            return '';
        }

        $normalized = wp_normalize_path( $absolute_path );

        if ( 0 !== strpos( $this->normalized_path( $normalized ), $this->normalized_path( $basedir ) . '/' ) ) {
            return '';
        }

        $relative = ltrim( substr( $normalized, strlen( $basedir ) ), '/' );

        // 持久化数据入库前逐项校验：不允许回溯、不允许带盘符或协议。
        if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, ':' ) ) {
            return '';
        }

        return $relative;
    }

    /**
     * 相对路径还原为绝对路径
     *
     * @param string $relative_path uploads 目录内的相对路径
     * @return string 路径非法或上传目录不可用时返回空字符串
     */
    private function to_absolute_path( string $relative_path ): string {
        $basedir = $this->upload_basedir();
        $relative = ltrim( wp_normalize_path( $relative_path ), '/' );

        if ( '' === $basedir || '' === $relative ) {
            return '';
        }

        if ( false !== strpos( $relative, '..' ) || false !== strpos( $relative, ':' ) ) {
            return '';
        }

        return $basedir . '/' . $relative;
    }

    /**
     * 获取备份目录路径
     *
     * @return string 备份目录绝对路径
     */
    public function get_backup_dir(): string {
        return $this->upload_basedir() . '/' . self::BACKUP_DIR_NAME;
    }

    /**
     * 确保备份目录存在
     *
     * @return bool 是否成功
     */
    public function ensure_backup_dir(): bool {
        $backup_dir = $this->get_backup_dir();

        if ( '' === $this->upload_basedir() || is_link( $backup_dir ) ) {
            return false;
        }

        if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
            return false;
        }

        // 备份直接用于浏览器回退，改写访问限制文件而不删除已有文件。
        $directory_files = array(
            '.htaccess'  => "# 原图备份允许直接访问\nOptions -Indexes\n",
            'index.php'  => '<?php // 禁止目录列表，原图文件允许访问。',
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><directoryBrowse enabled=\"false\" /></system.webServer></configuration>",
        );

        foreach ( $directory_files as $filename => $content ) {
            $path = $backup_dir . '/' . $filename;
            if ( is_link( $path ) ) {
                return false;
            }
            if ( is_file( $path ) && file_get_contents( $path ) === $content ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            if ( false === file_put_contents( $path, $content ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 生成备份文件相对路径
     *
     * @param int    $attachment_id   附件 ID
     * @param string $relative_source 原文件的 uploads 相对路径
     * @return string 备份文件相对路径
     */
    private function generate_backup_path( int $attachment_id, string $relative_source ): string {
        // 随机令牌避免备份路径被 predictable 拼接枚举。
        return sprintf(
            '%s/%d_%s_%s',
            self::BACKUP_DIR_NAME,
            $attachment_id,
            wp_generate_password( 16, false, false ),
            basename( $relative_source )
        );
    }

    /**
     * 创建备份
     *
     * 同一原始路径只保留第一次备份：文件压缩后体积必然变小，若按当前大小重建
     * 备份，最初原图会被压缩结果顶替，之后再也回不到原图。
     *
     * 文件操作一律用绝对路径，落库一律存 uploads 相对路径。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     原文件绝对路径
     * @return bool 是否成功
     */
    public function create_backup( int $attachment_id, string $file_path ): bool {
        // 验证文件存在
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        // 验证文件路径安全性
        if ( ! $this->is_safe_path( $file_path ) ) {
            return false;
        }

        $relative = $this->to_relative_path( $file_path );

        if ( '' === $relative ) {
            return false;
        }

        $database = libre_compress()->database;
        $existing = $database->get_backup_by_attachment_and_path( $attachment_id, $relative );

        if ( $existing ) {
            $backup_path = $this->to_absolute_path( (string) $existing['backup_path'] );

            // 备份文件必须真实存在且位于备份目录内，否则不可信，不能当作原图。
            if ( '' !== $backup_path && $this->is_backup_file_path( $backup_path ) && file_exists( $backup_path ) ) {
                return true;
            }

            // 只清理失效索引，指向备份目录之外的文件一律不删。
            $database->delete_backup( $existing['id'] );
        }

        // 确保备份目录存在
        if ( ! $this->ensure_backup_dir() ) {
            return false;
        }

        $backup_relative = $this->generate_backup_path( $attachment_id, $relative );
        $backup_path     = $this->to_absolute_path( $backup_relative );

        if ( '' === $backup_path ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $file_path, $backup_path ) || ! $this->files_match( $file_path, $backup_path ) ) {
            if ( file_exists( $backup_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $backup_path );
            }
            return false;
        }

        // 备份目录允许直接访问、浏览器原图回退引用的也是备份文件，
        // 按选项先删掉备份里的元数据再落库。清理失败不终止：
        // 保留带元数据的备份也好过没有备份，恢复功能不受损。
        if ( Libre_Compress_Settings::strips_backup_metadata() ) {
            $this->strip_backup_metadata( $backup_path );
        }

        // 保存备份记录；数据库写入失败时删除孤立文件并终止后续破坏性操作。
        $record_id = $database->add_backup(
            array(
                'attachment_id' => $attachment_id,
                'original_path' => $relative,
                'backup_path'   => $backup_relative,
            )
        );

        if ( ! $record_id ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $backup_path );
            return false;
        }

        return true;
    }

    /**
     * 删除备份文件里的元数据
     *
     * 备份目录允许直接访问、浏览器原图回退引用的也是备份文件，
     * EXIF 里的 GPS 位置、设备型号、作者这类隐私数据不能跟着备份一起送出去。
     *
     * 只摘除元数据载体，图像数据原样保留；格式不支持或解析失败时返回 false，
     * 由调用方保留原备份——清理失败不应让备份和压缩整体失败。
     *
     * @param string $file_path 备份文件绝对路径
     * @return bool 是否完成清理
     */
    private function strip_backup_metadata( string $file_path ): bool {
        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            return false;
        }

        // wp_get_image_mime 认不出 SVG，按扩展名单独分支
        if ( 'svg' === strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) ) {
            return $this->strip_svg_metadata( $file_path );
        }

        switch ( wp_get_image_mime( $file_path ) ) {
            case 'image/jpeg':
                return $this->strip_jpeg_metadata( $file_path );
            case 'image/png':
                return $this->strip_png_metadata( $file_path );
            case 'image/gif':
                return $this->strip_gif_metadata( $file_path );
            case 'image/webp':
                return $this->strip_webp_metadata( $file_path );
            default:
                return false;
        }
    }

    /**
     * 删除 JPEG 备份的元数据段
     *
     * 逐段复制标记结构，只丢弃 APP1（EXIF 与 XMP）、APP13（IPTC）和 COM（注释）；
     * APP0（JFIF）、APP2（ICC 色彩配置）、量化表、哈夫曼表和帧头一律保留。
     * 遇到 SOS 说明其后是熵编码数据，剩余字节整体原样复制，图像数据一个字节都不动。
     *
     * @param string $file_path JPEG 文件路径
     * @return bool 是否完成清理
     */
    private function strip_jpeg_metadata( string $file_path ): bool {
        $contents = file_get_contents( $file_path );

        if ( false === $contents || strlen( $contents ) < 4 ) {
            return false;
        }

        // SOI 开头才是能按段解析的 JPEG
        if ( "\xFF\xD8" !== substr( $contents, 0, 2 ) ) {
            return false;
        }

        $output = "\xFF\xD8";
        $offset = 2;
        $total  = strlen( $contents );

        while ( $offset + 4 <= $total ) {
            if ( "\xFF" !== $contents[ $offset ] ) {
                return false;
            }

            $marker = ord( $contents[ $offset + 1 ] );

            // SOS 后的熵编码流里任何字节序列都不再解析，连同剩余部分原样带走
            if ( 0xDA === $marker ) {
                return $this->replace_stripped_file( $file_path, $output . substr( $contents, $offset ) );
            }

            // 段前允许 FF 填充字节，逐个跳过
            if ( 0xFF === $marker ) {
                $offset++;
                continue;
            }

            // SOI、EOI、C8、RST、TEM 这类无长度标记出现在段区间即视为损坏
            if ( $marker < 0xC0 || in_array( $marker, array( 0xC8, 0xD8, 0xD9 ), true ) ) {
                return false;
            }

            $segment_length = unpack( 'n', substr( $contents, $offset + 2, 2 ) )[1];

            if ( $segment_length < 2 || $offset + 2 + $segment_length > $total ) {
                return false;
            }

            if ( ! in_array( $marker, array( 0xE1, 0xED, 0xFE ), true ) ) {
                $output .= substr( $contents, $offset, 2 + $segment_length );
            }

            $offset += 2 + $segment_length;
        }

        // 没遇到 SOS 就是异常结构，保留原备份
        return false;
    }

    /**
     * 删除 PNG 备份的元数据文本块
     *
     * 只丢弃 tEXt、zTXt、iTXt、eXIf、tIME 这些承载文字、时间与 EXIF 的辅助块；
     * ICC 色彩配置（iCCP）、尺寸、调色板、透明通道与 APNG 动图块全部保留，
     * IDAT 里的图像数据连同 CRC 原样不动。
     *
     * @param string $file_path PNG 文件路径
     * @return bool 是否完成清理
     */
    private function strip_png_metadata( string $file_path ): bool {
        $contents = file_get_contents( $file_path );

        if ( false === $contents || strlen( $contents ) < 8 ) {
            return false;
        }

        $signature = "\x89PNG\r\n\x1a\n";

        if ( 0 !== substr_compare( $contents, $signature, 0, 8 ) ) {
            return false;
        }

        $output   = $signature;
        $offset   = 8;
        $total    = strlen( $contents );
        $stripped = false;

        // 每个块都是 4 字节长度 + 4 字节类型 + 数据 + 4 字节 CRC
        while ( $offset + 12 <= $total ) {
            $chunk_length = unpack( 'N', substr( $contents, $offset, 4 ) )[1];
            $chunk_type   = substr( $contents, $offset + 4, 4 );
            $chunk_total  = 12 + $chunk_length;

            if ( $offset + $chunk_total > $total ) {
                return false;
            }

            if ( in_array( $chunk_type, array( 'tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME' ), true ) ) {
                $stripped = true;
            } else {
                $output .= substr( $contents, $offset, $chunk_total );
            }

            $offset += $chunk_total;
        }

        // 必须正好以 IEND 收尾且确实删掉了块，否则不动原备份
        if ( ! $stripped || $offset !== $total || 'IEND' !== substr( $output, -8, 4 ) ) {
            return false;
        }

        return $this->replace_stripped_file( $file_path, $output );
    }

    /**
     * 删除 GIF 备份的注释扩展块
     *
     * GIF 的元数据主要放在注释扩展（0x21 0xFE）里，常见作者与制作工具信息；
     * 注释扩展由若干数据子块加 0x00 结束符组成，整段摘除即可。
     * 图形控制、应用程序（含 NETSCAPE 循环标记）和图像数据块全部保留。
     *
     * @param string $file_path GIF 文件路径
     * @return bool 是否完成清理
     */
    private function strip_gif_metadata( string $file_path ): bool {
        $contents = file_get_contents( $file_path );

        if ( false === $contents || strlen( $contents ) < 13 ) {
            return false;
        }

        $header = substr( $contents, 0, 6 );

        if ( 'GIF87a' !== $header && 'GIF89a' !== $header ) {
            return false;
        }

        $output   = $header;
        $offset   = 6;
        $total    = strlen( $contents );
        $stripped = false;

        // 逻辑屏幕描述符 7 字节；全局调色板按 2^(色深+1) 项、每项 3 字节
        $output .= substr( $contents, 6, 7 );
        $offset  = 13;

        $global_packed = ord( $contents[ 10 ] );

        if ( $global_packed & 0x80 ) {
            $global_table_size = 3 * ( 1 << ( ( $global_packed & 0x07 ) + 1 ) );

            if ( $offset + $global_table_size > $total ) {
                return false;
            }

            $output .= substr( $contents, $offset, $global_table_size );
            $offset += $global_table_size;
        }

        while ( $offset < $total ) {
            $block_type = ord( $contents[ $offset ] );

            // 0x3B 是文件结束符，连同剩余字节原样带走并收尾
            if ( 0x3B === $block_type ) {
                $output .= substr( $contents, $offset );
                $offset  = $total;
                break;
            }

            if ( 0x21 === $block_type ) {
                if ( $offset + 2 > $total ) {
                    return false;
                }

                $label    = ord( $contents[ $offset + 1 ] );
                $position = $offset + 2;

                // 扩展块由若干数据子块加结束符组成，逐个跳过定位结束位置
                while ( $position < $total && 0x00 !== ord( $contents[ $position ] ) ) {
                    $sub_length = ord( $contents[ $position ] );

                    if ( $position + 1 + $sub_length > $total ) {
                        return false;
                    }

                    $position += 1 + $sub_length;
                }

                if ( $position >= $total ) {
                    return false;
                }

                $block_end = $position + 1;

                if ( 0xFE === $label ) {
                    $stripped = true;
                } else {
                    $output .= substr( $contents, $offset, $block_end - $offset );
                }

                $offset = $block_end;
                continue;
            }

            if ( 0x2C === $block_type ) {
                if ( $offset + 10 > $total ) {
                    return false;
                }

                $local_packed = ord( $contents[ $offset + 9 ] );
                $position     = $offset + 10;

                // 局部调色板按同样的公式跳过
                if ( $local_packed & 0x80 ) {
                    $position += 3 * ( 1 << ( ( $local_packed & 0x07 ) + 1 ) );
                }

                // LZW 最小码长字节 + 图像数据子块
                if ( $position >= $total ) {
                    return false;
                }

                $position++;

                while ( $position < $total && 0x00 !== ord( $contents[ $position ] ) ) {
                    $sub_length = ord( $contents[ $position ] );

                    if ( $position + 1 + $sub_length > $total ) {
                        return false;
                    }

                    $position += 1 + $sub_length;
                }

                if ( $position >= $total ) {
                    return false;
                }

                $block_end = $position + 1;
                $output   .= substr( $contents, $offset, $block_end - $offset );
                $offset    = $block_end;
                continue;
            }

            // 无法识别的块类型：无法安全判断边界，保留原备份
            return false;
        }

        if ( ! $stripped || 0x3B !== ord( substr( $output, -1 ) ) ) {
            return false;
        }

        return $this->replace_stripped_file( $file_path, $output );
    }

    /**
     * 删除 WebP 备份的元数据 chunk
     *
     * RIFF 容器里 EXIF 与 XMP 是两个独立 chunk，整块摘除即可；
     * VP8/VP8L/VP8X、ANIM/ANMF、ALPH、ICCP 等图像与调色 chunk 全部保留。
     *
     * @param string $file_path WebP 文件路径
     * @return bool 是否完成清理
     */
    private function strip_webp_metadata( string $file_path ): bool {
        $contents = file_get_contents( $file_path );

        if ( false === $contents || strlen( $contents ) < 12 ) {
            return false;
        }

        if ( 'RIFF' !== substr( $contents, 0, 4 ) || 'WEBP' !== substr( $contents, 8, 4 ) ) {
            return false;
        }

        $output   = substr( $contents, 0, 12 );
        $offset   = 12;
        $total    = strlen( $contents );
        $stripped = false;

        // 每个 chunk 是 4 字节类型 + 4 字节长度 + 数据，数据按偶数字节对齐
        while ( $offset + 8 <= $total ) {
            $chunk_type   = substr( $contents, $offset, 4 );
            $chunk_length = unpack( 'V', substr( $contents, $offset + 4, 4 ) )[1];
            $chunk_total  = 8 + $chunk_length + ( $chunk_length % 2 );

            if ( $offset + $chunk_total > $total ) {
                return false;
            }

            if ( 'EXIF' === $chunk_type || 'XMP ' === $chunk_type ) {
                $stripped = true;
            } else {
                $output .= substr( $contents, $offset, $chunk_total );
            }

            $offset += $chunk_total;
        }

        if ( ! $stripped || $offset !== $total ) {
            return false;
        }

        // RIFF 头里的总长度要按清理后的实际大小重写
        $stripped_contents = substr( $output, 0, 4 ) . pack( 'V', strlen( $output ) - 8 ) . substr( $output, 8 );

        return $this->replace_stripped_file( $file_path, $stripped_contents );
    }

    /**
     * 删除 SVG 备份的元数据
     *
     * SVG 的元数据散在 <metadata>、注释和编辑器私有属性里，手写 XML 清理容易破坏文件；
     * 直接复用压缩用的 svgo，其默认预设就会移除这些内容。svgo 不可用时保留原备份。
     *
     * @param string $file_path SVG 文件路径
     * @return bool 是否完成清理
     */
    private function strip_svg_metadata( string $file_path ): bool {
        $tools = libre_compress()->compressor->get_tools();

        if ( ! isset( $tools['svgo'] ) || ! $tools['svgo']->is_tool_available() ) {
            return false;
        }

        $result = $tools['svgo']->compress( $file_path );

        return ! empty( $result['success'] );
    }

    /**
     * 用清理后的内容替换备份文件
     *
     * 清理只会让文件变小；结果反而变大、变空或不再是同格式图片，
     * 都说明解析出了问题，这时必须保留原备份——拿错结果覆盖原图比留着元数据更糟。
     *
     * @param string $file_path 备份文件绝对路径
     * @param string $contents  清理后的文件内容
     * @return bool 是否完成替换
     */
    private function replace_stripped_file( string $file_path, string $contents ): bool {
        clearstatcache( true, $file_path );

        if ( '' === $contents || ! is_file( $file_path ) || strlen( $contents ) >= filesize( $file_path ) ) {
            return false;
        }

        $temp_path = $file_path . '.lc-strip-' . wp_generate_password( 12, false, false );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === file_put_contents( $temp_path, $contents ) ) {
            if ( file_exists( $temp_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $temp_path );
            }

            return false;
        }

        // 清理结果必须仍是同格式图片，防止把不完整的结果落成备份
        if ( wp_get_image_mime( $temp_path ) !== wp_get_image_mime( $file_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_path );

            return false;
        }

        if ( file_exists( $file_path ) && ! unlink( $file_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_path );

            return false;
        }

        if ( ! rename( $temp_path, $file_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_path );

            return false;
        }

        clearstatcache( true, $file_path );

        return filesize( $file_path ) === strlen( $contents );
    }

    /**
     * 获取附件的备份索引
     *
     * 落库的是 uploads 相对路径，对外统一还原成绝对路径，调用方无需关心存储形态。
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_backups( int $attachment_id ): array {
        $rows   = libre_compress()->database->get_backups_by_attachment( $attachment_id );
        $result = array();

        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $row['original_path'] = $this->to_absolute_path( (string) $row['original_path'] );
            $row['backup_path']   = $this->to_absolute_path( (string) $row['backup_path'] );

            $result[] = $row;
        }

        return $result;
    }

    public function get_backups_batch( array $attachment_ids ): ?array {
        $rows = libre_compress()->database->get_backups_by_attachments( $attachment_ids );
        if ( null === $rows ) {
            return null;
        }
        $result = array();
        foreach ( $rows as $row ) {
            $row['original_path'] = $this->to_absolute_path( (string) $row['original_path'] );
            $row['backup_path'] = $this->to_absolute_path( (string) $row['backup_path'] );
            $result[ (int) $row['attachment_id'] ][] = $row;
        }
        return $result;
    }

    /**
     * 获取仍然存在且可安全公开的原图备份 URL。
     */
    public function get_original_url( int $attachment_id, string $original_path, ?array $backups = null ): string {
        foreach ( $backups ?? $this->get_backups( $attachment_id ) as $backup ) {
            if ( $this->normalized_path( $backup['original_path'] ) !== $this->normalized_path( $original_path ) ) {
                continue;
            }

            $path = $backup['backup_path'];
            if ( ! $this->is_backup_file_path( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
                return '';
            }

            if ( ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'png', 'gif', 'svg' ), true ) ) {
                return '';
            }

            $mime = wp_get_image_mime( $path );
            if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
                // SVG 原图仅在不含任何需清理内容时公开，防止不安全备份成为脚本入口。
                if ( 'svg' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                    return '';
                }
                $content = file_get_contents( $path );
                if ( false === $content || libre_compress_sanitize_svg_content( $content ) !== $content ) {
                    return '';
                }
            }

            $relative = $this->to_relative_path( $path );
            $uploads  = wp_upload_dir();
            return '' !== $relative ? trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) ) : '';
        }

        return '';
    }

    /**
     * 将备份内容写回原始路径
     *
     * 先落到临时文件并校验，再替换目标文件，任何一步失败都不会破坏现用文件。
     *
     * @param array $backup         备份索引行
     * @param bool  $allow_existing 原路径已有文件时是否允许覆盖；该文件不是本附件现用文件时必须为 false
     * @return bool 原路径内容是否已与备份一致
     */
    public function restore_backup_file( array $backup, bool $allow_existing ): bool {
        $backup_path   = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';
        $original_path = isset( $backup['original_path'] ) ? (string) $backup['original_path'] : '';

        if ( '' === $backup_path || '' === $original_path ) {
            return false;
        }

        // 备份记录属于不可信持久化数据，读取和覆盖前都要重新验证路径。
        if ( ! $this->is_backup_file_path( $backup_path ) || ! file_exists( $backup_path ) ) {
            return false;
        }

        if ( ! $this->is_safe_destination_path( $original_path ) ) {
            return false;
        }

        if ( file_exists( $original_path ) ) {
            // 已经一致时直接成功，让中断后的重试可以幂等继续。
            if ( $this->files_match( $backup_path, $original_path ) ) {
                return true;
            }

            if ( ! $allow_existing ) {
                // 原路径被其他内容占用，覆盖会误删别人的图片。
                return false;
            }
        }

        $restore_temp = $original_path . '.lc-restore-' . wp_generate_password( 12, false, false );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $backup_path, $restore_temp ) || ! $this->files_match( $backup_path, $restore_temp ) ) {
            if ( file_exists( $restore_temp ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $restore_temp );
            }
            return false;
        }

        if ( file_exists( $original_path ) && ! unlink( $original_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $restore_temp );
            return false;
        }

        // 先清空目标再改名落位，兼容 Windows 上 rename 不覆盖已有文件的行为。
        if ( ! rename( $restore_temp, $original_path ) ) {
            if ( file_exists( $restore_temp ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $restore_temp );
            }
            return false;
        }

        return $this->files_match( $backup_path, $original_path );
    }

    /**
     * 比较两个文件的内容是否一致
     *
     * @param string $left  文件路径
     * @param string $right 文件路径
     * @return bool
     */
    private function files_match( string $left, string $right ): bool {
        if ( ! file_exists( $left ) || ! file_exists( $right ) ) {
            return false;
        }

        clearstatcache( true, $left );
        clearstatcache( true, $right );

        if ( filesize( $left ) !== filesize( $right ) ) {
            return false;
        }

        return hash_file( 'sha256', $left ) === hash_file( 'sha256', $right );
    }

    /**
     * 删除附件的备份文件和索引
     *
     * 任一备份删除失败时保留对应索引和后续清理，保证还能重试。
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部删除成功
     */
    public function delete_backup( int $attachment_id ): bool {
        Libre_Compress_Fallback::invalidate_attachment( $attachment_id );
        $database = libre_compress()->database;
        $backups  = $this->get_backups( $attachment_id );
        $success  = true;

        foreach ( $backups as $backup ) {
            // get_backups() 已把索引里的相对路径还原成绝对路径，这里直接使用。
            $backup_path = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';

            if ( '' === $backup_path || ! $this->is_backup_file_path( $backup_path ) ) {
                // 路径为空、已损坏，或指向备份目录之外（可能被篡改）：绝不删除该文件。
                // 但索引必须清掉——它永远定位不到备份文件，留着只会让恢复流程每次都卡在这一行。
                if ( ! $database->delete_backup( $backup['id'] ) ) {
                    $success = false;
                }
                continue;
            }

            if ( file_exists( $backup_path ) && ! unlink( $backup_path ) ) {
                $success = false;
                continue;
            }

            if ( ! $database->delete_backup( $backup['id'] ) ) {
                $success = false;
            }
        }

        Libre_Compress_Fallback::invalidate_attachment( $attachment_id );
        return $success;
    }

    /**
     * 检查附件是否有可用的备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否有备份
     */
    public function has_backup( int $attachment_id ): bool {
        foreach ( $this->get_backups( $attachment_id ) as $backup ) {
            $backup_path = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';

            if ( $this->is_backup_file_path( $backup_path ) && file_exists( $backup_path ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取附件的备份信息
     *
     * @param int $attachment_id 附件 ID
     * @return array 备份信息列表
     */
    public function get_backup_info( int $attachment_id ): array {
        $info = array();

        foreach ( $this->get_backups( $attachment_id ) as $backup ) {
            $backup_path = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';
            $usable      = $this->is_backup_file_path( $backup_path ) && file_exists( $backup_path );

            $info[] = array(
                'original_path' => $backup['original_path'],
                'backup_path'   => $backup_path,
                'backup_size'   => $usable ? (int) filesize( $backup_path ) : 0,
                'created_at'    => $backup['created_at'],
            );
        }

        return $info;
    }

    /**
     * 删除所有备份
     *
     * 删除失败或索引异常时保留记录，避免丢失对残留文件的追踪。
     *
     * @return int 删除的备份数量
     */
    public function delete_all_backups(): int {
        $database = libre_compress()->database;
        $backups  = $database->get_all_backups();
        $count    = 0;

        foreach ( $backups as $backup ) {
            // get_all_backups() 返回的是库里的原始相对路径，这里才需要还原成绝对路径。
            $backup_path = $this->to_absolute_path( isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '' );

            if ( '' === $backup_path || ! $this->is_backup_file_path( $backup_path ) ) {
                // 路径损坏或指向备份目录之外：文件一律不动，但索引要清掉，
                // 否则"删除所有备份"之后还会留下一批永远定位不到文件的索引。
                $database->delete_backup( $backup['id'] );
                continue;
            }

            if ( file_exists( $backup_path ) && ! unlink( $backup_path ) ) {
                continue;
            }

            if ( $database->delete_backup( $backup['id'] ) ) {
                $count++;
            }
        }

        // 批量操作结束后全量切换缓存命名空间，覆盖异常中断或旧缓存残留。
        Libre_Compress_Fallback::invalidate_all();

        return $count;
    }

    /**
     * 验证路径是否为本插件备份目录内的备份文件
     *
     * @param string $file_path 备份文件路径
     * @return bool
     */
    private function is_backup_file_path( string $file_path ): bool {
        if ( '' === $file_path || false !== strpos( $file_path, '..' ) || is_link( $file_path ) ) {
            return false;
        }

        $backup_dir = realpath( $this->get_backup_dir() );

        if ( false === $backup_dir ) {
            return false;
        }

        $backup_dir = untrailingslashit( wp_normalize_path( $backup_dir ) );
        $real_path  = realpath( $file_path );
        $normalized = $real_path ? wp_normalize_path( $real_path ) : wp_normalize_path( $file_path );

        return 0 === strpos( untrailingslashit( $normalized ), $backup_dir . '/' );
    }

    /**
     * 验证允许写入的上传目录内目标路径
     *
     * @param string $file_path 目标绝对路径
     * @return bool
     */
    private function is_safe_destination_path( string $file_path ): bool {
        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $parent_dir = realpath( dirname( $file_path ) );

        if ( false === $base_dir || false === $parent_dir ) {
            return false;
        }

        $base_dir   = untrailingslashit( wp_normalize_path( $base_dir ) );
        $parent_dir = untrailingslashit( wp_normalize_path( $parent_dir ) );

        return 0 === strpos( $parent_dir . '/', $base_dir . '/' );
    }

    /**
     * 验证文件路径是否安全
     *
     * @param string $file_path 文件路径
     * @return bool 是否安全
     */
    private function is_safe_path( string $file_path ): bool {
        // 获取上传目录
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );

        // 获取文件真实路径
        $real_path = realpath( $file_path );

        // 如果文件不存在，realpath 返回 false
        if ( false === $real_path || false === $base_dir ) {
            return false;
        }

        // 检查文件是否在上传目录内，目录边界必须完整匹配。
        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $real_path = untrailingslashit( wp_normalize_path( $real_path ) );

        if ( 0 !== strpos( $real_path, $base_dir . '/' ) ) {
            return false;
        }

        // 检查路径中是否包含 ..
        if ( strpos( $file_path, '..' ) !== false ) {
            return false;
        }

        return true;
    }
}
