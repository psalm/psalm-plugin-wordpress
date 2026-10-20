<?php

// phpcs:disable HM.Functions.NamespacedFunctions.MissingNamespace,PEAR.NamingConventions.ValidClassName.StartWithCapital,PSR1.Classes.ClassDeclaration.MissingNamespace,Squiz.Commenting.FunctionComment.InvalidNoReturn,Squiz.Commenting.FunctionComment.MissingParamName

class WP_CLI {
	/**
	 * @param string $name
	 * @param callable|class-string $callable
	 * @param array{
	 *     before_invoke?: callable,
	 *     after_invoke?: callable,
	 *     shortdesc?: string,
	 *     longdesc?: string,
	 *     synopsis?: string,
	 *     when?: string,
	 *     is_deferred?: bool
	 * } $args
	 * @return bool
	 */
	public static function add_command( string $name, $callable, array $args = [] ) {}

	/**
	 * @template TExit of bool|int
	 * @param string|WP_Error|Exception|Throwable $message
	 * @psalm-param TExit $exit
	 * @return (
	 *     TExit is true
	 *     ? no-return
	 *     : null
	 * )
	 */
	public static function error( $message, $exit = true ) {}
}

class wpdb {
	/**
	 * @param string $query
	 * @param array<scalar>|scalar $args
	 * @return string|void
	 */
	public function prepare( $query, $args ) {}

	/**
	 * @template TObject of ARRAY_A|ARRAY_N|OBJECT|OBJECT_K
	 * @param string|null $query
	 * @psalm-param TObject $object
	 * @return (
	 *     TObject is OBJECT
	 *     ? list<ArrayObject<string, string>>
	 *     : ( TObject is ARRAY_A
	 *         ? list<array<string, scalar>>
	 *         : ( TObject is ARRAY_N
	 *             ? list<array<scalar>>
	 *             : array<string, ArrayObject<string, scalar>>
	 *           )
	 *       )
	 * )|null
	 */
	public function get_results( $query = null, $object = \OBJECT ) {}
}

/**
 * @return array{
 *   path: string,
 *   url: string,
 *   subdir: string,
 *   basedir: string,
 *   baseurl: string,
 *   error: string|false,
 * }
 */
function wp_get_upload_dir() {}

/**
 * @template TFilterValue
 * @param string $hook_name
 * @psalm-param TFilterValue $value
 * @return TFilterValue
 */
function apply_filters( string $hook_name, $value, ...$args ) {}

/**
 * | Component        |   |
 * | ---------------- | - |
 * | PHP_URL_SCHEME   | 0 |
 * | PHP_URL_HOST     | 1 |
 * | PHP_URL_PORT     | 2 |
 * | PHP_URL_USER     | 3 |
 * | PHP_URL_PASS     | 4 |
 * | PHP_URL_PATH     | 5 |
 * | PHP_URL_QUERY    | 6 |
 * | PHP_URL_FRAGMENT | 7 |
 *
 * @template TComponent of (-1|PHP_URL_*)
 * @param string $url
 * @param TComponent $component
 * @return (
 *     TComponent is -1
 *     ? array{
 *           scheme?: string,
 *           host?: string,
 *           port?: int,
 *           user?: string,
 *           pass?: string,
 *           path?: string,
 *           query?: string,
 *           fragment?: string,
 *       }
 *     : (
 *       TComponent is 2
 *       ? int|null
 *       : string|null
 *     )
 *  )|false
 */
function wp_parse_url( string $url, int $component = -1 ) {}

/**
 * @param string $option
 * @param mixed $default
 * @return mixed
 */
function get_option( string $option, $default = null ) {}

/**
 * @return array[] {
 *     Array of settings error arrays.
 *
 *     @type array ...$0 {
 *         Associative array of setting error data.
 *
 *         @type string $setting Slug title of the setting to which this error applies.
 *         @type string $code    Slug-name to identify the error. Used as part of 'id' attribute in HTML output.
 *         @type string $message The formatted message text to display to the user (will be shown inside styled
 *                               `<div>` and `<p>` tags).
 *         @type string $type    Optional. Message type, controls HTML class. Possible values include 'error',
 *                               'success', 'warning', 'info'. Default 'error'.
 *     }
 * }
 * @psalm-return array<int|string, array{
 *   setting: string,
 *   code: string,
 *   message: string,
 *   type: string,
 * }>
 */
function get_settings_errors( $setting = '', $sanitize = false ) : array {}

/**
 * @param string $path
 * @param 'https'|'http'|'relative'|'rest' $scheme
 * @return string
 */
function home_url( string $path = '', $scheme = null ) : string {}

/**
 * @template TArgs of array<array-key, mixed>
 * @template TDefaults of array<array-key, mixed>
 * @psalm-param TArgs $args
 * @psalm-param TDefaults $defaults
 * @psalm-return TDefaults&TArgs
 */
function wp_parse_args( $args, $defaults ) {}

/**
 * @param WP_Error|mixed $error
 * @psalm-assert-if-true WP_Error $error
 */
function is_wp_error( $error ) : bool {}

/**
 * @template T
 * @template K
 * @param array<array-key, array<K, T>> $list
 * @param K $column
 * @return list<T>
 */
function wp_list_pluck( array $list, string $column, string $index_key = null ) : array {}
