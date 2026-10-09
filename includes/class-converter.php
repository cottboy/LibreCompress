<?php
/**
 * 目标格式输出处理器
 *
 * @package LibreCompress
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 目标格式输出处理器
 *
 * 将勾选格式的图片（PNG/JPG/GIF/SVG）压缩输出为 WebP 或 AVIF，是统一压缩入口
 * 的一部分：结果小于源文件时生成真实的新文件并接管附件引用，同步附件路径、元数据、
 * MIME 和文章内已固化的图片链接。
 *
 * 新旧格式严格同名不同后缀：旧格式文件留在原地并同样经过压缩，作为前台
 * <picture> 的兼容格式回退，回退地址只靠换后缀拼接，不查询数据库。
 *
 * 单文件处理分成准备和提交两步。准备阶段只写目标文件和映射，提交阶段负责引用、
 * 链接，任何一步失败都会把引用退回源文件并记录失败，下次执行凭映射接管已有结果
 * 继续提交，不会重复编码。
 */
class Libre_Compress_Output {

    /**
     * 目标格式输出映射 post meta 键（供恢复原图时反向处理）
     *
     * @var string
     */
    const OUTPUT_META_KEY = '_libre_compress_output';

    /**
     * 正文链接扫描的单页文章数量
     *
     * @var int
     */
    const REFERENCE_BATCH_SIZE = 100;

    /**
     * 文件名分配的最大尝试次数
     *
     * @var int
     */
    const MAX_NAME_ATTEMPTS = 200;

    /**
     * 目标格式对应的编码工具注册名
     *
     * @var array
     */
    private $target_tools = array(
        'webp' => 'cwebp',
        'avif' => 'avifenc',
    );

    /**
     * 目标格式对应的 MIME 类型
     *
     * @var array
     */
    private $target_mimes = array(
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    );

    /**
     * 支持目标格式输出的源扩展名（jpg 涵盖 jpeg）
     *
     * @var array
     */
    private $source_formats = array( 'png', 'jpg', 'gif', 'svg' );

    /**
     * 源格式对应的 MIME 类型
     *
     * @var array
     */
    private $format_mimes = array(
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    );

    /**
     * 参与同名判断的全部图片后缀
     *
     * 转换前逐个查一遍，确保新生成的"同名换后缀"文件在整份媒体库里都是独一份的：
     * 目标后缀被占用会直接覆盖别人的图，其他后缀存在则会让前台的旧格式回退
     * 探到不属于本附件的图片。WordPress 上传时只按完整文件名避让，同名不同后缀
     * 的文件完全可以共存，所以这一步不能省。
     *
     * @var array
     */
    private $name_conflict_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif' );

    /**
     * 获取目标格式输出设置
     *
     * @return array target: webp|avif, formats: 已勾选的源格式列表
     */
    public function get_output_settings(): array {
        $general = get_option( 'libre_compress_general', array() );

        $target = ( isset( $general['output_format'] ) && 'avif' === $general['output_format'] ) ? 'avif' : 'webp';

        $formats = array();
        foreach ( $this->source_formats as $format ) {
            if ( ! empty( $general[ 'output_' . $format ] ) ) {
                $formats[] = $format;
            }
        }

        return array(
            'target'  => $target,
            'formats' => $formats,
        );
    }

    /**
     * 判断文件是否应按设置输出为目标格式
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    public function should_output_target( string $file_path ): bool {
        $settings = $this->get_output_settings();
        $format   = $this->format_of( $file_path );

        // WebP / AVIF 本身是可直接压缩的格式，不允许再次输出目标格式。
        if ( in_array( $format, array( 'webp', 'avif' ), true ) ) {
            return false;
        }

        // APNG 转 WebP/AVIF 同样只剩第一帧，统一不转换。
        if ( 'png' === $format && Libre_Compress_Compressor::is_apng( $file_path ) ) {
            return false;
        }

        return in_array( $format, $settings['formats'], true );
    }

    /**
     * 将单个文件压缩为目标格式（准备阶段）
     *
     * 流程固定为：目标名重名时先给源文件改名 → 备份原图 → 转换生成新格式 →
     * 单独压缩旧格式。备份必须早于改名和转换，否则备份下来的是动过的文件；
     * 旧格式必须在转换之后再压缩，否则压缩损失会叠加到转换质量上。
     *
     * 只生成目标文件、压缩记录和恢复映射，不改动附件引用；引用同步由提交阶段
     * 统一完成，因此结果不更小或备份失败时源文件必然完好。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @param string $size_type     尺寸类型
     * @param string $target        本次操作固定的目标格式
     * @return array 压缩结果
     */
    public function compress_to_target_format( int $attachment_id, string $file_path, string $size_type = 'full', string $target = '' ): array {
        $result_template = array(
            'file_path'       => $file_path,
            'from'            => $file_path,
            'to'              => '',
            'status'          => 'failed',
            'message'         => '',
            'original_size'   => 0,
            'compressed_size' => 0,
        );

        if ( ! file_exists( $file_path ) ) {
            $result_template['message'] = __( '文件不存在', 'libre-compress' );
            return $result_template;
        }

        if ( ! $this->is_safe_path( $file_path ) ) {
            $result_template['message'] = __( '文件路径不安全', 'libre-compress' );
            return $result_template;
        }

        $settings = $this->get_output_settings();
        $target   = in_array( $target, array( 'webp', 'avif' ), true ) ? $target : $settings['target'];
        $format   = $this->format_of( $file_path );

        if ( ! in_array( $format, $settings['formats'], true ) ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '该格式未配置格式转换', 'libre-compress' );
            return $result_template;
        }

        $original_size = (int) filesize( $file_path );
        $tool_name     = $target;

        // 已有映射说明插件此前确实把该文件转换过一次，可能是上次提交中断。
        // 只认映射，不能凭同名文件或大小猜测，否则会误把无关图片当作压缩结果接管。
        $entry          = $this->find_output_entry( $attachment_id, $size_type );
        $natural_target = $this->natural_target_path( $file_path, $target );

        if ( null !== $entry && $this->normalized_path( $entry['to'] ) !== $this->normalized_path( $natural_target ) ) {
            // 映射指向旧方案分配的名字，与"新旧格式同名"的回退规则不符，按新规则重新开始。
            $this->remove_output_entry( $attachment_id, $size_type );
            $entry = null;
        }

        // 同目录已有任何后缀的同名图片时，先给源文件改一个独一无二的名字。
        if ( null === $entry && $this->has_name_conflict( $file_path ) ) {
            $renamed = $this->rename_source_for_target( $attachment_id, $file_path );

            if ( null === $renamed ) {
                $result_template['message']       = __( '无法为源文件分配新文件名，已保留原文件', 'libre-compress' );
                $result_template['original_size'] = $original_size;
                return $result_template;
            }

            $file_path                   = $renamed;
            $natural_target              = $this->natural_target_path( $file_path, $target );
            $result_template['file_path'] = $file_path;
            $result_template['from']     = $file_path;
        }

        // 备份先于任何改动：备份的必须是还没被压缩和转换动过的原图。
        $settings_general = get_option( 'libre_compress_general', array() );
        $backup_enabled   = isset( $settings_general['backup_enabled'] ) ? (bool) $settings_general['backup_enabled'] : true;

        if ( $backup_enabled && ! libre_compress()->backup->create_backup( $attachment_id, $file_path ) ) {
            $result_template['message']       = __( '无法创建原图备份，已停止压缩', 'libre-compress' );
            $result_template['original_size'] = $original_size;
            return $result_template;
        }

        if ( null !== $entry && $this->is_safe_path( $entry['to'] ) && (int) filesize( $entry['to'] ) < $original_size ) {
            // 上次已生成的转换结果仍然有效，直接接管，不重复编码。
            $adopted = $this->prepare_existing_output( $attachment_id, $file_path, $entry['to'], $size_type, $original_size, $tool_name );

            if ( isset( $adopted['status'] ) && 'success' === $adopted['status'] ) {
                // 上次可能中断在旧格式压缩之前：这里补上兼容格式回退的压缩。
                $this->compress_fallback_file( $attachment_id, $file_path, $size_type );
            }

            return $adopted;
        }

        // 没有可接管的结果时重新编码；复用了旧映射的目标名，避免另起新名字留下多余文件。
        $target_path = null !== $entry ? $entry['to'] : $natural_target;

        $temp_token  = wp_generate_password( 12, false, false );
        $temp_output = $file_path . '.lc-compress-' . $temp_token . '.' . $target;
        $encode_ok   = false;
        $error_msg   = '';
        $temp_source = '';
        $command     = false;

        if ( 'gif' === $format ) {
            // 动画 GIF 使用专用工具，静态 GIF 先由 GD 解码为 PNG。
            if ( Libre_Compress_Compressor::is_animated_gif( $file_path ) ) {
                $animated = $this->build_animated_gif_command( $file_path, $temp_output, $target );

                if ( false === $animated ) {
                    // 缺工具：环境未配齐，不写入压缩记录，保留为待处理。
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = ( 'webp' === $target )
                        ? __( '动画 GIF 压缩为 WebP 需要 gif2webp 工具（libwebp 套件）', 'libre-compress' )
                        : __( '动画 GIF 压缩为 AVIF 需要 ffmpeg', 'libre-compress' );
                    return $result_template;
                }

                $command     = $animated['command'];
                $temp_source = $animated['temp'];
                $tool_name   = 'webp' === $target ? 'gif2webp' : 'ffmpeg+avifenc';
            } else {
                $temp_source = $file_path . '.lc-source-' . $temp_token . '.png';
                if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = __( '静态 GIF 压缩需要 GD 扩展', 'libre-compress' );
                    return $result_template;
                }
                if ( ! $this->decode_gif_to_png( $file_path, $temp_source ) ) {
                    if ( file_exists( $temp_source ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        unlink( $temp_source );
                    }
                    $result_template['message'] = __( 'GIF 压缩预处理失败', 'libre-compress' );
                    return $result_template;
                }
                $command = $this->build_encode_command( $temp_source, $temp_output, $target );
            }
        } elseif ( 'svg' === $format ) {
            // SVG 需先栅格化为 PNG 中间文件
            $temp_source = $file_path . '.lc-source-' . $temp_token . '.png';
            if ( false === $this->get_resvg_path() ) {
                $result_template['status']  = 'skipped';
                $result_template['message'] = __( 'SVG 压缩需要 resvg 工具', 'libre-compress' );
                return $result_template;
            }
            if ( ! $this->rasterize_svg_to_png( $file_path, $temp_source ) ) {
                if ( file_exists( $temp_source ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_source );
                }
                $result_template['message'] = __( 'SVG 压缩预处理失败', 'libre-compress' );
                return $result_template;
            }
            $command = $this->build_encode_command( $temp_source, $temp_output, $target );
        } else {
            // PNG/JPG 由编码工具直接支持
            $command = $this->build_encode_command( $file_path, $temp_output, $target );
        }

        if ( false === $command ) {
            // 缺编码工具（cwebp/avifenc）：环境未配齐，不写入压缩记录，保留为待处理。
            $error_msg                 = __( '没有可用的压缩工具', 'libre-compress' );
            $result_template['status'] = 'skipped';
        } else {
            // 命令链按顺序执行，任一步失败即整体失败且不再执行后续步骤
            $exec_result = array( 'success' => false, 'output' => '', 'return_code' => -1 );

            foreach ( $command as $step ) {
                $exec_result = Libre_Compress_Tool_Base::run_command( $step );

                if ( ! $exec_result['success'] ) {
                    break;
                }
            }

            clearstatcache( true, $temp_output );

            if ( ! $exec_result['success'] || ! file_exists( $temp_output ) || filesize( $temp_output ) <= 0 ) {
                $error_msg = __( '压缩失败', 'libre-compress' );

                // 编码失败时清理可能残留的部分输出文件
                if ( file_exists( $temp_output ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_output );
                }
            } else {
                $encode_ok = true;
            }
        }

        // 清理临时源文件
        if ( '' !== $temp_source && file_exists( $temp_source ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_source );
        }

        if ( ! $encode_ok ) {
            $result_template['message'] = $error_msg;
            return $result_template;
        }

        $compressed_size = (int) filesize( $temp_output );

        if ( $compressed_size >= $original_size ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );

            // 转换后更大说明目标格式对该图片没有收益，保留原图并按 0% 记为已压缩。
            if ( ! $this->save_record( $attachment_id, $file_path, $size_type, $original_size, $original_size, $tool_name, 'success', '' ) ) {
                $result_template['message'] = __( '压缩记录保存失败，请重新压缩', 'libre-compress' );
                return $result_template;
            }

            $this->remove_output_entry( $attachment_id, $size_type );

            // 复用了旧映射的目标名时，上一轮的转换结果文件会变成无人引用的孤儿：
            // 映射刚被删掉，它既不会被下次重跑覆盖，也不会被恢复流程删除。
            if ( $entry && file_exists( $target_path ) && $this->is_safe_path( $target_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $target_path );
            }

            $result_template['status']          = 'success';
            $result_template['message']         = __( '转换结果没有变小，已保留原图', 'libre-compress' );
            $result_template['original_size']   = $original_size;
            $result_template['compressed_size'] = $original_size;
            return $result_template;
        }

        // 先写入目标文件并保留临时文件，状态写入失败时源文件仍然完好。
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $temp_output, $target_path ) || ! file_exists( $target_path ) || (int) filesize( $target_path ) !== $compressed_size ) {
            if ( file_exists( $target_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $target_path );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '压缩结果文件写入失败', 'libre-compress' );
            return $result_template;
        }

        if ( ! $this->save_record( $attachment_id, $target_path, $size_type, $original_size, $compressed_size, $tool_name, 'success', '' ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '压缩记录保存失败，已保留原文件', 'libre-compress' );
            return $result_template;
        }

        if ( ! $this->add_output_entry( $attachment_id, $size_type, $file_path, $target_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );
            $this->save_record( $attachment_id, $file_path, $size_type, $original_size, $original_size, $tool_name, 'failed', __( '恢复映射保存失败，已保留原文件', 'libre-compress' ) );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '恢复映射保存失败，已保留原文件', 'libre-compress' );
            return $result_template;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $temp_output );

        // 旧格式压缩成兼容格式回退：新格式已从未被压缩过的原图生成，这里再单独压缩旧格式。
        // 顺序不能反——先压缩旧格式再转换会让转换质量受损。
        $this->compress_fallback_file( $attachment_id, $file_path, $size_type );

        return array(
            'file_path'       => $target_path,
            'from'            => $file_path,
            'to'              => $target_path,
            'size_type'       => $size_type,
            'status'          => 'success',
            'message'         => __( '压缩成功', 'libre-compress' ),
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'tool_name'       => $tool_name,
        );
    }

    /**
     * 接管此前已生成的转换结果
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @param string $target_path   已生成的结果文件绝对路径
     * @param string $size_type     尺寸类型
     * @param int    $original_size 源文件大小
     * @param string $tool_name     工具名称
     * @return array 压缩结果
     */
    private function prepare_existing_output( int $attachment_id, string $file_path, string $target_path, string $size_type, int $original_size, string $tool_name ): array {
        $compressed_size = (int) filesize( $target_path );

        if ( ! $this->save_record( $attachment_id, $target_path, $size_type, $original_size, $compressed_size, $tool_name, 'success', '' ) ) {
            return array(
                'file_path'       => $file_path,
                'from'            => $file_path,
                'to'              => '',
                'status'          => 'failed',
                'message'         => __( '压缩记录保存失败，已保留原文件', 'libre-compress' ),
                'size_type'       => $size_type,
                'original_size'   => $original_size,
                'compressed_size' => 0,
            );
        }

        return array(
            'file_path'       => $target_path,
            'from'            => $file_path,
            'to'              => $target_path,
            'size_type'       => $size_type,
            'status'          => 'success',
            'message'         => __( '压缩成功', 'libre-compress' ),
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'tool_name'       => $tool_name,
        );
    }

    /**
     * 提交格式转换结果
     *
     * 顺序固定为：附件路径与元数据 → MIME → 文章内图片链接。
     * 旧格式文件按同名规则留在原地作为兼容格式回退，提交不删除任何源文件。
     * 任一步失败都把引用退回源文件并把记录写成失败，下次执行凭映射继续提交，
     * 不会留下指向已删除文件的引用。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $output_map    size_type => array( from, to )
     * @return bool 是否提交成功
     */
    public function commit_attachment_format( int $attachment_id, array $output_map ): bool {
        if ( empty( $output_map ) ) {
            return true;
        }

        $entries    = $this->describe_entries( $output_map );
        $snapshot   = $this->get_reference_snapshot( $attachment_id );
        $target     = $this->target_of_entries( $entries );
        $mime       = isset( $this->target_mimes[ $target ] ) ? $this->target_mimes[ $target ] : '';

        if ( ! $this->sync_attachment_paths( $attachment_id, $entries, $target ) ) {
            return $this->abort_commit( $attachment_id, $snapshot, $entries, __( '附件路径同步失败，已保留原文件', 'libre-compress' ) );
        }

        if ( '' !== $mime && ! $this->sync_attachment_mime( $attachment_id, $entries, $mime ) ) {
            return $this->abort_commit( $attachment_id, $snapshot, $entries, __( '附件 MIME 类型同步失败，已保留原文件', 'libre-compress' ) );
        }

        if ( ! $this->update_content_references( $this->reference_pairs( $entries, false ) ) ) {
            return $this->abort_commit( $attachment_id, $snapshot, $entries, __( '文章图片链接替换失败，已保留原文件', 'libre-compress' ) );
        }

        return true;
    }

    /**
     * 获取附件当前的引用快照，供提交失败时退回
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    private function get_reference_snapshot( int $attachment_id ): array {
        return array(
            'attached' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
            'metadata' => wp_get_attachment_metadata( $attachment_id ),
            'mime'     => get_post_mime_type( $attachment_id ),
        );
    }

    /**
     * 提交失败时退回引用、链接和记录
     *
     * @param int    $attachment_id 附件 ID
     * @param array  $snapshot      引用快照
     * @param array  $entries       转换条目
     * @param string $message       失败原因
     * @return bool 恒为 false
     */
    private function abort_commit( int $attachment_id, array $snapshot, array $entries, string $message ): bool {
        // 源文件按同名规则留在原地，回退引用永远有文件可指。
        $this->restore_reference_snapshot( $attachment_id, $snapshot );
        $this->update_content_references( $this->reference_pairs( $entries, true ) );

        foreach ( $entries as $entry ) {
            $size  = file_exists( $entry['from'] ) ? (int) filesize( $entry['from'] ) : 0;
            $saved = $this->save_record( $attachment_id, $entry['from'], $entry['size_type'], $size, $size, 'target-output', 'failed', $message );

            if ( ! $saved ) {
                // 连失败记录都写不进去时，绝不能留下成功记录造成误判。
                libre_compress()->database->delete_records_by_attachment( $attachment_id );
            }
        }

        return false;
    }

    /**
     * 把附件路径、元数据和 MIME 退回快照值
     *
     * @param int   $attachment_id 附件 ID
     * @param array $snapshot      引用快照
     */
    private function restore_reference_snapshot( int $attachment_id, array $snapshot ): void {
        $attached = isset( $snapshot['attached'] ) ? (string) $snapshot['attached'] : '';

        if ( '' === $attached ) {
            delete_post_meta( $attachment_id, '_wp_attached_file' );
        } else {
            update_post_meta( $attachment_id, '_wp_attached_file', wp_slash( $attached ) );
        }

        $metadata = isset( $snapshot['metadata'] ) ? $snapshot['metadata'] : null;
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        } else {
            delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
        }

        $mime = isset( $snapshot['mime'] ) ? (string) $snapshot['mime'] : '';
        if ( '' !== $mime && $mime !== get_post_mime_type( $attachment_id ) ) {
            wp_update_post(
                array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $mime,
                ),
                true
            );
        }

        clean_post_cache( $attachment_id );
    }

    /**
     * 同步转换后的附件路径与元数据
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       转换条目
     * @param string $target       目标格式
     * @return bool
     */
    private function sync_attachment_paths( int $attachment_id, array $entries, string $target ): bool {
        $full     = isset( $entries['full'] ) ? $entries['full'] : null;
        $metadata = wp_get_attachment_metadata( $attachment_id );
        $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

        if ( null !== $full ) {
            $known = array(
                $this->normalized_path( $full['from_relative'] ),
                $this->normalized_path( $full['to_relative'] ),
            );

            // 附件路径和元数据都不认识这次转换，说明引用已被外部改动，不能盲目覆盖。
            if ( ! in_array( $this->normalized_path( $attached ), $known, true )
                && ! ( is_array( $metadata ) && ! empty( $metadata['file'] ) && in_array( $this->normalized_path( (string) $metadata['file'] ), $known, true ) ) ) {
                return false;
            }

            if ( ! $this->write_attached_file( $attachment_id, $full['to'] ) ) {
                return false;
            }
        }

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            // 允许没有完整元数据的图片（SVG、第三方上传）：按目标图片的真实信息建立，读不到就不伪造。
            $first   = null !== $full ? $full : reset( $entries );
            $source  = is_array( $first ) ? $first['to'] : '';
            $created = $this->build_attachment_metadata( $source );

            if ( ! is_array( $created ) ) {
                return null === $full;
            }

            return $this->write_metadata( $attachment_id, $created );
        }

        return $this->write_metadata( $attachment_id, $this->apply_converted_metadata( $metadata, $entries, $target ) );
    }

    /**
     * 按转换条目更新元数据中的文件名与 MIME
     *
     * @param array  $metadata 现有元数据
     * @param array  $entries  转换条目
     * @param string $target   目标格式
     * @return array
     */
    private function apply_converted_metadata( array $metadata, array $entries, string $target ): array {
        $mime = isset( $this->target_mimes[ $target ] ) ? $this->target_mimes[ $target ] : '';

        if ( isset( $entries['full'] ) ) {
            $metadata['file']     = $entries['full']['to_relative'];
            $metadata['filesize'] = file_exists( $entries['full']['to'] ) ? (int) filesize( $entries['full']['to'] ) : 0;
        }

        if ( isset( $entries['original_image'] ) && ! empty( $metadata['original_image'] ) ) {
            $metadata['original_image'] = $entries['original_image']['to_name'];
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $entry = isset( $entries[ $size_name ] ) ? $entries[ $size_name ] : null;

                if ( null === $entry && ! empty( $size_data['file'] ) ) {
                    // 尺寸名与映射不一致时按文件名匹配，避免元数据停留在旧文件。
                    foreach ( $entries as $candidate ) {
                        if ( $this->normalized_path( (string) $size_data['file'] ) === $this->normalized_path( $candidate['from_name'] )
                            || $this->normalized_path( (string) $size_data['file'] ) === $this->normalized_path( $candidate['to_name'] ) ) {
                            $entry = $candidate;
                            break;
                        }
                    }
                }

                if ( null === $entry ) {
                    continue;
                }

                $metadata['sizes'][ $size_name ]['file']      = $entry['to_name'];
                $metadata['sizes'][ $size_name ]['mime-type'] = $mime;
            }
        }

        return $metadata;
    }

    /**
     * 为缺少元数据的图片读取真实信息
     *
     * @param string $file_path 图片绝对路径
     * @return array|null 读不到真实尺寸时返回 null
     */
    private function build_attachment_metadata( string $file_path ) {
        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return null;
        }

        $size = wp_getimagesize( $file_path );

        if ( empty( $size[0] ) || empty( $size[1] ) ) {
            return null;
        }

        $metadata = array(
            'file'     => $this->get_relative_upload_path( $file_path ),
            'width'    => (int) $size[0],
            'height'   => (int) $size[1],
            'filesize' => (int) filesize( $file_path ),
        );

        if ( ! empty( $size['mime'] ) ) {
            $metadata['mime-type'] = (string) $size['mime'];
        }

        return $metadata;
    }

    /**
     * 同步附件级 MIME（只跟随主文件）
     *
     * @param int    $attachment_id 附件 ID
     * @param array  $entries       转换条目
     * @param string $mime          目标 MIME
     * @return bool
     */
    private function sync_attachment_mime( int $attachment_id, array $entries, string $mime ): bool {
        if ( ! isset( $entries['full'] ) ) {
            return true;
        }

        $updated = wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => $mime,
            ),
            true
        );

        return ! is_wp_error( $updated ) && $mime === get_post_mime_type( $attachment_id );
    }

    /**
     * 写入附件路径并读回校验
     *
     * 只传相对路径：update_attached_file 内部用 str_starts_with 与 uploads 的 basedir
     * 逐字符比对，Windows 下 basedir 是反斜杠，而本插件的路径一律是正斜杠，
     * 传绝对路径会匹配失败并把整个绝对路径存进 _wp_attached_file，
     * 之后 get_attached_file 会拼出 uploads + 绝对路径 的双重前缀。
     *
     * update_post_meta 在值未变化时同样返回 false，因此一律以读回结果为准。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $absolute_path 目标绝对路径
     * @return bool
     */
    private function write_attached_file( int $attachment_id, string $absolute_path ): bool {
        // uploads 外的路径一律不写，否则会把任意绝对路径存进 _wp_attached_file。
        if ( ! $this->is_safe_destination_path( $absolute_path ) ) {
            return false;
        }

        $relative = $this->get_relative_upload_path( $absolute_path );

        update_attached_file( $attachment_id, $relative );

        return $this->normalized_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) === $this->normalized_path( $relative );
    }

    /**
     * 写入附件元数据并读回校验
     *
     * @param int   $attachment_id 附件 ID
     * @param array $metadata      期望的元数据
     * @return bool
     */
    private function write_metadata( int $attachment_id, array $metadata ): bool {
        if ( ! empty( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $saved = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $saved ) ) {
            return empty( $metadata );
        }

        if ( isset( $metadata['file'] ) && $this->normalized_path( (string) $saved['file'] ) !== $this->normalized_path( (string) $metadata['file'] ) ) {
            return false;
        }

        if ( isset( $metadata['original_image'] ) && (string) $saved['original_image'] !== (string) $metadata['original_image'] ) {
            return false;
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! isset( $saved['sizes'][ $size_name ]['file'] ) || $saved['sizes'][ $size_name ]['file'] !== $size_data['file'] ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 获取附件的转换映射条目（绝对路径形式，按尺寸类型索引）
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_output_entries( int $attachment_id ): array {
        $upload_dir = wp_upload_dir();
        $base_dir   = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $entries    = array();

        foreach ( $this->read_output_entries( $attachment_id ) as $entry ) {
            $entries[] = array(
                'size_type'     => $entry['size_type'],
                'from'          => $base_dir . '/' . $entry['from'],
                'to'            => $base_dir . '/' . $entry['to'],
                'from_relative' => $entry['from'],
                'to_relative'   => $entry['to'],
                'from_name'     => basename( $entry['from'] ),
                'to_name'       => basename( $entry['to'] ),
            );
        }

        return $entries;
    }

    /**
     * 读取校验后的映射原始数据
     *
     * 映射是持久化数据，取出时按不可信处理：只接受 uploads 内的相对路径。
     *
     * @param int $attachment_id 附件 ID
     * @return array size_type => array( from, to, size_type )
     */
    private function read_output_entries( int $attachment_id ): array {
        $stored = get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );

        if ( ! is_array( $stored ) ) {
            return array();
        }

        $entries = array();

        foreach ( $stored as $entry ) {
            if ( empty( $entry['from'] ) || empty( $entry['to'] ) ) {
                continue;
            }

            $from = $this->sanitize_relative_path( (string) $entry['from'] );
            $to   = $this->sanitize_relative_path( (string) $entry['to'] );

            if ( '' === $from || '' === $to ) {
                continue;
            }

            $size_type                          = isset( $entry['size_type'] ) ? sanitize_text_field( (string) $entry['size_type'] ) : '';
            $size_type                          = '' === $size_type ? 'full' : $size_type;
            $entries[ $size_type ]              = array(
                'from'      => $from,
                'to'        => $to,
                'size_type' => $size_type,
            );
        }

        return $entries;
    }

    /**
     * 校验并规范化 uploads 内的相对路径
     *
     * @param string $path 相对路径
     * @return string 非法时返回空字符串
     */
    private function sanitize_relative_path( string $path ): string {
        $normalized = ltrim( wp_normalize_path( $path ), '/' );

        if ( '' === $normalized || false !== strpos( $normalized, '..' ) || false !== strpos( $normalized, ':' ) ) {
            return '';
        }

        return $normalized;
    }

    /**
     * 按尺寸类型删除恢复映射条目
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     */
    private function remove_output_entry( int $attachment_id, string $size_type ): void {
        $entries = $this->read_output_entries( $attachment_id );

        if ( ! isset( $entries[ $size_type ] ) ) {
            return;
        }

        unset( $entries[ $size_type ] );
        $this->store_output_entries( $attachment_id, $entries );
    }

    /**
     * 查找该尺寸已有的转换映射
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @return array|null
     */
    private function find_output_entry( int $attachment_id, string $size_type ) {
        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( $entry['size_type'] === $size_type ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * 写入转换映射，供中断续跑和恢复原图使用
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @param string $from_path     源文件绝对路径
     * @param string $to_path       结果文件绝对路径
     * @return bool 是否保存成功
     */
    private function add_output_entry( int $attachment_id, string $size_type, string $from_path, string $to_path ): bool {
        $entries                    = $this->read_output_entries( $attachment_id );
        $entries[ $size_type ]      = array(
            'from'      => $this->get_relative_upload_path( $from_path ),
            'to'        => $this->get_relative_upload_path( $to_path ),
            'size_type' => $size_type,
        );

        return $this->store_output_entries( $attachment_id, $entries );
    }

    /**
     * 持久化映射并读回校验
     *
     * 映射只存 uploads 内相对路径，避免 Windows 绝对路径的反斜杠在元数据写入时被剥掉。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       size_type => 映射条目
     * @return bool
     */
    private function store_output_entries( int $attachment_id, array $entries ): bool {
        if ( empty( $entries ) ) {
            return $this->forget_output_entries( $attachment_id );
        }

        update_post_meta( $attachment_id, self::OUTPUT_META_KEY, wp_slash( array_values( $entries ) ) );
        $saved = $this->read_output_entries( $attachment_id );

        foreach ( $entries as $size_type => $entry ) {
            if ( ! isset( $saved[ $size_type ] )
                || $saved[ $size_type ]['from'] !== $entry['from']
                || $saved[ $size_type ]['to'] !== $entry['to'] ) {
                return false;
            }
        }

        return count( $saved ) === count( $entries );
    }

    /**
     * 删除转换映射
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function forget_output_entries( int $attachment_id ): bool {
        if ( '' === get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true ) ) {
            return true;
        }

        delete_post_meta( $attachment_id, self::OUTPUT_META_KEY );

        return '' === get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );
    }

    /**
     * 把转换条目整理成提交阶段使用的统一结构
     *
     * @param array $output_map size_type => array( from, to )
     * @return array
     */
    private function describe_entries( array $output_map ): array {
        $entries = array();

        foreach ( $output_map as $size_type => $entry ) {
            if ( empty( $entry['from'] ) || empty( $entry['to'] ) ) {
                continue;
            }

            $from_relative = $this->get_relative_upload_path( $entry['from'] );
            $to_relative   = $this->get_relative_upload_path( $entry['to'] );

            $entries[ $size_type ] = array(
                'size_type'     => (string) $size_type,
                'from'          => $entry['from'],
                'to'            => $entry['to'],
                'from_relative' => $from_relative,
                'to_relative'   => $to_relative,
                'from_name'     => basename( $from_relative ),
                'to_name'       => basename( $to_relative ),
            );
        }

        return $entries;
    }

    /**
     * 推断本批转换条目的目标格式
     *
     * @param array $entries 条目集合
     * @return string webp|avif，无法判断时返回空字符串
     */
    private function target_of_entries( array $entries ): string {
        foreach ( $entries as $entry ) {
            $format = $this->format_of( $entry['to'] );

            if ( 'webp' === $format || 'avif' === $format ) {
                return $format;
            }
        }

        return '';
    }

    /**
     * 生成正文链接替换用的路径对
     *
     * @param array $entries 条目集合
     * @param bool  $reverse 是否反向（转换结果 → 源文件）
     * @return array
     */
    private function reference_pairs( array $entries, bool $reverse ): array {
        $pairs = array();

        foreach ( $entries as $entry ) {
            $pairs[] = $reverse
                ? array( 'from' => $entry['to'], 'to' => $entry['from'] )
                : array( 'from' => $entry['from'], 'to' => $entry['to'] );
        }

        return $pairs;
    }

    /**
     * 恢复格式转换前的引用与正文链接
     *
     * 只处理引用和链接，不删除任何文件；调用方需先确认备份已经写回原路径。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       需要恢复的映射条目（get_output_entries 的形式）
     * @return bool
     */
    public function revert_attachment_format( int $attachment_id, array $entries ): bool {
        if ( empty( $entries ) ) {
            return true;
        }

        $mapped   = array();
        foreach ( $entries as $entry ) {
            $mapped[ $entry['size_type'] ] = $entry;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $full     = isset( $mapped['full'] ) ? $mapped['full'] : null;

        if ( null !== $full ) {
            $points_to_target = $this->normalized_path( $attached ) === $this->normalized_path( $full['to_relative'] )
                || ( is_array( $metadata ) && ! empty( $metadata['file'] ) && $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $full['to_relative'] ) );
            $points_to_source = $this->normalized_path( $attached ) === $this->normalized_path( $full['from_relative'] )
                || ( is_array( $metadata ) && ! empty( $metadata['file'] ) && $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $full['from_relative'] ) );

            // 既不是转换结果也不是源文件，说明引用已被外部改动，不能盲目写回。
            if ( ! $points_to_target && ! $points_to_source ) {
                return false;
            }

            if ( $points_to_target && ! $this->write_attached_file( $attachment_id, $full['from'] ) ) {
                return false;
            }
        }

        if ( is_array( $metadata ) ) {
            $metadata = $this->apply_reverted_metadata( $metadata, $mapped );

            if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
                return false;
            }
        }

        if ( null !== $full ) {
            $source_mime = $this->source_mime_of( $full['from'] );

            if ( '' !== $source_mime && $source_mime !== get_post_mime_type( $attachment_id ) ) {
                $updated = wp_update_post(
                    array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $source_mime,
                    ),
                    true
                );

                if ( is_wp_error( $updated ) || $source_mime !== get_post_mime_type( $attachment_id ) ) {
                    return false;
                }
            }
        }

        return $this->update_content_references( $this->reference_pairs( $mapped, true ) );
    }

    /**
     * 按映射把元数据中的文件名改回源格式
     *
     * @param array $metadata 现有元数据
     * @param array $mapped   size_type => 条目
     * @return array
     */
    private function apply_reverted_metadata( array $metadata, array $mapped ): array {
        if ( isset( $mapped['full'] ) && ! empty( $metadata['file'] )
            && (
                $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $mapped['full']['to_relative'] )
                || $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $mapped['full']['from_relative'] )
            ) ) {
            $metadata['file'] = $mapped['full']['from_relative'];

            if ( file_exists( $mapped['full']['from'] ) ) {
                $metadata['filesize'] = (int) filesize( $mapped['full']['from'] );
            }
        }

        if ( isset( $mapped['original_image'] ) && ! empty( $metadata['original_image'] ) ) {
            $entry = $mapped['original_image'];

            if ( in_array(
                $this->normalized_path( (string) $metadata['original_image'] ),
                array( $this->normalized_path( $entry['to_name'] ), $this->normalized_path( $entry['from_name'] ) ),
                true
            ) ) {
                $metadata['original_image'] = $entry['from_name'];
            }
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $entry = isset( $mapped[ $size_name ] ) ? $mapped[ $size_name ] : null;

                if ( null === $entry ) {
                    foreach ( $mapped as $mapped_entry ) {
                        if ( $this->normalized_path( $size_data['file'] ) === $this->normalized_path( $mapped_entry['to_name'] ) ) {
                            $entry = $mapped_entry;
                            break;
                        }
                    }
                }

                if ( null === $entry ) {
                    continue;
                }

                $metadata['sizes'][ $size_name ]['file']      = $entry['from_name'];
                $metadata['sizes'][ $size_name ]['mime-type'] = $this->source_mime_of( $entry['from'] );
            }
        }

        return $metadata;
    }

    /**
     * 获取源格式对应的 MIME
     *
     * @param string $file_path 文件路径
     * @return string
     */
    private function source_mime_of( string $file_path ): string {
        $format = $this->format_of( $file_path );

        return isset( $this->format_mimes[ $format ] ) ? $this->format_mimes[ $format ] : '';
    }

    /**
     * 同目录是否已有同名不同后缀的图片文件
     *
     * 不看后缀优先级，所有图片后缀逐个查：只要有一个同名文件存在，新生成的
     * "同名换后缀"文件就不再是独一份，必须先把源文件改成独一无二的名字。
     *
     * @param string $file_path 源文件绝对路径
     * @return bool
     */
    private function has_name_conflict( string $file_path ): bool {
        $directory = dirname( $file_path );
        $basename  = pathinfo( $file_path, PATHINFO_FILENAME );
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        foreach ( $this->name_conflict_extensions as $candidate ) {
            // 自己的后缀就是文件本身，不算冲突。
            if ( $candidate === $extension ) {
                continue;
            }

            if ( file_exists( $directory . '/' . $basename . '.' . $candidate ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 新旧格式同名规则下的目标文件路径
     *
     * @param string $file_path 源文件绝对路径
     * @param string $target    目标格式
     * @return string
     */
    private function natural_target_path( string $file_path, string $target ): string {
        return dirname( $file_path ) . '/' . pathinfo( $file_path, PATHINFO_FILENAME ) . '.' . $target;
    }

    /**
     * 目标名被占用时给源文件改一个独一无二的名字
     *
     * 顺序固定为：正文链接 → 元数据与附件路径 → 磁盘改名。正文链接先改，失败时还没有
     * 动过任何文件；任一步失败都会把已完成的步骤退回原状，绝不留下引用不到的图片。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @return string|null 改名后的绝对路径，失败返回 null
     */
    private function rename_source_for_target( int $attachment_id, string $file_path ): ?string {
        $new_path = $this->allocate_unique_source_path( $file_path );

        if ( '' === $new_path ) {
            return null;
        }

        // 正文里固化的旧地址先改到新名字，失败时磁盘和元数据都还没动。
        if ( ! $this->update_content_references(
            array(
                array(
                    'from' => $file_path,
                    'to'   => $new_path,
                ),
            )
        ) ) {
            return null;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $updated  = is_array( $metadata ) ? $this->apply_renamed_metadata( $metadata, $file_path, $new_path ) : $metadata;

        // 只有附件路径本来就指向这个文件时才跟着改名，缩略图文件不能写进附件路径。
        $is_source = '' !== $attached
            && $this->normalized_path( $attached ) === $this->normalized_path( $this->get_relative_upload_path( $file_path ) );

        // 元数据没跟上就把元数据和正文退回原名字，文件一个字节都没动。
        if ( ! $this->write_metadata( $attachment_id, is_array( $updated ) ? $updated : array() )
            || ( $is_source && ! $this->write_attached_file( $attachment_id, $new_path ) ) ) {
            if ( is_array( $metadata ) ) {
                $this->write_metadata( $attachment_id, $metadata );
            }
            if ( $is_source ) {
                $this->write_attached_file( $attachment_id, $file_path );
            }
            $this->update_content_references(
                array(
                    array(
                        'from' => $new_path,
                        'to'   => $file_path,
                    ),
                )
            );

            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if ( ! rename( $file_path, $new_path ) || ! is_file( $new_path ) ) {
            // 改名失败：元数据、附件路径和正文全部退回原名，磁盘保持原样。
            if ( is_array( $metadata ) ) {
                $this->write_metadata( $attachment_id, $metadata );
            }
            if ( $is_source ) {
                $this->write_attached_file( $attachment_id, $file_path );
            }
            $this->update_content_references(
                array(
                    array(
                        'from' => $new_path,
                        'to'   => $file_path,
                    ),
                )
            );

            return null;
        }

        return $new_path;
    }

    /**
     * 分配未被占用的源文件名
     *
     * 与 WordPress 上传时的避让规则一致：依次追加 -1、-2 等数字后缀。
     *
     * @param string $file_path 源文件绝对路径
     * @return string 无法分配时返回空字符串
     */
    private function allocate_unique_source_path( string $file_path ): string {
        $directory = dirname( $file_path );
        $name      = pathinfo( $file_path, PATHINFO_FILENAME );
        $extension = pathinfo( $file_path, PATHINFO_EXTENSION );

        for ( $attempt = 1; $attempt <= self::MAX_NAME_ATTEMPTS; $attempt++ ) {
            $candidate = $directory . '/' . $name . '-' . $attempt . '.' . $extension;

            if ( ! file_exists( $candidate ) ) {
                return $this->is_safe_destination_path( $candidate ) ? $candidate : '';
            }
        }

        return '';
    }

    /**
     * 按改名后的路径更新元数据中的文件名
     *
     * @param array  $metadata 现有元数据
     * @param string $old_path 改名前的绝对路径
     * @param string $new_path 改名后的绝对路径
     * @return array
     */
    private function apply_renamed_metadata( array $metadata, string $old_path, string $new_path ): array {
        $old_relative = $this->get_relative_upload_path( $old_path );
        $new_relative = $this->get_relative_upload_path( $new_path );
        $old_name     = basename( $old_relative );
        $new_name     = basename( $new_relative );

        if ( ! empty( $metadata['file'] )
            && $this->normalized_path( (string) $metadata['file'] ) === $this->normalized_path( $old_relative ) ) {
            $metadata['file'] = $new_relative;
        }

        if ( ! empty( $metadata['original_image'] ) && (string) $metadata['original_image'] === $old_name ) {
            $metadata['original_image'] = $new_name;
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                if ( (string) $size_data['file'] === $old_name ) {
                    $metadata['sizes'][ $size_name ]['file'] = $new_name;
                }
            }
        }

        return $metadata;
    }

    /**
     * 把旧格式文件压缩成兼容格式回退文件
     *
     * 转换此时已从没有被压缩过的原图生成新格式，旧格式再单独压缩：先压缩旧格式再转换
     * 会让转换质量受损，顺序不能反。压缩记录写在后缀区分的尺寸类型下，
     * 避免与新格式文件的记录争用同一行。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     旧格式文件绝对路径
     * @param string $size_type     尺寸类型
     */
    private function compress_fallback_file( int $attachment_id, string $file_path, string $size_type ): void {
        $fallback_size_type = $this->fallback_size_type( $size_type );
        $record             = libre_compress()->database->get_record( $attachment_id, $fallback_size_type );

        // 已经按同样大小压缩过就跳过：接管旧结果时重复压缩没有收益。
        if ( $this->record_matches_file( $record, $file_path ) ) {
            return;
        }

        libre_compress()->compressor->compress_file( $attachment_id, $file_path, $fallback_size_type );
    }

    /**
     * 兼容格式回退文件专用的记录尺寸类型
     *
     * 压缩记录表以 (attachment_id, size_type) 唯一：新旧格式文件同属一个尺寸，
     * 回退文件必须落在另一个键下，否则会顶掉新格式文件的记录。
     *
     * 不再截断：size_type 列宽就是 InnoDB 唯一索引的物理上限（utf8mb4 下
     * 3072 字节索引减去 8 字节 BIGINT，每字符最多 4 字节），任何真实尺寸名
     * 都远在这个范围之内，截断只会平白制造撞车。
     *
     * @param string $size_type 尺寸类型
     * @return string
     */
    private function fallback_size_type( string $size_type ): string {
        return 'fallback_' . $size_type;
    }

    /**
     * 压缩记录是否与当前文件完全匹配
     *
     * @param array|null $record    压缩记录
     * @param string     $file_path 文件绝对路径
     * @return bool
     */
    private function record_matches_file( $record, string $file_path ): bool {
        if ( ! is_array( $record ) || 'success' !== ( isset( $record['status'] ) ? (string) $record['status'] : '' ) || empty( $record['file_path'] ) ) {
            return false;
        }

        clearstatcache( true, $file_path );

        if ( ! is_file( $file_path ) ) {
            return false;
        }

        return $this->normalized_path( (string) $record['file_path'] ) === $this->normalized_path( $this->get_relative_upload_path( $file_path ) )
            && (int) $record['compressed_size'] === (int) filesize( $file_path );
    }

    /**
     * 获取附件可以删除的兼容格式回退条目
     *
     * 仍在使用的文件、磁盘上已不存在的文件和没有新格式对应的文件都不删：
     * 删掉任何一个都会让附件或正文失去可用的图片。
     *
     * @param int $attachment_id 附件 ID
     * @return array 每项包含 from、to 和 size_type
     */
    public function get_fallback_entries( int $attachment_id ): array {
        $live = array();

        foreach ( libre_compress()->compressor->get_attachment_files( $attachment_id ) as $file ) {
            $live[] = $this->normalized_path( $file['file_path'] );
        }

        $entries = array();

        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( in_array( $this->normalized_path( $entry['from'] ), $live, true ) ) {
                continue;
            }

            if ( ! is_file( $entry['from'] ) || ! is_file( $entry['to'] ) ) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * 删除附件在媒体库里的旧格式回退文件
     *
     * 旧格式文件按同名规则留在原地，不写进附件元数据，WordPress 删除附件时不会带走
     * 它们，只能由插件按映射清理，否则会一直占着磁盘。
     *
     * @param int $attachment_id 附件 ID
     */
    public function delete_fallback_files( int $attachment_id ): void {
        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( is_link( $entry['from'] ) || ! is_file( $entry['from'] ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $entry['from'] );
        }
    }

    /**
     * 删除兼容格式回退文件的压缩记录
     *
     * 回退文件已删除，记录指向的文件不再存在，留着只会让状态与实际不符。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $size_types    已删除回退文件对应的尺寸类型
     */
    public function forget_fallback_records( int $attachment_id, array $size_types ): void {
        foreach ( $size_types as $size_type ) {
            $record = libre_compress()->database->get_record( $attachment_id, $this->fallback_size_type( (string) $size_type ) );

            if ( is_array( $record ) && ! empty( $record['id'] ) ) {
                libre_compress()->database->delete_record( (int) $record['id'] );
            }
        }
    }

    /**
     * 写入统一压缩记录
     *
     * @param int    $attachment_id   附件 ID
     * @param string $file_path       文件路径
     * @param string $size_type       尺寸类型
     * @param int    $original_size   处理前大小
     * @param int    $compressed_size 处理后大小
     * @param string $tool_name       工具名称
     * @param string $status          状态
     * @param string $message         附加说明
     * @return bool 是否写入成功
     */
    private function save_record( int $attachment_id, string $file_path, string $size_type, int $original_size, int $compressed_size, string $tool_name, string $status, string $message ): bool {
        return libre_compress()->compressor->save_compression_record(
            $attachment_id,
            $file_path,
            $size_type,
            $original_size,
            $compressed_size,
            $tool_name,
            $status,
            $message
        );
    }

    /**
     * 分页更新文章中的图片链接
     *
     * 转换后源文件会被删除，正文里已固化的旧地址必须持久改写；写入前比较原内容，
     * 避免覆盖用户在编辑器和别处同时做出的修改。
     *
     * @param array $replacements 每项包含 from 和 to 绝对路径
     * @param int   $replaced     输出参数：实际替换掉的引用数量
     * @return bool 是否全部更新成功
     */
    public function update_content_references( array $replacements, &$replaced = 0 ): bool {
        global $wpdb;

        $replaced = 0;

        if ( empty( $replacements ) ) {
            return true;
        }

        $like_sql  = array();
        $like_args = array();

        foreach ( $replacements as $replacement ) {
            $needle = $this->content_path_of( $replacement['from'] );

            if ( '' === $needle ) {
                continue;
            }

            // SQL 侧只做粗筛：同时匹配普通路径和区块 JSON 里 \/ 的转义写法，精确判断交给替换逻辑。
            foreach ( array( $needle, str_replace( '/', '\\/', $needle ) ) as $pattern ) {
                $like_sql[]  = 'post_content LIKE %s';
                $like_args[] = '%' . $wpdb->esc_like( $pattern ) . '%';
            }
        }

        if ( empty( $like_sql ) ) {
            return true;
        }

        $after_id = 0;
        $success  = true;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                    WHERE ID > %d
                    AND post_type <> 'revision'
                    AND post_status NOT IN ('trash', 'auto-draft')
                    AND ( " . implode( ' OR ', $like_sql ) . ' )
                    ORDER BY ID ASC
                    LIMIT %d',
                    array_merge( array( $after_id ), $like_args, array( self::REFERENCE_BATCH_SIZE ) )
                )
            );

            $posts     = is_array( $posts ) ? $posts : array();
            $page_size = count( $posts );

            foreach ( $posts as $post ) {
                $post_id = (int) $post->ID;
                $content = (string) $post->post_content;
                $updated = $this->replace_content_references( $content, $replacements );

                $after_id = max( $after_id, $post_id );

                if ( $updated === $content ) {
                    continue;
                }

                if ( $this->write_post_content( $post_id, $content, $updated ) ) {
                    $replaced += $this->count_missing_references( $content, $updated, $replacements );
                    continue;
                }

                // 写入未生效：可能已被并发修改，重读最新内容后再替换一次。
                $current = $this->read_post_content( $post_id );

                if ( $current === $content ) {
                    $success = false;
                    continue;
                }

                $retry = $this->replace_content_references( $current, $replacements );

                if ( $retry === $current ) {
                    continue;
                }

                if ( $this->write_post_content( $post_id, $current, $retry ) ) {
                    $replaced += $this->count_missing_references( $current, $retry, $replacements );
                } else {
                    $success = false;
                }
            }
        } while ( $page_size >= self::REFERENCE_BATCH_SIZE );

        return $success;
    }

    /**
     * 统计一次改写实际消除了多少处引用
     *
     * 同一个文件在 srcset 里可能出现多次，按出现次数计更接近用户看到的“替换了多少链接”。
     *
     * @param string $before       改写前的正文
     * @param string $after        改写后的正文
     * @param array  $replacements 每项包含 from 绝对路径
     * @return int
     */
    private function count_missing_references( string $before, string $after, array $replacements ): int {
        $count = 0;

        foreach ( $replacements as $replacement ) {
            $needle = $this->content_path_of( $replacement['from'] );

            if ( '' === $needle ) {
                continue;
            }

            $count += max( 0, substr_count( $before, $needle ) - substr_count( $after, $needle ) );
        }

        return $count;
    }

    /**
     * 读取文章内容
     *
     * @param int $post_id 文章 ID
     * @return string
     */
    private function read_post_content( int $post_id ): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id )
        );
    }

    /**
     * 比较原内容后写入文章内容
     *
     * @param int    $post_id  文章 ID
     * @param string $expected 读取到的原内容
     * @param string $content  新内容
     * @return bool 是否写入成功
     */
    private function write_post_content( int $post_id, string $expected, string $content ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $wpdb->posts,
            array( 'post_content' => $content ),
            array(
                'ID'           => $post_id,
                'post_content' => $expected,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        if ( 1 !== (int) $updated ) {
            return false;
        }

        clean_post_cache( $post_id );

        return true;
    }

    /**
     * 按实际映射替换内容中的图片地址
     *
     * 只替换文件名部分，保留协议、域名、查询参数、锚点和 srcset 的候选结构；
     * 同时兼容区块 JSON 里被转义成 \/ 的路径，外站地址与同名前缀文件不动。
     *
     * @param string $content     文章内容
     * @param array  $replacements 每项包含 from 和 to 绝对路径
     * @return string
     */
    public function replace_content_references( string $content, array $replacements ): string {
        foreach ( $replacements as $replacement ) {
            $content = $this->replace_reference_in_content( $content, $replacement['from'], $replacement['to'] );
        }

        return $content;
    }

    /**
     * 替换内容中指向某个 uploads 文件的地址
     *
     * @param string $content  文章内容
     * @param string $from     源文件绝对路径
     * @param string $to       结果文件绝对路径
     * @return string
     */
    private function replace_reference_in_content( string $content, string $from, string $to ): string {
        $needle = $this->content_path_of( $from );

        if ( '' === $needle || false === strpos( str_replace( '\\', '', $content ), str_replace( '\\', '', $needle ) ) ) {
            return $content;
        }

        $separator = '(?:\\\\)?/';
        $filename  = basename( $needle );
        $directory = rtrim( dirname( $needle ), '/' );
        $pattern   = $separator;

        foreach ( explode( '/', $directory ) as $segment ) {
            if ( '' === $segment ) {
                continue;
            }

            $pattern .= preg_quote( $segment, '#' ) . $separator;
        }

        // 文件名后面必须正好结束，避免把 a.jpg.extra 这类不同的文件一起改掉。
        $pattern .= '(?P<file>' . preg_quote( $filename, '#' ) . ')(?![\w.\-%~/-])(?![\\\\])';

        $matches = array();
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! preg_match_all( '#' . $pattern . '#i', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return $content;
        }

        $new_name = basename( $this->content_path_of( $to ) );

        // 从后往前替换，避免前面的替换让后面的偏移失效。
        for ( $index = count( $matches ) - 1; $index >= 0; $index-- ) {
            if ( ! isset( $matches[ $index ][0][1], $matches[ $index ]['file'][1] ) ) {
                continue;
            }

            $file_offset = (int) $matches[ $index ]['file'][1];

            // 以整段路径的起点判断宿主，匹配段自身以 / 开头，前面的内容正好是协议和域名。
            if ( ! $this->content_host_allowed( substr( $content, 0, (int) $matches[ $index ][0][1] ) ) ) {
                continue;
            }

            $content = substr_replace( $content, $new_name, $file_offset, strlen( $matches[ $index ]['file'][0] ) );
        }

        return $content;
    }

    /**
     * 把上传目录内的文件路径转换为站点内的 URL 路径
     *
     * @param string $file_path 绝对路径
     * @return string 无法定位时返回空字符串
     */
    private function content_path_of( string $file_path ): string {
        $upload    = wp_get_upload_dir();
        $base_path = isset( $upload['baseurl'] ) ? wp_parse_url( $upload['baseurl'], PHP_URL_PATH ) : '';
        $base_dir  = isset( $upload['basedir'] ) ? $upload['basedir'] : '';

        if ( ! is_string( $base_path ) || '' === $base_path || '' === $base_dir ) {
            return '';
        }

        $relative = $this->get_relative_upload_path( $file_path );

        if ( $relative === $this->normalized_path( $file_path ) ) {
            // 不在上传目录内，没有可替换的站点路径。
            return '';
        }

        return untrailingslashit( $base_path ) . '/' . ltrim( $relative, '/' );
    }

    /**
     * 判断待替换地址的宿主是否属于本站上传目录
     *
     * @param string $prefix 匹配位置之前的内容
     * @return bool
     */
    private function content_host_allowed( string $prefix ): bool {
        $delimiters = "\"'<> \t\n\r(),;=[]{}";
        $start      = strlen( $prefix );

        while ( $start > 0 && false === strpos( $delimiters, $prefix[ $start - 1 ] ) ) {
            $start--;
        }

        $token = str_replace( '\\', '', substr( $prefix, $start ) );

        // 站内相对路径
        if ( '' === $token ) {
            return true;
        }

        if ( ! preg_match( '~^(?:[a-z][a-z0-9+.\-]*:)?(?://(?P<host>[^/?#]*))?$~i', $token, $matched ) ) {
            return false;
        }

        if ( empty( $matched['host'] ) ) {
            return false;
        }

        $upload    = wp_get_upload_dir();
        $base_host = isset( $upload['baseurl'] ) ? wp_parse_url( $upload['baseurl'], PHP_URL_HOST ) : '';

        return is_string( $base_host ) && strtolower( $matched['host'] ) === strtolower( $base_host );
    }

    /**
     * 构建编码命令
     *
     * 复用压缩工具的二进制查找与质量设置
     *
     * @param string $source     编码输入文件路径
     * @param string $output     编码输出文件路径
     * @param string $target     目标格式 webp|avif
     * @return array|false 命令链（参数数组的数组）或 false
     */
    private function build_encode_command( string $source, string $output, string $target ) {
        $tool_name = isset( $this->target_tools[ $target ] ) ? $this->target_tools[ $target ] : '';
        $tools     = libre_compress()->compressor->get_tools();

        if ( '' === $tool_name || empty( $tools[ $tool_name ] ) ) {
            return false;
        }

        $tool_available = 'avifenc' === $tool_name && method_exists( $tools[ $tool_name ], 'has_encoder' )
            ? $tools[ $tool_name ]->has_encoder()
            : $tools[ $tool_name ]->is_tool_available();

        if ( ! $tool_available ) {
            return false;
        }

        $binary   = $tools[ $tool_name ]->get_tool_binary_path();
        $settings = get_option( 'libre_compress_tools', array() );

        if ( 'webp' === $target ) {
            $mode = isset( $settings['webp_mode'] ) ? $settings['webp_mode'] : 'lossy';

            if ( 'lossless' === $mode ) {
                return array( array( $binary, '-quiet', '-lossless', '-z', (string) Libre_Compress_Settings::tool_speed( 'webp_lossless_level' ), $source, '-o', $output ) );
            }

            $quality = isset( $settings['webp_quality'] ) ? absint( $settings['webp_quality'] ) : 80;
            $quality = max( 0, min( 100, $quality ) );

            return array( array( $binary, '-quiet', '-mt', '-q', (string) $quality, '-m', (string) Libre_Compress_Settings::tool_speed( 'webp_method' ), $source, '-o', $output ) );
        }

        $mode = isset( $settings['avif_mode'] ) ? $settings['avif_mode'] : 'lossy';

        // avifenc 默认会把输入图片里的 EXIF/XMP 原样搬进 AVIF，而转换结果正是对外访问的那张图
        $encode = array( $binary, '-j', '4', '-s', (string) Libre_Compress_Settings::tool_speed( 'avif_speed' ) );

        if ( Libre_Compress_Settings::strips_metadata() ) {
            $encode[] = '--ignore-exif';
            $encode[] = '--ignore-xmp';
        }

        if ( 'lossless' === $mode ) {
            $encode[] = '--lossless';
        } else {
            $quality = isset( $settings['avif_quality'] ) ? absint( $settings['avif_quality'] ) : 80;
            $quality = max( 0, min( 100, $quality ) );
            $encode[] = '-q';
            $encode[] = (string) $quality;
        }

        $encode[] = $source;
        $encode[] = $output;

        return array( $encode );
    }

    /**
     * 用 GD 将 GIF 解码为 PNG（仅用于静态 GIF，动画 GIF 走专用工具）
     *
     * @param string $gif_path GIF 源路径
     * @param string $png_path PNG 输出路径
     * @return bool 是否成功
     */
    private function decode_gif_to_png( string $gif_path, string $png_path ): bool {
        if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $image = @imagecreatefromgif( $gif_path );

        if ( false === $image ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $ok = @imagepng( $image, $png_path );
        imagedestroy( $image );

        return $ok && file_exists( $png_path ) && filesize( $png_path ) > 0;
    }

    /**
     * 用 resvg 将 SVG 栅格化为 PNG
     *
     * @param string $svg_path SVG 源路径
     * @param string $png_path PNG 输出路径
     * @return bool 是否成功
     */
    private function rasterize_svg_to_png( string $svg_path, string $png_path ): bool {
        $binary = $this->get_resvg_path();

        if ( false === $binary ) {
            return false;
        }

        $exec_result = Libre_Compress_Tool_Base::run_command( array( $binary, $svg_path, $png_path ) );
        clearstatcache( true, $png_path );

        return $exec_result['success'] && file_exists( $png_path ) && filesize( $png_path ) > 0;
    }

    /**
     * 构建动画 GIF 的目标格式压缩命令
     *
     * WebP 使用 gif2webp（libwebp 套件）直接压缩；
     * AVIF 无 GIF 输入能力，先由 ffmpeg 无损解码为 Y4M 序列，再由 avifenc 编码为动画 AVIF
     *
     * @param string $gif_path GIF 源路径
     * @param string $output   输出文件路径
     * @param string $target   目标格式 webp|avif
     * @return array|false array( command, temp )，工具不可用时返回 false
     */
    private function build_animated_gif_command( string $gif_path, string $output, string $target ) {
        $settings = get_option( 'libre_compress_tools', array() );

        if ( 'webp' === $target ) {
            $binary = $this->find_local_tool( 'gif2webp' );

            if ( false === $binary ) {
                return false;
            }

            $mode = isset( $settings['webp_mode'] ) ? $settings['webp_mode'] : 'lossy';
            $method = (string) Libre_Compress_Settings::tool_speed( 'gif2webp_method' );

            // gif2webp 默认即无损编码，有损模式需显式开启并指定质量
            if ( 'lossy' !== $mode ) {
                $command = array( $binary, '-m', $method, $gif_path, '-o', $output );
            } else {
                $quality = isset( $settings['webp_quality'] ) ? absint( $settings['webp_quality'] ) : 80;
                $quality = max( 0, min( 100, $quality ) );

                $command = array( $binary, '-m', $method, '-lossy', '-q', (string) $quality, $gif_path, '-o', $output );
            }

            return array(
                'command' => array( $command ),
                'temp'    => '',
            );
        }

        $ffmpeg  = $this->find_local_tool( 'ffmpeg' );
        $avifenc = $this->find_local_tool( 'avifenc' );

        if ( false === $ffmpeg || false === $avifenc ) {
            return false;
        }

        // Y4M 中间文件保留 GIF 的完整帧序列，由 avifenc 读取全部帧
        $temp_y4m = $gif_path . '.lc-animation-' . wp_generate_password( 12, false, false ) . '.y4m';

        $mode    = isset( $settings['avif_mode'] ) ? $settings['avif_mode'] : 'lossy';
        $quality = isset( $settings['avif_quality'] ) ? absint( $settings['avif_quality'] ) : 80;
        $quality = max( 0, min( 100, $quality ) );

        $decode = array(
            $ffmpeg, '-y', '-loglevel', 'error', '-i', $gif_path,
            '-pix_fmt', 'lossless' === $mode ? 'yuv444p' : 'yuv420p', '-f', 'yuv4mpegpipe', $temp_y4m,
        );

        $encode = array( $avifenc, '-j', '4', '-s', (string) Libre_Compress_Settings::tool_speed( 'avif_speed' ) );

        if ( 'lossless' === $mode ) {
            // 对 YUV 中间帧使用无损量化，保留其实际色彩矩阵；RGB 无损预设不接受此输入。
            $encode[] = '-q';
            $encode[] = '100';
            $encode[] = '--cicp';
            $encode[] = '1/13/6';
        } else {
            $encode[] = '-q';
            $encode[] = (string) $quality;
        }

        $encode[] = $temp_y4m;
        $encode[] = $output;

        return array(
            'command' => array( $decode, $encode ),
            'temp'    => $temp_y4m,
        );
    }

    /**
     * 查找本地辅助工具（resvg、gif2webp、ffmpeg 等）
     *
     * 优先 wp-content/LibreCompress-bin 目录，其次系统 PATH。
     * 设置页复用此方法展示辅助工具的安装状态与路径。
     *
     * @param string $name 可执行文件名
     * @return string|false 路径或 false
     */
    public function find_local_tool( string $name ) {
        static $cache = array();

        if ( isset( $cache[ $name ] ) ) {
            return $cache[ $name ];
        }

        // 没有 exec() 就不能执行本地工具，直接返回不可用，避免后续调用不存在的函数。
        if ( ! Libre_Compress_Tool_Base::is_command_execution_available() ) {
            $cache[ $name ] = false;
            return false;
        }

        $is_windows = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
        $bin_path   = LIBRE_COMPRESS_BIN_PATH . $name;

        if ( $is_windows ) {
            $bin_path .= '.exe';
        }

        if ( file_exists( $bin_path ) ) {
            $cache[ $name ] = $bin_path;
            return $bin_path;
        }

        $found = Libre_Compress_Tool_Base::run_command(
            array( $is_windows ? 'where' : 'which', $name ),
            15
        );

        if ( ! empty( $found['success'] ) ) {
            foreach ( preg_split( '/\R/', (string) $found['output'] ) as $line ) {
                $path = trim( $line );

                if ( '' !== $path && file_exists( $path ) ) {
                    $cache[ $name ] = $path;
                    return $path;
                }
            }
        }

        $cache[ $name ] = false;
        return false;
    }

    /**
     * 查找 resvg 可执行文件
     *
     * 优先 wp-content/LibreCompress-bin 目录，其次系统 PATH
     *
     * @return string|false 路径或 false
     */
    private function get_resvg_path() {
        return $this->find_local_tool( 'resvg' );
    }

    /**
     * 获取文件的格式标识（jpeg 归一为 jpg）
     *
     * @param string $file_path 文件路径
     * @return string
     */
    private function format_of( string $file_path ): string {
        $format = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        return 'jpeg' === $format ? 'jpg' : $format;
    }

    /**
     * 获取上传目录内的相对路径
     *
     * 返回值只统一分隔符、不动大小写：写进元数据、映射和正文链接的是用户可见的文件名，
     * 改成小写会让 URL 大小写敏感的环境（Linux、CDN）直接 404。
     * 大小写只在比较时由 normalized_path 忽略。
     *
     * @param string $path 绝对路径或相对路径
     * @return string
     */
    private function get_relative_upload_path( string $path ): string {
        $upload_dir = wp_upload_dir();
        $normalized = wp_normalize_path( $path );
        $base_dir   = $upload_dir['basedir'];

        if ( 0 === strpos( $this->normalized_path( $normalized ), $this->normalized_path( $base_dir ) . '/' ) ) {
            return ltrim( substr( $normalized, strlen( $base_dir ) ), '/' );
        }

        return ltrim( $normalized, '/' );
    }

    /**
     * 规范化路径用于精确比较
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 验证上传目录内的目标路径，允许文件当前不存在
     *
     * @param string $file_path 目标路径
     * @return bool
     */
    private function is_safe_destination_path( string $file_path ): bool {
        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $parent_dir = realpath( dirname( $file_path ) );

        if ( false === $base_dir || false === $parent_dir ) {
            return false;
        }

        $base_dir   = untrailingslashit( wp_normalize_path( $base_dir ) );
        $parent_dir = untrailingslashit( wp_normalize_path( $parent_dir ) );

        return 0 === strpos( $parent_dir . '/', $base_dir . '/' );
    }

    /**
     * 检查文件路径是否安全（必须位于上传目录内）
     *
     * @param string $file_path 文件路径
     * @return bool 是否安全
     */
    private function is_safe_path( string $file_path ): bool {
        $upload_dir = wp_get_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $file_path );

        if ( false === $real_path || false === $base_dir ) {
            return false;
        }

        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $real_path = untrailingslashit( wp_normalize_path( $real_path ) );

        if ( 0 !== strpos( $real_path, $base_dir . '/' ) ) {
            return false;
        }

        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        return true;
    }
}
