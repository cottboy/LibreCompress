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
        $args = self::build_encode_args( 'webp_', $file_path, $this->get_temp_output_path( $file_path ), $options );

        return array( array_merge( array( $this->get_executable_path() ), $args ) );
    }

    /**
     * 构建 cwebp 的参数
     *
     * 同格式压缩与格式转换共用这一份参数拼装，避免两边各写一遍走样。
     * -preset 必须排在其它编码参数前面：它会重置质量等方法参数，后面的显式值才生效。
     *
     * @param string $prefix  设置前缀：同格式压缩用 webp_，格式转换用 convert_webp_
     * @param string $source  源文件路径
     * @param string $output  输出文件路径
     * @param array  $options 调用方覆盖项（quality / lossless）
     * @return array 参数数组（不含可执行文件）
     */
    public static function build_encode_args( string $prefix, string $source, string $output, array $options = array() ): array {
        $settings = get_option( 'libre_compress_tools', array() );
        $mode     = isset( $settings[ $prefix . 'mode' ] ) ? $settings[ $prefix . 'mode' ] : 'lossy';
        $quality  = isset( $settings[ $prefix . 'quality' ] ) ? absint( $settings[ $prefix . 'quality' ] ) : 80;

        if ( isset( $options['quality'] ) ) {
            $quality = absint( $options['quality'] );
        }
        if ( isset( $options['lossless'] ) ) {
            $mode = $options['lossless'] ? 'lossless' : 'lossy';
        }

        $quality = max( 0, min( 100, $quality ) );

        $args = array(
            '-quiet',
            '-mt',
            '-preset',
            Libre_Compress_Settings::tool_choice( 'webp_preset' ),
        );

        if ( 'lossless' === $mode ) {
            $args[] = '-lossless';
            $args[] = '-z';
            $args[] = (string) Libre_Compress_Settings::tool_speed( $prefix . 'lossless_level' );
        } else {
            $args[] = '-q';
            $args[] = (string) $quality;
            $args[] = '-m';
            $args[] = (string) Libre_Compress_Settings::tool_speed( $prefix . 'method' );
        }

        // 透明通道单独编码，默认 100 即 alpha 无损；这里始终显式传入，省得默认值变化时悄悄掉画质。
        $args[] = '-alpha_q';
        $args[] = (string) Libre_Compress_Settings::tool_quality( 'webp_alpha_quality', 100 );

        // 透明区域的 RGB 默认会被压平，开源图或要再做透明处理时把它保下来。
        if ( Libre_Compress_Settings::tool_flag( 'webp_exact' ) ) {
            $args[] = '-exact';
        }

        // cwebp 默认不复制任何元数据；删除元数据时只保留 ICC，避免广色域图偏色。
        $args[] = '-metadata';
        $args[] = Libre_Compress_Settings::strips_metadata() ? 'icc' : 'all';

        $args[] = $source;
        $args[] = '-o';
        $args[] = $output;

        return $args;
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
