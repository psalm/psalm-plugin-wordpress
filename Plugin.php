<?php

namespace PsalmWordpress;

use PhpParser\Node\Expr\FuncCall;
use PhpParser;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\Plugin\Hook\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\Hook\FunctionParamsProviderInterface;
use Psalm\Plugin\Hook\BeforeFileAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeParameter;
use SimpleXMLElement;
use Psalm\Type\Union;
use Psalm\Type;
use Psalm;
use Psalm\Type\TypeNode;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TCallable;
use PhpParser\Node\Arg;
use PhpParser\Node\Name;
use Psalm\Type\Atomic;
use Exception;

class Plugin implements PluginEntryPointInterface, AfterEveryFunctionCallAnalysisInterface, FunctionParamsProviderInterface, BeforeFileAnalysisInterface {

	/**
	 * @var array<string, array{types: list<Union>}>
	 */
	public static $hooks = [];

	public function __invoke( RegistrationInterface $psalm, ?SimpleXMLElement $config = null ) {
		$psalm->registerHooksFromClass( static::class );
		array_map( [ $psalm, 'addStubFile' ], $this->getStubFiles() );
		Psalm\Config::getInstance()->before_analyze_file[] = __CLASS__;
	}

	/**
	 * @return string[]
	 */
	private function getStubFiles(): array {

		return [
			__DIR__ . '/stubs/wordpress.php',
			// __DIR__ . '/stubs/parent.php',
			__DIR__ . '/stubs/overrides.php',
		];
	}

	protected static function loadStubbedHooks() {
		if ( static::$hooks ) {
			return;
		}

		$hooks = [];
		$file_hooks = file_get_contents( __DIR__ . '/stubs/hooks.txt' );
		$lines = explode( "\n", $file_hooks );
		foreach ( $lines as $line ) {
			$hook = json_decode( $line, true );
			if ( ! $hook ) {
				continue;
			}
			$hooks[ $hook['name'] ] = [
				'types' => array_map( [ Type::class, 'parseString' ], $hook['types'] ),
			];
		}

		static::$hooks = $hooks;
	}

	public static function beforeAnalyzeFile( StatementsSource $statements_source, Context $file_context, FileStorage $file_storage, Codebase $codebase ) {
		$statements = $codebase->getStatementsForFile( $statements_source->getFilePath() );
		$traverser = new PhpParser\NodeTraverser;
		$traverser->addVisitor( new NodeVisitor() );
		try {
			$traverser->traverse( $statements );
		} catch ( Exception $e ) {

		}
	}

	public static function afterEveryFunctionCallAnalysis(
		FuncCall $expr,
		string $function_id,
		Context $context,
		StatementsSource $statements_source,
		Codebase $codebase
	): void {
		$apply_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		if ( ! in_array( $function_id, $apply_functions, true ) ) {
			return;
		}

		if ( ! $expr->args[0]->value instanceof String_ ) {
			return;
		}

		$name = $expr->args[0]->value->value;

		$this->loadStubbedHooks();

		// Check if this hook is already documented.
		if ( isset( static::$hooks[ $name ] ) ) {
			return;
		}

		$types = array_map( function ( Arg $arg ) use ( $statements_source ) {
			$type = $statements_source->getNodeTypeProvider()->getType( $arg->value );
			if ( ! $type ) {
				$type = Type::parseString( 'mixed' );
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
		}, array_slice( $expr->args, 1 ) );
		static::registerHook( $name, $types );
	}

	public static function getFunctionIds() : array {
		return [
			'add_action',
			'add_filter',
		];
	}

	public static function getFunctionParams(
		StatementsSource $statements_source,
		string $function_id,
		array $call_args,
		Context $context = null,
		CodeLocation $code_location = null
	) {
		//To do check if is in enum
		// $hooks = array_map( function ( string $hook ) : TLiteralString {
		// 	return new TLiteralString( $hook );
		// }, array_keys( static::$hooks ) );
		static::loadStubbedHooks();
		// Todo: test concat
		$hook = static::$hooks[ $call_args[0]->value->value ] ?? null;
		if ( ! $hook ) {
			var_dump( 'Hook not found!!!' );
			return [];
		}

		// Check how many args the filter is registered with.
		$num_args = $call_args[ 3 ]->value->value ?? 1;
		// Limit the required type params on the hook to match the registered number.
		$hook_types = array_slice( $hook['types'], 0, $num_args );

		$hook_params = array_map( function ( Union $type ) : FunctionLikeParameter {
			return new FunctionLikeParameter( 'param', false, $type, null, null, false );
		}, $hook_types );

		$is_filter = $function_id === 'add_filter';
		$is_action = $function_id === 'add_action';

		$return = [
			new FunctionLikeParameter( 'Hook', false, Type::parseString( 'string' ), null, null, false ),
			new FunctionLikeParameter( 'Callback', false, new Union( [
				new TCallable(
					'callable',
					$hook_params,
					// Actions must return null/void. Filters must return the same type as the first param.
					$is_action ? Type::parseString( 'void|null' ) : $hook['types'][0]
				),
			] ), null, null, false ),
			new FunctionLikeParameter( 'Priority', false, Type::parseString( 'int|null' ) ),
			new FunctionLikeParameter( 'Priority', false, Type::parseString( 'int|null' ) ),
		];
		return $return;
	}

	/**
	 * @param string $hook
	 * @return void
	 */
	public static function registerHook( string $hook, array $types ) {
		static::$hooks[ $hook ] = [
			'types' => $types,
		];

		$data = [
			'name' => $hook,
			'types' => array_map( 'strval', $types ),
		];

		file_put_contents( 'hooks.txt', json_encode( $data ) . "\n", FILE_APPEND );
	}
}

class NodeVisitor extends PhpParser\NodeVisitorAbstract {
	protected $last_doc = [];

	public function enterNode( PhpParser\Node $origNode ) {
		$apply_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		if ( $origNode->getDocComment() ) {
			$this->last_doc = $origNode->getDocComment();
		}

		if ( $this->last_doc && $origNode instanceof FuncCall && $origNode->name instanceof Name && in_array( (string) $origNode->name, $apply_functions, true ) ) {
			if ( ! $origNode->args[0]->value instanceof String_ ) {
				$this->last_doc = [];
				return;
			}

			$hook_name = $origNode->args[0]->value->value;
			$comment = Psalm\DocComment::parse( $this->last_doc );

			// Todo: test namespace resolution.
			$comments = Psalm\Internal\Analyzer\CommentAnalyzer::extractFunctionDocblockInfo( $this->last_doc );

			// Todo: handle no comments
			$types = array_map( function ( array $comment_type ) : Union {
				return Type::parseString( $comment_type['type'] );
			}, $comments->params );
			Plugin::registerHook( $hook_name, $types );
			$this->last_doc = [];
		}
	}
}
