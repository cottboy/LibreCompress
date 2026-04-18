<?php
/**
 * 本地压缩基类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 本地压缩基类
 *
 * 提供本地命令行压缩工具的通用功能
 */
abstract class Libre_Compress_Local_Base implements Libre_Compress_Channel_Interface {

    /**
     * 缓存的可执行文件路径
     *
     * @var string|false|null
     */
    protected $executable_path = null;

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    abstract protected function get_executable_name(): string;

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return string 完整命令
     */
    abstract protected function build_command( string $file_path, array $options ): string;

    /**
     * 获取单张图片最大尺寸限制（KB）
     *
     * 本地压缩默认无限制
     *
     * @return int 最大尺寸限制
     */
    public function get_max_file_size(): int {
        return 0;
    }

    /**
     * 获取渠道类型
     *
     * @return string 渠道类型，本地压缩工具都是官方开源工具
     */
    /**
     * 检查 exec() 函数是否可用
     *
     * @return bool 是否可用
     */
    public function is_exec_available(): bool {
        // 检查函数是否存在
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        // 检查函数是否被禁用
        $disabled_functions = explode( ',', ini_get( 'disable_functions' ) );
        $disabled_functions = array_map( 'trim', $disabled_functions );

        if ( in_array( 'exec', $disabled_functions, true ) ) {
            return false;
        }

        return true;
    }

    /**
     * 获取可执行文件路径
     *
     * 优先查找 wp-content/LibreCompress-bin 目录，然后查找系统 PATH
     *
     * @return string|false 可执行文件路径或 false
     */
    protected function get_executable_path() {
        // 使用缓存
        if ( null !== $this->executable_path ) {
            return $this->executable_path;
        }

        $executable_name = $this->get_executable_name();

        // 1. 首先检查 wp-content/LibreCompress-bin 目录
        $bin_path = LIBRE_COMPRESS_BIN_PATH . $executable_name;

        // Windows 系统添加 .exe 后缀
        if ( $this->is_windows() ) {
            $bin_path .= '.exe';
        }

        // Windows 上 is_executable() 不可靠，只检查文件是否存在
        if ( file_exists( $bin_path ) && ( $this->is_windows() || is_executable( $bin_path ) ) ) {
            $this->executable_path = $bin_path;
            return $this->executable_path;
        }

        // 2. 检查系统 PATH
        $system_path = $this->find_in_system_path( $executable_name );
        if ( $system_path ) {
            $this->executable_path = $system_path;
            return $this->executable_path;
        }

        $this->executable_path = false;
        return false;
    }

    /**
     * 在系统 PATH 中查找可执行文件
     *
     * @param string $executable_name 可执行文件名
     * @return string|false 完整路径或 false
     */
    protected function find_in_system_path( string $executable_name ) {
        if ( ! $this->is_exec_available() ) {
            return false;
        }

        // 使用 which 或 where 命令查找
        if ( $this->is_windows() ) {
            $command = 'where ' . escapeshellarg( $executable_name ) . ' 2>nul';
        } else {
            $command = 'which ' . escapeshellarg( $executable_name ) . ' 2>/dev/null';
        }

        $output = array();
        $result = 0;

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec( $command, $output, $result );

        if ( 0 === $result && ! empty( $output[0] ) ) {
            $path = trim( $output[0] );
            if ( file_exists( $path ) ) {
                return $path;
            }
        }

        return false;
    }

    /**
     * 检查是否为 Windows 系统
     *
     * @return bool 是否为 Windows
     */
    protected function is_windows(): bool {
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
    }

    /**
     * 检查工具是否可用
     *
     * @return bool 是否可用
     */
    public function is_tool_available(): bool {
        if ( ! $this->is_exec_available() ) {
            return false;
        }

        return false !== $this->get_executable_path();
    }

    /**
     * 执行命令
     *
     * @param string $command 要执行的命令
     * @return array 执行结果：
     *               - success: bool 是否成功
     *               - output: string 输出内容
     *               - return_code: int 返回码
     */
    protected function execute_command( string $command ): array {
        if ( ! $this->is_exec_available() ) {
            return array(
                'success'     => false,
                'output'      => __( 'exec() 函数不可用', 'libre-compress' ),
                'return_code' => -1,
            );
        }

        $output      = array();
        $return_code = 0;

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        exec( $command . ' 2>&1', $output, $return_code );

        return array(
            'success'     => 0 === $return_code,
            'output'      => implode( "\n", $output ),
            'return_code' => $return_code,
        );
    }

    /**
     * 压缩图片
     *
     * @param string $file_path 图片绝对路径
     * @param array  $options   压缩选项
     * @return array 压缩结果
     */
    public function compress( string $file_path, array $options = array() ): array {
        // 检查工具是否可用
        if ( ! $this->is_tool_available() ) {
            return array(
                'success'         => false,
                'message'         => sprintf(
                    /* translators: %s: 工具名称 */
                    __( '%s 不可用', 'libre-compress' ),
                    $this->get_name()
                ),
                'original_size'   => 0,
                'compressed_size' => 0,
            );
        }

        // 验证文件存在
        if ( ! file_exists( $file_path ) ) {
            return array(
                'success'         => false,
                'message'         => __( '文件不存在', 'libre-compress' ),
                'original_size'   => 0,
                'compressed_size' => 0,
            );
        }

        // 验证文件类型
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, $this->get_supported_formats(), true ) ) {
            return array(
                'success'         => false,
                'message'         => __( '不支持的文件格式', 'libre-compress' ),
                'original_size'   => 0,
                'compressed_size' => 0,
            );
        }

        // 获取原始文件大小
        $original_size = filesize( $file_path );

        // 构建并执行命令
        $command = $this->build_command( $file_path, $options );
        $result  = $this->execute_command( $command );

        if ( ! $result['success'] ) {
            return array(
                'success'         => false,
                'message'         => $result['output'],
                'original_size'   => $original_size,
                'compressed_size' => $original_size,
            );
        }

        // 清除文件状态缓存，获取压缩后大小
        clearstatcache( true, $file_path );
        $compressed_size = filesize( $file_path );

        return array(
            'success'         => true,
            'message'         => __( '压缩成功', 'libre-compress' ),
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
        );
    }

    /**
     * 获取工具版本
     *
     * @return string|false 版本号或 false
     */
    public function get_version() {
        if ( ! $this->is_tool_available() ) {
            return false;
        }

        $executable = $this->get_executable_path();
        $command    = escapeshellarg( $executable ) . ' --version 2>&1';
        $result     = $this->execute_command( $command );

        if ( $result['success'] && ! empty( $result['output'] ) ) {
            // 尝试从输出中提取版本号
            if ( preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $result['output'], $matches ) ) {
                return $matches[1];
            }
            return $result['output'];
        }

        return false;
    }
}
