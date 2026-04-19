<?php
/**
 * Pngquant PNG 有损压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pngquant PNG 有损压缩渠道
 *
 * 使用 pngquant 命令行工具进行 PNG 有损压缩
 */
class Libre_Compress_Pngquant extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'pngquant';
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
        return 'pngquant';
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
        $quality  = isset( $settings['png_lossy_quality'] ) ? absint( $settings['png_lossy_quality'] ) : 80;

        // 允许通过选项覆盖设置
        if ( isset( $options['quality'] ) ) {
            $quality = absint( $options['quality'] );
        }

        // 确保质量在有效范围内
        $quality = max( 0, min( 100, $quality ) );

        // pngquant 使用质量范围，最小值设为质量的一半
        $min_quality = max( 0, $quality - 20 );

        // 构建命令
        // --force: 覆盖输出文件
        // --output: 指定输出文件（覆盖原文件）
        // --quality: 设置质量范围
        // --skip-if-larger: 如果压缩后更大则跳过
        $command_parts = array(
            escapeshellarg( $executable ),
            '--force',
            '--skip-if-larger',
            sprintf( '--quality=%d-%d', $min_quality, $quality ),
            '--output',
            escapeshellarg( $file_path ),
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
        return 'https://pngquant.org';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install pngquant',
            'debian'  => 'sudo apt-get install pngquant',
            'centos'  => 'sudo yum install pngquant',
            'fedora'  => 'sudo dnf install pngquant',
            'macos'   => 'brew install pngquant',
            'windows' => __( '从官网下载预编译的二进制文件', 'libre-compress' ),
        );
    }
}
