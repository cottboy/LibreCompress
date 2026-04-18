<?php
/**
 * Jpegoptim JPEG 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Jpegoptim JPEG 压缩渠道
 *
 * 使用 jpegoptim 命令行工具压缩 JPEG 图片
 */
class Libre_Compress_Jpegoptim extends Libre_Compress_Local_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'jpegoptim';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'jpg', 'jpeg' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'jpegoptim';
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
        $settings = get_option( 'libre_compress_local', array() );
        $quality  = isset( $settings['jpeg_quality'] ) ? absint( $settings['jpeg_quality'] ) : 80;
        $mode     = isset( $settings['jpeg_mode'] ) ? $settings['jpeg_mode'] : 'lossy';
        $lossless = ( 'lossless' === $mode );

        // 允许通过选项覆盖设置
        if ( isset( $options['quality'] ) ) {
            $quality = absint( $options['quality'] );
        }
        if ( isset( $options['lossless'] ) ) {
            $lossless = (bool) $options['lossless'];
        }

        // 确保质量在有效范围内
        $quality = max( 0, min( 100, $quality ) );

        // 构建命令
        $command_parts = array(
            escapeshellarg( $executable ),
            '--strip-all',  // 移除所有元数据
            '--all-progressive',  // 转换为渐进式 JPEG
        );

        if ( $lossless ) {
            // 无损压缩：不设置质量参数
            // jpegoptim 默认就是无损的
        } else {
            // 有损压缩：设置最大质量
            $command_parts[] = sprintf( '--max=%d', $quality );
        }

        // 添加文件路径（必须转义）
        $command_parts[] = escapeshellarg( $file_path );

        return implode( ' ', $command_parts );
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/tjko/jpegoptim';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install jpegoptim',
            'debian'  => 'sudo apt-get install jpegoptim',
            'centos'  => 'sudo yum install jpegoptim',
            'fedora'  => 'sudo dnf install jpegoptim',
            'macos'   => 'brew install jpegoptim',
            'windows' => __( '从 GitHub 下载预编译的二进制文件', 'libre-compress' ),
        );
    }
}
