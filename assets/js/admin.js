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
            $('#libre-compress-delete-thumbnails').on('click', this.handleDeleteThumbnails.bind(this));
            $('#libre-compress-generate-thumbnails').on('click', this.handleGenerateMissingThumbnails.bind(this));
            $('#libre-compress-bulk-compress').on('click', this.handleBulkCompress.bind(this));
            $('#libre-compress-delete-all-backups').on('click', this.handleDeleteAllBackups.bind(this));

            // 原图备份保留时长不接受 0，步进跨过 0 时按方向落到 1 或 -1
            $(document).on('input change', '#libre-compress-retention-days', this.skipRetentionZero.bind(this));
        },

        /**
         * 归一化备份保留时长输入，跳过无效值 0
         */
        skipRetentionZero: function(e) {
            var $input = $(e.currentTarget);
            var days = parseInt($input.val(), 10);

            if (isNaN(days)) {
                return;
            }

            if (days !== 0) {
                $input.data('lastDays', days);
                return;
            }

            var last = $input.data('lastDays');
            days = (typeof last === 'number' && last > 0) ? -1 : 1;

            $input.val(days).data('lastDays', days);
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

            $btn.data('original-label', $btn.text());
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
                    if (!response.success) {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text($btn.data('original-label'));
                        return;
                    }

                    // 跳过和失败都要把原因显示出来，避免用户点了没反应
                    if (response.data.status === 'skipped' || response.data.status === 'failed') {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text($btn.data('original-label'));
                        location.reload();
                        return;
                    }

                    // 刷新页面以显示新状态
                    location.reload();
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text($btn.data('original-label'));
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
                        $btn.prop('disabled', false).text($btn.data('original-label'));
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text($btn.data('original-label'));
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
         * 汇总失败附件 ID
         */
        formatFailedIds: function(failedIds) {
            if (!failedIds.length) {
                return '';
            }

            var text = this.i18n.failedIds.replace('%s', failedIds.slice(0, 10).join(', '));

            if (failedIds.length > 10) {
                text += this.i18n.failedMore.replace('%d', failedIds.length);
            }

            return '\n' + text;
        },

        /**
         * 分页清除压缩记录与原图备份
         */
        handleClearRecords: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmClear)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);
            var after = 0;
            var cleared = 0;
            var failed = 0;
            var failedIds = [];
            var pageErrors = 0;

            $btn.prop('disabled', true).text(this.i18n.processing);

            function finish(interrupted) {
                var summary = self.i18n.operationSummary
                    .replace('%1$s', self.i18n.clearFinished)
                    .replace('%2$d', cleared)
                    .replace('%3$d', failed);

                summary += self.formatFailedIds(failedIds);

                if (interrupted) {
                    summary += '\n' + self.i18n.clearInterrupted.replace('%d', cleared + failed);
                }

                alert(summary);
                $btn.prop('disabled', false);
                location.reload();
            }

            function loadPage() {
                $.ajax({
                    url: self.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'libre_compress_clear_records',
                        nonce: self.nonce,
                        after: after
                    },
                    success: function(response) {
                        pageErrors = 0;

                        if (!response.success) {
                            finish(true);
                            return;
                        }

                        cleared += parseInt(response.data.cleared_count, 10) || 0;
                        failed += parseInt(response.data.failed_count, 10) || 0;
                        failedIds = failedIds.concat(response.data.failed_ids || []);
                        $btn.text(self.i18n.clearProgress.replace('%d', cleared + failed));

                        if (response.data.has_more && parseInt(response.data.next_after, 10) > after) {
                            after = parseInt(response.data.next_after, 10);
                            loadPage();
                        } else {
                            finish(false);
                        }
                    },
                    error: function() {
                        pageErrors++;

                        if (pageErrors <= 3) {
                            loadPage();
                            return;
                        }

                        finish(true);
                    }
                });
            }

            loadPage();
        },

        /**
         * 按游标分页恢复所有原图备份
         */
        handleRestoreAll: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmRestoreAll)) {
                return;
            }

            var self = this;
            var $btn = $(e.currentTarget);
            var after = 0;
            var success = 0;
            var failed = 0;
            var failedIds = [];
            var pageErrors = 0;

            $btn.prop('disabled', true).text(this.i18n.processing);

            function finish(interrupted) {
                var summary = self.i18n.operationSummary
                    .replace('%1$s', self.i18n.restoreFinished)
                    .replace('%2$d', success)
                    .replace('%3$d', failed);

                summary += self.formatFailedIds(failedIds);

                if (interrupted) {
                    summary += '\n' + self.i18n.restoreInterrupted.replace('%d', success + failed);
                }

                alert(summary);
                $btn.prop('disabled', false);
                location.reload();
            }

            function loadPage() {
                $.ajax({
                    url: self.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'libre_compress_restore_all',
                        nonce: self.nonce,
                        after: after
                    },
                    success: function(response) {
                        pageErrors = 0;

                        if (!response.success) {
                            finish(true);
                            return;
                        }

                        success += parseInt(response.data.success_count, 10) || 0;
                        failed += parseInt(response.data.failed_count, 10) || 0;
                        failedIds = failedIds.concat(response.data.failed_ids || []);
                        $btn.text(self.i18n.restoreProgress.replace('%d', success + failed));

                        if (response.data.has_more && parseInt(response.data.next_after, 10) > after) {
                            after = parseInt(response.data.next_after, 10);
                            loadPage();
                        } else {
                            finish(false);
                        }
                    },
                    error: function() {
                        pageErrors++;

                        if (pageErrors <= 3) {
                            loadPage();
                            return;
                        }

                        finish(true);
                    }
                });
            }

            loadPage();
        },

        /**
         * 删除未勾选尺寸的缩略图
         */
        handleDeleteThumbnails: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmDeleteThumbnails)) {
                return;
            }

            this.runThumbnailAction(e, 'delete');
        },

        /**
         * 补生成缺失尺寸的缩略图
         */
        handleGenerateMissingThumbnails: function(e) {
            e.preventDefault();

            if (!confirm(this.i18n.confirmGenerateThumbnails)) {
                return;
            }

            this.runThumbnailAction(e, 'generate');
        },

        /**
         * 发送缩略图管理请求（按附件游标分页，避免大媒体库单次请求超时）
         */
        runThumbnailAction: function(e, actionType) {
            var self = this;
            var $btn = $(e.currentTarget);

            $btn.prop('disabled', true).text(this.i18n.processing);

            var after = 0;
            var done = 0;
            var pageErrors = 0;
            var acc = {
                deleted: 0, affected: 0, replaced: 0,
                generated: 0, generatedFiles: 0, skipped: 0,
                failed: 0, failedIds: [], contentFailed: false
            };

            function finish(interrupted) {
                var summary;

                if (actionType === 'delete') {
                    summary = self.i18n.thumbnailDeleteSummary
                        .replace('%1$d', acc.deleted)
                        .replace('%2$d', acc.affected)
                        .replace('%3$d', acc.replaced);

                    if (acc.contentFailed) {
                        summary += ' ' + self.i18n.thumbnailContentFailed;
                    }
                } else {
                    summary = self.i18n.thumbnailGenerateSummary
                        .replace('%1$d', acc.generated)
                        .replace('%2$d', acc.generatedFiles)
                        .replace('%3$d', acc.skipped)
                        .replace('%4$d', acc.failed);
                }

                summary += self.formatFailedIds(acc.failedIds);

                if (interrupted) {
                    summary += '\n' + self.i18n.thumbnailInterrupted;
                }

                alert(summary);
                $btn.prop('disabled', false);
                location.reload();
            }

            function loadPage() {
                $.ajax({
                    url: self.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'libre_compress_thumbnail_action',
                        action_type: actionType,
                        nonce: self.nonce,
                        after: after
                    },
                    success: function(response) {
                        pageErrors = 0;

                        if (!response.success) {
                            finish(true);
                            return;
                        }

                        var d = response.data || {};
                        done += parseInt(d.processed, 10) || 0;

                        if (actionType === 'delete') {
                            acc.deleted += parseInt(d.deleted_files, 10) || 0;
                            acc.affected += parseInt(d.affected_count, 10) || 0;
                            acc.replaced += parseInt(d.replaced_links, 10) || 0;

                            if (d.content_failed) {
                                acc.contentFailed = true;
                            }
                        } else {
                            acc.generated += parseInt(d.generated_attachments, 10) || 0;
                            acc.generatedFiles += parseInt(d.generated_files, 10) || 0;
                            acc.skipped += parseInt(d.skipped_attachments, 10) || 0;
                        }

                        acc.failed += (d.failed_ids || []).length;
                        acc.failedIds = acc.failedIds.concat(d.failed_ids || []);
                        $btn.text(self.i18n.thumbnailProgress.replace('%d', done));

                        if (d.has_more && parseInt(d.next_after, 10) > after) {
                            after = parseInt(d.next_after, 10);
                            loadPage();
                        } else {
                            finish(false);
                        }
                    },
                    error: function() {
                        pageErrors++;

                        if (pageErrors <= 3) {
                            loadPage();
                            return;
                        }

                        finish(true);
                    }
                });
            }

            loadPage();
        },

        /**
         * 按附件游标分页批量压缩
         */
        handleBulkCompress: function(e) {
            e.preventDefault();

            var self = this;
            var $btn = $(e.currentTarget);
            var $progress = $('#libre-compress-bulk-progress');
            var $progressFill = $progress.find('.progress-fill');
            var $progressText = $progress.find('.progress-text');
            var after = 0;
            var completed = 0;
            var success = 0;
            var failed = 0;
            var skipped = 0;
            var hadError = false;
            var pageErrors = 0;
            var concurrency = parseInt(libreCompressData.concurrency, 10) || 5;

            $btn.prop('disabled', true);
            $progress.show();
            $progressFill.css('width', '0%');
            $progressText.text(this.i18n.processing);

            function updateProgress() {
                $progressText.text(self.i18n.batchProgress.replace('%d', completed));
            }

            function finish() {
                var summary;

                if (completed === 0 && hadError) {
                    summary = self.i18n.error;
                } else if (completed === 0) {
                    summary = self.i18n.noCompressItems;
                } else {
                    summary = self.i18n.batchSummary
                        .replace('%1$s', completed)
                        .replace('%2$d', success)
                        .replace('%3$d', skipped)
                        .replace('%4$d', failed);
                }

                $progressFill.css('width', '100%');
                $progressText.text(summary);
                $btn.prop('disabled', false);

                // 压缩结果只体现在页面统计和媒体库列上，不刷新就看不到本次成果
                alert(summary);
                location.reload();
            }

            function countResult(data) {
                if (data && data.status === 'success') {
                    success++;
                } else if (data && (data.status === 'failed' || data.status === 'partial')) {
                    failed++;
                } else {
                    skipped++;
                }
            }

            function processPage(items, nextAfter, hasMore) {
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
                                attachment_id: item.attachment_id
                            },
                            success: function(response) {
                                if (response.success) {
                                    countResult(response.data);
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
                                updateProgress();

                                if (queue.length > 0) {
                                    processNext();
                                } else if (running === 0) {
                                    if (hasMore && nextAfter > after) {
                                        after = nextAfter;
                                        loadPage();
                                    } else {
                                        finish();
                                    }
                                }
                            }
                        });
                    }

                    if (items.length === 0) {
                        if (hasMore && nextAfter > after) {
                            after = nextAfter;
                            loadPage();
                        } else {
                            finish();
                        }
                    }
                }

                processNext();
            }

            function loadPage() {
                $.ajax({
                    url: self.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'libre_compress_get_uncompressed',
                        nonce: self.nonce,
                        after: after
                    },
                    success: function(response) {
                        pageErrors = 0;

                        if (!response.success) {
                            hadError = true;
                            failed++;
                            finish();
                            return;
                        }

                        processPage(
                            response.data.items || [],
                            parseInt(response.data.next_after, 10) || after,
                            !!response.data.has_more
                        );
                    },
                    error: function() {
                        pageErrors++;

                        // 取页失败最多重试 3 次，仍失败才按中断结算，避免一次网络抖动丢下整个批量
                        if (pageErrors <= 3) {
                            loadPage();
                            return;
                        }

                        alert(self.i18n.error);
                        hadError = true;
                        failed++;
                        finish();
                    }
                });
            }

            loadPage();
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

    };

    // DOM 加载完成后初始化
    $(document).ready(function() {
        LC.init();
    });

})(jQuery);
