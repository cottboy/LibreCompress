<?php
/**
 * SVGO SVG 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SVGO SVG 压缩渠道
 *
 * 使用 svgo 命令行工具压缩 SVG 图片（需要系统安装 Node.js）
 */
class Libre_Compress_Svgo extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'svgo';
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
        return 'svgo';
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

        // 获取压缩精度设置（数值有效位数，越小文件越小但可能损失精度）
        $settings  = get_option( 'libre_compress_tools', array() );
        $precision = isset( $settings['svg_precision'] ) ? absint( $settings['svg_precision'] ) : 3;

        // 允许通过选项覆盖设置
        if ( isset( $options['precision'] ) ) {
            $precision = absint( $options['precision'] );
        }

        // 确保精度在有效范围内（svgo 默认 3，8 已接近无损）
        $precision = max( 0, min( 8, $precision ) );

        // 编码结果先落到临时文件，替换由基类用 PHP 文件函数完成
        $temp_output = $this->get_temp_output_path( $file_path );

        // 构建命令：-q 静默输出，--multipass 多轮压缩更彻底
        return array( array(
            $executable,
            '-q',
            '--multipass',
            sprintf( '-p%d', $precision ),
            '-i',
            $file_path,
            '-o',
            $temp_output,
        ) );
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
     * 在系统 PATH 中查找可执行文件
     *
     * npm 全局安装的 svgo 在 Windows 上是无扩展名的 shell 脚本和 .cmd 各一个，
     * cmd 无法执行无扩展名脚本，因此优先选择 .exe/.cmd/.bat 后缀的候选
     *
     * @param string $executable_name 可执行文件名
     * @return string|false 完整路径或 false
     */
    protected function find_in_system_path( string $executable_name ) {
        if ( ! $this->is_exec_available() ) {
            return false;
        }

        if ( $this->is_windows() ) {
            $found = self::run_command( array( 'where', $executable_name ), 15 );
        } else {
            $found = self::run_command( array( 'which', $executable_name ), 15 );
        }

        if ( empty( $found['success'] ) ) {
            return false;
        }

        $candidates = array_filter( array_map( 'trim', preg_split( '/\R/', (string) $found['output'] ) ) );

        if ( empty( $candidates ) ) {
            return false;
        }

        if ( $this->is_windows() ) {
            foreach ( $candidates as $path ) {
                if ( preg_match( '/\.(exe|cmd|bat)$/i', $path ) && file_exists( $path ) ) {
                    return $path;
                }
            }
        }

        foreach ( $candidates as $path ) {
            if ( '' !== $path && file_exists( $path ) ) {
                return $path;
            }
        }

        return false;
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/svg/svgo';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo npm install -g svgo',
            'debian'  => 'sudo npm install -g svgo',
            'centos'  => 'sudo npm install -g svgo',
            'fedora'  => 'sudo npm install -g svgo',
            'macos'   => 'brew install svgo',
            'windows' => __( '需要 Node.js 环境，运行 npm install -g svgo', 'libre-compress' ),
        );
    }
}
