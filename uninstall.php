<?php
/**
 * LibreCompress 卸载清理脚本
 *
 * 插件卸载时执行，清除所有产生的数据
 *
 * @package LibreCompress
 */

// 如果不是通过 WordPress 卸载，则退出
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 删除数据库表
$records_table = $wpdb->prefix . 'libre_compress_records';
$backups_table = $wpdb->prefix . 'libre_compress_backups';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$records_table}" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$backups_table}" );

// 删除设置项
delete_option( 'libre_compress_general' );
delete_option( 'libre_compress_tools' );
delete_option( 'libre_compress_db_version' );

// 删除插件写入的附件处理标记和目标格式输出映射；统一压缩记录已随数据表删除。
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_libre_compress_output', '_libre_compress_pending')" );

// 停用定时任务并清理限流 transient
wp_clear_scheduled_hook( 'libre_compress_pending_sweep_event' );
delete_transient( 'libre_compress_pending_sweep' );

// 删除备份文件目录和附件锁目录（目录名需与 Libre_Compress_Processor 常量保持一致）
$upload_dir = wp_upload_dir();

foreach ( array( 'libre-compress-backups', '.libre-compress-locks' ) as $dir_name ) {
    $target_dir = $upload_dir['basedir'] . '/' . $dir_name;

    if ( ! is_dir( $target_dir ) ) {
        continue;
    }

    // 递归删除目录中的所有文件
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $target_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            rmdir( $file->getRealPath() );
        } else {
            unlink( $file->getRealPath() );
        }
    }

    rmdir( $target_dir );
}

// 删除压缩工具二进制目录
$bin_dir = WP_CONTENT_DIR . '/LibreCompress-bin';

if ( is_dir( $bin_dir ) ) {
    // 递归删除目录中的所有文件
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $bin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            rmdir( $file->getRealPath() );
        } else {
            unlink( $file->getRealPath() );
        }
    }

    rmdir( $bin_dir );
}
