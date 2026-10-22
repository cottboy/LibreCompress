<?php
/**
 * 上传文件名消歧
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 上传文件名消歧
 *
 * 插件的格式转换产物按“源文件名.目标格式”的双扩展名规则生成：photo.jpg 转成
 * photo.jpg.webp，旧格式的 photo.jpg 原地留下当回退。前台据此推导回退地址：
 * 去掉最后一段扩展名就是旧格式文件名，再去磁盘上确认它确实存在。
 *
 * 用户自己上传的文件名不受这套规则约束。同目录下正好存在同名旧格式文件时，
 * 用户上传的新格式文件会被前台当成转换产物，于是配出一张不相干的图当回退。
 *
 * 这里在文件落盘前消一次歧：会跟旧格式文件（或同名新格式文件）撞上的名字，
 * 按 WordPress 的习惯追加 -1、-2 序号改名，改完就不再和任何旧格式文件撞名。
 */
class Local_Crush_Upload_Naming {

    /**
     * 插件的目标格式扩展名
     *
     * @var string[]
     */
    const TARGET_FORMATS = array( 'webp', 'avif', 'jxl' );

    /**
     * 改名尝试次数上限
     *
     * @var int
     */
    const MAX_ATTEMPTS = 100;

    /**
     * 构造函数
     */
    public function __construct() {
        add_filter( 'wp_unique_filename', array( $this, 'filter_unique_filename' ), 20, 3 );
    }

    /**
     * 上传落盘前消歧改名
     *
     * WordPress 已经保证返回的名字在目标目录里不存在，这里只处理“和另一种格式
     * 的同名文件撞上”这种它管不了的情况。
     *
     * @param string $filename WordPress 选定的文件名
     * @param string $ext      文件扩展名（含点）
     * @param string $dir      目标目录
     * @return string
     */
    public function filter_unique_filename( $filename, $ext = '', $dir = '' ) {
        if ( ! is_string( $filename ) || '' === $filename ) {
            return $filename;
        }

        if ( ! is_string( $dir ) || '' === $dir || ! $this->is_in_uploads( $dir ) ) {
            return $filename;
        }

        $name      = wp_basename( $filename );
        $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        if ( '' === $extension ) {
            return $filename;
        }

        // 新格式文件：去掉最后一段就是它可能被当成源文件的旧格式名。
        if ( in_array( $extension, self::TARGET_FORMATS, true ) ) {
            $stem = substr( $name, 0, strlen( $name ) - strlen( $extension ) - 1 );

            // 单扩展名（banner.webp）推不出旧格式文件名，不会撞名。
            if ( '' === pathinfo( $stem, PATHINFO_EXTENSION ) ) {
                return $filename;
            }

            if ( ! $this->file_exists( $dir, $stem ) ) {
                return $filename;
            }

            $renamed = $this->allocate_name( $dir, $name, $extension, true );

            return '' === $renamed ? $filename : $renamed;
        }

        // 旧格式文件：同目录下已有同名的新格式文件时改名，避免两者撞成一组，
        // 让那个新格式文件失去（或错配）自己的回退。
        foreach ( self::TARGET_FORMATS as $target ) {
            if ( $this->file_exists( $dir, $name . '.' . $target ) ) {
                $renamed = $this->allocate_name( $dir, $name, $extension, false );

                return '' === $renamed ? $filename : $renamed;
            }
        }

        return $filename;
    }

    /**
     * 分配一个不与任何相关文件撞名的名字
     *
     * 序号追加在主体名之后：photo.jpg → photo-1.jpg，photo.jpg.webp → photo.jpg-1.webp，
     * 与 WordPress 上传去重和插件转换时的改名规则一致。
     *
     * @param string $dir             目标目录
     * @param string $name            原文件名
     * @param string $extension       原扩展名（小写）
     * @param bool   $is_target_format 原文件是不是插件的目标格式
     * @return string 分配不到时返回空字符串
     */
    private function allocate_name( string $dir, string $name, string $extension, bool $is_target_format ): string {
        $body = pathinfo( $name, PATHINFO_FILENAME );

        if ( '' === $body ) {
            return '';
        }

        for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
            $candidate = $body . '-' . $attempt . '.' . $extension;

            if ( $this->file_exists( $dir, $candidate ) ) {
                continue;
            }

            if ( $is_target_format ) {
                // 新格式文件：去掉最后一段得到的旧格式名也不能已经被占用。
                $stem = substr( $candidate, 0, strlen( $candidate ) - strlen( $extension ) - 1 );

                if ( '' !== pathinfo( $stem, PATHINFO_EXTENSION ) && $this->file_exists( $dir, $stem ) ) {
                    continue;
                }

                return $candidate;
            }

            // 旧格式文件：同名的新格式文件也不能已经存在。
            $clash = false;

            foreach ( self::TARGET_FORMATS as $target ) {
                if ( $this->file_exists( $dir, $candidate . '.' . $target ) ) {
                    $clash = true;
                    break;
                }
            }

            if ( ! $clash ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 判断目录是否在上传目录内
     *
     * 只管上传目录里的文件，其他目录（插件目录、临时目录等）一律不碰。
     *
     * @param string $dir 目录绝对路径
     * @return bool
     */
    private function is_in_uploads( string $dir ): bool {
        $uploads = wp_upload_dir();

        if ( empty( $uploads['basedir'] ) ) {
            return false;
        }

        $base = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
        $real = realpath( $base );

        if ( false !== $real ) {
            $base = wp_normalize_path( $real );
        }

        $target = wp_normalize_path( $dir );
        $real   = realpath( $target );

        if ( false !== $real ) {
            $target = wp_normalize_path( $real );
        }

        return 0 === strpos( $target, $base . '/' );
    }

    /**
     * 判断目标目录下的文件是否存在
     *
     * 文件名来自上传流程，仍按不可信数据校验：目录穿越和非法字符一律当作不存在。
     *
     * @param string $dir  目录绝对路径
     * @param string $name 文件名
     * @return bool
     */
    private function file_exists( string $dir, string $name ): bool {
        if ( '' === $name || false !== strpos( $name, '..' ) || false !== strpbrk( $name, "/\\:\0" ) ) {
            return false;
        }

        $path = trailingslashit( wp_normalize_path( $dir ) ) . wp_normalize_path( $name );

        clearstatcache( true, $path );

        return is_file( $path );
    }
}
