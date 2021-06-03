<?php

do_action( 'foo', true );

add_filter( 'upload_dir', 'filter_upload_dir' );

/**
 * @param array{basedir: string, baseurl: string, error: false|string, path: string, subdir: string, url: string} $dir
 * @return array{basedir: string, baseurl: string, error: false|string, path: string, subdir: string, url: string}
 */
function filter_upload_dir( array $dir ) : array {
	return $dir;
}

add_filter( 'foo', function ( bool $post ) : void {
	new WP_Post( new StdClass );
}, 10, 2 );
