<?php
/**
 * Pngquant PNG 有损压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pngquant PNG 有损压缩渠道
 *
 * 使用 pngquant 命令行工具进行 PNG 有损压缩
 */
class Libre_Compress_Pngquant extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'pngquant';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'png' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'pngquant';
    }

    /**
     * 压缩 PNG 图片
     *
     * pngquant 在 --skip-if-larger 跳过（结果更大）时以 98 退出、在质量不达标时以 99
     * 退出，两种情况都保持原文件不动：这正是“没有压缩收益，保留原图”的情形，
     * 按成功返回，交由上层记为 0% 已压缩，避免被误记为失败导致反复重试。
     * 文件内容有任何变动都仍按失败处理，防止把真正的错误藏起来。
     *
     * @param string $file_path 图片绝对路径
     * @param array  $options   压缩选项
     * @return array 压缩结果
     */
    public function compress( string $file_path, array $options = array() ): array {
        if ( ! $this->is_tool_available() ) {
            return parent::compress( $file_path, $options );
        }

        if ( ! file_exists( $file_path ) ) {
            return parent::compress( $file_path, $options );
        }

        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, $this->get_supported_formats(), true ) ) {
            return parent::compress( $file_path, $options );
        }

        $original_size = filesize( $file_path );
        $command       = $this->build_command( $file_path, $options );
        $result        = $this->execute_command( $command );

        if ( $result['success'] ) {
            clearstatcache( true, $file_path );
            $compressed_size = filesize( $file_path );

            return array(
                'success'         => true,
                'message'         => __( '压缩成功', 'libre-compress' ),
                'original_size'   => $original_size,
                'compressed_size' => $compressed_size,
            );
        }

        $skipped = isset( $result['return_code'] ) && in_array( (int) $result['return_code'], array( 98, 99 ), true );

        if ( $skipped && file_exists( $file_path ) ) {
            clearstatcache( true, $file_path );

            if ( (int) filesize( $file_path ) === (int) $original_size ) {
                return array(
                    'success'         => true,
                    'message'         => __( '压缩成功', 'libre-compress' ),
                    'original_size'   => $original_size,
                    'compressed_size' => $original_size,
                );
            }
        }

        return array(
            'success'         => false,
            'message'         => $result['output'],
            'original_size'   => $original_size,
            'compressed_size' => $original_size,
        );
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
        $quality  = isset( $settings['png_lossy_quality'] ) ? absint( $settings['png_lossy_quality'] ) : 80;

        // 允许通过选项覆盖设置
        if ( isset( $options['quality'] ) ) {
            $quality = absint( $options['quality'] );
        }

        // 确保质量在有效范围内
        $quality = max( 0, min( 100, $quality ) );

        // pngquant 使用质量范围，最小值设为质量的一半
        $min_quality = max( 0, $quality - 20 );

        // 构建命令
        // --force: 覆盖输出文件
        // --output: 指定输出文件（覆盖原文件）
        // --quality: 设置质量范围
        // --skip-if-larger: 如果压缩后更大则跳过
        $command_parts = array(
            escapeshellarg( $executable ),
            '--force',
            '--skip-if-larger',
            sprintf( '--quality=%d-%d', $min_quality, $quality ),
        );

        // pngquant 只在 macOS 上默认清元数据，其他平台要显式加 --strip
        if ( Libre_Compress_Settings::strips_metadata() ) {
            $command_parts[] = '--strip';
        }

        $command_parts[] = '--output';
        $command_parts[] = escapeshellarg( $file_path );
        $command_parts[] = escapeshellarg( $file_path );

        return implode( ' ', $command_parts );
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://pngquant.org';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install pngquant',
            'debian'  => 'sudo apt-get install pngquant',
            'centos'  => 'sudo yum install pngquant',
            'fedora'  => 'sudo dnf install pngquant',
            'macos'   => 'brew install pngquant',
            'windows' => __( '从官网下载预编译的二进制文件', 'libre-compress' ),
        );
    }
}
