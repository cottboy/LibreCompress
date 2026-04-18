<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Libre_Compress_Channel_Interface {

    public function get_name(): string;

    public function get_supported_formats(): array;

    public function get_max_file_size(): int;

    public function compress( string $file_path, array $options = array() ): array;
}
