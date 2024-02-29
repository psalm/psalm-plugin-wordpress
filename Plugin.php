<?php

namespace PsalmWordPress;

use BadMethodCallException;
use Exception;
use InvalidArgumentException;
use phpDocumentor;
use PhpParser;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UseItem;
use Psalm;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\ConstFetchAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\SimpleTypeInferer;
use Psalm\Internal\Provider\ReturnTypeProvider\ParseUrlReturnTypeProvider;
use Psalm\IssueBuffer;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\PluginIssue;
use Psalm\Plugin\EventHandler\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterEveryFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\FunctionParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionParamsProviderInterface;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Union;
use RuntimeException;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * @psalm-type Hook = array{hook_type: string, types: list<Union>, deprecated: bool, minimum_invoke_args: int<0, max>}
 */
class Plugin implements
	AfterEveryFunctionCallAnalysisInterface,
	BeforeFileAnalysisInterface,
	FunctionParamsProviderInterface,
	FunctionReturnTypeProviderInterface,
	PluginEntryPointInterface {

	/**
	 * @var bool
	 */
	public static $requireAllParams = false;

	/**
	 * @var array{useDefaultHooks: bool, hooks: list<string>}
	 */
	public static $configHooks = [
		'useDefaultHooks' => false,
		'hooks'           => [],
	];

	/**
	 * @var array<string, Hook>
	 */
	public static $hooks = [];

	/**
	 * @var string[]
	 */
	public static $parseErrors = [];

	public function __invoke( RegistrationInterface $registration, ?SimpleXMLElement $config = null ) : void {
		$registration->registerHooksFromClass( static::class );

		// if all possible params of an apply_filters should be required
		if ( isset( $config->requireAllParams['value'] ) && (string) $config->requireAllParams['value'] === 'true' ) {
			static::$requireAllParams = true;
		}

		// if useDefaultStubs is not set or set to anything except false, we want to load the stubs included in this plugin
		if ( ! isset( $config->useDefaultStubs['value'] ) || (string) $config->useDefaultStubs['value'] !== 'false' ) {
			array_map( [ $registration, 'addStubFile' ], $this->getStubFiles() );
		}

		// if useDefaultHooks is not set or set to anything except false, we want to load the hooks included in this plugin
		if ( ! isset( $config->useDefaultHooks['value'] ) || (string) $config->useDefaultHooks['value'] !== 'false' ) {
			static::$configHooks['useDefaultHooks'] = true;
		}

		if ( ! empty( $config->hooks ) ) {
			$hooks = [];

			$psalm_config = Config::getInstance();
			if ( $psalm_config->resolve_from_config_file ) {
				$base_dir = $psalm_config->base_dir;
			} else {
				$base_dir = getcwd();
			}

			foreach ( $config->hooks as $hook_data ) {
				foreach ( $hook_data as $type => $data ) {
					if ( $type === 'file' ) {
						// this is a SimpleXmlElement, therefore we need to cast it to string!
						$file = (string) $data['name'];
						if ( $file[0] !== '/' ) {
							$file = $base_dir . '/' . $file;
						}

						if ( ! is_file( $file ) ) {
							throw new BadMethodCallException(
								sprintf( 'Hook file "%s" does not exist', $file )
							);
						}

						// File as key, to avoid loading the same hooks multiple times.
						$hooks[ $file ] = $file;
					} elseif ( $type === 'directory' ) {
						$directory = rtrim( (string) $data['name'], '/' );
						if ( $directory[0] !== '/' ) {
							$directory = $base_dir . '/' . $directory;
						}

						if ( ! is_dir( $directory ) ) {
							throw new BadMethodCallException(
								sprintf( 'Hook directory "%s" does not exist', $directory )
							);
						}

						if ( isset( $data['recursive'] ) && (string) $data['recursive'] === 'true' ) {
							$directories = glob( $directory . '/*', GLOB_ONLYDIR );
						}

						if ( empty( $directories ) ) {
							$directories = [ $directory ];
						} else {
							/** @var string[] $directories */
							$directories[] = $directory;

							// Might have duplicates if the directory is explicitly
							// specified and also passed in recursive directory.
							$directories = array_unique( $directories );
						}

						foreach ( $directories as $directory ) {
							foreach ( [ 'actions', 'filters', 'hooks' ] as $file_name ) {
								$file_path = $directory . '/' . $file_name . '.json';
								if ( is_file( $file_path ) ) {
									$hooks[ $file_path ] = $file_path;
								}
							}
						}
					}
				}
			}

			// Don't need the keys anymore and ensures array_merge runs smoothly later on.
			static::$configHooks['hooks'] = array_values( $hooks );
		}

		static::loadStubbedHooks();
	}

	/**
	 * Resolves a vendor-relative directory path to the absolute package directory.
	 *
	 * The plugin must run both from the source file in the repository (current working directory)
	 * as well as when required as a composer package when the current working directory may not
	 * have a vendor/ folder and the package directory is detected relative to this file.
	 *
	 * @param string $path Path of a folder, relative, inside `vendor/` (Composer).
	 *                     Must start with `vendor/` marker.
	 */
	private static function getVendorDir( string $path ) : string {
		$vendor = 'vendor/';
		$self = 'humanmade/psalm-plugin-wordpress';

		if ( 0 !== strpos( $path, $vendor ) ) {
			throw new BadMethodCallException(
				sprintf( '$path must start with "%s", "%s" given', $vendor, $path )
			);
		}

		$cwd = getcwd();

		// Prefer path relative to current working directory (original default).
		$cwd_path = $cwd . '/' . $path;
		if ( is_dir( $cwd_path ) ) {
			return $cwd_path;
		}

		// Check running as composer package inside a vendor folder.
		$pkg_self_dir = __DIR__;
		$vendor_dir = dirname( $pkg_self_dir, 2 );
		if ( $pkg_self_dir === $vendor_dir . '/' . $self ) {
			// Likely plugin is running as composer package, let's try for the path.
			$pkg_path = substr( $path, strlen( $vendor ) );
			$vendor_path = $vendor_dir . '/' . $pkg_path;
			if ( is_dir( $vendor_path ) ) {
				return $vendor_path;
			}
		}

		// Original default behaviour.
		return $cwd_path;
	}

	/**
	 * @return string[]
	 */
	private function getStubFiles() : array {
		return [
			self::getVendorDir( 'vendor/php-stubs/wordpress-stubs' ) . '/wordpress-stubs.php',
			self::getVendorDir( 'vendor/php-stubs/wordpress-globals' ) . '/wordpress-globals.php',
			self::getVendorDir( 'vendor/php-stubs/wp-cli-stubs' ) . '/wp-cli-stubs.php',
			self::getVendorDir( 'vendor/php-stubs/wp-cli-stubs' ) . '/wp-cli-commands-stubs.php',
			self::getVendorDir( 'vendor/php-stubs/wp-cli-stubs' ) . '/wp-cli-i18n-stubs.php',
			__DIR__ . '/stubs/globals.php',
			__DIR__ . '/stubs/overrides.php',
		];
	}

	protected static function loadStubbedHooks() : void {
		if ( static::$hooks ) {
			return;
		}

		if ( static::$configHooks['useDefaultHooks'] !== false ) {
			$wp_hooks_data_dir = self::getVendorDir( 'vendor/wp-hooks/wordpress-core/hooks' );

			static::loadHooksFromFile( $wp_hooks_data_dir . '/actions.json' );
			static::loadHooksFromFile( $wp_hooks_data_dir . '/filters.json' );
		}

		foreach ( static::$configHooks['hooks'] as $file ) {
			static::loadHooksFromFile( $file );
		}
	}

	protected static function loadHooksFromFile( string $filepath ) : void {
		$data = json_decode( file_get_contents( $filepath ), true );
		if ( ! isset( $data['hooks'] ) || ! is_array( $data['hooks'] ) ) {
			static::$parseErrors[] = 'Invalid hook file ' . $filepath;
			return;
		}

		/**
		 * @var list<array{
		 *     args: int<0, max>,
		 *     name: string,
		 *     aliases?: list<string>,
		 *     file: string,
		 *     type: 'action'|'action_reference'|'action_deprecated'|'filter'|'filter_reference'|'filter_deprecated',
		 *     doc: array{
		 *         description: string,
		 *         long_description: string,
		 *         long_description_html: string,
		 *         tags: list<array{ name: string, content: string, types?: list<string>}>
		 *     }
		 * }>
		 */
		$hooks = $data['hooks'];

		$plugin_slug = basename( dirname( $filepath ) );
		foreach ( $hooks as $hook ) {
			$params = array_filter( $hook['doc']['tags'], function ( $tag ) {
				return $tag['name'] === 'param';
			});

			$params = array_map( function ( array $param ) : array {
				if ( isset( $param['types'] ) && $param['types'] !== [ 'array' ] ) {
					return $param;
				}

				if ( substr_count( $param['content'], '{' ) !== 1 ) {
					// Unable to parse nested array style PHPDoc.
					return $param;
				}

				// ? after variable name is kept, to mark it optional
				// sometimes a hyphen is used in the "variable" here, e.g. "$update-supported"
				$found = preg_match_all( '/@type\s+([^ ]+)\s+\$([\w-]+\??)/', $param['content'], $matches, PREG_SET_ORDER );
				if ( ! $found ) {
					return $param;
				}

				$array_properties = [];
				foreach ( $matches as $match ) {
					// use as property as key to avoid setting the same property twice in case it is incorrectly set twice
					$array_properties[ $match[2] ] = $match[2] . ': ' . $match[1];
				}

				$array_string = 'array{ ' . implode( ', ', $array_properties ) . ' }';
				$param['types'] = [ $array_string ];
				return $param;
			}, $params );

			$types = array_column( $params, 'types' );

			// remove empty elements which can happen with invalid phpdoc - must be done before parseString to avoid notice there
			$types = array_filter( $types );

			$types = array_map( function ( $type ) : string {
				natcasesort( $type );
				return implode( '|', $type );
			}, $types );

			// not all types are documented, assume "mixed" for undocumented
			if ( count( $types ) < $hook['args'] ) {
				$fill_types = array_fill( 0, $hook['args'], 'mixed' );
				$types = $types + $fill_types;
				ksort( $types );
			}

			// skip invalid ones
			try {
				$parsed_types = array_map( [ Type::class, 'parseString' ], $types );
			} catch ( TypeParseTreeException $e ) {
				static::$parseErrors[] = $e->getMessage() . ' for hook ' . $hook['name'] . ' of hook file ' . $filepath . ' in ' . $plugin_slug . '/' . $hook['file'];

				continue;
			}

			if ( $hook['type'] === 'filter_deprecated' ) {
				$is_deprecated = true;
				$hook['type'] = 'filter';
			} elseif ( $hook['type'] === 'action_deprecated' ) {
				$is_deprecated = true;
				$hook['type'] = 'action';
			} else {
				$deprecated_tags = array_filter( $hook['doc']['tags'], function ( $tag ) {
					return $tag['name'] === 'deprecated';
				});
				$is_deprecated = $deprecated_tags === [] ? false : true;
			}

			static::registerHook( $hook['name'], $parsed_types, $hook['type'], $is_deprecated );

			if ( isset( $hook['aliases'] ) ) {
				foreach ( $hook['aliases'] as $alias_name ) {
					static::registerHook( $alias_name, $parsed_types, $hook['type'], $is_deprecated );
				}
			}
		}
	}

	public static function beforeAnalyzeFile( BeforeFileAnalysisEvent $event ) : void {
		$file_path = $event->getStatementsSource()->getFilePath();
		$statements = $event->getCodebase()->getStatementsForFile( $file_path );
		$traverser = new PhpParser\NodeTraverser;
		$hook_visitor = new HookNodeVisitor();
		$traverser->addVisitor( $hook_visitor );
		try {
			$traverser->traverse( $statements );
		} catch ( Exception $e ) {

		}

		foreach ( $hook_visitor->hooks as $hook ) {
			static::registerHook( $hook['name'], $hook['types'], $hook['hook_type'], $hook['deprecated'] );
		}
	}

	public static function getDynamicHookName( object $arg ) : ?string {
		// variable or 'foo' . $bar variable hook name
		// "foo_{$my_var}_bar" is the wp-hooks-generator style, we need to mimic
		if ( $arg instanceof Variable ) {
			return '{$' . $arg->name . '}';
		}

		if ( $arg instanceof PhpParser\Node\Scalar\Encapsed || $arg instanceof PhpParser\Node\Scalar\InterpolatedString ) {
			$hook_name = '';
			foreach ( $arg->parts as $part ) {
				$resolved_part = static::getDynamicHookName( $part );
				if ( $resolved_part === null ) {
					return null;
				}

				$hook_name .= $resolved_part;
			}

			return $hook_name;
		}

		if ( $arg instanceof PhpParser\Node\Expr\BinaryOp\Concat ) {
			$hook_name = static::getDynamicHookName( $arg->left );
			if ( is_null( $hook_name ) ) {
				return null;
			}

			$temp = static::getDynamicHookName( $arg->right );
			if ( is_null( $temp ) ) {
				return null;
			}

			$hook_name .= $temp;

			return $hook_name;
		}

		if ( $arg instanceof String_ || $arg instanceof PhpParser\Node\Scalar\EncapsedStringPart || $arg instanceof PhpParser\Node\InterpolatedStringPart || $arg instanceof PhpParser\Node\Scalar\LNumber || $arg instanceof PhpParser\Node\Scalar\Int_ ) {
			return $arg->value;
		}

		if ( $arg instanceof PhpParser\Node\Expr\StaticPropertyFetch ) {
			// @todo the WP hooks generator doesn't support that yet and handling needs to be added for it there first
			// e.g. self::$foo
			return null;
		}

		if ( $arg instanceof PhpParser\Node\Expr\StaticCall ) {
			if ( ! $arg->name instanceof PhpParser\Node\Identifier ) {
				throw new UnexpectedValueException( 'Unsupported dynamic hook name with name type ' . get_class( $arg->name ) . ' on line ' . $arg->getStartLine(), 0 );
			}

			// hook name with Foo:bar()
			if ( $arg->class instanceof Name ) {
				// @todo this can be handled here, however the WP hooks generator creates output that does not match the other format at all and needs to be fixed first
				return null;
			}

			// need to check recursively
			$temp = static::getDynamicHookName( $arg->class );
			if ( is_null( $temp ) ) {
				throw new UnexpectedValueException( 'Unsupported dynamic hook name with class type ' . get_class( $arg->class ) . ' on line ' . $arg->getStartLine(), 0 );
			}

			$append_method_call = '()';
			return rtrim( $temp, '}' ) . '::' . $arg->name->toString() . $append_method_call . '}';
		}

		if ( $arg instanceof PhpParser\Node\Expr\PropertyFetch || $arg instanceof PhpParser\Node\Expr\MethodCall ) {
			if ( ! $arg->name instanceof PhpParser\Node\Identifier ) {
				throw new UnexpectedValueException( 'Unsupported dynamic hook name with name type ' . get_class( $arg->name ) . ' on line ' . $arg->getStartLine(), 0 );
			}

			// need to check recursively
			$temp = static::getDynamicHookName( $arg->var );
			if ( is_null( $temp ) ) {
				throw new UnexpectedValueException( 'Unsupported dynamic hook name with var type ' . get_class( $arg->var ) . ' on line ' . $arg->getStartLine(), 0 );
			}

			$append_method_call = $arg instanceof PhpParser\Node\Expr\MethodCall ? '()' : '';

			return rtrim( $temp, '}' ) . '->' . $arg->name->toString() . $append_method_call . '}';
		}

		if ( $arg instanceof FuncCall ) {
			// mostly relevant for add_action - can just assume any variable name without using the function name, since it's useless (e.g. basename, dirname,... are common ones)
			return '{$variable}';
		}

		if ( $arg instanceof PhpParser\Node\Expr\ArrayDimFetch ) {
			$key_hook_name = static::getDynamicHookName( $arg->dim );
			if ( is_null( $key_hook_name ) ) {
				throw new UnexpectedValueException( 'Unsupported dynamic hook name with key type ' . get_class( $arg->dim ) . ' on line ' . $arg->getStartLine(), 0 );
			}

			// need to check recursively
			$temp = static::getDynamicHookName( $arg->var );
			if ( is_null( $temp ) ) {
				throw new UnexpectedValueException( 'Unsupported dynamic hook name with var type ' . get_class( $arg->var ) . ' on line ' . $arg->getStartLine(), 0 );
			}

			if ( $key_hook_name[0] === '{' ) {
				$key = trim( $key_hook_name, '{}' );
			} elseif ( is_numeric( $key_hook_name ) ) {
				$key = $key_hook_name;
			} else {
				$key = "'" . $key_hook_name . "'";
			}

			return rtrim( $temp, '}' ) . '[' . $key . ']}';
		}

		// isn't actually supported by the wp-hooks-generator yet and will be handled as regular string there
		// just handle it generically here for the time being
		if ( $arg instanceof PhpParser\Node\Expr\ConstFetch || $arg instanceof PhpParser\Node\Expr\ClassConstFetch ) {
			return '{$variable}';
		}

		// other types not supported yet
		// add handling if encountered @todo
		throw new UnexpectedValueException( 'Unsupported dynamic hook name with type ' . get_class( $arg ) . ' on line ' . $arg->getStartLine(), 0 );
	}

	/**
	 * @return Hook|null
	 */
	public static function getDynamicHookData( string $hook_name, bool $is_action_not_filter = false ) : ?array {
		// fully dynamic hooks like {$tag} cannot be used here, as they would match many hooks
		// same for {$tag}_{$hello}
		$normalized_hook_name = preg_replace( '/{(?:[^{}]+|(?R))*+}/', '{$abc}', $hook_name );
		$hook_dynamic_removed = ltrim( $normalized_hook_name, '_' );
		if ( empty( $hook_dynamic_removed ) ) {
			return null;
		}

		// register dynamic actions - here we can use the static name from the add_action to register it
		// this ensures that add_action for those will give correct error (e.g. if the 4th argument is not 0 for ajax) and we don't need to ignore these
		$all_hook_names = array_keys( static::$hooks );
		// dynamic hooks exist as some_{$variable}_text
		$dynamic_hook_names = preg_grep( '/{\$/', $all_hook_names );

		// normalize variable name length, to ensure longer variable names don't cause wrong sorting leading to incorrect replacements later on
		$normalized_dynamic_hook_names = preg_replace( '/{(?:[^{}]+|(?R))*+}/', '{$abc}', $dynamic_hook_names );

		$dynamic_hook_names = array_combine( $dynamic_hook_names, $normalized_dynamic_hook_names );

		// sort descending from longest to shortest, to avoid shorter dynamic hooks accidentally matching
		uasort( $dynamic_hook_names, function( string $a, string $b ) {
			return strlen( $b ) - strlen( $a );
		});

		// the hook name has a variable, so we first check it against hooks that use a variable too only, to see if we get a match here already
		$dynamic_hook_name_key = array_search( $normalized_hook_name, $dynamic_hook_names, true );
		if ( $dynamic_hook_name_key !== false && $is_action_not_filter && static::$hooks[ $dynamic_hook_name_key ]['hook_type'] !== 'action' ) {
			// action used as filter
			return null;
		} elseif ( $dynamic_hook_name_key !== false && ! $is_action_not_filter && static::$hooks[ $dynamic_hook_name_key ]['hook_type'] !== 'filter' ) {
			// filter used as action
			return null;
		} elseif ( $dynamic_hook_name_key !== false ) {
			$dynamic_hook = static::$hooks[ $dynamic_hook_name_key ];

			// it's already in the correct format, so we just need to assign it to the non-dynamic name
			static::$hooks[ $hook_name ] = [
				'hook_type' => $dynamic_hook['hook_type'],
				'types' => $dynamic_hook['types'],
				'deprecated' => $dynamic_hook['deprecated'],
				'minimum_invoke_args' => $dynamic_hook['minimum_invoke_args'],
			];

			return static::$hooks[ $hook_name ];
		}

		foreach ( $dynamic_hook_names as $dynamic_hook_name => $normalized_dynamic_hook_name ) {
			if ( $is_action_not_filter && ! in_array( static::$hooks[ $dynamic_hook_name ]['hook_type'], [ 'action', 'action_reference', 'action_deprecated' ], true ) ) {
				// don't match actions with filters here, so we will get an error later on
				continue;
			}

			if ( ! $is_action_not_filter && ! in_array( static::$hooks[ $dynamic_hook_name ]['hook_type'], [ 'filter', 'filter_reference', 'filter_deprecated' ], true ) ) {
				continue;
			}

			// fully dynamic hooks like {$tag} cannot be used here, as they would match all hooks
			// same for {$tag}_{$hello}
			$dynamic_removed = ltrim( str_replace( '{$abc}', '', $normalized_dynamic_hook_name ), '_' );
			if ( empty( $dynamic_removed ) ) {
				continue;
			}

			// need to escape it beforehand, since we insert regex into it
			$preg_hook_name = preg_quote( $normalized_dynamic_hook_name, '/' );
			// dot for dynamic hook names, e.g. load-edit.php
			// may contain a variable if the hook name is dynamic too
			$preg_hook_name = str_replace( '\{\$abc\}', '({\$)?[\w:>.[\]\'$\/-]+}?', $preg_hook_name );

			if ( preg_match( '/^' . $preg_hook_name . '$/', $hook_name ) === 1 ) {
				$dynamic_hook = static::$hooks[ $dynamic_hook_name ];

				// it's already in the correct format, so we just need to assign it to the non-dynamic name
				static::$hooks[ $hook_name ] = [
					'hook_type' => $dynamic_hook['hook_type'],
					'types' => $dynamic_hook['types'],
					'deprecated' => $dynamic_hook['deprecated'],
					'minimum_invoke_args' => $dynamic_hook['minimum_invoke_args'],
				];

				return static::$hooks[ $hook_name ];
			}
		}

		return null;
	}

	public static function afterEveryFunctionCallAnalysis( AfterEveryFunctionCallAnalysisEvent $event ) : void {
		$apply_filter_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
		];

		$do_action_functions = [
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		$function_id = $event->getFunctionId();
		if ( in_array( $function_id, $apply_filter_functions, true ) ) {
			$hook_type = 'filter';
		} elseif ( in_array( $function_id, $do_action_functions, true ) ) {
			$hook_type = 'action';
		} elseif ( preg_match( '/_deprecated_hook$/', $function_id ) !== 1 ) {
			// there are custom implementations of "deprecated_hook", e.g. for "wcs", so we need to preg match it
			return;
		}

		$call_args = $event->getExpr()->getArgs();
		if ( ! isset( $call_args[0] ) ) {
			return;
		}

		if ( ! $call_args[0]->value instanceof String_ ) {
			$statements_source = $event->getStatementsSource();
			try {
				$union = $statements_source->getNodeTypeProvider()->getType( $call_args[0]->value );
			} catch (UnexpectedValueException $e) {
				$union = null;
			}
			if ( ! $union ) {
				$union = static::getTypeFromArg( $call_args[0]->value, $event->getContext(), $statements_source );
			}

			$potential_hook_name = false;
			if ( $union && $union->isSingleStringLiteral() ) {
				$potential_hook_name = $union->getSingleStringLiteral()->value;
			}

			if ( $potential_hook_name && isset( static::$hooks[ $potential_hook_name ] ) ) {
				$hook_name = $potential_hook_name;
			} else {
				$hook_name = static::getDynamicHookName( $call_args[0]->value );
				if ( is_null( $hook_name ) && ! $potential_hook_name ) {
					return;
				}

				if ( $potential_hook_name ) {
					if ( is_null( $hook_name ) || ! isset( static::$hooks[ $hook_name ] ) ) {
						// if it's not registered yet, use the resolved hook name
						$hook_name = $potential_hook_name;
					} elseif ( isset( static::$hooks[ $hook_name ] ) ) {
						// if it's registered already, store the resolved name hook too
						static::$hooks[ $potential_hook_name ] = static::$hooks[ $hook_name ];
					}
				}
			}
		} else {
			$hook_name = $call_args[0]->value->value;
		}

		if ( preg_match( '/_deprecated_hook$/', $function_id ) === 1 ) {
			// hook type is irrelevant and won't be used when overriding
			// in case the hook is not registered yet, it will eventually be registered and the hook type and types will be set
			static::registerHook( $hook_name, [], '', true );
			return;
		}

		// Check if this hook is already documented.
		if ( isset( static::$hooks[ $hook_name ] ) ) {
			if (
				! in_array( $function_id, ['apply_filters_deprecated', 'do_action_deprecated'], true ) &&
				static::$hooks[ $hook_name ]['deprecated'] === true
			) {
				$statements_source = $event->getStatementsSource();
				$code_location = new CodeLocation( $event->getStatementsSource(), $event->getExpr() );
				$suggestion = $hook_type === 'filter' ? 'apply_filters_deprecated' : 'do_action_deprecated';
				IssueBuffer::accepts(
					new DeprecatedHook(
						'Hook "' . $hook_name . '" is deprecated. If you still need this, check if there is a replacement for it. Otherwise, if this is a 3rd party ' . $hook_type . ', you can remove it. If it is your own/custom, please use "' . $suggestion . '" here instead and add an "@deprecated new" comment in the phpdoc',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return;
		}

		$statements_source = $event->getStatementsSource();
		$types = array_map( function ( Arg $arg ) use ( $statements_source ) {
			try {
				$type = $statements_source->getNodeTypeProvider()->getType( $arg->value );
			} catch ( UnexpectedValueException $e ) {
				$type = null;
			}

			if ( ! $type ) {
				$type = Type::getMixed();
			} else {
				$sub_types = array_values( $type->getAtomicTypes() );
				$sub_types = array_map( function ( Atomic $type ) : Atomic {
					if ( $type instanceof Atomic\TTrue || $type instanceof Atomic\TFalse ) {
						return new Atomic\TBool;
					} elseif ( $type instanceof Atomic\TLiteralString ) {
						return new Atomic\TString;
					} elseif ( $type instanceof Atomic\TLiteralInt ) {
						return new Atomic\TInt;
					} elseif ( $type instanceof Atomic\TLiteralFloat ) {
						return new Atomic\TFloat;
					}

					return $type;
				}, $sub_types );
				$type = new Union( $sub_types );
			}

			return $type;
		}, array_slice( $call_args, 1 ) );

		$is_deprecated = false;
		if ( in_array( $function_id, ['apply_filters_deprecated', 'do_action_deprecated'], true ) ) {
			$is_deprecated = true;
		}

		static::registerHook( $hook_name, $types, $hook_type, $is_deprecated );
	}

	/**
	 * @return non-empty-list<lowercase-string>
	 */
	public static function getFunctionIds() : array {
		return [
			'add_action',
			'add_filter',
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
			'did_action',
			'did_filter',
			'doing_action',
			'doing_filter',
			'has_action',
			'has_filter',
			'remove_action',
			'remove_filter',
			'remove_all_actions',
			'remove_all_filters',
			'wp_parse_url',
		];
	}

	/**
	 * @return ?array<int, Psalm\Storage\FunctionLikeParameter>
	 */
	public static function getFunctionParams( FunctionParamsProviderEvent $event ) : ?array {
		$function_id = $event->getFunctionId();
		if ( ! in_array( $function_id, static::getFunctionIds(), true ) ) {
			return null;
		}

		if ( $function_id === 'wp_parse_url' ) {
			return null;
		}

		// @todo not supported yet below
		if ( in_array( $function_id, ['do_action_ref_array', 'do_action_deprecated', 'apply_filters_ref_array', 'apply_filters_deprecated'], true ) ) {
			return null;
		}

		static::loadStubbedHooks();

		$statements_source = $event->getStatementsSource();
		$code_location = $event->getCodeLocation();

		// output any parse errors
		foreach ( static::$parseErrors as $error_message ) {
			// do not allow suppressing this
			IssueBuffer::accepts(
				new InvalidDocblock(
					$error_message,
					$code_location // this can be completely wrong (even completely wrong file), since the parse error might be taken from the hooks file, so ideally we would create the correct code location before adding to $parseErrors
				)
			);
		}
		static::$parseErrors = [];

		$is_action = $function_id === 'add_action';
		$is_do_action = $function_id === 'do_action';
		$is_action_not_filter = $is_action || $is_do_action;
		$is_invoke = $is_do_action || $function_id === 'apply_filters';

		$is_utility = false;
		if (
			in_array(
				$function_id,
				array(
					'did_action',
					'doing_action',
					'has_action',
					'remove_action',
					'remove_all_actions',
				),
				true
			)
		) {
			$is_utility = true;
			$is_action_not_filter = true;
		} elseif (
			in_array( $function_id,
				array(
					'did_filter',
					'doing_filter',
					'has_filter',
					'remove_filter',
					'remove_all_filters',
				),
				true
			)
		) {
			$is_utility = true;
		}

		$call_args = $event->getCallArgs();
		if ( ! isset( $call_args[0] ) ) {
			return null;
		}

		if ( ! $call_args[0]->value instanceof String_ ) {
			try {
				$union = $statements_source->getNodeTypeProvider()->getType( $call_args[0]->value );
			} catch (UnexpectedValueException $e) {
				$union = null;
			}
			if ( ! $union ) {
				$union = static::getTypeFromArg( $call_args[0]->value, $event->getContext(), $statements_source );
			}

			$potential_hook_name = false;
			if ( $union && $union->isSingleStringLiteral() ) {
				$potential_hook_name = $union->getSingleStringLiteral()->value;
			}

			if ( $potential_hook_name && isset( static::$hooks[ $potential_hook_name ] ) ) {
				$hook_name = $potential_hook_name;
			} else {
				$hook_name = static::getDynamicHookName( $call_args[0]->value );
				if ( is_null( $hook_name ) && ! $potential_hook_name ) {
					return null;
				}

				if ( $potential_hook_name ) {
					if ( is_null( $hook_name ) || ! isset( static::$hooks[ $hook_name ] ) ) {
						$hook_name = $potential_hook_name;
					}
				}
			}
		} else {
			$hook_name = $call_args[0]->value->value;
		}

		$hook = static::$hooks[ $hook_name ] ?? null;

		if ( is_null( $hook ) ) {
			$hook = static::getDynamicHookData( $hook_name, $is_action_not_filter );
		}

		// if we declare/invoke the hook, the hook obviously exists
		if ( ! $is_invoke && ! $hook ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook "' . $hook_name . '" not found',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// if it were an add_filter/add_action, we would have returned above if the $hook is not set
		// if the $hook is still not set, it means we have a do_action/apply_filters here
		// it may be empty if it's missing in the actions/filters.json
		// or if it's not documented in the file. e.g. a do_action without a phpdoc, an action that is only declared in a wp_schedule_event,... and thus not picked up by beforeAnalyzeFile
		// since we have no details on it, we skip it
		if ( ! $hook ) {
			// like this, it will give error that the do_action does not expect any arguments, thus prompting the dev to add phpdoc or remove args
			// if this should be ignored return []; instead
			// return [
			//	new FunctionLikeParameter( 'hook_name', false, Type::getNonEmptyString(), null, null, null, false ),
			// ];
			return [];
		}

		$hook_type = $hook['hook_type'] ?? '';

		// action_reference for do_action_ref_array
		if ( $is_action_not_filter && ! in_array( $hook_type, [ 'action', 'action_reference', 'action_deprecated' ], true ) ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook "' . $hook_name . '" is a filter not an action',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}

			return [];
		}

		// filter_reference for apply_filters_ref_array
		if ( ! $is_action_not_filter && ! in_array( $hook_type, [ 'filter', 'filter_reference', 'filter_deprecated' ], true ) ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook "' . $hook_name . '" is an action not a filter',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}

			return [];
		}

		if ( ! $is_invoke && $hook['deprecated'] === true && $code_location ) {
			IssueBuffer::accepts(
				new DeprecatedHook(
					'Hook "' . $hook_name . '" is deprecated',
					$code_location
				),
				$statements_source->getSuppressedIssues()
			);
		}

		// don't modify the param types for utility types, since the stubbed ones are fine
		if ( $is_utility ) {
			return null;
		}

		// Check how many args the filter is registered with.
		$accepted_args_provided = false;
		if ( $is_invoke ) {
			// need to deduct 1, since the first argument (string) is the hardcoded hook name, which is added manually later on, since it's not in the PHPDoc
			$num_args = count( $call_args ) - 1;
		} else {
			$num_args = 1;
			if ( isset( $call_args[ 3 ]->value->value ) && !isset( $call_args[ 3 ]->name ) ) {
				$num_args = max( 0, (int) $call_args[ 3 ]->value->value );
				$accepted_args_provided = true;
			}

			// named arguments
			foreach ( $call_args as $call_arg ) {
				if ( isset( $call_arg->name ) && $call_arg->name instanceof PhpParser\Node\Identifier && $call_arg->name->name === 'accepted_args' ) {
					$num_args = max( 0, (int) $call_arg->value->value );
					$accepted_args_provided = true;
					break;
				}
			}
		}

		// if the PHPDoc is missing from the do_action/apply_filters, the types can be empty - we assign "mixed" when loading stubs in that case though to avoid this
		// this means these hooks really do not accept any args and the arg
		// this will cause "InvalidArgument" error for add_action/add_filter and TooManyArguments error for do_action/apply_filters
		// except where it actually has 0 args
		if ( empty( $hook['types'] ) && $num_args !== 0 ) {
			if ( $is_invoke ) {
				// impossible, as it would have been populated with "mixed" already
				// kept for completeness sake
				$hook_types = array_fill( 0, $num_args, Type::getMixed() );
			} else {
				// add_action default has 1 arg, but this hook does not accept any args
				if ( $is_action ) {
					// if accepted args are not provided, it will use the default value, so we need to give an error
					// if it's provided psalm will report an InvalidArgument error by default already
					if ( $code_location && $accepted_args_provided === false ) {
						IssueBuffer::accepts(
							new HookInvalidArgs(
								'Hook "' . $hook_name . '" does not accept any args, but the default number of args of add_action is 1. Please pass 0 as 4th argument',
								$code_location
							),
							$statements_source->getSuppressedIssues()
						);
					}
					$hook_types = [];
				} else {
					// should never happen, since we always assume "mixed" as default already and apply_filters must have at least 1 arg which is the value that is filtered
					// this might be worth to debug, if anybody ever encounters this
					throw new UnexpectedValueException( 'You found a bug for hook "' . $hook_name . '". Please open an issue with a code sample on https://github.com/psalm/psalm-plugin-wordpress', 0 );

					// alternatively handle it with mixed for add_filter
					// $hook_types = [ Type::getMixed() ];
				}
			}
		} else {
			$hook_types = $hook['types'];
		}

		$max_params = count( $hook_types );

		// when not all params should be required, we set all others to optional
		$required_params_count = static::$requireAllParams === true && $is_invoke ? $max_params : $num_args;

		// we must slice for add_action/add_filter, to get the number of params matching the number of add_action/filter args, so it will report an error if the number is wrong
		$hook_types = $is_invoke ? $hook_types : array_slice( $hook_types, 0, $required_params_count );

		// if the required args in add_action are higher than what the hook is called with in some cases
		if ( $required_params_count > $hook['minimum_invoke_args'] && ! $is_invoke ) {
			// all args that go above the minimum invoke args must be set to optional, as the filter is called
			// only after we sliced above already (since we need to include all params, just need to change if they're optional or not)
			$required_params_count = $hook['minimum_invoke_args'];
		}

		$hook_params = array_map( function ( Union $type ) use ( &$required_params_count ) : FunctionLikeParameter {
			$is_optional = $required_params_count > 0 ? false : true;
			$required_params_count--;

			return new FunctionLikeParameter( 'param', false, $type, null, null, null, $is_optional );
		}, $hook_types );

		// check that the types passed to filter are of the type that is specified in PHPDoc
		if ( $is_invoke ) {
			$return = [
				// generic non-empty string (don't use $hook_name, as it will report false positives for dynamic hook names that contain a variable)
				new FunctionLikeParameter( 'hook_name', false, Type::getNonEmptyString(), null, null, null, false ),
			];

			return array_merge( $return, $hook_params );
		}

		// add_action callback return value can be anything, but is discarded anyway, therefore should be void
		$min_args = 0;
		if ( $is_action ) {
			// by setting this to "Type::getVoid()", the callback can return ANY value (e.g. int, string,...)
			// by setting this to "Type::getNull()", the callback must explicitly return null or void
			// using null is too strict, as you would have to create tons of unnecessary wrapper functions then
			$return_type = Type::getVoid();
		} else {
			// technically 0 is allowed for add_filter, however it doesn't make sense since any previously filtered values would not be used and this is the wrong way to use a filter
			$min_args = 1;
			if ( isset( $hook['types'][0] ) ) {
				// add_filter callback must return the same type as the first documented parameter (2nd arg)
				$return_type = $hook['types'][0];

				// for bool we can use 0, so "__return_true" and "__return_false" can be used without error, as for bool only (!) filters the previous value doesn't matter
				if ( $return_type->isBool() ) {
					$min_args = 0;
				}
			} else {
				// unknown due to lack of PHPDoc - but a filter must always return something - mixed is the most generic case
				$return_type = Type::getMixed();
			}
		}

		$args_type = $max_params === 0 || $min_args >= $max_params ? Type::getInt( false, $max_params ) : new Union([ new TIntRange( $min_args, $max_params )] );

		$return = [
			// the first argument of each FunctionLikeParameter must match the param name of the function to allow the use of named arguments
			new FunctionLikeParameter( 'hook_name', false, Type::getNonEmptyString(), null, null, null, false ),
			new FunctionLikeParameter( 'callback', false, new Union( [
				new TCallable(
					'callable',
					$hook_params,
					$return_type
				),
			] ), null, null, null, false ),
			// $is_optional arg in FunctionLikeParameter is true by default, so we can just set type of int directly without null (since it's not nullable anyway)
			new FunctionLikeParameter( 'priority', false, Type::getInt() ),
			new FunctionLikeParameter( 'accepted_args', false, $args_type ),
		];

		return $return;
	}

	public static function getFunctionReturnType( FunctionReturnTypeProviderEvent $event ) : ?Union {
		if ( $event->getFunctionId() === 'wp_parse_url' ) {
			return ParseUrlReturnTypeProvider::getFunctionReturnType( $event );
		}

		if ( in_array( $event->getFunctionId(), [ 'add_action', 'add_filter' ], true ) ) {
			return Type::getTrue();
		}

		if ( in_array( $event->getFunctionId(), [ 'do_action', 'do_action_ref_array', 'do_action_deprecated' ], true ) ) {
			return Type::getVoid();
		}

		// @todo not supported yet below
		if ( in_array( $event->getFunctionId(), [ 'apply_filters_ref_array', 'apply_filters_deprecated' ], true ) ) {
			return null;
		}

		// use the stubbed type for those
		if (
			in_array(
				$event->getFunctionId(),
				array(
					'did_action',
					'did_filter',
					'doing_action',
					'doing_filter',
					'has_action',
					'has_filter',
					'remove_action',
					'remove_filter',
					'remove_all_actions',
					'remove_all_filters',
				),
				true
			)
		) {
			return null;
		}

		static::loadStubbedHooks();

		$call_args = $event->getCallArgs();

		// only apply_filters left to handle
		if ( ! $call_args[0]->value instanceof String_ ) {
			$statements_source = $event->getStatementsSource();
			try {
				$union = $statements_source->getNodeTypeProvider()->getType( $call_args[0]->value );
			} catch (UnexpectedValueException $e) {
				$union = null;
			}
			if ( ! $union ) {
				$union = static::getTypeFromArg( $call_args[0]->value, $event->getContext(), $statements_source );
			}

			$potential_hook_name = false;
			if ( $union && $union->isSingleStringLiteral() ) {
				$potential_hook_name = $union->getSingleStringLiteral()->value;
			}

			if ( $potential_hook_name && isset( static::$hooks[ $potential_hook_name ] ) ) {
				$hook_name = $potential_hook_name;
			} else {
				$hook_name = static::getDynamicHookName( $call_args[0]->value );
				if ( is_null( $hook_name ) && ! $potential_hook_name ) {
					return static::getTypeFromArg( $call_args[1]->value, $event->getContext(), $event->getStatementsSource() );
				}

				if ( $potential_hook_name ) {
					if ( is_null( $hook_name ) || ! isset( static::$hooks[ $hook_name ] ) ) {
						$hook_name = $potential_hook_name;
					}
				}
			}
		} else {
			$hook_name = $call_args[0]->value->value;
		}

		$hook = static::$hooks[ $hook_name ] ?? null;
		if ( is_null( $hook ) ) {
			$hook = static::getDynamicHookData( $hook_name, false );
		}

		// if it's not a filter
		if ( isset( $hook['hook_type'] ) && ! in_array( $hook['hook_type'], [ 'filter', 'filter_reference', 'filter_deprecated' ], true ) ) {
			// can't happen unless there is a filter and an action with the same name
			// or a dynamic filter name matches an action name
			return Type::getNull();
		}

		if ( isset( $hook['types'][0] ) ) {
			// add_filter callback must return the same type as the first documented parameter (2nd arg)
			return $hook['types'][0];
		} else {
			// unknown due to lack of PHPDoc
			return static::getTypeFromArg( $call_args[1]->value, $event->getContext(), $event->getStatementsSource() );
		}
	}

	protected static function getTypeFromArg( $parser_param, Context $context, StatementsSource $statements_source ) : ?Union {
		if ( isset( $statements_source->node_data ) ) {
			$mode_type = SimpleTypeInferer::infer(
				$statements_source->getCodebase(),
				$statements_source->node_data,
				$parser_param,
				$statements_source->getAliases(),
				$statements_source,
			);

			if ( ! $mode_type && $parser_param instanceof PhpParser\Node\Expr\ConstFetch ) {
				$mode_type = ConstFetchAnalyzer::getConstType(
					$statements_source,
					$parser_param->name->toString(),
					true,
					$context,
				);
			}

			if ( $mode_type ) {
				return $mode_type;
			}
		}

		$extended_var_id = ExpressionIdentifier::getExtendedVarId(
			$parser_param,
			null,
			$statements_source,
		);

		if ( ! $extended_var_id ) {
			return null;
		}

		// if it's set return the type of the variable, otherwise set it to null (mixed via fallback)
		return $context->vars_in_scope[ $extended_var_id ] ?? null;
	}

	/**
	 * @param string $hook_name
	 * @param array<int<0, max>, Union> $types
	 * @return void
	 */
	public static function registerHook( string $hook_name, array $types, string $hook_type, bool $is_deprecated ) {
		// remove empty elements which can happen with invalid phpdoc
		$types = array_filter( $types );
		$minimum_invoke_args = count( $types );

		// do not assign empty types if we already have this hook registered
		if ( isset( static::$hooks[ $hook_name ] ) && $minimum_invoke_args === 0 ) {
			// allow overriding the deprecated in this case though - e.g. for calls to "_deprecated_hook"
			if ( static::$hooks[ $hook_name ]['deprecated'] === false ) {
				static::$hooks[ $hook_name ]['deprecated'] = $is_deprecated;
			}

			// filter must have at least 1 arg to work
			if ( $hook_type !== '' && $hook_type !== 'filter' && static::$hooks[ $hook_name ]['minimum_invoke_args'] !== 0 ) {
				static::$hooks[ $hook_name ]['minimum_invoke_args'] = 0;
			}

			return;
		}

		// if this hook is registered already
		if ( isset( static::$hooks[ $hook_name ] ) ) {
			$minimum_invoke_args = static::$hooks[ $hook_name ]['hook_type'] === '' ? $minimum_invoke_args : min( static::$hooks[ $hook_name ]['minimum_invoke_args'], $minimum_invoke_args );
			// if we have more types than already registered, we overwrite existing ones, but keep additional ones (array_merge would combine them which is wrong)
			// except where type is "mixed" and we have a more specific type, we keep the more specific type
			// we do not merge types together, as this would lead to a complete chaos and no PHPDocs matching up whatsoever
			foreach ( $types as $key => $param_type ) {
				if ( ! isset( static::$hooks[ $hook_name ]['types'][ $key ] ) ) {
					// new type has more types than existing
					break;
				}

				if ( ! $param_type->isSingle() ) {
					continue;
				}

				if ( $param_type->hasMixed() ) {
					$types[ $key ] = static::$hooks[ $hook_name ]['types'][ $key ];
				}
			}
			$types = $types + static::$hooks[ $hook_name ]['types'];

			// if keys are missing in one of the 2 arrays, it can lead to incorrect param order, so we have to sort by key
			ksort( $types );

			// if it's deprecated anywhere, keep it deprecated
			$is_deprecated = static::$hooks[ $hook_name ]['deprecated'] === true ? true : $is_deprecated;
		}

		// if there are keys missing, e.g. they were removed from the array_filter due to invalid docblock, we need to set them
		if ( array_values( $types ) !== $types ) {
			for ( $i = 0; $i < count( $types ); $i++ ) {
				if ( isset( $types[ $i ] ) ) {
					continue;
				}

				// assign mixed, since we do not have a valid phpdoc for it
				$types[ $i ] = Type::getMixed();
			}

			ksort( $types );
		}

		if ( $minimum_invoke_args === 0 && $hook_type === 'filter' ) {
			$minimum_invoke_args = 1;
		}

		static::$hooks[ $hook_name ] = [
			'hook_type' => $hook_type,
			'types' => array_values( $types ),
			'deprecated' => $is_deprecated,
			'minimum_invoke_args' => $minimum_invoke_args,
		];
	}
}

class HookNodeVisitor extends PhpParser\NodeVisitorAbstract {
	/** @var ?PhpParser\Comment\Doc */
	protected $last_doc = null;

	/** @var list<array{name: string, hook_type: string, types: list<Union>, deprecated: bool}> */
	public $hooks = [];

	/**
	 * @var int
	 */
	private $maxLine = 0;

	/**
	 * @var string
	 */
	protected $useNamespace = '';

	/**
	 * @var array<string, string>
	 */
	protected $useStatements = [];

	/**
	 * @var array<string, string>|false
	 */
	protected $useStatementsNonClass = false;

	public function enterNode( PhpParser\Node $node ) {
		$apply_filter_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
		];

		$do_action_functions = [
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		$event_functions = [
			'wp_schedule_event',
			// WooCommerce action scheduler
			'as_schedule_recurring_action',
			'as_schedule_cron_action',
		];

		$single_event_functions = [
			'wp_schedule_single_event',
			// WooCommerce action scheduler
			'as_schedule_single_action',
			'as_enqueue_async_action',
		];

		// see visitor.php for original code
		if ( ( $this->useNamespace !== '' || $this->useStatements !== [] || $this->useStatementsNonClass !== false ) && $this->maxLine > $node->getStartLine() ) {
			$this->useNamespace = '';
			$this->useStatements = [];
			$this->useStatementsNonClass = false;
		}

		$this->maxLine = $node->getStartLine();

		if ( $node instanceof Namespace_ ) {
			// as soon as there is a new namespace, we need to empty the useStatements, as there will be new ones for the given namespace
			$this->useNamespace = $node->name->toString();
			$this->useStatements = [];
			$this->useStatementsNonClass = false;
			return null;
		}

		// normal "use" statements
		if ( $node instanceof PhpParser\Node\Stmt\UseUse || $node instanceof UseItem ) {
			// must implode, before we remove the class name from the array
			$fqcn = $node->name->toString();

			// sometimes happens as an artifact of GroupUse below and can be ignored
			if ( empty( $fqcn ) ) {
				return null;
			}

			// some have unnecessary leading \ in "use", we need to remove
			$fqcn = ltrim( $fqcn, '\\' );

			// technically not needed, since the namespace is included in the stubs
			// changing it anyway, to make things easier to follow/read, as the stubs are a massive file usually
			$fqcn = preg_replace( '/^namespace\\\\/', $this->useNamespace . '\\', $fqcn, 1 );

			// if "as" is used, it's set as alias
			if ( ! empty( $node->alias->name ) ) {
				// class names are unique, so we use them as keys of the array
				// however it might already be set by a GroupUse, in which case we don't want to override it, as we would set a wrong value here
				if ( empty( $this->useStatements[ $node->alias->name ] ) ) {
					$this->useStatements[ $node->alias->name ] = $fqcn;
				}
			} else {
				$class_name = $node->name->getLast();

				// might already be set by a GroupUse
				if ( empty( $this->useStatements[ $class_name ] ) ) {
					$this->useStatements[ $class_name ] = $fqcn;
				}
			}

			return null;
		}

		// like "use superWC\special\{Order, Extra\Refund, User};"
		if ( $node instanceof GroupUse ) {
			// some have unnecessary leading \ in them, we need to remove first
			$fqcn_prefix = ltrim( $node->prefix->toString(), '\\' );

			foreach ( $node->uses as $use_object ) {
				$fqcn = $use_object->name->toString();
				if ( empty( $fqcn ) ) {
					continue;
				}

				// some have invalid leading \ in them, we need to remove first
				$fqcn = $fqcn_prefix . '\\' . ltrim( $fqcn, '\\' );
				$fqcn = preg_replace( '/^namespace\\\\/', $this->useNamespace . '\\', $fqcn, 1 );

				if ( ! empty( $use_object->alias->name ) ) {
					$this->useStatements[ $use_object->alias->name ] = $fqcn;
				} else {
					$this->useStatements[ $use_object->name->getLast() ] = $fqcn;
				}
			}

			return null;
		}
		// end of visitor.php duplicate code

		// "return apply_filters" will assign the phpdoc to the return instead of the apply_filters, so we need to store it
		// "$var = apply_filters" directly after a function declaration
		// "echo apply_filters"
		// cannot do this for all cases, as often it will assign completely wrong stuff otherwise
		if ( $node->getDocComment() && ( $node instanceof FuncCall || $node instanceof Return_ || $node instanceof Variable || $node instanceof Echo_ ) ) {
			$this->last_doc = $node->getDocComment();
		} elseif ( isset( $this->last_doc ) && ! $node instanceof FuncCall ) {
			// if it's set already and this is not a FuncCall, reset it to null, since there's something else and it would be used incorrectly
			$this->last_doc = null;
		}

		if ( $node instanceof FuncCall && $node->name instanceof Name ) {
			$hook_index = 0;
			$is_deprecated = false;
			if ( in_array( (string) $node->name, $apply_filter_functions, true ) ) {
				$hook_type = 'filter';
			} elseif ( in_array( (string) $node->name, $do_action_functions, true ) ) {
				$hook_type = 'action';
			} elseif ( in_array( (string) $node->name, $event_functions, true ) ) {
				$hook_type = 'cron-action';
				// the 3rd arg (index key 2) is the hook name
				$hook_index = 2;
			} elseif ( in_array( (string) $node->name, $single_event_functions, true ) ) {
				$hook_type = 'cron-action';
				$hook_index = 1;

				if ( (string) $node->name === 'as_enqueue_async_action' ) {
					$hook_index = 0;
				}
			} elseif ( preg_match( '/_deprecated_hook$/', (string) $node->name ) === 1 ) {
				// ignore dynamic hooks
				if ( $node->args[0]->value instanceof String_ ) {
					$hook_name = $node->args[0]->value->value;
				} else {
					$hook_name = Plugin::getDynamicHookName( $node->args[0]->value );
				}

				if ( ! $hook_name ) {
					$this->last_doc = null;
					return null;
				}

				// hook type is irrelevant and won't be used when overriding
				// in case the hook is not registered yet, it will eventually be registered and the hook type and types will be set
				$this->hooks[] = [
					'name' => $hook_name,
					'hook_type' => '',
					'types' => [],
					'deprecated' => true,
				];
				return null;
			} else {
				return null;
			}

			$types = [];
			$override_num_args_from_docblock = false;
			if ( $hook_type === 'cron-action' ) {
				if ( $node->args[ $hook_index ]->value instanceof String_ ) {
					$hook_name = $node->args[ $hook_index ]->value->value;
				} else {
					$hook_name = Plugin::getDynamicHookName( $node->args[ $hook_index ]->value );
				}

				if ( ! $hook_name ) {
					$this->last_doc = null;
					return null;
				}

				// if it's not documented (which it honestly never is for these), we need to get the number of args and assign mixed
				// the args are passed as array as element after hook name in all cases
				// args are optional, so by default we will have 0
				$num_args = 0;
				if ( isset( $node->args[ $hook_index + 1 ] ) && $node->args[ $hook_index + 1 ] instanceof Arg && $node->args[ $hook_index + 1 ]->value instanceof Array_ ) {
					$cron_args = $node->args[ $hook_index + 1 ]->value->items;
					$num_args = count( $cron_args );

					// see if we can assign better types, than just mixed for all
					foreach ( $cron_args as $item ) {
						// cron events only use array values, keys are ignored by WP
						if ( $item->value instanceof Variable ) {
							$types[] = Type::getMixed();
						} elseif ( $item->value instanceof String_ || $item->value instanceof PhpParser\Node\Expr\Cast\String_ ) {
							$types[] = Type::getString();
						} elseif ( $item->value instanceof Array_ || $item->value instanceof PhpParser\Node\Expr\Cast\Array_ ) {
							$types[] = Type::getArray();
						} elseif ( $item->value instanceof PhpParser\Node\Scalar\LNumber || $item->value instanceof PhpParser\Node\Scalar\Int_ || $item->value instanceof PhpParser\Node\Expr\Cast\Int_ ) {
							$types[] = Type::getInt();
						} elseif ( $item->value instanceof PhpParser\Node\Scalar\DNumber || $item->value instanceof PhpParser\Node\Scalar\Float_ || $item->value instanceof PhpParser\Node\Expr\Cast\Double ) {
							$types[] = Type::getFloat();
						} elseif ( ( $item->value instanceof PhpParser\Node\Expr\ConstFetch && in_array( strtolower( $item->value->name->toString() ), [ 'false', 'true' ], true ) ) || $item->value instanceof PhpParser\Node\Expr\Cast\Bool_ ) {
							$types[] = Type::getBool();
						} elseif ( $item->value instanceof PhpParser\Node\Expr\Cast\Object_ ) {
							$types[] = Type::getObject();
						} else {
							$types[] = Type::getMixed();
						}
					}
				} elseif ( isset( $node->args[ $hook_index + 1 ] ) && $node->args[ $hook_index + 1 ] instanceof Arg && $node->args[ $hook_index + 1 ]->value instanceof Variable ) {
					// there's something there, but we cannot determine the type. Theoretically could be multiple args, but we cannot determine.
					// theoretically possible it's an empty element, in which case this would be wrong, but then no empty variable should be set here in the first place
					$num_args = 1;
					$types = [ Type::getMixed() ];

					// if we have a docblock, override the num_args with the docblock declared params, as this is more correct
					// as otherwise we get lots of optional parameters required in callbacks all the time
					$override_num_args_from_docblock = true;
				}

				// since it's just a regular action and we don't need to differentiate anymore now
				$hook_type = 'action';
			} else {
				if ( $node->args[0]->value instanceof String_ ) {
					$hook_name = $node->args[0]->value->value;
				} else {
					$hook_name = Plugin::getDynamicHookName( $node->args[0]->value );
				}

				if ( ! $hook_name ) {
					$this->last_doc = null;
					return null;
				}

				// the first arg is the hook name, which gets skipped, so we need to "-1"
				$num_args = count( $node->args ) - 1;

				// cron actions cannot be deprecated, which means we only need to check this here
				if ( in_array( (string) $node->name, ['apply_filters_deprecated', 'do_action_deprecated'], true ) ) {
					$is_deprecated = true;
				}
			}

			$has_valid_docblock = true;
			// an undocumented filter or action invoke
			if ( is_null( $this->last_doc ) ) {
				$has_valid_docblock = false;
			} else {
				$doc_comment = $this->last_doc->getText();

				// reset it right away, in case the docblock is invalid and we return early
				$this->last_doc = null;

				// quick and dirty
				if ( $is_deprecated === false && preg_match( '/\* *@deprecated/', $doc_comment ) === 1 ) {
					$is_deprecated = true;
				}

				$doc_factory = phpDocumentor\Reflection\DocBlockFactory::createInstance();
				$context = new phpDocumentor\Reflection\Types\Context( $this->useNamespace, $this->useStatements );
				try {
					$doc_block = $doc_factory->create( $doc_comment, $context );
				} catch ( RuntimeException $e ) {
					$has_valid_docblock = false;
				} catch ( InvalidArgumentException $e ) {
					$has_valid_docblock = false;
				}
			}

			if ( $has_valid_docblock === false ) {
				if ( $num_args > 0 && empty( $types ) ) {
					$types = array_fill( 0, $num_args, Type::getMixed() );
				}

				$this->hooks[] = [
					'name' => $hook_name,
					'hook_type' => $hook_type,
					'types' => $types,
					'deprecated' => $is_deprecated,
				];
				return null;
			}

			/** @var array<phpDocumentor\Reflection\DocBlock\Tags\Param|phpDocumentor\Reflection\DocBlock\Tags\InvalidTag> */
			$params = $doc_block->getTagsByName( 'param' );
			if ( $override_num_args_from_docblock === true && count( $params ) > $num_args ) {
				$num_args = count( $params );
			}

			$i = 0;
			$types = [];
			foreach ( $params as $param ) {
				if ( $i >= $num_args ) {
					break;
				}

				++$i;

				if( ! ( $param instanceof phpDocumentor\Reflection\DocBlock\Tags\Param ) ) {
					// set to mixed - if we skip it, it will mess up all subsequent args
					$types[] = Type::getMixed();
					continue;
				}
				$param_type = $param->getType();
				if ( is_null( $param_type ) ) {
					// set to mixed - if we skip it, it will mess up all subsequent args
					$types[] = Type::getMixed();
					continue;
				}

				$types[] = Type::parseString( $param_type->__toString() );
			}

			if ( count( $types ) < $num_args ) {
				// we have a list, so we can just array merge instead of "+" and ksort
				$fill_types = array_fill( count( $types ), $num_args - count( $types ), Type::getMixed() );
				$types = array_merge( $types, $fill_types );
			}

			// cannot assign to hooks directly, as this would mean we overwrite if this hook exists multiple times in this file
			// all type handling logic is better handled in a single place later where hooks get registered
			$this->hooks[] = [
				'name' => $hook_name,
				'hook_type' => $hook_type,
				'types' => $types,
				'deprecated' => $is_deprecated,
			];
		}

		return null;
	}
}

class HookNotFound extends PluginIssue {}

class HookInvalidArgs extends PluginIssue {}

class DeprecatedHook extends PluginIssue {}
