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
     * 获取备份目录路径
     *
     * @return string 备份目录绝对路径
     */
    public function get_backup_dir(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::BACKUP_DIR_NAME;
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
            '.htaccess'    => 'Deny from all',
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
     * 生成备份文件路径
     *
     * @param int    $attachment_id 附件 ID
     * @param string $original_path 原文件路径
     * @return string 备份文件路径
     */
    private function generate_backup_path( int $attachment_id, string $original_path ): string {
        $backup_dir = $this->get_backup_dir();
        $basename   = basename( $original_path );

        // 随机令牌避免备份 URL 被 predictable 拼接枚举。
        return sprintf(
            '%s/%d_%s_%s',
            $backup_dir,
            $attachment_id,
            wp_generate_password( 16, false, false ),
            $basename
        );
    }

    /**
     * 创建备份
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

        // 检查是否已有备份
        $database = libre_compress()->database;
        $existing = $database->get_backup_by_path( $file_path );

        if ( $existing ) {
            // 数据库有记录时仍必须确认备份文件真实存在且大小与当前源文件一致。
            if ( ! empty( $existing['backup_path'] ) && file_exists( $existing['backup_path'] )
                && filesize( $existing['backup_path'] ) === filesize( $file_path ) ) {
                return true;
            }

            if ( ! empty( $existing['backup_path'] ) && file_exists( $existing['backup_path'] )
                && $this->is_safe_path( $existing['backup_path'] ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $existing['backup_path'] );
            }
            $database->delete_backup( $existing['id'] );
        }

        // 确保备份目录存在
        if ( ! $this->ensure_backup_dir() ) {
            return false;
        }

        // 生成备份路径
        $backup_path = $this->generate_backup_path( $attachment_id, $file_path );

        // 复制文件
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        $result = copy( $file_path, $backup_path );

        if ( ! $result || filesize( $backup_path ) !== filesize( $file_path ) ) {
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
                'original_path' => $file_path,
                'backup_path'   => $backup_path,
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
     * 恢复备份
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     原文件路径（可选，不提供则恢复所有）
     * @return bool 是否成功
     */
    public function restore_backup( int $attachment_id, string $file_path = '' ): bool {
        $database = libre_compress()->database;

        if ( ! empty( $file_path ) ) {
            // 恢复单个文件
            $backup = $database->get_backup_by_path( $file_path );

            if ( ! $backup ) {
                return false;
            }

            return $this->restore_single_backup( $backup );
        }

        // 恢复附件的所有备份
        $backups = $database->get_backups_by_attachment( $attachment_id );

        if ( empty( $backups ) ) {
            return false;
        }

        $success = true;
        foreach ( $backups as $backup ) {
            if ( ! $this->restore_single_backup( $backup ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 清理已成功恢复的备份和统一压缩记录
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部清理成功
     */
    public function finalize_restored_backups( int $attachment_id ): bool {
        $database = libre_compress()->database;
        $backups  = $database->get_backups_by_attachment( $attachment_id );
        $success  = true;

        foreach ( $backups as $backup ) {
            if ( file_exists( $backup['backup_path'] ) ) {
                if ( ! $this->is_safe_path( $backup['backup_path'] )
                    || ! unlink( $backup['backup_path'] ) ) {
                    $success = false;
                    continue;
                }
            }

            if ( ! $database->delete_backup( $backup['id'] ) ) {
                $success = false;
            }
        }

        if ( $success ) {
            $database->delete_records_by_attachment( $attachment_id );
        }

        return $success;
    }

    /**
     * 恢复单个备份
     *
     * @param array $backup 备份记录
     * @return bool 是否成功
     */
    private function restore_single_backup( array $backup ): bool {
        $backup_path   = $backup['backup_path'];
        $original_path = $backup['original_path'];

        // 备份记录属于不可信持久化数据，删除或覆盖前必须重新验证路径。
        if ( ! file_exists( $backup_path ) || ! $this->is_safe_path( $backup_path ) || ! $this->is_safe_destination_path( $original_path ) ) {
            return false;
        }

        $restore_temp  = $original_path . '.lc-restore-' . wp_generate_password( 12, false, false );
        $is_windows    = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $backup_path, $restore_temp ) || filesize( $restore_temp ) !== filesize( $backup_path ) ) {
            if ( file_exists( $restore_temp ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $restore_temp );
            }
            return false;
        }

        if ( $is_windows ) {
            // Windows 的 rename 不能覆盖现有文件；源文件本来不存在时直接落位。
            $removed_existing = true;
            if ( file_exists( $original_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                $removed_existing = unlink( $original_path );
            }

            if ( ! $removed_existing || ! rename( $restore_temp, $original_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
                copy( $backup_path, $original_path );
                if ( file_exists( $restore_temp ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $restore_temp );
                }
                return false;
            }
        } elseif ( ! rename( $restore_temp, $original_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $restore_temp );
            return false;
        }

        // 备份文件和记录在附件路径/MIME 全部提交成功后再由 finalize_restored_backups() 清理。
        return true;
    }

    /**
     * 删除备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否成功
     */
    public function delete_backup( int $attachment_id ): bool {
        $database = libre_compress()->database;
        $backups  = $database->get_backups_by_attachment( $attachment_id );

        if ( empty( $backups ) ) {
            return true;
        }

        $success = true;

        foreach ( $backups as $backup ) {
            if ( file_exists( $backup['backup_path'] ) ) {
                if ( ! $this->is_safe_path( $backup['backup_path'] ) ) {
                    $success = false;
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( ! unlink( $backup['backup_path'] ) ) {
                    $success = false;
                    continue;
                }
            }

            if ( ! $database->delete_backup( $backup['id'] ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 检查附件是否有备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否有备份
     */
    public function has_backup( int $attachment_id ): bool {
        $backups = libre_compress()->database->get_backups_by_attachment( $attachment_id );

        foreach ( $backups as $backup ) {
            if ( ! empty( $backup['backup_path'] ) && file_exists( $backup['backup_path'] ) ) {
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
        $database = libre_compress()->database;
        $backups  = $database->get_backups_by_attachment( $attachment_id );

        $info = array();
        foreach ( $backups as $backup ) {
            $backup_size = file_exists( $backup['backup_path'] ) ? filesize( $backup['backup_path'] ) : 0;

            $info[] = array(
                'original_path' => $backup['original_path'],
                'backup_path'   => $backup['backup_path'],
                'backup_size'   => $backup_size,
                'created_at'    => $backup['created_at'],
            );
        }

        return $info;
    }

    /**
     * 删除所有备份
     *
     * @return int 删除的备份数量
     */
    public function delete_all_backups(): int {
        $database = libre_compress()->database;
        $backups  = $database->get_all_backups();
        $count    = 0;

        foreach ( $backups as $backup ) {
            $backup_path = $backup['backup_path'];

            if ( file_exists( $backup_path ) ) {
                if ( ! $this->is_safe_path( $backup_path ) ) {
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( ! unlink( $backup_path ) ) {
                    continue;
                }
            }

            if ( $database->delete_backup( $backup['id'] ) ) {
                $count++;
            }
        }

        return $count;
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
