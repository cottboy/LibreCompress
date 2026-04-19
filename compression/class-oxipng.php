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
     * @return string 完整命令
     */
    protected function build_command( string $file_path, array $options ): string {
        $executable = $this->get_executable_path();

        // 获取压缩设置
        $settings = get_option( 'libre_compress_tools', array() );
        $level    = isset( $settings['png_lossless_level'] ) ? absint( $settings['png_lossless_level'] ) : 4;

        // 允许通过选项覆盖设置
        if ( isset( $options['level'] ) ) {
            $level = absint( $options['level'] );
        }

        // 确保优化级别在有效范围内（0-6）
        $level = max( 0, min( 6, $level ) );

        // 构建命令
        // -o: 优化级别（0-6）
        // --strip: 移除所有元数据
        // --quiet: 静默模式
        $command_parts = array(
            escapeshellarg( $executable ),
            sprintf( '-o %d', $level ),
            '--strip=all',  // 使用等号形式避免 Windows 命令行解析问题
            '--quiet',
            escapeshellarg( $file_path ),
        );

        return implode( ' ', $command_parts );
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/shssoichiro/oxipng';
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
