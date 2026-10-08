<?php
/**
 * Oxipng PNG 无损压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Oxipng PNG 无损压缩渠道
 *
 * 使用 oxipng 命令行工具进行 PNG 无损压缩
 */
class Libre_Compress_Oxipng extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'oxipng';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'png' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'oxipng';
    }

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $executable = $this->get_executable_path();

        $level = Libre_Compress_Settings::tool_speed( 'oxipng_level' );

        // 构建命令：-o 无损级别，--strip 元数据取舍，--quiet 静默
        return array( array(
            $executable,
            sprintf( '-o%d', $level ),
            Libre_Compress_Settings::strips_metadata() ? '--strip=safe' : '--strip=none',
            '--quiet',
            $file_path,
        ) );
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/oxipng/oxipng';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'cargo install oxipng',
            'debian'  => 'cargo install oxipng',
            'centos'  => 'cargo install oxipng',
            'fedora'  => 'sudo dnf install oxipng',
            'macos'   => 'brew install oxipng',
            'windows' => __( '从 GitHub 下载预编译的二进制文件', 'libre-compress' ),
        );
    }
}
