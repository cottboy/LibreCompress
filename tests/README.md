# 全量回归测试

使用已启用本插件的本地 WordPress，PHP 8.1 及以上、GD、InnoDB，以及全部本地工具（包含 svgo、gif2webp、resvg、ffmpeg）。不要在生产站点运行。

```powershell
php tests/run.php C:/你的WordPress目录
```

测试会在数据库事务内创建附件、修改选项并在结束时回滚，文件全部放在本轮随机命名的 `uploads/libre-compress-tests-*` 目录，默认自动删除本轮测试文件。覆盖设置校验、全部速度档位和命令分支、实际压缩、WebP/AVIF 转换、图片回退、备份 HTTP 访问、原图恢复、动画、备份失效、安全路径和缩略图管理。

浏览器测试需要 Node.js、Playwright 和 Chromium：

```powershell
php tests/run.php C:/你的WordPress目录 --keep-files
node tests/browser.cjs http://你的站点/wp-content/uploads/本轮测试目录/ C:/浏览器测试截图目录
```

浏览器验证桌面与移动端的新格式选图、模拟不支持格式时的 PNG 回退、图片像素、默认档位、速度与质量控件的位置、设置控件交互和 JavaScript 错误。`--keep-files` 会保留带有测试标记的本轮目录，完成浏览器验证后使用以下命令逐文件清理：

```powershell
php tests/run.php C:/你的WordPress目录 --cleanup-files 本轮测试目录名
```
