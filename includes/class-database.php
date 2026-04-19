<?php
/**
 * 数据库操作类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 数据库操作类
 *
 * 负责创建和管理压缩记录表、备份记录表
 */
class Libre_Compress_Database {

    /**
     * 压缩记录表名
     *
     * @var string
     */
    private $records_table;

    /**
     * 备份记录表名
     *
     * @var string
     */
    private $backups_table;

    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->records_table = $wpdb->prefix . 'libre_compress_records';
        $this->backups_table = $wpdb->prefix . 'libre_compress_backups';
    }

    /**
     * 获取压缩记录表名
     *
     * @return string
     */
    public function get_records_table() {
        return $this->records_table;
    }

    /**
     * 获取备份记录表名
     *
     * @return string
     */
    public function get_backups_table() {
        return $this->backups_table;
    }

    /**
     * 创建数据库表
     */
    public function create_tables() {
        $this->create_records_table();
        $this->create_backups_table();
        $this->migrate_obsolete_columns();
    }

    /**
     * 创建压缩记录表
     */
    private function create_records_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->records_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            size_type VARCHAR(50) NOT NULL DEFAULT 'full',
            original_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            compressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            compression_ratio DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            tool_name VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            error_message TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            UNIQUE KEY attachment_size (attachment_id, size_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * 创建备份记录表
     */
    private function create_backups_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->backups_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            original_path VARCHAR(500) NOT NULL,
            backup_path VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * 清理旧字段
     */
    private function migrate_obsolete_columns() {
        global $wpdb;

        $compression_mode_column = $wpdb->get_var( "SHOW COLUMNS FROM {$this->records_table} LIKE 'compression_mode'" );

        if ( 'compression_mode' === $compression_mode_column ) {
            $wpdb->query( "ALTER TABLE {$this->records_table} DROP COLUMN compression_mode" );
        }

        $channel_name_column = $wpdb->get_var( "SHOW COLUMNS FROM {$this->records_table} LIKE 'channel_name'" );
        $tool_name_column    = $wpdb->get_var( "SHOW COLUMNS FROM {$this->records_table} LIKE 'tool_name'" );

        if ( 'channel_name' === $channel_name_column && 'tool_name' !== $tool_name_column ) {
            $wpdb->query( "ALTER TABLE {$this->records_table} ADD COLUMN tool_name VARCHAR(50) NOT NULL DEFAULT '' AFTER compression_ratio" );
            $wpdb->query( "UPDATE {$this->records_table} SET tool_name = channel_name WHERE tool_name = ''" );
            $wpdb->query( "ALTER TABLE {$this->records_table} DROP COLUMN channel_name" );
            return;
        }

        if ( 'channel_name' === $channel_name_column && 'tool_name' === $tool_name_column ) {
            $wpdb->query( "UPDATE {$this->records_table} SET tool_name = channel_name WHERE tool_name = ''" );
            $wpdb->query( "ALTER TABLE {$this->records_table} DROP COLUMN channel_name" );
        }
    }

    /**
     * 添加压缩记录
     *
     * @param array $data 记录数据
     * @return int|false 插入的记录 ID 或 false
     */
    public function add_record( $data ) {
        global $wpdb;

        // 验证必需字段
        if ( empty( $data['attachment_id'] ) || empty( $data['file_path'] ) ) {
            return false;
        }

        // 清理和验证数据
        $insert_data = array(
            'attachment_id'     => absint( $data['attachment_id'] ),
            'file_path'         => sanitize_text_field( $data['file_path'] ),
            'size_type'         => isset( $data['size_type'] ) ? sanitize_text_field( $data['size_type'] ) : 'full',
            'original_size'     => isset( $data['original_size'] ) ? absint( $data['original_size'] ) : 0,
            'compressed_size'   => isset( $data['compressed_size'] ) ? absint( $data['compressed_size'] ) : 0,
            'compression_ratio' => isset( $data['compression_ratio'] ) ? floatval( $data['compression_ratio'] ) : 0.00,
            'tool_name'         => isset( $data['tool_name'] ) ? sanitize_text_field( $data['tool_name'] ) : '',
            'status'            => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'success',
            'error_message'     => isset( $data['error_message'] ) ? sanitize_textarea_field( $data['error_message'] ) : '',
        );

        $format = array(
            '%d', // attachment_id
            '%s', // file_path
            '%s', // size_type
            '%d', // original_size
            '%d', // compressed_size
            '%f', // compression_ratio
            '%s', // tool_name
            '%s', // status
            '%s', // error_message
        );

        $result = $wpdb->insert( $this->records_table, $insert_data, $format );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 根据附件 ID 获取压缩记录
     *
     * @param int $attachment_id 附件 ID
     * @return array 压缩记录列表
     */
    public function get_records_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->records_table} WHERE attachment_id = %d ORDER BY size_type ASC",
                $attachment_id
            ),
            ARRAY_A
        );
    }

    /**
     * 根据附件 ID 和尺寸类型获取单条记录
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @return array|null 压缩记录或 null
     */
    public function get_record( $attachment_id, $size_type = 'full' ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );
        $size_type     = sanitize_text_field( $size_type );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->records_table} WHERE attachment_id = %d AND size_type = %s",
                $attachment_id,
                $size_type
            ),
            ARRAY_A
        );
    }

    /**
     * 根据状态获取压缩记录
     *
     * @param string $status 状态
     * @param int    $limit  数量限制
     * @param int    $offset 偏移量
     * @return array
     */
    public function get_records_by_status( $status, $limit = 100, $offset = 0 ) {
        global $wpdb;

        $status = sanitize_text_field( $status );
        $limit  = absint( $limit );
        $offset = absint( $offset );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->records_table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status,
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * 更新压缩记录
     *
     * @param int   $record_id 记录 ID
     * @param array $data      更新数据
     * @return bool
     */
    public function update_record( $record_id, $data ) {
        global $wpdb;

        $record_id = absint( $record_id );

        if ( ! $record_id ) {
            return false;
        }

        $update_data = array();
        $format      = array();

        if ( isset( $data['original_size'] ) ) {
            $update_data['original_size'] = absint( $data['original_size'] );
            $format[]                     = '%d';
        }

        if ( isset( $data['compressed_size'] ) ) {
            $update_data['compressed_size'] = absint( $data['compressed_size'] );
            $format[]                       = '%d';
        }

        if ( isset( $data['compression_ratio'] ) ) {
            $update_data['compression_ratio'] = floatval( $data['compression_ratio'] );
            $format[]                         = '%f';
        }

        if ( isset( $data['status'] ) ) {
            $update_data['status'] = sanitize_text_field( $data['status'] );
            $format[]              = '%s';
        }

        if ( isset( $data['error_message'] ) ) {
            $update_data['error_message'] = sanitize_textarea_field( $data['error_message'] );
            $format[]                     = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $this->records_table,
            $update_data,
            array( 'id' => $record_id ),
            $format,
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 删除单条压缩记录
     *
     * @param int $record_id 记录 ID
     * @return bool
     */
    public function delete_record( $record_id ) {
        global $wpdb;

        $record_id = absint( $record_id );

        if ( ! $record_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->records_table,
            array( 'id' => $record_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 根据附件删除压缩记录
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function delete_records_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->records_table,
            array( 'attachment_id' => $attachment_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 清空所有压缩记录
     *
     * @return int|false
     */
    public function clear_all_records() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query( "DELETE FROM {$this->records_table}" );
    }

    /**
     * 获取未压缩的附件列表
     *
     * @param int $limit  数量限制
     * @param int $offset 偏移量
     * @return array
     */
    public function get_uncompressed_attachments( $limit = 100, $offset = 0 ) {
        global $wpdb;

        $limit  = absint( $limit );
        $offset = absint( $offset );

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.guid, pm.meta_value as file_path
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
            AND p.ID NOT IN (
                SELECT DISTINCT attachment_id FROM {$this->records_table} WHERE size_type = 'full' AND status = 'success'
            )
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * 获取附件压缩统计
     *
     * @param int $attachment_id 附件 ID
     * @return array|null
     */
    public function get_attachment_stats( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total_files,
                    SUM(original_size) as total_original_size,
                    SUM(compressed_size) as total_compressed_size,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
                FROM {$this->records_table}
                WHERE attachment_id = %d",
                $attachment_id
            ),
            ARRAY_A
        );

        if ( $result && $result['total_original_size'] > 0 ) {
            $result['total_ratio'] = round(
                ( 1 - $result['total_compressed_size'] / $result['total_original_size'] ) * 100,
                2
            );
        } else {
            $result['total_ratio'] = 0;
        }

        return $result;
    }

    /**
     * 添加备份记录
     *
     * @param array $data 备份数据
     * @return int|false
     */
    public function add_backup( $data ) {
        global $wpdb;

        if ( empty( $data['attachment_id'] ) || empty( $data['original_path'] ) || empty( $data['backup_path'] ) ) {
            return false;
        }

        $insert_data = array(
            'attachment_id' => absint( $data['attachment_id'] ),
            'original_path' => sanitize_text_field( $data['original_path'] ),
            'backup_path'   => sanitize_text_field( $data['backup_path'] ),
        );

        $format = array( '%d', '%s', '%s' );

        $result = $wpdb->insert( $this->backups_table, $insert_data, $format );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 根据附件获取备份列表
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_backups_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->backups_table} WHERE attachment_id = %d",
                $attachment_id
            ),
            ARRAY_A
        );
    }

    /**
     * 根据原始路径获取备份
     *
     * @param string $original_path 原始路径
     * @return array|null
     */
    public function get_backup_by_path( $original_path ) {
        global $wpdb;

        $original_path = sanitize_text_field( $original_path );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->backups_table} WHERE original_path = %s",
                $original_path
            ),
            ARRAY_A
        );
    }

    /**
     * 删除单条备份
     *
     * @param int $backup_id 备份 ID
     * @return bool
     */
    public function delete_backup( $backup_id ) {
        global $wpdb;

        $backup_id = absint( $backup_id );

        if ( ! $backup_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->backups_table,
            array( 'id' => $backup_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 根据附件删除备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function delete_backups_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->backups_table,
            array( 'attachment_id' => $attachment_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 检查附件是否存在备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function has_backup( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->backups_table} WHERE attachment_id = %d",
                $attachment_id
            )
        );

        return $count > 0;
    }

    /**
     * 获取所有备份
     *
     * @return array
     */
    public function get_all_backups() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            "SELECT * FROM {$this->backups_table} ORDER BY attachment_id ASC",
            ARRAY_A
        );
    }
}
