<?php

do_action( 'foo', true );

add_filter( 'upload_dir', 'filter_upload_dir' );

/**
 * @param  array{basedir: string, baseurl: string, error: false|string, path: string, subdir: string, url: string} $dir
 * @return array{basedir: string, baseurl: string, error: false|string, path: string, subdir: string, url: string}
 */
function filter_upload_dir( array $dir ) : array {
	return $dir;
}

add_filter( 'foo', function ( bool $post ) : void {
	new WP_Post( new stdClass );
}, 10, 2 );

add_filter( 'admin_notices', function () {
	echo 'hi';
} );

$uploads = wp_get_upload_dir();

$url_host = wp_parse_url( 'https://github.com:443/psalm/psalm-plugin-wordpress?query=1#frag', PHP_URL_HOST );

$url_port = wp_parse_url( 'https://github.com:443/psalm/psalm-plugin-wordpress?query=1#frag', PHP_URL_PORT );

$url_parts = wp_parse_url( 'https://github.com:443/psalm/psalm-plugin-wordpress?query=1#frag' );
