<?php

define( 'HOUR_IN_SECONDS', 3600 );

class WP_CLI {
	/**
	 *
	 * @param string $command
	 * @param callable|class-string $class
	 * @return void
	 */
	static function add_command( string $command, $class ) {

	}

	/**
	 * @param WP_Error|string $message
	 * @param bool|int $exit
	 *
	 * @return (
	 *     $exit is true
	 *     ? no-return
	 *     : null
	 * )
	 */
	public static function error( $message, $exit = true ) {
	}
}

class wpdb {
	/**
	 * @param string $query
	 * @param array<scalar>|scalar $args
	 * @return string|null
	 */
	public function prepare( $query, $args ) {

	}
	/**
	 * Undocumented function
	 *
	 * @param string? $query
	 * @param 'OBJECT'|'ARRAY_A'|'ARRAY_N'|'OBJECT_K' $object
	 * @return (
	 *     $object is 'OBJECT'
	 *     ? list<ArrayObject<string, string>>
	 *     : ( $object is 'ARRAY_A'
	 *         ? list<array<string, scalar>>
	 *         : ( $object is 'ARRAY_N'
	 *               ? list<array<scalar>>
	 *               : array<string, ArrayObject<string, scalar>>
	 *           )
	 *    )
	 * )
	 */
	public function get_results( $query = null, $object = 'OBJECT' ) {

	}
}


/**
 * @return array{path: string, basedir: string, baseurl: string, url: string}
 */
function wp_upload_dir() {

}

/**
 * @return array{path: string, basedir: string, baseurl: string, url: string}
 */
function wp_get_upload_dir() {

}

/**
 * @template TFilterValue
 * @param string $a
 * @psalm-param TFilterValue $value
 * @return TFilterValue
 */
function apply_filters( string $a, $value, ...$args ) {}

function add_filter( string $filter, callable $function, int $priority = 10, int $args = 1 ) {}

function add_action( string $filter, callable $function, int $priority = 10, int $args = 1 ) {}

/**
 * @param integer $attachment_id
 * @param boolean $skip_filters
 * @return array{width?: int, height?: int, sizes?: array<string,array{width: int, height: int, file: string}>, file: string}
 */
function wp_get_attachment_metadata( int $attachment_id, $skip_filters = false ) {}

/**
 *
 * @param string $url
 * @return array{path?: string, scheme?: string, host?: string, port?: int, user?: string, pass?: string, query?: string, fragment?: string}
 */
function wp_parse_url( string $url ) {}

/**
 *
 * @param string $option
 * @param mixed $default
 * @return mixed
 */
function get_option( string $option, $default = null ) {}


/**
 *
 * @param string $path
 * @param "https"|"http" $scheme
 * @return string
 */
function home_url( string $path = null, $scheme = null ) : string {

}

/**
 *
 * @template Args of array<array-key, mixed>
 * @template Defaults of array<array-key, mixed>
 * @psalm-param Args $args
 * @psalm-param Defaults $defaults
 * @psalm-return Defaults&Args
 */
function wp_parse_args( $args, $defaults ) {
}

/**
 * @param WP_Error|mixed $error
 * @psalm-assert-if-true WP_Error $error
 */
function is_wp_error( $error ) : bool {

}

/**
 * @template T
 * @template K
 * @param array<array-key, array<K, T>> $list
 * @param K $column
 * @return list<T>
 */
function wp_list_pluck( array $list, string $column, string $index_key = null ) : array {

}

/**
 * @param string $capability
 * @param mixed|null $args
 * @return bool
 */
function current_user_can($capability, $args = null): bool {

}

/**
 * @param string|array $key
 * @param string|null $value
 * @param string|null $uri
 * @return string
 */
function add_query_arg($key, ?string $value = null, ?string $uri = null): string {

}

/**
 * @param WP_Post|int|null $post
 * @param string $output
 * @param string $filter
 *
 * @return WP_Post|array<array-key, mixed>|null
 */
function get_post($post = null, $output = OBJECT, $filter = 'raw')
{
}
