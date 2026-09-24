<?php
/**
 * Gifsicle GIF 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gifsicle GIF 压缩渠道
 *
 * 使用 gifsicle 命令行工具压缩 GIF 图片（支持静态与动画 GIF）
 */
class Libre_Compress_Gifsicle extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'gifsicle';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'gif' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'gifsicle';
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
        $quality  = isset( $settings['gif_quality'] ) ? absint( $settings['gif_quality'] ) : 60;
        $mode     = isset( $settings['gif_mode'] ) ? $settings['gif_mode'] : 'lossy';
        $lossless = ( 'lossless' === $mode );

        // 允许通过选项覆盖设置
        if ( isset( $options['quality'] ) ) {
            $quality = absint( $options['quality'] );
        }
        if ( isset( $options['lossless'] ) ) {
            $lossless = (bool) $options['lossless'];
        }

        // gifsicle 的 --lossy 参数语义与质量相反：数值越大压缩越狠、质量越差
        // 将 0-100 的质量映射为 20-200 的有损强度：质量 60 对应 gifsicle 官方推荐的 80
        $lossiness = (int) round( 200 - $quality * 2 );
        $lossiness = max( 20, min( 200, $lossiness ) );

        // 创建临时输出文件路径
        $temp_output = $file_path . '.tmp.gif';

        // 构建命令
        $command_parts = array(
            escapeshellarg( $executable ),
            '-O3',  // 最高级别无损压缩
        );

        if ( ! $lossless ) {
            // 有损压缩
            $command_parts[] = sprintf( '--lossy=%d', $lossiness );
        }

        $command_parts[] = '-o';
        $command_parts[] = escapeshellarg( $temp_output );
        $command_parts[] = escapeshellarg( $file_path );

        // 压缩成功后替换原文件（move /y 直接覆盖，压缩失败时不会破坏原文件）
        if ( $this->is_windows() ) {
            $move_command = sprintf(
                '&& move /y "%s" "%s"',
                str_replace( '/', '\\', $temp_output ),
                str_replace( '/', '\\', $file_path )
            );
        } else {
            $move_command = sprintf( '&& mv -f %s %s', escapeshellarg( $temp_output ), escapeshellarg( $file_path ) );
        }

        return implode( ' ', $command_parts ) . ' ' . $move_command;
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://www.lcdf.org/gifsicle/';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install gifsicle',
            'debian'  => 'sudo apt-get install gifsicle',
            'centos'  => 'sudo yum install gifsicle',
            'fedora'  => 'sudo dnf install gifsicle',
            'macos'   => 'brew install gifsicle',
            'windows' => __( '从 eternallybored.org 下载预编译的 gifsicle.exe', 'libre-compress' ),
        );
    }
}
