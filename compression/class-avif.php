<?php
/**
 * Avifenc AVIF 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Avifenc AVIF 压缩渠道
 *
 * 使用 libavif 的 avifenc 命令行工具压缩 AVIF 图片。
 * avifenc 不能直接读取 AVIF 文件，因此先用 avifdec 无损解码为
 * PNG 中间文件，再重新编码回 AVIF 并替换原文件。
 */
class Libre_Compress_Avif extends Libre_Compress_Tool_Base {

    /**
     * 缓存的解码器路径
     *
     * @var string|false|null
     */
    protected $decoder_path = null;

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'avifenc';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'avif' );
    }

    /**
     * 获取可执行文件名（编码器）
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'avifenc';
    }

    /**
     * 获取解码器（avifdec）路径
     *
     * 查找逻辑与 get_executable_path() 一致：优先 wp-content/LibreCompress-bin 目录，其次系统 PATH。
     * public 供设置页展示解码器的安装状态与路径。
     *
     * @return string|false 解码器路径或 false
     */
    public function get_decoder_path() {
        // 使用缓存
        if ( null !== $this->decoder_path ) {
            return $this->decoder_path;
        }

        $bin_path = LIBRE_COMPRESS_BIN_PATH . 'avifdec';

        // Windows 系统添加 .exe 后缀
        if ( $this->is_windows() ) {
            $bin_path .= '.exe';
        }

        if ( file_exists( $bin_path ) && ( $this->is_windows() || is_executable( $bin_path ) ) ) {
            $this->decoder_path = $bin_path;
            return $this->decoder_path;
        }

        $this->decoder_path = $this->find_in_system_path( 'avifdec' );
        return $this->decoder_path;
    }

    /**
     * 检查工具是否可用
     *
     * 编码器与解码器必须同时存在
     *
     * @return bool 是否可用
     */
    public function is_tool_available(): bool {
        if ( ! $this->has_encoder() ) {
            return false;
        }

        return false !== $this->get_decoder_path();
    }

    /**
     * 检查编码器是否可用
     *
     * 目标格式输出只需要 avifenc；同格式 AVIF 压缩还需要 avifdec。
     *
     * @return bool
     */
    public function has_encoder(): bool {
        return parent::is_tool_available();
    }

    /**
     * 压缩 AVIF 前确认文件只有单帧
     *
     * 当前同格式处理链只支持单张 PNG 中间文件，动画或多帧文件必须跳过，
     * 防止静默丢失动画帧。
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array
     */
    public function compress( string $file_path, array $options = array() ): array {
        $decoder = $this->get_decoder_path();

        if ( false === $decoder || ! $this->is_exec_available() ) {
            return parent::compress( $file_path, $options );
        }

        $result  = self::run_command( array( $decoder, '--info', $file_path ), 15 );
        $info    = $result['output'];
        $frames  = $this->parse_frame_count( $info );

        if ( ! $result['success'] || 0 === $frames ) {
            return array(
                'success'         => false,
                'message'         => __( '无法确认 AVIF 帧数，已停止压缩以保护动画内容', 'libre-compress' ),
                'original_size'   => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
                'compressed_size' => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
            );
        }

        if ( $frames > 1 ) {
            return array(
                'success'         => false,
                'message'         => __( '动画或多帧 AVIF 暂不支持同格式压缩，已保持原文件不变', 'libre-compress' ),
                'original_size'   => (int) filesize( $file_path ),
                'compressed_size' => (int) filesize( $file_path ),
            );
        }

        return parent::compress( $file_path, $options );
    }

    /**
     * 从 avifdec --info 输出中解析帧数
     *
     * libavif 1.x 已去掉 Image Count 字段，改成
     * "… 1.00 seconds (1 timescales), 1 frame"；旧版本才是 "Image Count: N"。
     * 两种输出都认，都读不到时返回 0，表示无法确认帧数。
     *
     * @param string $info avifdec --info 的输出
     * @return int 帧数，无法确认时返回 0
     */
    private function parse_frame_count( string $info ): int {
        if ( preg_match( '/Image\s+Count\s*:\s*(\d+)/i', $info, $matches ) ) {
            return (int) $matches[1];
        }

        // 逗号锚定，避免把 "32 worker threads" 这类数字误当成帧数。
        if ( preg_match( '/,\s*(\d+)\s+frames?\b/i', $info, $matches ) ) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * 构建压缩命令链
     *
     * 同格式 AVIF 压缩分两步：先无损解码成 PNG 中间文件，再用 avifenc 压回 AVIF。
     * 两条命令分开返回，由基类顺序执行，任一步失败即整体失败。
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $decoder = $this->get_decoder_path();
        $encoder = $this->get_executable_path();

        // 获取压缩设置
        $settings = get_option( 'libre_compress_tools', array() );
        $quality  = isset( $settings['avif_quality'] ) ? absint( $settings['avif_quality'] ) : 80;
        $mode     = isset( $settings['avif_mode'] ) ? $settings['avif_mode'] : 'lossy';
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

        // 中间 PNG 与编码结果都走确定性路径，由基类统一替换和兜底清理。
        $temp_png  = $file_path . '.lc-avif.png';
        $temp_avif = $this->get_temp_output_path( $file_path );

        $decode = array( $decoder, $file_path, $temp_png );

        $encode = array( $encoder, '-j', '4', '-s', '0' );  // 0=最慢但体积最小

        // avifenc 默认会把输入 PNG 里的 EXIF/XMP 原样搬进 AVIF，而 AVIF 是对外公开访问的文件
        if ( Libre_Compress_Settings::strips_metadata() ) {
            $encode[] = '--ignore-exif';
            $encode[] = '--ignore-xmp';
        }

        if ( $lossless ) {
            $encode[] = '--lossless';
        } else {
            $encode[] = '-q';
            $encode[] = (string) $quality;
        }

        $encode[] = $temp_png;
        $encode[] = $temp_avif;

        return array( $decode, $encode );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.lc-avif.avif';
    }

    /**
     * 获取解码中间 PNG 的路径
     *
     * 中间文件由基类兜底清理，不再由命令里的 del/rm 处理。
     *
     * @param string $file_path 文件路径
     * @return string[]
     */
    protected function get_extra_temp_paths( string $file_path ): array {
        return array( $file_path . '.lc-avif.png' );
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/AOMediaCodec/libavif';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install libavif-bin',
            'debian'  => 'sudo apt-get install libavif-bin',
            'centos'  => __( '暂无官方包，建议从 GitHub Releases 下载或自行编译', 'libre-compress' ),
            'fedora'  => 'sudo dnf install libavif-tools',
            'macos'   => 'brew install libavif',
            'windows' => __( '从 libavif GitHub Releases 下载静态编译的 avifenc.exe 和 avifdec.exe', 'libre-compress' ),
        );
    }
}
