<?php
/**
 * Gifsicle GIF 压缩渠道
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gifsicle GIF 压缩渠道
 *
 * 使用 gifsicle 命令行工具压缩 GIF 图片（支持静态与动画 GIF）
 */
class Libre_Compress_Gifsicle extends Libre_Compress_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'gifsicle';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'gif' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'gifsicle';
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

        $lossless = 'lossless' === Libre_Compress_Settings::param_mode( 'gif' );

        // 编码结果先落到临时文件，替换由基类用 PHP 文件函数完成
        $temp_output = $this->get_temp_output_path( $file_path );

        // 构建命令
        $command_parts = array(
            $executable,
            '-O' . Libre_Compress_Settings::param_int( 'gif_level' ),
        );

        // --lossy 就是有损强度本身：数值越大压得越狠、噪点越多，界面直接填这个值，不做换算。
        $lossiness = Libre_Compress_Settings::param_int( 'gif_lossy' );

        if ( ! $lossless ) {
            // 填多少传多少：0 也照传，gifsicle 自己把 --lossy=0 当作不启用有损。
            $command_parts[] = '--lossy=' . $lossiness;
        }

        // 调色板上限照填照传，256 就是 GIF 的上限（等于不缩色），不做“等于默认值就不传”的判断。
        $command_parts[] = '--colors';
        $command_parts[] = (string) Libre_Compress_Settings::param_int( 'gif_colors' );

        // GIF 的注释块、帧名和应用扩展块里常是作者、制作工具与 XMP 信息。
        // 三个开关要一起给：实测单独的 --no-extensions 会漏掉文件末尾的注释块，
        // 只有 --no-comments 又会漏掉某些 XMP 应用扩展。全给之后两者都不剩，
        // 而 NETSCAPE 循环扩展与透明索引不受影响，动图仍能无限循环。
        if ( Libre_Compress_Settings::strips_metadata() ) {
            $command_parts[] = '--no-comments';
            $command_parts[] = '--no-names';
            $command_parts[] = '--no-extensions';
        }

        $command_parts[] = '-o';
        $command_parts[] = $temp_output;
        $command_parts[] = $file_path;

        return array( $command_parts );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.tmp.gif';
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://www.lcdf.org/gifsicle/';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install gifsicle',
            'debian'  => 'sudo apt-get install gifsicle',
            'centos'  => 'sudo yum install gifsicle',
            'fedora'  => 'sudo dnf install gifsicle',
            'macos'   => 'brew install gifsicle',
            'windows' => __( '从 eternallybored.org 下载预编译的 gifsicle.exe', 'libre-compress' ),
        );
    }
}
