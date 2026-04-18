/**
 * LibreCompress 后台 JavaScript
 *
 * @package LibreCompress
 */

(function($) {
    'use strict';

    // 确保数据对象存在
    if (typeof libreCompressData === 'undefined') {
        return;
    }

    var LC = {
        ajaxUrl: libreCompressData.ajaxUrl,
        nonce: libreCompressData.nonce,
        i18n: libreCompressData.i18n,

        /**
         * 初始化
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            // 媒体库压缩/恢复按钮
            $(document).on('click', '.libre-compress-btn', this.handleMediaLibraryAction.bind(this));

            // 设置页面按钮
            $('#libre-compress-clear-records').on('click', this.handleClearRecords.bind(this));
            $('#libre-compress-restore-all').on('click', this.handleRestoreAll.bind(this));
            $('#libre-compress-disable-thumbnails').on('click', this.handleDisableThumbnails.bind(this));
            $('#libre-compress-enable-thumbnails').on('click', this.handleEnableThumbnails.bind(this));
            $('#libre-compress-delete-thumbnails').on('click', this.handleDeleteThumbnails.bind(this));
            $('#libre-compress-regenerate-thumbnails').on('click', this.handleRegenerateThumbnails.bind(this));
            $('#libre-compress-bulk-compress').on('click', this.handleBulkCompress.bind(this));
            $('#libre-compress-delete-all-backups').on('click', this.handleDeleteAllBackups.bind(this));
        },

        /**
         * 处理媒体库操作
         */
        handleMediaLibraryAction: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var action = $btn.data('action');
            var attachmentId = $btn.data('attachment-id');

            if (!attachmentId) {
                return;
            }

            $btn.prop('disabled', true).text(this.i18n.processing);

            if (action === 'compress') {
                this.compressSingle(attachmentId, $btn);
            } else if (action === 'restore') {
                this.restoreSingle(attachmentId, $btn);
            } else if (action === 'delete-backup') {
                this.deleteBackup(attachmentId, $btn);
            }
        },

        /**
         * 压缩单张图片
         */
        compressSingle: function(attachmentId, $btn) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_compress_single',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        // 刷新页面以显示新状态
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text(self.i18n.compressing.replace('...', ''));
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text(self.i18n.compressing.replace('...', ''));
                }
            });
        },

        /**
         * 恢复单张图片
         */
        restoreSingle: function(attachmentId, $btn) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_restore',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text(self.i18n.restoring.replace('...', ''));
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text(self.i18n.restoring.replace('...', ''));
                }
            });
        },

        /**
         * 删除单张图片的备份
         */
        deleteBackup: function(attachmentId, $btn) {
            var self = this;

            if (!confirm(this.i18n.confirmDeleteBackup)) {
                $btn.prop('disabled', false).text(self.i18n.deleteBackup);
                return;
            }

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_delete_backup',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text(self.i18n.deleteBackup);
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text(self.i18n.deleteBackup);
                }
            });
        },

        /**
         * 清除压缩记录
         */
        handleClearRecords: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmClear)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_clear_records',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false).text($btn.text().replace(self.i18n.processing, ''));
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 恢复所有原图备份
         */
        handleRestoreAll: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmRestoreAll)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_restore_all',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false);
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 禁止生成缩略图
         */
        handleDisableThumbnails: function(e) {
            e.preventDefault();

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_thumbnail_action',
                    action_type: 'disable',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false).text($btn.text().replace(self.i18n.processing, ''));
                    location.reload();
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 重新启用缩略图
         */
        handleEnableThumbnails: function(e) {
            e.preventDefault();

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_thumbnail_action',
                    action_type: 'enable',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false).text($btn.text().replace(self.i18n.processing, ''));
                    location.reload();
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 删除缩略图
         */
        handleDeleteThumbnails: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmDelete)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_thumbnail_action',
                    action_type: 'delete',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false);
                    location.reload();
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 重新生成缩略图
         */
        handleRegenerateThumbnails: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmRegenerate)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_thumbnail_action',
                    action_type: 'regenerate',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false);
                    location.reload();
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 批量压缩
         */
        handleBulkCompress: function(e) {
            e.preventDefault();

            var self = this;
            var $btn = $(e.currentTarget);
            var $progress = $('#libre-compress-bulk-progress');
            var $progressFill = $progress.find('.progress-fill');
            var $progressText = $progress.find('.progress-text');

            $btn.prop('disabled', true);
            $progress.show();
            $progressText.text(this.i18n.processing);

            // 首先获取未压缩的图片列表
            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_get_uncompressed',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success && response.data.items.length > 0) {
                        self.processBulkCompress(response.data.items, $progressFill, $progressText, $btn);
                    } else {
                        $progressText.text(self.i18n.completed + ' - ' + (response.data.message || '没有需要压缩的图片'));
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                    $progress.hide();
                }
            });
        },

        /**
         * 删除所有原图备份
         */
        handleDeleteAllBackups: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmDeleteAllBackups)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_delete_all_backups',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                    }
                    $btn.prop('disabled', false);
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false);
                }
            });
        },

        /**
         * 处理批量压缩
         */
        processBulkCompress: function(items, $progressFill, $progressText, $btn) {
            var self = this;
            var total = items.length;
            var completed = 0;
            var success = 0;
            var failed = 0;

            // 获取并发数设置
            var concurrency = 5;

            // 创建任务队列
            var queue = items.slice();
            var running = 0;

            function processNext() {
                while (running < concurrency && queue.length > 0) {
                    var item = queue.shift();
                    running++;

                    $.ajax({
                        url: self.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'libre_compress_compress_single',
                            nonce: self.nonce,
                            attachment_id: item.attachment_id,
                            size_type: item.size_type
                        },
                        success: function(response) {
                            if (response.success && response.data.status === 'success') {
                                success++;
                            } else {
                                failed++;
                            }
                        },
                        error: function() {
                            failed++;
                        },
                        complete: function() {
                            running--;
                            completed++;

                            // 更新进度
                            var percent = Math.round((completed / total) * 100);
                            $progressFill.css('width', percent + '%');
                            $progressText.text(completed + ' / ' + total + ' (' + percent + '%)');

                            // 继续处理下一个
                            if (queue.length > 0) {
                                processNext();
                            } else if (running === 0) {
                                // 全部完成
                                $progressText.text(self.i18n.completed + ' - 成功: ' + success + ', 失败: ' + failed);
                                $btn.prop('disabled', false);
                            }
                        }
                    });
                }
            }

            processNext();
        }
    };

    // DOM 加载完成后初始化
    $(document).ready(function() {
        LC.init();
    });

})(jQuery);
