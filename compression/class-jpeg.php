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
 * 有损压缩优先使用 jpegli（cjpegli），未安装时回退 jpegoptim；
 * 无损压缩始终使用 jpegoptim。jpegli 只是更好的选择，不是必需依赖。
 */
class Libre_Compress_Jpeg extends Libre_Compress_Tool_Base {

    /**
     * 当前调用的压缩选项
     *
     * @var array
     */
    private $options = array();

    /**
     * 本次是否使用 jpegli
     *
     * @var bool
     */
    private $use_jpegli = false;

    /**
     * 是否已完成模式判定
     *
     * @var bool
     */
    private $resolved = false;

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
     * 获取可执行文件名（无损与回退路径使用 jpegoptim）
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'jpegoptim';
    }

    /**
     * 记录本次实际使用的工具名
     *
     * @return string
     */
    public function get_used_tool_name(): string {
        $this->resolve_mode( $this->options );

        return $this->use_jpegli ? 'jpegli' : 'jpegoptim';
    }

    /**
     * 压缩入口
     *
     * @param string $file_path 图片绝对路径
     * @param array  $options   压缩选项
     * @return array 压缩结果
     */
    public function compress( string $file_path, array $options = array() ): array {
        $this->options  = $options;
        $this->resolved = false;
        $this->resolve_mode( $options );

        return parent::compress( $file_path, $options );
    }

    /**
     * 检查工具是否可用
     *
     * 有损模式下 jpegli 或 jpegoptim 任一可用即可；无损模式必须有 jpegoptim。
     *
     * @return bool 是否可用
     */
    public function is_tool_available(): bool {
        $this->resolve_mode( $this->options );

        if ( $this->use_jpegli ) {
            return true;
        }

        return parent::is_tool_available();
    }

    /**
     * 判定本次使用 jpegli 还是 jpegoptim
     *
     * @param array $options 压缩选项
     */
    private function resolve_mode( array $options ): void {
        if ( $this->resolved ) {
            return;
        }

        $settings = get_option( 'libre_compress_tools', array() );
        $lossless = 'lossless' === ( $settings['jpeg_mode'] ?? 'lossy' );

        if ( isset( $options['lossless'] ) ) {
            $lossless = (bool) $options['lossless'];
        }

        $this->use_jpegli = ! $lossless && false !== Libre_Compress_Output::find_local_tool( 'cjpegli' );
        $this->resolved   = true;
    }

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $this->resolve_mode( $options );

        $settings = get_option( 'libre_compress_tools', array() );
        $quality  = isset( $settings['jpeg_quality'] ) ? absint( $settings['jpeg_quality'] ) : 80;
        $lossless = 'lossless' === ( $settings['jpeg_mode'] ?? 'lossy' );

        if ( isset( $options['quality'] ) ) {
            $quality = absint( $options['quality'] );
        }
        if ( isset( $options['lossless'] ) ) {
            $lossless = (bool) $options['lossless'];
        }

        $quality = max( 0, min( 100, $quality ) );

        if ( $this->use_jpegli ) {
            // cjpegli 默认不复制 EXIF/XMP 等元数据，删除元数据设置自然生效。
            $jpegli = Libre_Compress_Output::find_local_tool( 'cjpegli' );

            return array( array(
                $jpegli,
                $file_path,
                $this->get_temp_output_path( $file_path ),
                '-q',
                (string) max( 1, $quality ),
                '--quiet',
            ) );
        }

        $executable = $this->get_executable_path();

        if ( false === $executable ) {
            return array();
        }

        // 构建命令
        $command_parts = array(
            $executable,
            '--all-progressive',  // 编码为渐进式 JPEG
        );

        // 只删隐私类元数据；ICC 色彩配置留着，删掉会让广色域图偏色。
        if ( Libre_Compress_Settings::strips_metadata() ) {
            $command_parts[] = '--strip-exif';
            $command_parts[] = '--strip-iptc';
            $command_parts[] = '--strip-com';
        } else {
            $command_parts[] = '--strip-none';
        }

        if ( ! $lossless ) {
            // 有损压缩设置最大质量；无损下不传质量参数，jpegoptim 默认就是无损的
            $command_parts[] = sprintf( '--max=%d', $quality );
        }

        $command_parts[] = $file_path;

        return array( $command_parts );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * jpegli 直接写临时文件，由基类替换原文件；jpegoptim 原地覆盖。
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        $this->resolve_mode( $this->options );

        return $this->use_jpegli ? $file_path . '.lc-jpegli.jpg' : '';
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
            'windows' => __( '从 GitHub 下载预编译的二进制文件；可选安装 libjxl 提供的 cjpegli 提升有损压缩效果', 'libre-compress' ),
        );
    }
}
