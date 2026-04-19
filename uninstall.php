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

// 删除备份文件目录
$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/libre-compress-backups';

if ( is_dir( $backup_dir ) ) {
    // 递归删除目录中的所有文件
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            rmdir( $file->getRealPath() );
        } else {
            unlink( $file->getRealPath() );
        }
    }

    rmdir( $backup_dir );
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
