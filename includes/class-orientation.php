<?php
/**
 * 图片方向元数据处理
 *
 * 删除元数据时只保留方向（Orientation），避免手机竖拍照片被浏览器当成横图。
 * 不保留其他 EXIF，GPS、设备型号等隐私信息仍然会被清掉。
 *
 * @package LocalCrush
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Local_Crush_Orientation {

    /**
     * 读取文件的方向值（1-8），读不到时返回 1
     *
     * @param string $file_path 文件绝对路径
     * @return int
     */
    public static function from_file( string $file_path ): int {
        $extension = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );

        switch ( $extension ) {
            case 'jpg':
            case 'jpeg':
                return self::from_jpeg( $file_path );

            case 'webp':
                return self::from_webp( $file_path );

            case 'png':
                return self::from_png( $file_path );
        }

        return 1;
    }

    /**
     * 读取 JPEG 的 EXIF 方向
     *
     * @param string $file_path JPEG 路径
     * @return int
     */
    public static function from_jpeg( string $file_path ): int {
        if ( function_exists( 'exif_read_data' ) ) {
            $data = @exif_read_data( $file_path );

            if ( is_array( $data ) && isset( $data['Orientation'] ) ) {
                return self::normalize( (int) $data['Orientation'] );
            }
        }

        $handle = @fopen( $file_path, 'rb' );

        if ( false === $handle ) {
            return 1;
        }

        $signature = fread( $handle, 2 );

        if ( "\xFF\xD8" !== $signature ) {
            fclose( $handle );
            return 1;
        }

        while ( ! feof( $handle ) ) {
            $marker = fread( $handle, 2 );

            if ( strlen( $marker ) < 2 || "\xFF" !== $marker[0] ) {
                break;
            }

            $code = ord( $marker[1] );

            if ( 0xD8 === $code ) {
                continue;
            }

            if ( 0xD9 === $code || 0xDA === $code ) {
                break;
            }

            if ( $code >= 0xD0 && $code <= 0xD7 ) {
                continue;
            }

            $length_bytes = fread( $handle, 2 );

            if ( strlen( $length_bytes ) < 2 ) {
                break;
            }

            $length = (int) unpack( 'n', $length_bytes )[1];

            if ( $length < 2 ) {
                break;
            }

            if ( 0xE1 === $code ) {
                $payload = fread( $handle, $length - 2 );

                if ( is_string( $payload ) && 0 === strpos( $payload, "Exif\0\0" ) ) {
                    $orientation = self::tiff_orientation( substr( $payload, 6 ) );

                    if ( $orientation > 1 ) {
                        fclose( $handle );
                        return $orientation;
                    }
                }
            } else {
                fseek( $handle, $length - 2, SEEK_CUR );
            }
        }

        fclose( $handle );

        return 1;
    }

    /**
     * 读取 WebP 容器里的 EXIF 方向
     *
     * @param string $file_path WebP 路径
     * @return int
     */
    public static function from_webp( string $file_path ): int {
        $handle = @fopen( $file_path, 'rb' );

        if ( false === $handle ) {
            return 1;
        }

        $header = fread( $handle, 12 );

        if ( strlen( $header ) < 12 || 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) ) {
            fclose( $handle );
            return 1;
        }

        $file_size = (int) filesize( $file_path );
        $offset    = 12;

        while ( $offset + 8 <= $file_size ) {
            $chunk_header = fread( $handle, 8 );

            if ( strlen( $chunk_header ) < 8 ) {
                break;
            }

            $size = (int) unpack( 'V', substr( $chunk_header, 4, 4 ) )[1];
            $type = substr( $chunk_header, 0, 4 );

            if ( $size > $file_size - $offset - 8 ) {
                break;
            }

            if ( 'EXIF' === $type ) {
                $payload = fread( $handle, $size );

                if ( is_string( $payload ) ) {
                    if ( 0 === strpos( $payload, "Exif\0\0" ) ) {
                        $payload = substr( $payload, 6 );
                    }

                    $orientation = self::tiff_orientation( $payload );

                    if ( $orientation > 1 ) {
                        fclose( $handle );
                        return $orientation;
                    }
                }

                break;
            }

            $padded = $size + ( $size % 2 );

            if ( 0 !== fseek( $handle, $padded, SEEK_CUR ) ) {
                break;
            }

            $offset += 8 + $padded;
        }

        fclose( $handle );

        return 1;
    }

    /**
     * 读取 PNG eXIf 块里的方向
     *
     * @param string $file_path PNG 路径
     * @return int
     */
    public static function from_png( string $file_path ): int {
        $handle = @fopen( $file_path, 'rb' );

        if ( false === $handle ) {
            return 1;
        }

        $signature = fread( $handle, 8 );

        if ( "\x89PNG\r\n\x1a\n" !== $signature ) {
            fclose( $handle );
            return 1;
        }

        $file_size = (int) filesize( $file_path );
        $offset    = 8;

        while ( $offset + 12 <= $file_size ) {
            $header = fread( $handle, 8 );

            if ( strlen( $header ) < 8 ) {
                break;
            }

            $length = (int) unpack( 'N', substr( $header, 0, 4 ) )[1];
            $type   = substr( $header, 4, 4 );
            $offset += 8;

            if ( $length > $file_size - $offset - 4 ) {
                break;
            }

            if ( 'eXIf' === $type ) {
                $payload = fread( $handle, $length );
                fclose( $handle );

                return is_string( $payload ) ? self::tiff_orientation( $payload ) : 1;
            }

            if ( 0 !== fseek( $handle, $length + 4, SEEK_CUR ) ) {
                break;
            }

            $offset += $length + 4;

            if ( 'IEND' === $type ) {
                break;
            }
        }

        fclose( $handle );

        return 1;
    }

    /**
     * 从 TIFF/EXIF 结构里读取 Orientation 标签
     *
     * @param string $tiff TIFF 数据
     * @return int
     */
    private static function tiff_orientation( string $tiff ): int {
        $length = strlen( $tiff );

        if ( $length < 8 ) {
            return 1;
        }

        $order = substr( $tiff, 0, 2 );

        if ( 'II' === $order ) {
            $little = true;
        } elseif ( 'MM' === $order ) {
            $little = false;
        } else {
            return 1;
        }

        $read16 = static function ( string $bytes ) use ( $little ): int {
            return (int) unpack( $little ? 'v' : 'n', $bytes )[1];
        };
        $read32 = static function ( string $bytes ) use ( $little ): int {
            return (int) unpack( $little ? 'V' : 'N', $bytes )[1];
        };

        if ( 42 !== $read16( substr( $tiff, 2, 2 ) ) ) {
            return 1;
        }

        $ifd_offset = $read32( substr( $tiff, 4, 4 ) );

        if ( $ifd_offset < 8 || $ifd_offset + 2 > $length ) {
            return 1;
        }

        $entry_count = $read16( substr( $tiff, $ifd_offset, 2 ) );
        $position    = $ifd_offset + 2;

        for ( $i = 0; $i < $entry_count; $i++ ) {
            if ( $position + 12 > $length ) {
                break;
            }

            $tag   = $read16( substr( $tiff, $position, 2 ) );
            $type  = $read16( substr( $tiff, $position + 2, 2 ) );
            $count = $read32( substr( $tiff, $position + 4, 4 ) );

            if ( 0x0112 === $tag && $count >= 1 ) {
                if ( 3 === $type ) {
                    return self::normalize( $read16( substr( $tiff, $position + 8, 2 ) ) );
                }

                if ( 4 === $type ) {
                    return self::normalize( $read32( substr( $tiff, $position + 8, 4 ) ) );
                }

                return 1;
            }

            $position += 12;
        }

        return 1;
    }

    /**
     * 夹到 1-8
     *
     * @param int $orientation 方向值
     * @return int
     */
    private static function normalize( int $orientation ): int {
        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * 只带 Orientation 标签的最小 EXIF 载荷
     *
     * @param int  $orientation      方向值
     * @param bool $with_exif_header 是否带 "Exif\0\0" 头（JPEG/WebP/AVIF 需要，JXL 不需要）
     * @return string
     */
    public static function payload( int $orientation, bool $with_exif_header = true ): string {
        $orientation = self::normalize( $orientation );
        $tiff        = 'II'
            . pack( 'v', 42 )
            . pack( 'V', 8 )
            . pack( 'v', 1 )
            . pack( 'vvV', 0x0112, 3, 1 )
            . pack( 'v', $orientation )
            . "\0\0"
            . pack( 'V', 0 );

        return $with_exif_header ? "Exif\0\0" . $tiff : $tiff;
    }

    /**
     * 写一个只含方向的临时 EXIF 文件
     *
     * @param int  $orientation      方向值
     * @param bool $with_exif_header 是否带 "Exif\0\0" 头
     * @return string 临时文件路径，失败返回空字符串
     */
    public static function write_exif_temp( int $orientation, bool $with_exif_header = true ): string {
        $path = tempnam( sys_get_temp_dir(), 'lc_orient_' );

        if ( false === $path ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === file_put_contents( $path, self::payload( $orientation, $with_exif_header ) ) ) {
            unlink( $path );
            return '';
        }

        return $path;
    }

    /**
     * 确保 WebP 里带方向
     *
     * cwebp 已经带了方向就不动；没带才用 webpmux 只补一个 Orientation。
     *
     * @param string $file_path   编码后的 WebP 临时文件
     * @param int    $orientation 原图方向
     * @return bool
     */
    public static function ensure_webp( string $file_path, int $orientation ): bool {
        if ( $orientation <= 1 || ! is_file( $file_path ) ) {
            return true;
        }

        if ( self::from_webp( $file_path ) > 1 ) {
            return true;
        }

        $webpmux = Local_Crush_Output::find_local_tool( 'webpmux' );

        if ( false === $webpmux ) {
            return false;
        }

        $exif = self::write_exif_temp( $orientation, true );

        if ( '' === $exif ) {
            return false;
        }

        $muxed = tempnam( sys_get_temp_dir(), 'lc_webp_mux_' );

        if ( false === $muxed ) {
            unlink( $exif );
            return false;
        }

        $run = Local_Crush_Tool_Base::run_command(
            array( $webpmux, '-set', 'exif', $exif, $file_path, '-o', $muxed ),
            60
        );

        $ok = ! empty( $run['success'] ) && is_file( $muxed ) && filesize( $muxed ) > 0;

        if ( $ok ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
            $ok = copy( $muxed, $file_path ) && self::from_webp( $file_path ) > 1;
        }

        if ( is_file( $muxed ) ) {
            unlink( $muxed );
        }

        unlink( $exif );

        return $ok;
    }

    /**
     * 把只含方向的 EXIF 写回 JPEG
     *
     * 只在开启“删除图片元数据”时调用；jpegoptim 已经把原 EXIF 删掉了，
     * 这里补回方向，避免手机竖拍照片被浏览器当横图。
     *
     * @param string $file_path   JPEG 路径
     * @param int    $orientation 方向值
     * @return bool
     */
    public static function inject_jpeg( string $file_path, int $orientation ): bool {
        if ( $orientation <= 1 ) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $data = file_get_contents( $file_path );

        if ( false === $data || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
            return false;
        }

        $payload = self::payload( $orientation, true );
        $app1    = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return false !== file_put_contents( $file_path, "\xFF\xD8" . $app1 . substr( $data, 2 ) );
    }
}
