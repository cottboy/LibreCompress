<?php
/**
 * JPEG 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JPEG 压缩渠道
 *
 * 有损与无损压缩全部由 jpegoptim 完成，原地覆盖原文件。
 *
 * 有损：--max 设定允许的最高质量；
 * 无损：不传任何质量参数，jpegoptim 只重排压缩数据，画面像素不变。
 */
class Libre_Compress_Jpeg extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'jpeg';
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
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $executable = $this->get_executable_path();

        if ( false === $executable ) {
            return array();
        }

        $lossless = 'lossless' === Libre_Compress_Settings::param_mode( 'jpeg' );
        $command  = array( $executable, '--quiet' );

        // 渐进式、顺序式与自动挑选直接对应 jpegoptim 的三个开关。
        $progressive = Libre_Compress_Settings::param_choice( 'jpeg_progressive' );

        if ( 'normal' === $progressive ) {
            $command[] = '--all-normal';
        } elseif ( 'progressive' === $progressive ) {
            $command[] = '--all-progressive';
        } else {
            $command[] = '--auto-mode';
        }

        // 只删隐私类元数据；ICC 色彩配置留着，删掉会让广色域图偏色。
        if ( Libre_Compress_Settings::strips_metadata() ) {
            $command[] = '--strip-exif';
            $command[] = '--strip-xmp';
            $command[] = '--strip-iptc';
            $command[] = '--strip-com';
        } else {
            $command[] = '--strip-none';
        }

        if ( ! $lossless ) {
            // 有损压缩设置最大质量；无损下不传质量参数，jpegoptim 默认就是无损的
            $command[] = '--max=' . Libre_Compress_Settings::param_int( 'jpeg_quality' );
        }

        $command[] = $file_path;

        return array( $command );
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
            'windows' => __( '从 GitHub 下载预编译的 jpegoptim.exe', 'libre-compress' ),
        );
    }
}
