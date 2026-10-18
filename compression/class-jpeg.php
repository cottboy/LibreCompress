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
 * 有损压缩优先使用 jpegli，未安装时回退 jpegoptim；无损压缩始终使用 jpegoptim。
 *
 * cjpegli 只能读 PPM / PNM / PFM / PAM / PGX，读不了 JPEG，所以 jpegli 这条路上
 * 必须先由 djpegli 把 JPEG 解成 PPM，再交给 cjpegli 重新编码。少了这一步，
 * cjpegli 会直接报「Failed to decode input image」退出，压缩永远失败。
 */
class Libre_Compress_Jpeg extends Libre_Compress_Tool_Base {

    /**
     * 当前调用的压缩选项
     *
     * @var array
     */
    private $options = array();

    /**
     * 本次实际使用的编码器：cjpegli|jpegoptim
     *
     * @var string
     */
    private $encoder = '';

    /**
     * 本次是否无损压缩
     *
     * @var bool
     */
    private $lossless = false;

    /**
     * 是否已完成编码器判定
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
        return 'cjpegli' === $this->resolve_encoder( $this->options ) ? 'jpegli' : 'jpegoptim';
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
        $this->resolve_encoder( $options );

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
        if ( 'cjpegli' === $this->resolve_encoder( $this->options ) ) {
            return true;
        }

        return parent::is_tool_available();
    }

    /**
     * 判定本次使用 jpegli 还是 jpegoptim
     *
     * jpegli 需要 cjpegli 与 djpegli 同时存在：解码一步由 djpegli 完成，缺一个都跑不起来。
     * 设置了「只用 jpegli」但文件不齐时退回 jpegoptim，压缩继续，不会整条路径失效。
     *
     * @param array $options 压缩选项
     * @return string cjpegli|jpegoptim
     */
    private function resolve_encoder( array $options ): string {
        if ( $this->resolved ) {
            return $this->encoder;
        }

        $this->resolved = true;

        $lossless = 'lossless' === Libre_Compress_Settings::param_mode( 'jpeg' );

        $this->lossless = $lossless;

        $preference = Libre_Compress_Settings::param_choice( 'jpeg_encoder' );
        $jpegli     = false !== Libre_Compress_Output::find_local_tool( 'cjpegli' ) && false !== Libre_Compress_Output::find_local_tool( 'djpegli' );

        $this->encoder = ( ! $lossless && 'jpegoptim' !== $preference && $jpegli ) ? 'cjpegli' : 'jpegoptim';

        return $this->encoder;
    }

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $quality = Libre_Compress_Settings::param_int( 'jpeg_quality' );

        if ( 'cjpegli' === $this->resolve_encoder( $options ) ) {
            return $this->build_jpegli_chain( $file_path, $quality );
        }

        return $this->build_jpegoptim_chain( $file_path, $quality, $this->lossless );
    }

    /**
     * jpegli 命令链：djpegli 解码到 PPM，再交给 cjpegli 编码
     *
     * @param string $file_path 文件路径
     * @param int    $quality   有损质量
     * @return array[]
     */
    private function build_jpegli_chain( string $file_path, int $quality ): array {
        $decoder = Libre_Compress_Output::find_local_tool( 'djpegli' );
        $encoder = Libre_Compress_Output::find_local_tool( 'cjpegli' );

        if ( false === $decoder || false === $encoder ) {
            return array();
        }

        $decode = array(
            $decoder,
            $file_path,
            $this->get_decode_temp_path( $file_path ),
            '--bitdepth=8',
            '--quiet',
        );

        $encode = array(
            $encoder,
            $this->get_decode_temp_path( $file_path ),
            $this->get_temp_output_path( $file_path ),
            '-q',
            (string) $quality,
        );

        $progressive = Libre_Compress_Settings::param_choice( 'jpeg_progressive' );

        if ( 'normal' === $progressive ) {
            $encode[] = '-p';
            $encode[] = '0';
        } elseif ( 'progressive' === $progressive ) {
            $encode[] = '-p';
            $encode[] = '2';
        }

        $chroma = Libre_Compress_Settings::param_choice( 'jpeg_chroma' );

        if ( 'auto' !== $chroma ) {
            $encode[] = '--chroma_subsampling=' . $chroma;
        }

        $encode[] = '--quiet';

        return array( $decode, $encode );
    }

    /**
     * jpegoptim 命令：原地覆盖
     *
     * @param string $file_path 文件路径
     * @param int    $quality   有损质量
     * @param bool   $lossless  是否无损
     * @return array[]
     */
    private function build_jpegoptim_chain( string $file_path, int $quality, bool $lossless ): array {
        $executable = $this->get_executable_path();

        if ( false === $executable ) {
            return array();
        }

        $command = array( $executable, '--quiet' );

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
            $command[] = '--max=' . $quality;
        }

        $command[] = $file_path;

        return array( $command );
    }

    /**
     * jpegli 解码出来的 PPM 中间文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    private function get_decode_temp_path( string $file_path ): string {
        return $file_path . '.lc-jpegli.ppm';
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
        return 'cjpegli' === $this->resolve_encoder( $this->options ) ? $file_path . '.lc-jpegli.jpg' : '';
    }

    /**
     * 获取解码中间文件
     *
     * @param string $file_path 文件路径
     * @return string[]
     */
    protected function get_extra_temp_paths( string $file_path ): array {
        return 'cjpegli' === $this->resolve_encoder( $this->options )
            ? array( $this->get_decode_temp_path( $file_path ) )
            : array();
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
            'windows' => __( '从 GitHub 下载预编译的 jpegoptim.exe；要用 jpegli 请把 jpegli 项目编译出的 cjpegli.exe 与 djpegli.exe 一起放进工具目录', 'libre-compress' ),
        );
    }
}
