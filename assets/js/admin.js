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
        taskState: libreCompressData.taskState || { has_task: false },

        // 批量任务：面板、轮询定时器、连续取不到状态的次数
        $panel: null,
        taskTimer: null,
        taskErrors: 0,

        /**
         * 批量按钮与后台任务类型的对应关系
         */
        batchButtons: {
            '#libre-compress-bulk-compress': { type: 'compress', confirm: 'confirmBulkCompress' },
            '#libre-compress-clear-records': { type: 'clear', confirm: 'confirmClear' },
            '#libre-compress-restore-all': { type: 'restore', confirm: 'confirmRestoreAll' },
            '#libre-compress-delete-all-backups': { type: 'delete_backups', confirm: 'confirmDeleteAllBackups' },
            '#libre-compress-delete-all-fallbacks': { type: 'delete_fallbacks', confirm: 'confirmDeleteAllFallbacks' },
            '#libre-compress-delete-thumbnails': { type: 'delete_thumbnails', confirm: 'confirmDeleteThumbnails' },
            '#libre-compress-generate-thumbnails': { type: 'generate_thumbnails', confirm: 'confirmGenerateThumbnails' }
        },

        /**
         * 初始化
         */
        init: function() {
            this.bindEvents();
            this.initTaskPanel();
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            var self = this;

            // 媒体库压缩/恢复按钮
            $(document).on('click', '.libre-compress-btn', this.handleMediaLibraryAction.bind(this));

            // 批量按钮：只是把任务交给服务器，之后由面板显示进度
            $.each(this.batchButtons, function(selector, config) {
                $(document).on('click', selector, function(e) {
                    e.preventDefault();
                    self.startTask(config.type, config.confirm ? self.i18n[config.confirm] : '');
                });
            });

            // 任务面板上的按钮
            $(document).on('click', '#libre-compress-task-stop', this.stopTask.bind(this));
            $(document).on('click', '#libre-compress-task-resume', this.resumeTask.bind(this));
            $(document).on('click', '#libre-compress-task-retry', this.retryTask.bind(this));
            $(document).on('click', '#libre-compress-task-dismiss', this.dismissTask.bind(this));

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

            if (action === 'details') {
                this.showDetails(attachmentId);
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
            } else if (action === 'delete-fallback') {
                this.deleteFallback(attachmentId, $btn);
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
         * 显示单张图片的空间详情。
         */
        showDetails: function(attachmentId) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_get_details',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (!response.success || !response.data) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        return;
                    }

                    self.renderDetailsModal(response.data);
                },
                error: function() {
                    alert(self.i18n.error);
                }
            });
        },

        /**
         * 安全转义详情弹窗里的文本。
         */
        escapeHtml: function(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        },

        /**
         * 渲染详情弹窗。
         */
        renderDetailsModal: function(details) {
            var self = this;
            var i18n = this.i18n;
            var rows = details.items || [];
            var html = '';

            html += '<div class="libre-compress-details-modal" role="dialog" aria-modal="true">';
            html += '<div class="libre-compress-details-dialog">';
            html += '<div class="libre-compress-details-head">';
            html += '<h2>' + this.escapeHtml(i18n.detailsTitle) + '</h2>';
            html += '<button type="button" class="button-link libre-compress-details-close">' + this.escapeHtml(i18n.detailsClose) + '</button>';
            html += '</div>';
            html += '<table class="libre-compress-details-table"><thead><tr>';
            html += '<th>' + this.escapeHtml(i18n.detailsGroup) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsItem) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsFormat) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsStatus) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsOriginalFileSize) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsCurrentSize) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsSavedSize) + '</th>';
            html += '</tr></thead><tbody>';

            if (!rows.length) {
                html += '<tr><td colspan="7">' + this.escapeHtml(i18n.detailsEmpty) + '</td></tr>';
            } else {
                $.each(rows, function(index, row) {
                    html += '<tr>';
                    html += '<td>' + self.escapeHtml(row.group) + '</td>';
                    html += '<td>' + self.escapeHtml(row.name);
                    if (row.file_name) {
                        html += '<br><small>' + self.escapeHtml(row.file_name) + '</small>';
                    }
                    html += '</td>';
                    html += '<td>' + self.escapeHtml(row.format) + '</td>';
                    html += '<td>' + self.escapeHtml(row.status) + '</td>';
                    html += '<td>' + self.escapeHtml(self.formatBytes(row.original_size)) + '</td>';
                    html += '<td>' + self.escapeHtml(self.formatBytes(row.current_size)) + '</td>';
                    html += '<td>' + self.escapeHtml(self.formatBytes(row.saved_size)) + '</td>';
                    html += '</tr>';
                });
            }

            html += '</tbody></table></div></div>';

            $('.libre-compress-details-modal').remove();
            $('body').append(html);

            var $modal = $('.libre-compress-details-modal').last();

            function closeModal() {
                $modal.remove();
                $(document).off('keydown.libreCompressDetails');
            }

            $modal.on('click', function(e) {
                if (e.target === this || $(e.target).hasClass('libre-compress-details-close')) {
                    closeModal();
                }
            });
            $(document).off('keydown.libreCompressDetails').on('keydown.libreCompressDetails', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                }
            });
        },

        /**
         * 格式化详情中的文件大小。
         */
        formatBytes: function(bytes) {
            var value = Math.max(0, parseInt(bytes, 10) || 0);
            var units = ['B', 'KB', 'MB', 'GB', 'TB'];
            var index = 0;

            while (value >= 1024 && index < units.length - 1) {
                value = value / 1024;
                index++;
            }

            if (index === 0) {
                return value + units[index];
            }

            return parseFloat(value.toFixed(2)) + units[index];
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
         * 删除单张图片的兼容格式回退
         */
        deleteFallback: function(attachmentId, $btn) {
            var self = this;

            if (!confirm(this.i18n.confirmDeleteFallback)) {
                $btn.prop('disabled', false).text(self.i18n.deleteFallback);
                return;
            }

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'libre_compress_delete_fallback',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text(self.i18n.deleteFallback);
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text(self.i18n.deleteFallback);
                }
            });
        },

        // ===== 批量任务 =====
        // 真正干活的是服务器（见 includes/class-task.php），页面只负责把进度画出来。
        // 所以关掉浏览器任务照样继续，重新打开这个页面还能接着看进度。

        /**
         * 页面载入时按服务端给的状态恢复面板
         */
        initTaskPanel: function() {
            this.$panel = $('#libre-compress-task');

            if (!this.$panel.length) {
                this.$panel = null;
                return;
            }

            if (this.taskState && this.taskState.has_task) {
                this.renderTask(this.taskState);

                if (this.taskState.status === 'running') {
                    this.startPolling();
                }
            } else {
                this.$panel.hide();
            }
        },

        /**
         * 开始一个批量任务
         */
        startTask: function(type, confirmText) {
            if (confirmText && !confirm(confirmText)) {
                return;
            }

            var self = this;

            this.lockBatchButtons(true);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: 'libre_compress_task_start',
                    nonce: this.nonce,
                    type: type
                },
                success: function(response) {
                    self.lockBatchButtons(false);

                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        return;
                    }

                    self.renderTask(response.data);
                    self.startPolling();
                },
                error: function() {
                    self.lockBatchButtons(false);
                    alert(self.i18n.error);
                }
            });
        },

        /**
         * 停止任务
         */
        stopTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            if (!confirm(this.i18n.taskStopConfirm)) {
                return;
            }

            this.taskAction('libre_compress_task_stop');
        },

        /**
         * 继续执行：页面轮询本来就会补一棒，这里手动催一下并立刻刷新状态
         */
        resumeTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            this.taskAction('libre_compress_task_status');
        },

        /**
         * 只重跑失败的项目
         */
        retryTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            this.taskAction('libre_compress_task_retry');
        },

        /**
         * 关掉结果面板（服务端直接删掉任务工单，刷新页面也不会再冒出来）
         */
        dismissTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            var self = this;

            this.stopPolling();

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 30000,
                data: {
                    action: 'libre_compress_task_dismiss',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        return;
                    }

                    self.taskState = { has_task: false };

                    if (self.$panel) {
                        self.$panel.hide();
                    }

                    self.lockBatchButtons(false);
                },
                error: function() {
                    alert(self.i18n.error);
                }
            });
        },

        /**
         * 发送一个任务操作请求
         */
        taskAction: function(action) {
            var self = this;

            this.stopPolling();

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: action,
                    nonce: this.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);

                        if (self.taskState && self.taskState.status === 'running') {
                            self.startPolling();
                        }
                        return;
                    }

                    self.renderTask(response.data);

                    if (response.data && response.data.status === 'running') {
                        self.startPolling();
                    }
                },
                error: function() {
                    alert(self.i18n.error);

                    if (self.taskState && self.taskState.status === 'running') {
                        self.startPolling();
                    }
                }
            });
        },

        /**
         * 开始轮询任务状态
         */
        startPolling: function() {
            var self = this;

            this.stopPolling();

            this.taskTimer = setTimeout(function() {
                self.pollStatus();
            }, 2000);
        },

        /**
         * 停止轮询
         */
        stopPolling: function() {
            if (this.taskTimer) {
                clearTimeout(this.taskTimer);
                this.taskTimer = null;
            }
        },

        /**
         * 取一次任务状态
         */
        pollStatus: function() {
            var self = this;

            this.taskTimer = null;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: 'libre_compress_task_status',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        self.pollFailed();
                        return;
                    }

                    self.taskErrors = 0;
                    self.renderTask(response.data);

                    if (response.data && response.data.status === 'running') {
                        self.startPolling();
                    }
                },
                error: function() {
                    self.pollFailed();
                }
            });
        },

        /**
         * 一次状态查询失败：连着失败太多次就别再空转，免得一直打服务器
         */
        pollFailed: function() {
            this.taskErrors++;

            if (this.taskErrors < 5) {
                this.startPolling();
                return;
            }

            this.showPanelNote(this.i18n.error);
        },

        /**
         * 画出任务面板
         */
        renderTask: function(state) {
            if (!this.$panel || !state || !state.has_task) {
                if (this.$panel) {
                    this.$panel.hide();
                }
                return;
            }

            this.taskState = state;
            this.$panel.show();
            this.$panel.toggleClass('is-done', state.status === 'done');
            this.$panel.toggleClass('is-stopped', state.status === 'stopped');

            var percent = Math.max(0, Math.min(100, parseInt(state.percent, 10) || 0));
            var progress = (state.scanned ? this.i18n.taskScanned : this.i18n.taskProgress)
                .replace('%1$d', state.progress)
                .replace('%2$d', state.total);
            var counts = this.i18n.taskCounts
                .replace('%1$d', state.success)
                .replace('%2$d', state.skipped)
                .replace('%3$d', state.failed);
            var elapsed = this.i18n.taskElapsed.replace('%s', this.formatDuration(state.elapsed));
            var current = state.current ? this.i18n.taskCurrent.replace('%s', state.current) : '';

            if (state.stalled && state.status === 'running') {
                current = this.i18n.taskStalled;
            }

            this.$panel.find('.task-label').text(state.label || '');
            this.$panel.find('.task-status').text(this.statusText(state));
            this.$panel.find('.task-bar-fill').css('width', percent + '%');
            this.$panel.find('.task-bar-text').text(percent + '%');
            this.$panel.find('.task-progress').text(progress + '　' + elapsed);
            this.$panel.find('.task-counts').text(counts);
            this.$panel.find('.task-current').text(current);
            this.$panel.find('.task-summary').text(state.summary || '');

            var running = state.status === 'running';

            this.$panel.find('#libre-compress-task-stop').toggle(running);
            this.$panel.find('#libre-compress-task-resume').toggle(running && !!state.stalled);
            this.$panel.find('#libre-compress-task-dismiss').toggle(!running);

            var retryCount = parseInt(state.retry_count, 10) || 0;
            var $retry = this.$panel.find('#libre-compress-task-retry');

            $retry.toggle(!running && retryCount > 0);

            if (retryCount > 0) {
                $retry.text(this.i18n.taskRetry.replace('%d', retryCount));
            }

            this.lockBatchButtons(running);
        },

        /**
         * 面板上的一行提示（轮询彻底失败时用）
         */
        showPanelNote: function(text) {
            if (!this.$panel) {
                return;
            }

            this.$panel.show();
            this.$panel.find('.task-current').text(text);
        },

        /**
         * 任务状态文字
         */
        statusText: function(state) {
            if (state.status === 'done') {
                return this.i18n.taskDone;
            }

            if (state.status === 'stopped') {
                return this.i18n.taskStopped;
            }

            return state.stalled ? this.i18n.taskStalled : this.i18n.taskRunning;
        },

        /**
         * 任务跑着的时候禁掉所有批量按钮，避免重复启动
         */
        lockBatchButtons: function(disabled) {
            $.each(this.batchButtons, function(selector) {
                $(selector).prop('disabled', !!disabled);
            });
        },

        /**
         * 秒数转“几分几秒”
         */
        formatDuration: function(seconds) {
            var value = Math.max(0, parseInt(seconds, 10) || 0);

            if (value < 60) {
                return this.i18n.taskSeconds.replace('%d', value);
            }

            return this.i18n.taskMinutes
                .replace('%1$d', Math.floor(value / 60))
                .replace('%2$d', value % 60);
        }
    };

    // DOM 加载完成后初始化
    $(document).ready(function() {
        LC.init();
    });

})(jQuery);
