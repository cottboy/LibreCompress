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
     * 当前调用产生的临时文件
     *
     * @var array
     */
    private $temporary_files = array();

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
        $this->temporary_files = array();
        $decoder = $this->get_decoder_path();

        if ( false === $decoder || ! $this->is_exec_available() ) {
            return parent::compress( $file_path, $options );
        }

        $command = escapeshellarg( $decoder ) . ' --info ' . escapeshellarg( $file_path );
        $result  = self::run_command( $command, 15 );
        $info    = $result['output'];

        if ( ! $result['success'] || ! preg_match( '/Image\s+Count\s*:\s*(\d+)/i', $info, $matches ) ) {
            return array(
                'success'         => false,
                'message'         => __( '无法确认 AVIF 帧数，已停止压缩以保护动画内容', 'libre-compress' ),
                'original_size'   => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
                'compressed_size' => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
            );
        }

        if ( (int) $matches[1] > 1 ) {
            return array(
                'success'         => false,
                'message'         => __( '动画或多帧 AVIF 暂不支持同格式压缩，已保持原文件不变', 'libre-compress' ),
                'original_size'   => (int) filesize( $file_path ),
                'compressed_size' => (int) filesize( $file_path ),
            );
        }

        $result = parent::compress( $file_path, $options );
        foreach ( $this->temporary_files as $temporary_file ) {
            if ( file_exists( $temporary_file ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $temporary_file );
            }
        }
        $this->temporary_files = array();

        return $result;
    }

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return string 完整命令
     */
    protected function build_command( string $file_path, array $options ): string {
        $decoder = escapeshellarg( $this->get_decoder_path() );
        $encoder = escapeshellarg( $this->get_executable_path() );

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

        // 临时文件：使用唯一名称，避免异常重试互相覆盖。
        $token           = wp_generate_password( 12, false, false );
        $temp_png        = $file_path . '.lc-avif-' . $token . '.png';
        $temp_avif       = $file_path . '.lc-avif-' . $token . '.avif';
        $this->temporary_files = array( $temp_png, $temp_avif );

        // 第一步：无损解码为 PNG，保留完整像素
        $command_parts = array(
            $decoder,
            escapeshellarg( $file_path ),
            escapeshellarg( $temp_png ),
            '&&',
            $encoder,
            '-j 4',  // 编码线程数
        );

        if ( $lossless ) {
            // 无损压缩
            $command_parts[] = '--lossless';
        } else {
            // 有损压缩
            $command_parts[] = sprintf( '-q %d', $quality );
        }

        // 第二步：重新编码回 AVIF
        $command_parts[] = escapeshellarg( $temp_png );
        $command_parts[] = escapeshellarg( $temp_avif );

        // 编码成功后清理中间文件并替换原文件（move/mv -f 直接覆盖，任何一步失败都不会破坏原文件）
        if ( $this->is_windows() ) {
            $command_parts[] = sprintf(
                '&& del /f /q "%s" && move /y "%s" "%s"',
                str_replace( '/', '\\', $temp_png ),
                str_replace( '/', '\\', $temp_avif ),
                str_replace( '/', '\\', $file_path )
            );
        } else {
            $command_parts[] = sprintf(
                '&& rm -f %s && mv -f %s %s',
                escapeshellarg( $temp_png ),
                escapeshellarg( $temp_avif ),
                escapeshellarg( $file_path )
            );
        }

        return implode( ' ', $command_parts );
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
