<?php

namespace PsalmWordpress;

use PhpParser\Node\Expr\FuncCall;

use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\StatementsSource;
use SimpleXMLElement;

class Plugin implements PluginEntryPointInterface {

	public function __invoke( RegistrationInterface $psalm, ?SimpleXMLElement $config = null ) {
		$psalm->registerHooksFromClass( static::class );

		array_map( [ $psalm, 'addStubFile' ], $this->getStubFiles() );

		// Psalm allows arbitrary content to be stored under you plugin entry in
		// its config file, psalm.xml, so you plugin users can put some configuration
		// values there. They will be provided to your plugin entry point in $config
		// parameter, as a SimpleXmlElement object. If there's no configuration present,
		// null will be passed instead.
	}

	/**
	 * @return string[]
	 */
	private function getStubFiles(): array {
		return [
			__DIR__ . '/stubs/wordpress.php',
			__DIR__ . '/stubs/overrides.php',
		];
	}

	public static function afterEveryFunctionCallAnalysis(
		FuncCall $expr,
		string $function_id,
		Context $context,
		StatementsSource $statements_source,
		Codebase $codebase
	): void {
		$functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		if ( ! in_array( $function_id, $functions, true ) ) {
			return;
		}

		if ( ! $expr->args[0]->value instanceof String_ ) {
			return;
		}

		$name = $expr->args[0]->value->value;

		//var_dump( $name );
		if ( $name === 'get_attached_file' ) {
			return;
		}
		//var_dump( $expr );
		//var_dump( $expr->getDocComment() );
		exit;
	}
}
