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

        if ( ! file_exists( $backup_dir ) ) {
            // 创建目录
            $result = wp_mkdir_p( $backup_dir );

            if ( $result ) {
                // 创建 .htaccess 防止直接访问
                $htaccess_file = $backup_dir . '/.htaccess';
                if ( ! file_exists( $htaccess_file ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    file_put_contents( $htaccess_file, 'Deny from all' );
                }

                // 创建 index.php 防止目录浏览
                $index_file = $backup_dir . '/index.php';
                if ( ! file_exists( $index_file ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    file_put_contents( $index_file, '<?php // Silence is golden.' );
                }
            }

            return $result;
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

        // 使用附件 ID 和原文件名生成备份文件名
        // 格式：{attachment_id}_{basename}
        // WordPress 文件名本身已包含尺寸信息，如 photo-150x150.jpg
        return sprintf(
            '%s/%d_%s',
            $backup_dir,
            $attachment_id,
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
            // 已有备份，不重复创建
            return true;
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

        if ( ! $result ) {
            return false;
        }

        // 保存备份记录
        $database->add_backup(
            array(
                'attachment_id' => $attachment_id,
                'original_path' => $file_path,
                'backup_path'   => $backup_path,
            )
        );

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

        // 删除压缩记录
        $database->delete_records_by_attachment( $attachment_id );

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

        // 验证备份文件存在
        if ( ! file_exists( $backup_path ) ) {
            return false;
        }

        // 复制备份文件到原位置
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        $result = copy( $backup_path, $original_path );

        if ( ! $result ) {
            return false;
        }

        // 删除备份文件
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $backup_path );

        // 删除备份记录
        $database = libre_compress()->database;
        $database->delete_backup( $backup['id'] );

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

        foreach ( $backups as $backup ) {
            // 删除备份文件
            if ( file_exists( $backup['backup_path'] ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $backup['backup_path'] );
            }

            // 删除备份记录
            $database->delete_backup( $backup['id'] );
        }

        return true;
    }

    /**
     * 检查附件是否有备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否有备份
     */
    public function has_backup( int $attachment_id ): bool {
        $database = libre_compress()->database;
        return $database->has_backup( $attachment_id );
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
        $backup_dir = $this->get_backup_dir();
        $count      = 0;

        if ( ! is_dir( $backup_dir ) ) {
            return 0;
        }

        // 遍历删除所有备份文件
        $files = glob( $backup_dir . '/*' );

        foreach ( $files as $file ) {
            if ( is_file( $file ) && basename( $file ) !== '.htaccess' && basename( $file ) !== 'index.php' ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( unlink( $file ) ) {
                    $count++;
                }
            }
        }

        // 清空备份记录表
        global $wpdb;
        $table_name = $wpdb->prefix . 'libre_compress_backups';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "TRUNCATE TABLE {$table_name}" );

        return $count;
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

        // 检查文件是否在上传目录内
        if ( strpos( $real_path, $base_dir ) !== 0 ) {
            return false;
        }

        // 检查路径中是否包含 ..
        if ( strpos( $file_path, '..' ) !== false ) {
            return false;
        }

        return true;
    }
}
