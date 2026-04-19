<?php
/**
 * 压缩调度器类
 *
 * @package LibreCompress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 压缩调度器类
 *
 * 负责管理本地压缩工具并执行压缩流程。
 */
class Libre_Compress_Compressor {

    /**
     * 本地压缩工具列表
     *
     * @var array
     */
    private $local_tools = array();

    /**
     * 构造函数
     */
    public function __construct() {
        $this->register_default_tools();
        $this->init_hooks();
    }

    /**
     * 注册默认压缩工具
     */
    private function register_default_tools() {
        $this->register_local_tool( new Libre_Compress_Jpegoptim() );
        $this->register_local_tool( new Libre_Compress_Pngquant() );
        $this->register_local_tool( new Libre_Compress_Oxipng() );
        $this->register_local_tool( new Libre_Compress_Cwebp() );

        do_action( 'libre_compress_register_local_tools', $this );
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_compress_on_upload' ), 10, 2 );
    }

    /**
     * 注册本地压缩工具
     *
     * @param Libre_Compress_Local_Base $tool 工具实例
     */
    public function register_local_tool( Libre_Compress_Local_Base $tool ) {
        $this->local_tools[ $tool->get_name() ] = $tool;
    }

    /**
     * 获取所有本地压缩工具
     *
     * @return array 工具列表
     */
    public function get_local_tools(): array {
        return $this->local_tools;
    }

    /**
     * 根据文件格式获取可用的本地压缩工具
     *
     * @param string $extension 文件扩展名
     * @return Libre_Compress_Local_Base|null 工具实例或 null
     */
    public function get_local_tool_for_format( string $extension ): ?Libre_Compress_Local_Base {
        $extension = strtolower( $extension );

        if ( 'png' === $extension ) {
            $settings  = get_option( 'libre_compress_local', array() );
            $png_mode  = isset( $settings['png_mode'] ) ? $settings['png_mode'] : 'lossy';
            $use_lossy = ( 'lossy' === $png_mode );

            $tool_name = $use_lossy ? 'pngquant' : 'oxipng';

            if ( isset( $this->local_tools[ $tool_name ] ) && $this->local_tools[ $tool_name ]->is_tool_available() ) {
                return $this->local_tools[ $tool_name ];
            }

            $fallback_tool_name = $use_lossy ? 'oxipng' : 'pngquant';
            if ( isset( $this->local_tools[ $fallback_tool_name ] ) && $this->local_tools[ $fallback_tool_name ]->is_tool_available() ) {
                return $this->local_tools[ $fallback_tool_name ];
            }

            return null;
        }

        foreach ( $this->local_tools as $tool ) {
            if ( in_array( $extension, $tool->get_supported_formats(), true ) && $tool->is_tool_available() ) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * 获取附件对应的原图和缩略图文件
     *
     * @param int $attachment_id 附件 ID
     * @return array 文件列表
     */
    public function get_attachment_files( int $attachment_id ): array {
        $files = array();

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
            return $files;
        }

        $original_file = $base_dir . '/' . $metadata['file'];
        if ( file_exists( $original_file ) ) {
            $files[] = array(
                'size_type' => 'full',
                'file_path' => $original_file,
            );
        }

        if ( ! empty( $metadata['original_image'] ) ) {
            $file_dir            = dirname( $metadata['file'] );
            $original_image_path = $base_dir . '/' . $file_dir . '/' . $metadata['original_image'];
            if ( file_exists( $original_image_path ) ) {
                $files[] = array(
                    'size_type' => 'original_image',
                    'file_path' => $original_image_path,
                );
            }
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $file_dir = dirname( $metadata['file'] );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $thumb_file = $base_dir . '/' . $file_dir . '/' . $size_data['file'];
                    if ( file_exists( $thumb_file ) ) {
                        $files[] = array(
                            'size_type' => sanitize_text_field( $size_name ),
                            'file_path' => $thumb_file,
                        );
                    }
                }
            }
        }

        return $files;
    }

    /**
     * 压缩单个文件
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     文件路径
     * @param string $size_type     尺寸类型
     * @param array  $options       额外参数
     * @return array 压缩结果
     */
    public function compress_file( int $attachment_id, string $file_path, string $size_type = 'full', array $options = array() ): array {
        do_action( 'libre_compress_before_compress', $attachment_id, $file_path, $size_type );

        if ( ! file_exists( $file_path ) ) {
            return array(
                'success' => false,
                'message' => __( '文件不存在', 'libre-compress' ),
                'status'  => 'failed',
            );
        }

        if ( ! $this->is_safe_path( $file_path ) ) {
            return array(
                'success' => false,
                'message' => __( '文件路径不安全', 'libre-compress' ),
                'status'  => 'failed',
            );
        }

        $extension      = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $settings       = get_option( 'libre_compress_general', array() );
        $backup_enabled = isset( $settings['backup_enabled'] ) ? (bool) $settings['backup_enabled'] : true;
        $tool           = $this->get_local_tool_for_format( $extension );

        if ( ! $tool ) {
            return array(
                'success' => false,
                'message' => __( '没有可用的本地压缩工具', 'libre-compress' ),
                'status'  => 'skipped',
            );
        }

        $file_size      = filesize( $file_path );
        $max_size_mb    = $tool->get_max_file_size();
        $max_size_bytes = $max_size_mb * 1024 * 1024;

        if ( $max_size_mb > 0 && $file_size > $max_size_bytes ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( '文件大小超过限制（最大 %d MB）', 'libre-compress' ),
                    $max_size_mb
                ),
                'status'  => 'skipped',
            );
        }

        if ( $backup_enabled ) {
            $backup = libre_compress()->backup;
            $backup->create_backup( $attachment_id, $file_path );
        }

        $original_size = filesize( $file_path );
        $result        = $tool->compress( $file_path, $options );

        if ( ! $result['success'] ) {
            $this->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool->get_name(),
                'failed',
                $result['message']
            );

            return array(
                'success'         => false,
                'message'         => $result['message'],
                'status'          => 'failed',
                'original_size'   => $original_size,
                'compressed_size' => $original_size,
            );
        }

        clearstatcache( true, $file_path );
        $compressed_size = filesize( $file_path );

        if ( $compressed_size >= $original_size ) {
            if ( $backup_enabled ) {
                $backup = libre_compress()->backup;
                $backup->restore_backup( $attachment_id, $file_path );
            }

            $this->save_compression_record(
                $attachment_id,
                $file_path,
                $size_type,
                $original_size,
                $original_size,
                $tool->get_name(),
                'skipped',
                __( '压缩后体积更大，已跳过', 'libre-compress' )
            );

            return array(
                'success'         => true,
                'message'         => __( '压缩后体积更大，已跳过', 'libre-compress' ),
                'status'          => 'skipped',
                'original_size'   => $original_size,
                'compressed_size' => $original_size,
            );
        }

        $ratio = round( ( 1 - $compressed_size / $original_size ) * 100, 2 );

        $this->save_compression_record(
            $attachment_id,
            $file_path,
            $size_type,
            $original_size,
            $compressed_size,
            $tool->get_name(),
            'success'
        );

        do_action( 'libre_compress_after_compress', $attachment_id, $file_path, $size_type, $result );

        return array(
            'success'         => true,
            'message'         => __( '压缩成功', 'libre-compress' ),
            'status'          => 'success',
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'ratio'           => $ratio,
        );
    }

    /**
     * 压缩整个附件及其相关尺寸
     *
     * @param int   $attachment_id 附件 ID
     * @param array $options       额外参数
     * @return array 压缩结果
     */
    public function compress_attachment( int $attachment_id, array $options = array() ): array {
        $files   = $this->get_attachment_files( $attachment_id );
        $results = array(
            'total'       => count( $files ),
            'success'     => 0,
            'failed'      => 0,
            'skipped'     => 0,
            'saved_bytes' => 0,
            'details'     => array(),
        );

        foreach ( $files as $file ) {
            $result = $this->compress_file(
                $attachment_id,
                $file['file_path'],
                $file['size_type'],
                $options
            );

            $results['details'][] = array_merge( $file, $result );

            if ( 'success' === $result['status'] ) {
                $results['success']++;
                $results['saved_bytes'] += ( $result['original_size'] - $result['compressed_size'] );
            } elseif ( 'failed' === $result['status'] ) {
                $results['failed']++;
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * 上传后自动压缩
     *
     * @param array $metadata      附件元数据
     * @param int   $attachment_id 附件 ID
     * @return array
     */
    public function auto_compress_on_upload( $metadata, $attachment_id ) {
        $settings      = get_option( 'libre_compress_general', array() );
        $auto_compress = isset( $settings['auto_compress'] ) ? (bool) $settings['auto_compress'] : false;

        if ( ! $auto_compress ) {
            return $metadata;
        }

        $mime_type     = get_post_mime_type( $attachment_id );
        $allowed_types = array( 'image/jpeg', 'image/png', 'image/webp' );

        if ( ! in_array( $mime_type, $allowed_types, true ) ) {
            return $metadata;
        }

        $this->compress_attachment( $attachment_id );

        return $metadata;
    }

    /**
     * 保存压缩记录
     *
     * @param int    $attachment_id   附件 ID
     * @param string $file_path       文件路径
     * @param string $size_type       尺寸类型
     * @param int    $original_size   原始大小
     * @param int    $compressed_size 压缩后大小
     * @param string $channel_name    工具名称
     * @param string $status          状态
     * @param string $error_message   错误信息
     */
    private function save_compression_record(
        int $attachment_id,
        string $file_path,
        string $size_type,
        int $original_size,
        int $compressed_size,
        string $channel_name,
        string $status,
        string $error_message = ''
    ) {
        $database = libre_compress()->database;

        $upload_dir    = wp_upload_dir();
        $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );
        $ratio         = $original_size > 0 ? round( ( 1 - $compressed_size / $original_size ) * 100, 2 ) : 0;
        $existing      = $database->get_record( $attachment_id, $size_type );

        if ( $existing ) {
            $database->update_record(
                $existing['id'],
                array(
                    'original_size'     => $original_size,
                    'compressed_size'   => $compressed_size,
                    'compression_ratio' => $ratio,
                    'status'            => $status,
                    'error_message'     => $error_message,
                )
            );
            return;
        }

        $database->add_record(
            array(
                'attachment_id'     => $attachment_id,
                'file_path'         => $relative_path,
                'size_type'         => $size_type,
                'original_size'     => $original_size,
                'compressed_size'   => $compressed_size,
                'compression_ratio' => $ratio,
                'channel_name'      => $channel_name,
                'status'            => $status,
                'error_message'     => $error_message,
            )
        );
    }

    /**
     * 检查文件路径是否安全
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    private function is_safe_path( string $file_path ): bool {
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $file_path );

        if ( false === $real_path || false === $base_dir ) {
            return false;
        }

        if ( 0 !== strpos( $real_path, $base_dir ) ) {
            return false;
        }

        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        return true;
    }
}
