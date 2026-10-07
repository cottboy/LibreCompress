<?php
/**
 * 压缩工具基类
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 压缩工具基类
 *
 * 提供命令行压缩工具的通用功能
 */
abstract class Libre_Compress_Tool_Base {

    /**
     * 缓存的可执行文件路径
     *
     * @var string|false|null
     */
    protected $executable_path = null;

    /**
     * 获取工具名称
     *
     * @return string 工具名称
     */
    abstract public function get_name(): string;

    /**
     * 获取支持的文件格式
     *
     * @return array 支持的扩展名列表
     */
    abstract public function get_supported_formats(): array;

    /**
     * 获取工具下载地址
     *
     * @return string 下载地址
     */
    abstract public function get_download_url(): string;

    /**
     * 获取安装说明
     *
     * @return array 安装说明
     */
    abstract public function get_install_instructions(): array;

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    abstract protected function get_executable_name(): string;

    /**
     * 构建压缩命令链
     *
     * 返回命令参数数组的数组：外层是执行顺序，内层是单条命令的可执行文件加参数。
     * 基类按顺序依次执行，任一条失败即整体失败且不再执行后续命令。
     *
     * 必须是参数数组而不是命令字符串：字符串会交给 shell 解析，
     * 而 Windows 上 proc_open 走 cmd，escapeshellarg 挡不住 %VAR% 展开——
     * 文件名里出现 %PATH% 会被替换成系统 PATH 内容，命令直接找不到文件。
     * 传数组时 PHP 直接 CreateProcess，不经过 shell，% & 引号全部是普通字符。
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链，每项为参数数组
     */
    abstract protected function build_command_chain( string $file_path, array $options ): array;

    /**
     * 获取单张图片最大文件大小（MB）
     *
     * 默认无限制
     *
     * @return int 最大尺寸限制
     */
    public function get_max_file_size(): int {
        return 0;
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * 返回空字符串表示工具自己原地覆盖原文件（默认）。
     * 返回非空时，build_command 只负责把编码结果写到这个临时文件，
     * 由基类在编码成功后用 PHP 的文件函数替换原文件。
     *
     * 不用 shell 的 move/mv 做替换有两个原因：
     * 一是 Windows 上 proc_open 走 cmd，move /y "%s" 里的 %VAR% 在双引号内仍会展开，
     * 文件名带 % 或 & 就会失败并留下临时文件；
     * 二是命令被超时中断时 move 根本不会执行，临时文件就永久留在公开的 uploads 里。
     *
     * 路径必须是确定性的：基类要在命令结束后按同一路径做替换和兜底清理。
     * 并发由压缩调度器的附件级锁保证，同一文件不会被两个进程同时编码。
     *
     * @param string $file_path 文件路径
     * @return string 临时输出路径，空字符串表示原地覆盖
     */
    protected function get_temp_output_path( string $file_path ): string {
        return '';
    }

    /**
     * 获取编码过程中产生的其他临时文件
     *
     * 只用于兜底清理，不参与替换。
     *
     * @param string $file_path 文件路径
     * @return string[]
     */
    protected function get_extra_temp_paths( string $file_path ): array {
        return array();
    }

    /**
     * 把临时输出替换为原文件
     *
     * 先删后改名，兼容 Windows 上 rename 不覆盖已有文件的行为；
     * 原文件删掉后改名失败会返回 false，上层回滚副本负责还原。
     *
     * @param string $temp_output 临时输出路径
     * @param string $file_path    原文件路径
     * @return bool 是否替换成功
     */
    private function replace_with_temp_output( string $temp_output, string $file_path ): bool {
        clearstatcache( true, $temp_output );

        if ( ! is_file( $temp_output ) || 0 === (int) filesize( $temp_output ) ) {
            return false;
        }

        clearstatcache( true, $file_path );

        if ( file_exists( $file_path ) && ! unlink( $file_path ) ) {
            return false;
        }

        return rename( $temp_output, $file_path );
    }

    /**
     * 检查 exec() 函数是否可用
     *
     * @return bool 是否可用
     */
    public function is_exec_available(): bool {
        return self::is_command_execution_available();
    }

    /**
     * 获取可执行文件路径
     *
     * 优先查找 wp-content/LibreCompress-bin 目录，然后查找系统 PATH
     *
     * @return string|false 可执行文件路径或 false
     */
    protected function get_executable_path() {        // 使用缓存
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
            $output = self::run_command( array( 'where', $executable_name ), 15 );
        } else {
            $output = self::run_command( array( 'which', $executable_name ), 15 );
        }

        if ( empty( $output['success'] ) ) {
            return false;
        }

        // where/which 可能输出多行，逐行找第一个真实存在的文件
        foreach ( preg_split( '/\R/', (string) $output['output'] ) as $line ) {
            $path = trim( $line );

            if ( '' !== $path && file_exists( $path ) ) {
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
     * 获取工具二进制路径（供统一压缩的其他处理路径复用）
     *
     * @return string|false 可执行文件路径或 false
     */
    public function get_tool_binary_path() {
        return $this->get_executable_path();
    }

    /**
     * 执行命令
     *
     * @param array $command 要执行的命令参数数组
     * @return array 执行结果
     */
    protected function execute_command( array $command ): array {
        return self::run_command( $command );
    }

    /**
     * 执行带超时和进程树终止保护的本地命令
     *
     * 命令以参数数组传入：PHP 会直接 CreateProcess 而不经过 shell，
     * 路径里的 % & 等字符不会被解释，也不会构成命令注入。
     *
     * @param array $command 可执行文件加参数的数组
     * @param int   $timeout 超时秒数
     * @return array{success:bool,output:string,return_code:int}
     */
    public static function run_command( array $command, int $timeout = 120 ): array {
        $command = array_values( array_filter( $command, static function ( $part ) {
            return '' !== $part && null !== $part;
        } ) );

        if ( empty( $command ) ) {
            return array(
                'success'     => false,
                'output'      => __( '命令为空', 'libre-compress' ),
                'return_code' => -1,
            );
        }
        if ( ! self::is_command_execution_available() ) {
            return array(
                'success'     => false,
                'output'      => __( '本地命令执行功能不可用', 'libre-compress' ),
                'return_code' => -1,
            );
        }

        $stdout_path = tempnam( sys_get_temp_dir(), 'lc_stdout_' );
        $stderr_path = tempnam( sys_get_temp_dir(), 'lc_stderr_' );
        if ( false === $stdout_path || false === $stderr_path ) {
            if ( $stdout_path && file_exists( $stdout_path ) ) {
                unlink( $stdout_path );
            }
            if ( $stderr_path && file_exists( $stderr_path ) ) {
                unlink( $stderr_path );
            }
            return array(
                'success'     => false,
                'output'      => __( '无法创建命令输出缓冲区', 'libre-compress' ),
                'return_code' => -1,
            );
        }

        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'file', $stdout_path, 'w' ),
            2 => array( 'file', $stderr_path, 'w' ),
        );
        $pipes       = array();
        $process     = proc_open( $command, $descriptors, $pipes );
        if ( ! is_resource( $process ) ) {
            unlink( $stdout_path );
            unlink( $stderr_path );
            return array(
                'success'     => false,
                'output'      => __( '无法启动本地命令', 'libre-compress' ),
                'return_code' => -1,
            );
        }

        fclose( $pipes[0] );
        $started  = microtime( true );
        $exitcode = -1;
        $timedout = false;

        while ( true ) {
            $status = proc_get_status( $process );
            if ( ! $status['running'] ) {
                $exitcode = (int) $status['exitcode'];
                break;
            }

            if ( microtime( true ) - $started > max( 1, $timeout ) ) {
                $timedout = true;
                self::terminate_process( $process, (int) $status['pid'] );
                break;
            }

            usleep( 100000 );
        }

        $close_code = proc_close( $process );
        if ( -1 === $exitcode && 0 === $close_code ) {
            $exitcode = 0;
        }

        $max_output_bytes = 1024 * 1024;
        $output = (string) file_get_contents( $stdout_path, false, null, 0, $max_output_bytes )
            . (string) file_get_contents( $stderr_path, false, null, 0, $max_output_bytes );
        unlink( $stdout_path );
        unlink( $stderr_path );

        if ( $timedout ) {
            return array(
                'success'     => false,
                'output'      => __( '本地命令执行超时', 'libre-compress' ),
                'return_code' => 124,
            );
        }

        return array(
            'success'     => 0 === $exitcode,
            'output'      => $output,
            'return_code' => $exitcode,
        );
    }

    /**
     * 检查本地命令执行依赖
     *
     * @return bool
     */
    public static function is_command_execution_available(): bool {
        if ( ! function_exists( 'proc_open' ) || ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        return ! in_array( 'proc_open', $disabled, true ) && ! in_array( 'exec', $disabled, true );
    }

    /**
     * 终止命令进程树
     *
     * @param resource $process 进程资源
     * @param int      $pid      进程 ID
     */
    private static function terminate_process( $process, int $pid ): void {
        if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) && $pid > 0 ) {
            // Windows 的 proc_terminate 不保证结束 cmd.exe 的子进程，使用 taskkill /T。
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            @exec( 'taskkill /F /T /PID ' . absint( $pid ) . ' >NUL 2>&1', $taskkill_output, $taskkill_rc );
            if ( 0 === (int) $taskkill_rc ) {
                return;
            }
        }

        if ( function_exists( 'posix_kill' ) && defined( 'SIGTERM' ) && $pid > 0 ) {
            @posix_kill( $pid, SIGTERM );
        } else {
            proc_terminate( $process );
        }
        usleep( 200000 );
        $status = proc_get_status( $process );
        if ( $status['running'] ) {
            if ( function_exists( 'posix_kill' ) && defined( 'SIGKILL' ) && $pid > 0 ) {
                @posix_kill( $pid, SIGKILL );
            } else {
                proc_terminate( $process, 9 );
            }
        }
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

        // 临时路径必须在命令链构建之前确定：基类要在命令结束后
        // 按同一路径做替换和兜底清理。
        $temp_output = $this->get_temp_output_path( $file_path );
        $temp_paths  = array_filter( array_merge( array( $temp_output ), $this->get_extra_temp_paths( $file_path ) ) );

        // 按顺序执行命令链，任一条失败即整体失败且不再执行后续命令
        $result = array( 'success' => false, 'output' => '', 'return_code' => -1 );

        foreach ( $this->build_command_chain( $file_path, $options ) as $command ) {
            $result = $this->execute_command( $command );

            if ( ! $result['success'] ) {
                break;
            }
        }

        try {
            if ( ! $result['success'] ) {
                return array(
                    'success'         => false,
                    'message'         => $result['output'],
                    'original_size'   => $original_size,
                    'compressed_size' => $original_size,
                );
            }

            if ( '' !== $temp_output && ! $this->replace_with_temp_output( $temp_output, $file_path ) ) {
                return array(
                    'success'         => false,
                    'message'         => __( '压缩结果替换失败，已保留原文件', 'libre-compress' ),
                    'original_size'   => $original_size,
                    'compressed_size' => $original_size,
                );
            }

            // 清除文件状态缓存，获取压缩后大小
            clearstatcache( true, $file_path );
            $compressed_size = (int) filesize( $file_path );

            if ( $compressed_size <= 0 ) {
                return array(
                    'success'         => false,
                    'message'         => __( '压缩结果为空文件，已保留原文件', 'libre-compress' ),
                    'original_size'   => $original_size,
                    'compressed_size' => $original_size,
                );
            }

            return array(
                'success'         => true,
                'message'         => __( '压缩成功', 'libre-compress' ),
                'original_size'   => $original_size,
                'compressed_size' => $compressed_size,
            );
        } finally {
            // 临时文件兜底清理：命令失败、超时、替换失败都要清干净，
            // 编码中间产物留在公开的 uploads 里等于泄漏未压缩内容。
            foreach ( $temp_paths as $temp_path ) {
                if ( '' !== $temp_path && file_exists( $temp_path ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_path );
                }
            }
        }
    }
}
