<?php
/**
 * Cwebp WEBP 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cwebp WEBP 压缩渠道
 *
 * 使用 cwebp 命令行工具压缩 WebP 图片
 */
class Libre_Compress_Cwebp extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'cwebp';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'webp' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'cwebp';
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

        // 获取压缩设置
        $settings = get_option( 'libre_compress_tools', array() );
        $quality  = isset( $settings['webp_quality'] ) ? absint( $settings['webp_quality'] ) : 80;
        $mode     = isset( $settings['webp_mode'] ) ? $settings['webp_mode'] : 'lossy';
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

        // 编码结果先落到临时文件，替换由基类用 PHP 文件函数完成
        $temp_output = $this->get_temp_output_path( $file_path );

        // 构建命令
        $command_parts = array(
            $executable,
            '-quiet',  // 静默模式
            '-mt',     // 多线程
        );

        if ( $lossless ) {
            // 无损压缩
            $command_parts[] = '-lossless';
            $command_parts[] = '-z';
            $command_parts[] = '9';  // 最高压缩级别
        } else {
            // 有损压缩
            $command_parts[] = '-q';
            $command_parts[] = (string) $quality;
            $command_parts[] = '-m';
            $command_parts[] = '6';  // 最彻底的编码速度
        }

        // 输入和输出文件
        $command_parts[] = $file_path;
        $command_parts[] = '-o';
        $command_parts[] = $temp_output;

        return array( $command_parts );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.tmp.webp';
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://developers.google.com/speed/webp/download';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install webp',
            'debian'  => 'sudo apt-get install webp',
            'centos'  => 'sudo yum install libwebp-tools',
            'fedora'  => 'sudo dnf install libwebp-tools',
            'macos'   => 'brew install webp',
            'windows' => __( '从 Google 官网下载预编译的二进制文件', 'libre-compress' ),
        );
    }
}
