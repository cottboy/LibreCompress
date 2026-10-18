<?php
/**
 * oxvg SVG 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * oxvg SVG 压缩渠道
 *
 * 用 Rust 写的 oxvg 压缩 SVG：单文件可执行程序，不需要 Node.js。
 * 命令行与 svgo 完全不同——oxvg 用子命令，优化力度靠 -e 预设指定。
 */
class Libre_Compress_Oxvg extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'oxvg';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'svg' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'oxvg';
    }

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $executable  = $this->get_executable_path();
        $temp_output = $this->get_temp_output_path( $file_path );
        $preset      = Libre_Compress_Settings::param_choice( 'svg_preset' );

        // default 是常规优化预设；safe 更保守，几乎不改视觉。
        return array(
            array(
                $executable,
                'optimise',
                '-e',
                $preset,
                '-o',
                $temp_output,
                $file_path,
            ),
        );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.tmp.svg';
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/noahbald/oxvg/releases';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'cargo install oxvg',
            'debian'  => 'cargo install oxvg',
            'centos'  => 'cargo install oxvg',
            'fedora'  => 'cargo install oxvg',
            'macos'   => 'brew install oxvg',
            'windows' => __( '从发布页下载 oxvg.exe 放进工具目录（x64 请用 v0.0.6，之后的版本只发了 ARM64）', 'libre-compress' ),
        );
    }
}
