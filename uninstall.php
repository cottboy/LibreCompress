<?php
/**
 * Local Crush 卸载清理脚本
 *
 * 插件卸载时执行，清除所有产生的数据
 *
 * @package LocalCrush
 */

// 如果不是通过 WordPress 卸载，则退出
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 删除数据库表
$records_table = $wpdb->prefix . 'local_crush_records';
$backups_table = $wpdb->prefix . 'local_crush_backups';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$records_table}" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$backups_table}" );

// 删除设置项
delete_option( 'local_crush_general' );
delete_option( 'local_crush_tools' );
delete_option( 'local_crush_db_version' );
delete_option( 'local_crush_task' );

// 删除插件写入的附件处理标记、格式转换映射和恢复中间状态；统一压缩记录已随数据表删除。
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_local_crush_output', '_local_crush_restore_ready', '_local_crush_pending')" );

// 停用定时任务并清理限流 transient（钩子名需与 Local_Crush_Processor、Local_Crush_Task 常量保持一致）
wp_clear_scheduled_hook( 'local_crush_pending_sweep_event' );
wp_clear_scheduled_hook( 'local_crush_backup_prune_event' );
wp_clear_scheduled_hook( 'local_crush_cleanup_event' );
wp_unschedule_hook( 'local_crush_task_watchdog_event' );
delete_transient( 'local_crush_pending_sweep' );

// 删除备份文件目录和附件锁目录（目录名需与 Local_Crush_Processor 常量保持一致）
$upload_dir = wp_upload_dir();

foreach ( array( 'local-crush-backups', '.local-crush-locks' ) as $dir_name ) {
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
$bin_dir = WP_CONTENT_DIR . '/local-crush-bin';

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
