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

        if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
            return false;
        }

        $protection_files = array(
            '.htaccess'    => self::protection_htaccess_content(),
            'index.php'    => '<?php // Silence is golden.',
            'web.config'   => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add users=\"\" roles=\"\" verbs=\"\" /></authorization></system.webServer></configuration>",
        );

        foreach ( $protection_files as $filename => $content ) {
            $path = $backup_dir . '/' . $filename;
            if ( file_exists( $path ) ) {
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
